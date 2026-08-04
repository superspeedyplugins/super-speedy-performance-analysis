=== Super Speedy Performance Analysis ===
Contributors: dhilditch
Donate link: https://www.superspeedyplugins.com/
Tags: speed, performance, profiling, query monitor, analysis
Requires at least: 6.2
Tested up to: 6.9
Stable tag: 0.9.12
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

This plugin is free. Download it from https://www.superspeedyplugins.com/ - once installed it checks for its own updates from superspeedyplugins.com, so new versions appear on your Plugins screen like any other update.

== Installation ==

1. Upload the plugin to /wp-content/plugins/ or install the zip from https://www.superspeedyplugins.com/assets/plugins/super-speedy-performance-analysis.zip
2. Activate it through the Plugins menu.
3. Go to Super Speedy -> Performance Analysis and run your first analysis.

== Changelog ==

= 0.9.12 (4th August 2026) =
* The "Analyse this page" popover is now a full-width panel under the toolbar - no scrolling, and room for everything.
* FIXED: a long unbreakable callback name (namespaced class names have no spaces) could blow one column out sideways, squeezing the other column and pushing the millisecond values off the edge. Columns are now hard-capped at half the width and long names wrap.
* Whether you are looking at a stored result is now unmissable: a "Cached result" / "Fresh result" badge with the timestamp sits in a bar at the TOP of the panel, next to the Re-run button - no more scrolling to re-run.
* "Full detail" is renamed "Open in Performance Analysis" and moved to a quiet link on the right of that bar - it leads to the site-wide view (all pages side by side, per-plugin SQL aggregates, HTTP calls, duplicate queries, history), not to more detail about this one page.

= 0.9.11 (4th August 2026) =
* FIXED: the popover's Re-run and Full detail buttons no longer use the generic "button" class, which themes restyle unpredictably (some with !important) and which has no styling at all on the front end of most themes. They now carry the plugin's own class with self-contained styling, verified against a live WooCommerce theme.

= 0.9.10 (4th August 2026) =
* NEW: render breakdown. The "Template render + output" phase is no longer a single number: every wp_head/wp_footer/content-filter callback, every shortcode and every widget is individually timed and attributed to its plugin, with the remainder honestly labelled as the theme's own template code. Widgets are attributed to the plugin that provides them, not to WordPress core.
* The "Analyse this page" popover is now a two-column layout - roughly double the width, phases and stats on the left, per-plugin costs and the render breakdown on the right, slowest queries across the bottom - so results fit without scrolling.
* The same render breakdown appears in the Pages tab drill-down.

= 0.9.9 (4th August 2026) =
* FIXED: the "Where the PHP time went" phase table stopped at routing, so on a real page its rows summed to well under the generation time and the difference looked unmeasurable - when it was simply the template render. The table now accounts for the whole request: new "Post-init boot" and "Template render + output" rows close the gap, and the phases sum to the page's generation time.

= 0.9.8 (4th August 2026) =
* NEW: "Analyse this page" in the admin toolbar. On any page of your site - front end or wp-admin - click the button and the plugin profiles the exact URL you are looking at while you watch, then shows the results in a popover: generation time, SQL, HTTP and RAM, where the PHP time went (per plugin), and the slowest queries. Click it again later on the same page and the stored result reopens instantly, with a Re-run button.
* These one-page checks are stored separately from full analyses, so they never replace your site-wide results on the Overview and Pages tabs.
* Admins only, same-site URLs only, and the profiling requests are the same signed, cache-busted, never-cached requests the full analysis uses.

= 0.9.7 (3rd August 2026) =
* NEW: bootstrap decomposition - the answer to "every page is slow but no single plugin shows up". Many sites pay a flat PHP cost on every request (plugin loading plus init hooks) that is spread across dozens of plugins in slices too small for one-at-a-time exclusion to see. Every profiled page now records where that time went, with nothing to install: the request broken into phases (core, plugin file loading, plugin boot callbacks, theme setup, init, routing, render), the PHP cost of EVERY plugin individually (file load time plus its callbacks on the expensive hooks), and the slowest single hook callbacks by name.
* Find it in the Pages tab: click any page row - "Where the PHP time went" appears in the drill-down alongside the existing query and HTTP detail.
* The instrument only runs on the analysis's own signed requests, adds two clock reads per callback there, and touches nothing on normal traffic.

