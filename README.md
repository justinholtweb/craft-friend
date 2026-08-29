# Friends

**Every dead URL has a friend.** When a page 404s, Friends looks for the entry the visitor was
probably after and sends them there — under rules you write, with a threshold you set, and a log
of every guess it made.

Free, no editions, no licence key.

```sh
composer require justinholtweb/craft-friends
php craft plugin/install friends
```

Craft 5.3+, PHP 8.2+. No build step; no runtime dependencies beyond Craft's own.

Reference point: the WordPress *404 Auto Redirect to Similar Post* plugin. Friends covers the same
ground and then some — because in WordPress "similar post" is one search across one post table,
and in Craft you need to say *which* elements are eligible, *when*, and *how sure* the match has to
be before a visitor gets moved.

---

## What it does

A 404 is usually a visitor holding a URL that used to work. Three different things are normally
true, and they need three different answers:

1. **The slug moved.** `/blog/our-new-office` became `/news/our-new-office`. An exact match on the
   last segment finds it with near-certainty.
2. **The page went, but its parent survived.** `/services/seo-audits` when the site only has
   `/services`. The nearest surviving ancestor is the right answer.
3. **Nobody knows.** A scanner asking for `/wp-login.php`. The right answer is to stay 404, and to
   never even try.

Friends installs with a rule for the first case already switched on, an example for the second
switched off to read, and guards for the third.

## How a 404 is decided

```
404
 ├─ guards ............. site request? GET? not an ignored path or extension?
 ├─ config/redirects.php  already covers it? → stand down, let Craft do it
 ├─ pins ............... an exact stored answer for this URI? → use it
 └─ rules, in order .... first one that decides wins
```

A rule can decide three ways:

| action | what happens |
| --- | --- |
| **Redirect** | the visitor is moved to the winning candidate |
| **Suggestions** | the 404 stands, and your template gets a ranked "did you mean" list |
| **Do nothing** | the rule matched on purpose and stops — how you exclude a branch of the site from every rule below it |

A rule that applies but finds nothing above its threshold has **not** decided. Evaluation falls
through to the next rule.

## Finding candidates

Retrieval is about recall; scoring is about precision. Mixing them is how a "similar post" feature
ends up sending `/contact-us` to a blog entry from 2014.

**Retrieval** — any combination, per rule:

| method | what it asks the database |
| --- | --- |
| **Exact slug** | an element whose slug is exactly the missing last segment |
| **Word overlap** | elements whose slug or title contains any significant word from the URL |
| **Search index** | Craft's own search index, with the words OR-ed together |
| **Nearest ancestor page** | the closest existing element at a shorter prefix of the same path |

**Scoring** re-ranks everything that came back, in PHP, as a weighted blend of three numbers you
can see:

- **slug** — word overlap (Dice) against the missing slug, or character similarity, whichever is
  the better read
- **title** — the same, against the element's title
- **path** — how many leading path segments the two URLs share

Weights are relative, so `3 / 1 / 1` means what it looks like. Ancestor candidates are scored
structurally instead — `100 × surviving segments ÷ total segments` — which is comparable with the
blend and honest about what it means.

**The threshold is the whole safety mechanism.** Default 55. Below it, the rule declines.

## Seeing why

**Friends → Tester** takes a URL and shows the entire decision: every rule considered, why each
was skipped or declined, and the ranked candidates with their score breakdown.

```
$ php craft friends/match/test blog/2019/our-new-offices

  blog/2019/our-new-offices
  site: Main   slug: our-new-offices   tokens: blog, 2019, our, new, office

  skipped          Ignore probes — The URI does not match `re:^wp-`.
  matched          Similar entries (best 92.3) — Redirecting to news/our-new-office.

  Candidates
     92.3  /news/our-new-office      slug 0.87  title 1.00  path 0.00  [slug,tokens,search]
     41.0  /news/office-move         slug 0.44  title 0.40  path 0.00  [search]

  → 302 https://example.com/news/our-new-office
```

