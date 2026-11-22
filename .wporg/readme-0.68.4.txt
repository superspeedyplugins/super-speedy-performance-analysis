=== Super Speedy Performance Analysis ===
Contributors: dhilditch
Tags: speed, debug, woocommerce, performance, profiling
Requires at least: 6.9
Tested up to: 7.1
Stable tag: 0.68.4
Requires PHP: 7.4
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Find out which plugins slow your WordPress or WooCommerce site down, on which pages, and by how much. Free, on your own server.

== Description ==

Your site is slow, but which plugin is doing it? Super Speedy Performance Analysis measures your key pages and tells you, in plain English, which plugins cost the most time, queries and memory, and on which pages.

Press Run Analysis. It measures your home page, posts, archives, search and your wp-admin screens, and with WooCommerce your shop, categories, products, cart, checkout and orders. Then it lists what it found, worst first: the slowest queries and the plugin that ran them, plugins running queries in loops, queries fetching hundreds of rows, slow calls to other servers and more. Each finding links to the page it was measured on.

You do not need Query Monitor, a developer or an account. Everything runs on your own server and stays in your own database.

= Find the plugin that is slowing you down =

* **Every key page, front end and admin.** Administrator screens are measured with your own login, so you see what your team waits for, not only what visitors see.
* **Cost per plugin.** Database time, query counts, rows fetched and memory, charged to the plugin or theme whose code ran.
* **Plugin Impact Analysis.** Choose the plugins you suspect, and each page is measured again with that plugin left out of the test requests. Your visitors still get the full site: nothing is deactivated and no activation or deactivation code runs.
* **Function by function.** If your server has the excimer PHP extension, every page profile shows which functions used the time, including inside theme templates. The Tools tab shows the install commands for your server.

= Check your caching =

* **Cache performance** shows whether new visitors, returning visitors and shoppers with a basket get cached pages, and which cookies stop them.
* **Ad-click parameters** such as gclid, fbclid and utm_source: see whether one makes your site set a cookie or skip the cache, and which code is responsible.

= Measure what your shop does =

* **Checkout and order handling.** One test purchase on a hidden zero-price product, then viewing, completing, refunding and trashing the order. No payment provider is contacted.
* **Saving in the editor.** How long a save takes for a post, page, product or order, measured on a temporary item that is removed afterwards.
* **AJAX and REST.** Record the requests a real workflow makes, such as filtering or adding to basket, and see what each one costs.

= See what changed =

Every analysis is saved. Compare puts two of them side by side: response time first, then any new errors, warnings or changed output. When you update plugins, it offers a quick comparison so you can see what the update did.

Compare can also load public figures from superspeedy.org, so you can see how sites like yours, and the plugins you run, measure on other real sites. Loading them sends nothing about your site.

= Share the results =

Download or copy any analysis as Markdown, ready for a support ticket, your developer or an AI assistant. WP-CLI commands run analyses from the command line, and read-only WordPress abilities let an AI agent read the results over the REST API.

= What an analysis does on your site =

Performance tests require test objects. Administrator measurements use your own login, from the browser session that starts them; the plugin never creates, edits or deletes a user account. With guest checkout disabled, WooCommerce itself creates a customer account for the checkout flow's test purchase, and the results link to WordPress's Delete Users screen for it. Opt-in write profiles create private temporary post/product objects and a temporary order with its own test product; these objects are deleted after measurement. The separate checkout flow retains its hidden test product and its refunded orders in Trash for inspection, without contacting a payment provider.

Analyses send real emails to the site administrator: three mail probes with the default three baseline samples, plus any messages triggered by write and checkout measurements; extensions and deferred queues can add messages. Profiling a real save with Analyse update/save preserves its normal recipients.

== Installation ==

1. In Plugins -> Add New, search for Super Speedy Performance Analysis and install it.
2. Activate it through the Plugins menu.
3. Open Performance in the admin menu, then Tools, and press Enable profiler.
4. Go back to Overview and press Run Analysis.

== Frequently Asked Questions ==

= Do I need Query Monitor or any other tool? =

No. The plugin brings its own profiler. Tools -> Enable profiler installs it, and Disable profiler or deactivating the plugin removes it.

= Does it slow down my site for visitors? =

No. The profiler switches on only for the plugin's own test requests, which carry a signed, expiring token; every other request skips it. Plugin Impact Analysis leaves plugins out of those test requests only, so your visitors always get the full site and no plugin is ever deactivated.


= Is WooCommerce required? =

No. WordPress page and administrator profiling work without WooCommerce. Shop, cart, checkout and order measurements require WooCommerce.

= Does Traffic recording send visitor data to the community service? =

No. Traffic recording stores its observations in this site's own database. Community sharing submits profiling-run evidence through a separate opt-in workflow.

= What happens when I delete the plugin? =

Saved analysis data is retained by default. Before deleting the plugin, enable "Delete all SSPA data when the plugin is deleted" on the Compare tab if you want its profiling tables, settings and managed test objects removed. Deleting local data does not delete evidence you previously submitted to the community service.

