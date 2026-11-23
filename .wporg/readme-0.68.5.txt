=== Super Speedy Performance Analysis ===
Contributors: dhilditch
Tags: speed, debug, woocommerce, performance, profiling
Requires at least: 6.9
Tested up to: 7.1
Stable tag: 0.68.5
Requires PHP: 7.4
License: GPLv3
License URI: https://www.gnu.org/licenses/gpl-3.0.html

Find out which plugins slow your WordPress or WooCommerce site down, on which pages, and by how much. Free, on your own server.

== Description ==

**Your site is slow. Find out which plugin is doing it, on which page, and by how much.**

Press **Run Analysis**. It measures your real pages on your own server and names the plugin behind each slowdown. No developer, no account, no switching plugins off one at a time.

= One button, then answers (screenshot 1) =

* **Every key page:** home, posts, archives, search and wp-admin, plus shop, products, cart, checkout and orders with WooCommerce
* **Worst first:** slow queries and the plugin that ran them, queries in loops, queries fetching hundreds of rows, slow calls to other servers
* **One click to the cause:** every finding links to the page it was measured on

= See exactly where the time went (screenshots 2 and 3) =

* **Full page profile:** request phases, cost per plugin and the slowest queries
* **Admin screens too:** measured with your own login, so you see what your team waits for
* **Every page side by side:** time, database time, queries, rows and memory

= Name the plugin that costs you most (screenshot 4) =

* **Cost per plugin:** database time, queries, rows and memory, charged to the plugin or theme whose code ran
* **Plugin Impact Analysis:** pick your suspects and every page is measured again without them. Visitors still get the full site; nothing is deactivated
* **Function by function:** with the excimer PHP extension, see which functions used the time, theme templates included

= Check your caching (screenshot 5) =

* **Who gets cached pages:** new visitors, returning visitors and shoppers with a basket
* **Which cookies stop them**
* **Ad-click links:** whether gclid, fbclid or utm_source skip your cache, and which code is responsible

= Measure what your shop really does =

* **Checkout and orders:** a test purchase on a hidden zero-price product, then view, complete, refund and trash the order. No payment provider is contacted
* **Saving:** how long a post, page, product or order takes to save
* **AJAX and REST:** record a real workflow, such as filtering or adding to basket, and see what each request costs

= See what changed (screenshot 6) =

* **Every analysis is saved.** Compare two side by side: response time first, then new errors, warnings or changed output
* **After plugin updates** it offers a quick comparison
* **Sites like yours:** load public figures from superspeedy.org, sending nothing about your site

= Share it and script it (screenshot 7) =

* **Markdown reports** for a support ticket, your developer or an AI assistant
* **WP-CLI** and **read-only WordPress abilities** for scripts and AI agents
* **Tools tab:** the profiler switch and install commands for your server's optional extras

= Performance Analysis Pro =