## The 404 log

One row per site and URI, with a hit counter — so it is a usable "what is broken on this site"
report, not a million-row request log. It records which target was chosen, at what score, by which
rule, and whether the visitor was actually moved.

Once a rule has been picking the right target for a URL a few hundred times, press **Pin** on its
row. A pin is a stored exact answer, checked before any rule, costing one indexed lookup — the
escape hatch for the one URL the scoring gets wrong, without making the rules worse for every
other URL.

## In your 404 template

Set a rule's action to **Suggestions** — or just let a rule decline — and offer the near-misses
instead of moving anyone:

```twig
{% set suggestions = craft.friends.suggestions(5) %}

{% if suggestions %}
    <h2>Did you mean…</h2>
    <ul>
        {% for candidate in suggestions %}
            <li>
                <a href="{{ candidate.url }}">{{ candidate.title }}</a>
                <span class="score">{{ candidate.score }}%</span>
            </li>
        {% endfor %}
    </ul>
{% endif %}
```

| tag | what it gives you |
| --- | --- |
| `craft.friends.suggestions(limit, uri)` | ranked candidates, best first |
| `craft.friends.best(uri)` | the single winning candidate, or null |
| `craft.friends.outcome(uri)` | everything Friends decided, traces included |
| `craft.friends.missedUri()` | the path that 404'd, in normal form |

Every one of them takes an optional URI, so the same tags work on any page, not just a 404.

## Why 302 and not 301

The WordPress plugin defaults to 301. A 301 is cached by the browser and by everything between it
and your server, often for far longer than anyone intends — so a *guessed* permanent redirect that
guesses wrong leaves a visitor unable to reach that URL again even after you fix the rule.

Friends defaults to **302**, and a rule can opt into 301 once its column in the log has convinced
you it gets the answer right.

## Guards

Checked before any rule, and not overridable per rule:

- site requests only — never the control panel, an action request, or a preview
- `GET` and `HEAD` only
- never the homepage
- never an ignored URI pattern (`wp-*`, `xmlrpc.php`, dot-paths… — editable)
- never an ignored file extension (images, stylesheets, scripts, archives… — editable), because
  redirecting a missing image to an HTML page turns a visible 404 into an invisible
  wrong-content-type bug
- never to the URI that just missed, which is a redirect loop

## `config/redirects.php`

Craft 5.6 added its own static redirect file. Friends hooks the top of Craft's exception handling,
which is *before* Craft reads it — so without help, a fuzzy guess would quietly outrank an explicit
instruction. Friends checks that file first and stands down when a rule in it already matches.
Explicit beats inferred. Switch it off in the settings if you want the opposite.

## Console

```sh
php craft friends/match/test <uri> [--site=handle] [--all]   # resolve a URI, print the reasoning
php craft friends/match/rules                                # the rule set in evaluation order
php craft friends/log [--limit=25] [--unresolved]            # the busiest 404s
php craft friends/log/prune                                  # apply the retention settings now
php craft friends/log/clear                                  # empty the log
```

## Performance

- Resolved outcomes are cached per URI for an hour by default. Dead URLs get hammered — one broken
  link in a newsletter, one stale sitemap, one scanner working through a wordlist — and the second
  thousand requests should not each pay for a search query. Saving a rule or a pin clears it.
- Candidates are scored from `id`, `slug`, `title` and `uri`, and their URLs are built from the URI
  and the site exactly as `Element::getUrl()` would. Nothing on the hot path loads an element.
- The log is capped by age *and* by row count, trimmed during Craft's garbage collection.

## Permissions

| permission | what it allows |
| --- | --- |
| View the 404 log | the log and the tester |
| Create and edit pins | pins, and the **Pin** button on the log |
| Create and edit rules | the rule set |

## Licence

MIT. See `LICENSE.md`.
