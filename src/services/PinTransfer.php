<?php

namespace justinholtweb\friend\services;

use Craft;
use craft\base\Component;
use craft\models\Site;
use justinholtweb\friend\helpers\Csv;
use justinholtweb\friend\helpers\Uris;
use justinholtweb\friend\models\Pin;
use justinholtweb\friend\models\PinImport;
use justinholtweb\friend\models\Rule;
use justinholtweb\friend\Plugin;
use InvalidArgumentException;
use Throwable;

/**
 * Pins in and out of CSV.
 *
 * The point is a migration: the old site's redirect map — exported from Retour, Redirect Manager,
 * a WordPress plugin or a spreadsheet — becomes pins, so the redirects somebody already knows sit
 * in front of the guesses instead of competing with them.
 *
 * Every row is untrusted. The destination is the part that matters: a pin is a redirect, so a
 * file that could plant `https://evil.example` behind a real URL would make the site an open
 * redirect. Imported destinations must be on this site — a path, or a full URL on one of the
 * site's own hosts, which is turned back into a path — unless whoever runs the import explicitly
 * allows external targets.
 */
class PinTransfer extends Component
{
    /** Header names other tools use for each column, compared lowercased with punctuation removed. */
    private const COLUMNS = [
        'from' => ['from', 'source', 'sourceurl', 'sourceuri', 'src', 'old', 'oldurl', 'olduri', 'uri', 'path', 'legacyurl', 'legacyurlpattern', 'redirectsrcurl', 'fromurl', 'fromuri'],
        'to' => ['to', 'destination', 'destinationurl', 'dest', 'desturl', 'target', 'targeturl', 'redirectto', 'redirectdesturl', 'new', 'newurl', 'touri', 'tourl', 'url'],
        'status' => ['status', 'statuscode', 'code', 'httpstatus', 'httpcode', 'redirecthttpcode'],
        'site' => ['site', 'sitehandle', 'siteid'],
        'enabled' => ['enabled', 'active'],
        'match' => ['matchtype', 'redirectmatchtype'],
        'pointsAt' => ['pointsat'],
    ];

    /**
     * Import pins from a CSV file.
     *
     * Options:
     * - `siteId` (int|null) — the site for rows without a `site` column; null is every site
     * - `update` (bool) — overwrite a pin that already exists for the same site and URI
     * - `dryRun` (bool) — validate everything, save nothing
     * - `linkElements` (bool, default true) — when the destination is an entry's URI, pin the entry,
     *   so the redirect follows it if its URI changes later
     * - `allowExternal` (bool) — accept destinations on other hosts
     *
     * @param array{siteId?: int|null, update?: bool, dryRun?: bool, linkElements?: bool, allowExternal?: bool} $options
     */
    public function import(string $path, array $options = []): PinImport
    {
        $result = new PinImport(['dryRun' => (bool)($options['dryRun'] ?? false)]);

        try {
            $rows = Csv::read($path);
        } catch (InvalidArgumentException $e) {
            $result->fileError = $e->getMessage();

            return $result;
        }

        return $this->importRows($rows, $options, $result);
    }

