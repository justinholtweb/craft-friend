<?php

namespace justinholtweb\friend\services;

use Craft;
use craft\base\Component;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\elements\Category;
use craft\elements\Entry;
use craft\helpers\UrlHelper;
use justinholtweb\friend\helpers\Similarity;
use justinholtweb\friend\helpers\Uris;
use justinholtweb\friend\models\Candidate;
use justinholtweb\friend\models\Miss;
use justinholtweb\friend\models\Rule;
use justinholtweb\friend\Plugin;
use Throwable;

/**
 * Retrieval and scoring — the two halves of "similar", kept apart on purpose.
 *
 * **Retrieval** is about recall: get plausible elements out of the database cheaply, by as many
 * routes as the rule allows. **Scoring** is about precision: re-rank everything retrieval found,
 * in PHP, against numbers the tester can show a human.
 *
 * Blurring the two is how a "similar post" feature ends up redirecting `/contact-us` to a blog
 * entry from 2014 — because whatever the database happened to return first was treated as an
 * answer rather than as a shortlist.
 *
 * Nothing here loads an element. Candidates are scored from `id`, `slug`, `title` and `uri`, and a
 * URL is built from the URI and the site, exactly as `Element::getUrl()` would. The elements
 * table is only touched if something actually wants the element — which, on the hot path, nothing
 * does.
 */
class Candidates extends Component
{
    /**
     * @return Candidate[] best first
     */
    public function find(Rule $rule, Miss $miss): array
    {
        if (!$miss->isSearchable()) {
            return [];
        }

        $limit = $rule->candidateLimit ?? Plugin::getInstance()->getSettings()->candidateLimit;
        $limit = max(1, $limit);

        /** @var array<int, array> $rows elementId => row */
        $rows = [];

        /** @var array<int, string[]> $methods elementId => methods that found it */
        $methods = [];

        /** @var array<int, float> $ancestorScores elementId => 0–100 */
        $ancestorScores = [];

        foreach ($rule->methods as $method) {
            try {
                $found = match ($method) {
                    Rule::METHOD_SLUG => $this->_bySlug($rule, $miss, $limit),
                    Rule::METHOD_TOKENS => $this->_byTokens($rule, $miss, $limit),
                    Rule::METHOD_SEARCH => $this->_bySearch($rule, $miss, $limit),
                    Rule::METHOD_ANCESTOR => $this->_byAncestor($rule, $miss, $ancestorScores),
                    default => [],
                };
            } catch (Throwable $e) {
                // One bad retrieval method must not take the other three down with it — a broken
                // search index is a reason to score worse, not a reason to 500 on a 404.
                Craft::warning("Retrieval method '$method' failed: " . $e->getMessage(), Plugin::LOG_CATEGORY);
                continue;
            }

            foreach ($found as $row) {
                $id = (int)$row['id'];
                $rows[$id] ??= $row;
                $methods[$id][] = $method;
            }
        }

        if (!$rows) {
            return [];
        }

        $candidates = [];
        $missUri = $miss->uri;

        foreach ($rows as $id => $row) {
            $uri = Uris::normalize($row['uri'] ?? '');

            // A candidate at the URI that just 404'd would redirect the visitor to the request
            // they already made. It happens for real — a disabled element keeps its URI, and a
            // rule with `enabledOnly` switched off will find it.
            if ($uri === $missUri) {
                continue;
            }

            $candidate = $this->_score($rule, $miss, $row, $uri);
            $candidate->elementId = $id;
            $candidate->elementType = $rule->elementType;
            $candidate->methods = array_values(array_unique($methods[$id] ?? []));

            if (isset($ancestorScores[$id]) && $ancestorScores[$id] > $candidate->score) {
                $candidate->score = $ancestorScores[$id];
                $candidate->breakdown['ancestor'] = $ancestorScores[$id] / 100;
            }

            $candidates[] = $candidate;
        }

        usort($candidates, static function(Candidate $a, Candidate $b) {
            // Score, then the shorter URI: two pages that look equally similar and sit at
            // different depths — `/services` and `/services/2019/archive/seo` — the general one is
            // the safer place to send somebody who is already lost.
            return [$b->score, strlen($a->uri), $a->elementId] <=> [$a->score, strlen($b->uri), $b->elementId];
        });

        return array_slice($candidates, 0, $limit);
    }

