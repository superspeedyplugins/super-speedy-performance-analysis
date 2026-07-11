# Methodology - how the numbers are made

Credibility is the product, so here is exactly how measurement works.

## Sampling

Every page is requested once as a warm-up (priming caches), then three measured times.
We report the **median**, keep the spread, and measure a near-empty baseline request to
know your server's noise floor. Responses served by a page cache are detected (via a
per-request token echoed in a response header) and discarded - a cached page tells us
nothing about PHP.

## Capture

For profiling requests only, a conditional `db.php` drop-in swaps in an instrumented
database class recording every query's duration, returned row count, errors and call
stack. The call stack maps each query to the plugin, theme or core code that ran it.
HTTP API calls, object-cache hits/misses, peak memory and emails are captured alongside.
For normal traffic the drop-in does nothing - zero overhead.

Full SQL is kept locally only for the slowest/biggest queries; everything else is stored
as a normalised fingerprint with all values stripped.

## Isolation (Deep Analysis)

To prove a plugin's cost we re-profile its worst page with the plugin **virtually
disabled for the test requests only** - a per-request filter, not a real deactivation.
Visitors always get the full site; no activation/deactivation hooks fire. A response
canary verifies the override applied before a measurement counts.

The measured delta must beat max(3 x sample spread, 30ms) - otherwise we report "no
measurable impact" rather than inventing precision. Where per-query attribution misses
smeared costs, the slowest page is bisected across all eligible plugins (dependency-aware:
plugins that others require are never removed from under them).

## Cache impact

With Redis/Memcached present, pages are profiled with the object cache on and off (again
per-request). A plugin whose query count is identical either way is cache-blind; one
whose queries mostly disappear is cache-friendly.

## What is never done

Profiled requests never send email (recipients are stripped before any transport work),
never change your live plugin set, and - unless you explicitly enable write profiles -
never write anything. Write profiles use temporary duplicates that are deleted
immediately after measurement.
