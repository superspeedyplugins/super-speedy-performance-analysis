=== Super Speedy Performance Analysis ===
Contributors: dhilditch
Donate link: https://www.superspeedyplugins.com/
Tags: speed, performance, profiling, query monitor, analysis
Requires at least: 6.2
Tested up to: 7.0
Stable tag: 0.18.1
Requires PHP: 7.4
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

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

1. Upload the plugin to /wp-content/plugins/ or install the zip from https://github.com/superspeedyplugins/super-speedy-performance-analysis/releases/latest
2. Activate it through the Plugins menu.
3. Go to Super Speedy -> Performance Analysis and run your first analysis.

== Changelog ==

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
* Agent-ready report JSON: stable schema (documented in docs/agent-api.md) with plain-English headlines and explicit recommendation objects per finding - built for LLM consumption.
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
