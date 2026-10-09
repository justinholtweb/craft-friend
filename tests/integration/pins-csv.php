<?php
/**
 * Friend pins CSV import/export checks.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-friend/tests/integration/pins-csv.php
 *
 * The reader's caps, formula defusing on the way out, every refusal the importer makes (above all
 * destinations off the site), header aliases from other redirect tools, dry runs, the update
 * switch, entry linking, an export importing back as the same pins, and both console commands run
 * for real. Idempotent and self-cleaning, like checks.php: fixtures carry the `friend-check-` prefix.
 */

$root = getcwd();
require $root . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;
use justinholtweb\friend\db\Table;
use justinholtweb\friend\helpers\Csv;
use justinholtweb\friend\models\Miss;
use justinholtweb\friend\models\Outcome;
use justinholtweb\friend\models\Pin;
use justinholtweb\friend\Plugin;

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

const PREFIX = 'friend-check-';

$plugin = Plugin::getInstance();

if ($plugin === null) {
    echo "Friend is not installed in this site.\n";
    exit(1);
}

$transfer = $plugin->getPinTransfer();
$pins = $plugin->getPins();
$matcher = $plugin->getMatcher();
$primary = Craft::$app->getSites()->getPrimarySite();
$siteId = $primary->id;
$host = parse_url((string)$primary->getBaseUrl(), PHP_URL_HOST);
$section = Craft::$app->getEntries()->getSectionByHandle('liveTest');

if ($section === null) {
    echo "No `liveTest` section in this site — nothing to build fixtures in.\n";
    exit(1);
}

$tmp = sys_get_temp_dir() . '/friend-pins-csv-' . getmypid();
@mkdir($tmp);

$sweep = static function() use ($section): void {
    foreach (Entry::find()->sectionId($section->id)->slug(PREFIX . 'csv-*')->status(null)->all() as $entry) {
        Craft::$app->getElements()->deleteElement($entry, true);
    }

    Craft::$app->getDb()->createCommand()->delete(Table::PINS, ['like', 'uri', PREFIX . '%', false])->execute();
};

/** Write rows to a CSV file and return its path. */
$file = static function(string $name, string $content) use ($tmp): string {
    $path = "$tmp/$name.csv";
    file_put_contents($path, $content);

    return $path;
};

$pin = static fn(?int $site, string $uri): ?Pin => Plugin::getInstance()->getPins()->findByUri($site, $uri);

$sweep();

