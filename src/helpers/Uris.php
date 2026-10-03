<?php

namespace justinholtweb\friend\helpers;

/**
 * Path arithmetic.
 *
 * Everything in Friend compares URIs, and Craft hands them over in more than one shape — with a
 * leading slash from a request, without one from `elements.uri`, and as `__home__` for a site's
 * homepage. One normal form, applied at every boundary, is cheaper than remembering which shape
 * you are holding.
 */
abstract class Uris
{
    /** What Craft stores in `elements.uri` for a site's homepage. */
    public const HOMEPAGE = '__home__';

    /** How many ancestors the ancestor method may try, nearest first. */
    public const MAX_ANCESTORS = 10;

    /**
     * The normal form: no scheme, no host, no query, no fragment, no leading or trailing slash.
     *
     * The homepage normalises to an empty string, which is also what a request for `/` produces —
     * so the two compare equal without anywhere having to special-case them.
     */
    public static function normalize(?string $uri): string
    {
        $uri = (string)$uri;

        if ($uri === self::HOMEPAGE) {
            return '';
        }

        // Tolerate being handed a whole URL.
        if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $uri)) {
            $uri = (string)parse_url($uri, PHP_URL_PATH);
        }

        $uri = explode('#', $uri, 2)[0];
        $uri = explode('?', $uri, 2)[0];
        $uri = rawurldecode($uri);

        // Collapse repeated slashes so `/blog//post` and `/blog/post` are one URI.
        $uri = (string)preg_replace('~/{2,}~', '/', $uri);

        return trim($uri, '/');
    }

    /**
     * Whether a hand-typed redirect target is one a browser should be sent to: a site URI, or an
     * http(s) URL. `javascript:`, `data:` and the like are refused, as is `https:evil.com`, which
     * a browser on an http page resolves to another host.
     */
    public static function isSafeTarget(string $target): bool
    {
        $target = trim($target);

        if (!preg_match('~^[a-z][a-z0-9+.-]*:~i', $target)) {
            return true;
        }

        return preg_match('~^https?://~i', $target) === 1;
    }

    /** @return string[] */
    public static function segments(string $uri): array
    {
        $uri = self::normalize($uri);

        return $uri === '' ? [] : explode('/', $uri);
    }

    /** The last path segment, with any file extension removed. */
    public static function slug(string $uri): string
    {
        $segments = self::segments($uri);

        if (!$segments) {
            return '';
        }

        return self::stripExtension(end($segments));
    }

    public static function stripExtension(string $segment): string
    {
        return (string)preg_replace('~\.[a-z0-9]{1,8}$~i', '', $segment);
    }

    /** The file extension of a URI's last segment, lowercased, or null. */
    public static function extension(string $uri): ?string
    {
        $segments = self::segments($uri);

        if (!$segments) {
            return null;
        }

        if (!preg_match('~\.([a-z0-9]{1,8})$~i', end($segments), $match)) {
            return null;
        }

        return strtolower($match[1]);
    }

    /**
     * Every ancestor path of a URI, longest first.
     *
     * `blog/2024/some-post` → `blog/2024`, `blog`. The URI itself is never included: it is the one
     * path known not to exist.
     *
     * Only the nearest MAX_ANCESTORS are returned. Each is a query, and a scanner will happily send
     * a path with thousands of segments.
     *
     * @return string[]
     */
    public static function ancestors(string $uri): array
    {
        $segments = self::segments($uri);
        $ancestors = [];

        while (count($segments) > 1 && count($ancestors) < self::MAX_ANCESTORS) {
            array_pop($segments);
            $ancestors[] = implode('/', $segments);
        }

        return $ancestors;
    }

    /**
     * Whether a URI matches a pattern.
     *
     * Patterns are globs — `*` matches any run of characters, `?` any single one. A pattern
     * starting `re:` is the rest of it as a regular expression, unanchored, so `re:^wp-` works
     * the way anyone writing it expects.
     */
    public static function matchesPattern(string $uri, string $pattern): bool
    {
        $pattern = trim($pattern);

        if ($pattern === '') {
            return false;
        }

        if (str_starts_with($pattern, 're:')) {
            $expression = '~' . str_replace('~', '\~', substr($pattern, 3)) . '~i';

            // A pattern an admin typed is allowed to be wrong; it is not allowed to throw a
            // warning into the middle of somebody's 404.
            return @preg_match($expression, $uri) === 1;
        }

        $quoted = preg_quote(trim($pattern, '/'), '~');
        $quoted = str_replace(['\*', '\?'], ['.*', '.'], $quoted);

        return preg_match('~^' . $quoted . '$~i', self::normalize($uri)) === 1;
    }

    /**
     * @param string[] $patterns
     */
    public static function matchesAny(string $uri, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (is_string($pattern) && self::matchesPattern($uri, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
