# Methodology

Credibility is the product, so here is exactly how measurement works. For what the resulting findings mean, see [Understanding Your Results](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/understanding-your-results/).

## Sampling

Every page is requested once as a warm-up (priming caches), then three measured times. The plugin reports the **median**, keeps the spread, and measures a near-empty baseline request to establish your server's noise floor. Responses served by a page cache are detected (via a per-request token echoed in a response header) and discarded - a cached page says nothing about PHP.

## Capture

For profiling requests only, a conditional `db.php` drop-in swaps in an instrumented database class recording every query's duration, returned row count, errors and call stack. The call stack maps each query to the plugin, theme or core code that ran it. HTTP API calls, object-cache hits/misses, peak memory and emails are captured alongside. For normal traffic the drop-in does nothing - zero overhead.

Full SQL is kept locally only for the slowest/biggest queries; everything else is stored as a normalised fingerprint with all values stripped.

## Isolation (Plugin Impact Analysis - the two-phase sweep)

Plugin Impact Analysis measures each plugin by re-profiling pages with that one plugin **virtually disabled for the test requests only** - a per-request filter, not a real deactivation. Visitors always get the full site; no activation/deactivation hooks fire. A response canary verifies the override applied before a measurement counts. Eligibility is dependency-aware: plugins that other active plugins require, and fragile plugins (security etc), are never excluded behind your back.

It runs in **two phases**, so thoroughness does not mean measuring everything everywhere:

1. **Screen** - every eligible plugin is measured in one cache mode on a handful of pages: its top pages by attributed SQL+HTTP time plus the site's slowest page (a plugin with no attributed queries is screened on the home page and slowest page, since hook-only costs leave no query trail). Two samples per cell - fast.
2. **Confirm** - only plugins that showed a measurable impact in the screen graduate to the full treatment: every remaining page (three samples), plus - with a persistent object cache and the SSPA `db.php` shim - **cache-disabled** (persistent cache bypassed for that request only) and **cache-priming** (the first cache-enabled request, no warm-up) measurements on their screened pages. Innocent plugins never cost more than their screening cells.

The headline number is the **normal** mode: the object cache in its natural, warmed state - what real visitors experience. One honest caveat on priming: the live cache is never flushed (that would hurt real visitors), so "priming" measures the first hit against whatever cache state exists, not a guaranteed-cold cache.

Both phases run page-major with a fresh baseline at the start of each page block, re-measured every five plugin cells, so long runs cannot let server drift masquerade as plugin cost. Excluded-set measurements get their own warm-up request so their first sample does not pay cold costs the baseline never paid.

Each cell's measured delta must beat max(3 x baseline sample spread, 30ms) in *absolute value* - otherwise that cell honestly reports "no measurable impact". The delta is signed: positive means the plugin adds time, negative means the page was slower without it (the plugin saves time).

## Cache impact

With Redis/Memcached present, pages are profiled with the object cache on and off (again per-request). A plugin whose query count is identical either way is cache-blind; one whose queries mostly disappear is cache-friendly.

## What is never done

Profiled requests never send email (recipients are stripped before any transport work), never change your live plugin set, and - unless you explicitly enable write profiles - never write anything. Write profiles use temporary duplicates that are deleted immediately after measurement.

## Further reading

- [Understanding Your Results](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/understanding-your-results/) - reading the findings the method produces
- [Installing the Optional Profiling Extras](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/setup-server/installing-profiling-extensions/) - what the optional extras add to the measurement
- [The Archive Query Profile](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/features/archive-query-profile/) - the contract for the archive half of a run
