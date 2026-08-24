# Understanding Your Results

## The site score

100 minus penalties for findings (critical findings cost more than warnings). [Methodology](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/advanced/methodology/) sets out exactly how the numbers are produced. Above 80: healthy. 50-80: real problems worth fixing. Below 50: your visitors are feeling it.

## Finding types

- **Slow query** - one query over 50ms, attributed to a plugin/theme, with its shape classified: `wp_postmeta` scans, `SQL_CALC_FOUND_ROWS`, `ORDER BY rand()`, leading-wildcard LIKE, nested taxonomy joins. The shape drives the recommendation.
- **Large result set** - a query fetching 200+ rows. Every row is hydrated into PHP memory, so these are the usual cause of high RAM per visit.
- **Queries in a loop (N+1)** - one component running 50+ queries on a page. The count grows with your content, so this gets worse every month.
- **Duplicate queries** - the same query byte-for-byte, run repeatedly in one page load. The component is missing basic caching.
- **Blocking HTTP call** - a plugin calls a remote server while your page renders (licence checks, geo lookups). Every visitor waits for it.
- **Ignores the object cache (cache-blind)** - from a Cache Impact run: the plugin runs identical queries with Redis on and off. Your caching investment does nothing for it.
- **Slow email construction** - building an email blocked the request; order status changes and checkout wait on this.
- **Autoloaded options bloat / environment findings** - site-level issues: options loaded on every request, outdated PHP, missing object cache on a large database.

## Inferred vs measured

Attribution from query backtraces is **inferred** - accurate, but circumstantial. Plugin Impact Analysis upgrades suspects to **measured**: the page is re-profiled with the plugin virtually disabled for the test requests only, and the difference is the plugin's proven cost. Anything below the measurement noise floor is honestly reported as "no measurable impact" - the plugin is not guilty on this site, whatever its reputation.

A measured impact can be **negative** - shown as "saves Xms" in green. That means the page got *slower* when the plugin was excluded: the plugin is actively speeding your site up. This is normal for performance plugins that replace a slow core or WooCommerce feature (search, filtering, archives).

## The attribution trap (read this before blaming a plugin)

The SQL/query columns credit work to **whichever component runs it**. A plugin that *replaces* a slow feature - say a search plugin that takes over product or order search - runs the search query itself. The search time then appears under *its* name, while the slow native code it replaced does not run at all and is credited to nobody. On the attribution columns alone, the plugin making your search fast can look like your biggest SQL spender.

The **Measured impact** column is the antidote: it compares the real page with and without the plugin. If a plugin's attribution looks expensive, click **Measure** and trust the measured verdict - "adds" means it genuinely costs you time, "saves" means it is earning its keep.

## Special pages

- **baseline** - a near-empty request measuring your server's noise floor.
- **mail-probe** - the cost of building (not sending) an email through your mail stack.
- **write-save-product / write-order-processing** - opt-in: the full save/status-change hook cascade, measured against temporary objects that are deleted immediately after.

## Further reading

- [Methodology](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/advanced/methodology/) - exactly how the numbers are produced
- [Function-Level Profiling](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/features/function-level-profiling/) - go past the plugin name to the function inside it
- [Quick Start Guide](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/quick-start-guide/) - running your first analysis
