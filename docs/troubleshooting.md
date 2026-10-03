---
title: Troubleshooting
slug: troubleshooting
order: 50
summary: URLs that still 404, redirects that land in the wrong place, and what to check first.
---

## Start with the tester

Almost every question about Friend is answered by **Friend → Tester** or
`php craft friend/match/test <uri>`. It shows which guard stopped a URI, what each rule said and
why, and every candidate's score broken down into slug, title and path. Run it before changing
anything.

## A URL still 404s

Work down this list:

1. **Is Look for friend on?** Check **Friend → Settings**, and `config/friend.php` if you have one —
   the file wins over the screen.
2. **Is a guard stopping it?** The homepage, `POST` requests, control panel, action and preview
   requests are never touched. Nor is anything matching **Never touch** or ending in an
   **Extension that stays 404** — including `.php`, `.asp`, `.aspx` and `.jsp` by default. The
   tester says when an ignored pattern or extension is the reason.
3. **Does `config/redirects.php` match it?** Then Friend stands down on purpose and Craft handles
   it.
4. **Is any rule enabled for this site?** The tester says "No rules are enabled for this site" if
   not.
5. **Did every rule decline?** A `below-threshold` verdict shows the best score against the
   threshold. Lower the threshold, or adjust the weights — see [Rules](rules#scoring).
6. **Did a rule further up decide first?** A **Do nothing** rule stops everything below it.
7. **Is the right page eligible at all?** Check the rule's element type, sections, entry types,
   **Only under** prefix and **Live elements only**. A `no-candidates` verdict usually means the
   page you expected is outside the rule's sources.

## It redirected somewhere wrong

Open the URL in the tester and read the candidate table. The usual causes:

- **The threshold is too low.** Raise it until the wrong answer no longer clears it.
- **A shared word is doing all the work.** A section name or brand in every slug makes everything
  look similar. Add it to **Words to ignore**.
- **Title weight is too high** for a site with generic titles. Lower it, or raise slug.
- **The rule is too broad.** Narrow it with **Only URIs matching**, sections or **Only under**.

For a single URL the scoring keeps getting wrong, don't make the rules worse for every other URL —
add a [pin](usage#pins) with the right answer.

## I fixed the rule, but the browser still goes to the old page

If the redirect was a 301 or 308, your browser cached it. Friend has already stopped sending it;
the browser has not stopped asking. Test in a private window, or clear the browser cache. This is
why Friend defaults to 302.

## I added the page, but Friend still doesn't find it

Friend caches each decision for **Cache decisions for** seconds — an hour by default — and saving an
entry does not clear that cache. Either wait, save any rule or pin (which clears every cached
decision), or run `php craft clear-caches/data`.

The tester bypasses the cache, so it will already show the new answer.

## Missing images and stylesheets are not redirected

By design. A missing image or stylesheet is a broken reference, not a lost visitor, and redirecting
one to an HTML page turns a visible 404 into an invisible wrong-content-type bug. If you really
want an extension handled, remove it from **Extensions that stay 404**.

## The search index method finds nothing

**Search index** uses Craft's own search index. If it is stale — after an import, say — rebuild it:

```sh
php craft resave/entries --update-search-index
```

The other methods do not depend on it.

## The 404 log is empty

- **Record misses** is off.
- Every 404 so far has been stopped by a guard or handled by `config/redirects.php`, neither of
  which is logged.
- The rows were pruned. Check **Keep for** and **Row cap**.

## The log is growing faster than I'd like

A scanned site hits **Row cap** long before anything ages out — which is what the cap is for. Lower
it, shorten **Keep for**, or add the scanner's paths to **Never touch** so they are neither matched
nor logged. Pruning runs during Craft's garbage collection; `php craft friend/log/prune` runs it now.

## Someone can't see Rules or Pins

Rules need **Create and edit rules**, pins need **Create and edit pins**, and both sit under **View
the 404 log**. Settings are for admins only. See [Permissions](configuration#permissions).

## Getting help

Open an issue at [github.com/justinholtweb/craft-friend](https://github.com/justinholtweb/craft-friend/issues),
or email [justin@justinholt.com](mailto:justin@justinholt.com), with the output of
`php craft friend/match/test <uri>` for the URL in question and `php craft friend/match/rules`.
