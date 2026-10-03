---
title: Configuration
slug: configuration
order: 20
summary: Plugin settings, the config file, guard patterns and permissions.
---

## Settings

**Friend → Settings** (admins only). Every setting has a config key, for use in
[`config/friend.php`](#the-config-file).

| Setting | Config key | Default | What it does |
|---|---|---|---|
| Look for friend | `enabled` | on | The master switch. Off means Friend never handles a 404 — no matching, no logging, no redirect |
| Default redirect status | `redirectStatusCode` | `302` | The status a redirect uses when its rule or pin does not set one. `301`, `302`, `307` or `308` |
| Let config/redirects.php win | `honourConfigRedirects` | on | Stand down when a rule in `config/redirects.php` already matches the request — see below |
| Never touch | `ignoredPatterns` | `wp-*`, `*/wp-*`, `xmlrpc.php`, `.*`, `.*/*`, `cpresources/*` | URI patterns Friend leaves alone entirely |
| Extensions that stay 404 | `ignoredExtensions` | images, stylesheets, scripts, fonts, media, archives, `php`, `asp`, `env`, `sql`… | Missing files with these extensions are never redirected |
| Candidate limit | `candidateLimit` | `50` | Most elements any one retrieval method may pull back before scoring, 1–500. A rule can set its own |
| Shortest word to compare | `minTokenLength` | `2` | Words shorter than this are ignored when comparing, 1–10. Numbers are always kept |
| Words to ignore | `extraStopWords` | none | Extra words that tell Friend nothing — a section name in every slug, house jargon |
| Cache decisions for | `cacheDuration` | `3600` | Seconds to cache a decision per dead URL. `0` turns caching off |
| Record misses | `logMisses` | on | Write to the 404 log |
| Keep for | `logRetentionDays` | `180` | Days since a URI was last requested before its log row is pruned. `0` keeps everything |
| Row cap | `logMaxRows` | `10000` | Most log rows; the least recently seen go first. `0` means no cap |

The full default extension list is `jpg jpeg png gif webp avif svg ico bmp css js mjs map json xml
txt zip gz rar php asp aspx jsp env sql woff woff2 ttf eot otf mp3 mp4 webm mov avi`.

## Why 302 by default

A 301 is cached by the browser and by everything between it and your server, often for far longer
than anyone intends. A *guessed* permanent redirect that guesses wrong leaves a visitor unable to
reach that URL again even after you fix the rule.

Leave the default at 302. Move an individual rule to 301 once its rows in the 404 log have
convinced you it gets the answer right.

## config/redirects.php

Craft 5.6 added its own static redirect file. Friend hooks the very top of Craft's exception
handling, which runs *before* Craft reads that file — so without help, a fuzzy guess would quietly
outrank an explicit instruction.

With **Let config/redirects.php win** on, Friend reads the file through Craft's own redirect rule
objects, asks each one whether it matches the current request, and stands down if any does. Craft
then handles the redirect as normal. A URI that Friend stands down for is not logged.

Switch it off if you want Friend's answer to win instead.

## Patterns

**Never touch**, and a rule's **Only URIs matching** and **Except** fields, all take the same
kind of pattern:

- A **glob**. `*` matches any run of characters — slashes included — and `?` matches one. The
  whole URI has to match, case-insensitively, without a leading slash: `blog/*`, `*/feed`,
  `old-site/*.html`.
- **`re:`** followed by a regular expression. Unanchored and case-insensitive, so `re:^wp-` means
  "starts with `wp-`" and `re:login` means "contains `login`".

URIs are compared in a normal form: no domain, no query string, no leading or trailing slash,
repeated slashes collapsed.

## The config file

Any setting can be fixed in `config/friend.php`, using the config keys above. Values in the file
override whatever is saved in the settings screen.

```php
<?php

return [
    'redirectStatusCode' => 302,
    'cacheDuration' => 3600,
    'ignoredPatterns' => [
        'wp-*',
        '*/wp-*',
        'xmlrpc.php',
        '.*',
        '.*/*',
        'cpresources/*',
        'api/*',
    ],
    'extraStopWords' => ['acme'],
    'logRetentionDays' => 90,
];
```

The list settings are plain arrays of strings in the file. Extensions are lowercased with any
leading dot removed, and stop words are lowercased.

Rules and pins are not settings. They live in the database, not in project config, so they are
edited per environment in the control panel.

## Permissions

| Permission | What it allows |
|---|---|
| **View the 404 log** | The 404 log and the tester. Read-only: clearing the log needs **Create and edit rules** |
| ↳ **Create and edit pins** | The Pins screen, and the **Pin** button on the log |
| ↳ **Create and edit rules** | The Rules screen, and clearing the 404 log |

The two editing permissions are nested under **View the 404 log**. A user also needs Craft's own
**Access Friend** permission to see the section at all. The settings screen is for admins only.
