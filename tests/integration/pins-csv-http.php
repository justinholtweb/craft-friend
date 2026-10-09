<?php
/**
 * Friend pins import/export over real HTTP — the security half.
 *
 *     docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-friend/tests/integration/pins-csv-http.php
 *
 * The upload action needs a signed-in user with "Create and edit pins", a POST and a CSRF token;
 * only an admin may let a file point pins at other hosts; export needs the same permission.
 * Signs in as the harness admin and as two throwaway users, all through Craft's own impersonation
 * links. Self-cleaning.
 */

$root = getcwd();
require $root . '/bootstrap.php';
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\User;
use justinholtweb\friend\db\Table;
use justinholtweb\friend\Plugin;

const PREFIX = 'friend-check-';

$host = parse_url(Craft::$app->getSites()->getPrimarySite()->getBaseUrl(), PHP_URL_HOST) ?: 'localhost';
$base = 'http://' . $host;
$cp = '/' . Craft::$app->getConfig()->getGeneral()->cpTrigger;
$jarDir = sys_get_temp_dir() . '/friend-http-' . getmypid();
@mkdir($jarDir);

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
        echo "  ✗ $label\n    " . get_class($e) . ': ' . $e->getMessage() . "\n";
    }
}

/**
 * @param array<string, mixed> $post fields; a CURLFile value makes it multipart
 * @return array{0: int, 1: string, 2: array<string, string>}
 */
function http(string $jar, string $method, string $path, array $post = [], array $headers = []): array
{
    global $base, $host, $jarDir;

    $responseHeaders = [];
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_COOKIEJAR => "$jarDir/$jar",
        CURLOPT_COOKIEFILE => "$jarDir/$jar",
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_USERAGENT => 'friend-http-checks',
        CURLOPT_RESOLVE => ["$host:80:127.0.0.1"],
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADERFUNCTION => function($ch, $line) use (&$responseHeaders) {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
            }

            return strlen($line);
        },
    ]);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }

    $body = (string)curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    return [$code, $body, $responseHeaders];
}

function csrf(string $html): ?string
{
    return preg_match('/name="CRAFT_CSRF_TOKEN" value="([^"]+)"/', $html, $m)
        || preg_match('/"csrfTokenValue":"([^"]+)"/', $html, $m) ? stripcslashes($m[1]) : null;
}

/** Sign a cookie jar in as a user, through Craft's own impersonation link. */
function signIn(string $jar, string $username): bool
{
    global $cp;

    exec('php craft users/impersonate ' . escapeshellarg($username) . ' --color=0 2>&1', $out);

    if (!preg_match('#https?://\S+#', implode("\n", $out), $m)) {
        return false;
    }

    $url = parse_url($m[0]);
    http($jar, 'GET', $url['path'] . '?' . ($url['query'] ?? ''));
    [$code] = http($jar, 'GET', "$cp/dashboard");

    return $code === 200;
}

function csv(string $content): CURLFile
{
    global $jarDir;

    $path = "$jarDir/upload-" . md5($content) . '.csv';
    file_put_contents($path, $content);

    return new CURLFile($path, 'text/csv', 'pins.csv');
}

$pin = static fn(string $uri) => Plugin::getInstance()->getPins()->findByUri(null, $uri);

$users = [];

$makeUser = static function(string $suffix, array $permissions) use (&$users): User {
    $username = 'friendcheck' . $suffix;

    if ($existing = User::find()->username($username)->status(null)->one()) {
        Craft::$app->getElements()->deleteElement($existing, true);
    }

    $user = new User();
    $user->username = $username;
    $user->email = "$username@example.test";
    $user->active = true;

    if (!Craft::$app->getElements()->saveElement($user, false)) {
        throw new RuntimeException("Could not create $username");
    }

    Craft::$app->getUserPermissions()->saveUserPermissions($user->id, $permissions);
    $users[] = $user;

    return $user;
};