    // ------------------------------------------------------------------ retrieval

    /**
     * An element whose slug is exactly the missing last segment.
     *
     * The highest-value query in the plugin, and the cheapest: it is what a moved page looks like.
     */
    private function _bySlug(Rule $rule, Miss $miss, int $limit): array
    {
        if ($miss->slug === '') {
            return [];
        }

        return $this->_baseQuery($rule, $miss)
            ->slug($miss->slug)
            ->limit(min($limit, 25))
            ->asArray()
            ->all();
    }

    /**
     * Elements whose slug or title contains any significant token from the URL.
     *
     * Two queries, not one: element query params AND together, so slug and title have to be asked
     * separately to mean "or".
     */
    private function _byTokens(Rule $rule, Miss $miss, int $limit): array
    {
        $tokens = $miss->slugTokens ?: $miss->tokens;

        if (!$tokens) {
            return [];
        }

        // More than a handful of `LIKE '%x%'` terms stops narrowing anything and starts being a
        // table scan with extra steps.
        $tokens = array_slice($tokens, 0, 6);
        $wildcards = array_merge(['or'], array_map(static fn(string $token) => "*$token*", $tokens));

        $bySlug = $this->_baseQuery($rule, $miss)
            ->slug($wildcards)
            ->limit($limit)
            ->asArray()
            ->all();

        $byTitle = $this->_baseQuery($rule, $miss)
            ->title($wildcards)
            ->limit($limit)
            ->asArray()
            ->all();

        return array_merge($bySlug, $byTitle);
    }

    /**
     * Craft's own search index, with the tokens OR-ed together.
     *
     * The retrieval method that reaches content rather than just slugs and titles — a page whose
     * URL never mentioned the word the visitor typed can still be the right answer.
     */
    private function _bySearch(Rule $rule, Miss $miss, int $limit): array
    {
        $tokens = $miss->tokens ?: $miss->slugTokens;

        if (!$tokens) {
            return [];
        }

        $terms = implode(' OR ', array_slice($tokens, 0, 8));

        return $this->_baseQuery($rule, $miss)
            ->search($terms)
            ->limit($limit)
            ->asArray()
            ->all();
    }

    /**
     * The nearest existing element at a shorter prefix of the same path.
     *
     * Scored structurally rather than textually: how much of the path survived, as a percentage.
     * `/services/seo-audits` → `/services` keeps one segment of two, so 50. That number is
     * comparable with the blended scores and honest about what it means, which matters because
     * this is the method most likely to be the only one that finds anything at all.
     *
     * @param array<int, float> $ancestorScores written by reference
     */
    private function _byAncestor(Rule $rule, Miss $miss, array &$ancestorScores): array
    {
        $ancestors = Uris::ancestors($miss->uri);

        if (!$ancestors) {
            return [];
        }

        $total = count($miss->segments);
        $found = [];

        foreach ($ancestors as $ancestor) {
            $rows = $this->_baseQuery($rule, $miss)
                ->uri($ancestor)
                ->limit(1)
                ->asArray()
                ->all();

            if (!$rows) {
                continue;
            }

            $depth = count(Uris::segments($ancestor));

            foreach ($rows as $row) {
                $found[] = $row;
                $ancestorScores[(int)$row['id']] = $total > 0 ? round(100 * $depth / $total, 1) : 0.0;
            }

            // Longest first, and the nearest ancestor is the only one worth offering.
            break;
        }

        return $found;
    }

