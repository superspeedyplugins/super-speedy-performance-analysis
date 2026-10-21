=== Super Speedy Performance Analysis ===
Contributors: dhilditch
Tags: speed, performance, profiling, database, analysis
Requires at least: 6.2
Tested up to: 7.1
Stable tag: 0.48.2
Requires PHP: 7.4
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Free performance analysis for WordPress and WooCommerce. Finds which plugins are costing you SQL time, RAM and page speed, with evidence.

== Description ==

Super Speedy Performance Analysis diagnoses your site's performance the way an expert would:

* Profiles your key pages (home, shop, archives, search, checkout, wp-admin lists and editors) with a built-in profiler enabled for requested measurements - no Query Monitor install required.
* Attributes SQL time, returned row counts, RAM and query counts to individual plugins and your theme.
* Presents plain-English insights: the slowest queries and who ran them, queries fetching hundreds of rows (the usual RAM culprits), plugins running queries in loops, blocking HTTP calls and more.
* Plugin Impact Analysis isolates the real culprits by virtually disabling plugins for test requests only - your live visitors are never affected - and measures each suspect's true cost.
* Checkout analysis uses its own hidden SSPA product; write-order profiles create a separate zero-price product with its own managed stock, so neither orders real catalogue products.
* The Compare tab compares saved points in time, leads with response-time changes, highlights new faults and changed output, and can offer a quick comparison after plugin updates.
* When you ask it to, Compare downloads superspeedy.org's public figures and shows how sites like yours, and your active plugins, measure there.
* Compare can also opt into complete data removal on plugin deletion, dropping the profiling tables and removing SSPA options, transients, scheduled events and test objects.
* Optionally share anonymised results with the community at superspeedy.org to help build an open database of plugin performance. You see the exact payload before anything is sent.

Performance tests require test objects. Customer-session measurements create a restricted test customer account that cannot be logged into and is replaced on the next run. Administrator measurements use your existing account. Opt-in write profiles create private temporary post/product objects and a temporary order with its own test product; these objects are deleted after measurement. The separate checkout flow retains its hidden test product and its refunded orders in Trash for inspection, without contacting a payment provider.

Analyses send real emails to the site administrator: three mail probes with the default three baseline samples, plus any messages triggered by write and checkout measurements; extensions and deferred queues can add messages. Profiling a real save with Analyse update/save preserves its normal recipients.

This plugin is free. The build downloaded from https://www.superspeedyplugins.com/ includes automatic updates from superspeedyplugins.com; the WordPress.org build does not include this updater.

== Installation ==

1. In Plugins -> Add New -> Upload Plugin, upload the ZIP for your chosen distribution channel.
2. Activate it through the Plugins menu.
3. Open Performance Analysis in the admin menu, or Super Speedy -> Performance Analysis when the shared Super Speedy menu is installed.

== Frequently Asked Questions ==

= Is WooCommerce required? =

No. WordPress page and administrator profiling work without WooCommerce. Shop, cart, checkout and order measurements require WooCommerce.

= Does Traffic recording send visitor data to the community service? =

No. Traffic recording stores its observations in this site's own database. Community sharing submits profiling-run evidence through a separate opt-in workflow.

= What happens when I delete the plugin? =

Saved analysis data is retained by default. Before deleting the plugin, enable "Delete all SSPA data when the plugin is deleted" on the Compare tab if you want its profiling tables, settings and managed test objects removed. Deleting local data does not delete evidence you previously submitted to the community service.

= Do analyses send emails? =

Yes, synthetic measurements send real emails to the administrator and measure send or queue time; a baseline with the default three samples sends three probes, with additional mail from optional writes and checkout. The real Analyse update/save action preserves normal recipients.

= Which requests does the Cache performance check send? =

Only requests to this site's own pages, after an administrator presses "Check cache performance". It requests up to four fixed public pages (home and, with WooCommerce, the shop, one product category and one product): two requests per page from the administrator's browser without cookies, then seven per page from the server - two anonymous, two carrying WooCommerce's basket cookies with no basket behind them, two as the plugin's synthetic logged-in test customer, and one final anonymous request that checks no logged-in content reached the anonymous cache. That is 36 requests for four pages. Nothing is sent to any other host, and no cache is purged or changed.

== External services ==

The optional community service at https://superspeedy.org/ collects profiling evidence to build public performance comparisons. Automatic sharing is disabled by default. An administrator can enable it or submit selected analyses individually; the Share tab previews the exact evidence before submission. No purchased account is required.

Registration sends a random installation identifier, a generated authentication secret, and the plugin, WordPress and PHP versions. Each submission sends the installation identifier, run and submission identifiers, archive checksums and compressed/uncompressed sizes, protocol/schema/anonymisation versions, the run type and archive creation time, and a compressed archive containing timings and counts, plugin and theme identifiers and versions, page classifications and sanitised profiling details. These include size bands, normalised query fingerprints, component-published performance settings, external HTTP call aggregates and bounded boot, hook, render and asset summaries where available and covered by consent. Registration and submission metadata go to collector.superspeedy.org; the archive goes only to the Cloudflare R2 HTTPS storage upload host it supplies, ACCOUNT_ID.r2.cloudflarestorage.com (optionally prefixed by its bucket name), followed by receipt confirmation using the reservation identifier and archive checksum. Submission requests carry the installation identifier and cryptographic authentication signatures. Queued submissions may retry after temporary failures. Passive traffic and AJAX observation windows remain local.

Before queueing or sending evidence, privacy validation rejects site addresses and file paths, distinctive database names, email and IP addresses, user/order/product identifiers, sessions, cookies and request or response bodies. Calls to the site's own host are excluded. This anonymisation does not make the connection anonymous: the receiving servers and Cloudflare see its source IP address. The collector does not record IP addresses.

superspeedy.org is operated by WP Intense OÜ, which also runs superspeedyplugins.com. superspeedy.org data is never joined to leads, quote requests or customer records, and is never used for sales targeting. WP Intense OÜ stores submitted archives with Cloudflare R2 indefinitely, and may publish aggregates and anonymised individual results. Email support@superspeedyplugins.com with the installation identifier shown in Share to request deletion and recalculation of affected comparisons; uninstalling the plugin does not delete previous submissions. Submission grants WP Intense OÜ a permanent, royalty-free licence to store, process and publish that evidence in aggregated or anonymised form.

Community figures on the Compare tab: only when an administrator presses "Load community figures", the plugin downloads two public, read-only documents from superspeedy.org, https://superspeedy.org/api/v1/cohorts and https://superspeedy.org/api/v1/plugin-impact, one request each. These downloads send nothing about the site: no query string, cohort, plugin list, identifier or site address, and a plugin User-Agent instead of WordPress's default, which contains the site address. The site's cohort and active plugins are matched locally. Like any server, Cloudflare and superspeedy.org see the IP address of the web server making the request, but superspeedy.org does not log it. Nothing is downloaded until the button is pressed, and sharing is not needed to load the figures.

Privacy policy: https://superspeedy.org/privacy-and-terms/
Terms of service: https://superspeedy.org/privacy-and-terms/
Cloudflare privacy policy: https://www.cloudflare.com/privacypolicy/
Cloudflare service terms (including R2): https://www.cloudflare.com/service-specific-terms-developer-platform/

== Source code and third-party libraries ==

The plugin's readable source and build tools are available at https://github.com/superspeedyplugins/super-speedy-performance-analysis .

