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

## Comparing points in time

The first History chart automatically compares the **Current setup** with the **Previous setup**.
A setup is the active plugins and theme plus the exact versions captured when each analysis ran.
If three plugins are updated together, that starts one new setup period; Performance Analysis does
not guess how an unmeasured combination would have behaved. Returning to an older combination
later starts another period rather than merging separate dates.

Each key page shows every valid retained request-time point and the median for both setup periods.
Use the Metric control for generation time, database time, query count, outbound HTTP time, or peak
memory. Those five views use one saved per-run median because older rows do not retain all of their
raw samples. Blocked requests, transport errors, HTTP errors and missing measurements keep distinct
labels, use a separate fault marker and never count towards a median. **View chart data** exposes the
same points, medians, changes and evidence states as a table.

The History tab can compare any two completed full scans or spot checks. **Response time is
the headline**, because Performance Analysis is primarily a performance tool. A newly observed
fatal, transport/HTTP failure, warning, or failed validity check is shown ahead of timing because
a very fast error page is not an improvement.

Open **Setup changes** in the comparison to see plugins or themes added, removed, or moved between
versions. Those identities come from the two completed runs, so the report shows the setup that
was actually measured rather than today's installed versions.

**Configuration changes** appear when a plugin has deliberately published a small privacy-checked
state declaration to Performance Analysis. PA never dumps another plugin's option table or guesses
which free-text settings are safe.

Performance Analysis also compares a normalised hash of each stable response. It stores no HTML
for this check. **Changed** means the visible/meaningful output differed and should be reviewed;
it does not automatically mean the site is wrong. Products can go out of stock, catalogue data
can change, and ranking rules can be intentionally adjusted. When a stable result is important,
**Use After as expected** turns that learned signature into a lightweight declared check for
future comparisons.

Plugin-change detection is enabled by default. After plugin updates, activation, or deactivation,
the admin notice offers a quick comparison and explicitly tells you to finish any remaining
updates first. The notice shows the exact compatible earlier analysis it will use before you start;
if none exists, it explains that the new run will become the first saved comparison point. Detection
can be disabled under **History → Advanced history settings**. The updater request only records the
changed plugin/version; it never runs an analysis itself.

The comparison's privacy-safe evidence must be previewed before it can be downloaded. It contains
run/component identities, measurements, cases, diagnostic fingerprints, and output-change state;
it excludes response bodies, URLs, cookies, nonces, personal data, SQL text, and filesystem paths.
Downloading this local file does not enable community sharing.

For local automation, `wp sspa history-compare <before-run-id> <after-run-id>` and the readonly
`compare-history` ability return that same versioned evidence contract without contacting a
remote service. This is the PA input intended for Release Confidence and other test runners.

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