    /**
     * A fresh element query scoped to the rule's sources.
     *
     * Fresh every time — element queries are mutable and reusing one across retrieval methods
     * would AND their params together, which is the opposite of what a multi-method rule means.
     */
    private function _baseQuery(Rule $rule, Miss $miss)
    {
        /** @var class-string<ElementInterface> $type */
        $type = $rule->elementType;

        $query = $type::find()
            ->siteId($miss->siteId)
            ->uri(':notempty:');

        if ($rule->enabledOnly) {
            $status = $this->_statusFor($type);

            if ($status !== null) {
                $query->status($status);
            }
        } else {
            $query->status(null);
        }

        if ($rule->uriPrefix) {
            $prefix = Uris::normalize($rule->uriPrefix);

            if ($prefix !== '') {
                $query->andWhere(['like', 'elements_sites.uri', $prefix . '/%', false]);
            }
        }

        if (is_a($type, Entry::class, true)) {
            if ($rule->sectionIds) {
                $query->sectionId($rule->sectionIds);
            }

            if ($rule->entryTypeIds) {
                $query->typeId($rule->entryTypeIds);
            }
        } elseif (is_a($type, Category::class, true) && $rule->categoryGroupIds) {
            $query->groupId($rule->categoryGroupIds);
        }

        return $query;
    }

    /**
     * The status that means "publicly visible" for this element type.
     *
     * Not a constant: `status('live')` is entry-only, and an element query asked for a status its
     * type does not have returns **nothing at all** rather than erroring — a rule that silently
     * never matches, with no message anywhere to say why.
     */
    private function _statusFor(string $elementType): ?string
    {
        try {
            $statuses = array_keys($elementType::statuses());
        } catch (Throwable) {
            return Element::STATUS_ENABLED;
        }

        foreach ([Entry::STATUS_LIVE, Element::STATUS_ENABLED] as $status) {
            if (in_array($status, $statuses, true)) {
                return $status;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ scoring

    private function _score(Rule $rule, Miss $miss, array $row, string $uri): Candidate
    {
        $settings = Plugin::getInstance()->getSettings();

        $slug = (string)($row['slug'] ?? '');
        $title = (string)($row['title'] ?? '');

        $slugTokens = Similarity::tokenize($slug, $settings->minTokenLength, $settings->extraStopWords);
        $titleTokens = Similarity::tokenize($title, $settings->minTokenLength, $settings->extraStopWords);

        // Word overlap and character similarity fail on different things — overlap misses a typo,
        // character similarity misses a reordering — so the better of the two is the honest read
        // of "how alike are these", rather than an average that splits the difference on both.
        $slugScore = max(
            Similarity::dice($miss->slugTokens, $slugTokens),
            (Similarity::textRatio($miss->slug, $slug) + Similarity::levenshteinRatio($miss->slug, $slug)) / 2
        );

        $titleScore = max(
            Similarity::dice($miss->tokens, $titleTokens),
            Similarity::textRatio(str_replace(['-', '_'], ' ', $miss->slug), $title)
        );

        // Parent paths only. The last segment is what the slug score is already about, and
        // counting it twice would let a rule's path weight quietly become more slug weight.
        $pathScore = Similarity::pathAffinity(
            array_slice($miss->segments, 0, -1),
            array_slice(Uris::segments($uri), 0, -1)
        );

        $breakdown = [
            'slug' => round($slugScore, 3),
            'title' => round($titleScore, 3),
            'path' => round($pathScore, 3),
        ];

        return new Candidate([
            'title' => $title,
            'uri' => $uri,
            'url' => $this->urlFor($uri, $miss->siteId),
            'score' => Similarity::blend($breakdown, $rule->weights()),
            'breakdown' => $breakdown,
        ]);
    }

    /**
     * The URL an element at this URI would have.
     *
     * Built rather than read, because reading it means loading the element — and a redirect that
     * costs one extra element load per 404 is a redirect that costs a lot on the day somebody
     * points a scanner at the site.
     */
    public function urlFor(string $uri, int $siteId): ?string
    {
        $uri = Uris::normalize($uri);

        try {
            return UrlHelper::siteUrl($uri, null, null, $siteId);
        } catch (Throwable $e) {
            Craft::warning("Could not build a URL for '$uri': " . $e->getMessage(), Plugin::LOG_CATEGORY);
            return null;
        }
    }
}
