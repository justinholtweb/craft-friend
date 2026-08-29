<?php

namespace justinholtweb\friend\models;

use craft\base\Model;

/**
 * What Friend decided about one miss.
 *
 * Always returned, even when nothing matched — a "no" carrying its reasons is what the log and the
 * tester are both built on.
 */
class Outcome extends Model
{
    public const SOURCE_PIN = 'pin';
    public const SOURCE_RULE = 'rule';

    public ?Miss $miss = null;

    /** 'pin' or 'rule', or null when nothing decided. */
    public ?string $source = null;

    public ?Rule $rule = null;
    public ?Pin $pin = null;
    public ?Candidate $candidate = null;

    /**
     * Everything the deciding rule found, best first — including the ones below its threshold,
     * because that is exactly what a 404 template wants for a "did you mean" list.
     *
     * @var Candidate[]
     */
    public array $candidates = [];

    /** @var Trace[] */
    public array $traces = [];

    public string $action = Rule::ACTION_NONE;
    public ?string $targetUrl = null;
    public int $statusCode = 0;

    public function shouldRedirect(): bool
    {
        return $this->action === Rule::ACTION_REDIRECT
            && $this->targetUrl !== null
            && $this->targetUrl !== ''
            && $this->statusCode >= 300;
    }

    public function matched(): bool
    {
        return $this->source !== null;
    }

    public function getScore(): ?float
    {
        return $this->candidate?->score;
    }

    /**
     * @return Candidate[]
     */
    public function suggestions(int $limit = 5): array
    {
        return array_slice($this->candidates, 0, max(0, $limit));
    }
}