History charts use a locally bundled custom build of Apache ECharts 6.1.0 and its zrender dependency. The ECharts Apache-2.0 licence, zrender and d3 BSD-3-Clause licences and the ECharts NOTICE are included in includes/admin/vendor/. No chart script is downloaded from a CDN.

The readable chart entry point, pinned dependency lockfile and build script are at https://github.com/superspeedyplugins/super-speedy-performance-analysis/tree/main/.build/echarts . From that directory in a source checkout, run `npm ci` followed by `npm run build` to regenerate includes/admin/vendor/echarts-history.min.js and its licence files. Upstream readable source is at https://github.com/apache/echarts/tree/6.1.0 and https://github.com/ecomfe/zrender ; the exact zrender version is recorded in the lockfile.

== Files outside the plugin directory ==

The database profiler follows the Query Monitor drop-in pattern: activation installs `wp-content/db.php` only when no drop-in exists, honours DISALLOW_FILE_MODS and removes only its own signed file.
The explicit Enable profiler action in Tools installs `wp-content/mu-plugins/sspa-loader.php`; Disable removes it.
Traffic recording installs the fixed `wp-content/mu-plugins/sspa-traffic-observer.php` only while recording is enabled.
Network-wide profiler changes require a network administrator on multisite; Traffic recording is unavailable on multisite.

Non-PHP files live in `uploads/super-speedy-performance-analysis/`: `traffic-config.json` and `sspa-traffic-observer.stopped`, plus temporary traffic-config publication files.
The non-public `sspa-traffic-authority.php` starts with an exit guard and stores JSON after it; it uses a locked stable inode to prevent recording after deactivation and is removed on uninstall.
Upgrades migrate the old authority from mu-plugins; uninstall also removes legacy authority and stop-marker data there.
Recovery can restore `db.php.sspa-hold` and `object-cache.php.sspa-hold` left by older versions; this version never creates those holds.
WP-CLI exports print to stdout; redirect output in your shell to save it.

== Traffic privacy ==

Traffic recording stores pseudonymous keyed visitor, order and path joins, timestamps, event codes, timings, query counts, aggregate basket/order values and currency locally until an administrator deletes the collection in Traffic.
Pseudonymous observations are not anonymous and are never transmitted to the community service.
Recording has explicit time and row limits, and community sharing is a separate opt-in for anonymised profiling-run evidence.
Suggested privacy-policy wording is available in WordPress Settings -> Privacy.

== Library licences ==

Apache ECharts 6.1.0 uses Apache-2.0; zrender 6.1.0 and the bundled d3 quantile code use BSD-3-Clause, which are GPLv3-compatible licences.
The chart bundle includes the licence and notice files; its reproducible source is in the public repository's `.build/echarts/` directory.

== Changelog ==

= 0.48.2 (21st October 2026) =
* Raw stream URLs in page reports and summaries no longer show email addresses, numbers or other private-looking parts of the path.

= 0.48.1 (21st October 2026) =
* The cache check no longer reports a time to first byte later than the request's total time.

= 0.48.0 (21st October 2026) =
* Compared with other sites now sits directly under Compare points in time instead of below the list of every analysis run.
* Community figures on the Compare tab list your key pages first (home, shop, product category, product, checkout and search), with a Show all link for the other page types.
* The History tab is now called Compare, and old History links and bookmarks still open it.
* After an analysis and on the Compare tab, a panel asks whether to share this site's anonymised results automatically, with a button to be asked again in 24 hours.
* The Share tab has a Don't ask me again option that stops that panel, and can be unticked to bring it back.
* The Compare tab can load superspeedy.org's public figures for similar sites and for your active plugins, showing how many installations each figure comes from and how far it can be relied on.
* Community figures are downloaded only when you press Load community figures, and the download sends nothing about your site.

= 0.47.0 (21st October 2026) =
* The fixed-price fix quote for a page whose time is mostly unattributed now appears only when that page takes 1,000 ms or more to generate.
* Findings now offer Scalability Pro, Super Speedy Search, Super Speedy Filters, Super Speedy Imports or a fixed-price fix quote only when this site's measured evidence triggers them, after the finding and its free fix.
* Each screen shows at most one primary and one secondary offer, and never offers a product the site already runs.
* Every offer has a Don't show this again link, and a dismissed offer is left out of Markdown exports too.
* Added an Imports tab showing your importer's measured run speed beside the published Super Speedy Imports benchmarks, with a button to install the free Super Speedy Imports Lite.
* Offer descriptions and figures are quoted word for word from published Super Speedy Plugins pages, with the source link and the date they were checked.
* Recommendations no longer link to a product, and their text now describes the free fix only.
* The outdated PHP recommendation no longer claims PHP 8 is significantly faster.
* Offer links carry only a fixed placement tag, never the site address, the user or the analysis.

= 0.46.1 (21st October 2026) =
* Plugin Check now completes its escaping check on the cache check code instead of stopping with an internal error.

= 0.46.0 (21st October 2026) =
* The Cache performance tab warns when visitors with a basket are served the anonymous cached page and nothing on it loads their own basket, so a mini-basket can show empty.
* Without WooCommerce the cache check states and sends no basket requests.
* Overview shows the cache summary and its link to Cache performance before any analysis has been completed.
* Renamed an internal price-plugin list key so WordPress.org Plugin Check reports no warning for it.
* The Cache performance tab summarises how many visitors with a basket and logged-in customers reached WordPress in the latest completed Traffic collection, or links to Traffic.
* Added a Cache performance tab showing your cache system, the measured result for each audience and what published Super Speedy Plugins pages say about caching basket users and customers.
* The Cache performance tab presents the caching implementation service and, where prices vary per visitor, Super Speedy Ajax Prices, with links tagged only by placement.
* Overview now shows a two-line cache summary that opens the Cache performance tab.
* Markdown exports mention the caching implementation service only when the cache evidence for that export supports it.
* Added a cache check that measures anonymous visitors, visitors with a basket and logged-in customers separately and checks that no logged-in page reaches anonymous visitors.
* Agents and WP-CLI receive the per-audience cache check, the cache system inventory and the leak check in the version 3 cache document.

= 0.45.0 (20th October 2026) =
* Page reports now lead with the dominant time, the measured culprits, attribution coverage and a next action, with PHP phases and sample ranges in the Markdown export.
* Signed profiling requests measure process CPU against elapsed wall time.
* Signed page measurements time bounded template loads and record header, footer, sidebar, template-part and loop boundaries.
* Reports identify the largest untimed render gap with its surrounding events and template context.
* Added an optional all-hook timing pass with exclusive callback wall time and source locations.
* Page export headlines and component totals now come from the same measured request, keep sub-page paths and list profiler SQL separately as overhead.
* Moved page-specific Plugin Impact to the summary with measured suspects preselected, and single-page sweeps can use the browser when loopbacks fail.
* Added a separate opt-in raw HTTP stream diagnostic with caller timings and a response-header compatibility warning.

= 0.44.1 (19th October 2026) =
* Documented the remaining WordPress.org Plugin Check exceptions in the code.

