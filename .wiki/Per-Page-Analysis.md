# Per-page analysis

Profiles one exact URL and shows where its time went. Front end or wp-admin, any page on the site.

## What you get to see

For the URL you're looking at:

- **Generation time, SQL time, HTTP time and peak RAM**, as medians across three measured samples after a warm-up.
- **Where the PHP time went, per phase**: core, plugin file loading, plugin boot callbacks, theme setup, init, routing, template render and output. The phases sum to the page's generation time, so nothing is unaccounted for.
- **Per-plugin cost**, in both attribution modes (see below).
- **The slowest queries with the code that ran them**, what `EXPLAIN` says about each one, and click-to-copy for the full statement.
- **The outbound HTTP calls** the page made.
- **The object cache hit rate** for that page.
- **The individual PHP functions by self time** where excimer is installed, attributed to their plugin or theme, including inside theme template files.
- **What's been measured by disabling plugins on this page**, if you've run [[Plugin-Impact-Analysis]] against it.

## Attribution: which code ran, and who asked

Two views of the same measurement, switchable in place with nothing re-profiled.

**Code owner** is the default: whose code executed. **Caller** is the honest answer when a plugin calls a WooCommerce function seventy times in a loop instead of once. That's the calling plugin's fault, not WooCommerce's.

The attribution is shared-library aware. When two plugins bundle the same library, Freemius, Action Scheduler, Guzzle or anything under a `vendor` directory, PHP loads one copy and which plugin owns that copy on disk is decided by autoloader order rather than by whose work it is. A plugin is never charged for a library another plugin called into.

The N+1 finding names where the queries ran as well as who made them: "70 queries, 70 of them inside WooCommerce" is the difference between a plugin being busy and a plugin looping over someone else's API.

## How to run it

- **Admin bar:** the PA menu, Analyse this page. It profiles the URL you're on, front end or wp-admin, while you watch.
- **Pages tab:** click any page row to open the same panel.
- **WP-CLI:** `wp sspa run --type=spot` for the site's key pages. See [[WP-CLI-Reference]].

Click the button again later on the same page and the stored result reopens instantly, with a Re-run button. A badge at the top of the panel says whether you're looking at a cached result or a fresh one, with its age.

Front-end pages are profiled as a logged-out visitor, which is how a real visitor gets them. wp-admin URLs are profiled as an administrator. The panel says which.

## What the measurement does to your site

Nothing that a visitor can see. Profiling requests are signed, cache-busted and sent `Cache-Control: no-store`, so a measurement can never be stored and served to a real visitor. Cache-served responses are discarded via a response canary, so a page cache can't fake a good result, and a page that couldn't be measured is reported as "not measured, cache served it" rather than quietly dropped.

Where no per-query capture was possible, SQL time and row totals are stored as unknown rather than zero. A blind measurement is never mistaken for a page that spent no time in SQL.

## Requirements and limits

Nothing to install for the core numbers. Function-level detail needs the free excimer extension, and rows-examined needs MySQL `performance_schema`: both are covered in [[Server-Capabilities]].

Full SQL is kept for the slowest 20 distinct queries per page. The rest are stored as privacy-safe fingerprints, so `EXPLAIN` plans are available for the slow ones and not for every query on the page.

Profiling requests are loopbacks from the server to itself, and on many hosts those bypass the CDN. Every capture records whether it passed through Cloudflare and whether the country header was present, and the panel says so, because behaviour keyed on those headers differs between the measured path and the visitor path.

---

Related: [[How-It-Measures]] · [[Plugin-Impact-Analysis]] · [[Server-Capabilities]] · [knowledge base: page generation time](https://www.superspeedyplugins.com/kb/performance-optimization/site-owner-tips/wordpress-page-generation-time/)
