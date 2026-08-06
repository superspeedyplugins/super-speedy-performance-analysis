# Run Analysis walkthrough - findings and fixes (24 Jul 2026)

> **Superseded in part, same day:** after this review Dave asked for Deep Analysis to be
> rebuilt as an exhaustive sweep - see "The 0.8.0 sweep redesign" at the end. The bug
> analysis below still stands; the planner/bisection fixes described mid-document were
> then retired along with the planner itself.

Dave reported: Super Speedy Search definitely speeds up wp-admin order search, yet the
plugin reported it ~2 seconds *slower*; plus assorted GUI bugginess around "investigating
individual plugins" (the Measure button) and the full deep sweep, from a session about a
month ago. The local dev DB has no surviving run data from that session, so this is a
code walkthrough of the whole Run Analysis / Deep Analysis flow, with fixes applied.

## Why Super Speedy Search looked "2 seconds slower"

Two compounding problems, both now fixed:

### 1. Speed-up plugins were structurally invisible to Deep Analysis

The isolation planner's sign convention is correct: `delta = baseline (all plugins) -
measurement with the plugin excluded`, so positive = the plugin adds time. For SSS on
order search the delta is strongly **negative** (excluding it makes the page slower).
But `add_impact()` only treated `delta > gate` as measurable - a large negative delta
got `confidence = 'none'` and the GUI said **"no measurable impact"**. A plugin saving
you 2 seconds was reported identically to a plugin doing nothing. The bisect branch
likewise discarded negative singleton deltas.

**Fixed:** the gate is now on `abs(delta)`; savings are measured impacts. The Plugins
tab renders "saves 2,000ms" (green) vs "adds 300ms" (red), and the display no longer
hard-codes a `+` prefix (a negative delta used to render as the garbled `+-2,000ms` -
which read exactly like "+2,000ms slower" and is very plausibly what you saw). Test
`08-isolation-planner.php` now has a speed-up scenario.

### 2. The attribution table blames the worker, not the work

`component_stats` credits each query to whichever component *runs* it. SSS runs the
search query itself, so on the Plugins tab SSS "owns" the entire search SQL time, while
the slow native path it replaced runs on zero pages and is credited to nobody. A search
replacement plugin therefore tops the "SQL total" column precisely because it is doing
the site's heaviest job faster than core would. If your "2 seconds" memory came from the
attribution columns or a slow_query insight naming super-speedy-search, this is it.

This one is **inherent to attribution** and cannot be "fixed" - it is answered by the
measured impact. I've added explanatory copy to the Plugins tab and a "The attribution
trap" section to `.kb/understanding-results.md`. **Needs your input:** should the
community submission payload flag replacement-category plugins (search/filter) so
superspeedy.org ranks them by measured impact rather than attributed SQL? Otherwise the
hub will reproduce the same distortion at scale.

## Measurement robustness fixes (drift)

Deep runs measured each page's baseline once at run start, then measured exclusions up
to minutes later - server drift (MySQL buffer pool, opcache, load) lands directly in the
delta at the 100ms scale the gate is meant to police. Now:

- **Fresh paired baseline**: before every single-out measurement the page baseline is
  re-measured back-to-back (one extra measurement per suspect; the noise gate also
  refreshes from the new spread).
- **Warm-up parity**: isolation warm-ups were unsigned, so they ran the FULL plugin set;
  the first excluded sample then paid cold-cache costs the baseline never paid (biasing
  plugins to look *better* than reality). Isolation warm-ups are now signed with the
  same `ps` flag (capture discarded), warming the actual configuration being measured.

## GUI / flow bugs found and fixed

1. **Runs started from a non-Overview tab were invisible.** `sspa_start_typed_run` set
   `window.location.hash` but never switched tabs (the tab handler only fires on
   clicks), so the Measure button on the Plugins tab appeared to do nothing while a run
   ran hidden. Now switches to Overview explicitly. This is almost certainly the
   "buggy Measure" you remember.
2. **After a deep or cache run, every tab silently switched to that run.** Overview read
   `notes.score` from the deep run (none exists) -> **site score 0, red, "no insights"**;
   Pages showed the deep run's partial, plugin-set-modified profiles; the Plugins
   attribution totals aggregated baseline + excluded measurements together. All those
   views now pin to the latest `baseline`/`spot` run, and Overview gained a "Latest deep
   analysis" summary card so deep results are no longer buried.
3. **Spot checks masqueraded as full baselines.** The plugin-toggle notice's 3-page
   spot run was stored as `run_type = 'baseline'`, becoming the "latest analysis" for
   every tab and the source for deep suspect selection. Page-filtered runs are now
   typed `spot` (the CLI already did this; the GUI path didn't).
4. **Deep source-run selection** could pick a cache-impact run (`run_type != 'deep'`);
   now restricted to `baseline`/`spot`. Suspects whose worst page was a `write-*` or
   `mail-probe` probe would silently fatal (those page_keys can't be rebuilt as
   crawlable jobs); they're excluded from worst-page selection now.
5. **Theme impacts were measured but never displayed** - stored under plugin `'theme'`,
   which matches no component row. Now stored under the real stylesheet slug.
6. **Progress bar** could exceed 100% on deep runs (estimated totals shrink); clamped.
   Start-run AJAX had no `.fail` handler - a network hiccup left the button disabled
   with no feedback; now alerts and re-enables.
7. **Cache impact**: negative "saved %" from sampling jitter clamped to 0.

## Also done

- `delta_http_ms` added to plugin impacts end-to-end (schema 1.2 via dbDelta, planner,
  report, Plugins tab) - completes your metric list: RAM, queries, PHP time (derivable:
  generation - SQL - HTTP), SQL time, HTTP time per plugin.
- The measured-impact cell now shows the secondary deltas (SQL / queries / RAM / HTTP),
  and "no measurable impact" states the noise floor it was inside.
- `.docs/agent-api.md` now defines the delta sign convention explicitly (an LLM reading
  `delta_generation_ms: -2000` with no documented convention could easily report it as
  "2 seconds slower" - the other candidate for your original report).
- KB articles updated: attribution trap, negative impacts, methodology drift controls,
  gotchas list in getting-started.

## The 0.8.0 sweep redesign (same day, after Dave's feedback)

Dave's verdicts: measure every plugin on EVERY page (not just its worst page); with an
object cache, measure each cell three ways (no cache / priming / warm); one button,
walk away, visible progress with an ETA; no surprise page refreshes; savings are
first-class results. Implemented as:

- **Sweep engine** replaces the adaptive planner + bisection entirely (planner class
  and its unit test deleted - the sweep measures every eligible plugin on every page
  directly, which strictly supersedes bisection's cheap-search purpose). Deterministic
  page-major queue: baseline cell, then each plugin cell, re-baselining every 5 cells
  for drift control. Eligibility unchanged (dependency roots + fragile list protected).
- **Cache modes**: with a persistent object cache AND our db.php shim: disabled
  (per-request `enable_loading_object_cache_dropin` filter), prime (first cache-enabled
  request, no warm-up, single sample), warm (after warm-up, 3 samples). Honest caveat
  documented: the live cache is never flushed, so "prime" is first-hit-against-current-
  cache-state, not guaranteed-cold. plugin_impacts gained `object_cache_mode` (schema
  1.3); one impact row per plugin x page x mode.
- **Floating run monitor**: minimisable popover on all SSPA tabs; shows plugin/page/
  mode being tested, progress, elapsed, ETA (trivial now the queue is deterministic);
  resumes on reload; cancel; transient AJAX failures retry with backoff instead of
  reloading the page. Runs no longer force tab switches or surprise refreshes; a single
  reload happens on completion to render results.
- **Long-run safety**: staleness by progress (re-kick at 30 min idle, fail at 3 h);
  `wp sspa run --type=deep` deadline 6 h.
- **Plugins tab**: net adds/saves across pages (warm mode preferred) + biggest
  cost/saving, with a per-page x per-mode drill-down grid (`sspa_plugin_detail` AJAX).

Costing note: a sweep is pages x plugins x modes cells, ~4-6 requests per cell. 20
pages x 10 plugins x 3 modes ≈ 3,000+ requests - an hour or two on a typical site. That
is the accepted price of thoroughness; the monitor's ETA makes it predictable.

## 0.9.1: the two-phase sweep (same day, field feedback)

First real-world run (client site, ~many plugins x ~many pages x 3 modes) produced
11,280 queued measurements and a ~42 hour ETA - the full cross-product is unusable at
scale. Dave's call: one measurement per plugin first, then go deep. Restructured:

- **Phase 1 (screen)**: every plugin x 1 cache mode (normal) x its top
  `SWEEP_SCREEN_PAGES` (2) attributed pages + the site's slowest page, 2 samples per
  cell. Plugins with no attributed queries screen on home + slowest (hook-only costs
  leave no query trail). Theme: home + slowest anon page.
- **Phase 2 (confirm)**: at the phase boundary the queue self-extends
  (`sweep_extend_phase2`) for plugins with >= 1 measured screening cell: all remaining
  pages (normal, 3 samples) + disabled/prime on their screened pages when oc-capable.
  No impacted plugins -> run finishes after the screen.
- **Mode relabel**: `warm` retired; `normal` (cache as-is, warmed) is the headline.
  Sites keep 3-mode data (`normal`/`disabled`/`prime`) but only for guilty plugins.
- Targeted runs (Measure button / `--suspects`) still cover all pages in phase 1, with
  cache modes added in phase 2 if impacted.
- The monitor shows "phase 1/2: screening all plugins" and phase-scoped ETA.

Trade-off accepted: a plugin whose cost lives ONLY on a page outside its screening set
(no attributed queries there, not the slowest page, not home) can slip through the
screen. The screen's page choice is attribution-informed to make that rare; if it ever
matters, the per-plugin Measure button still does the full every-page treatment.

## Open questions for Dave

1. **Hub ranking semantics** (above): flag replacement plugins in the submission payload?
2. **Baseline sample count**: 3 samples with a 1-request warm-up is thin for wp-admin
   pages on busy sites. An adaptive scheme (keep sampling until spread stabilises, cap
   at ~7) would tighten gates at modest request cost. Worth it?
3. ~~Bisection ignores savings by design~~ **Resolved (Dave, 24 Jul): savings are too
   useful to ignore** - and then mooted the same day by the 0.8.0 sweep redesign, which
   retired bisection entirely: every plugin is now measured directly on every page, so
   savers surface with no cancellation blind spot.