= 0.44.0 (19th October 2026) =
* Added Clear all caches to the admin bar, with separate outcomes for the object cache, whole page cache and OPcache.
* Fixed SG Optimizer page-cache clearing through its public purge API.
* Removed the obsolete `--output` option from Traffic command help.
* Reactivation migrates the old Traffic authority before enabling it, and fresh legacy workflow objects keep their launch window.
* Upgrade failures appear on Performance Analysis screens and retry in wp-admin without interrupting visitor requests.
* Analyse update/save preserves real recipients, while private test objects and deferred order emails use administrator recipients.
* Interrupted workflows and write measurements clean up their owned objects, and stale profiler files are flagged in Health.
* Browser evidence uses sanitised form arrays, and community uploads are restricted to Cloudflare R2 storage hosts.
* WP-CLI exports use stdout; existing agent configurations must use the `sspa/` ability names.
* Traffic events use individual inserts and stop at the actual event ID limit.
* Traffic collections spanning older releases may split keyed URL or visitor joins where input normalisation changed.
* Removed the incomplete "Super Speedy caches" admin-bar action in both editions.
* Escaped admin report fragments and moved History data and admin-bar styles to WordPress asset APIs.
* Restricted ad-hoc profiling assets to measurement screens and controls.
* Sanitised request fields and replaced browser response uploads with derived diagnostic evidence.
* Moved Traffic authority and stop markers to uploads and protected foreign profiler-loader symlinks.
* Renamed agent abilities to `sspa/` and included traffic comparisons in discovery.
* The wp.org cache shortcut clears only Performance Analysis transients.
* Added Traffic privacy-policy guidance, JavaScript translations and complete chart library notices.
* Traffic event batches name their table through a prepared identifier placeholder.
* Analysis emails go to the site administrator and report measured send/queue time.
* Edit/save workflows create and remove their own administrator-owned objects, using a separate zero-price stock product for orders.
* Replacement mailers show an owner-naming warning when administrator recipient rewriting cannot be confirmed.
* Prepared analysis and traffic SQL identifiers and values without changing query selection or ordering.
* Limited query-plan index lookups to this site's prefixed tables.
* Updated profiler Health guidance to use the explicit Tools controls.
* Removed obsolete settings for the community rules service.
* Updated the community sharing notice, privacy policy and terms links.
* Removed UTM tracking parameters and an unsupported query-time claim from recommendations.
* Traffic collection now stores its observer configuration as JSON.
* Added explicit Enable profiler and Disable controls to Tools.
* Analysis rules now come only from the bundled snapshot.
* Aligned the stable tag and translation template with the plugin version.

= 0.43.4 (16th October 2026) =
* Removed the temporary `db.php` swap to keep connection-supplying database drop-ins in place during analysis.
* Cache Impact Analysis now uses only per-request cache toggling and names the database drop-in owner when that prevents analysis.
* Recovery and uninstall still restore drop-ins held by older versions without replacing another plugin's current file.

= 0.43.3 (13th October 2026) =
* Fixed `invalid_http_call_evidence` when an outbound HTTP call is recorded on the 404 page.
* Component settings are included in automatic sharing only when the recorded consent covers them.

= 0.43.2 (13th October 2026) =
* Fixed `invalid_component_state_evidence` when a shared component has empty settings or state maps.

= 0.43.1 (20th September 2026) =
* WordPress.org builds run every Plugin Check check and reject errors and warnings outside the documented database exceptions.
* Added external-service disclosures, removal instructions and links to the chart source and build tools.
* WordPress.org packages omit developer documentation and link the complete changelog.
* AJAX handlers verify nonces at their entry points and normalise request values before use.
* Community uploads require HTTPS and omit the site URL from their User-Agent header.
* Chart labels support translations, saved profiles receive keyboard focus, and score labels have clearer contrast.
* Checkout integration and webhook opt-outs also apply during completion, refunds and Trash.
* Shared SQL fingerprints remove comments and encoded numeric values; queued archives are rechecked before upload.

= 0.43 (16th September 2026) =
* Persistent-cache sites use the explicit Object cache action instead of the Super Speedy caches shortcut.
* Added a translation template for the plugin’s PHP and JavaScript messages.
* Consumed profiling tokens stay unusable after their captured results are read or discarded.
* Admin messages, AJAX controls and comparison summaries support WordPress translations.
* Plugin Impact links from the Super Speedy dashboard open the Plugins tab correctly.
* Failed tab loads show the request error and a Retry button.
* Wide analysis tables scroll within their panels on phones.
* Workflow grids, AJAX controls and long action labels fit narrow phone screens.
* Profile loading, progress and results expose concise status updates to assistive technology.
* Small profile notes use darker text for readable contrast.
* Profile phase details and query-copy controls are accessible from the keyboard.
* Sharing retries recognise an existing archive receipt without uploading the same analysis again.

= 0.42 (16th September 2026) =
* Successful profiled requests are no longer marked blocked by challenge-related page text or another request's status.
* Request details explain unsuccessful responses with their recorded reason instead of internal evidence labels.
* History charts distinguish warning squares from error triangles, with separate controls for their highlighting.

= 0.41 (15th September 2026) =
* History comparisons highlight response-time changes, new faults, changed output and plugin changes.
* Customer-session measurements cover My Account, Orders, the shop and a product page using a restricted test account.
* Order write profiles create their own zero-price product with separate stock.
* Markdown query fingerprints remove quoted data values.
* Traffic recording stops safely during deactivation and can start without WooCommerce.

= 0.40 (14th September 2026) =
* Expanded regression coverage for checkout timeout detection.
* Expanded regression coverage for Traffic comparison timing and History chart loading.
* Deactivating the plugin stops Traffic recording while concurrent requests finish safely.
* Traffic collections started before this update retain their data and finish as incomplete; start a new collection to use the revised recorder.
* Reactivation after complete data removal recreates Traffic tables before using retained state.
* Traffic collection can start on WordPress sites without WooCommerce.
* History labels average generation time as the mean.
* Archive-query capture reports when additional queries exceed its limit.
* Markdown query fingerprints remove both single-quoted and double-quoted data values.
* Write profiles use their own temporary product and stock for order-processing measurements.
* History comparisons share one set of rules across the report, CLI and Abilities.
* The page report panel gives focus back to whatever opened it when it closes, and rows on the Pages tab open from the keyboard with Enter or Space.
* A traffic collection stopped early now keeps a fixed observed duration, so its projected daily figures and any comparison built on them no longer drift until the planned end time passes.
* When an analysis temporarily swaps out another plugin's db.php drop-in, the serving PHP's opcache is invalidated on the swap and on the restore, so the first profiled requests no longer run the displaced file.
* Scans now measure My Account, Orders, the shop and a product page as a logged-in customer, using a synthetic account that is recreated for every run and cannot be logged into by a person.

= 0.39 (14th September 2026) =
* Detected plugin changes are recorded through one validated record, so an update, activation or deactivation always carries the versions it needs.
* Plugin version strings are validated by one shared rule everywhere History, change detection and sharing handle them.
* History expectations now check the approved HTTP response code as well as the page output, and say which one failed.
* Block checkout analyses record the order's real processing status before completion instead of a stale checkout-draft.
* The customer account WooCommerce auto-creates during a checkout analysis on stores without guest checkout is now deleted at the end of the run.
* The checkout-flow WP-CLI command accepts its documented --no-integrations and --no-webhooks switches.
* Plugins loaded through a symlinked directory are attributed to the plugin, not to core.
* Browser-driven analyses no longer pause for fifteen seconds when the same driver renews its lease within the same second.
* The checkout preflight reports a checkout page holding neither the shortcode nor the block as unsupported instead of offering a purchase.
* Report actions wrap inside the panel on phone-width screens instead of being clipped.
* The History chart's axis title no longer collides with the legend on narrow screens.
* Admin bar links to the Tools tab now open it, and the misleading MySQL digests warning is gone from the admin bar; the Tools tab still explains the performance_schema setting.
* History comparisons display retained measurements across old runs, partial analyses, test configurations and changed environments, without requiring complete version metadata.

