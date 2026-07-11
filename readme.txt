=== Super Speedy Performance Analysis ===
Contributors: dhilditch
Donate link: https://superspeedy.org/
Tags: speed, performance, profiling, query monitor, analysis
Requires at least: 6.2
Tested up to: 6.9
Stable tag: 0.4.0
Requires PHP: 7.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Free performance analysis for WordPress and WooCommerce. Finds which plugins are costing you SQL time, RAM and page speed, with evidence.

== Description ==

Super Speedy Performance Analysis diagnoses your site's performance the way an expert would:

* Profiles your key pages (home, shop, archives, search, checkout, wp-admin lists and editors) with a lean built-in profiler - no Query Monitor install required, and zero overhead on normal traffic.
* Attributes SQL time, returned row counts, RAM and query counts to individual plugins and your theme.
* Presents plain-English insights: the slowest queries and who ran them, queries fetching hundreds of rows (the usual RAM culprits), plugins running queries in loops, blocking HTTP calls and more.
* Deep analysis isolates the real culprits by virtually disabling plugins for test requests only - your live visitors are never affected - and measures each suspect's true cost.
* Optionally share anonymised results with the community at superspeedy.org to help build an open database of plugin performance. You see the exact payload before anything is sent.

This plugin is free and open source, developed in the open at https://github.com/superspeedyplugins/super-speedy-performance-analysis - issues and contributions welcome.

== Installation ==

1. Upload the plugin to /wp-content/plugins/ or install from a GitHub release zip.
2. Activate it through the Plugins menu.
3. Go to Super Speedy -> Performance Analysis and run your first analysis.

== Changelog ==

= 0.4.0 (11th July 2026) =
* Phase 3 Deep Analysis (culprit isolation): measures each suspect plugin's true cost by re-profiling its worst page with the plugin disabled FOR THE TEST REQUESTS ONLY. No plugin is ever really deactivated, no activation/deactivation hooks fire, and visitors always get the full site.
* Bisection: the slowest page is binary-searched across all eligible plugins to find costs that per-query attribution cannot see (the coupon-plugin class of offender). Multiple culprits are found; a typical 8-plugin search takes about 12 measurements.
* Dependency awareness: plugins required by others (Requires Plugins header) and fragile plugins (security etc) are never excluded during bisection; a fatal response is treated as dependency evidence and the search re-splits and continues.
* Noise gate: every isolated delta must beat max(3x sample spread, 30ms) or it is honestly reported as "no measurable impact" - no fake findings on jittery hosts.
* Theme isolation: your theme's true cost measured by swapping to a stock theme for test requests only.
* Plugins tab now shows measured impact (+ms on worst page) with a green "measured" badge, plus a per-row "Measure" button to isolation-test any single plugin.
* Quick spot-check prompt whenever you activate or deactivate a plugin - build your site's own before/after cost ledger over time.
* Crash safety: isolation payloads are verified per-request via a response canary, cleaned up on finish/cancel/failure, and deep runs store their measurement profiles for inspection.

= 0.3.0 (11th July 2026) =
* Phase 2 analysis engine: your profiles now become plain-English findings. Heuristics cover slow queries (with shape classification: postmeta scans, SQL_CALC_FOUND_ROWS, ORDER BY rand(), leading-wildcard LIKE, nested taxonomy joins), large result sets (the usual RAM culprits), queries-in-loops (N+1), byte-identical duplicate queries, blocking HTTP calls during page render, autoloaded-options bloat, environment red flags (old PHP, missing object cache on large databases), overlapping plugins and security-blocked pages.
* Site score plus Top 5 insights on the Overview tab, each naming the responsible plugin or theme with evidence and a recommendation.
* Site profile card: content counts, database size, sector inference (e-commerce, jobs board, publisher etc) - recorded per run for future benchmarking.
* Pages tab drill-down: click any page for its per-plugin breakdown, slowest queries with callers, and HTTP calls.
* Plugins tab: per-plugin SQL time, query counts, rows fetched and slowest query across the whole analysis (inferred attribution; measured isolation testing comes with Deep Analysis).
* History tab: score, findings and median generation time across runs, so you can see whether your site is getting slower as it grows.
* Stored-data meter with a manual "delete detailed data older than the last 5 runs" button - nothing is ever pruned automatically.
* Recommendation texts, thresholds, plugin categories and sector signatures ship as a bundled rules file, ready to be community-updated via superspeedy.org in a later phase.

= 0.2.0 (11th July 2026) =
* Phase 1 capture engine: profiles your key pages (front end, WooCommerce and wp-admin) via signed loopback requests and stores per-page metrics - generation time, SQL time, query counts, returned rows, HTTP API time, peak RAM.
* Lean built-in profiler: a conditional db.php drop-in and mu-plugin loader that are completely inert for normal traffic and only activate for token-signed profiling requests. No Query Monitor required.
* Per-query row counts, errors and plugin/theme attribution via backtraces; full SQL kept for the slowest/biggest queries, privacy-safe fingerprints for the rest.
* Detects foreign db.php drop-ins (Query Monitor, LudicrousDB, W3TC): rides Query Monitor's, runs degraded alongside others, or (opt-in, with a clear warning) temporarily swaps them out for the run with crash-safe restoration.
* Detects security plugins blocking loopbacks and names what to whitelist; the run continues.
* Sampling discipline: warmup plus 3 measured samples per page, medians reported, cache-served responses discarded via a response canary.
* Run Analysis button with live progress on the Performance Analysis page; results in the Pages tab.
* Docker-based test suite (see .tests/README.md).

= 0.1.0 (11th July 2026) =
* Initial scaffold: plugin skeleton, database schema (runs, profiles, component stats, findings, plugin impacts, site metrics), admin page shell with client-side tabs, GitHub-based update checker.
