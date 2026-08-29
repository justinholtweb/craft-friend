<?php

namespace justinholtweb\friends\models;

use Craft;
use craft\base\Model;
use craft\web\Request;
use justinholtweb\friends\helpers\Similarity;
use justinholtweb\friends\helpers\Uris;

/**
 * One 404: the URL that was asked for, pre-chewed into the pieces matching needs.
 *
 * Built once per request and passed down, so tokenising and path splitting happen once however
 * many rules end up looking at it.
 */
class Miss extends Model
{
    public int $siteId = 0;

    /** Normal form — no host, no query, no leading or trailing slash. */
    public string $uri = '';

    public string $queryString = '';
    public ?string $referrer = null;
    public string $method = 'GET';

    /** @var string[] */
    public array $segments = [];

    /** The last segment with any extension stripped — the part a slug match compares against. */
    public string $slug = '';

    /** Tokens from the whole path, most-significant last segment included. */
    public array $tokens = [];

    /** Tokens from the last segment only. */
    public array $slugTokens = [];

    public static function fromRequest(Request $request, ?int $siteId = null, array $config = []): self
    {
        $uri = Uris::normalize($request->getPathInfo());

        return self::fromUri($uri, $siteId, [
            'queryString' => (string)$request->getQueryString(),
            'referrer' => $request->getReferrer(),
            'method' => strtoupper($request->getMethod()),
        ] + $config);
    }

    public static function fromUri(string $uri, ?int $siteId = null, array $config = []): self
    {
        $settings = \justinholtweb\friends\Plugin::getInstance()->getSettings();

        $miss = new self($config);
        $miss->siteId = $siteId ?? Craft::$app->getSites()->getCurrentSite()->id;
        $miss->uri = Uris::normalize($uri);
        $miss->segments = Uris::segments($miss->uri);
        $miss->slug = Uris::slug($miss->uri);

        $miss->slugTokens = Similarity::tokenize(
            $miss->slug,
            $settings->minTokenLength,
            $settings->extraStopWords
        );

        $miss->tokens = Similarity::tokenize(
            str_replace('/', ' ', Uris::stripExtension($miss->uri)),
            $settings->minTokenLength,
            $settings->extraStopWords
        );

        return $miss;
    }

    /** Whether there is anything here worth searching on. */
    public function isSearchable(): bool
    {
        return $this->uri !== '' && ($this->tokens !== [] || $this->slug !== '');
    }
}