= 0.38 (10th September 2026) =
* History axis labels show page names, with URLs and request details in instant hover tooltips.
* History charts use vertical page names and relative URLs, with faint category dividers and measurement context in tooltips.

= 0.37 (8th September 2026) =
* Load the chart library only when a History or AJAX chart is needed.
* AJAX feature builds require recorded tests for the current source and an approved SQL review.
* Shared profiling runs now retain plugin loading, selected hook, render and asset summaries with updated consent.
* AJAX comparisons report an error when saved request provenance is incomplete.
* Added local AJAX before-and-after charts with captured plugin selections, server request timings and chart export.
* Added opt-in bounded AJAX plugin activity evidence with separate hook registration and execution observations.
* Added a privacy-safe 15-minute endpoint evidence contract for Scalability Pro with exact registered identities, request metrics, callback ownership and honest unknown activity

= 0.36 (8th September 2026) =
* Removed the database insert-speed cutoff so traffic collection can start on slow databases.
* Fixed identical REST responses appearing changed when pagination links include profiling cache busters.
* Fixed query-loop findings missing a plugin's queries when its callback is invoked by the theme.
* Fixed Share payload previews opening in the wrong tab or disappearing during a background refresh.
* Fixed admin tabs following URL fragments when navigating to History or using browser Back and Forward.
* Added measured-page links to History point details and saved URL/workflow labels above profile actions
* History chart tooltips identify the page and whether its measurement is previous or recent
* Simplified History charts to grey previous measurements, blue recent measurements and red errors, without median lines
* Fixed failed chart metric changes leaving the selector inconsistent with the displayed measurements
* Fixed comparison headlines treating unmatched or failed pages as performance improvements
* Fixed automatic configuration comparisons to select the last measured run before the update
* Added clickable History entries with saved on-screen reports and page-profile inspection without rerunning analyses
* Added exact Before and After run selection that updates the chart and comparison report together, including unchanged plugin configurations
* Added request-level PHP diagnostic markers and details for newly measured pages, with explicit unavailable coverage for older measurements
* Fixed comparison exports using pending selector changes instead of the displayed comparison
* Improved History chart and comparison controls for narrow mobile screens
* Fixed History page filters being lost from the comparison table when changing chart metrics
* Fixed quick-comparison charts rejecting their selected full-scan baseline when it measured additional pages
* Added chart median change tooltips and clear labels distinguishing request samples from per-analysis summary medians
* Added History charts that compare every retained key-page measurement and median from the current plugin and theme setup with the immediately previous measured setup
* Added before-and-after History comparisons with update-triggered quick scans, response/output validity checks, learned expectations, and a preview-first privacy-safe evidence contract available through wp-admin, WP-CLI and the Abilities API

= 0.35 (23rd-26th August 2026) =
* Added managed-host safeguards that skip unsafe platform-owned cache comparisons, learn undeclared plugin dependency groups after one isolated failure, and preflight exact Plugin Impact and checkout paths before work is queued; thanks to Glenn H for discovering and documenting all three cases
* Relabelled direct PHP attribution so its bootstrap-timer boundary cannot be mistaken for a plugin's total causal impact
* Changed the top-level `Performance Analysis` admin-bar item to run `Analyse this page`, matching its first submenu action
* Tested up to WordPress 7.1
* Fixed `Analyse update/save` being hidden by the block editor's fullscreen mode and the Workflows item picker remaining on `Loading latest item…`
* Added the GitHub wiki, with a publish script
* Fixed the admin bar always reporting excimer as not installed
* Updated super-speedy-settings to the latest version

= 0.34 (22nd August 2026) =
* Plugin Impact Analysis is the name used throughout, matching page, checkout and order editing performance analysis
* The shared Super Speedy dashboard shows the slowest page measured and the component costing the most on it
* A link can open a tool ready to run rather than only landing on the page: `?sspa_open=page`, `checkout` or `save` opens the panel or the pre-flight, and never starts a measurement on its own

= 0.33 (22nd August 2026) =
* The traffic collector runs on any WordPress site, with only the commerce funnel needing WooCommerce, instead of refusing to start without it
* The autoloaded options finding offers Scalability Pro's options manager for anyone who would rather not run the generated SQL by hand every time the site changes

= 0.32 (22nd August 2026) =
* The mu-plugin loader and the `db.php` drop-in carry their own version numbers, so an ordinary release stops rewriting files it has not changed
* The synthetic account used to measure a page as a logged-in customer cannot be logged into at all: no password login, no password reset and no application passwords, while the five-minute cookie the profiler mints still works
* A site with page analyses but no site-wide run is told which measurement is missing rather than that nothing has been analysed
* The analysis actions come first in the Performance Analysis menu

= 0.31 (22nd August 2026) =
* Added the Performance Analysis admin-bar menu: analyse this page, analyse checkout, analyse update/save, the caches worth clearing before measuring, and one line each on the object cache, excimer and MySQL digests
* Any analysis can be downloaded as a privacy-safe Markdown report from the menu, for a developer, a host or an LLM
* Clearing caches reports what it actually did on a site that keeps its transients in a persistent object cache, instead of reporting nothing cleared
* The Health box refreshes the profiler loader when it is out of date rather than reporting it as not installed, and tells apart out of date, not writable, and a site that sets `DISALLOW_FILE_MODS`

= 0.30 (22nd August 2026) =
* The Super Speedy menu and the update checker come from the shared settings module, so a site running only Performance Analysis gets the full Super Speedy dashboard
* Updates are free and need no licence key, and come from the public release
* Where the settings module is absent there is deliberately no update checker, and the plugin falls back to its own top-level menu
* The plugin version is read from its own header, so the version named in reports, generated files and download filenames cannot drift from the one you installed

= 0.29.7 (21st August 2026) =
* Hardened the shared settings module and removed its Jetpack Autoloader dependency

= 0.29 (21st August 2026) =
* Added `Analyse update/save` to existing post, page, product, custom post type and HPOS order editors, profiling the real write request without including the subsequent editor reload

= 0.28 (20th-21st August 2026) =
* Fixed renamed WooCommerce session cookies being missed during checkout analysis and experimental traffic collection
* Preserved plugins with measured boot or asset costs when switching a report to caller attribution, even when they ran no SQL or HTTP calls
* Added WooCommerce popularity ordering to the archive profile contract

= 0.27 (20th August 2026) =
* Added privacy-safe Markdown downloads and clipboard copies for page, full-site and checkout reports
* Added expandable Excimer sampling details and server-specific installation guidance wherever function-level samples are unavailable

= 0.26 (19th-20th August 2026) =
* Added per-page plugin usage and unload-safety evidence, including boot cost, asset counts, output changes, dependencies and fail-safe classifications

= 0.25 (19th August 2026) =
* Removed the measurement request timeout so unusually slow pages can finish and be diagnosed instead of losing their profile
* Added a privacy-safe outbound WordPress HTTP API inventory with endpoint ownership, timing, purpose and fail-safe blocking classifications

= 0.24 (18th August 2026) =
* Added traffic opportunity estimates and before/after comparisons projected to 24 hours
* Updated `super-speedy-settings` to 1.8.0, restoring the official WordPress MCP Adapter

