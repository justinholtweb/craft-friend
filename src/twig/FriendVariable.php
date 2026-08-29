<?php

namespace justinholtweb\friend\twig;

use justinholtweb\friend\models\Candidate;
use justinholtweb\friend\models\Miss;
use justinholtweb\friend\models\Outcome;
use justinholtweb\friend\Plugin;
use yii\base\BaseObject;

/**
 * `craft.friend` — what a 404 template can ask.
 *
 * The interesting case is a rule set to *suggest* rather than redirect, or one whose best
 * candidate did not clear the threshold. Friend stays out of the way, the site's own 404
 * template renders, and it can offer the near-misses as a "did you mean" list — a much lower bar
 * than moving somebody automatically, and often the more useful answer.
 */
class FriendVariable extends BaseObject
{
    /**
     * The candidates for the current request, best first.
     *
     * @return Candidate[]
     */
    public function suggestions(int $limit = 5, ?string $uri = null): array
    {
        $outcome = $this->outcome($uri);

        return $outcome ? $outcome->suggestions($limit) : [];
    }

    /**
     * Everything Friend decided about a URI — the current request's by default.
     */
    public function outcome(?string $uri = null): ?Outcome
    {
        $matcher = Plugin::getInstance()->getMatcher();

        if ($uri === null) {
            return $matcher->currentOutcome();
        }

        return $matcher->resolve(Miss::fromUri($uri), true);
    }

    /** The best candidate for the current request, or null. */
    public function best(?string $uri = null): ?Candidate
    {
        $outcome = $this->outcome($uri);

        if ($outcome === null) {
            return null;
        }

        return $outcome->candidate ?? ($outcome->candidates[0] ?? null);
    }

    /** The URI that missed, in normal form — no host, no query, no leading slash. */
    public function missedUri(): string
    {
        return Plugin::getInstance()->getMatcher()->currentOutcome()?->miss?->uri ?? '';
    }

    /** Whether Friend is switched on at all. Handy for a template that wants to say so. */
    public function enabled(): bool
    {
        return Plugin::getInstance()->getSettings()->enabled;
    }
}
