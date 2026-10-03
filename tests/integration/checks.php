<?php
/**
 * Friend integration checks.
 *
 * Run inside the plugin-testing container, from the site root:
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-friend/tests/integration/checks.php
 *
 * Idempotent and self-cleaning: every fixture it creates it deletes again, whether the run passes
 * or not, and it sweeps up strays from a run that died half way.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use craft\helpers\Db;
use justinholtweb\friend\db\Table;
use justinholtweb\friend\helpers\Similarity;
use justinholtweb\friend\helpers\Uris;
use justinholtweb\friend\models\Miss;
use justinholtweb\friend\models\Outcome;
use justinholtweb\friend\models\Pin;
use justinholtweb\friend\models\Rule;
use justinholtweb\friend\models\Trace;
use justinholtweb\friend\Plugin;
use justinholtweb\friend\twig\FriendVariable;

$passed = 0;
$failed = 0;

function check(string $label, callable $test): void
{
    global $passed, $failed;

    try {
        $result = $test();

        if ($result === true) {
            $passed++;
            echo "  ✓ $label\n";
            return;
        }

        $failed++;
        echo "  ✗ $label\n    " . (is_string($result) ? $result : 'returned ' . var_export($result, true)) . "\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n      " . $e->getFile() . ':' . $e->getLine() . "\n";
    }
}

function heading(string $text): void
{
    echo "\n$text\n";
}

// ---------------------------------------------------------------------- fixtures

const PREFIX = 'friend-check-';

$plugin = Plugin::getInstance();

if ($plugin === null) {
    echo "Friend is not installed in this site.\n";
    exit(1);
}

$settings = $plugin->getSettings();
$originalSettings = $settings->toArray();
$siteId = Craft::$app->getSites()->getPrimarySite()->id;

$section = Craft::$app->getEntries()->getSectionByHandle('liveTest');

if ($section === null) {
    echo "No `liveTest` section in this site — nothing to build fixtures in.\n";
    exit(1);
}

$entryType = $section->getEntryTypes()[0];

/**
 * Delete every fixture this script has ever made, including strays from a run that died.
 */
$sweep = static function() use ($section): void {
    $entries = Entry::find()
        ->sectionId($section->id)
        ->slug(PREFIX . '*')
        ->status(null)
        ->all();

    foreach ($entries as $entry) {
        Craft::$app->getElements()->deleteElement($entry, true);
    }

    Craft::$app->getDb()->createCommand()->delete(Table::RULES, ['like', 'handle', 'friendCheck%', false])->execute();
    Craft::$app->getDb()->createCommand()->delete(Table::PINS, ['like', 'uri', PREFIX . '%', false])->execute();
    Craft::$app->getDb()->createCommand()->delete(Table::LOG, ['like', 'uri', PREFIX . '%', false])->execute();
    Craft::$app->getDb()->createCommand()->delete(Table::LOG, ['like', 'uri', 'live-test/' . PREFIX . '%', false])->execute();
};

$sweep();

$makeEntry = static function(string $slug, string $title) use ($section, $entryType, $siteId): Entry {
    $entry = new Entry();
    $entry->sectionId = $section->id;
    $entry->typeId = $entryType->id;
    $entry->siteId = $siteId;
    $entry->title = $title;
    $entry->slug = $slug;
    $entry->enabled = true;

    if (!Craft::$app->getElements()->saveElement($entry)) {
        throw new RuntimeException("Could not save fixture '$slug': " . implode(' ', $entry->getFirstErrors()));
    }

    // Console saves queue the index update; the search retrieval check needs it now.
    Craft::$app->getSearch()->indexElementAttributes($entry);

    return $entry;
};

$fixtures = [];