= 0.23 (14th August 2026) =
* Standardised the 17 performance abilities on the shared site bridge and removed the duplicate dedicated Adapter server
* Added separate `sspa_manage` and `sspa_execute` permissions so a report reader cannot start measurements or external work
* Real checkout runs now require `confirm: true` and default to suppressed mail with integrations and webhooks off unless each side effect is explicitly enabled
* Download filenames now start with the site's domain and SSPA version so reports from multiple sites can be grouped without opening them
* Added an experimental WooCommerce traffic collector with 24-hour, 72-hour and 7-day collection options in the new Traffic tab
* Added `wp sspa traffic start|status|stop|observations|delete` and matching non-destructive MCP abilities for starting, monitoring, stopping and reviewing an experimental collection
* Logged-in customer and non-empty-basket requests reaching WordPress are observed exactly, while broad anonymous origin traffic is deterministically sampled
* WooCommerce basket, cart, checkout, order, payment, cancellation and refund events use temporary keyed joins without storing customer, session, order or product identifiers
* The collector uses one buffered append per observed request, refuses a slow database pre-flight, retires itself at hard timestamp and event limits without WP-Cron, and checks a separate disk ceiling during health updates
* Added a privacy-checked experimental observations download for feeding real collection results and limitations back into the Traffic Performance Analysis design
* Stopped experimental collections can be permanently deleted with their raw event rows and temporary join key from the Traffic tab or WP-CLI; destructive deletion is deliberately not exposed through MCP
* Renamed the immediate shared-cache scan to Cache optimisation analysis in the GUI, CLI and MCP while retaining the existing `sspa/shared-cache-safety-report@2` schema and `wp sspa cache-scan` compatibility alias
* Fixed the checkout flow reporting an order's completion transition as `processing` to `processing` on sites with a persistent object cache
* Cleared every Plugin Check (PCP) error: table names now go through `$wpdb->prepare()`'s `%i` placeholder, admin output is escaped, and translatable strings with placeholders carry translators comments
* Sites that set `DISALLOW_FILE_MODS` are respected: no helper files are written, and the health line and run errors name the constant instead of file permissions
* Added a wordpress.org edition build that omits the bundled update checker
* Tested the full suite on PHP 8.1 through 8.5

= 0.22 (13th August 2026) =
* Checkout results now show the synthetic order's email address, WooCommerce order number, coupons and line items so fulfilment teams can identify and ignore it
* Shared-cache safety reports now separate infrastructure cookies from application hazards, score repeated site-wide signals once, show nonce provenance and page-associated source candidates, fairly sample every component, and exclude builder template pseudo-pages
* Performance analyses now include a downloadable shared-cache safety report showing visitor-specific content hazards, likely review difficulty, existing dynamic-region coverage and the plugin or theme components worth inspecting first; the same evidence is available through MCP and `wp sspa cache-scan`
* Classic checkout analysis now uses the nonce rendered for its actual guest cart session, fixing order placement on sites with customised WooCommerce session or nonce handling
* Analysis progress now updates as each measurement finishes instead of appearing idle and then showing a batch of completed pages at once
* Checkout and order-management waterfall rows now expand into their measured component, query, HTTP, mail and function-level evidence
* Order-management findings now describe staff time rather than incorrectly saying the customer is waiting after the purchase
* Single-page analysis results can now be exported as a self-contained JSON diagnostic for performance and cache-optimisation work
* Updated super-speedy-settings to the latest version
* Added browser-driven profiling when Basic Auth, a WAF, CDN or other security layer blocks the server from fetching its own pages
* Checkout-flow requests now use Cloudflare Turnstile's documented programmatic bypass, limited to SSPA's signed synthetic checkout, so human verification no longer prevents the test order being created
* Failed checkout reports no longer show a fictitious `Post-capture: 0 ms` section or imply that unmanaged stock prevented order creation

= 0.21.2 (13th August 2026) =
* An archive page that could not be profiled no longer stops Super Speedy Archives configuring itself when the pages it needed were measured. A blocked 404 page used to be enough to refuse the whole run.
* When archive pages genuinely were missed, the reason now names them and suggests checking whether a security plugin is blocking them.

= 0.21.1 (13th August 2026) =
* An analysis now starts even when collecting another plugin's published settings fails, instead of the analysis failing with it. The reason is recorded and shown on the Share tab.

= 0.21.0 (13th August 2026) =
* The Share tab now lists every plugin publishing its own settings, in that plugin's own words, with a switch for each one. Switching a plugin off stops it publishing anything from your next analysis onwards; the rest of your analysis is shared exactly as before.
* A plugin that publishes without describing what it publishes is now listed as having declared nothing, rather than appearing as a bare slug.
* The consent text is built from what the installed plugins actually say they publish, so it stays accurate on a site running plugins that did not exist when the text was written.

= 0.20.0 (12th August 2026) =
* Other plugins can now publish their own performance settings alongside a shared analysis, through the new `sspa_component_state` filter. A plugin only publishes what it deliberately opts in to publishing; this plugin never reads another plugin's options.
* Scalability Pro and Super Speedy Archives are the first to opt in, publishing which optimisations were on and whether the archive table was built.
* The two halves of an Enable good settings run are now labelled `before` and `after` and share a cycle identifier, so a shared pair can be compared as one experiment.
* A shared analysis now records whether the site is a production, staging, development or local one.
* The Share tab names every plugin publishing its settings, and the consent text is updated to match. Existing sharing settings are unaffected.
* Fixed an analysis reporting itself as incomplete when one page fetch failed after that page had already been profiled successfully.

= 0.19.1 (12th August 2026) =
* Archive recommendations now only cover archives big enough for an index to be worth building, so WordPress's own one-row template and global-styles queries no longer crowd out your real archives. Every archive is still reported with its size and its time, so anything reading the results can make its own choice.
* Fixed an analysis reporting itself as incomplete on every full run, which told anything consuming the archive profile to disregard it. The noise-floor and mail probes are instruments rather than pages and no longer count as pages that failed.
* Each archive now carries the rows it returned as well as the rows it matched, so there is a size to judge it by even when WordPress skips counting the total.
* Archive shapes are now included when you share an analysis: the sort columns, the index shapes and the query plan verdicts. Custom field names are only shared when they belong to WordPress, WooCommerce or a widely used plugin; anything specific to your site is shared as "custom", and field values are never shared.
* Fixed a proposed index listing a taxonomy-table column, which belongs to the table the optimisation replaces rather than to the index.

= 0.19.0 (12th August 2026) =
* Analysis now records what your archive pages - category, tag, shop, product category, custom post type and custom taxonomy archives - actually filter and sort by, and works out the exact database indexes that would let those pages sort without the database re-sorting every matching row each time.
* It also reports which custom fields would need to become real typed columns first, and infers the correct type for each by sampling the values you actually store. A price stored as text sorts "100" before "99", so the type is the difference between an optimisation that works and one that quietly corrupts the order.
* Fields whose values do not agree on a type are reported as mixed rather than guessed at, and are never recommended for automatic application.
* A new finding names each archive the database cannot sort from an index, with the query plan that proves it. The plan is read directly, so an archive that is fast today but will not stay fast as the catalogue grows is still caught.
* This works on sites with a persistent object cache, where a warm archive page never reaches the database at all.
* New agent ability `get-archive-profile` (also available over MCP) so Super Speedy Archives can configure itself from a measured analysis instead of asking you to fill the settings in by hand. The full contract is documented in the knowledge base article "The Archive Query Profile".

= 0.18.1 (12th August 2026) =
* The agent report schema and the OpenAI agent instructions moved to `docs/` in the repo, so the links in this readme, the README and SKILL.md resolve for anyone reading them on GitHub.
* The documented ability list now includes `run-checkout-flow` and `get-checkout-flow`, and the documented CLI list includes `wp sspa checkout-flow`. All four have existed since 0.11.0; only the documentation was behind.

