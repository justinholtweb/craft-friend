<?php

namespace justinholtweb\friends\models;

use craft\base\ElementInterface;
use craft\base\Model;

/**
 * An element Friends thinks the visitor might have wanted, and how sure it is.
 *
 * The breakdown is carried around rather than recomputed for display, because the tester screen
 * showing a different number from the one the matcher acted on would make the tester useless.
 */
class Candidate extends Model
{
    public ?int $elementId = null;
    public ?string $elementType = null;
    public ?ElementInterface $element = null;
    public string $title = '';
    public string $uri = '';
    public ?string $url = null;

    /** 0–100. */
    public float $score = 0.0;

    /**
     * Per-dimension scores, 0–1, plus whatever a structural method wants to record.
     *
     * @var array<string, float|string>
     */
    public array $breakdown = [];

    /**
     * Which retrieval methods turned this element up. Several usually do; that is not itself a
     * signal, but it is the first thing you want to see when a score surprises you.
     *
     * @var string[]
     */
    public array $methods = [];

    public function addMethod(string $method): void
    {
        if (!in_array($method, $this->methods, true)) {
            $this->methods[] = $method;
        }
    }

    public function getLabel(): string
    {
        return $this->title !== '' ? $this->title : ($this->uri !== '' ? $this->uri : (string)$this->elementId);
    }
}