$cleanup = static function() use (&$users): void {
    Craft::$app->getDb()->createCommand()->delete(Table::PINS, ['like', 'uri', PREFIX . 'http-%', false])->execute();

    foreach ($users as $user) {
        Craft::$app->getElements()->deleteElement($user, true);
    }
};

try {
    $cleanup();

    $viewer = $makeUser('viewer', ['accesscp', 'accessplugin-friend', 'friend:viewlog']);
    $editor = $makeUser('editor', ['accesscp', 'accessplugin-friend', 'friend:viewlog', 'friend:managepins']);

    echo "\nAnonymous\n";

    check('the import screen sends an anonymous visitor to login', function() use ($cp) {
        [$code, , $headers] = http('anon', 'GET', "$cp/friend/pins/import");

        return $code === 302 && str_contains($headers['location'] ?? '', 'login') ?: "HTTP $code";
    });
    check('an anonymous upload is refused and saves nothing', function() use ($cp, $pin) {
        [, $html] = http('anon', 'GET', "$cp/login");
        [$code] = http('anon', 'POST', "$cp/actions/friend/pins/upload", [
            'CRAFT_CSRF_TOKEN' => csrf($html),
            'file' => csv("from,to\n" . PREFIX . "http-anon,/x\n"),
        ]);

        return in_array($code, [302, 400, 401, 403], true) && $pin(PREFIX . 'http-anon') === null ?: "HTTP $code";
    });
    check('an anonymous export is refused', function() use ($cp) {
        [$code, $body] = http('anon', 'GET', "$cp/actions/friend/pins/export");

        return $code !== 200 && !str_contains($body, 'from,to') ?: "HTTP $code";
    });

    echo "\nAdmin\n";

    check('the harness admin signs in', fn() => signIn('admin', 'admin') ?: 'impersonation failed');

    $page = '';

    check('the import screen renders with the upload form and the admin-only switch', function() use ($cp, &$page) {
        [$code, $page] = http('admin', 'GET', "$cp/friend/pins/import");

        return $code === 200 && str_contains($page, 'enctype="multipart/form-data"') && str_contains($page, 'type="file"')
            && str_contains($page, 'name="allowExternal"') ?: "HTTP $code";
    });
    check('the pins screen links to import and export', function() use ($cp) {
        [$code, $html] = http('admin', 'GET', "$cp/friend/pins");

        return $code === 200 && str_contains($html, 'friend/pins/import') ?: "HTTP $code";
    });
    check('a GET to the upload action is refused', function() use ($cp) {
        [$code] = http('admin', 'GET', "$cp/actions/friend/pins/upload");

        return in_array($code, [400, 405], true) ?: "HTTP $code";
    });
    check('an upload without a CSRF token is refused and saves nothing', function() use ($cp, $pin) {
        [$code] = http('admin', 'POST', "$cp/actions/friend/pins/upload", [
            'file' => csv("from,to\n" . PREFIX . "http-nocsrf,/x\n"),
        ]);

        return $code === 400 && $pin(PREFIX . 'http-nocsrf') === null ?: "HTTP $code";
    });
    check('an upload with a token imports, and refuses the off-site row', function() use ($cp, $pin, &$page) {
        [$code, $html] = http('admin', 'POST', "$cp/actions/friend/pins/upload", [
            'CRAFT_CSRF_TOKEN' => csrf($page),
            'linkElements' => '1',
            'file' => csv("from,to\n" . PREFIX . "http-a,/new-a\n" . PREFIX . "http-evil,https://evil.example/\n"),
        ]);

        return $code === 200 && $pin(PREFIX . 'http-a')?->url === '/new-a' && $pin(PREFIX . 'http-evil') === null
            && str_contains($html, 'Why it was refused') && str_contains($html, 'evil.example') ?: "HTTP $code";
    });
    check('a dry run upload saves nothing', function() use ($cp, $pin, &$page) {
        [$code] = http('admin', 'POST', "$cp/actions/friend/pins/upload", [
            'CRAFT_CSRF_TOKEN' => csrf($page),
            'dryRun' => '1',
            'file' => csv("from,to\n" . PREFIX . "http-dry,/x\n"),
        ]);

        return $code === 200 && $pin(PREFIX . 'http-dry') === null ?: "HTTP $code";
    });
    check('an admin may allow an off-site destination', function() use ($cp, $pin, &$page) {
        [$code] = http('admin', 'POST', "$cp/actions/friend/pins/upload", [
            'CRAFT_CSRF_TOKEN' => csrf($page),
            'allowExternal' => '1',
            'file' => csv("from,to\n" . PREFIX . "http-ext,https://example.org/x\n"),
        ]);

        return $code === 200 && $pin(PREFIX . 'http-ext')?->url === 'https://example.org/x' ?: "HTTP $code";
    });
    check('export downloads a CSV attachment', function() use ($cp) {
        [$code, $body, $headers] = http('admin', 'GET', "$cp/actions/friend/pins/export");

        return $code === 200 && str_starts_with($body, 'from,to,status,site,enabled,pointsAt')
            && str_contains($headers['content-disposition'] ?? '', 'attachment') && str_contains($body, '/' . PREFIX . 'http-a,/new-a')
            ?: "HTTP $code " . substr($body, 0, 100);
    });

    echo "\nUser who can view the log but not edit pins\n";

    check('the viewer signs in', fn() => signIn('viewer', $viewer->username) ?: 'impersonation failed');
    check('the viewer is refused the import screen', function() use ($cp) {
        [$code] = http('viewer', 'GET', "$cp/friend/pins/import");

        return $code === 403 ?: "HTTP $code";
    });
    check('the viewer’s upload is refused and saves nothing', function() use ($cp, $pin) {
        [, $html] = http('viewer', 'GET', "$cp/friend/log");
        $token = csrf($html);

        if ($token === null) {
            return 'no CSRF token on the log screen';
        }

        [$code] = http('viewer', 'POST', "$cp/actions/friend/pins/upload", [
            'CRAFT_CSRF_TOKEN' => $token,
            'file' => csv("from,to\n" . PREFIX . "http-viewer,/x\n"),
        ]);

        return $code === 403 && $pin(PREFIX . 'http-viewer') === null ?: "HTTP $code";
    });
    check('the viewer is refused the export', function() use ($cp) {
        [$code] = http('viewer', 'GET', "$cp/actions/friend/pins/export");

        return $code === 403 ?: "HTTP $code";
    });

    echo "\nUser who can edit pins, not an admin\n";

    $editorPage = '';

    check('the editor signs in and sees the import screen without the off-site switch', function() use ($cp, $editor, &$editorPage) {
        if (!signIn('editor', $editor->username)) {
            return 'impersonation failed';
        }

        [$code, $editorPage] = http('editor', 'GET', "$cp/friend/pins/import");

        return $code === 200 && !str_contains($editorPage, 'name="allowExternal"') ?: "HTTP $code";
    });
    check('the editor cannot allow an off-site destination by posting the switch anyway', function() use ($cp, $pin, &$editorPage) {
        [$code] = http('editor', 'POST', "$cp/actions/friend/pins/upload", [
            'CRAFT_CSRF_TOKEN' => csrf($editorPage),
            'allowExternal' => '1',
            'file' => csv("from,to\n" . PREFIX . "http-editor-ext,https://evil.example/\n" . PREFIX . "http-editor,/fine\n"),
        ]);

        return $code === 200 && $pin(PREFIX . 'http-editor-ext') === null && $pin(PREFIX . 'http-editor')?->url === '/fine' ?: "HTTP $code";
    });
} finally {
    $cleanup();

    foreach (glob("$jarDir/*") ?: [] as $f) {
        @unlink($f);
    }

    @rmdir($jarDir);
}

echo "\n$passed passed, $failed failed\n\n";

exit($failed === 0 ? 0 : 1);