try {
    $fixtures['office'] = $makeEntry(PREFIX . 'our-new-office', 'Our New Office');
    $fixtures['hub'] = $makeEntry(PREFIX . 'hub', 'Friend Check Hub');
    $fixtures['audits'] = $makeEntry(PREFIX . 'seo-audits', 'SEO Audits');
    $fixtures['disabled'] = $makeEntry(PREFIX . 'archived-page', 'Archived Page');

    $fixtures['disabled']->enabled = false;
    Craft::$app->getElements()->saveElement($fixtures['disabled']);

    $officeUri = 'live-test/' . PREFIX . 'our-new-office';

    // A rule pointed only at the fixtures, so nothing in this file depends on what else happens
    // to be in the harness.
    $baseRule = static function(array $config = []) use ($section): Rule {
        return new Rule(array_merge([
            'name' => 'Friend check rule',
            'handle' => 'friendCheckRule',
            'elementType' => Entry::class,
            'sectionIds' => [$section->id],
            'methods' => [Rule::METHOD_SLUG, Rule::METHOD_TOKENS, Rule::METHOD_SEARCH],
            'threshold' => 55,
            'action' => Rule::ACTION_REDIRECT,
        ], $config));
    };

    // ------------------------------------------------------------------ Uris

    heading('URIs');

    check('normalize strips slashes, query and fragment', fn() => Uris::normalize('/blog/post/?a=1#x') === 'blog/post');
    check('normalize accepts a whole URL', fn() => Uris::normalize('https://example.com/a/b') === 'a/b');
    check('normalize collapses repeated slashes', fn() => Uris::normalize('/blog//post') === 'blog/post');
    check('normalize turns __home__ into an empty string', fn() => Uris::normalize('__home__') === '');
    check('a request for / and the homepage compare equal', fn() => Uris::normalize('/') === Uris::normalize('__home__'));
    check('segments splits a path', fn() => Uris::segments('a/b/c') === ['a', 'b', 'c']);
    check('segments of an empty URI is an empty array', fn() => Uris::segments('') === []);
    check('slug is the last segment without its extension', fn() => Uris::slug('/blog/2024/a-post.html') === 'a-post');
    check('extension is lowercased', fn() => Uris::extension('/x/IMAGE.PNG') === 'png');
    check('extension is null when there is none', fn() => Uris::extension('/blog/post') === null);
    check('ancestors are longest first and exclude the URI itself', fn() => Uris::ancestors('a/b/c') === ['a/b', 'a']);
    check('a single-segment URI has no ancestors', fn() => Uris::ancestors('a') === []);
    check('glob patterns match', fn() => Uris::matchesPattern('wp-admin/index.php', 'wp-*'));
    check('glob patterns are anchored at both ends', fn() => !Uris::matchesPattern('blog/wp-admin', 'wp-*'));
    check('re: patterns are treated as regular expressions', fn() => Uris::matchesPattern('blog/2024/x', 're:^blog/\d{4}/'));
    check('a broken re: pattern does not throw', fn() => Uris::matchesPattern('anything', 're:([') === false);
    check('matchesAny ignores non-strings', fn() => Uris::matchesAny('wp-login.php', [null, 42, 'wp-*']));
    check('ancestors stop at the cap, nearest first', function() {
        $ancestors = Uris::ancestors(implode('/', array_fill(0, 200, 'a')) . '/x');

        return count($ancestors) === Uris::MAX_ANCESTORS && substr_count($ancestors[0], '/') === 199;
    });
    check('a site URI and http(s) URLs are safe targets', fn() => Uris::isSafeTarget('blog') && Uris::isSafeTarget('/blog') && Uris::isSafeTarget('https://example.com/a') && Uris::isSafeTarget('//example.com'));
    check('other schemes are not safe targets', fn() => !Uris::isSafeTarget('javascript:alert(1)') && !Uris::isSafeTarget('data:text/html,x') && !Uris::isSafeTarget('https:evil.com'));

    // ------------------------------------------------------------------ Similarity

    heading('Similarity');

    check('tokenize splits on punctuation and lowercases', fn() => Similarity::tokenize('Our-New_Office') === ['our', 'new', 'office']);
    check('tokenize splits camelCase', fn() => Similarity::tokenize('ourNewOffice') === ['our', 'new', 'office']);
    check('tokenize transliterates', fn() => Similarity::tokenize('café') === ['cafe']);
    check('tokenize drops stop words', fn() => Similarity::tokenize('the-state-of-the-art') === ['state', 'art']);
    check('tokenize keeps bare numbers below the length floor', fn() => in_array('1', Similarity::tokenize('guide-part-1'), true));
    check('a number is never treated as a stop word', fn() => Similarity::tokenize('2', 3) === ['2']);
    check('two slugs differing only by a digit are not identical', function() {
        return Similarity::dice(Similarity::tokenize('guide-part-1'), Similarity::tokenize('guide-part-2')) < 1.0;
    });
    check('tokenize stems a trailing plural', fn() => Similarity::tokenize('offices') === ['office']);
    check('tokenize does not stem a double s', fn() => Similarity::tokenize('address') === ['address']);
    check('tokenize de-duplicates', fn() => Similarity::tokenize('news-news') === ['new']);
    check('dice of identical sets is 1', fn() => Similarity::dice(['a', 'b'], ['a', 'b']) === 1.0);
    check('dice of disjoint sets is 0', fn() => Similarity::dice(['a'], ['b']) === 0.0);
    check('dice of an empty set is 0', fn() => Similarity::dice([], ['a']) === 0.0);
    check('textRatio of identical strings is 1', fn() => Similarity::textRatio('abc', 'abc') === 1.0);
    check('textRatio is case and accent insensitive', fn() => Similarity::textRatio('Café', 'cafe') === 1.0);
    check('levenshteinRatio survives a 400-character string', function() {
        $long = str_repeat('a', 400);

        return Similarity::levenshteinRatio($long, $long) === 1.0;
    });
    check('pathAffinity counts leading segments only', fn() => Similarity::pathAffinity(['blog', 'x'], ['blog', 'y']) === 0.5);
    check('pathAffinity of unrelated first segments is 0', fn() => Similarity::pathAffinity(['news'], ['blog']) === 0.0);
    check('pathAffinity of two empty paths is 1', fn() => Similarity::pathAffinity([], []) === 1.0);
    check('blend normalises relative weights', fn() => Similarity::blend(['a' => 1.0, 'b' => 0.0], ['a' => 3, 'b' => 1]) === 75.0);
    check('blend with all-zero weights falls back to an even split', fn() => Similarity::blend(['a' => 1.0, 'b' => 0.0], ['a' => 0, 'b' => 0]) === 50.0);
    check('blend clamps a score above 1', fn() => Similarity::blend(['a' => 5.0], ['a' => 1]) === 100.0);

    // ------------------------------------------------------------------ Rule model

    heading('Rule model');

    check('a rule with no retrieval methods is invalid', function() use ($baseRule) {
        $rule = $baseRule(['methods' => []]);

        return !$rule->validate() && $rule->hasErrors('methods');
    });
    check('an unknown retrieval method is dropped', function() use ($baseRule) {
        $rule = $baseRule(['methods' => [Rule::METHOD_SLUG, 'telepathy']]);
        $rule->validate();

        return $rule->methods === [Rule::METHOD_SLUG];
    });
    check('a broken regex URI pattern is rejected', function() use ($baseRule) {
        $rule = $baseRule(['uriPattern' => 're:([']);

        return !$rule->validate() && $rule->hasErrors('uriPattern');
    });
    check('a URL fallback with no URL is rejected', function() use ($baseRule) {
        $rule = $baseRule(['fallback' => Rule::FALLBACK_URL]);

        return !$rule->validate() && $rule->hasErrors('fallbackUrl');
    });
    check('an element type without URLs is rejected', function() use ($baseRule) {
        $rule = $baseRule(['elementType' => craft\elements\User::class]);
        $rule->validate();

        return $rule->hasErrors('elementType');
    });
    check('a threshold over 100 is rejected', function() use ($baseRule) {
        $rule = $baseRule(['threshold' => 140]);

        return !$rule->validate() && $rule->hasErrors('threshold');
    });
    check('appliesToSite is true for an unrestricted rule', fn() => $baseRule()->appliesToSite($siteId));
    check('appliesToSite is false for another site', fn() => !$baseRule(['siteIds' => [$siteId + 9999]])->appliesToSite($siteId));
    check('usesMethod reads the method list', fn() => $baseRule()->usesMethod(Rule::METHOD_SLUG) && !$baseRule()->usesMethod(Rule::METHOD_ANCESTOR));
    check('jsonBlocks and fromRow round-trip every setting', function() use ($baseRule) {
        $rule = $baseRule([
            'uriPattern' => 'blog/*',
            'excludePatterns' => ['blog/drafts/*'],
            'minSegments' => 2,
            'maxSegments' => 4,
            'uriPrefix' => 'blog',
            'enabledOnly' => false,
            'weightSlug' => 70,
            'weightTitle' => 20,
            'weightPath' => 10,
            'candidateLimit' => 12,
            'fallback' => Rule::FALLBACK_URL,
            'fallbackUrl' => 'blog',
            'siteIds' => [1, 2],
        ]);

        $row = array_merge([
            'id' => 1,
            'name' => $rule->name,
            'handle' => $rule->handle,
            'description' => null,
            'enabled' => true,
            'sortOrder' => 1,
            'action' => $rule->action,
            'statusCode' => null,
            'threshold' => $rule->threshold,
        ], $rule->jsonBlocks());

        $restored = Rule::fromRow($row);

        foreach (['uriPattern', 'excludePatterns', 'minSegments', 'maxSegments', 'uriPrefix', 'enabledOnly', 'weightSlug', 'weightTitle', 'weightPath', 'candidateLimit', 'fallback', 'fallbackUrl', 'siteIds', 'methods', 'sectionIds'] as $attribute) {
            if ($restored->$attribute != $rule->$attribute) {
                return "$attribute did not round-trip";
            }
        }

        return true;
    });
    check('fromRow keeps defaults for missing blocks', function() {
        $rule = Rule::fromRow([
            'id' => 1, 'name' => 'x', 'handle' => 'x', 'enabled' => true,
            'sortOrder' => 1, 'action' => '', 'threshold' => 55,
        ]);

        return $rule->elementType === Entry::class && $rule->enabledOnly === true && $rule->action === Rule::ACTION_REDIRECT;
    });

    // ------------------------------------------------------------------ Settings

    heading('Settings');

    check('editable-table rows are flattened to strings', function() {
        $model = new justinholtweb\friend\models\Settings();
        $model->ignoredPatterns = [['pattern' => 'wp-*'], ['pattern' => '']];
        $model->validate();

        return $model->ignoredPatterns === ['wp-*'];
    });
    check('extensions are lowercased and stripped of their dot', function() {
        $model = new justinholtweb\friend\models\Settings();
        $model->ignoredExtensions = [['extension' => '.JPG']];
        $model->validate();

        return $model->ignoredExtensions === ['jpg'];
    });
    check('no setting is required, so a blank model still validates', function() {
        $model = new justinholtweb\friend\models\Settings();
        $model->ignoredPatterns = [];
        $model->ignoredExtensions = [];

        return $model->validate();
    });
    check('an out-of-range status code is rejected', function() {
        $model = new justinholtweb\friend\models\Settings();
        $model->redirectStatusCode = 418;

        return !$model->validate();
    });

    // ------------------------------------------------------------------ Rules service

    heading('Rules service');

    $rules = $plugin->getRules();

    check('a rule saves and comes back by handle', function() use ($rules, $baseRule) {
        $rule = $baseRule();

        if (!$rules->saveRule($rule)) {
            return implode(' ', $rule->getFirstErrors());
        }

        return $rules->getRuleByHandle('friendCheckRule')?->id === $rule->id;
    });

    $savedRule = $rules->getRuleByHandle('friendCheckRule');

    check('a duplicate handle is rejected', function() use ($rules, $baseRule) {
        $duplicate = $baseRule();

        return !$rules->saveRule($duplicate) && $duplicate->hasErrors('handle');
    });
    check('a new rule lands at the bottom of the order', function() use ($rules, $baseRule, $savedRule) {
        $second = $baseRule(['name' => 'Friend check rule two', 'handle' => 'friendCheckRuleTwo']);
        $rules->saveRule($second);

        return $second->sortOrder > $savedRule->sortOrder;
    });
    check('reorder rewrites sortOrder', function() use ($rules) {
        $all = $rules->getAllRules();
        $ids = array_map(static fn(Rule $r) => $r->id, $all);
        $reversed = array_reverse($ids);
        $rules->reorderRules($reversed);
        $after = array_map(static fn(Rule $r) => $r->id, $rules->getAllRules());
        $rules->reorderRules($ids);

        return $after === $reversed;
    });
    check('toggling a rule disables it', function() use ($rules, $savedRule) {
        $rules->toggleRule($savedRule->id, false);
        $off = $rules->getRuleById($savedRule->id)->enabled === false;
        $rules->toggleRule($savedRule->id, true);

        return $off && $rules->getRuleById($savedRule->id)->enabled === true;
    });
    check('getEnabledRules excludes rules for other sites', function() use ($rules, $savedRule, $siteId) {
        $rule = $rules->getRuleById($savedRule->id);
        $rule->siteIds = [$siteId + 9999];
        $rules->saveRule($rule);
        $absent = !in_array($savedRule->id, array_map(static fn(Rule $r) => $r->id, $rules->getEnabledRules($siteId)), true);
        $rule->siteIds = [];
        $rules->saveRule($rule);

        return $absent;
    });
    check('deleting the second rule removes it', function() use ($rules) {
        $second = $rules->getRuleByHandle('friendCheckRuleTwo');
        $rules->deleteRuleById($second->id);

        return $rules->getRuleByHandle('friendCheckRuleTwo') === null;
    });

    // ------------------------------------------------------------------ Candidates

    heading('Candidates');

    $candidates = $plugin->getCandidates();

    check('exact slug retrieval finds a moved page', function() use ($candidates, $baseRule, $siteId) {
        $rule = $baseRule(['methods' => [Rule::METHOD_SLUG]]);
        $found = $candidates->find($rule, Miss::fromUri('news/' . PREFIX . 'our-new-office', $siteId));

        return count($found) === 1 && $found[0]->uri === 'live-test/' . PREFIX . 'our-new-office';
    });
    check('an exact slug match at a different path still clears a 55 threshold', function() use ($candidates, $baseRule, $siteId) {
        $rule = $baseRule(['methods' => [Rule::METHOD_SLUG]]);
        $found = $candidates->find($rule, Miss::fromUri('news/' . PREFIX . 'our-new-office', $siteId));

        return $found[0]->score >= 55 ?: 'scored ' . $found[0]->score;
    });
    check('word overlap finds a near miss', function() use ($candidates, $baseRule, $siteId) {
        $rule = $baseRule(['methods' => [Rule::METHOD_TOKENS]]);
        $found = $candidates->find($rule, Miss::fromUri('live-test/' . PREFIX . 'our-new-offices', $siteId));

        return (bool)array_filter($found, static fn($c) => str_ends_with($c->uri, 'our-new-office'));
    });
    check('the search index finds a page by its title words', function() use ($candidates, $baseRule, $siteId) {
        $rule = $baseRule(['methods' => [Rule::METHOD_SEARCH]]);
        $found = $candidates->find($rule, Miss::fromUri('live-test/seo-audits-2019', $siteId));

        return (bool)array_filter($found, static fn($c) => str_ends_with($c->uri, 'seo-audits'));
    });
    check('the ancestor method finds the surviving parent path', function() use ($candidates, $baseRule, $siteId) {
        $rule = $baseRule(['methods' => [Rule::METHOD_ANCESTOR]]);
        $found = $candidates->find($rule, Miss::fromUri('live-test/' . PREFIX . 'hub/a-child', $siteId));

        return count($found) === 1 && str_ends_with($found[0]->uri, PREFIX . 'hub');
    });
    check('element-query syntax in a URI is taken literally', function() use ($candidates, $baseRule, $siteId) {
        $slug = $candidates->find($baseRule(['methods' => [Rule::METHOD_SLUG]]), Miss::fromUri('news/*', $siteId));
        $ancestor = $candidates->find(
            $baseRule(['methods' => [Rule::METHOD_ANCESTOR]]),
            Miss::fromUri('nothing,live-test/' . PREFIX . 'hub/x', $siteId),
        );

        return $slug === [] && $ancestor === [];
    });
    check('ancestor score is the share of the path that survived', function() use ($candidates, $baseRule, $siteId) {
        $rule = $baseRule(['methods' => [Rule::METHOD_ANCESTOR]]);
        $found = $candidates->find($rule, Miss::fromUri('live-test/' . PREFIX . 'hub/a-child', $siteId));

        // 2 surviving segments of 3.
        return abs($found[0]->score - 66.7) < 0.2 ?: 'scored ' . $found[0]->score;
    });
    check('disabled elements are not candidates', function() use ($candidates, $baseRule, $siteId) {
        $rule = $baseRule(['methods' => [Rule::METHOD_SLUG]]);
        $found = $candidates->find($rule, Miss::fromUri('news/' . PREFIX . 'archived-page', $siteId));

        return $found === [];
    });
    check('switching off enabledOnly does reach a disabled element', function() use ($candidates, $baseRule, $siteId) {
        $rule = $baseRule(['methods' => [Rule::METHOD_SLUG], 'enabledOnly' => false]);
        $found = $candidates->find($rule, Miss::fromUri('news/' . PREFIX . 'archived-page', $siteId));

        return count($found) === 1;
    });
    check('a candidate at the URI that just missed is discarded', function() use ($candidates, $baseRule, $siteId) {
        // The disabled fixture keeps its URI, so an `enabledOnly: false` rule can find the very
        // page that 404'd — and redirecting there is an infinite loop.
        $rule = $baseRule(['methods' => [Rule::METHOD_SLUG], 'enabledOnly' => false]);
        $found = $candidates->find($rule, Miss::fromUri('live-test/' . PREFIX . 'archived-page', $siteId));

        return $found === [];
    });
    check('uriPrefix narrows candidates away', function() use ($candidates, $baseRule, $siteId) {
        $rule = $baseRule(['methods' => [Rule::METHOD_SLUG], 'uriPrefix' => 'somewhere-else']);

        return $candidates->find($rule, Miss::fromUri('news/' . PREFIX . 'our-new-office', $siteId)) === [];
    });
    check('sectionIds narrows candidates away', function() use ($candidates, $baseRule, $siteId) {
        $rule = $baseRule(['methods' => [Rule::METHOD_SLUG], 'sectionIds' => [999999]]);

        return $candidates->find($rule, Miss::fromUri('news/' . PREFIX . 'our-new-office', $siteId)) === [];
    });
    check('candidates come back best first', function() use ($candidates, $baseRule, $siteId) {
        $found = $candidates->find($baseRule(), Miss::fromUri('live-test/' . PREFIX . 'our-new-office-2', $siteId));
        $scores = array_map(static fn($c) => $c->score, $found);
        $sorted = $scores;
        rsort($sorted);

        return $scores === $sorted;
    });
    check('candidateLimit caps the result set', function() use ($candidates, $baseRule, $siteId) {
        $found = $candidates->find($baseRule(['candidateLimit' => 1]), Miss::fromUri('live-test/' . PREFIX . 'our', $siteId));

        return count($found) <= 1;
    });
    check('a candidate carries a usable absolute URL', function() use ($candidates, $baseRule, $siteId) {
        $found = $candidates->find($baseRule(['methods' => [Rule::METHOD_SLUG]]), Miss::fromUri('news/' . PREFIX . 'our-new-office', $siteId));

        return str_starts_with((string)$found[0]->url, 'http') && str_ends_with((string)$found[0]->url, PREFIX . 'our-new-office');
    });
    check('the breakdown is recorded for every dimension', function() use ($candidates, $baseRule, $siteId) {
        $found = $candidates->find($baseRule(['methods' => [Rule::METHOD_SLUG]]), Miss::fromUri('news/' . PREFIX . 'our-new-office', $siteId));

        return isset($found[0]->breakdown['slug'], $found[0]->breakdown['title'], $found[0]->breakdown['path']);
    });
    check('a URI with no usable tokens returns nothing rather than everything', function() use ($candidates, $baseRule, $siteId) {
        return $candidates->find($baseRule(), Miss::fromUri('', $siteId)) === [];
    });

    // ------------------------------------------------------------------ Matcher

    heading('Matcher');

    $matcher = $plugin->getMatcher();
    $settings->cacheDuration = 0;

    $withRule = static function(array $config, callable $body) use ($rules, $baseRule) {
        $rule = $rules->getRuleByHandle('friendCheckRule');

        foreach ($config as $attribute => $value) {
            $rule->$attribute = $value;
        }

        if (!$rules->saveRule($rule)) {
            throw new RuntimeException('Could not save the check rule: ' . implode(' ', $rule->getFirstErrors()));
        }

        return $body($rule);
    };

    // Every other rule in the site is stood down for the matcher checks, so the outcome is about
    // the rule under test and nothing else.
    $otherRuleIds = [];

    foreach ($rules->getAllRules() as $rule) {
        if ($rule->handle !== 'friendCheckRule' && $rule->enabled) {
            $otherRuleIds[] = $rule->id;
            $rules->toggleRule($rule->id, false);
        }
    }

    check('an eligible miss with a strong match redirects', function() use ($matcher, $withRule, $siteId, $officeUri) {
        return $withRule([], function() use ($matcher, $siteId, $officeUri) {
            $outcome = $matcher->resolve(Miss::fromUri('news/' . PREFIX . 'our-new-office', $siteId));

            return $outcome->shouldRedirect() && str_ends_with((string)$outcome->targetUrl, PREFIX . 'our-new-office');
        });
    });
    check('the redirect uses the plugin default status', function() use ($matcher, $withRule, $siteId, $settings) {
        return $withRule(['statusCode' => null], function() use ($matcher, $siteId, $settings) {
            return $matcher->resolve(Miss::fromUri('news/' . PREFIX . 'our-new-office', $siteId))->statusCode === $settings->redirectStatusCode;
        });
    });
    check('a rule status code overrides the default', function() use ($matcher, $withRule, $siteId) {
        return $withRule(['statusCode' => 301], function() use ($matcher, $siteId) {
            return $matcher->resolve(Miss::fromUri('news/' . PREFIX . 'our-new-office', $siteId))->statusCode === 301;
        });
    });
    check('a threshold of 100 makes the rule decline', function() use ($matcher, $withRule, $siteId) {
        return $withRule(['threshold' => 100, 'statusCode' => null], function() use ($matcher, $siteId) {
            $outcome = $matcher->resolve(Miss::fromUri('news/' . PREFIX . 'our-new-officey', $siteId));

            return !$outcome->shouldRedirect() && $outcome->action === Rule::ACTION_NONE;
        });
    });
    check('declining still carries the candidates for a suggestions list', function() use ($matcher, $withRule, $siteId) {
        return $withRule(['threshold' => 100], function() use ($matcher, $siteId) {
            return count($matcher->resolve(Miss::fromUri('news/' . PREFIX . 'our-new-officey', $siteId))->candidates) > 0;
        });
    });
    check('the suggest action matches without redirecting', function() use ($matcher, $withRule, $siteId) {
        return $withRule(['threshold' => 55, 'action' => Rule::ACTION_SUGGEST], function() use ($matcher, $siteId) {
            $outcome = $matcher->resolve(Miss::fromUri('news/' . PREFIX . 'our-new-office', $siteId));

            return $outcome->matched() && !$outcome->shouldRedirect() && $outcome->candidates !== [];
        });
    });
    check('the ignore action decides and redirects nowhere', function() use ($matcher, $withRule, $siteId) {
        return $withRule(['action' => Rule::ACTION_IGNORE], function() use ($matcher, $siteId) {
            $outcome = $matcher->resolve(Miss::fromUri('news/' . PREFIX . 'our-new-office', $siteId));

            return $outcome->matched() && $outcome->action === Rule::ACTION_IGNORE && !$outcome->shouldRedirect();
        });
    });
    check('a URL fallback fires when nothing clears the threshold', function() use ($matcher, $withRule, $siteId) {
        return $withRule([
            'action' => Rule::ACTION_REDIRECT,
            'threshold' => 100,
            'fallback' => Rule::FALLBACK_URL,
            'fallbackUrl' => 'live-test/' . PREFIX . 'hub',
        ], function() use ($matcher, $siteId) {
            $outcome = $matcher->resolve(Miss::fromUri('news/' . PREFIX . 'our-new-officey', $siteId));

            return $outcome->shouldRedirect() && str_ends_with((string)$outcome->targetUrl, PREFIX . 'hub');
        });
    });
    check('a uriPattern that does not match skips the rule', function() use ($matcher, $withRule, $siteId) {
        return $withRule([
            'threshold' => 55,
            'fallback' => Rule::FALLBACK_NONE,
            'fallbackUrl' => null,
            'uriPattern' => 'shop/*',
        ], function() use ($matcher, $siteId) {
            $outcome = $matcher->resolve(Miss::fromUri('news/' . PREFIX . 'our-new-office', $siteId), true);

            return !$outcome->matched() && $outcome->traces[0]->status === Trace::SKIPPED;
        });
    });
    check('an exclude pattern skips the rule', function() use ($matcher, $withRule, $siteId) {
        return $withRule(['uriPattern' => null, 'excludePatterns' => ['news/*']], function() use ($matcher, $siteId) {
            return !$matcher->resolve(Miss::fromUri('news/' . PREFIX . 'our-new-office', $siteId))->matched();
        });
    });
    check('minSegments skips a shallower path', function() use ($matcher, $withRule, $siteId) {
        return $withRule(['excludePatterns' => [], 'minSegments' => 3], function() use ($matcher, $siteId) {
            return !$matcher->resolve(Miss::fromUri('news/' . PREFIX . 'our-new-office', $siteId))->matched();
        });
    });
    check('maxSegments skips a deeper path', function() use ($matcher, $withRule, $siteId) {
        return $withRule(['minSegments' => null, 'maxSegments' => 1], function() use ($matcher, $siteId) {
            return !$matcher->resolve(Miss::fromUri('news/' . PREFIX . 'our-new-office', $siteId))->matched();
        });
    });
    check('a trace is produced for every rule considered', function() use ($matcher, $withRule, $siteId) {
        return $withRule(['maxSegments' => null], function() use ($matcher, $siteId) {
            $outcome = $matcher->resolve(Miss::fromUri('news/' . PREFIX . 'our-new-office', $siteId), true);

            return count($outcome->traces) >= 1 && $outcome->traces[0]->reason !== '';
        });
    });
    check('an ignored extension is not eligible', fn() => !$matcher->uriIsEligible('assets/logo.PNG'));
    check('an ignored pattern is not eligible', fn() => !$matcher->uriIsEligible('wp-admin/setup-config.php'));
    check('the homepage is never eligible', fn() => !$matcher->uriIsEligible(''));
    check('an ordinary page is eligible', fn() => $matcher->uriIsEligible('blog/a-post'));
    check('configRedirectCovers declines to answer outside the matching request', fn() => $matcher->configRedirectCovers('anything') === false);

    // ------------------------------------------------------------------ Pins

    heading('Pins');

    $pins = $plugin->getPins();

    check('the same site and URI always hash the same', fn() => justinholtweb\friend\services\Pins::hash(1, '/a/b') === justinholtweb\friend\services\Pins::hash(1, 'a/b'));
    check('different sites hash differently', fn() => justinholtweb\friend\services\Pins::hash(1, 'a') !== justinholtweb\friend\services\Pins::hash(2, 'a'));
    check('a pin saves and is found by URI', function() use ($pins, $fixtures, $siteId) {
        $pin = new Pin([
            'siteId' => null,
            'uri' => PREFIX . 'pinned-path',
            'targetType' => Pin::TARGET_ELEMENT,
            'elementId' => $fixtures['hub']->id,
        ]);

        if (!$pins->savePin($pin)) {
            return implode(' ', $pin->getFirstErrors());
        }

        return $pins->find($siteId, PREFIX . 'pinned-path')?->id === $pin->id;
    });
    check('a site-specific pin beats a site-agnostic one', function() use ($pins, $fixtures, $siteId) {
        $specific = new Pin([
            'siteId' => $siteId,
            'uri' => PREFIX . 'pinned-path',
            'targetType' => Pin::TARGET_ELEMENT,
            'elementId' => $fixtures['audits']->id,
        ]);

        if (!$pins->savePin($specific)) {
            return implode(' ', $specific->getFirstErrors());
        }

        return $pins->find($siteId, PREFIX . 'pinned-path')?->siteId === $siteId;
    });
    check('a pin resolves an element to its URL', function() use ($pins, $siteId) {
        return str_ends_with((string)$pins->find($siteId, PREFIX . 'pinned-path')?->getTargetUrl(), PREFIX . 'seo-audits');
    });
    check('a URL pin refuses a javascript: target', function() use ($siteId) {
        $pin = new Pin(['siteId' => $siteId, 'uri' => 'x', 'targetType' => Pin::TARGET_URL, 'url' => 'javascript:alert(1)']);

        return !$pin->validate() && $pin->hasErrors('url');
    });
    check('a URL pin resolves a bare URI against the site', function() use ($siteId) {
        $pin = new Pin(['siteId' => $siteId, 'uri' => 'x', 'targetType' => Pin::TARGET_URL, 'url' => 'somewhere']);

        return str_starts_with((string)$pin->getTargetUrl(), 'http');
    });
    check('a URL pin leaves an absolute URL alone', function() {
        $pin = new Pin(['uri' => 'x', 'targetType' => Pin::TARGET_URL, 'url' => 'https://example.com/a']);

        return $pin->getTargetUrl() === 'https://example.com/a';
    });
    check('a pin pointing at nothing is invalid', function() {
        $pin = new Pin(['uri' => 'x', 'targetType' => Pin::TARGET_ELEMENT]);

        return !$pin->validate() && $pin->hasErrors('elementId');
    });
    check('a pin URI is normalised on the way in', function() {
        $pin = new Pin(['uri' => '/a/b/', 'targetType' => Pin::TARGET_URL, 'url' => 'x']);
        $pin->validate();

        return $pin->uri === 'a/b';
    });
    check('a pin beats every rule', function() use ($matcher, $siteId) {
        $outcome = $matcher->resolve(Miss::fromUri(PREFIX . 'pinned-path', $siteId));

        return $outcome->source === Outcome::SOURCE_PIN
            && $outcome->shouldRedirect()
            && str_ends_with((string)$outcome->targetUrl, PREFIX . 'seo-audits');
    });
    check('a pin records its hits', function() use ($pins, $matcher, $siteId) {
        $before = $pins->find($siteId, PREFIX . 'pinned-path')->hits;
        $matcher->resolve(Miss::fromUri(PREFIX . 'pinned-path', $siteId));

        return $pins->find($siteId, PREFIX . 'pinned-path')->hits > $before;
    });
    check('a pin pointing at its own URI is refused rather than looping', function() use ($pins, $matcher, $siteId) {
        $pin = new Pin([
            'siteId' => $siteId,
            'uri' => PREFIX . 'loop',
            'targetType' => Pin::TARGET_URL,
            'url' => PREFIX . 'loop',
        ]);
        $pins->savePin($pin);

        $outcome = $matcher->resolve(Miss::fromUri(PREFIX . 'loop', $siteId));
        $pins->deletePinById($pin->id);

        return $outcome->source !== Outcome::SOURCE_PIN;
    });
    check('a deleted pin stops being found', function() use ($pins, $siteId) {
        foreach ($pins->getAllPins() as $pin) {
            if (str_starts_with($pin->uri, PREFIX)) {
                $pins->deletePinById($pin->id);
            }
        }

        return $pins->find($siteId, PREFIX . 'pinned-path') === null;
    });

    // ------------------------------------------------------------------ Log

    heading('Log');

    $log = $plugin->getLog();
    $settings->logMisses = true;

    check('a miss is recorded', function() use ($log, $matcher, $siteId) {
        $miss = Miss::fromUri('live-test/' . PREFIX . 'logged-path', $siteId);
        $log->record($miss, $matcher->resolve($miss));

        return $log->getTotal(['search' => PREFIX . 'logged-path']) === 1;
    });
    check('a second hit on the same URI increments rather than inserting', function() use ($log, $matcher, $siteId) {
        $miss = Miss::fromUri('live-test/' . PREFIX . 'logged-path', $siteId);
        $log->record($miss, $matcher->resolve($miss));
        $entries = $log->getEntries(['search' => PREFIX . 'logged-path']);

        return count($entries) === 1 && $entries[0]->hits === 2;
    });
    check('a redirected miss records its target and score', function() use ($log, $matcher, $withRule, $siteId) {
        return $withRule(['threshold' => 55, 'fallback' => Rule::FALLBACK_NONE, 'fallbackUrl' => null], function() use ($log, $matcher, $siteId) {
            $miss = Miss::fromUri('live-test/' . PREFIX . 'our-new-officex', $siteId);
            $log->record($miss, $matcher->resolve($miss));
            $entry = $log->getEntries(['search' => PREFIX . 'our-new-officex'])[0] ?? null;

            return $entry !== null && $entry->wasRedirected() && $entry->score > 0 && $entry->targetUrl !== null;
        });
    });
    check('logging can be switched off', function() use ($log, $matcher, $settings, $siteId) {
        $settings->logMisses = false;
        $miss = Miss::fromUri('live-test/' . PREFIX . 'not-logged', $siteId);
        $log->record($miss, $matcher->resolve($miss));
        $settings->logMisses = true;

        return $log->getTotal(['search' => PREFIX . 'not-logged']) === 0;
    });
    check('the summary counts rows and hits', function() use ($log) {
        $summary = $log->getSummary();

        return $summary['total'] >= 2 && $summary['hits'] >= 3 && is_int($summary['redirected']);
    });
    check('the unresolved filter excludes redirects', function() use ($log) {
        foreach ($log->getEntries(['status' => 'unresolved', 'limit' => 200]) as $entry) {
            if ($entry->wasRedirected()) {
                return 'a redirected row came back as unresolved';
            }
        }

        return true;
    });
    check('a very long URI is stored without blowing the column', function() use ($log, $matcher, $siteId) {
        $long = 'live-test/' . PREFIX . str_repeat('a', 900);
        $miss = Miss::fromUri($long, $siteId);
        $log->record($miss, $matcher->resolve($miss));

        return $log->getTotal(['search' => PREFIX . str_repeat('a', 100)]) === 1;
    });
    check('pruning by age removes stale rows', function() use ($log, $settings) {
        Craft::$app->getDb()->createCommand()->update(
            Table::LOG,
            ['dateLastHit' => Db::prepareDateForDb((new DateTime())->modify('-400 days'))],
            ['like', 'uri', 'live-test/' . PREFIX . '%', false]
        )->execute();

        $settings->logRetentionDays = 30;
        $settings->logMaxRows = 0;
        $log->prune();

        return $log->getTotal(['search' => PREFIX]) === 0;
    });
    check('pruning by row cap keeps the most recently seen', function() use ($log, $matcher, $settings, $siteId) {
        $settings->logRetentionDays = 0;

        foreach (['aa', 'bb', 'cc'] as $index => $suffix) {
            $miss = Miss::fromUri('live-test/' . PREFIX . 'cap-' . $suffix, $siteId);
            $log->record($miss, $matcher->resolve($miss));
        }

        $before = $log->getTotal();
        $settings->logMaxRows = max(1, $before - 2);
        $log->prune();
        $after = $log->getTotal();
        $settings->logMaxRows = 10000;

        return $after <= $before - 2;
    });

    // ------------------------------------------------------------------ caching

    heading('Caching');

    check('a resolved outcome is served from the cache the second time', function() use ($matcher, $settings, $rules, $siteId) {
        $settings->cacheDuration = 60;
        $miss = Miss::fromUri('news/' . PREFIX . 'our-new-office', $siteId);
        $first = $matcher->resolve($miss);

        // Disable every rule *behind* the cache's back so a second resolve can only come back
        // right if it never ran the rules at all.
        Craft::$app->getDb()->createCommand()->update(Table::RULES, ['enabled' => false])->execute();

        $second = $matcher->resolve($miss);

        Craft::$app->getDb()->createCommand()->update(Table::RULES, ['enabled' => true], ['handle' => 'friendCheckRule'])->execute();

        return $first->targetUrl === $second->targetUrl && $second->targetUrl !== null;
    });
    check('clearing caches makes the next resolve run the rules again', function() use ($matcher, $rules, $siteId) {
        Craft::$app->getDb()->createCommand()->update(Table::RULES, ['enabled' => false])->execute();
        $rules->clearCaches();

        $outcome = $matcher->resolve(Miss::fromUri('news/' . PREFIX . 'our-new-office', $siteId));

        Craft::$app->getDb()->createCommand()->update(Table::RULES, ['enabled' => true], ['handle' => 'friendCheckRule'])->execute();
        $rules->clearCaches();

        return !$outcome->matched();
    });
    check('a suggestion outcome is never cached', function() use ($matcher, $withRule, $settings, $rules, $siteId) {
        $settings->cacheDuration = 60;

        return $withRule(['action' => Rule::ACTION_SUGGEST, 'threshold' => 55], function() use ($matcher, $rules, $siteId) {
            $miss = Miss::fromUri('news/' . PREFIX . 'our-new-office', $siteId);
            $matcher->resolve($miss);

            Craft::$app->getDb()->createCommand()->update(Table::RULES, ['enabled' => false])->execute();
            $rules->clearCaches();
            $second = $matcher->resolve($miss);
            Craft::$app->getDb()->createCommand()->update(Table::RULES, ['enabled' => true], ['handle' => 'friendCheckRule'])->execute();
            $rules->clearCaches();

            return !$second->matched();
        });
    });
    check('a declined miss keeps its candidates on the second resolve', function() use ($matcher, $withRule, $settings, $siteId) {
        $settings->cacheDuration = 60;

        return $withRule(['action' => Rule::ACTION_REDIRECT, 'threshold' => 100, 'fallback' => Rule::FALLBACK_NONE], function() use ($matcher, $siteId) {
            $miss = Miss::fromUri('news/' . PREFIX . 'our-new-officee', $siteId);
            $first = $matcher->resolve($miss);
            $second = $matcher->resolve($miss);

            return !$first->matched() && $first->candidates && $second->candidates;
        });
    });

    $settings->cacheDuration = 0;

    // ------------------------------------------------------------------ Twig variable

    heading('Twig');

    check('suggestions come back for a given URI', function() use ($withRule, $siteId) {
        return $withRule(['action' => Rule::ACTION_REDIRECT, 'threshold' => 55], function() {
            $variable = new FriendVariable();

            return count($variable->suggestions(3, 'news/' . PREFIX . 'our-new-office')) > 0;
        });
    });
    check('suggestions respect the limit', function() {
        $variable = new FriendVariable();

        return count($variable->suggestions(1, 'live-test/' . PREFIX . 'our')) <= 1;
    });
    check('best returns the winning candidate', function() {
        $variable = new FriendVariable();

        return str_ends_with((string)$variable->best('news/' . PREFIX . 'our-new-office')?->uri, PREFIX . 'our-new-office');
    });
    check('outcome is returned for an arbitrary URI', function() {
        $variable = new FriendVariable();

        return $variable->outcome('news/' . PREFIX . 'our-new-office') instanceof Outcome;
    });
    check('missedUri is empty outside a web request', function() {
        return (new FriendVariable())->missedUri() === '';
    });
    check('the master switch silences the tags', function() use ($withRule, $settings) {
        return $withRule(['action' => Rule::ACTION_REDIRECT, 'threshold' => 55], function() use ($settings) {
            $settings->enabled = false;
            $variable = new FriendVariable();
            $silent = $variable->suggestions(3, 'news/' . PREFIX . 'our-new-office') === []
                && $variable->outcome('news/' . PREFIX . 'our-new-office') === null;
            $settings->enabled = true;

            return $silent;
        });
    });

    foreach ($otherRuleIds as $id) {
        $rules->toggleRule($id, true);
    }
} finally {
    // ------------------------------------------------------------------ cleanup

    foreach ($originalSettings as $attribute => $value) {
        $settings->$attribute = $value;
    }

    $sweep();
    $plugin->getRules()->clearCaches();
}

echo "\n$passed passed, $failed failed\n\n";

exit($failed === 0 ? 0 : 1);
