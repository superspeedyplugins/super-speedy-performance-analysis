=== Super Speedy Performance Analysis ===
Contributors: dhilditch
Donate link: https://superspeedy.org/
Tags: speed, performance, profiling, query monitor, analysis
Requires at least: 6.2
Tested up to: 6.9
Stable tag: 0.2.0
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
