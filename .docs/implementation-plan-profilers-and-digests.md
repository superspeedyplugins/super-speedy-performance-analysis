# Implementation plan - MySQL query fingerprints and function-level profiling

Status: **planned, not started.** Written July 2026.
Background and tool survey: `.docs/third-party-perf-tool-integration.md`.

Adds two capabilities to SSPA:

- **A. MySQL query fingerprints** - real `rows examined`, index usage, temp-table and filesort
  behaviour per normalised query, from `performance_schema` where available and `EXPLAIN`
  where not.
- **B. Function-level profiling** - which *function* inside a plugin is burning the time, via
  the `excimer` and XHProf-family PHP extensions.

Plus **C**, the Tools tab that detects what the server supports and hands the user exact
installation steps.

---

## 0. The blocker to fix first: shared-library misattribution

Dave flagged this from Code Profiler Pro: when two plugins bundle the same library, the wrong
plugin gets blamed. **SSPA has this bug today.** It is not hypothetical and it is not confined
to the new features.

`profiler/class-sspa-component-map.php:73-92`, `attribute()`, walks the stack innermost-first
and returns the **first non-core frame**. So:

```
frame 0  wp-content/plugins/plugin-a/vendor/guzzlehttp/guzzle/src/Client.php   <- returned
frame 1  wp-content/plugins/plugin-b/src/Api.php                               <- the real culprit
frame 2  wp-includes/class-wp-hook.php
```

PHP loads exactly one copy of a shared library. Whichever plugin's autoloader registered the
class first owns the file on disk, so **plugin A is charged for work plugin B asked for.**
Real cases on real WordPress sites:

- **Freemius SDK** - bundled by hundreds of plugins, has explicit "newest version wins"
  leader election, so the winner absorbs everyone's licensing overhead.
