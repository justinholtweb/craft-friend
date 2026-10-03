---
title: Rules
slug: rules
order: 30
summary: How rules are evaluated, every field on the rule screen, and how candidates are found and scored.
---

## How a 404 is decided

```
404
 ├─ guards ............. a GET for a page, not the homepage,
 │                       not an ignored path or extension?
 ├─ config/redirects.php  already covers it? → stand down, let Craft do it
 ├─ pins ............... an exact stored answer for this URI? → use it
 └─ rules, in order .... first one that decides wins
```

Rules are tried top to bottom, in the order shown on **Friend → Rules**. Drag them to reorder. New
rules are added at the bottom.

A rule **decides** in one of three ways:

| Action | What happens |
|---|---|
| **Redirect to the best match** | The visitor is moved to the winning candidate |
| **Stay 404, offer suggestions** | The 404 stands, and your template gets a ranked "did you mean" list from [`craft.friend.suggestions()`](usage#twig) |
| **Do nothing, and stop here** | The rule matched on purpose and no later rule is consulted |

A rule that applies but finds nothing at or above its threshold has **not** decided. Evaluation
falls through to the next rule. That applies to **Suggestions** rules too: a suggestions rule only
decides when its best candidate clears its threshold, so give it a lower threshold than a redirect
rule would have.

If no rule decides, the visitor gets your normal 404 page.

## What it does

| Field | Default | Notes |
|---|---|---|
| Name, Handle | — | Required. The handle must be unique |
| Description | — | For whoever reads the rule list in a year |
| Enabled | on | A disabled rule is skipped entirely |
| Action | Redirect | Redirect, Suggestions or Do nothing — see above |
| Redirect status | Use the plugin default | `301`, `302`, `307` or `308` for this rule only |
| If nothing clears the threshold | Let the next rule try | Or **Send them somewhere anyway** |
| Fallback URL | — | Required when the fallback is on |

**Send them somewhere anyway** turns the rule into one that always decides: when nothing clears
the threshold, or nothing is found at all, the visitor is redirected to the **Fallback URL** with
the rule's redirect status. This happens whatever the rule's action, except **Do nothing**. The
fallback is a URI on the current site, like `blog`, or a full URL to somewhere else.

Use it sparingly. A fallback turns every unresolved 404 under the rule into a redirect, which
hides your broken links rather than showing them to you.

## When it applies

| Field | Default | Notes |
|---|---|---|
| Only URIs matching | any | A glob like `blog/*`, or `re:` and a regular expression — see [Patterns](configuration#patterns) |
| Except | none | Patterns that disqualify the rule even when the one above matched |
| Fewest path segments | — | 1–20. `blog/2019/post` has three |
| Most path segments | — | 1–20 |
| Sites | every site | Only shown on a multi-site install. Leave everything unchecked for every site |

## Where it may point

| Field | Default | Notes |
|---|---|---|
| Element type | Entries | Any element type that has URLs — entries, categories, Commerce products, and so on |
| Sections, Entry types | all | For entries. Leave unchecked for all of them |
| Category groups | all | For categories |
| Only under | — | A URI prefix. Only elements whose URI starts with `prefix/` are considered. Works for every element type |
| Live elements only | on | Live entries, enabled everything else. Off lets a disabled page become a redirect target, which is almost always a mistake |

A candidate whose URI is the one that just 404'd is always dropped, so a rule can never redirect a
visitor back to the URL they asked for.

## How sure it must be

| Field | Default | Notes |
|---|---|---|
| How to find candidates | Exact slug, Word overlap, Search index | Any combination of the four methods below. At least one is required |
| Weights | slug 60 · title 25 · path 15 | 0–100 each. Relative, not out of 100 |
| Threshold | 55 | 0–100. The best candidate must score at least this |
| Candidate limit | the plugin default | 1–500. Most elements one retrieval method may return |

## Finding candidates

Each rule runs the methods you tick, merges what they found, and only then scores the lot.

| Method | What it asks the database |
|---|---|
| **Exact slug** | An element whose slug is exactly the missing last segment, with any file extension removed. The strongest and cheapest — it is what a moved page looks like |
| **Word overlap** | Elements whose slug or title contains any of the first six significant words of the missing slug |
| **Search index** | Craft's own search index, with up to eight words from the whole path OR-ed together. Reaches page content, not just slugs and titles |
| **Nearest ancestor page** | The closest existing element at a shorter prefix of the same path |

"Significant words" means the URL broken on anything that is not a letter or a digit, lowercased,
transliterated (`café` matches `cafe`), with a trailing plural `s` removed, and with common English
words (`the`, `and`, `of`…), your **Words to ignore** and anything shorter than **Shortest word to
compare** dropped. Numbers are never dropped — `/guide-part-1` and `/guide-part-2` stay different.

## Scoring

Every candidate gets three numbers between 0 and 1, which the tester shows:

- **slug** — the better of word overlap (the Dice coefficient) against the missing slug, and
  character similarity, which catches typos and missing hyphens
- **title** — the better of word overlap between the whole missing path and the element's title,
  and character similarity between the missing slug and the title
- **path** — how many leading parent segments the two URLs share. `blog/2019/x` and `blog/2024/y`
  share one of two

The score is the weighted average of the three, as a number from 0 to 100. Weights are relative,
so `3 / 1 / 1` means what it looks like. Set a weight to 0 to ignore that dimension.

**Nearest ancestor page** candidates are scored on structure instead: `100 × surviving segments ÷
total segments`. `/services/seo-audits` → `/services` keeps one segment of two, so it scores 50.
`/services/2019/seo-audits` → `/services` scores 33.3. Whichever is higher — that or the blended
score — counts.

Candidates are ranked by score, and a tie goes to the shorter URI: of two equally good matches,
the more general page is the safer place to send someone who is already lost.

**The threshold is the whole safety mechanism.** Start high and lower it with the tester open.

## Examples

**Keep a branch of the site out of everything.** Put a rule at the top with **Only URIs matching**
set to `members/*` and the action **Do nothing, and stop here**. No rule below it will ever
redirect a members URL.

**Only match blog posts to blog posts.** **Only URIs matching** `blog/*`, **Sections** set to your
blog section, and raise the **path** weight so a post stays near its own year or category.

**Walk up the path as a last resort.** Enable **Nearest surviving page** below **Similar entries**.
Similar entries tries first; when it declines, the nearest surviving parent gets its turn — but
only when at least half the path survived, because its threshold is 50.

**A "did you mean" page instead of redirects.** Set the action to **Stay 404, offer suggestions**,
lower the threshold to 30 or so, and add [`craft.friend.suggestions()`](usage#twig) to your 404
template.
