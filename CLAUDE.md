# Friend — Craft CMS 5 Plugin

## Project Overview

Friend redirects 404s to the entry the visitor was probably after, under admin-written rules.
Distributed as `justinholtweb/craft-friend`. **Free — no editions, no licensing code.**

Reference point: the WordPress *404 Auto Redirect to Similar Post* plugin. Same ground, done the
Craft way — because "similar post" in WordPress is one search over one table, and in Craft the
admin has to say *which* elements are eligible, *when*, and *how sure* the match must be.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, Yii2, Twig
- No build step. One hand-written CP stylesheet, no JavaScript beyond two inline snippets.
- No runtime dependencies beyond Craft's own.

## Architecture

- Namespace `justinholtweb\friend` · package `justinholtweb/craft-friend` · handle `friend`

### The one hook everything hangs off

`craft\web\ErrorHandler::EVENT_BEFORE_HANDLE_EXCEPTION` (with `craft\events\ExceptionEvent`).
Craft fires it at the top of `handleException()` — **before** it reads `config/redirects.php` and
long before it renders the site's 404 template. That is the only place a redirect is free; by the
time a `Response` event could see the 404, the error template has already been rendered and is
about to be discarded. Redirecting from inside it is Craft's own pattern in the same method:
`getResponse()->redirect($url, $code)` then `Craft::$app->end()`, which sends and exits.

### The decision

guards → `config/redirects.php` deference → pins → rules in order. A rule decides by redirecting,
by offering suggestions, or by matching and deliberately doing nothing. **Declining because nothing
cleared the threshold is not a decision** — the next rule gets a turn.

### Retrieval and scoring are separate, on purpose

`services\Candidates` does both halves and keeps them apart. Retrieval (`_bySlug`, `_byTokens`,
`_bySearch`, `_byAncestor`) is about recall; scoring (`_score` + `helpers\Similarity`) is about
precision. Mixing them is how a similar-post feature ends up redirecting `/contact-us` to a 2014
blog post — whatever the database returned first gets treated as an answer instead of a shortlist.

**Nothing on the hot path loads an element.** Candidates are scored from `id`, `slug`, `title` and
`uri` via `->asArray()`, and a URL is built with `UrlHelper::siteUrl($uri, null, null, $siteId)` —
exactly what `Element::getUrl()` does.

### Services

- `rules` — the ordered set, memoised
- `pins` — exact stored answers; `Pins::hash()` is the shared `(siteId, uri)` key for pins *and*
  the log
- `pinTransfer` — pins in and out of CSV (`friend/pins/import|export`, CP **Import CSV**). Reading
  and writing live in `helpers\Csv` (size/row caps, delimiter sniffing, formula-cell guard and its
  inverse); validation in the service. **Imported destinations must be same-site** — a path, or an
  http(s) URL on one of the install's site hosts (port and `www.` ignored) rewritten to a path —
  because an imported pin is a redirect; `allowExternal` is admin-only in the CP. `pointsAt` in the
  export keeps an export→import round trip exact
- `matcher` — guards, deference, resolution, caching, and the outcome for the request in flight
- `candidates` — retrieval and scoring
- `log` — aggregated per `(siteId, uri)` with a hit counter, pruned by age and row cap on GC

### Caching

One entry per dead URL, keyed on a **generation counter** folded into the key. There is no index of
those keys, so "forget everything" cannot be a list of deletes — bumping the counter makes the
whole previous generation unreachable in one write. Suggestion outcomes are never cached, because
the cache stores a decision and not a candidate list.

## Traps found while building this

- **`action` is Craft's controller parameter.** The rule edit form has an action select
  (redirect / suggest / ignore), and naming that field `action` overwrites `friend/rules/save`
  with `redirect` — the request 404s on a route nobody wrote, *before the controller runs*. Posted
  as `ruleAction`. Same family as the reserved `token` and `p` params in
  `[[craft-plugin-gotchas]]`.
- **`hasMethod()` collides with `yii\base\Component`.** A `Rule::hasMethod(string): bool` is a
  fatal compile error the moment the class autoloads, nowhere near the call site. Renamed
  `usesMethod()`. Same family as `Component::load()`.
- **`array_keys()` on a set with numeric-string keys returns ints.** `$tokens['1'] = true` is
  stored as `$tokens[1]`, so `tokenize()` silently broke its own `string[]` contract and every
  strict comparison downstream. `array_map('strval', array_keys(...))`.
- **Digits must survive the token length floor.** A bare number in a slug is a year, a version, an
  ordinal — never noise — and dropping it makes `/guide-part-1` and `/guide-part-2` identical to
  the matcher.
- **`RedirectRule::getMatch()` reads the current request**, not a subject you pass it. So
  `configRedirectCovers()` can only answer about the URI actually being requested; asking it about
  a hypothetical path in the tester would match Craft's rules against a *control panel* URL.
  It declines instead, and the tester says so.
- **A candidate at the URI that just missed is a redirect loop.** It really happens: a disabled
  element keeps its URI, so a rule with `enabledOnly` off finds the very page that 404'd.
- **`status('live')` is entry-only** (already in `[[craft-plugin-gotchas]]`) — `_statusFor()`
  reads `$type::statuses()` and picks `live` or `enabled`, because an element query asked for a
  status its type does not have returns nothing at all rather than erroring.
- **Yii's `like` with `false` as the fourth argument adds no wildcards**, so
  `['like', 'uri', 'prefix', false]` is an equality test that matches nothing. Write the `%`
  yourself.
- **A URI can be longer than any index key either database will take**, and a scanner will send
  one. Pins and log rows are unique on a `sha1(siteId|uri)` column, which needs no prefix lengths
  and behaves the same on MySQL and Postgres.
- **Cross-database upsert with a `hits + 1` expression is not worth it.** The expression forms
  differ between MySQL and Postgres; a read-then-write that loses a count to a race on a 404
  counter is much the cheaper bug.
- **The harness's site base URL carries a port** (`https://plugin-testing.ddev.site:33001/`), and a
  redirect map exported from production has none — so same-site host checks compare hosts without
  the port (or a leading `www.`).
- **`craft\web\Controller` has no `getCurrentUser()`** on Craft 5.3 — it is a 500 at render time.
  `Craft::$app->getUser()->getIsAdmin()`.
- **`ddev exec` runs under `set -u`** and can leave the project stopped;
  `docker exec -w /var/www/html ddev-plugin-testing-web …` is the reliable way to script the
  harness.

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-friend/tests/integration/checks.php   # 132 checks
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-friend/tests/integration/pins-csv.php      # 51 — CSV import/export + console
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-friend/tests/integration/pins-csv-http.php # 18 — upload/export permission, POST, CSRF
docker exec ddev-plugin-testing-web bash -c 'find /var/www/craft-friend/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

The checks are idempotent and self-cleaning — every fixture is deleted whether the run passes or
not, and strays from a run that died are swept first. They build entries in the harness's
`liveTest` section with a `friend-check-` slug prefix, and stand every other rule down so an
outcome is about the rule under test.

End-to-end (the error handler hook itself cannot be reached from the console):

```sh
curl -sk -o /dev/null -D - https://plugin-testing.ddev.site/smoke-test/test-entry-2 | head -4
```

`ddev craft clear-caches/cp-resources` after editing `src/web/assets/cp/dist/friend-cp.css`, or
Craft keeps serving the published copy.

## Coding conventions

- `Craft::t('friend', '…')` for user-facing strings; `src/translations/en/friend.php` is generated
  from the source
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP `fullPageForm` template
- Never mark plugin settings `required`
- Nothing in the 404 path may throw: a 404 is never worth turning into a 500