= 0.9.6 (3rd August 2026) =
* FIXED: during Deep Analysis, a page that fatally errors while a plugin is virtually excluded (for example the WooCommerce orders screen without WooCommerce Subscriptions, whose order types it needs) triggered WordPress's built-in fatal-error email, telling the site owner their site had a technical issue and blaming the plugin whose file threw. Profiling requests now set the same sandbox flag WordPress uses for its own loopback tests, so these controlled test failures no longer send recovery-mode emails or trigger recovery mode. Real visitor requests are unaffected and keep WordPress's full protection.
* Deep Analysis now reports such pages as discovered hard dependencies: the run summary names the plugin and page that cannot run without it, explains that only the analysis's own test requests saw the error, and that the plugin was never actually deactivated. Measurement was never affected - failed cells were already excluded from every impact number.
* To be clear about what Deep Analysis never does: it does not deactivate plugins. It excludes one plugin at a time for its own signed test requests only, and plugins that others declare as required (WooCommerce on this site, via the Requires Plugins header) are never candidates at all.

= 0.9.5 (3rd August 2026) =
* FIXED: a custom post type whose archive URL embeds a taxonomy placeholder (a knowledge base at /kb/%kb_category%/, for example) was profiled at the literal placeholder URL, which can never verify the signed profiling token, so the page silently produced no data. The placeholder is now filled in with the taxonomy's biggest term, and the page is skipped entirely if no term exists.

= 0.9.4 (3rd August 2026) =
* FIXED: pages served by a full-page cache or CDN were silently skipped. Every profiling request now carries a unique cache-busting query argument (signed into the token, so it cannot be stripped), guaranteeing the request reaches PHP instead of being answered from nginx, Varnish, LiteSpeed or a CDN cache - previously the home page, shop and product pages of any cached site produced no data at all.
* Profiled responses now send Cache-Control no-store, so a cache can never store one and serve it to a real visitor.
* Runs that still could not measure some pages now say so loudly: a warning on the Overview names how many pages went unmeasured, the Pages tab marks each one "not measured - cache served it", and the count is stored with the run instead of the score quietly ignoring them.
* FIXED: with Query Monitor's db.php in place, EVERY query was attributed to Query Monitor - its database class is the innermost frame of every query stack, and attribution stopped there. The database layer (Query Monitor's, LudicrousDB's, or any other wpdb subclass) is now skipped, so queries are attributed to the plugin that actually ran them.
* FIXED: with Query Monitor ACTIVE, anonymous (logged-out) page profiles captured no per-query data at all - Query Monitor only collects for logged-in viewers. The health check now warns about this instead of claiming full detail, and the temporary db.php swap is offered for Query Monitor too, not just for unknown drop-ins.
* NEW: detects an orphaned Query Monitor db.php (the plugin deactivated but its drop-in left behind, which blocks our capture layer) and offers a one-click replacement - the old file is renamed, never deleted.
* When no per-query capture was possible, SQL time and row totals are now stored as unknown rather than zero, so a blind measurement can no longer be mistaken for a page that spent no time in SQL.
* Cache-served responses are recognised from more signals (x-fastcgi-cache, the Age header and others), so they are classified as cached rather than reported as a generic canary failure.

= 0.9.3 (3rd August 2026) =
* Updated the shared Super Speedy settings module: AI agents can check your licence and install other Super Speedy plugins over MCP, now also exposed on AI Engine's MCP endpoint when AI Engine is active
* New Check frequency setting - cap licence, update and changelog checks to once a day or once a week, and see when the licence was last checked
* Licence and changelog checks fail fast when superspeedyplugins.com cannot be reached, instead of hanging the settings page
* The newest installed copy of the shared Super Speedy settings code now runs site-wide, whichever plugin loads first - previously the alphabetically-first plugin's often-older copy won