    /**
     * @param array<int, string[]> $rows line number → cells
     * @param array{siteId?: int|null, update?: bool, dryRun?: bool, linkElements?: bool, allowExternal?: bool} $options
     */
    public function importRows(array $rows, array $options = [], ?PinImport $result = null): PinImport
    {
        $result ??= new PinImport(['dryRun' => (bool)($options['dryRun'] ?? false)]);

        if (!$rows) {
            $result->fileError = Craft::t('friend', 'The file has no rows.');

            return $result;
        }

        $map = $this->_columnMap(reset($rows));

        if ($map === null) {
            // No recognisable header: `from,to[,status]`, which is what most tools write when they
            // write no header at all.
            $map = ['from' => 0, 'to' => 1, 'status' => 2];
        } else {
            unset($rows[array_key_first($rows)]);

            if (!isset($map['from'], $map['to'])) {
                $result->fileError = Craft::t('friend', 'The header needs a source column (such as `from`) and a destination column (such as `to`).');

                return $result;
            }
        }

        $defaultSiteId = isset($options['siteId']) ? (int)$options['siteId'] : null;
        $update = (bool)($options['update'] ?? false);
        $linkElements = (bool)($options['linkElements'] ?? true);
        $allowExternal = (bool)($options['allowExternal'] ?? false);
        $pins = Plugin::getInstance()->getPins();
        $seen = [];

        $transaction = $result->dryRun ? null : Craft::$app->getDb()->beginTransaction();

        try {
            foreach ($rows as $line => $cells) {
                $result->rows++;
                $cell = static fn(string $column): string => isset($map[$column]) ? trim((string)($cells[$map[$column]] ?? '')) : '';

                if (stripos($cell('match'), 'regex') !== false) {
                    $result->addRowError($line, Craft::t('friend', 'A pattern redirect, not an exact one. Write it as a rule instead.'));
                    continue;
                }

                $siteId = $defaultSiteId;

                if ($cell('site') !== '') {
                    $site = $this->_site($cell('site'));

                    if ($site === null) {
                        $result->addRowError($line, Craft::t('friend', 'No site “{site}”.', ['site' => $cell('site')]));
                        continue;
                    }

                    $siteId = $site->id;
                }

                $from = Uris::normalize($cell('from'));

                if ($from === '') {
                    $result->addRowError($line, Craft::t('friend', 'No source URI. The homepage can’t be pinned.'));
                    continue;
                }

                $statusCode = $this->_statusCode($cell('status'));

                if ($statusCode === false) {
                    $result->addRowError($line, Craft::t('friend', 'Status “{status}” isn’t a redirect Friend sends (301, 302, 307 or 308).', ['status' => $cell('status')]));
                    continue;
                }

                $target = $this->resolveDestination($cell('to'), $siteId, $allowExternal);

                if (is_string($target)) {
                    $result->addRowError($line, $target);
                    continue;
                }

                if ($target['path'] !== null && Uris::normalize($target['path']) === $from) {
                    $result->addRowError($line, Craft::t('friend', 'The destination is the source — that is a redirect loop.'));
                    continue;
                }

                $key = Pins::hash($siteId, $from);

                if (isset($seen[$key])) {
                    $result->addRowError($line, Craft::t('friend', 'Duplicate of line {line}.', ['line' => $seen[$key]]));
                    continue;
                }

                $seen[$key] = $line;
                $existing = $pins->findByUri($siteId, $from);

                if ($existing !== null && !$update) {
                    $result->skipped++;
                    continue;
                }

                $pin = $existing ?? new Pin(['siteId' => $siteId, 'uri' => $from]);
                $pin->statusCode = $statusCode;
                $pin->enabled = $this->_bool($cell('enabled'), true);

                // `pointsAt` is what an export writes, so a URL pin that happens to share an entry's
                // path imports back as a URL pin rather than quietly becoming an entry pin.
                $pointsAt = strtolower($cell('pointsAt'));
                $link = $pointsAt === 'entry' || ($pointsAt !== 'url' && $linkElements);

                $element = $link && $target['path'] !== null && $target['plain']
                    ? Craft::$app->getElements()->getElementByUri(
                        Uris::normalize($target['path']) ?: Uris::HOMEPAGE,
                        $siteId ?? Craft::$app->getSites()->getPrimarySite()->id,
                        true
                    )
                    : null;

                if ($element !== null) {
                    $pin->targetType = Pin::TARGET_ELEMENT;
                    $pin->elementId = (int)$element->id;
                    $pin->url = null;
                } else {
                    $pin->targetType = Pin::TARGET_URL;
                    $pin->elementId = null;
                    $pin->url = $target['url'];
                }

                if (!$pin->validate()) {
                    $result->addRowError($line, implode(' ', $pin->getFirstErrors()));
                    continue;
                }

                if (!$result->dryRun && !$pins->savePin($pin, false, false)) {
                    $result->addRowError($line, Craft::t('friend', 'Couldn’t save the pin.'));
                    continue;
                }

                $existing !== null ? $result->updated++ : $result->created++;

                if ($element !== null) {
                    $result->linked++;
                }
            }

            $transaction?->commit();
        } catch (Throwable $e) {
            $transaction?->rollBack();
            Craft::error('Pin import failed: ' . $e->getMessage(), Plugin::LOG_CATEGORY);

            $result->created = $result->updated = 0;
            $result->fileError = Craft::t('friend', 'The import failed and nothing was saved: {error}', ['error' => $e->getMessage()]);

            return $result;
        }

        if (!$result->dryRun && ($result->created || $result->updated)) {
            Plugin::getInstance()->getMatcher()->clearCaches();
        }

        return $result;
    }