- **Action Scheduler** - bundled by WooCommerce, WP All Import, and many others.
- **Composer vendor trees** - Guzzle, Monolog, Carbon, league/*, and anything not prefixed.
- **ACF or Carbon Fields bundled inside a plugin or theme.**

Note this is *not* simply "ignore `/vendor/`". A plugin's private vendor tree used only by
that plugin genuinely is that plugin's cost. The distinction is **who called in**, not where
the file lives.

### The fix: record the executor and the initiator, decide policy at report time

Rewrite `attribute()` to keep walking past the innermost non-core frame and return both ends
of the boundary:

```
executor  = first non-core, non-SSPA frame          (where the time is being spent)
initiator = next frame belonging to a DIFFERENT component, still non-core
            (who asked for it)
```

Then:

| Case | executor | initiator | Attributed to | `via` |
|---|---|---|---|---|
| Plugin B calls Plugin A's bundled Guzzle | plugin-a | plugin-b | **plugin-b** | `guzzle (in plugin-a)` |
| Plugin A calls its own private vendor tree | plugin-a | plugin-a | plugin-a | - |
| WooCommerce schedules its own action | woocommerce | woocommerce | woocommerce | - |
| WP All Import schedules via Woo's Action Scheduler | woocommerce | wp-all-import | **wp-all-import** | `action-scheduler (in woocommerce)` |

The redirect to `initiator` applies **only when the executor frame sits in a shared-library
path**, detected by directory name: `vendor`, `vendor_prefixed`, `vendors`, `freemius`,
`packages`, `third-party`, `thirdparty`.

**Correction made during implementation.** The first draft of this plan also proposed an
empirical detector - "any directory entered by two or more distinct components is shared,
whatever it is called" - and called it the more reliable of the two. It is not, and it was
dropped. Applied broadly it re-blames every ordinary cross-plugin API call: plugin B calling
`wc_get_product()` enters WooCommerce's `includes/` from a second component, which would mark
WooCommerce's own API surface as a shared library and charge its cost to every plugin that
calls it. WooCommerce would look artificially clean and the callers would look artificially
expensive. That is a new misattribution bug, not a fix for the old one.

Directory naming is the conservative signal precisely because `vendor/` and friends assert
"this is not this component's own code". Note also that bare `lib`, `libs` and `libraries` are
**excluded** from the marker list: plugins commonly use those for their own code, and a false
positive here re-blames the wrong plugin, which is the exact bug being fixed.

The empirical signal is still worth collecting later, but for **reporting** ("this library is
bundled by 3 active plugins") rather than for deciding attribution.

Store all three fields on every attributed query, HTTP call and profile frame:
`component` (the policy answer), `via_component` (the executor, when it differs), and
`shared` (bool). Keeping the raw pair means the policy can be changed later without
re-profiling anything, and the UI can show "WP All Import, via Action Scheduler bundled in
WooCommerce", which is the honest and far more useful sentence.

### Why this matters less than it looks, and why we say so

SSPA's **measured impact is immune to the whole problem.** Virtually disabling plugin B
removes plugin B's calls into Guzzle no matter whose `vendor/` directory Guzzle lives in.
Attribution is a hypothesis; measured impact is an experiment.

That is already SSPA's architecture and it is the honest answer to the Code Profiler flaw:
**attribution can be wrong, so never publish an attribution number without the measured number
beside it.** The UI must keep doing this, and any report sent to a plugin author (section 6 of
the tool-integration doc) must lead with measured impact, not attribution.

**Do this phase first.** Everything below inherits it.

---

## A. MySQL query fingerprints

Three sources, in order of availability. Build them in this order so every user gets
something.

### A0. `EXPLAIN` enrichment - works everywhere, no install, no grant

For each distinct captured `SELECT` fingerprint, run `EXPLAIN` (never `EXPLAIN ANALYZE`, which
executes the query) and record `type`, `key`, `rows`, `filtered` and `Extra`. That yields
`Using filesort`, `Using temporary`, `Using where; Using join buffer`, and full-table-scan
detection, on any host with an ordinary DB user.

- Run it **after** the profiling run, from the admin request, against stored fingerprints.
  Never inside a measured request.
- The fingerprint has literals stripped to `?`, so re-bind representative values before
  `EXPLAIN`. Where SSPA kept the full SQL (slowest/biggest queries only) use that verbatim;
  otherwise skip. Do not invent values.
- Row estimates are optimiser estimates, not truth. Label them as such in the UI.

### A1. `performance_schema` digests - the real numbers

`performance_schema.events_statements_summary_by_digest` gives MySQL's own normalised
fingerprints with **actual** rows examined and sent, not estimates. This is the same
normalisation the paid APMs charge for.

**Detection ladder** (each step gives a specific, fixable message):

```sql
SHOW VARIABLES LIKE 'performance_schema';                    -- ON / OFF
SELECT 1 FROM performance_schema.events_statements_summary_by_digest LIMIT 1;  -- grant check
SELECT CURRENT_USER();                                       -- to generate the GRANT
SHOW VARIABLES LIKE 'performance_schema_digests_size';       -- overflow risk
```

Verified on the local dev install (MariaDB 12.1.2): step 2 returns
`ERROR 1142: SELECT command denied to user 'superspeedy'@'localhost'`. So the denial path is
the **normal** case, not an edge case, and the UI must treat it as such. MariaDB additionally
ships `performance_schema` **off by default**, needing a `my.cnf` change and a restart.

**Capture**: snapshot the digest table immediately before the profiling run and immediately
after, then diff the counters. Absolute totals are useless; the delta is the run.

Columns worth taking: `DIGEST`, `DIGEST_TEXT`, `COUNT_STAR`, `SUM_TIMER_WAIT`,
`SUM_ROWS_EXAMINED`, `SUM_ROWS_SENT`, `SUM_NO_INDEX_USED`, `SUM_CREATED_TMP_DISK_TABLES`,
`SUM_SELECT_FULL_JOIN`, `SUM_SORT_MERGE_PASSES`.

**Gotchas that will bite:**

- `SUM_TIMER_WAIT` is in **picoseconds**. Divide by 1e9 for milliseconds.
- The table is **server-wide**. On a shared DB server it includes other sites and other
  requests. Mitigation below.
- Filter on `SCHEMA_NAME` = the WordPress database.
- The digest table has a fixed size (`performance_schema_digests_size`). On overflow, rows
  collapse into a single `DIGEST IS NULL` bucket. Detect it and warn rather than reporting
  nonsense.
- Requires only `SELECT`. Never ask for anything else, and never issue `TRUNCATE` on it, which
  would destroy data the site owner or their host may be relying on.

**Joining MySQL digests to SSPA's own captured queries.** This is what makes it useful and
what defuses the server-wide-noise problem: only enrich queries SSPA already saw and already
attributed to a component. Everything unmatched is reported separately as "other traffic on
this database server", which is itself a useful finding.

The join key: run `sspa_sql_fingerprint()` (`profiler/fingerprint.php`) over **both** sides.
MySQL's `DIGEST_TEXT` already parameterises literals to `?`, and our fingerprint does the
same, so the shapes converge. Add a shared pre-pass to both sides before hashing: uppercase
keywords, strip backticks, collapse whitespace. Where an exact match fails, fall back to
matching on the first 64 characters of the normalised text and mark the match `fuzzy` in the
UI. Never silently present a fuzzy match as exact.

### A2. Slow query log + `pt-query-digest` - optional import

Where the user already has the slow log on, accept a pasted or uploaded `pt-query-digest`
output and fold it in. Parsing only, nothing to install on our side. Low priority.

---

## B. Function-level profiling

### Which extension, and why not just one

| | `excimer` | `tideways_xhprof` / `xhprof` | `spx` |
|---|---|---|---|
| Licence | Apache 2.0 | Apache 2.0 | GPL-3 |
| Method | Sampling | Tracing, every call | Tracing |
| Data | **Full stack per sample** | Parent→child edges, **stack context lost** | Full stack |
| Call counts | Statistical only | **Exact** | Exact |
| Overhead | Negligible, runs on Wikipedia in production | High, distorts timings | Moderate |
| Own UI | No | No | Yes, good |

**Answering "will XHProf be enough": no, not on its own, and the reason is exactly Dave's
shared-vendor flaw.** XHProf aggregates per caller/callee pair and discards the full stack. Its
parent→child edges *do* split a shared library's cost by immediate caller, which already beats
path-based blame. But when a vendor tree calls several levels into itself before doing the
work, the deep frames' parents are other vendor frames, and reaching the plugin boundary means
apportioning cost back up a graph where functions have multiple parents. That is an
approximation, and approximations are what produced the Code Profiler flaw in the first place.

Excimer records a **complete stack for every sample**, so the attribution walk in section 0
runs exactly, with no apportioning at all. It is also the only one of the three safe to leave
running on a live site.

What XHProf gives that Excimer cannot: exact call counts. "Called 47 times" is the difference
between a plugin author fixing an N+1 and ignoring the report.

**So: build one collector interface, two implementations.** Each collector is small - enable,
disable, hand back a normalised structure. The abstraction is the deliverable; the collectors
are roughly 100 lines each.

- **Excimer**: default. Runs during the normal profiling pass, because its overhead does not
  distort the timings SSPA is simultaneously measuring.
- **XHProf-family**: an explicit "deep dive this page" button. Its overhead **must never be on
  during a measured-impact run** or it will pollute the very numbers that make SSPA credible.
  Enforce that in code, not documentation.
- **SPX**: detect and link to its own UI. Do not re-render its data, and do not vendor GPL-3
  code into a GPLv2-or-later plugin.

### Capture design

SSPA already runs signed loopback profiling requests through its own mu-plugin, so profiling
is enabled for **those requests only**. Nothing changes for visitors. Wire the collector into
`profiler/bootstrap.php` alongside the existing capture.

Normalised output, one row per function per profile:

```
function, file, line, component, via_component, shared,
incl_ms, excl_ms, calls (xhprof) | samples (excimer), collector
```

Attribution runs the **section 0 walk** over each Excimer stack, or over each XHProf edge with
graph propagation, and stores `component` / `via_component` / `shared` exactly as the SQL path
does. One attribution policy, one place, both data sources.

### Reporting

Extend the existing per-plugin drill-down on the Plugins tab. It currently says "WooCommerce
Product Filters adds 320ms". With this it says:

> **320ms measured**, of which 280ms is in `WCPF\Query::build_meta_clause()`, called 47 times.
> 41ms of that is inside `guzzlehttp/guzzle` bundled in *another* plugin, charged here because
> this plugin initiated the calls.

Measured impact stays the headline number. Function detail is evidence, not verdict.

---

## C. The Tools tab

A new tab listing every capability as a card: **Available**, **Not installed**, or **Blocked
by your hosting**.

Detection: `extension_loaded()` for `excimer`, `tideways_xhprof`, `xhprof`, `spx`,
`opentelemetry`, `newrelic`, `ddtrace`, `blackfire`; the `performance_schema` ladder from A1;
slow-log variables; and whether `EXPLAIN` works.

**Install steps popover.** A button per card opening a popover with steps generated for *this*
server, not generic docs:

- OS and distribution from `php_uname()`.
- PHP version and `PHP_INT_SIZE` / ZTS status, because `pecl install` needs the right
  toolchain and the extension must match the ZTS/NTS build.
- The ini scan directory from `php_ini_scanned_files()` and `PHP_CONFIG_FILE_SCAN_DIR`, so we
  name the exact file to create rather than saying "edit php.ini".
- SAPI from `php_sapi_name()` to pick the right restart command.
- A copy button per command block.
- **"Send these steps to my host"** - generates a plain-text message the user can paste to
  support. For the shared-hosting majority this is the only route that ends in success, and it
  costs almost nothing to build.

Hard rules, stated in the plan so nobody is tempted later:

- SSPA **never** edits `php.ini`, **never** runs `pecl`, **never** shells out to a package
  manager, and **never** restarts anything.
- SSPA **never** ships a compiled extension in its zip.
- Every command shown is displayed for the user to run, with the reason it is needed.

---

## Schema

Two new tables, one column set added to existing ones.

```
sspa_sql_digests
  run_id, profile_id, fingerprint_hash, digest_text,
  calls, total_ms, rows_examined, rows_sent,
  no_index_used, tmp_disk_tables, full_joins, sort_merge_passes,
  source ENUM('performance_schema','explain','slow_log'),
  match_quality ENUM('exact','fuzzy','unmatched')

sspa_profile_frames
  run_id, profile_id, function, file, line,
  component, via_component, shared,
  incl_ms, excl_ms, calls, samples,
  collector ENUM('excimer','xhprof','tideways_xhprof')
```

Add `via_component VARCHAR(191) NULL` and `shared TINYINT(1)` to the existing query, HTTP call
and component-stat tables. Bump `SSPA_Schema` version and add the upgrade path in
`SSPA_Install::maybe_upgrade()`.

---

## Phasing

| Phase | Work | Why this order |
|---|---|---|
| 1 | **Attribution fix** (section 0) - **DONE, in 0.9.2** | Live bug; everything else inherits it |
| 2 | `EXPLAIN` enrichment (A0) - **DONE, in 0.9.2** | Works for every user, no install, no grant |
| 3 | Tools tab + detection + install popovers (C) - **DONE, in 0.9.2** | Needed before anything requiring an install is worth offering |
| 4 | `performance_schema` digests (A1) | Highest value of the SQL work; gated on the grant |
| 5 | Collector interface + Excimer (B) | Correct attribution, production-safe |
| 6 | XHProf-family collector (B) | Exact call counts for author reports |
| 7 | Function detail in hub submissions and author reports | Depends on 1, 5, 6 |

Phases 1 and 2 are worth doing regardless of whether any of the rest happens.

### Two attribution modes

A single "correct" attribution does not exist, so the chain is captured once and the mode is a
pure function over it (`SSPA_Component_Map::resolve()`). Nothing is decided at capture time.

- **CODE_OWNER (default)** - the component whose code actually ran. Answers "which codebase do
  I open" and "how fast is WooCommerce itself".
- **CALLER** - the component that asked for the work. A plugin calling `wc_get_product()` 200
  times in a loop instead of one aggregate query is that plugin's fault, and this mode says so.
  It also matches Deep Analysis: disable that plugin and those calls disappear.

**Why CALLER cannot be the global default**, discovered by testing it: on a normal shop page
the chain is `[woocommerce, <theme>]`, because the theme template is what calls into
WooCommerce. Caller mode therefore charges the theme for WooCommerce rendering its own shop
page. Every theme would look catastrophic and WooCommerce would look free. Caller mode earns
its keep on the N+1 findings, where the repeated call genuinely *is* the waste, not as a
blanket policy.

**Vendored code is exempt from the mode entirely.** When the executing frame sits in a
`vendor`-style directory and a different component called in, the caller takes the cost in
*both* modes, because one shared copy of a library owned by whichever plugin's autoloader won
is never a meaningful answer.

### Phase 1 as built (0.9.2)

- `profiler/class-sspa-component-map.php` - `attribute()` rewritten to the executor/initiator
  walk, returning `via` and `shared` alongside `component`, `type` and `caller`.
- `profiler/class-sspa-capture.php` - `via` threaded through the SQL (both full and degraded
  modes) and HTTP entries.
- `includes/class-sspa-analysis-engine.php` - `via` carried into `slow_query` and
  `big_result_set` finding evidence.
- `includes/admin/class-sspa-insights.php` - those two insight headlines now explain a
  redirected attribution rather than leaving it unexplained.
- `.tests/cases/04-component-map.php` - 7 new assertions covering the shared vendor copy, a
  deep vendor chain, a private vendor tree, bundled Action Scheduler, and the cross-plugin API
  call that must NOT be redirected. Full suite green: 11 cases, 0 failed.

**No schema change was needed.** Per-query data rides in the compressed JSON capture blob, and
`component_stats` aggregates from the already-corrected component. The anonymiser whitelists
evidence keys off the DB tables, so `via` does not enter community submissions unless it is
explicitly added there later.

**Not done, deliberately:** `via` is surfaced on the two query insight headlines only. The
Plugins tab drill-down and the HTTP findings still show the corrected component without the
"work done in X" explanation. Worth finishing when the Plugins tab is next touched.

### Phase 1b as built (0.9.2) - option 2, plus the toggle

`includes/class-sspa-attribution.php`. Code-owner reads `component_stats` unchanged; caller is
**recomputed on demand** from each profile's stored capture blob using the `"type:component"`
chain. No `attrib_mode` column, so none of the six `component_stats` readers can double-count.

- `query_loop` (the N+1 detector) now uses CALLER mode **always**, regardless of the display
  toggle. That finding is about who is being wasteful, so code-owner mode would file a plugin's
  loop under WooCommerce and let the plugin off.
- Plugins tab has an exploratory Code owner / Caller toggle (`?attrib=`), a read-only view
  switch: no option written, no nonce needed, stored numbers untouched. Grouping moved from
  SQL to PHP because the caller aggregate is computed, not stored.

**A vacuous test caught here, worth remembering.** The first e2e assertion ("caller mode
conserves the query total") passed while caller mode was doing *nothing at all*: across 7,085
captured queries in the docker site, **zero** had a chain of two or more components. WordPress
dispatches almost everything through core hooks, so the typical stack is
`plugin -> core -> core` and collapses to a single-component chain. Conservation is trivially
true when nothing moves.

`.tests/cases/13-attribution-modes.php` fixes that: a fixture plugin calls
`wc_get_product_id_by_sku()` 70 times in `wp_footer`, which produces a real
`plugin:woocommerce <- plugin:sspa-caller-fixture` chain. It asserts the modes disagree in the
right direction (70 queries move off WooCommerce, 95 -> 25, onto the fixture, 0 -> 70), that
the total is conserved, and that the `query_loop` finding names the fixture and **not**
WooCommerce. 70 is chosen to clear the `query_hog_count` threshold of 50, so the finding
genuinely fires rather than being skipped.

Lesson for the remaining phases: an assertion over an aggregate can pass because the feature
did nothing. Always assert that the thing under test actually changed something.

### Phase 2 as built (0.9.2) - EXPLAIN enrichment

`includes/class-sspa-explain.php` plus a `build_plans()` pass in the analysis engine that runs
before every other heuristic.

- SELECT only, and fingerprint-only queries are refused: literals are stripped to `?`, so
  inventing values would produce a plan for a query the site never ran. `EXPLAIN ANALYZE` is
  never used (it would execute the statement).
- One EXPLAIN per distinct fingerprint per run, capped at 200. Runs during analysis, never
  inside a profiled request.
- `slow_query` and `big_result_set` findings now carry a `plan_note`, so a slow query is
  reported *with the reason*: "no usable index on wp_postmeta - MySQL expects to examine about
  45,000 rows; builds a temporary table; sorts the results (filesort)".
- New finding type **`unindexed_query`** for queries that are not slow enough to be flagged
  today but have no usable index, so they degrade as the site grows. Deduped against
  `slow_query` so the same query is never reported twice.
- New rules entries: threshold `unindexed_scan_rows` (500) and recommendation
  `unindexed_query`. Both tunable via the community feed without a code change.

**The coverage limit, found while testing and worth knowing.** EXPLAIN can only run on queries
whose FULL SQL was retained, and retention is deliberately narrow for privacy and blob size:
`FULL_SQL_TOP_N = 20` slowest per page, plus anything over `FULL_SQL_MS`, over
`FULL_SQL_ROWS`, or errored. Measured on the docker site: **20 of 34 queries retained on the
home page**. On a page running 300 queries, roughly 280 are never explained.

That is not a bug, it is the retention policy, and it should not be widened casually -
full SQL contains literals, i.e. potentially customer data, which is exactly what the
fingerprint-only default exists to avoid. But it does cap what phase 2 can ever see, and it is
the strongest argument for phase 4: `performance_schema` reports **every** query the server
ran, with real rows-examined rather than an estimate, and needs no retained SQL at all.

Also worth noting: the first version of the e2e test failed for a boring reason that looked
like a bug - `wp_posts` on the docker site has 132 rows against an `unindexed_scan_rows`
threshold of 500, so the finding was correctly suppressed. The fixture now scans `wp_postmeta`
(~1,070 rows) instead. On a real site with real tables the threshold is a low bar; if findings
prove noisy, tune it through the rules feed rather than in code.

### Phase 3 as built (0.9.2) - the Tools tab

`includes/class-sspa-tools.php` + `includes/admin/tabs/tools.php`, a new Tools tab.

- Detects the `performance_schema` ladder (compiled in / switched on / readable by OUR user),
  the profiling extensions, and any third-party APM agent already loaded.
- Generates installation steps **for this server**: distro from `/etc/os-release`, package
  manager, PHP version and SAPI, the real ini scan directory from `PHP_CONFIG_FILE_SCAN_DIR`,
  and the correct restart command for the init system. Copy button on every block.
- Generates a paste-into-a-support-ticket message, since most WordPress sites cannot install
  a PHP extension themselves and that path has to be first class rather than a footnote.
- The `GRANT` is generated with the site's real `CURRENT_USER()`, correctly quoted, asking for
  `SELECT ON performance_schema.*` and nothing more.

**The honesty rule, enforced by tests.** Every capability carries a `used` flag that is
separate from its status. `performance_schema`, `excimer`, `tideways_xhprof` and `spx` are all
`used => false`, and the tab renders "Detected, but this plugin does not read it yet" for any
of them that is present. Detecting a thing is not the same as using it, and the UI must never
blur the two. `15-tools.php` asserts each one stays `used => false` until its phase lands.

**Testing on Alpine caught two real bugs** that a systemd-only assumption would have shipped:
the generated restart command was `systemctl` on a distro that runs OpenRC, and the build-deps
line was `php8-dev` where Alpine names it `php83-dev`. Both are now derived from the detected
distro, and asserted. The lesson generalises: the whole point of this tab is that it is not
generic documentation, so any command it prints needs a test that it is right *for the
detected platform*, not merely present.

Also asserted: `SSPA_Tools` never calls `exec`, `shell_exec`, `passthru`, `proc_open`, `popen`,
`system`, `file_put_contents` or `ini_set`. Those are grep assertions over its own source, so
the hard rules cannot be quietly broken later.

### Phase 1b - the alternative not taken

Caller mode currently exists in the map and is unit-tested, but nothing in the product selects
it yet, so the N+1 case Dave raised is not yet reflected in any finding.

The blocker is aggregation. `sspa_component_stats` holds one row per component per profile,
written from the code-owner resolution, and the `query_loop` finding (the N+1 detector, and
therefore the one that should use caller mode) is built by querying that table
(`class-sspa-analysis-engine.php:182`). Two options:

1. **Dual-write** - add an `attrib_mode` column and write both resolutions. Instant toggle in
   the UI. Risk: six places read `component_stats`
   (`run-controller.php:205`, `run-controller.php:808`, `anonymiser.php:65`,
   `analysis-engine.php:182`, `admin/tabs/plugins.php:21`, plus the profile-store write), and
   any reader that forgets `AND attrib_mode = ...` silently double-counts. That is the same
   class of quiet wrongness this whole phase exists to remove, so it needs a test asserting
   totals are unchanged.
2. **Re-resolve on demand** - `chain` is already stored per query in the capture blob, so the
   caller-mode aggregate can be recomputed from stored captures without re-profiling. No
   schema change, no double-count risk, but a mode switch costs a recompute.

Option 2 is the safer first step and enough to make the `query_loop` finding caller-attributed,
which is the case that actually matters. Option 1 only becomes worth it if a live UI toggle on
the Plugins tab is wanted.

---

## Risks

- **Re-introducing the flaw in a new place.** The attribution walk must live in exactly one
  function used by the SQL path, the HTTP path and both collectors. If it gets copied, the
  copies will diverge and one of them will be wrong.
- **Profiler overhead polluting measured impact.** Guard in code: XHProf-family collection must
  be refused while a measured-impact run is in flight.
- **`performance_schema` noise on shared DB servers.** Handled by only enriching queries SSPA
  itself captured, and reporting the remainder separately rather than folding it in.
- **Fuzzy digest matches presented as exact.** Always carry `match_quality` through to the UI.
- **Telling users to run commands as root.** Every generated command needs its reason stated,
  and the "send to my host" path must be at least as prominent as the "run it yourself" path.
- **Extension availability is genuinely low.** Neither `excimer` nor `tideways_xhprof` is on
  this dev machine, and `php -m` on a typical shared host has none of them. The Tools tab must
  be useful to somebody who can install nothing, or it becomes a wall of things they cannot
  have. That is why A0 comes before A1 and B.