= Do analyses send emails? =

Yes, synthetic measurements send real emails to the administrator and measure send or queue time; a baseline with the default three samples sends three probes, with additional mail from optional writes and checkout. The real Analyse update/save action preserves normal recipients.

= Which requests does the Cache performance check send? =

Only requests to this site's own pages, after an administrator presses "Check cache performance". It requests up to four fixed public pages (home and, with WooCommerce, the shop, one product category and one product): two requests per page from the administrator's browser without cookies, then up to seven per page from the server - two anonymous, two as a returning visitor carrying only the cookies the first response set (only when it sets one), two carrying WooCommerce's basket cookies with no basket behind them, and one final anonymous request. That is up to 36 requests for four pages. Logged-in customers are not requested. Nothing is sent to any other host, and no cache is purged or changed.

== Screenshots ==

1. The Overview after an analysis: a site score and the top findings, each naming the plugin responsible and linking to the page it was measured on.
2. Pages: every measured page with its generation time, database time, queries, rows fetched and memory.
3. One page's full profile: request phases, cost per plugin and the slowest queries.
4. Plugins: database time, queries and memory per plugin across every measured page.
5. Cache performance: which visitors get cached pages, and why the others do not.
6. Compare: two saved analyses side by side, response time first.
7. Tools: the profiler switch and the server tools that add detail, with the commands for your server.

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

Fix quote requests to superspeedyplugins.com: on the plugin's own screens an administrator can press "Request a fix quote" beside a page analysis, in its summary or on the Cache performance tab. A dialog first shows, in full, the page Markdown report that would be sent. Only when the administrator ticks the consent box and presses Send does the site's server send one HTTPS request to https://www.superspeedyplugins.com/wp-json/ssp-contact/v1/quote-request, run by WP Intense OÜ. It contains the name and email address entered, the site address unless it was cleared, the optional message, the page Markdown report (timings, query shapes with values removed, plugins and settings; never the JSON export), which screen it came from, the "ongoing performance care" and "Email me performance tips and offers" choices (both unticked to start with), the consent wording version, the plugin version and a random request number so that a retry is not stored twice. It uses a plugin User-Agent instead of WordPress's default, which contains the site address. The receiving server records the web server's IP address with the consent. The request is used to reply and prepare a quote and is never joined to anything shared with superspeedy.org. Nothing is sent automatically.

Quote request privacy: https://www.superspeedyplugins.com/privacy-policy/#performance-review-requests
Terms and conditions: https://www.superspeedyplugins.com/terms-conditions/

== Source code and third-party libraries ==

The plugin's readable source and build tools are available at https://github.com/superspeedyplugins/super-speedy-performance-analysis .

History charts use a locally bundled custom build of Apache ECharts 6.1.0 and its zrender dependency. The ECharts Apache-2.0 licence, zrender and d3 BSD-3-Clause licences and the ECharts NOTICE are included in includes/admin/vendor/. No chart script is downloaded from a CDN.

The readable chart entry point, pinned dependency lockfile and build script are at https://github.com/superspeedyplugins/super-speedy-performance-analysis/tree/main/.build/echarts . From that directory in a source checkout, run `npm ci` followed by `npm run build` to regenerate includes/admin/vendor/echarts-history.min.js and its licence files. Upstream readable source is at https://github.com/apache/echarts/tree/6.1.0 and https://github.com/ecomfe/zrender ; the exact zrender version is recorded in the lockfile.

== Files outside the plugin directory ==

Activation writes nothing outside the plugin folder. The explicit Enable profiler action in Tools installs `wp-content/mu-plugins/sspa-loader.php` and, only when no drop-in exists, the conditional database drop-in `wp-content/db.php`; Disable profiler and deactivation remove both. Enable honours DISALLOW_FILE_MODS, and only files signed by this plugin are ever removed.
Traffic recording installs the fixed `wp-content/mu-plugins/sspa-traffic-observer.php` only while recording is enabled.
Network-wide profiler changes require a network administrator on multisite; Traffic recording is unavailable on multisite.

Traffic data files live in `uploads/super-speedy-performance-analysis/`, which is closed to direct web access (`.htaccess` and an empty `index.html`): `traffic-config.json`, `sspa-traffic-observer.stopped`, temporary traffic-config publication files, and the empty lock file `sspa-traffic.lock`, which stops recording from continuing after deactivation. The recording on/off state is stored in the database.
Upgrades move the state from older authority files into the database and delete them; uninstall removes the folder's files and the option.
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

= 0.68.4 (22nd November 2026) =
* Fixed the Compare tab saying "Could not load this tab: Unexpected server response." after a site's first analysis; it now says this is the first saved point in time.
* The wordpress.org edition's analysis panel now says administrator pages use your own login and that no user account is created.

[Complete changelog](https://github.com/superspeedyplugins/super-speedy-performance-analysis/releases)

