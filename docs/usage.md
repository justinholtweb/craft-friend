---
title: Usage
slug: usage
order: 40
summary: The 404 log, pins (and importing them from CSV), the tester, Twig, console commands and caching.
---

## What happens on a 404

Friend listens on Craft's `ErrorHandler::EVENT_BEFORE_HANDLE_EXCEPTION`, which fires at the top of
Craft's exception handling — before Craft reads `config/redirects.php`, and before it renders your
404 template. A redirect from there costs nothing that would otherwise be thrown away.

For a 404 that passes the [guards](installation#what-you-get-out-of-the-box), Friend checks
`config/redirects.php`, then pins, then [rules](rules). It writes the result to the log, and either
redirects or steps aside and lets your 404 template render.

Nothing Friend does on that path can turn a 404 into a 500. If something goes wrong, it writes an
error to Craft's log under the `friend` category and the visitor gets their 404.

## The 404 log

**Friend → 404 log**. One row per site and URI, with a hit counter — a usable "what is broken on
this site" report, not a million-row request log.

Each row shows the requested path, the most recent referrer, how many times it has been requested,
what happened (the status and target, and which rule or pin decided), the score, and when it was
last seen. The summary at the top counts dead URLs, total requests, how many were sent somewhere
and how many are still 404.

Filter by **Still 404** or **Sent somewhere**, search URIs and targets, and sort by last seen,
requests, URI or score. **Test** opens the row in the tester.

Only 404s Friend actually looked at are logged. Requests stopped by a guard, and URIs that
`config/redirects.php` handled, are not.

The log is trimmed by age (**Keep for**) and then by row count (**Row cap**) during Craft's garbage
collection, never on a visitor's request. `php craft gc` runs it on demand, and so does
`php craft friend/log/prune`.

## Pins

A pin is a stored exact answer for one URI, checked before any rule and costing one indexed
lookup. It is the escape hatch for the one URL the scoring gets wrong, without making the rules
worse for every other URL.

There are two ways to make one:

- **Pin** on a row in the 404 log. The row's current target becomes the pin's target. The button
  only appears on rows that have a target, and for users who can edit pins.
- **Friend → Pins → New pin**.

| Field | Notes |
|---|---|
| URI | The path that 404s, without the domain. A leading slash is fine |
| Point at | **An entry**, or **A URL** — a site URI like `blog`, or a full http(s) URL to somewhere else entirely |
| Site | One site, or **All sites**. A pin for one site beats an all-sites pin for the same URI |
| Redirect status | The plugin default, or `301`/`302`/`307`/`308` |
| Enabled | A disabled pin is ignored |

A pin that points at an entry follows the entry: if its URI changes, the pin's redirect changes
with it. A pin whose target no longer resolves, or would point back at the URI that missed, is
skipped and the rules get their turn. Each pin counts its hits.

### Importing and exporting pins

A migration usually arrives with a redirect map — the old site's Retour or Redirect Manager
export, a WordPress plugin's CSV, a spreadsheet somebody kept. Import it as pins and the redirects
you already know are answered before any rule guesses.

**Friend → Pins → Import CSV**, or `php craft friend/pins/import`. Columns are read by header:

| Column | Also accepted as | Notes |
|---|---|---|
| `from` | `source`, `old`, `uri`, `path`, `Legacy URL Pattern`, `redirectSrcUrl`, … | The URI that 404s. A full URL is fine — only its path is kept |
| `to` | `destination`, `target`, `new`, `url`, `Redirect To`, `redirectDestUrl`, … | Where it goes. See below |
| `status` | `statusCode`, `code`, `HTTP Status`, `redirectHttpCode` | `301`, `302`, `307` or `308`; blank uses the plugin default |
| `site` | `siteHandle`, `siteId` | A site handle or ID; blank uses the site chosen for the import, or all sites |
| `enabled` | `active` | `0`, `false`, `no` or `off` imports the pin disabled |
| `pointsAt` | | `entry` or `url` — written by the export so a file imports back exactly |
| `Match Type` | `redirectMatchType` | Rows marked as regex are refused: a pattern is a [rule](rules), not a pin |

A file with no recognisable header is read as `from,to,status`. Comma, semicolon and tab
delimiters all work, and a file Excel saved as Windows-1252 is converted.

**Destinations must be on this site.** A pin is a redirect, so a file that could plant
`https://elsewhere.example` behind one of your URLs would make your site an open redirect. A
destination is accepted when it is a path (`/about`, `about?tab=team`) or a full http(s) URL on one
of this install's site hosts (compared without the port or a leading `www.`), which is stored as a
path. Protocol-relative `//host` destinations, other schemes, backslashes, spaces and control
characters are refused. An admin can switch on **Allow destinations on other hosts** (console:
`--allow-external`) for a file they have read.

**Point at entries** (on by default; console `--link-elements=0` to turn off): when a destination
is exactly an entry's URI on the pin's site, the pin points at the entry, so it follows the entry if
its URI changes later.

A URI that already has a pin is left alone unless you switch on **Overwrite existing pins**
(`--update`), which keeps the pin's hit count. **Dry run** (`--dry-run`) checks every row and
reports, without saving anything — the CP form starts with it on.

Each refused row is reported with its line number and the reason: an unknown site, a status Friend
doesn't send, a destination off the site, a destination that is the source itself, a duplicate of an
earlier line. Good rows still import. A file is capped at 2 MB and 10,000 rows; split anything
bigger.

**Export CSV** on the Pins screen (or `php craft friend/pins/export`) writes
`from,to,status,site,enabled,pointsAt`, the same columns the importer reads. An entry pin exports
as the entry's current path. A cell that a spreadsheet would run as a formula (one starting with
`=`, `+`, `-`, `@`, a tab or a carriage return) is written with a leading apostrophe, and the
importer strips it again.