= 0.9.2 (30th July 2026) =
* NEW: MySQL query fingerprints. Where your database lets us read its performance_schema statistics, the analysis now reports how many rows MySQL ACTUALLY read to answer each query, rather than how many it returned or what the optimiser guessed. New finding for queries that read far more than they return - a query reading 400,000 rows to hand back 12 is doing a hidden full scan, and it is invisible to every other measurement. Needs one read-only GRANT, which the Tools tab writes out for you; without it, everything else still works.
* The N+1 finding now says where those queries actually ran, so "this plugin ran 70 queries" becomes "70 of them inside WooCommerce" - which is the difference between a plugin being busy and a plugin looping over someone else's API.
* Query call stacks are captured deeper (32 frames, was 14), and the Tools tab reports how often a stack was still cut short, so you can tell whether attribution is seeing everything it needs to.
* NEW Tools tab: reports what your server can do for deeper analysis, and for anything missing generates the exact commands for YOUR operating system, PHP version and init system - not generic documentation you have to translate. Every command has a copy button, and there is a ready-written message you can paste into a support ticket, because most sites cannot install a PHP extension themselves.
* The plugin never installs anything itself: it does not edit php.ini, run pecl, or restart anything. It shows you what to run, with the reason for each step.
* The Tools tab also generates the exact read-only GRANT statement your database user needs, with your real username filled in, and tells you plainly when something is detected but not yet used by the plugin.
* NEW: query plan analysis. Every distinct query whose full SQL was captured is now run through MySQL's EXPLAIN, so a slow query is reported with the REASON it is slow - no usable index, a temporary table, a filesort - instead of just the fact that it was slow. Needs nothing installed, no extra database permission, and works on any host.
* NEW finding: "query with no usable index". These are the queries that are fast today because your tables are small and get linearly slower as you grow - the single most common cause of a site that was fine last year. EXPLAIN is the only way to see them before they hurt.
* EXPLAIN never executes your queries (that would be EXPLAIN ANALYZE, which is deliberately not used), only ever runs on SELECTs, and runs after profiling rather than during it, so it cannot affect any measurement.
* FIXED: shared library misattribution. When two plugins bundle the same library (Freemius, Action Scheduler, Guzzle and anything else under a `vendor` directory), PHP loads only one copy, and whichever plugin happened to own that copy on disk was charged for every other plugin's use of it. Attribution now walks past the shared library to the plugin that actually called in, and reports the library separately: "WP All Import, work done in action-scheduler (in woocommerce)". A plugin using its OWN bundled library is still charged for it, and ordinary cross-plugin API calls (a plugin calling `wc_get_product()`) still resolve to the API's owner, not the caller.
* Attribution now records the full component chain for every query and HTTP call, so the same capture answers two different questions: which component's code RAN (the default), and which component ASKED for the work. The second is the honest answer when a plugin calls a WooCommerce function seventy times in a loop instead of once - that is the plugin's fault, not WooCommerce's.
* The "plugin ran N queries" finding (the N+1 detector) now always names the plugin that made the calls, even when the queries themselves ran inside WooCommerce or another plugin's code. Previously a plugin looping over another plugin's API was invisible: the cost was filed under the API's owner.
* NEW on the Plugins tab: a Code owner / Caller attribution switch, for exploring the same run both ways. It only changes how the run is presented - nothing is re-profiled and no stored number changes. Measured impact is unaffected either way, because it comes from disabling the plugin and re-measuring rather than from attribution.
* Attribution has always been a hypothesis and measured impact the experiment - Deep Analysis was never affected by this, because virtually disabling a plugin removes its calls into a shared library no matter whose folder that library sits in.
* Updates now come from superspeedyplugins.com instead of GitHub, so the Plugins screen offers new versions again while the source repo is still private. No licence key needed - the plugin stays free and the download is ungated.

