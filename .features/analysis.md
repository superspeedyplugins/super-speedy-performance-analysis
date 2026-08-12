# Analysis, findings and the admin UI

### Plain-English findings from the captures
**Since:** 0.3.0, 11 July 2026

Heuristics turn raw profiles into findings, each naming the responsible plugin or theme with
evidence and a recommendation. Coverage includes slow queries with shape classification
(postmeta scans, `SQL_CALC_FOUND_ROWS`, `ORDER BY rand()`, leading-wildcard `LIKE`, nested
taxonomy joins), large result sets, queries in loops (N+1), byte-identical duplicate queries,
blocking HTTP calls during render, autoloaded-options bloat, environment red flags (old PHP,
missing object cache on a large database), overlapping plugins and security-blocked pages.

### Site score, shown out of 100
**Since:** 0.3.0, 11 July 2026 (shown as `/100` since 0.17.1, 11 August 2026)

An Overview tab carrying a site score and the five insights that matter most, each with the
component responsible and what to do about it. The score is written `10/100` on the Overview
tile, the History tab and in `wp sspa` output, because a bare `10` was readable as ten out of
ten.

### Seven admin tabs
**Since:** 0.3.0, 11 July 2026 (Share 0.6.0, Tools 0.9.2, Settings 0.16.1)

Overview, Pages, Plugins, History, Tools, Share and Settings. Pages drills down to a per-plugin
breakdown, slowest queries with their callers, and HTTP calls. Plugins shows per-plugin SQL
time, query counts, rows fetched and slowest query across the whole analysis. Tools is covered
in `server-tools.md`.

### Nothing reloads the page
**Since:** 0.12.0, 9 August 2026

Queueing a run, pruning detailed data, replacing a drop-in, retrying or pausing a submission,
cancelling a run, finishing an analysis, switching attribution mode and re-checking the Tools
tab all update in place and keep you on the tab you were on. The Sharing settings and Choose
analyses links switch tab in place rather than reloading you back onto Overview.

### One page panel, everywhere
**Since:** 0.9.8, 4 August 2026 (unified with the Pages tab in 0.14.0, 10 August 2026)

"Analyse this page" in the admin toolbar profiles the exact URL you are looking at - front end
or wp-admin - while you watch, then shows the results in a branded full-width panel. Click it
again later on the same page and the stored result reopens instantly, with a Re-run button.
Since 0.14.0 clicking a page on the Pages tab opens the **same** panel, carrying everything
either view used to show on its own.

The panel shows: generation time, SQL, HTTP and RAM; where the PHP time went, per plugin and per
phase; per-plugin attribution in **both** modes; the outbound HTTP calls; what `EXPLAIN` says
about each slow query; the object cache hit rate for the page; the function breakdown where
excimer is installed; and what has been measured by disabling plugins on it.

Admins only, same-site URLs only, and the profiling requests are the same signed, cache-busted,
never-cached requests the full analysis uses. One-page checks are stored separately from full
analyses, so they never replace site-wide results on Overview and Pages.

<!-- internal -->
0.14.0 removed the "Open in Performance Analysis" link - the panel IS the full view now. Any
screenshot or doc showing that link is pre-0.14.0.

### The panel is branded and screenshot-ready
**Since:** 0.9.13, 4 August 2026 (wordmark 0.10.1, version pill 0.10.3)

Purple accents, rounded corners, the Super Speedy Plugins wordmark in the header with a version
pill, and a "Powered by Super Speedy Performance Analysis - free from superspeedyplugins.com"
footer, so screenshots of results say where they came from and which version produced the
numbers. Escape closes the panel on the front end and in wp-admin.

### Click-to-copy slow queries
**Since:** 0.9.13, 4 August 2026

The row shows the start of the query; clicking it copies the FULL query to the clipboard and
confirms with a small "Copied" toast.

### Provenance bar: cached or fresh
**Since:** 0.9.12, 4 August 2026 (relative age 0.9.15)

A "Cached result" / "Fresh result" badge with the timestamp sits at the TOP of the panel next to
the Re-run button. The age is relative and computed server-side ("5m ago", "just now"), so there
is no server-timezone reconciliation to do. The old cryptic "anon" label now reads "profiled as
a logged-out visitor" (or "profiled as admin" for wp-admin URLs) - front-end pages are
deliberately measured the way a real visitor gets them.

### Site profile card
**Since:** 0.3.0, 11 July 2026

Content counts, database size and inferred sector (e-commerce, jobs board, publisher and so on),
recorded per run for benchmarking over time. Reworked in 0.18.0 into the site-characteristics
classifier described in `community.md`.

### History
**Since:** 0.3.0, 11 July 2026

Score, findings and median generation time across runs, so you can see whether the site is
getting slower as it grows. Since 0.12.0 it also lists the components and versions each analysis
measured, and carries a per-analysis "Share this" control.

### Component versions are recorded and warned about
**Since:** 0.12.0, 9 August 2026

A Version column on the Plugins tab shows the version each component was at when the analysis
measured it. Plugin Impact Analysis records the version it measured, and the Plugins tab says
when a measured verdict was taken against a version you no longer run. The per-page breakdown
names the version its measurements came from, and measured impact says when it was measured -
warning when that measurement predates the analysis shown beside it.

