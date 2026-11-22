=== Super Speedy Performance Analysis ===
Contributors: dhilditch
Tags: speed, performance, profiling, database, analysis
Requires at least: 6.9
Tested up to: 7.1
Stable tag: 0.68.3
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

Performance tests require test objects. Administrator measurements use your own login, from the browser session that starts them; the plugin never creates, edits or deletes a user account. With guest checkout disabled, WooCommerce itself creates a customer account for the checkout flow's test purchase, and the results link to WordPress's Delete Users screen for it. Opt-in write profiles create private temporary post/product objects and a temporary order with its own test product; these objects are deleted after measurement. The separate checkout flow retains its hidden test product and its refunded orders in Trash for inspection, without contacting a payment provider.

Analyses send real emails to the site administrator: three mail probes with the default three baseline samples, plus any messages triggered by write and checkout measurements; extensions and deferred queues can add messages. Profiling a real save with Analyse update/save preserves its normal recipients.


== Installation ==

1. In Plugins -> Add New, search for Super Speedy Performance Analysis and install it.
2. Activate it through the Plugins menu.
3. Open Performance in the admin menu.

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

Only requests to this site's own pages, after an administrator presses "Check cache performance". It requests up to four fixed public pages (home and, with WooCommerce, the shop, one product category and one product): two requests per page from the administrator's browser without cookies, then five per page from the server - two anonymous, two carrying WooCommerce's basket cookies with no basket behind them, and one final anonymous request. That is 28 requests for four pages. Nothing is sent to any other host, and no cache is purged or changed.

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

= 0.68.3 (19th November 2026) =
* Admin, login and REST requests are now recognised wherever the site puts them, including a renamed login page, a custom REST prefix and a moved `wp-content` folder.
* Every output buffer now closes even when the code inside it fails.

[Complete changelog](https://github.com/superspeedyplugins/super-speedy-performance-analysis/releases)

