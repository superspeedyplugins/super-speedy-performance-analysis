# Plugin Impact Analysis

Measures what each plugin actually costs by taking it out and re-measuring.

## What you get to see

A per-plugin, per-page cost map, each cell proven by re-measuring that page with that plugin virtually disabled. A verdict reads like *"adds 120ms typically, up to 340ms on wc-checkout"* rather than one site-wide total that no visitor ever experienced.

Plugins that make your site **faster** are reported as such, with a measured saving, because the noise gate works on the absolute value of the delta rather than assuming every plugin costs something.

## Why this is different from attribution

Attribution says which plugin ran the queries. That's inference from a backtrace, and it's the best any profiler can do from a single request. It can't tell you what would happen if the plugin weren't there, because a plugin's real cost includes work it causes elsewhere and excludes work something else would do anyway.

Plugin Impact Analysis measures the difference. It's the number you'd get by deactivating the plugin, measuring, and reactivating it, done safely and repeatedly.

## Nothing is ever really deactivated

Each suspect is disabled **for the analysis's own test requests only**. No plugin is deactivated, no activation or deactivation routines fire, and visitors get the full site throughout.

Two guards make that a guarantee rather than an intention:

**Dependency groups.** The analysis reads each plugin's own code to find which plugins can't run without another one, and measures those together, so nothing is ever left running without something it depends on. A verdict measured this way says so: *"measured together with seo-by-rank-math-pro, which cannot run without it"*. The picker tells you which plugins will be measured together before you start.

**Reaction guards.** If a plugin reacts to another being excluded during a measurement, its activation and deactivation routines are silenced before they can run, and destructive database statements, `DROP`, `TRUNCATE`, `ALTER ... DROP` and whole-table `DELETE`, are refused for that request. This was built against a real deactivation routine that drops database indexes. Every caught reaction becomes a finding, and the pair is measured together from the next run on, so a reaction can happen at most once per site.

Because the database guard lives in this plugin's own `db.php` drop-in, the run requires that drop-in. Sites where another plugin owns the drop-in can use the temporary swap option.

## You choose what gets measured

It always starts from a chooser. Nothing is measured until you pick the plugins, nothing is preselected, and the chooser tells you the cost before anything runs: *"7 plugins x 1 page = 9 measurements, about 20 seconds"*, with the estimate derived from your own site's last five runs.

Choosing every plugin runs a two-phase sweep. Phase 1 screens every plugin in one cache mode on its busiest pages. Phase 2 gives the full treatment only to the plugins that showed measurable impact, so innocent plugins never cost more than their screening cells.

## How to run it

- **Settings page:** Plugins tab, or the Plugin Impact Analysis button on the shared Super Speedy dashboard.
- **Any single page:** "Measure plugin impact on this page" in the page panel, including a page no analysis has profiled, because it measures that page's own baseline as it goes.
- **WP-CLI:** `wp sspa run --type=deep`, with `--suspects=` and `--pages=` to narrow it. See [[WP-CLI-Reference]].

Start it and walk away. The floating monitor shows where it's up to, and if you close the tab WP-Cron continues the run in the background.

## How to read the result

Every isolated delta has to beat `max(3x sample spread, 30ms)` or it's reported as "no measurable impact". No findings are invented on a jittery host.

With a persistent object cache and the drop-in present, each cell can be measured three ways: object cache disabled, priming, and normal warmed cache. `normal` is the headline number, and the three together show who depends on your cache and who ignores it.

The theme is measured the same way, by swapping to a stock theme for test requests only.

## Requirements and limits

Fragile plugins such as security plugins are never excluded. A fatal response is treated as dependency evidence, and the search re-splits and continues.

A deep sweep is the longest run type in the plugin. `wp sspa run --type=deep` allows up to six hours, and the chooser's estimate is the number to trust for your own site.

The site score comes only from full analyses, not from measuring one page on its own.

---

Related: [[Per-Page-Analysis]] · [[How-It-Measures]] · [[Cache-Optimisation-Analysis]]