Importing and exporting need **Create and edit pins**.

## The tester

**Friend → Tester**. Enter a path, pick a site on a multi-site install, and press **Try it**.

You see the slug and the words Friend extracted, every enabled rule's verdict with the reason and
its best score, every candidate with its **slug**, **title** and **path** scores and the methods
that found it, and the final answer. Verdicts are:

| Verdict | Meaning |
|---|---|
| `skipped` | The rule does not apply to this URI — pattern, exclusion or segment count |
| `no-candidates` | Nothing came back from any retrieval method |
| `below-threshold` | Candidates came back, but the best did not reach the threshold |
| `matched` | The rule (or a pin, or a fallback URL) redirects |
| `suggested` | The rule matched and hands suggestions to the template |
| `ignored` | The rule matched and is set to do nothing |

The tester bypasses the cache and does not write to the log, so it shows what the rules say now.
(A pin it lands on does still count the hit.)

It cannot evaluate `config/redirects.php` for a typed-in URI, because Craft's redirect rules only
match against the live request. When the file exists and **Let config/redirects.php win** is on,
the tester says so rather than implying it checked.

## Twig

`craft.friend` is available in every template. In your 404 template:

```twig
{% set suggestions = craft.friend.suggestions(5) %}

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

| Tag | Returns |
|---|---|
| `craft.friend.suggestions(limit, uri)` | Ranked candidates, best first. `limit` defaults to 5 |
| `craft.friend.best(uri)` | The single best candidate, or `null` |
| `craft.friend.outcome(uri)` | Everything Friend decided, or `null` |
| `craft.friend.missedUri()` | The path that 404'd, in normal form — no domain, no query, no leading slash |
| `craft.friend.enabled()` | Whether **Look for friend** is on. When it is off, the other tags return nothing |

Without a `uri`, the tags answer for the current request. With one, they resolve that URI as though
it had just 404'd — so they work on any page, not just a 404. Each call with a `uri` runs the rules
fresh, uncached, so set the result once and reuse it.

Suggestions come from the rule that decided, or — when no rule decided — from the first rule that
found any candidates, whether or not they cleared its threshold. A "did you mean" list has a much
lower bar than a redirect.

A **candidate** has `title`, `uri` (no leading slash), `url`, `score` (0–100), `elementId`,
`elementType`, `methods` (which retrieval methods found it) and `breakdown` (`slug`, `title` and
`path`, each 0–1).

An **outcome** has `action` (`redirect`, `suggest`, `ignore` or `none`), `source` (`pin`, `rule` or
`null`), `rule`, `pin`, `candidate`, `candidates`, `targetUrl`, `statusCode`, `miss` and `traces`,
plus `matched()`, `shouldRedirect()` and `getScore()`.

## Console

```sh
php craft friend/match/test <uri> [--site=handle] [--all]   # resolve a URI, print the reasoning
php craft friend/match/rules                                # the rule set in evaluation order
php craft friend/log [--limit=25] [--unresolved]            # the busiest 404s
php craft friend/log/prune                                  # apply the retention settings now
php craft friend/log/clear                                  # empty the log
php craft friend/pins/import <file> [--site=handle] [--update] [--dry-run] [--link-elements=0] [--allow-external]
php craft friend/pins/export [file] [--site=handle]         # to a file, or standard output
```

`friend/match/test` is the tester without a browser. It resolves against the primary site unless
you pass `--site`, and shows the top eight candidates unless you pass `--all`.

```
$ php craft friend/match/test blog/2019/our-new-offices

  blog/2019/our-new-offices
  site: Main   slug: our-new-offices   tokens: blog, 2019, our, new, office

  skipped          Ignore probes — The URI does not match `re:^wp-`.
  matched          Similar entries (best 92.3) — Redirecting to news/our-new-office.

  Candidates
     92.3  /news/our-new-office      slug 0.87  title 1.00  path 0.00  [slug,tokens,search]
     41.0  /news/office-move         slug 0.44  title 0.40  path 0.00  [search]

  → 302 https://example.com/news/our-new-office
```

`friend/log` lists rows by hit count, with totals at the top. `friend/log/clear` asks for
confirmation; add `--interactive=0` to skip it in a script.

`friend/pins/import` prints a summary and every refused row with its line number, and exits `65`
when any row was refused, so a deploy script can stop on it. See
[Importing and exporting pins](#importing-and-exporting-pins) for the columns and the rules.

## Caching

Dead URLs get hammered — one broken link in a newsletter, one stale sitemap, one scanner working
through a wordlist. Friend caches its decision for each dead URL for **Cache decisions for**
seconds (an hour by default), so only the first request pays for the search. Every request is
still counted in the log.

Saving, deleting or reordering rules, and saving or deleting a pin, clears every cached decision at
once. Saving an entry does not. Suggestion decisions are never cached.

Rule candidates are scored from `id`, `slug`, `title` and `uri`, and their URLs built from the URI
and the site exactly as `Element::getUrl()` would. Matching a rule never loads a full element.