### Option access tracking and the autoloaded options panel
**Since:** 0.12.0, 9 August 2026

Every analysis records which options each page actually read, so the Overview can name the
autoloaded options that **no page used** and the size they cost on every request. The
Autoloaded options panel comes with copy-and-paste SQL to switch autoload off for options
nothing read, and on for options read on nearly every page.

<!-- internal -->
Built on WordPress 6.1's generic `pre_option` filter (design: `.docs/2026-08-09-option-access-tracking.md`,
whose header still says "design, nothing built" - it shipped in 0.12.0). Coverage is only as
good as the pages profiled: an option read solely by a page no analysis touches will be reported
as unused. Say "no profiled page read it", not "nothing reads it", in customer-facing copy.

### Stored-data meter with manual pruning
**Since:** 0.3.0, 11 July 2026

Shows how much detailed data is stored, with a manual "delete detailed data older than the last
5 runs" button. Nothing is ever pruned automatically.

### Query plan analysis (EXPLAIN)
**Since:** 0.9.2, 30 July 2026

Captured queries are run through MySQL's `EXPLAIN` after profiling, so a slow query is reported
with the reason it is slow - no usable index, a temporary table, a filesort - rather than only
the fact that it was slow. Needs nothing installed, no extra database permission, and works on
any host. Row counts from `EXPLAIN` are the optimiser's estimate, not a measurement, and the UI
says so on every line that shows one.

Never executes the query: `EXPLAIN ANALYZE` would, and is deliberately not used. `SELECT`
statements only, one statement only, and never on a stored fingerprint (its literals have been
replaced with `?`, so any plan would be fiction). It runs during analysis, never inside a
profiled request, so it cannot affect a measurement.

<!-- internal -->
Coverage limit worth knowing before writing copy: only queries whose FULL SQL was retained can
be explained, and retention is the slowest 20 distinct queries per page
(`SSPA_Capture::FULL_SQL_TOP_N`). Everything else is stored as a privacy-safe fingerprint and is
skipped. Capped at 200 distinct queries explained per run. So "every slow query gets a plan" is
right; "every query gets a plan" is not.

### Finding: query with no usable index
**Since:** 0.9.2, 30 July 2026

Flags queries that scan a table or an entire index with no key chosen, above a row threshold.
These are the queries that are fast today because the tables are small and get linearly slower
as the site grows - a common cause of a site that was fine last year. Timing alone cannot see
them; a plan can.

### Finding: query reading far more rows than it returns
**Since:** 0.9.2, 30 July 2026

Where the database allows it, MySQL's own counters are read for each query shape and compared
with what came back. A query reading 400,000 rows to hand back 12 is doing a hidden full scan,
and it is invisible to every other measurement the plugin takes: our own capture sees only what
was returned, and `EXPLAIN` only estimates. Default thresholds: 100x more rows read than
returned, and at least 1,000 rows read.

Requires `performance_schema` to be on and readable - see `.compatibility/`. Without it the
analysis runs exactly as before and this finding simply does not appear; nothing is estimated or
invented in its place.

### Code owner / Caller attribution switch
**Since:** 0.9.2, 30 July 2026 (in-place swap since 0.12.0)

A switch on the Plugins tab and the page panel for viewing the same run two ways: which
component's code **ran** (the default), and which component **asked** for the work. The second
is the honest answer when a plugin calls a WooCommerce function seventy times in a loop instead
of once - that is the plugin's fault, not WooCommerce's.

It is a view switch only. Nothing is re-profiled, no stored number changes, and measured impact
is unaffected either way because it comes from disabling the plugin and re-measuring rather than
from attribution.

<!-- internal -->
Still flagged exploratory in `.roadmap/planned.md`, but it has survived nine months of releases,
was carried into the unified page panel in 0.14.0 and made in-place in 0.12.0. That is a
de facto keep. Worth asking Dave to close the decision formally rather than leaving it open.

### The N+1 finding names where the queries ran
**Since:** 0.9.2, 30 July 2026

"This plugin ran 70 queries" now becomes "70 of them inside WooCommerce", which is the
difference between a plugin being busy and a plugin looping over someone else's API. The finding
also always names the plugin that made the calls, even when the queries executed inside another
plugin's code.

### The attribution trap, explained in the UI
**Since:** 0.8.0, 24 July 2026

The Plugins tab and knowledge base explain that a plugin replacing a slow feature (search,
filters) is credited with the queries it runs even when it is far faster than what it replaced.
The measured impact from Plugin Impact Analysis is the true verdict, not inferred attribution.

### Plugin reactions get their own Overview block
**Since:** 0.17.4, 12 August 2026

When a plugin reacts to another being excluded for measurement, it is reported in its own block
on the Overview rather than competing for a top-five insight slot it always lost, and reads as
plain English - *"Rank Math Pro tried to deactivate a plugin while Rank Math was excluded for
measurement"* - rather than as a rule name. An admin notice names the reacting plugin and the
excluded one, so you find out even if the analysis finished while you were elsewhere. See
`deep-analysis.md` for what is actually blocked.
