# Changelog

All notable changes to Friend are documented here.

## Unreleased

### Added

- **Pin import and export (CSV).** **Pins → Import CSV** and `php craft friend/pins/import` bring
  an old site's redirect map in as pins, so a migration's known redirects are answered before any
  rule guesses. Columns are read by header — `from`/`to`, or the names Retour, Redirect Manager and
  most spreadsheets use, plus optional `status`, `site`, `enabled` and `pointsAt` — and a file with
  no header is read as `from,to,status`. Dry run, overwrite-existing, and linking a destination to
  the entry at that URI (so the pin follows it) are all switches.
- Every imported row is validated and refused rows are reported by line number. Destinations must
  be on this site — a path, or a full URL on one of the install's own hosts, stored as a path — so
  an imported file can't turn the site into an open redirect; only an admin can allow other hosts.
  Regex rows are refused (they are rules, not pins). Files are capped at 2 MB and 10,000 rows.
- **Pins → Export CSV** and `php craft friend/pins/export` write the importer's own columns, so an
  export imports back as the same pins. Cells a spreadsheet would run as a formula are defused.
- 51 integration checks for import/export and 18 HTTP checks for the upload and export actions'
  permission, POST and CSRF requirements.

## 5.0.0 — 2026-08-18

Initial release. Versioned 5.x to match the Craft major it targets, as the rest of this plugin
family is.

### Added

- **Rule set** — an ordered list of rules, each saying when it applies (URI pattern, exclusions,
  path depth, sites), what it may point at (element type, sections, entry types, category groups,
  URI prefix, live-only), how sure it has to be (retrieval methods, weights, threshold) and what it
  does (redirect, offer suggestions, or deliberately nothing).
- **Four retrieval methods** — exact slug, word overlap, Craft's search index, and nearest
  surviving ancestor page.
- **Weighted scoring** across slug, title and path affinity, with a per-rule threshold that is the
  whole safety mechanism. Ancestor candidates are scored structurally on how much of the path
  survived.
- **Pins** — stored exact answers for a single URI, consulted before any rule, creatable by hand or
  with one press on a row in the 404 log.
- **404 log** — aggregated per site and URI with a hit counter, recording the chosen target, the
  score, the deciding rule, and whether the visitor was moved. Pruned by age and by row cap during
  garbage collection.
- **Tester** — a control panel screen and a console command that resolve a URL and print the whole
  decision: every rule considered, why each was skipped or declined, and the ranked candidates with
  their score breakdown.
- **`craft.friend`** — `suggestions()`, `best()`, `outcome()` and `missedUri()` for a 404 template
  that would rather offer a "did you mean" list than move anybody.
- **Guards** — site GET requests only, never the homepage, never an ignored pattern or file
  extension, and never a redirect back to the URI that just missed.
- **Deference to `config/redirects.php`** — Friend runs before Craft consults that file, so it
  checks the file itself and stands down when a rule there already covers the URI.
- **Console commands** — `friend/match/test`, `friend/match/rules`, `friend/log`,
  `friend/log/prune`, `friend/log/clear`.
- Two rules seeded on install: a conservative "Similar entries" that works, and a "Nearest
  surviving page" switched off to read.
- 125 integration checks.

### Fixed

- The Rules screen rendered an empty table. It builds its index with `Craft.VueAdminTable` without registering Craft's `AdminTableAsset` bundle, so the constructor was undefined, the JavaScript threw, and the table area was left blank with no rows and no empty-state message.

