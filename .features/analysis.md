# Analysis, findings and the admin UI

### Plain-English findings from the captures
**Since:** 0.3.0, 11 July 2026

Heuristics turn raw profiles into findings, each naming the responsible plugin or theme with
evidence and a recommendation. Coverage includes slow queries with shape classification
(postmeta scans, `SQL_CALC_FOUND_ROWS`, `ORDER BY rand()`, leading-wildcard `LIKE`, nested
taxonomy joins), large result sets, queries in loops (N+1), byte-identical duplicate queries,
blocking HTTP calls during render, autoloaded-options bloat, environment red flags (old PHP,
missing object cache on a large database), overlapping plugins and security-blocked pages.

### Site score and Top 5 insights
**Since:** 0.3.0, 11 July 2026

An Overview tab carrying a site score and the five insights that matter most, each with the
component responsible and what to do about it.

### Six admin tabs
**Since:** 0.3.0, 11 July 2026 (Share added 0.6.0, Tools added 0.9.2)

Overview, Pages, Plugins, History, Tools and Share. Pages drills down to a per-plugin breakdown,
slowest queries with their callers, and HTTP calls. Plugins shows per-plugin SQL time, query
counts, rows fetched and slowest query across the whole analysis. Tools is covered in
`server-tools.md`.

### Site profile card
**Since:** 0.3.0, 11 July 2026

Content counts, database size and inferred sector (e-commerce, jobs board, publisher and so
on), recorded per run for benchmarking over time.

### History
**Since:** 0.3.0, 11 July 2026

Score, findings and median generation time across runs, so you can see whether the site is
getting slower as it grows.

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

### New finding: query with no usable index
**Since:** 0.9.2, 30 July 2026

Flags queries that scan a table or an entire index with no key chosen, above a row threshold.
These are the queries that are fast today because the tables are small and get linearly slower
as the site grows - a common cause of a site that was fine last year. Timing alone cannot see
them; a plan can.

### New finding: query reading far more rows than it returns
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
**Since:** 0.9.2, 30 July 2026

A switch on the Plugins tab for viewing the same run two ways: which component's code **ran**
(the default), and which component **asked** for the work. The second is the honest answer when
a plugin calls a WooCommerce function seventy times in a loop instead of once - that is the
plugin's fault, not WooCommerce's.

It is a view switch only. Nothing is re-profiled, no stored number changes, and measured impact
is unaffected either way because it comes from disabling the plugin and re-measuring rather than
from attribution.

<!-- internal -->
The toggle is explicitly exploratory. Dave's instruction: keep it if caller mode helps diagnose
real problems on a client site, remove it if it does not. Do not put it on a sales page yet.

### The N+1 finding names where the queries ran
**Since:** 0.9.2, 30 July 2026

"This plugin ran 70 queries" now becomes "70 of them inside WooCommerce", which is the
difference between a plugin being busy and a plugin looping over someone else's API. The finding
also always names the plugin that made the calls, even when the queries executed inside another
plugin's code - previously that cost was filed under the API's owner and the looping plugin was
invisible.

### The attribution trap, explained in the UI
**Since:** 0.8.0, 24 July 2026

The Plugins tab and knowledge base explain that a plugin replacing a slow feature (search,
filters) is credited with the queries it runs even when it is far faster than what it replaced.
The measured impact from deep analysis is the true verdict, not inferred attribution.
