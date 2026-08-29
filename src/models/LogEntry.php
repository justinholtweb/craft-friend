<?php

namespace justinholtweb\friends\models;

use Craft;
use craft\base\ElementInterface;
use craft\base\Model;
use DateTime;

/**
 * One row of the 404 log: a URI, how often it has been asked for, and what Friends did about it.
 *
 * Aggregated per site and URI rather than appended per request. A hit counter answers "what is
 * actually broken on this site" in one glance; a million-row request log answers it after a query
 * nobody writes.
 */
class LogEntry extends Model
{
    public ?int $id = null;
    public ?int $siteId = null;
    public string $uri = '';
    public ?string $referrer = null;
    public int $hits = 0;

    /** 0 when the visitor was left on the 404. */
    public int $statusCode = 0;

    public ?int $ruleId = null;
    public ?string $ruleName = null;
    public ?int $targetElementId = null;
    public ?string $targetUrl = null;
    public ?float $score = null;
    public ?string $source = null;
    public ?DateTime $dateFirstHit = null;
    public ?DateTime $dateLastHit = null;
    public ?string $uid = null;

    public function datetimeAttributes(): array
    {
        return array_merge(parent::datetimeAttributes(), ['dateFirstHit', 'dateLastHit']);
    }

    public function wasRedirected(): bool
    {
        return $this->statusCode >= 300 && $this->statusCode < 400;
    }

    public function getTargetElement(): ?ElementInterface
    {
        return $this->targetElementId
            ? Craft::$app->getElements()->getElementById($this->targetElementId, null, $this->siteId)
            : null;
    }
}
