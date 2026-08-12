# Plugin Impact Analysis - measured culprit isolation

Called **Deep Analysis** until 0.13.1 (10 August 2026); the current name is **Plugin Impact
Analysis**, matching the "Measured impact" column it fills. The CLI type and the ability are
still `deep` / `run-deep-analysis`.

This is the plugin's differentiator. Inferred attribution says which plugin ran the queries;
Plugin Impact Analysis measures what each plugin actually costs by taking it out and
re-measuring.

### Virtual disabling for test requests only
**Since:** 0.4.0, 11 July 2026

Each suspect's true cost is measured by re-profiling with the plugin disabled **for the test
requests only**. No plugin is ever really deactivated, no activation or deactivation hooks fire,
and visitors always get the full site throughout.

### You choose what gets measured
**Since:** 0.16.0, 10 August 2026

Plugin Impact Analysis always starts from a chooser. Nothing is measured until you pick the
plugins yourself, nothing is preselected, and the chooser explains exactly what will happen and
what it will cost - *"7 plugins x 1 page = 9 measurements, about 20 seconds"* - before anything
runs. The duration estimate is derived from this site's own last five runs. Object-cache
measurements are opt-in from the chooser too, rather than automatic.

### Comprehensive sweep: every plugin, every page
**Since:** 0.9.0, 24 July 2026

Measures every eligible plugin on every profiled page, each cell proven by re-measuring with
that plugin virtually disabled. The result is a complete per-plugin cost map rather than a spot
check.

### Two-phase screening
**Since:** 0.9.1, 24 July 2026

Phase 1 screens every plugin in a single cache mode on its busiest pages (top attributed pages
plus the site's slowest page, 2 samples per cell). Phase 2 then automatically gives the full
treatment only to plugins that showed measurable impact. Innocent plugins never cost more than
their screening cells, which Dave measured as typically a 20-30x reduction in requests.

### Per-page impact, not one meaningless total
**Since:** 0.13.0, 10 August 2026

Measured impact reads *"adds 120ms typically, up to 340ms on wc-checkout"*, replacing a total
that summed every page together - a figure no visitor ever experienced, and one that grew simply
by measuring more pages. Impact is kept per page, so re-measuring one plugin on one page updates
that page and leaves every other page's result standing.

### Point it at any URL
**Since:** 0.14.0, 10 August 2026

Plugin Impact Analysis can be run against any URL on the site, including a page no analysis has
ever profiled, because it measures that page's own baseline as it goes. "Measure plugin impact
on this page" sits on the page panel: tick the plugins, read the cost estimate, and the verdicts
appear in the same panel when it finishes. Page-scoped impact defaults to the plugins that
page's own attribution blames, each tickable, with "select every eligible plugin" for the full
set.

The Pages tab lists the newest measurement of each page, whether it came from a full analysis or
from analysing that page on its own, with the date it was taken. The **site score still comes
only from full analyses**.

### Dependency groups: plugins measured together
**Since:** 0.15.0, 10 August 2026

Plugin Impact Analysis reads each plugin's own code to find which plugins cannot run without
another one, and measures those together in one go, so nothing is ever left running without
something it depends on. Your SEO plugin, page builder or anything else another plugin depends
on can now be measured **at all** - these used to be skipped entirely to keep them safe. A
verdict measured this way says so: *"measured together with seo-by-rank-math-pro, which cannot
run without it"*. The plugin picker tells you which plugins will be measured together before you
start.

<!-- internal -->
Three sources, and the third is the interesting one: core's `Requires Plugins` header (usually
absent - on the site this was written against, not one of nine active plugins declared
anything), the rules snapshot's fragile list, and a scan of the plugins' own main files for
dependency literals. The question it answers is narrower than "does A depend on B": it is "will
A do something drastic if B is not there". Main files are scanned to 1MB
(`SSPA_Dependency_Map::SCAN_BYTES`).

### Reaction guards: a measurement cannot change your site
**Since:** 0.16.0, 10 August 2026 (reporting 0.17.4)

If a plugin reacts to another being excluded during a measurement, its activation and
deactivation routines are silenced before they can run, and destructive database statements
(`DROP`, `TRUNCATE`, `ALTER ... DROP`, whole-table `DELETE`) are refused for that request. This
was built against a real deactivation routine that drops database indexes.