    /**
     * Check an imported destination and put it in the form a pin stores.
     *
     * Returns an error message, or `url` (what the pin stores), `path` (the site-relative path when
     * the destination is on this site, else null) and `plain` (no query string or fragment, so the
     * path can stand for an entry).
     *
     * @return array{url: string, path: string|null, plain: bool}|string
     */
    public function resolveDestination(string $raw, ?int $siteId, bool $allowExternal = false): array|string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return Craft::t('friend', 'No destination.');
        }

        // Control characters and backslashes are how a "path" turns into another host: browsers
        // read `/\evil.example` and `/\tevil.example` as `//evil.example`.
        if (preg_match('~[\x00-\x20\x7F\\\\]~', $raw)) {
            return Craft::t('friend', 'The destination contains spaces, control characters or backslashes.');
        }

        if (str_starts_with($raw, '//')) {
            return Craft::t('friend', 'A protocol-relative destination (`//host/…`) is not allowed. Use a path or a full http(s) URL.');
        }

        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $raw)) {
            if (!preg_match('~^https?://~i', $raw)) {
                return Craft::t('friend', 'Only http(s) destinations are allowed.');
            }

            $parts = parse_url($raw);

            if ($parts === false || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
                return Craft::t('friend', 'The destination isn’t a valid URL.');
            }

            $path = $this->_sitePath($parts, $siteId);

            if ($path === null) {
                if (!$allowExternal) {
                    return Craft::t('friend', 'The destination is on another host ({host}). Imported pins must stay on this site.', ['host' => $parts['host']]);
                }

                return ['url' => $raw, 'path' => null, 'plain' => false];
            }

            $suffix = (isset($parts['query']) ? '?' . $parts['query'] : '') . (isset($parts['fragment']) ? '#' . $parts['fragment'] : '');
            $raw = '/' . $path . $suffix;
        }

        $path = explode('#', explode('?', $raw, 2)[0], 2)[0];
        $url = '/' . ltrim($raw, '/');

        return [
            'url' => $url,
            'path' => $path,
            'plain' => $url === '/' . ltrim($path, '/'),
        ];
    }

    /**
     * Export pins as CSV: `from,to,status,site,enabled,pointsAt` — the same columns the importer
     * reads, so an export imports back as the same pins.
     */
    public function export(?int $siteId = null): string
    {
        $rows = [];

        foreach (Plugin::getInstance()->getPins()->getAllPins($siteId) as $pin) {
            if ($pin->targetType === Pin::TARGET_ELEMENT) {
                $element = $pin->getElement();
                $uri = $element?->uri;
                $to = $uri === null ? '' : '/' . Uris::normalize($uri);
            } else {
                $to = (string)$pin->url;

                if (!preg_match('~^[a-z][a-z0-9+.-]*:~i', $to) && !str_starts_with($to, '//')) {
                    $to = '/' . ltrim($to, '/');
                }
            }

            $rows[] = [
                '/' . $pin->uri,
                $to,
                $pin->statusCode ?? '',
                $pin->siteId ? (Craft::$app->getSites()->getSiteById($pin->siteId)?->handle ?? '') : '',
                $pin->enabled ? '1' : '0',
                $pin->targetType === Pin::TARGET_ELEMENT ? 'entry' : 'url',
            ];
        }

        return Csv::write(['from', 'to', 'status', 'site', 'enabled', 'pointsAt'], $rows);
    }

    /**
     * Which column holds what, if the first row is a header. A data row is not mistaken for one:
     * header cells don't contain slashes, and both a source and a destination name must be there.
     *
     * @param string[] $cells
     * @return array<string, int>|null
     */
    private function _columnMap(array $cells): ?array
    {
        $map = [];

        foreach ($cells as $index => $cell) {
            if (str_contains($cell, '/')) {
                return null;
            }

            $name = strtolower((string)preg_replace('~[^a-z0-9]~i', '', $cell));

            foreach (self::COLUMNS as $column => $aliases) {
                if (!isset($map[$column]) && in_array($name, $aliases, true)) {
                    $map[$column] = $index;
                    break;
                }
            }
        }

        return isset($map['from']) || isset($map['to']) ? $map : null;
    }

    /**
     * The path below the site's base URL, when a URL is on one of this install's sites — the pin's
     * site, or any site for an all-sites pin. Null when it is somewhere else.
     *
     * Hosts are compared without the port or a leading `www.`: a redirect map exported from
     * `https://www.example.com` should import on `https://example.com:8443`, and neither is a way
     * to reach a different host.
     *
     * @param array<string, mixed> $parts
     */
    private function _sitePath(array $parts, ?int $siteId): ?string
    {
        $sitesService = Craft::$app->getSites();
        $sites = $siteId !== null ? array_filter([$sitesService->getSiteById($siteId)]) : $sitesService->getAllSites();
        $host = $this->_host((string)$parts['host']);
        $path = trim((string)($parts['path'] ?? ''), '/');

        foreach ($sites as $site) {
            $base = parse_url((string)$site->getBaseUrl());

            if (!is_array($base) || empty($base['host'])) {
                continue;
            }

            if ($this->_host($base['host']) !== $host) {
                continue;
            }

            $basePath = trim((string)($base['path'] ?? ''), '/');

            if ($basePath === '') {
                return $path;
            }

            if ($path === $basePath) {
                return '';
            }

            if (str_starts_with($path, $basePath . '/')) {
                return substr($path, strlen($basePath) + 1);
            }
        }

        return null;
    }

    private function _host(string $host): string
    {
        $host = strtolower(rtrim($host, '.'));

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private function _site(string $value): ?Site
    {
        $sites = Craft::$app->getSites();

        return ctype_digit($value) ? $sites->getSiteById((int)$value) : $sites->getSiteByHandle($value);
    }

    /**
     * Null for "use the plugin default", false for something Friend won't send.
     */
    private function _statusCode(string $value): int|null|false
    {
        $value = strtolower($value);

        if ($value === '') {
            return null;
        }

        $value = match ($value) {
            'permanent', 'moved permanently' => '301',
            'temporary', 'found' => '302',
            default => $value,
        };

        if (!ctype_digit($value) || !array_key_exists((int)$value, Rule::statusCodes())) {
            return false;
        }

        return (int)$value;
    }

    private function _bool(string $value, bool $default): bool
    {
        $value = strtolower($value);

        if ($value === '') {
            return $default;
        }

        return !in_array($value, ['0', 'false', 'no', 'off', 'n', 'disabled'], true);
    }
}
