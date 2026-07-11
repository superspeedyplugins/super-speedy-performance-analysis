# Understanding your results

## The site score

100 minus penalties for findings (critical findings cost more than warnings). Above 80:
healthy. 50-80: real problems worth fixing. Below 50: your visitors are feeling it.

## Finding types

- **Slow query** - one query over 50ms, attributed to a plugin/theme, with its shape
  classified: `wp_postmeta` scans, `SQL_CALC_FOUND_ROWS`, `ORDER BY rand()`,
  leading-wildcard LIKE, nested taxonomy joins. The shape drives the recommendation.
- **Large result set** - a query fetching 200+ rows. Every row is hydrated into PHP
  memory, so these are the usual cause of high RAM per visit.
- **Queries in a loop (N+1)** - one component running 50+ queries on a page. The count
  grows with your content, so this gets worse every month.
- **Duplicate queries** - the same query byte-for-byte, run repeatedly in one page load.
  The component is missing basic caching.
- **Blocking HTTP call** - a plugin calls a remote server while your page renders
  (licence checks, geo lookups). Every visitor waits for it.
- **Ignores the object cache (cache-blind)** - from a Cache Impact run: the plugin runs
  identical queries with Redis on and off. Your caching investment does nothing for it.
- **Slow email construction** - building an email blocked the request; order status
  changes and checkout wait on this.
- **Autoloaded options bloat / environment findings** - site-level issues: options loaded
  on every request, outdated PHP, missing object cache on a large database.

## Inferred vs measured

Attribution from query backtraces is **inferred** - accurate, but circumstantial. Deep
Analysis upgrades suspects to **measured**: the page is re-profiled with the plugin
virtually disabled for the test requests only, and the difference is the plugin's proven
cost. Anything below the measurement noise floor is honestly reported as "no measurable
impact" - the plugin is not guilty on this site, whatever its reputation.

## Special pages

- **baseline** - a near-empty request measuring your server's noise floor.
- **mail-probe** - the cost of building (not sending) an email through your mail stack.
- **write-save-product / write-order-processing** - opt-in: the full save/status-change
  hook cascade, measured against temporary objects that are deleted immediately after.