= 0.18.0 (12th August 2026) =
* Shared analyses now describe what kind of site this is - an online shop, a jobs board, a publisher, or honestly more than one of them - with the signals that led to the label, so superspeedy.org can compare your results against sites genuinely like yours instead of a single average.
* Site sizes are shared as bands and never exact counts, and now cover pages, total orders, orders in the last 30 days, comments and how many plugins are active, alongside the posts, products, users and database size already sent. Order counts use safe routes only: total orders come from WooCommerce's own order table estimate or WordPress's maintained count, and from nothing at all on a posts table too large to count cheaply, while the 30-day figure is a real count of that window and stays accurate however busy your shop is.
* A checkout analysis now shares your order-management time - opening the order and marking it completed - as its own record with its own total. It was measured but discarded on the way out before. Customer checkout totals are unchanged and still contain no admin time.
* A management sequence that was blocked by a security plugin, or that only got half way, is shared as blocked or partial rather than quietly left out.
* The Share tab now describes all of this in plain terms: what the bands mean, and that checkout and order management travel separately.

= 0.17.4 (12th August 2026) =
* When a plugin reacts to another being excluded for measurement - trying to switch a plugin on or off, or to run a destructive database statement - you are now told, with an admin notice naming the plugin that reacted and the plugin it reacted to. Every attempt was already refused; now you find out even if the analysis finished while you were elsewhere.
* Reactions get their own block on the Overview tab instead of competing for a top-five slot they always lost, and read as plain English ("Rank Math Pro tried to deactivate a plugin while Rank Math was excluded for measurement") rather than a rule name.
* Shared analyses now carry which plugin was excluded alongside which plugin reacted, so these pairs can build a community dependency map. The refused statement itself is still never shared, only its fingerprint.
* Fixed the SEO plugin slug in the bundled rules (Rank Math installs as seo-by-rank-math), so a Rank Math site is correctly spotted when a second SEO plugin is also active.

= 0.17.3 (11th August 2026) =
* MCP abilities are now marked public so agent tooling can discover them

= 0.17.2 (11th August 2026) =
* Updated super-speedy-settings to the latest version

= 0.17.1 (11th August 2026) =
* The site score is now shown out of 100 (e.g. 10/100) on the Overview tile, the History tab and in `wp sspa` output, so a low score cannot be read as 10 out of 10.
* Added a `status` index to the runs table so the active-run check no longer scans the whole run history, keeping admin pages fast once many analyses have accumulated.

= 0.17.0 (11th August 2026) =
* The checkout analysis now measures order management too: after the purchase it opens the order in wp-admin and marks it completed - the two things a shop owner does most - so you can see how slow your order screen is and what marking an order completed sets off (the completed-order email, stock and downloads, and every plugin hooking order completion).
* The order-management time is shown in its own section below the customer's wait, because it is your staff time per order, not something a customer waits through.
* "Analyse checkout flow" is now "Analyse checkout & order flow" on the admin bar and the Pages tab, and the disclosure lists the order view and completion before anything runs.
* The completed-order email is timed or intercepted exactly as the other order emails are, following your mail setting.

= 0.16.1 (11th August 2026) =
* New Settings tab with the measurement timeout: how long each measurement request may wait for a page before giving up (10-900 seconds, default 60). Raise it when the pages you are diagnosing take longer than a minute to load - a page slower than the limit records nothing at all, and those are exactly the pages worth measuring.

= 0.16.0 (10th August 2026) =
* Plugin Impact Analysis now always starts from a chooser: nothing is measured until you pick the plugins yourself, nothing is preselected, and the chooser explains exactly what will happen and what it will cost before anything runs.
* If a plugin reacts to another being excluded during a measurement, its activation and deactivation routines are now silenced before they can run, and destructive database statements (`DROP`, `TRUNCATE`, `ALTER ... DROP`, whole-table `DELETE`) are refused for that request - measured on a real deactivation routine that drops database indexes.
* Every caught reaction becomes a finding on the analysis, is included when you share results, and the pair is measured together from the next run on, so a reaction can happen at most once per site.
* Plugin Impact Analysis now requires this plugin's own `db.php` drop-in during the run, because that is what enforces the database guard; sites where another plugin owns the drop-in can use the existing temporary swap option.
* New `--no-cache-modes` behaviour applies from the chooser too: the object-cache measurements are opt-in rather than automatic.

= 0.15.0 (10th August 2026) =
* Plugin Impact Analysis now reads each plugin's own code to find which plugins cannot run without another one, and measures those together in one go, so nothing is ever left running without something it depends on.
* Your SEO plugin, page builder or anything else another plugin depends on can now be measured at all: these used to be skipped entirely to keep them safe.
* A verdict measured this way says so, e.g. "measured together with seo-by-rank-math-pro, which cannot run without it", and the running analysis names both plugins in the measurement.
* The plugin picker on a page panel tells you which plugins will be measured together before you start.

= 0.14.0 (10th August 2026) =
* Fixed a plugin that switches itself off when a dependency is missing staying off after a Plugin Impact Analysis measured it: a measurement can no longer change which plugins your site runs.
* "Analyse this page" and clicking a page on the Pages tab now open the same panel, carrying everything either of them used to show on its own.
* That panel now also shows per-plugin attribution in both modes, the outbound HTTP calls, what `EXPLAIN` says about each slow query, the object cache hit rate for the page, and what has been measured by disabling plugins on it.
* New "Measure plugin impact on this page" on the panel: tick the plugins, read "7 plugins x 1 page = 9 measurements, about 20 seconds" before anything starts, and the verdicts appear in the same panel when it finishes.
* Plugin Impact Analysis can now be pointed at any URL on your site, including a page no analysis has ever profiled, because it measures that page's own baseline as it goes.
* The Pages tab now lists the newest measurement of each page, whether it came from a full analysis or from analysing that page on its own, with the date it was taken; the site score still comes only from full analyses.
* Removed the "Open in Performance Analysis" link from the page panel, which is now the full view.
* New `--url` and `--no-cache-modes` options on `wp sspa run --type=deep`, and `url`, `pages` and `cache_modes` on the `run-deep-analysis` ability.

= 0.13.1 (10th August 2026) =
* Deep Analysis is now called Plugin Impact Analysis, matching the Measured impact column it fills.
* The Share tab now tells you when sharing is on but an analysis could not be prepared, instead of failing quietly.
* A queued submission no longer stops if the collector address is wrong for a moment - it retries a few times before giving up, so correcting the address no longer means retrying every item by hand.
* An analysis you chose to share on its own is no longer left waiting when it needs a retry.
* The running analysis now shows which phase it is in beside the measurement count, so the total growing part-way through makes sense.
* A queued submission no longer depends on its analysis still being in your history.
* Database updates now run one at a time, so two visitors arriving together cannot collide.

= 0.13.0 (10th August 2026) =
* New "See exactly what gets sent" button on the Share tab: it builds the payload for your latest analysis, explains in plain English what it contains and what it never contains, and lets you download the file. Nothing is queued or sent.
* Queued submissions are now delivered by your browser while you are on the analysis screen, instead of waiting for WP-Cron, which many hosts disable. Cron still runs as a backstop.
* Measured impact now reads "adds 120ms typically, up to 340ms on wc-checkout", replacing a total that summed every page together - a figure no visitor ever experienced, and one that grew simply by measuring more pages.
* Measured impact is now kept per page, so re-measuring one plugin on one page updates that page and leaves every other page's result standing.
* Analysing a page that the full analysis already covers, such as the shop or the home page, now files it under that page rather than a one-off entry, so the two are comparable.

