<?php

namespace justinholtweb\friend\models;

use craft\base\Model;

/**
 * Plugin-wide settings.
 *
 * Nothing here is `required`. A fresh install has to be able to save its settings before it has
 * been configured, and a `required` rule fails `savePluginSettings()` wholesale — taking every
 * unrelated setting with it.
 */
class Settings extends Model
{
    /** Master switch. Off means Friend never looks at a 404 at all. */
    public bool $enabled = true;

    /**
     * Default HTTP status for a redirect a rule does not override.
     *
     * 302, not 301. A 301 is cached by the browser and by everything between it and the server,
     * often for far longer than anyone intends — so a *guessed* permanent redirect that guesses
     * wrong leaves the visitor unable to reach the URL again even after the rule is fixed. Move to
     * 301 per rule, once the log shows that rule getting it right.
     */
    public int $redirectStatusCode = 302;

    /**
     * Stand down when a `config/redirects.php` rule already covers the URI.
     *
     * Friend hooks the top of Craft's exception handling, which is *before* Craft consults that
     * file — so without this check a fuzzy guess would quietly outrank an explicit instruction.
     */
    public bool $honourConfigRedirects = true;

    /**
     * URI patterns Friend never touches.
     *
     * Globs, or `re:` followed by a regular expression. The defaults are the paths that generate
     * most of a site's 404s and none of its lost visitors: probes for other CMSes, and anything
     * under a dot-directory.
     */
    public array $ignoredPatterns = [
        'wp-*',
        '*/wp-*',
        'xmlrpc.php',
        '.*',
        '.*/*',
        'cpresources/*',
    ];

    /**
     * File extensions that stay 404.
     *
     * A missing image or stylesheet is a broken asset reference, and redirecting one to an HTML
     * page turns a visible 404 in the network panel into an invisible wrong-content-type bug.
     */
    public array $ignoredExtensions = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'svg', 'ico', 'bmp', 'css', 'js', 'mjs',
        'map', 'json', 'xml', 'txt', 'zip', 'gz', 'rar', 'php', 'asp', 'aspx', 'jsp', 'env',
        'sql', 'woff', 'woff2', 'ttf', 'eot', 'otf', 'mp3', 'mp4', 'webm', 'mov', 'avi',
    ];

    /** Record every miss, matched or not. The log is also the 404 report. */
    public bool $logMisses = true;

    /** Days of log to keep. 0 keeps everything. */
    public int $logRetentionDays = 180;

    /** Hard cap on log rows; the least recently seen are dropped first. 0 means no cap. */
    public int $logMaxRows = 10000;

    /**
     * Seconds to cache a resolved outcome for a URI. 0 disables it.
     *
     * Dead URLs are hammered — one broken link in a newsletter, one stale sitemap, one scanner
     * working through a wordlist. The first request should pay for the search; the next thousand
     * should not.
     */
    public int $cacheDuration = 3600;

    /** Most elements any one retrieval method may pull back before scoring. */
    public int $candidateLimit = 50;

    /** Tokens shorter than this are ignored when comparing. */
    public int $minTokenLength = 2;

    /** Extra words to ignore when comparing — house jargon, a section name in every slug. */
    public array $extraStopWords = [];

    protected function defineRules(): array
    {
        return [
            [['enabled', 'honourConfigRedirects', 'logMisses'], 'boolean'],
            [['redirectStatusCode'], 'in', 'range' => [301, 302, 307, 308]],
            [['logRetentionDays', 'logMaxRows', 'cacheDuration'], 'integer', 'min' => 0],
            [['candidateLimit'], 'integer', 'min' => 1, 'max' => 500],
            [['minTokenLength'], 'integer', 'min' => 1, 'max' => 10],
            [['ignoredPatterns', 'ignoredExtensions', 'extraStopWords'], 'safe'],
        ];
    }

    /**
     * Craft's editable table posts rows (`[['pattern' => 'wp-*'], …]`), not strings, and the
     * settings screen uses one for each of these. Flattening in the validator rather than in a
     * setter keeps the properties plain arrays for every reader.
     */
    public function beforeValidate(): bool
    {
        $this->ignoredPatterns = self::flatten($this->ignoredPatterns, 'pattern');
        $this->ignoredExtensions = array_map(
            static fn(string $extension) => strtolower(ltrim(trim($extension), '.')),
            self::flatten($this->ignoredExtensions, 'extension')
        );
        $this->extraStopWords = array_map('strtolower', self::flatten($this->extraStopWords, 'word'));

        return parent::beforeValidate();
    }

    /**
     * @return string[]
     */
    private static function flatten(mixed $value, string $key): array
    {
        if (!is_array($value)) {
            return [];
        }

        $flattened = [];

        foreach ($value as $row) {
            $item = is_array($row) ? ($row[$key] ?? reset($row)) : $row;

            if (is_string($item) && trim($item) !== '') {
                $flattened[] = trim($item);
            }
        }

        return array_values(array_unique($flattened));
    }
}
