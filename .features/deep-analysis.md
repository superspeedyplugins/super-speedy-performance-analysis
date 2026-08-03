# Deep analysis - measured culprit isolation

This is the plugin's differentiator. Inferred attribution says which plugin ran the queries;
deep analysis measures what each plugin actually costs by taking it out and re-measuring.

### Virtual disabling for test requests only
**Since:** 0.4.0, 11 July 2026

Each suspect's true cost is measured by re-profiling with the plugin disabled **for the test
requests only**. No plugin is ever really deactivated, no activation or deactivation hooks
fire, and visitors always get the full site throughout.

### Comprehensive sweep: every plugin, every page
**Since:** 0.9.0, 24 July 2026

One button measures every eligible plugin on every profiled page, each cell proven by
re-measuring with that plugin virtually disabled. The result is a complete per-plugin cost map
rather than a spot check.

### Two-phase screening
**Since:** 0.9.1, 24 July 2026

Phase 1 screens every plugin in a single cache mode on its busiest pages (top attributed pages
plus the site's slowest page, 2 samples per cell). Phase 2 then automatically gives the full
treatment only to plugins that showed measurable impact. Innocent plugins never cost more than
their screening cells, which Dave measured as typically a 20-30x reduction in requests.

### Object cache modes
**Since:** 0.9.0, 24 July 2026

With a persistent object cache (Redis or Memcached) and the SSPA `db.php` shim present, each
plugin/page cell is measured three ways: object cache disabled, priming (the first
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

Plugins required by others (via the `Requires Plugins` header) and fragile plugins such as
security plugins are never excluded. A fatal response is treated as dependency evidence, and
the search re-splits and continues. Isolation payloads are verified per request via a response
canary and cleaned up on finish, cancel or failure.

### Floating run monitor
**Since:** 0.9.0, 24 July 2026 (phase display 0.9.1)

A minimisable popover on every tab showing which plugin is being tested, on which page, in
which cache mode, with a progress bar, elapsed time and estimated time remaining. It survives
page reloads and resumes automatically.

### Long runs are safe
**Since:** 0.9.0, 24 July 2026

Staleness is judged by progress rather than age: a wedged run is re-kicked after 30 minutes and
only failed after 3 hours without progress, so an hours-long sweep is never killed mid-flight.

### Quick spot-check on plugin activation
**Since:** 0.4.0, 11 July 2026

Prompts for a spot-check whenever you activate or deactivate a plugin, building the site's own
before/after cost ledger over time.

<!-- internal -->
Removed, do not describe as current: the adaptive bisection planner was retired in 0.9.0 and
is verified absent from the code. Any older copy describing "binary search across plugins" or
"a typical 8-plugin search takes about 12 measurements" is stale.