try {
    $entry = new Entry();
    $entry->sectionId = $section->id;
    $entry->typeId = $section->getEntryTypes()[0]->id;
    $entry->siteId = $siteId;
    $entry->title = 'CSV Target';
    $entry->slug = PREFIX . 'csv-target';

    if (!Craft::$app->getElements()->saveElement($entry)) {
        throw new RuntimeException('Could not save the fixture entry: ' . implode(' ', $entry->getFirstErrors()));
    }

    $entryUri = $entry->uri;

    // ------------------------------------------------------------------ CSV helper

    heading('CSV helper');

    check('formula cells are defused on the way out', function() {
        foreach (['=1+1', '+1', '-1', '@SUM(A1)', "\tx", "\rx"] as $cell) {
            if (Csv::guard($cell) !== "'" . $cell) {
                return 'not guarded: ' . json_encode($cell);
            }
        }

        return Csv::guard('/blog') === '/blog' && Csv::guard('') === '';
    });
    check('a written CSV carries the defused cell', function() {
        $csv = Csv::write(['from', 'to'], [['/a', '=HYPERLINK("http://evil.example","x")']]);

        return str_contains($csv, "\"'=HYPERLINK(") && !preg_match('~(^|,)=~m', $csv);
    });
    check('a defused cell reads back as it was', fn() => Csv::unguard("'=1+1") === '=1+1' && Csv::unguard("'quoted") === "'quoted");
    check('a byte-order mark, semicolons and blank lines are handled, keyed by line', function() {
        $rows = Csv::parse("\xEF\xBB\xBFfrom;to\n\n/a;/b\n");

        return $rows === [1 => ['from', 'to'], 3 => ['/a', '/b']] ?: json_encode($rows);
    });
    check('a Windows-1252 file is read as UTF-8', function() {
        $rows = Csv::parse("from,to\n/caf\xE9,/b\n");

        return ($rows[2][0] ?? null) === '/café';
    });
    check('a file over the size cap is refused', function() use ($file) {
        try {
            Csv::read($file('big', str_repeat("a,b\n", 100)), 64);
        } catch (InvalidArgumentException $e) {
            return str_contains($e->getMessage(), 'larger');
        }

        return 'read it';
    });
    check('a file over the row cap is refused', function() {
        try {
            Csv::parse("from,to\n" . str_repeat("/a,/b\n", 6), 5);
        } catch (InvalidArgumentException $e) {
            return str_contains($e->getMessage(), 'more than 5 rows');
        }

        return 'parsed it';
    });
    check('a file exactly at the row cap (plus a header) is read', fn() => count(Csv::parse("from,to\n" . str_repeat("/a,/b\n", 5), 5)) === 6);

    // ------------------------------------------------------------------ destinations

    heading('Destinations');

    check('a path is accepted and stored with a leading slash', function() use ($transfer) {
        $target = $transfer->resolveDestination('about/team', null);

        return is_array($target) && $target['url'] === '/about/team' && $target['plain'];
    });
    check('a path with a query string is kept, but not treated as an entry URI', function() use ($transfer) {
        $target = $transfer->resolveDestination('/shop?page=2', null);

        return is_array($target) && $target['url'] === '/shop?page=2' && $target['plain'] === false;
    });
    check('a full URL on this site becomes a path', function() use ($transfer, $host, $siteId) {
        $target = $transfer->resolveDestination("https://$host/about?x=1", $siteId);

        return is_array($target) && $target['url'] === '/about?x=1' && $target['path'] === '/about' ?: json_encode($target);
    });
    check('the site host matches whatever the port or a www. prefix', function() use ($transfer, $host) {
        $a = $transfer->resolveDestination("https://www.$host:8443/about", null);
        $b = $transfer->resolveDestination('http://' . strtoupper($host) . '/', null);

        return is_array($a) && $a['url'] === '/about' && is_array($b) && $b['url'] === '/' ?: json_encode([$a, $b]);
    });
    check('a full URL on another site of this install is refused for a pin on one site', function() use ($transfer, $siteId) {
        foreach (Craft::$app->getSites()->getAllSites() as $other) {
            $otherHost = parse_url((string)$other->getBaseUrl(), PHP_URL_HOST);

            if ($other->id !== $siteId && $otherHost !== parse_url((string)Craft::$app->getSites()->getSiteById($siteId)->getBaseUrl(), PHP_URL_HOST)) {
                return is_string($transfer->resolveDestination("https://$otherHost/x", $siteId))
                    && is_array($transfer->resolveDestination("https://$otherHost/x", null));
            }
        }

        return true;
    });
    foreach ([
        'another host' => 'https://evil.example/login',
        'a protocol-relative URL' => '//evil.example/login',
        'a backslash path' => '/\\evil.example',
        'a tab-smuggled path' => "/\t/evil.example",
        'javascript:' => 'javascript:alert(1)',
        'data:' => 'data:text/html,<script>alert(1)</script>',
        'a scheme without slashes' => 'https:evil.example',
        'credentials in the URL' => "https://$host@evil.example/",
        'a lookalike host' => "https://$host.evil.example/",
        'nothing' => '',
    ] as $label => $destination) {
        check("$label is refused as a destination", fn() => is_string($transfer->resolveDestination($destination, null)));
    }
    check('another host is accepted only when external targets are allowed', function() use ($transfer) {
        $target = $transfer->resolveDestination('https://example.org/new', null, true);

        return is_array($target) && $target['url'] === 'https://example.org/new'
            && is_string($transfer->resolveDestination('//example.org/new', null, true))
            && is_string($transfer->resolveDestination('javascript:alert(1)', null, true));
    });

    // ------------------------------------------------------------------ import

    heading('Import');

    check('a from,to file creates URL pins and entry pins', function() use ($transfer, $file, $pin, $entryUri) {
        $result = $transfer->import($file('basic', "from,to,status\n/" . PREFIX . "old-a,/somewhere/else,301\n" . PREFIX . "old-b,/$entryUri,\n"));

        $a = $pin(null, PREFIX . 'old-a');
        $b = $pin(null, PREFIX . 'old-b');

        return $result->succeeded() && $result->created === 2 && $result->linked === 1
            && $a?->targetType === Pin::TARGET_URL && $a->url === '/somewhere/else' && $a->statusCode === 301
            && $b?->targetType === Pin::TARGET_ELEMENT && $b->statusCode === null
            ?: json_encode($result->toArray());
    });
    check('an imported pin actually redirects, ahead of every rule', function() use ($matcher, $siteId) {
        $outcome = $matcher->resolve(Miss::fromUri(PREFIX . 'old-a', $siteId));

        return $outcome->source === Outcome::SOURCE_PIN && $outcome->shouldRedirect()
            && $outcome->statusCode === 301 && str_ends_with((string)$outcome->targetUrl, '/somewhere/else');
    });
    check('entry linking can be switched off', function() use ($transfer, $file, $pin, $entryUri) {
        $result = $transfer->import($file('nolink', "from,to\n" . PREFIX . "old-c,/$entryUri\n"), ['linkElements' => false]);

        return $result->succeeded() && $pin(null, PREFIX . 'old-c')?->targetType === Pin::TARGET_URL;
    });
    check('a file with no header is read as from,to,status', function() use ($transfer, $file, $pin) {
        $result = $transfer->import($file('headerless', "/" . PREFIX . "old-d,/new-d,308\n"));

        return $result->succeeded() && $pin(null, PREFIX . 'old-d')?->statusCode === 308;
    });
    check('Retour headers are understood, and its regex rows refused', function() use ($transfer, $file, $pin) {
        $result = $transfer->import($file('retour', "\"Legacy URL Pattern\",\"Redirect To\",\"Match Type\",\"HTTP Status\"\n"
            . "/" . PREFIX . "old-e,/new-e,exactmatch,302\n"
            . "/" . PREFIX . "old-(.*),/new-$1,regexmatch,301\n"));

        return $result->created === 1 && isset($result->errors[3]) && $pin(null, PREFIX . 'old-e')?->statusCode === 302
            ?: json_encode($result->toArray());
    });
    check('a site column picks the site, and an unknown site is refused', function() use ($transfer, $file, $pin, $primary, $siteId) {
        $result = $transfer->import($file('sites', "from,to,site\n" . PREFIX . "old-f,/new-f,{$primary->handle}\n" . PREFIX . "old-g,/new-g,nosuchsite\n"));

        return $result->created === 1 && isset($result->errors[3]) && $pin($siteId, PREFIX . 'old-f') !== null && $pin(null, PREFIX . 'old-f') === null;
    });
    check('the default site applies to rows without a site column', function() use ($transfer, $file, $pin, $siteId) {
        $transfer->import($file('defaultsite', "from,to\n" . PREFIX . "old-h,/new-h\n"), ['siteId' => $siteId]);

        return $pin($siteId, PREFIX . 'old-h') !== null;
    });
    check('an enabled column of 0 imports a disabled pin', function() use ($transfer, $file, $pin) {
        $transfer->import($file('disabled', "from,to,enabled\n" . PREFIX . "old-i,/new-i,0\n"));

        return $pin(null, PREFIX . 'old-i')?->enabled === false;
    });
    check('bad rows are refused with their line numbers, good ones still import', function() use ($transfer, $file) {
        $result = $transfer->import($file('bad', "from,to,status\n"
            . PREFIX . "bad-status,/x,404\n"           // 2
            . PREFIX . "bad-loop,/" . PREFIX . "bad-loop,\n" // 3
            . "/,/x,\n"                                  // 4 homepage
            . PREFIX . "bad-host,https://evil.example/,\n" // 5
            . PREFIX . "dup,/x,\n"                       // 6
            . PREFIX . "dup,/y,\n"                       // 7
            . PREFIX . "bad-js,javascript:alert(1),\n"));  // 8

        return array_keys($result->errors) === [2, 3, 4, 5, 7, 8] && $result->created === 1 && !$result->succeeded()
            ?: json_encode($result->toArray());
    });
    check('no pin was saved for any refused row', function() use ($pin) {
        foreach (['bad-status', 'bad-loop', 'bad-host', 'bad-js'] as $uri) {
            if ($pin(null, PREFIX . $uri) !== null) {
                return "$uri was saved";
            }
        }

        return $pin(null, PREFIX . 'dup')?->url === '/x';
    });
    check('an external destination imports when allowed', function() use ($transfer, $file, $pin) {
        $result = $transfer->import($file('external', "from,to\n" . PREFIX . "old-ext,https://example.org/x\n"), ['allowExternal' => true]);

        return $result->succeeded() && $pin(null, PREFIX . 'old-ext')?->url === 'https://example.org/x';
    });
    check('an existing pin is left alone without update', function() use ($transfer, $file, $pin) {
        $result = $transfer->import($file('again', "from,to\n" . PREFIX . "old-a,/changed\n"));

        return $result->skipped === 1 && $result->created === 0 && $pin(null, PREFIX . 'old-a')?->url === '/somewhere/else';
    });
    check('an existing pin is overwritten with update, keeping its hits', function() use ($transfer, $file, $pin, $pins) {
        $before = $pin(null, PREFIX . 'old-a');
        $pins->recordHit($before);
        $result = $transfer->import($file('again2', "from,to\n" . PREFIX . "old-a,/changed\n"), ['update' => true]);
        $after = $pin(null, PREFIX . 'old-a');

        return $result->updated === 1 && $after?->url === '/changed' && $after->hits >= 1 && $after->id === $before->id;
    });
    check('a dry run reports what it would do and saves nothing', function() use ($transfer, $file, $pin) {
        $result = $transfer->import($file('dry', "from,to\n" . PREFIX . "dry-1,/a\n" . PREFIX . "old-a,/dry\n"), ['dryRun' => true, 'update' => true]);

        return $result->dryRun && $result->created === 1 && $result->updated === 1
            && $pin(null, PREFIX . 'dry-1') === null && $pin(null, PREFIX . 'old-a')?->url === '/changed';
    });
    check('a formula-guarded cell imports as its real value', function() use ($transfer, $file, $pin) {
        $transfer->import($file('guarded', "from,to\n'-" . PREFIX . "neg,/new\n"));

        return $pin(null, '-' . PREFIX . 'neg') !== null;
    });
    check('a pointsAt column of url keeps a URL pin even at an entry’s path', function() use ($transfer, $file, $pin, $entryUri) {
        $transfer->import($file('pointsat', "from,to,pointsAt\n" . PREFIX . "old-pa,/$entryUri,url\n"));

        return $pin(null, PREFIX . 'old-pa')?->targetType === Pin::TARGET_URL;
    });
    check('a header without a destination column is refused as a whole', function() use ($transfer, $file) {
        $result = $transfer->import($file('nodest', "from,notes\n" . PREFIX . "x,hello\n"));

        return $result->fileError !== null && $result->created === 0;
    });
    check('an over-cap file imports nothing', function() use ($transfer, $file) {
        $path = $file('huge', "from,to\n" . str_repeat('/' . PREFIX . "h,/x\n", Csv::MAX_ROWS + 1));
        $result = $transfer->import($path);

        return $result->fileError !== null && $result->created === 0;
    });
    check('a missing file is a file error, not an exception', fn() => $transfer->import('/nonexistent/friend.csv')->fileError !== null);

    // ------------------------------------------------------------------ export

    heading('Export');

    // Clean the leading-dash fixture first: Craft's `like` would read it as a range otherwise.
    Craft::$app->getDb()->createCommand()->delete(Table::PINS, ['uri' => '-' . PREFIX . 'neg'])->execute();

    $exported = '';

    check('the export has the importer’s columns and every pin', function() use ($transfer, &$exported) {
        $exported = $transfer->export();
        $rows = Csv::parse($exported);

        return reset($rows) === ['from', 'to', 'status', 'site', 'enabled', 'pointsAt'] && str_contains($exported, '/' . PREFIX . 'old-a,/changed,');
    });
    check('an entry pin exports as the entry’s current path', fn() => str_contains($exported, '/' . PREFIX . 'old-b,/' . $entryUri . ','));
    check('an export imports back as the same pins', function() use ($transfer, $file, $sweep, &$exported) {
        $before = array_map(static fn(Pin $p) => [$p->siteId, $p->uri, $p->targetType, $p->url, $p->elementId, $p->statusCode, $p->enabled],
            array_values(array_filter(Plugin::getInstance()->getPins()->getAllPins(), static fn(Pin $p) => str_starts_with($p->uri, PREFIX))));

        Craft::$app->getDb()->createCommand()->delete(Table::PINS, ['like', 'uri', PREFIX . '%', false])->execute();

        $csv = implode("\n", array_filter(explode("\n", $exported), static fn($line) => $line === '' || str_starts_with($line, 'from,') || str_contains($line, PREFIX)));
        $result = $transfer->import($file('roundtrip', $csv), ['allowExternal' => true]);

        $after = array_map(static fn(Pin $p) => [$p->siteId, $p->uri, $p->targetType, $p->url, $p->elementId, $p->statusCode, $p->enabled],
            array_values(array_filter(Plugin::getInstance()->getPins()->getAllPins(), static fn(Pin $p) => str_starts_with($p->uri, PREFIX))));

        return $result->succeeded() && $before === $after ?: json_encode(['errors' => $result->errors, 'before' => $before, 'after' => $after]);
    });

    // ------------------------------------------------------------------ console

    heading('Console');

    check('friend/pins/import runs, and exits non-zero on refused rows', function() use ($file, $pin) {
        $path = $file('console', "from,to\n" . PREFIX . "cli-a,/cli\n" . PREFIX . "cli-b,https://evil.example/\n");
        exec('php craft friend/pins/import ' . escapeshellarg($path) . ' --color=0 2>&1', $out, $code);
        $text = implode("\n", $out);

        return $code === 65 && str_contains($text, '1 created') && str_contains($text, 'line 3') && $pin(null, PREFIX . 'cli-a') !== null
            ?: "exit $code: $text";
    });
    check('friend/pins/import --dry-run saves nothing', function() use ($file, $pin) {
        $path = $file('console-dry', "from,to\n" . PREFIX . "cli-dry,/cli\n");
        exec('php craft friend/pins/import ' . escapeshellarg($path) . ' --dry-run --color=0 2>&1', $out, $code);

        return $code === 0 && str_contains(implode("\n", $out), 'Dry run') && $pin(null, PREFIX . 'cli-dry') === null;
    });
    check('friend/pins/import refuses an unknown --site', function() use ($file) {
        exec('php craft friend/pins/import ' . escapeshellarg($file('x', "a,b\n")) . ' --site=nosuchsite --color=0 2>&1', $out, $code);

        return $code === 64;
    });
    check('friend/pins/export writes a file', function() use ($tmp) {
        exec('php craft friend/pins/export ' . escapeshellarg("$tmp/out.csv") . ' --color=0 2>&1', $out, $code);

        return $code === 0 && str_contains((string)@file_get_contents("$tmp/out.csv"), '/' . PREFIX . 'cli-a,/cli,');
    });
    check('friend/pins/export prints to standard output', function() {
        exec('php craft friend/pins/export --color=0 2>/dev/null', $out, $code);

        return $code === 0 && ($out[0] ?? '') === 'from,to,status,site,enabled,pointsAt';
    });
} finally {
    $sweep();
    Craft::$app->getDb()->createCommand()->delete(Table::PINS, ['uri' => '-' . PREFIX . 'neg'])->execute();
    $matcher->clearCaches();

    foreach (glob("$tmp/*") ?: [] as $f) {
        @unlink($f);
    }

    @rmdir($tmp);
}

echo "\n$passed passed, $failed failed\n\n";

exit($failed === 0 ? 0 : 1);