Every caught reaction becomes a finding, is included when you share results, and the pair is
measured together from the next run on - so a reaction can happen at most once per site. Since
0.17.4 you are told about it directly: an admin notice plus its own Overview block naming the
plugin that reacted and the plugin it reacted to.

Because the database guard lives in this plugin's own `db.php` shim, Plugin Impact Analysis
requires that drop-in during the run; sites where another plugin owns the drop-in can use the
existing temporary swap option.

<!-- internal -->
0.14.0 fixed the case this guard exists for: a plugin that switches itself off when a dependency
is missing was staying off after a measurement. That was a real defect where a measurement
changed which plugins the site ran. Worth stating on a sales page as "a measurement can never
change which plugins your site runs" - it is now true and enforced, but do not claim it for
versions before 0.16.0.

### Object cache modes
**Since:** 0.9.0, 24 July 2026 (opt-in since 0.16.0)

With a persistent object cache (Redis or Memcached) and the SSPA `db.php` shim present, each
plugin/page cell can be measured three ways: object cache disabled, priming (the first
cache-enabled request) and normal warmed cache. `normal` is the headline number. This shows who
depends on your cache and who ignores it.

### Cache impact analysis
**Since:** 0.5.0, 11 July 2026

Profiles the slowest pages with the persistent object cache on versus off, per request via the
`db.php` shim so visitors are unaffected. A site-wide `object-cache.php` swap is available as an
explicit fallback. Cache-blind plugins (identical queries either way) are named; cache-friendly
plugins get credit. The off-mode is verified from the captures before any conclusion is drawn.

### Theme isolation
**Since:** 0.4.0, 11 July 2026

Measures the theme's true cost by swapping to a stock theme for test requests only.

### Noise gate
**Since:** 0.4.0, 11 July 2026

Every isolated delta must beat `max(3x sample spread, 30ms)` or it is honestly reported as "no
measurable impact". No invented findings on a jittery host.

### Plugins that make your site faster are reported as such
**Since:** 0.8.0, 24 July 2026

The noise gate works on the absolute value of the delta, so a plugin that saves time shows a
green "saves Xms" measured impact rather than being written off as no impact.

### Dependency awareness and crash safety
**Since:** 0.4.0, 11 July 2026

Fragile plugins such as security plugins are never excluded. A fatal response is treated as
dependency evidence, and the search re-splits and continues. Isolation payloads are verified per
request via a response canary and cleaned up on finish, cancel or failure.

Since 0.9.6, such pages are reported as **discovered hard dependencies**: the run summary names
the plugin and page that cannot run without it, explains that only the analysis's own test
requests saw the error, and that the plugin was never actually deactivated. Measurement was
never affected - failed cells were already excluded from every impact number.

### Floating run monitor
**Since:** 0.9.0, 24 July 2026 (phase display 0.9.1, per-measurement list 0.12.0)

A minimisable panel showing which plugin is being tested, on which page, in which cache mode,
with a progress bar, elapsed time and estimated time remaining. It survives page reloads and
resumes automatically. Since 0.12.0 it opens in the middle of the screen and lists every
measurement as it is taken - *"home with super-speedy-search disabled, object cache off"* -
rather than showing only a progress bar, and names the phase it is in beside the measurement
count so a total that grows part-way through makes sense.

### Long runs are safe
**Since:** 0.9.0, 24 July 2026

Staleness is judged by progress rather than age: a wedged run is re-kicked after 30 minutes and
only failed after 3 hours without progress, so an hours-long sweep is never killed mid-flight.

### Quick spot-check on plugin activation
**Since:** 0.4.0, 11 July 2026

Prompts for a spot-check whenever you activate or deactivate a plugin, building the site's own
before/after cost ledger over time. Since 0.10.10 the notice clears once you take it up, and the
check stops once its results are in rather than re-running on every page load.

<!-- internal -->
Removed, do not describe as current: the adaptive bisection planner was retired in 0.9.0 and is
verified absent from the code. Any older copy describing "binary search across plugins" or "a
typical 8-plugin search takes about 12 measurements" is stale.
