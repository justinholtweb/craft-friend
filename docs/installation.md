---
title: Installation
slug: installation
order: 10
summary: Requirements, install, what you get out of the box, and your first ten minutes.
---

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later

No build step, and no runtime dependencies beyond Craft's own.

## Install

```sh
composer require justinholtweb/craft-friend
php craft plugin/install friend
```

Friend is free. There are no editions and no licence key to enter.

## What you get out of the box

Installing creates three tables — rules, pins and the 404 log — and two rules:

| Rule | State | What it does |
|---|---|---|
| **Similar entries** | on | Looks for the live entry whose slug and title are closest to the missing URL, using exact slug, word overlap and the search index. Redirects with a 302 when the best match scores 55 or more. |
| **Nearest surviving page** | off | Walks up the path instead — `/services/seo-audits` → `/services`. Switched off until you have decided you want it. |

It also switches on the guards: control panel, action and preview requests, anything that is not
`GET` or `HEAD`, the homepage, the usual scanner paths (`wp-*`, `xmlrpc.php`, dot-paths) and
missing files with an asset extension (images, stylesheets, scripts, fonts, archives…) are never
touched.

So from the moment it is installed, Friend is redirecting. If you would rather look first, switch
**Look for friend** off in **Friend → Settings**, or disable the **Similar entries** rule, and
use the tester until you trust it.

## Your first ten minutes

1. Go to **Friend → Tester** and try a handful of URLs you know are dead — old links from a
   previous site, entries you have renamed, typos. The tester shows every rule's verdict and every
   candidate's score, and it never redirects anyone or writes to the log.
2. If a URL lands somewhere wrong, the score breakdown tells you why. Raise the rule's
   **Threshold**, or change its **Weights** — see [Rules](rules).
3. Leave it running for a day and open **Friend → 404 log**. Sort by **Requests**. The top of that
   list is what is actually broken on your site, and the **What happened** column says whether
   Friend moved each visitor and where to.
4. When a row has been getting the right answer, press **Pin** on it. That answer is now stored and
   no longer guessed.

## Uninstalling

Uninstalling drops all three tables. Your rules, pins and log go with them.