= 0.12.0 (9th August 2026) =
* Nothing on the Performance Analysis screen reloads the page any more: queueing a run, pruning detailed data, replacing a drop-in, retrying or pausing a submission, cancelling a run and finishing an analysis all update in place, keeping the tab you were on.
* The Sharing settings and Choose analyses links now switch tab in place instead of reloading and landing you back on Overview.
* Fixed sharing being refused on any site running a plugin with a four-part version number, e.g. 3.0.83.3, which the privacy check mistook for an IP address. Affected sites queued nothing at all; their existing analyses can be sent with Queue existing runs.
* New Version column on the Plugins tab, showing the version each component was at when the analysis measured it.
* Deep Analysis records the plugin version it measured, and the Plugins tab now says when a measured verdict was taken against a version you no longer run.
* The per-page breakdown names the version its measurements came from.
* The History tab lists the components and versions each analysis measured.
* The page title now sits at the start of the tab row, with the running plugin version beside it.
* The community rules feed backs off for 12 hours after a failed fetch instead of retrying every hour.
* Queued submissions are claimed atomically, so two background workers can never deliver the same payload twice.
* A submission interrupted by a PHP timeout now waits for its scheduled retry instead of becoming due again immediately.
* Admin styles and scripts refresh as soon as they change, so the analysis screens cannot render with a stale stylesheet.
* Every analysis now records which options each page actually read, so the Overview can name the autoloaded options no page used and the size they cost on every request.
* New Autoloaded options panel on the Overview, with copy-and-paste SQL to switch autoload off for options nothing read and on for options read on nearly every page.
* The Attribution Code owner and Caller buttons on the Plugins tab now swap the table in place instead of reloading the page.
* Measured impact now says when it was measured, and warns when that Deep Analysis ran before the analysis shown beside it or against a version you no longer run.
* A running analysis now opens in the middle of the screen and lists every measurement as it is taken, e.g. "home with super-speedy-search disabled, object cache off", instead of only a progress bar.
* Fixed the minimise button on the running-analysis panel doing nothing.
* Deep Analysis now accepts a page list, so `wp sspa run --type=deep --suspects=my-plugin --pages=admin-orders-search` re-measures one plugin on one page instead of sweeping every page.
* Sharing your results with the community is now a durable background queue: each completed run becomes its own versioned anonymised payload, which survives reloads and outages and retries until the community archive confirms it is stored.
* Deep analyses, cache analyses, checkout flows, ad-hoc page profiles, plugin-toggle spot checks and Excimer function, component and phase data are all shared now, each as one coherent run rather than a mixture of the latest profiles and unrelated history.
* Ad-hoc page profiles are shared as a page classification and an opaque identifier, so no URL or URL-derived key leaves your site.
* The Share tab previews the exact JSON each queued payload would send, with its compressed size, SHA-256 and receipt.
* New `Queue existing runs` control shares the analysis history you already have, in bounded resumable batches, skipping runs that are already queued or archived.
* New `Retry now`, `Pause` and `Resume` controls per queued payload, replacing the old Submit Now button, which rebuilt a payload from scratch on every press.
* Payloads upload straight to the community archive using a short-lived single-object URL issued by superspeedy.org, and TLS verification is always on.
* Sharing stays off until you opt in, an update never turns it on, and turning it off stops new payloads and delivery attempts without deleting anything already archived.
* New `Share this` control on every analysis in the History tab, so you can contribute a single full scan, deep analysis, page analysis, cache analysis, spot check or checkout analysis without switching on sharing for everything else.
* The Share tab now presents the two choices separately: share every analysis automatically, or pick individual analyses and share only those.
* Analysis runs never wait on the network, so the community archive being unreachable cannot slow down or fail a run.

= 0.11.4 (7th August 2026) =
* The excimer install steps now use your distribution's package (`php8.3-excimer` style on Debian and Ubuntu, Remi's `php-pecl-excimer` on RHEL and Fedora, `php83-pecl-excimer` on Alpine), named for the exact PHP version the site runs so servers carrying more than one PHP enable it for the right one; pecl remains only where no package exists, as upstream no longer publishes excimer releases there.
* The `tideways_xhprof` install steps now build the extension from its source repository, `github.com/tideways/php-xhprof-extension`.
* The ask-your-host message carries the same install commands and states which PHP version the site runs.

= 0.11.3 (7th August 2026) =
* New deterministic misbehaviour signatures in the checkout analysis, matched by behaviour so white-labelled and hosting-installed forks are caught identically: `checkout_purge_order_pages` (a cache plugin "purging" the order itself - with HPOS that is a request to `/?p=<order id>` that renders a slow 404, fired on every order note), `checkout_amp_purge_missing` (purging `/amp/` pages on a site with no AMP plugin, each one a full 404 render), and `checkout_self_fetch_failed` (the site calling its own URL and timing out while the customer waits). Each finding names the calling plugin and the specific fix.
* Outbound calls keep the values of safe routing keys (`p`, `page_id` and similar), never anything else, so the target of a purge is identifiable.