[Performance Analysis Pro](https://www.superspeedyplugins.com/product/super-speedy-performance-analysis/) adds what WordPress.org does not host: measurements as a logged-in customer, checkout tests that delete the account a purchase creates, a DNS check, and an AI agent that can start analyses and change settings. Both editions share your saved analyses, so moving to Pro keeps every result.

== Installation ==

1. In Plugins -> Add New, search for Super Speedy Performance Analysis and install it.
2. Activate it through the Plugins menu.
3. Open Performance in the admin menu and press Run Analysis. Your first analysis installs the profiler, and the screen shows what it adds before you press the button.

== Frequently Asked Questions ==

= Do I need Query Monitor or any other tool? =

No. The plugin brings its own profiler. Your first analysis installs it, and Disable profiler in Tools or deactivating the plugin removes it.

= Does it slow down my site for visitors? =

No. The profiler switches on only for the plugin's own test requests, which carry a signed, expiring token; every other request skips it. Plugin Impact Analysis leaves plugins out of those test requests only, so your visitors always get the full site and no plugin is ever deactivated.

= Is WooCommerce required? =

No. WordPress page and administrator profiling work without WooCommerce. Shop, cart, checkout and order measurements need WooCommerce.

= What does an analysis create on my site? =

Administrator pages are measured with your own login; the plugin never creates, edits or deletes a user account. With guest checkout disabled, WooCommerce itself creates a customer account for the checkout test purchase, and the results link to Delete Users for it. Opt-in save measurements use private temporary posts, products and a temporary order, deleted afterwards. The checkout flow keeps its hidden test product and its refunded orders in Trash for you to inspect.

= Do analyses send emails? =

Yes, to the site administrator: three mail probes with the default three baseline samples, plus any messages that save and checkout measurements trigger. Analyse update/save profiles a real save, so it keeps that save's normal recipients.

= Which requests does the Cache performance check send? =

Only requests to your own site, after you press "Check cache performance": up to four public pages (home and, with WooCommerce, the shop, a category and a product), at most 36 requests in all, as a new visitor, a returning visitor and a shopper with a basket. Nothing goes to any other host, and no cache is purged or changed.

= Does Traffic recording send visitor data anywhere? =

No. Traffic recording keeps pseudonymous visitor, order and path joins, timestamps, event codes, timings, query counts and aggregate basket values in your own database, within time and row limits you set, until you delete the collection in Traffic. It is never sent to the community service. Suggested privacy-policy wording is in Settings -> Privacy.

= Can I delete what I shared with superspeedy.org? =

Yes. Email support@superspeedyplugins.com with the installation identifier shown on the Share tab. Uninstalling the plugin does not delete earlier submissions.

= What happens when I delete the plugin? =

Saved analyses are kept by default. To remove its tables, settings and test objects too, enable "Delete all SSPA data when the plugin is deleted" on the Compare tab first.

== Screenshots ==

1. Press Run Analysis: the analysis runs (sped up here) and the findings appear, worst first, each naming the plugin responsible.
2. Open a finding: the page's full profile, with request phases, cost per plugin and the slowest queries.
3. Pages: every measured page with its generation time, database time, queries, rows fetched and memory.
4. Plugins: database time, queries and memory per plugin across every measured page.
5. Cache performance: which visitors get cached pages, and why the others do not.
6. Compare: two saved analyses side by side, response time first.
7. Tools: the profiler switch and the server tools that add detail, with the commands for your server.

== External services ==

Nothing is sent unless an administrator asks for it. All three services are run by WP Intense OÜ.

**Community sharing, off by default.** When an administrator turns sharing on, or submits an analysis from the Share tab (which previews the exact evidence first), the plugin registers with collector.superspeedy.org (a random installation identifier, a generated secret, and the plugin, WordPress and PHP versions), then uploads an anonymised archive of timings, counts, plugin and theme versions, page types and sanitised query shapes to Cloudflare R2 storage (ACCOUNT_ID.r2.cloudflarestorage.com). Site addresses, paths, emails, IP addresses, user, order and product identifiers, cookies and request bodies are rejected before sending. [Privacy and terms](https://superspeedy.org/privacy-and-terms/), [Cloudflare privacy policy](https://www.cloudflare.com/privacypolicy/), [Cloudflare R2 terms](https://www.cloudflare.com/service-specific-terms-developer-platform/).

**Community figures.** Only when an administrator presses "Load community figures" on Compare: two read-only downloads, https://superspeedy.org/api/v1/cohorts and https://superspeedy.org/api/v1/plugin-impact. Nothing about the site is sent. Same privacy and terms as above.

**Fix quote requests.** Only when an administrator presses "Request a fix quote", reads the report it will send, ticks consent and presses Send: one request to https://www.superspeedyplugins.com/wp-json/ssp-contact/v1/quote-request with the name, email, optional site address and message, and that page report. [Privacy](https://www.superspeedyplugins.com/privacy-policy/#performance-review-requests), [terms](https://www.superspeedyplugins.com/terms-conditions/).

== Files outside the plugin directory ==

Activation writes nothing outside the plugin folder. Starting an analysis, or Enable profiler in Tools, installs `wp-content/mu-plugins/sspa-loader.php` and, only if none exists, the database drop-in `wp-content/db.php`. Disable profiler or deactivation removes both. Traffic recording adds `wp-content/mu-plugins/sspa-traffic-observer.php` while it is on, and keeps its files in `uploads/super-speedy-performance-analysis/`, closed to web access. DISALLOW_FILE_MODS is honoured, and only files signed by this plugin are ever removed.

== Source code ==

Readable source and build tools: https://github.com/superspeedyplugins/super-speedy-performance-analysis-pro . The history charts use a bundled build of Apache ECharts 6.1.0 (Apache-2.0) with zrender and d3 code (BSD-3-Clause); the licences are in includes/admin/vendor/ and the build source in the repository's .build/echarts/ directory.

== Changelog ==

= 0.68.5 (22nd November 2026) =
* First release on WordPress.org.
* Starting an analysis installs the profiler, so there is no separate Enable profiler step; Disable profiler in Tools keeps it off until you enable it again.
* Fixed the Compare tab saying "Could not load this tab: Unexpected server response." after a site's first analysis; it now says this is the first saved point in time.

[Complete changelog](https://github.com/superspeedyplugins/super-speedy-performance-analysis-pro/releases)
