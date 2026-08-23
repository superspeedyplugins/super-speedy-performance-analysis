# How it measures

The method, for anyone who wants to know whether to trust the numbers.

## Inert until asked

The capture layer is a conditional `db.php` drop-in plus an mu-plugin loader. On a request with no profiling token the loader reads two request values and returns, and the drop-in returns before creating `$wpdb`, so WordPress instantiates its own stock class. Per-query overhead on ordinary traffic is zero.

Profiling requests carry a signed token: an id, an expiry and flags, HMAC'd against the install's own secret over the request URI. A forged token doesn't verify, and each token is single-use.

Query Monitor isn't required and isn't a dependency.

## Sampling

A warm-up request, then three measured samples per page, medians reported. Every request carries a unique cache-busting argument signed into the token so it can't be stripped, which guarantees the request reaches PHP rather than being answered by nginx, Varnish, LiteSpeed or a CDN.

Profiled responses send `Cache-Control: no-store`, so a measurement can never be stored and served to a real visitor. Cache-served responses are discarded via a response canary.

A page that still couldn't be measured is reported loudly: a warning on the Overview counts them, and the Pages tab marks each one "not measured, cache served it". Where no per-query capture was possible, SQL time and row totals are stored as unknown rather than zero, so a blind measurement is never mistaken for a page that spent no time in SQL.

There's no client-side response deadline. The analysis waits as long as your server, PHP, proxy and operating system allow. On the pages most worth diagnosing, a tool that gives up first is a tool that tells you nothing.

## Attribution, and the trap in it

Per-query attribution comes from backtraces: row counts, errors and the owning plugin or theme, captured per query. Full SQL is kept for the slowest 20 distinct queries per page, and the rest are stored as privacy-safe fingerprints.

The trap is shared libraries. When two plugins bundle the same library, Freemius, Action Scheduler, Guzzle or anything under a `vendor` directory, PHP loads exactly one copy, and which plugin owns that copy on disk is decided by autoloader order rather than by whose work it is. Every other tool charges the owning plugin. This one follows the call chain past the shared library to the plugin that actually called into it.

Two views of the same data, switchable with nothing re-profiled: **code owner**, whose code ran, and **caller**, who asked for the work. The second is the honest answer when a plugin calls a WooCommerce function seventy times in a loop.

## Where the flat cost goes

The answer to "every page is slow but no single plugin shows up". Many sites pay a flat PHP cost on every request, plugin loading plus init hooks, spread across dozens of plugins in slices too small for one-at-a-time exclusion to see.

Every profiled page records the request broken into phases: core, plugin file loading, plugin boot callbacks, theme setup, init, routing, template render and output. The phases sum to the page's generation time, so nothing is unaccounted for. Each plugin's individual PHP cost is recorded, file load time plus its callbacks on the expensive hooks, along with the slowest single hook callbacks by name.

That instrument runs only on the analysis's own signed requests and adds two clock reads per callback there.

## Query plans

Captured queries go through MySQL's `EXPLAIN` after profiling, so a slow query is reported with the reason it's slow, no usable index, a temporary table, a filesort, rather than only the fact that it was slow. It needs nothing installed and no extra database permission.

It never executes the query. `EXPLAIN ANALYZE` would, and is deliberately not used. `SELECT` statements only, one statement only, and never on a stored fingerprint, whose literals have been replaced and whose plan would be fiction. It runs during analysis, never inside a profiled request, so it can't affect a measurement.

Row counts from `EXPLAIN` are the optimiser's estimate, not a measurement, and the UI says so on every line that shows one. For measured rows examined, see [[Server-Capabilities]].

## The measurement path, including the gap

Profiling requests are loopbacks from the server to itself, and on many hosts those bypass the CDN, arriving without the headers Cloudflare adds for real visitors. Behaviour keyed on those headers then differs between the measured path and the visitor path. WooCommerce's MaxMind lookup, for example, only runs when no country header is present.

Every capture records whether it passed through Cloudflare and whether the country header was present, and the panel says so, including a note when Cloudflare was traversed but IP Geolocation is switched off.

That's a known systematic error in the method, named rather than hidden, and it's why the number on a page can differ from what a real visitor experiences.

## Measured, not inferred

Everything above is still attribution. [[Plugin-Impact-Analysis]] is the part that measures: take the plugin out, re-measure, report the difference, with a noise gate of `max(3x sample spread, 30ms)` so nothing is invented on a jittery host.

---

Related: [[Per-Page-Analysis]] · [[Plugin-Impact-Analysis]] · [[Server-Capabilities]] · [knowledge base: how to analyse slow MySQL 8 performance](https://www.superspeedyplugins.com/kb/performance-optimization/developer-tips/how-to-analyse-slow-mysql-8-performance/)