= 0.11.2 (7th August 2026) =
* Fixed the checkout flow's own pre-flight and cleanup steps leaking into the component roll-up, the outbound-call list and the mail totals, which could overstate a cache-purge plugin's cost by a quarter.
* The payment boundary is now stamped at entry to `payment_complete()`, so order emails, cache purges and integrations hanging off the status transition are reported as post-payment confirmation time rather than time that risks the sale.
* On stores that disallow guest checkout, the customer account WooCommerce creates for the purchase is now deleted with the order, the pre-flight discloses it, and the hourly janitor sweeps any a crashed run left behind.
* One `checkout_blocking_http` finding per plugin per step, with the call count, total time and the worst call, instead of one finding per call.
* Outbound calls now show their method, query-string keys and calling function, so a purge of `/?p=123` no longer displays as a fetch of the bare homepage.
* Emails sent through a mail plugin that replaces WordPress delivery (Mailgun's HTTP mode and similar) are now all counted and reported as untimed, with their real cost visible under outbound calls, instead of showing as one message in 0ms.

= 0.11.1 (7th August 2026) =
* The checkout flow now measures the classic shortcode checkout as well as the block checkout, so a store using `[woocommerce_checkout]` no longer gets "Only the block checkout is supported so far".
* Fixed the run notes reporting "0 orders deleted" on a checkout flow that failed part way through, when the order had in fact been deleted.

= 0.11.0 (7th August 2026) =
* New: "Analyse checkout flow" measures the server time your customer waits through at every step of one real purchase, with every plugin on your site active, split at the payment boundary so time that risks the sale is shown separately from time after the money is in.
* A pre-flight panel lists exactly which emails, webhooks and plugins a run will set off before anything is created, and the order is cancelled and deleted afterwards with stock restored.
* New checkout findings: `checkout_slow_step`, `checkout_component_cost`, `checkout_blocking_http`, `checkout_mail_inline` and `checkout_dupe_queries`.
* New `wp sspa checkout-flow` command plus the `run-checkout-flow` and `get-checkout-flow` abilities, with `--dry-run` to print the pre-flight without buying anything.
* Order emails are sent for real during a checkout flow run so your mail server's own time is measured; every other kind of run still sends nothing.
* The checkout flow covers the block checkout, and says so rather than guessing when a store uses the shortcode checkout.
* Profiles now record the request method, so the POST steps of a checkout are no longer listed as GETs.
* The sampling profiler labels the last phase of a REST or `wc-ajax` request `endpoint_work` rather than `render_and_output`.

= 0.10.12 (7th August 2026) =
* The installation instructions now point at the GitHub Releases download, https://github.com/superspeedyplugins/super-speedy-performance-analysis/releases/latest

= 0.10.11 (7th August 2026) =
* Restored the plugin's own update check against superspeedyplugins.com, so new versions show up on your Plugins screen again. If you are on 0.9.3 to 0.10.10, install this one by hand once from https://github.com/superspeedyplugins/super-speedy-performance-analysis/releases/latest and updates arrive on their own from then on.

= 0.10.10 (7th August 2026) =
* Fixed the spot-check offered after you activate or deactivate a plugin re-running itself every time the page reloaded, instead of stopping once its results were in.
* The spot-check notice now clears once you take it up, not only when you dismiss it.

= 0.10.9 (4th August 2026) =
* The Tools tab leads with excimer - it is the single biggest capability upgrade a site can make, and the page says so plainly: "if you install one thing, install excimer". Its card describes everything it unlocks (the function view, driven-by splits, per-phase drill-downs) and switches to Active with an in-use description once installed.
* The tracing-profiler card (tideways_xhprof) states honestly that the plugin does not read it: exact call counts are a planned deep-dive mode, and sampling answers most questions without the overhead.
* Function-level profiling has a full guide in the knowledge base at superspeedyplugins.com/kb/super-speedy-performance-analysis/features/function-level-profiling/ - what the function view gives you, how to read self versus inclusive time, and the exact install steps including the noexec /tmp fix.

= 0.10.8 (4th August 2026) =
* NEW: the panel discloses the measurement path. Profiling requests are loopbacks from your server to itself, and on many hosts those bypass your CDN - arriving WITHOUT the headers Cloudflare adds for real visitors (the country header, WAF marks). Behaviour keyed on those headers then differs between the measured path and the visitor path: WooCommerce's MaxMind lookup, for example, only runs when no country header is present. Every capture records whether it passed through Cloudflare and whether the country header was present, and the panel says so plainly - including a note when Cloudflare was traversed but IP Geolocation is switched off.

= 0.10.7 (4th August 2026) =
* NEW: the untimed rows expand IN PLACE to the functions that were actually running during that specific phase. Every profiler sample carries a timestamp, so samples are bucketed into the request phases - clicking the render remainder shows the functions sampled during render, clicking the plugin-boot gap shows what ran during plugin boot. Each phase gets its own list instead of one global table answering every click the same way.
* The global "By function" table remains at the bottom as the whole-request view; older stored results without phase data keep the jump-to-table behaviour.

= 0.10.6 (4th August 2026) =
* NEW: the "By function" table now says who was DRIVING each hot function. When a shared function's time is spent on behalf of several plugins, the row shows the split - "WP_Object_Cache::get, 63ms self, driven by: woocommerce 31ms, seo-by-rank-math 12ms" - using the same shared-library-aware attribution as everything else. This is the difference between knowing the object cache is busy and knowing which plugin is keeping it busy.

= 0.10.5 (4th August 2026) =
* The "untimed remainder" rows are now the doorway to the function view: when the sampling profiler ran, clicking any untimed row jumps to the "By function" table and highlights it - that table is where the remainder's time gets named, function by function.
* The panel's "By function" table now lists functions by SELF time first. Sorted by inclusive time, the top rows were WordPress's own bootstrap include chain - plumbing, not culprits. Self time names the functions actually burning the CPU.

= 0.10.4 (4th August 2026) =
* The Tools tab's PECL install steps now include the fix for the "shtool ... does not exist or is not executable" failure: hardened servers mount /tmp with noexec, which breaks pecl's build. The verified fix is a temporary exec remount of /tmp for the build, restoring noexec afterwards - proven on a real hardened server.
* The Tools tab's Re-check button now rechecks in place over ajax instead of reloading the page, so it no longer dumps you back on the first tab.

= 0.10.3 (4th August 2026) =
* The panel header now reads "Super Speedy Performance Analysis" with a version pill, matching the Super Speedy Imports design - so screenshots also say which version produced the numbers.

= 0.10.2 (4th August 2026) =
* The panel header wordmark is now the text-only version, without the icon.

= 0.10.1 (4th August 2026) =
* The panel header now carries the current Super Speedy Plugins lockup (icon + wordmark, white), bundled with the plugin instead of borrowed from the shared settings module, whose copy was an older mark.
* Escape closes the panel, on the front end and in wp-admin.

= 0.10.0 (4th August 2026) =
* NEW: function-level profiling via the excimer PHP extension. When excimer is installed (the Tools tab generates the exact install steps for your server, or a ready-to-paste message for your host), every profiled page automatically gains a "By function" breakdown: which exact functions the time was spent in, with self and inclusive milliseconds, each attributed to its plugin or theme - including inside theme template files, which nothing else can see. Appears in both the "Analyse this page" panel and the Pages tab drill-down.
* Because excimer records a complete call stack for every sample, attribution uses the same shared-library-aware walk as the SQL analysis - a plugin is never blamed for a vendor library another plugin called into.
* Sampling has negligible overhead (the same profiler runs on every Wikipedia request), so it runs during the normal measurement pass without distorting any of the numbers.
* Everything still works without excimer - the breakdown simply does not appear, and the Tools tab card now switches to "in use" once the extension is present.

= 0.9.15 (4th August 2026) =
* The panel's provenance line now leads with a relative age ("5m ago", "just now") computed server-side, so you never have to reconcile the timestamp against the server's timezone.
* The cryptic "anon" label now says what it means: "profiled as a logged-out visitor" (or "profiled as admin" for wp-admin URLs) - front-end pages are deliberately measured the way a real visitor gets them, without the admin bar and admin-only hooks.

= 0.9.14 (4th August 2026) =
* NEW: every phase in "Where the PHP time went" is now expandable - click a phase to see which plugins its time belongs to (plugin file loading per plugin, plugins_loaded/init/wp_loaded callbacks per plugin, the render phase per plugin), with anything our instruments could not see honestly labelled. Works on already-stored results too - the data was always captured, just not shown.
* NEW: much more of the render phase is now timed. WooCommerce's template layout hooks (shop loop, product summaries, sidebar and friends) and every dynamic block render are individually timed and attributed to the plugin that provides them, so the "untimed remainder" shrinks to genuinely just the theme's own template code.
* FIXED: on archive pages, hooks that fire once per post (the_content, the WooCommerce loop hooks) had their callbacks re-wrapped on every firing, nesting the timers and counting the same work more than once. Wrapping now happens exactly once per hook.

= 0.9.13 (4th August 2026) =
* The "Analyse this page" panel is now branded and styled to match the Super Speedy design language: purple accents, rounded corners, the Super Speedy Plugins wordmark in the header, and a "Powered by Super Speedy Performance Analysis - free from superspeedyplugins.com" footer, so screenshots of your results say where they came from.
* Slow queries are click-to-copy: the row shows the start of the query, clicking it copies the FULL query to your clipboard and confirms with a small "Copied" toast that fades out.
* Tested up to WordPress 7.0.

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
* Agent-ready report JSON: stable schema (documented in .kb/agent-api.md) with plain-English headlines and explicit recommendation objects per finding - built for LLM consumption.
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
