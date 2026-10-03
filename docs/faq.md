---
title: FAQ
slug: faq
order: 60
summary: Common questions about redirecting 404s to similar entries in Craft CMS.
---

## Is Friend free?

Yes. There are no editions, no Pro tier and no licence key. It is licensed under the Craft License
— see `LICENSE.md`.

## Which Craft and PHP versions are supported?

Craft CMS 5.3+ and PHP 8.2+.

## Will it start redirecting as soon as I install it?

Yes. The **Similar entries** rule is on by default, with a threshold of 55 and 302 redirects. If you
would rather look first, disable that rule or switch **Look for friend** off, and use the tester
until you trust it. See [Installation](installation#what-you-get-out-of-the-box).

## How is this different from the WordPress "404 Auto Redirect to Similar Post" plugin?

It covers the same ground. In WordPress "similar post" is one search across one post table; in
Craft you need to say *which* elements are eligible, *when*, and *how sure* the match has to be.
So Friend has ordered rules, a threshold per rule, a score breakdown you can read, pins for exact
answers, and a log of every guess. It also defaults to 302, not 301.

## Why 302 and not 301?

A 301 is cached by browsers and proxies, often for far longer than anyone intends. A guessed 301
that guesses wrong leaves a visitor unable to reach that URL again even after you fix the rule. Move
a rule to 301 once the log shows it getting the answer right. See
[Configuration](configuration#why-302-by-default).

## Does it replace `config/redirects.php` or a redirect manager?

No. Explicit redirects are for URLs you know about; Friend is for the ones you don't. By default
Friend stands down whenever `config/redirects.php` already matches the request. For a single URL you
know the answer to, a [pin](usage#pins) does the same job inside Friend.

## Will it redirect to entries in any section?

The default rule considers every live entry with a URL. Narrow a rule with **Sections**, **Entry
types** or **Only under**, or point it at categories or any other element type that has URLs. See
[Rules](rules#where-it-may-point).

## Can it send people to the parent page instead?

Yes — enable the **Nearest surviving page** rule that ships switched off, or tick **Nearest ancestor
page** on any rule. `/services/seo-audits` goes to `/services`.

## Can I show "did you mean" links instead of redirecting?

Yes. Set a rule's action to **Stay 404, offer suggestions** and use
`craft.friend.suggestions()` in your 404 template. See [Usage](usage#twig).

## How do I stop it touching part of my site?

Add the path to **Never touch** in the settings, or put a rule with **Do nothing, and stop here**
at the top of the rule list. The setting also keeps those URIs out of the log; the rule does not.

## Will it redirect missing images or files?

No. Missing files with an asset extension — images, stylesheets, scripts, fonts, media, archives —
always stay 404. The list is editable.

## My old site used `.php` or `.aspx` URLs. Will Friend help?

Not with the default settings: `php`, `asp`, `aspx` and `jsp` are on the ignored extension list,
because scanners request them constantly. Remove the ones your old site used from **Extensions that
stay 404**. The extension is stripped before matching, so `/about-us.php` is compared as `about-us`.
`.html` is not on the list and works as-is.

## Does it slow down my site?

Only 404s are affected, and each dead URL pays for its search once: the decision is cached for an
hour by default. Rule candidates are scored without loading elements, and the log is pruned during
garbage collection rather than on a visitor's request.

## Does it work on multi-site installs?

Yes. Candidates are always looked for in the site that 404'd. Rules can be limited to some sites,
pins can be for one site or all of them, the log records which site each miss came from, and the tester takes a site.

## Can a redirect send someone round in a loop?

No. A candidate at the URI that just 404'd is dropped, and a pin that points back at its own URI is
ignored.

## What happens to the data when I uninstall?

Rules, pins and the log are dropped with their tables.
