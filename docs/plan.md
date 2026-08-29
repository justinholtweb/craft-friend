# Friends — plan

**Every dead URL has a friend.** When a page 404s, Friends looks for the entry the visitor was
probably after and sends them there, under rules an admin writes.

Package `justinholtweb/craft-friends`, namespace `justinholtweb\friends`, handle `friends`.
**Free — no editions, no licensing code.** Craft 5.3+, PHP 8.2+, no build step, no runtime
dependencies beyond Craft's own.

Reference point: the WordPress *404 Auto Redirect to Similar Post* plugin. Friends covers the same
ground and then some — because in WordPress "similar post" is one search across one post table,
and in Craft the admin needs to say *which* elements are eligible, *when*, and *how sure* the
match has to be before a visitor is moved.

## The shape of the problem

A 404 is a visitor who typed, followed or inherited a URL that used to work. Three different
things are usually true, and they need three different answers:

1. **The slug changed.** `/blog/our-new-office` became `/news/our-new-office`. A textual match on
   the last segment finds it with near-certainty.
2. **The page moved under a new parent, or was never a page at all.** `/services/seo-audits` when
   the site only has `/services`. The nearest surviving ancestor is the right answer.
3. **Nobody knows.** A scanner asking for `/wp-login.php`. The right answer is to stay 404, and to
   never even try — which is a guard, not a rule.

## The load-bearing decisions

### Hook: `craft\web\ErrorHandler::EVENT_BEFORE_HANDLE_EXCEPTION`

Craft fires this at the very top of `handleException()`, *before* it consults
`config/redirects.php` and long before it renders the site's 404 template. That is the only place
a redirect costs nothing: by the time a `Response` event could see the 404, the error template has
already been rendered and thrown away.

Redirecting from inside it is Craft's own pattern in the same method —
`getResponse()->redirect($url, $code)` then `Craft::$app->end()`, which sends and exits.

Because our handler runs *first*, `config/redirects.php` would otherwise never get a look in. So
Friends re-checks those rules itself and stands down when one of them matches (`honourConfigRedirects`,
on by default). Explicit beats inferred, always.

### 302 by default, not 301

The WordPress plugin defaults to 301. A 301 is cached by the browser and by every intermediary,
often for as long as the browser feels like — so a *guessed* 301 that guesses wrong is a URL the
visitor cannot reach again even after the admin fixes the rule. Friends defaults to **302** and
lets a rule opt into 301 once the admin trusts it. This is written down in the settings screen,
not just here.

### Retrieval and scoring are separate

Retrieval is about recall — getting plausible elements out of the database cheaply. Scoring is
about precision — ranking them honestly. Mixing the two is how "similar post" plugins end up
redirecting `/contact-us` to a blog post from 2014.

**Retrieval methods** (per rule, any combination):

| method | what it asks the database |
| --- | --- |
| `slug` | an element whose slug is exactly the missing last segment |
| `tokens` | elements whose slug or title contains any significant token from the URL |
| `search` | Craft's own search index, queried with the tokens OR-ed together |
| `ancestor` | the nearest existing element at a shorter prefix of the same path |

**Scoring** re-ranks everything retrieval found, in PHP, with a weighted blend:

- **slug similarity** — Dice coefficient over tokens, plus `similar_text` on the raw strings
- **title similarity** — same, against the element's title
- **path affinity** — how many leading path segments the miss and the candidate share

Weights are per rule and normalised, so an admin who only cares about slugs can zero the rest.
Ancestor candidates are scored structurally instead: `100 × ancestorSegments / missSegments`, which
is comparable to the blend and honest about how much of the path actually survived.

A rule's **threshold** (default 55) is the whole safety mechanism. Below it, the rule declines and
the next rule gets a turn.

### Rules are ordered, and the first one that *decides* wins

A rule can decide three ways: `redirect`, `suggest` (record the match, stay 404, hand the
candidates to the template) or `ignore` (match the URI and deliberately do nothing, so no later
rule fires). `ignore` is how `/wp-*` gets excluded without touching the global guard.

A rule that matches the URI but finds nothing above its threshold does **not** decide — evaluation
falls through to the next rule.

### Pins beat rules

A pin is a stored exact mapping for one URI. Two ways to get one: type it, or press **Pin** on a
row in the 404 log once the engine has proved it picks the right target. Pins are consulted before
any rule, cost one indexed lookup, and are the escape hatch for the one URL the scoring gets wrong.

### The log is the product

Aggregated per `(siteId, uri)` with a hit counter and last-seen date, so it is a usable 404 report
in its own right — which target was chosen, at what score, by which rule, and whether the visitor
was actually moved. Pruned by age and by row cap.

### The tester

A CP screen and a console command that take a URL and print the full trace: every rule considered,
why each was skipped or declined, and the ranked candidates with their score breakdown. A fuzzy
engine nobody can interrogate is a fuzzy engine nobody will turn on.

## Guards (before any rule is consulted)

Site requests only · GET and HEAD only · not CP, action, console or live preview · path not in the
ignored-pattern list · extension not in the ignored-extension list · plugin enabled. And on the way
out: never redirect to the URI that just missed, and never to a target with no URL.

## Data

- `friends_rules` — ordered rule set; scalars in columns, the four config blocks as JSON
- `friends_pins` — unique on `(siteId, uri)`
- `friends_log` — unique on `(siteId, uri)`, hit-counted

## Caching

Rule set in memory and in Craft's cache. Resolved outcomes cached per `(siteId, uri)` for an hour
by default — dead URLs are hammered by bots, and the second thousand requests should not each pay
for a search query.