= 0.9.1 (24th July 2026) =
* HOTFIX: Deep Analysis was unusably slow on plugin-heavy sites (a 40+ hour estimate was seen in the wild) because every plugin got every page in all three cache modes up front. It now runs in TWO PHASES. Phase 1 screens every plugin in a single cache mode on just its busiest pages (top attributed pages plus the site's slowest page, 2 samples per cell) - a fast first pass over everything. Phase 2 then automatically gives only the plugins that showed a measurable impact the full treatment: every remaining page, plus object-cache-disabled and cache-priming measurements. Innocent plugins never cost more than their screening cells - typically a 20-30x reduction in requests.
* The floating monitor shows which phase is running and the time remaining for the current phase.
* Cache-mode labelling simplified: `normal` (the cache in its natural, warmed state) is the headline number; `disabled` and `prime` are added for impacted plugins. The separate `warm` label is gone - it duplicated `normal`.

= 0.9.0 (24th July 2026) =
* Deep Analysis is now a comprehensive sweep: EVERY eligible plugin measured on EVERY profiled page (not just each suspect's worst page), each cell proven by re-measuring the page with the plugin virtually disabled for the test requests only. One button, walk away, come back to a complete per-plugin cost map.
* Object cache modes: with a persistent object cache (Redis/Memcached) and the SSPA `db.php` shim present, every plugin/page cell is measured three ways - object cache disabled, priming (first cache-enabled request) and warm cache - so you can see who depends on your cache and who ignores it.
* Floating run monitor: a minimisable popover visible on every tab shows exactly which plugin is being tested on which page in which cache mode, with a progress bar, elapsed time and estimated time remaining. It survives page reloads and resumes automatically; runs no longer hijack your screen with surprise refreshes.
* Per-page breakdown on the Plugins tab: measured impact now aggregates across all pages ("adds 320ms across 5 of 22 pages") with a click-through page-by-page, mode-by-mode grid. The per-plugin Measure button now measures that plugin on every page too.
* Long runs are safe: staleness is now judged by progress rather than age (a wedged run is re-kicked after 30 minutes and only failed after 3 hours without progress), so an hours-long sweep is never killed mid-flight. `wp sspa run --type=deep` allows 6 hours.
* The adaptive bisection planner is retired - the sweep measures every plugin on every page directly, which supersedes it.
* Measured impacts gained an `object_cache_mode` dimension throughout (database, report JSON, abilities, community payload); agents are told to headline the warm-cache numbers.

= 0.8.0 (24th July 2026) =
* FIXED: plugins that SPEED YOUR SITE UP are now reported correctly. Deep analysis measured them but reported "no measurable impact" (the noise gate only looked at positive deltas), and the Plugins tab rendered negative deltas garbled as `+-2,000ms`. Speed-up plugins now show a green `saves Xms` measured impact - the noise gate works on the delta's absolute value, so savings are first-class measured results.
* FIXED: runs started from the Plugins tab (the per-plugin Measure button) appeared to do nothing - the page never switched to the Overview tab where the progress bar lives. It now switches automatically.
* FIXED: after a deep or cache-impact run, the Overview showed site score 0 with no insights, and the Pages/Plugins tabs displayed the deep run's partial plugin-set-modified profiles instead of your last real analysis. All tabs now pin to the latest full analysis, and the Overview gained a "Latest deep analysis" summary card.
* FIXED: the quick spot-check (plugin toggle prompt) recorded itself as a full baseline run, hijacking every results view and deep-analysis suspect selection. Page-filtered runs are now typed as spot runs.
* FIXED: theme isolation results were measured but never displayed (stored under a synthetic slug no view matched). They now appear under your real theme slug on the Plugins tab.
* Accuracy: single-plugin measurements now re-measure the baseline seconds before the excluded measurement (instead of reusing one from minutes earlier), and the excluded plugin set gets its own warm-up request - server drift and cold caches no longer masquerade as plugin cost.
* Measured impacts now include the HTTP/API time delta alongside generation, SQL, query-count and RAM deltas, and the Plugins tab shows all of them per plugin.
* The Plugins tab and knowledge base now explain the attribution trap: a plugin that replaces a slow feature (search, filters) is credited with the queries it runs even when it is far faster than what it replaced - the measured impact is the true verdict.
* Agent API: the delta sign convention is now documented explicitly (positive = the plugin adds time, negative = it saves time) so AI assistants cannot misreport a saving as a slowdown.

= 0.7.2 (20th July 2026) =
* Updated super-speedy-settings to the latest version

= 0.7.1 (11th July 2026) =
* Hub client hardening, proven against a real second site: submissions and the rules feed now use ?rest_route= URLs, which work on every WordPress regardless of the hub's permalink or trailing-slash redirect setup (pretty /wp-json/ URLs 301 on some hosts, silently breaking POST submissions).
* Install secrets are now stored per hub URL - switching between a development hub and superspeedy.org no longer presents one hub's credentials to the other. Existing secrets migrate automatically.
* Added .tests/dev-local-hub.sh: points the docker test site at a hub on the developer's local WordPress and verifies the full cross-site round trip (registration, signed submission, rules feed).

= 0.7.0 (11th July 2026) =
* Phase 6 Agents: AI assistants and scripts can now drive the whole plugin.
* WP-CLI commands: wp sspa run (synchronous with progress, all run types), wp sspa status, wp sspa findings, wp sspa impacts, wp sspa report - JSON output throughout.
* WordPress Abilities API (WP 6.9+): category super-speedy-performance with get-status, get-report, get-findings, get-plugin-impacts, get-site-metrics, run-analysis, run-deep-analysis and submit-results. Read-only abilities answer plain GETs on the core abilities REST controller; with the MCP Adapter plugin installed the same abilities appear as MCP tools automatically.
* Agent-ready report JSON: stable schema (documented in .docs/agent-api.md) with plain-English headlines and explicit recommendation objects per finding - built for LLM consumption.
* Guard rails for agents: all abilities require manage_options, submissions still require the site owner's opt-in on the Share tab, and run-analysis without include_writes performs GET requests only.
* SKILL.md published in the repo (Claude agents) plus OpenAI-equivalent instructions; knowledge-base articles for getting started, understanding results, security whitelisting and methodology.

= 0.6.0 (11th July 2026) =
* Phase 5 Community: opt-in sharing of anonymised results with superspeedy.org. The Share tab shows the EXACT payload before anything is sent: metric medians per generic page type, per-plugin attribution, measured isolation deltas, findings with normalised query fingerprints, plugin slugs and versions, bucketed site sizes. Never shared: your domain, URLs, raw SQL, emails or customer data - your site is a random ID.
* Install registration with per-install secrets; every submission is HMAC-signed. Submission history on the Share tab; opted-in sites contribute before pruning detailed data.
* Community rules feed: recommendation texts, thresholds, plugin categories, sector signatures and fragile lists can now improve without a plugin update. The feed is RSA-signed and verified before anything trusts it - a tampered feed is ignored and the bundled snapshot applies.
* Companion hub plugin (MVP, ships in the repo's hub/ folder for now): receives and quarantines submissions, flattens measured impacts for the future public rankings, serves the signed rules feed. Destined for superspeedy.org.

= 0.5.0 (11th July 2026) =
* Phase 4 Cache Impact Analysis: profiles your slowest pages with the persistent object cache on vs off (per-request via the db.php shim - zero effect on visitors; site-wide object-cache.php swap available as an explicit fallback). Per plugin you learn who actually uses your Redis/Memcached: cache-blind plugins (identical queries either way) are named and shamed, cache-friendly plugins get credit. The off-mode is verified from the captures before any conclusion is drawn.
* Object cache column on the Plugins tab showing queries saved per plugin.
* Email profiling: every analysis now measures your mail stack's construction cost via an intercepted test email (nothing is ever delivered - recipients are stripped before any transport work). Slow email construction becomes a finding attributed to the responsible plugin.
* Opt-in write profiles: saves a TEMPORARY copy of a post/product and steps a TEMPORARY order through pending -> processing -> completed, measuring the full save/status hook cascade including the emails each transition builds. Temporary objects are created and deleted around each measurement - zero residue, no real content touched, no emails sent.
* Safety rail hardened: profiled requests can never send mail in either interception mode, and failed mail construction is recorded rather than silently vanishing.

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
