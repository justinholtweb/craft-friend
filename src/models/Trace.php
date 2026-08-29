<?php

namespace justinholtweb\friend\models;

use craft\base\Model;

/**
 * Why one rule did what it did.
 *
 * Produced on every resolve, kept only when somebody asked for it — the tester, the console
 * command. A matcher nobody can interrogate is a matcher nobody will switch on.
 */
class Trace extends Model
{
    public const SKIPPED = 'skipped';
    public const NO_CANDIDATES = 'no-candidates';
    public const BELOW_THRESHOLD = 'below-threshold';
    public const MATCHED = 'matched';
    public const IGNORED = 'ignored';
    public const SUGGESTED = 'suggested';

    public ?int $ruleId = null;
    public string $ruleName = '';
    public string $status = self::SKIPPED;
    public string $reason = '';
    public ?float $bestScore = null;
    public int $candidateCount = 0;

    public function isDecisive(): bool
    {
        return in_array($this->status, [self::MATCHED, self::IGNORED, self::SUGGESTED], true);
    }
}
