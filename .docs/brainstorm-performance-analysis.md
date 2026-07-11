# Super Speedy Performance Analysis - Brainstorm

Status: brainstorm v2, updated after Dave's feedback. 2026-07-11.
Companion server-side design: see `brainstorm-superspeedy-org-companion.md`.

## 1. Vision

A free, open source WordPress plugin that diagnoses site performance the way Dave does it
manually: profile the key pages, attribute SQL time / row counts / RAM / query counts to
individual plugins and the theme, then isolate offenders by selectively removing plugins and
re-measuring. Results are presented as plain-English insights on a Performance Analysis page.

Strategic goals:

- **Community flywheel**: opt-in submission of anonymised results to superspeedy.org builds a
  public database of good/bad plugins for performance. That database is unique content, SEO
  bait, and a moat.
- **Natural funnel**: slow queries -> Scalability Pro; slow search -> Super Speedy Search;
  slow filtering -> Super Speedy Filters. Recommendations come out of the data, not ads.
- **LLM-first distribution**: full MCP control + a published skill, so "Claude, why is my
  site slow?" ends with this plugin installed and an analysis run automatically.
- Distributed free on the public GitHub repo (`superspeedyplugins/super-speedy-performance-analysis`),
  promoted on superspeedy.org (community brand) rather than superspeedyplugins.com (commercial).

## 2. The profiler: our own lean collector, no Query Monitor dependency

Decision (Dave): do NOT require Query Monitor. Installing it is a pain for admins, it slows
wp-admin and the front end (always-on collection, heavy JS on large pages), and we only need a
subset of what it gathers. We build a lean profiler with the same measurement quality, active
**only for requests carrying our profiling token** - zero overhead for normal traffic, which
is a genuine advantage over QM. QM remains a dev-time cross-check: our test suite profiles the
same page with both and asserts our numbers agree with QM's within tolerance.

### 2.1 How we replicate QM's measurements

- **SQL - needs our db.php shim, hooks alone are not enough** (Dave's catch). Core
  `SAVEQUERIES` logging gives SQL + duration + a caller-summary string, but NOT returned row
  counts or per-query errors. QM only has those when its own db.php drop-in swaps `$wpdb` for
  a `QM_DB` subclass (its collector literally checks `$query['result']` and sets a
  `has_result` flag); and no hook can recover them, because `wpdb::query()` calls `flush()` -
  which zeroes `num_rows`/`rows_affected`/`last_error` - before the next query's `log_query`
  fires. Row counts are the metric the big-result-set/RAM heuristic hangs on, so:
  **the sspa db.php shim (see 2.1a) instantiates our own profiling wpdb subclass for token
  requests**, recording per query: SQL, duration, returned rows / rows affected, error, and a
  real `debug_backtrace()` for component attribution.
**2.1a The sspa db.php shim - one conditional drop-in, three jobs.** `db.php` loads inside
`require_wp_db()`, before wpdb is instantiated and before the object cache starts, which makes
it the only place all three of these can live:

  1. Define `SAVEQUERIES` and create the profiling wpdb subclass for token requests - giving
     100% query coverage including the bootstrap queries that even QM-as-a-plugin misses.
  2. Register the `enable_loading_object_cache_dropin` filter for cache-impact runs (3.6).
  3. Nothing, for every other request: core's `require_wp_db()` treats db.php as optional -
     if the file returns without creating `$wpdb`, core instantiates the stock class. So
     unlike QM's always-on drop-in, ours is **completely inert for real traffic**; production
     requests run stock wpdb with zero collection overhead.

  Conflict ladder when wp-content already has a db.php (checked BEFORE the run starts, and
  the user chooses - never silently):
  - It's QM's: fine - QM_DB already appends `result` and `trace` to `$wpdb->queries`; the
    profiler just reads those instead (free ride, identical data). No prompt needed.
  - It's LudicrousDB / W3TC dbcache / other: pre-run prompt with two options:
    1. **Run degraded**: leave their db.php alone; SAVEQUERIES still captures time + caller,
       row counts unavailable - affected findings marked lower-confidence, reason shown.
    2. **Temporarily swap for the duration of the run**: rename theirs to
       `db.php.sspa-hold`, install ours, restore the moment the run finishes. Clear warning:
       "W3TC database caching (or equivalent) will be OFF site-wide while the analysis runs -
       run this at a low-traffic time." Crash-safety: restoration also happens on shutdown of
       the run controller, and on every plugin load a sanity check restores any held db.php
       when no run is active, plus an admin notice if a hold is ever found stale.
  - wp-content not writable: degraded mode, explained pre-run.

- **Component attribution** (the crown jewel of QM): map each call-stack frame's file path to
  `wp-content/plugins/<slug>`, the active theme/child theme, mu-plugins, or core. This is a
  small, well-understood piece of QM_Backtrace to replicate; we keep the top non-core caller
  per query. Same mapping is reused for HTTP calls and mail.
- **HTTP API**: timestamp in `pre_http_request` (keyed by URL+args hash), finalise in
  `http_api_debug` - per-call duration, host, response code, blocking flag, component.
- **Overview**: `timer_stop()` for page generation, `memory_get_peak_usage()`, response code,
  included-files count, `did_action('...')` sanity markers.
- **Object cache**: `$wp_object_cache` hits/misses (+ per-group where the backend exposes it,
  Redis Object Cache and Object Cache Pro both do), backend detection, alloptions size.
- **Mail**: instrument `wp_mail` via `pre_wp_mail` / `phpmailer_init` (see 3.4) - count,
  per-send duration, transport, triggering component.
- **Conditionals**: tiny to collect, lets us verify the page we profiled is the page we meant
  (`is_product`, `is_search` etc).
- **Deliberately NOT collected** (keeps blobs small): the full hooks/actions firehose, scripts
  and styles detail (front-end weight is out of scope v1 - asset *counts* only), template
  hierarchy detail, capability checks.

### 2.2 What we store per query (data-minimisation with teeth kept)

Locally we keep **full raw SQL** for the top N slowest queries and anything over the
row-count/time thresholds - Dave wants real evidence when a plugin ships bad SQL (the WPML
class of offender), enough to write the "please fix your shit" article. Everything else is
stored as a **normalised fingerprint** (literals replaced with `?`, whitespace collapsed,
IN-lists collapsed to `IN (?...)`). Normalised SQL preserves everything that proves bad design
- the joins, the missing-index shape, `ORDER BY rand()`, `SQL_CALC_FOUND_ROWS`, meta_query
spaghetti - while containing zero customer values. **Submission to superspeedy.org only ever
sends normalised SQL**, so the public evidence base exists without PII risk.

### 2.3 JSON everywhere

The profiler's output is a versioned JSON schema we own end to end: written to our tables,
rendered in the UI, returned by MCP abilities, submitted upstream. (If QM happens to be
installed we could additionally slurp `QM_Collectors::get('db_queries')->get_data()` etc for
cross-checking, and QM's REST `?_envelope` `qm` property remains a free source for REST-page
profiles - but nothing depends on QM being present.)

## 3. Architecture overview

```
+------------------------------- WP install --------------------------------+
|                                                                            |
|  Crawler (WP-Cron / Action Scheduler batches, or manual "Run analysis")    |
|     |  loopback HTTP requests with signed profiling token + auth cookie    |
|     v                                                                      |
|  sspa db.php shim (conditional drop-in, inert without token - see 2.1a)    |
|     - SAVEQUERIES + profiling wpdb subclass (rows/errors/backtraces)       |
|     - object-cache disable filter for cache-impact runs (see 3.6)          |
|  MU loader (auto-installed mu-plugin, tiny, returns early with no token)   |
|     - recognises token, arms the rest of the profiler for that request     |
|     - applies plugin-set override via option_active_plugins filter         |
|     - sets DONOTCACHEPAGE etc so page caches don't fake the numbers        |
|     - suppresses/blackholes outbound mail during write profiles            |
|     v                                                                      |
|  Profiled request -> shutdown -> profiler JSON                             |
|     v                                                                      |
|  Custom tables (runs, profiles, findings, plugin_impacts, site_metrics)    |
|     v                                                                      |
|  Analysis engine (heuristics = Dave's SOP)                                 |
|     v                                                                      |
|  Results tab UI  +  MCP abilities  +  WP-CLI  +  opt-in submit             |
|                                                       |                    |
+-------------------------------------------------------|--------------------+
                                                        v
                     superspeedy.org companion plugin (see companion doc)
                     (submissions in, rules feed + alternatives out)
```

### 3.1 The crawler

- `wp_remote_get`/`wp_remote_post` loopbacks to the site's own URLs. Each carries a
  single-use signed token (HMAC of URL + nonce + expiry, secret stored in options) in a header.
  Never a static secret in a query string; tokens expire in seconds and are single-use so a
  leaked log line can't let outsiders trigger profiled (plugin-filtered!) requests.
- **Three audience variants**, because they mine different truths (page caches usually serve
  anonymous users; logged-in traffic hits PHP; admins hit yet another code path):
  - **Anonymous**: plain request, no cookie.
  - **Logged-in admin**: auth cookie generated with `wp_generate_auth_cookie()` for the
    *currently logged-in admin* who started the run. No temporary admin user - Dave's call,
    and it avoids tripping security plugins that alert on user creation.
  - **Logged-in customer/subscriber**: we should NOT impersonate a real customer (their cart,
    session and last-login metadata would be touched). Create one clearly-named test account
    (`sspa-test-customer`, role customer/subscriber, flagged in usermeta) on first use, reuse
    it, show it in the UI with a delete button. Low-privilege, so far less spicy than a temp
    admin.
- **Security-plugin awareness** (Dave's answer to Q2): admin GET pages ARE in run 1. If a
  loopback comes back blocked (403/503, CAPTCHA/challenge markup, redirect to login when a
  valid cookie was sent), the run **continues**, marks that page `blocked`, detects which
  security layer did it (plugin detection: Wordfence, Solid Security, All-In-One Security,
  Shield, Sucuri, Jetpack Protect; edge detection via headers: Cloudflare, Sucuri WAF) and
  emits a finding telling the user exactly what to whitelist in that specific plugin. The
  per-plugin whitelisting instructions live in the rules feed so the community can improve
  them without a release.
- **Cache busting**: token requests get `Cache-Control: no-cache`, a cache-buster arg, and the
  MU loader defines `DONOTCACHEPAGE`/`DONOTCACHEOBJECT` where honoured. Detect when a page
  cache served the response anyway (header sniffing: x-cache, cf-cache-status etc, plus a
  canary: the response must echo our per-request token) and discard that sample.
- **Sampling discipline**: per page per plugin-set: 1-2 warm-up requests (prime object cache
  and opcache), then 3 measured requests, store all, report the **median**, keep the spread as
  a noise estimate. Also measure an idle "hello world" baseline (a trivial endpoint we
  register) to estimate the server noise floor. Without this, deltas on shared hosting are
  meaningless.
- **Batching**: run through WP-Cron/Action Scheduler in small batches with politeness delays;
  progress bar in the UI; manual "run now" with a warning.
- **Timeouts and fatals**: a profiled request that 500s or times out is itself a finding.
  Never let one bad page abort the run.

### 3.2 Page catalogue

Each page gets a stable `page_key` so results are comparable across runs, and each is profiled
in whichever audience variants make sense (front-end pages: anonymous + customer; wp-admin:
admin only).

Front end:

- Home page
- Blog index / a specific post page
- Post category archive, post tag archive
- Shop archive, product category archive, specific product page
- Search results: (a) a term matching many products, (b) a term with zero results
  (zero-result searches are often the slowest - full scans with no early exit)
- Cart, checkout, my-account (Woo) - checkout is the money page
- For **every public CPT** (auto-discovered): archive page, single page (pick 2-3
  representatives: newest, most-commented or median-sized), plus each of its public
  taxonomies' term archives (pick the term with the most objects - worst case)
- 404 page, RSS feed, sitemap index
- REST: `/wp-json/wp/v2/posts`, Woo REST products list if Woo active
- admin-ajax: heartbeat, Woo add-to-cart fragment refresh if Woo active

wp-admin (auth'd loopbacks, in run 1 per Dave):

- Dashboard, Plugins list, Media library
- Orders list, Orders list **with a customer search** (the classic Scalability Pro win),
  Products list, Posts list
- Edit product, Edit order, Edit post (editor load)
- New post/product editor (different queries/assets than edit)

Write profiles (POST, opt-in, never in the first non-destructive run):

- Save product, save order, save post: POST to the real handlers with a valid nonce + auth
  cookie, **always against a temporary duplicate** (duplicate the product/order, save the
  duplicate, delete it after). Nonces are user+session dependent - generate server-side under
  the admin's session; spike this early.
- **Order processing profile** (Dave's #1): create a temp order, transition it
  pending -> processing -> completed inside profiled requests, measuring the full hook
  cascade: which plugins hook order status changes, how long each transition takes, how many
  emails are generated and how long email sending blocks the request. This is where checkout
  pain actually lives (payment webhooks aside).

### 3.3 Email profiling (new)

Two distinct measurements:

1. **Email plugin overhead**: profile a plain `wp_mail()` send (a test message) with whichever
   SMTP/mail plugin is active. A blocking SMTP handshake to a slow relay can add 500ms+ to any
   request that sends mail - checkout, registration, order status changes. Measure connect +
   send time, attribute to the mail plugin. Repeat during isolation testing (mail plugin
   virtually disabled -> PHP `mail()` fallback) to give the mail plugin its own impact number.
2. **Emails triggered by actions**: during order-processing/write profiles, count and time
   every `wp_mail` call and attribute the *trigger* (which component initiated the send).

Where do profiled emails go? Options, in preference order:

- **Local blackhole (default, v1)**: `pre_wp_mail` short-circuit... except that skips the very
  SMTP work we want to measure. So instead: let the mail plugin build the message, then at
  `phpmailer_init` (the last hook before send) swap the recipient list for a blackhole and -
  in "suppress" mode - swap the transport for a no-op after timing message construction. Two
  sub-modes: *construct-only* (measure template/build cost, nothing leaves the server, zero
  risk) and *full-send to sink* (measure real SMTP cost).
- **SMTP sink at superspeedy.org**: `blackhole@sink.superspeedy.org` - a dumb SMTP server that
  accepts RCPT and discards DATA. Lets *full-send* mode measure a real SMTP round trip without
  a real mailbox. Requires server-side infra (companion doc).
- **Receiver bucket** (`order-processed-analysis+<guid>@superspeedy.org`): only worth it if we
  want to verify emails actually arrive and render (deliverability/DKIM checks) - a different
  product feature. If we ever do it: synthetic orders only, store headers + verdict, discard
  bodies. Real customer emails must never land at superspeedy.org. Park for later; Dave's
  instinct that "maybe we don't actually need to receive them" is right for the performance
  use case.

Safety rail either way: during ANY profiled request the MU loader forces the recipient
override, so a misconfigured profile can never email real customers.

### 3.4 Storage schema (custom tables, prefix `sspa_` - confirmed)

- `sspa_runs`: id, type (baseline | deep | cache_impact | spot), trigger (manual | cron |
  plugin_toggle | mcp), started/finished, plugin_set snapshot (JSON + hash), site_metrics_id,
  status, notes.
- `sspa_profiles`: id, run_id, page_key, url, method, variant (anon | customer | admin),
  plugin_set_hash, object_cache_mode (normal | disabled), samples (JSON of raw timings),
  ttfb_ms (median), page_gen_ms, sql_ms, sql_count, http_ms, http_count, php_ms (derived),
  peak_mem_bytes, rows_returned_total, dupe_query_count, cache_hits, cache_misses,
  mail_count, mail_ms, response_code, blocked_by (nullable security-layer slug),
  profile_blob (LONGBLOB, gzcompressed profiler JSON).
- `sspa_component_stats`: profile_id, component, query_count, sql_ms, rows_returned,
  slowest_query_ms, http_ms, mail_ms, cache_hits, cache_misses. Extracted at capture time so
  the UI and trends never unpack blobs. Practise what we preach: this is our own hot path,
  index it properly.
- `sspa_findings`: run_id, severity, type (slow_query | big_result_set | query_loop |
  dupe_queries | slow_http | ram_hog | autoload_bloat | blocking_mail | cache_blind |
  security_block | ...), component, page_key, evidence (JSON incl. normalised query + local
  raw-SQL reference), recommendation_key, confidence.
- `sspa_plugin_impacts`: plugin, page_key, method (single_out | bisect | cache_toggle),
  delta_ttfb_ms, delta_sql_ms, delta_mem_bytes, delta_queries, noise_floor_ms, confidence,
  run_id refs. The crown-jewel table - it's what gets (anonymously) submitted upstream.
- `sspa_site_metrics`: demographics snapshot per run (see 3.8).

**Retention (Dave's rule)**: never auto-delete. The Overview tab shows space used by blobs
("Profile data: 214 MB across 12 runs") with a button: *"Delete detailed data older than the
last 5 runs"*. Clicking it first offers - if the site hasn't opted in yet - "Before deleting,
would you like to submit this data anonymously to superspeedy.org to help the community?"
Aggregate rows (`component_stats`, `plugin_impacts`, findings) are kept forever regardless;
only blobs are pruned.

### 3.5 Analysis engine - encode Dave's SOP as explicit heuristics

Each heuristic emits findings with evidence + a recommendation key (recommendation text comes
from the rules feed so wording/targets improve without a release):

1. **Slow queries** (> X ms, default 50): attribute via component. Classify the query shape:
   `wp_postmeta` joins/scans, `meta_query` on unindexed keys, `SQL_CALC_FOUND_ROWS`,
   `ORDER BY rand()`, `LIKE '%...%'` on unindexed columns, huge `IN (...)` lists, tax_query
   nested joins. Shape classification drives the recommendation ("this exact pattern is what
   Scalability Pro's indexes/rewrites fix").
2. **Big result sets** (> 200 rows returned, configurable): RAM suspects. Correlate with peak
   memory; seed list for deep analysis.
3. **Query-count hogs per component**: > ~50 queries on one page, or - much stronger - query
   count that *scales with content* (product page with 3 related items vs 30; or growth across
   runs). Queries-in-loops (N+1) is the disease; count scaling is the symptom.
4. **Duplicate queries**: identical fingerprint run repeatedly in one request - missing
   caching in that component.
5. **Slow/blocking HTTP API calls** during page load (licence pings, geo lookups): anything
   > 100ms blocking a front-end page is critical.
6. **Blocking mail** (new): wp_mail time on profiled write/order actions; slow SMTP relay or
   heavyweight template builders.
7. **Autoload bloat**: alloptions > 1MB; biggest autoloaded options by component.
8. **Cache-blind components** (new, from 3.6): identical query counts with object cache on vs
   off = the component caches nothing.
9. **RAM per page** vs hello-world baseline; attribute via deep analysis.
10. **Duplicate-functionality plugins** (two SEO plugins, three security plugins) via the
    category map in the rules feed.
11. **Environment red flags**: no persistent object cache on a postmeta-heavy site, PHP < 8,
    ancient MySQL, absurd memory_limit masking a hog.

Results lead with a "Top 5 insights" narrative, not a wall of tables.

### 3.6 Deep analysis (isolation testing)

**Don't actually deactivate plugins. Virtually deactivate them per-request.**

The MU loader filters `option_active_plugins` (and the sitewide equivalent) *only for requests
carrying the profiling token*, removing the plugins under test. Proven technique (Freesoul
Deactivate Plugins / Plugin Organizer). Consequences:

- Real visitors and admins are **completely unaffected** while deep analysis runs. No
  deactivation hooks fire, no side effects, no broken-site window.
- The warning shrinks to the honest version: "test requests will run with some plugins
  disabled for those requests only".
- Parallel-safe and crash-safe: if the analysis dies mid-run, nothing is left deactivated.
- Theme isolation via the same trick (`template`/`stylesheet` filters for token requests,
  switching to a default twenty* theme): "your theme's functions.php adds 900ms" is a very
  common real-world finding.
- Fallback: real deactivation mode behind a scarier warning, only for hosts where mu-plugin
  installation fails (read-only wp-content).

**Selection strategy** (deep runs are targeted, not full re-crawls):

1. **Suspect single-out**: for each component with findings, profile its single worst page
   with just that component virtually disabled; delta vs same-run baseline = attributable cost.
2. **Bisection sweep** (finds the Smart Coupons class of offender whose cost is smeared across
   many small hooks): on the slowest 1-2 pages, binary-search the plugin list; recurse into
   whichever half retains the cost, both halves if both do (multiple culprits).
   ~2·log2(n) requests per culprit per page - 40 plugins ≈ a dozen requests.
3. **Dependency awareness**: never virtually disable WooCommerce under a Woo extension.
   Dependency map seeded from the core `Requires Plugins` header, improved by the rules feed.
   A half that fatals (500) reveals a dependency - record it, split differently, move on.
4. **Noise gate**: a delta only becomes a finding if it exceeds
   max(3 × stddev of baseline samples, 30ms). Otherwise record "no measurable impact" - which
   is exactly what the community whitelist needs.

**Object-cache / Redis impact runs** (Dave's #6). Which plugins actually use Redis well is
genuinely valuable community data. Wrinkle: the object-cache.php drop-in loads in
`wp_start_object_cache()` *before* mu-plugins, so the MU loader is too late to stop it.
Options:

- **The sspa db.php shim (preferred)** - the same shim that captures per-query row counts
  (2.1a), which we're installing anyway: it loads before the object cache starts, so for
  token + cache-off requests it registers the core `enable_loading_object_cache_dropin`
  filter (WP 5.8+) to return false for that request only. Same per-request, zero-live-impact
  property as virtual plugin deactivation. If the site already has a foreign db.php
  (LudicrousDB etc), fall back to:
- **Brief site-wide toggle**: rename object-cache.php, run the batch, restore. Works anywhere
  but causes a short cache-cold storm on a busy site - explicit warning, off-peak advice.

What a cache_impact run yields, per component: query count and SQL time with cache on vs off.
"Plugin X: identical queries either way - cache-blind." "Plugin Y: 90% fewer queries with
Redis - cache-friendly." Plus overall hit ratio and which cache groups dominate. Submitted
upstream, this becomes the "which plugins use Redis effectively" database - data nobody else
has.

**Continuous collection**: every isolation measurement appends to `sspa_plugin_impacts`.
Hook `activated_plugin`/`deactivated_plugin` for normal admin toggles too: offer a 30-second
before/after spot-profile via admin notice. Over time each site accumulates its own
per-plugin cost ledger, and opted-in sites feed the global one.

### 3.7 Site demographics + sector inference

Per run snapshot: post/page counts, per-CPT counts, product count, order count (+ last 30
days - activity beats totals), user count, comment count, term counts,
postmeta/options/usermeta row counts, autoload size, DB size, active theme + child, plugin
list w/ versions, PHP/MySQL versions, object cache backend, page cache detected, server
software, WP version, multisite flag, locale, memory_limit.

Sector guess: dominant CPT + signature plugins (job_listing -> jobs board; download + EDD ->
digital store; property -> real estate; tribe_events -> events; course/lesson -> e-learning;
product + subscriptions -> subscription commerce; else high post count -> publisher).
Signature table ships in the rules feed so the community can extend it. Sector powers
benchmarking: "your product pages are slower than 80% of stores your size".

**Settings snapshots for feature detection** (feeds the companion's alternatives engine):
with explicit opt-in, capture which features of a plugin are actually enabled (its settings,
allowlisted keys only, values bucketed to on/off/enum - never free text, which can contain
keys/emails). This is how "you only use 3 of Plugin X's features; Plugin Z covers those and
is 400ms faster" becomes possible.

### 3.8 Results UI

One top-level "Performance Analysis" page using **Dave's JS tab pattern from the shared
`super-speedy-settings` submodule** - all tabs render in one page load,
`.nav-tab-wrapper .nav-tab[data-tab]` switches `div.tab-contents[data-tab]` visibility purely
client-side, hash written via `history.pushState` so tabs are deep-linkable, zero page
reloads, zero lost state. (Reference implementation:
`super-speedy-filters/assets/js/fww-admin.js` + `includes/settings.php`.) This plugin should
include the `super-speedy-settings` submodule anyway - it brings the settings framework,
admin styling and plugin-update-checker (GitHub-based updates for free users) in one go.

Tabs:

- **Overview**: site score, top 5 insights in plain English, demographics card, blob storage
  meter + "delete older than last 5 runs" button (with the share-before-delete prompt), "Run
  Analysis" / "Run Deep Analysis" buttons with time estimates and progress.
- **Pages**: sortable table - TTFB / SQL / PHP / RAM / queries / mail per page and variant,
  sparklines vs previous runs; drill into a page for per-component breakdown and worst queries
  (pretty-printed with caller stacks).
- **Plugins**: per-plugin cost table (component stats + isolation deltas where measured),
  confidence badges (inferred vs measured), cache-effectiveness column after a cache_impact
  run, "Measure this plugin" row action queueing a targeted single-out. Where the rules feed
  knows alternatives: "faster options in this category" links (data via companion plugin).
- **History**: runs over time - is the site getting slower as it grows? Retention hook for
  quarterly re-runs.
- **Share**: submission opt-in, payload preview, link to the site's anonymous entry and the
  global rankings on superspeedy.org.

Button naming: **"Run Deep Analysis"** (confirmed). Results section titled "Culprit isolation".

### 3.9 superspeedy.org integration (client side)

Server side is now its own design: `brainstorm-superspeedy-org-companion.md`. From this
plugin's perspective:

- `POST /submissions`: anonymised profiles + plugin_impacts + demographics + (opt-in) feature
  snapshots. Anonymisation: no domain/URLs (site identity = random install UUID; salted domain
  hash only for dedupe), normalised SQL only, plugin slugs + versions, counts bucketed. Show
  the exact JSON before first submission - trust is the product.
- `GET /rules.json` (signed, cached 24h, bundled snapshot for offline): whitelist (skip in
  bisection), blacklist (known offenders + fix + recommendation text), fragile list (never
  virtually disable), dependency map, sector signatures, security-plugin whitelisting
  instructions, query-shape recommendations, plugin category map.
- `GET /alternatives?plugin=X&features=[...]`: category competitors ranked by measured
  performance, filtered to the features this site actually uses.

### 3.10 MCP / abilities / LLM skills

- **WordPress Abilities API + MCP Adapter** (same stack as Super Speedy Emails - see the
  wp-abilities-api skill for gotchas). Abilities (readonly ones GET):
  `sspa/get-status`, `sspa/run-analysis`, `sspa/run-deep-analysis`, `sspa/get-findings`,
  `sspa/get-page-profile`, `sspa/get-plugin-impacts`, `sspa/get-site-metrics`,
  `sspa/submit-results` (still gated on the human-set opt-in flag).
- **WP-CLI parity** (`wp sspa run`, `wp sspa findings --format=json`) for SSH'd agents, CI,
  and our own e2e tests.
- Publish a **Claude skill** (SKILL.md in repo + registry) and an OpenAI-equivalent: install
  plugin -> run analysis -> read findings JSON -> explain -> offer deep analysis. Findings
  JSON is written for LLM consumption: stable keys, human-readable evidence strings, explicit
  recommendation objects.
- REST endpoints with application-password auth as the lowest common denominator.

### 3.11 Free distribution mechanics

- Public GitHub repo is the canonical home; releases via GitHub; in-plugin updates via the
  plugin-update-checker already bundled in the super-speedy-settings submodule.
- wp.org listing later, maybe. Not a launch blocker.
- Rules being data files is what makes "let others contribute to discovering bad plugins"
  real: dependency map, sector signatures, whitelisting instructions and recommendation texts
  are all easy first PRs.

## 4. Risks / gotchas

- **Measurement noise** is the #1 threat to credibility: median-of-3 + warmups + noise floor +
  confidence labels everywhere. Never report a 40ms delta on a host with 60ms jitter.
- **Page caches / CDNs** faking TTFB - header sniffing + response canary, discard cached
  samples.
- **Security plugins** blocking loopbacks - detect, continue, advise whitelisting (3.1).
- **Write profiles** have real side effects - temp duplicates only, forced mail blackhole,
  opt-in, never in run 1.
- **Nonce generation** for admin POST simulation is user+session dependent - spike early.
- **Hosting weirdness**: no mu-plugins write access (fallback modes), basic-auth staging
  (store credentials), Cloudflare in front of the loopback URL (direct-IP override), disabled
  WP-Cron (browser-driven batches).
- **db.php shim conflicts** (existing QM/LudicrousDB/W3TC drop-in) - conflict ladder in 2.1a:
  ride QM's; for others the user chooses pre-run between degraded mode and a temporary swap
  (with a caching-off / low-traffic warning and crash-safe restoration).
- **Multisite**: park for v1, keep blog_id in the schema.
- **Name-and-shame** stays defensible as *measurement*, not verdict: sample sizes,
  distributions, right of reply on superspeedy.org - and the normalised-SQL evidence policy
  means we can publish the receipts (looking at you, WPML) without ever holding customer data.

## 5. Out of scope (v1)

- Front-end performance (JS, LCP, CWV) - this is a server-side generation-time analyser. Say
  so on the results page; maybe CrUX data later for context.
- Fixing anything; deliverability testing (receiver bucket parked); placing real test orders
  through payment gateways; multisite.

## 6. Phased roadmap

1. **MVP (capture + read-only insights)**: lean profiler + MU loader, front-end GET catalogue
   + admin GET pages (3 audience variants), security-block detection, sampling, custom tables,
   component stats, heuristics 1-5 + 7 + 11, Overview/Pages/Plugins tabs (JS tab pattern),
   demographics, blob meter + delete button. Strictly non-destructive.
2. **Deep analysis**: virtual deactivation, single-out + bisection, theme isolation, noise
   gate, plugin_impacts, plugin-toggle spot-profile prompt, "Run Deep Analysis".
3. **Cache + mail**: db.php shim, cache_impact runs, cache-blind findings; wp_mail overhead
   profile, local blackhole modes; write profiles + order-processing profile (temp duplicates,
   forced blackhole).
4. **Community**: submission opt-in + anonymiser + payload preview, rules feed consumption,
   alternatives surfacing; companion plugin endpoints (separate doc) live on localhost first,
   then superspeedy.org.
5. **Agents**: Abilities/MCP, WP-CLI, findings-for-LLMs polish, Claude skill + OpenAI
   equivalent published.
6. **Later**: SMTP sink full-send mode, deliverability bucket (synthetic only), REST/AJAX
   catalogue expansion, sector benchmarks, wp.org decision.

## 7. Decisions log (from Dave's review, 2026-07-11)

- Prefix `sspa_` - confirmed.
- wp-admin GET pages in run 1 - yes; security blocks don't abort the run, they become
  findings with plugin-specific whitelisting advice.
- No temporary admin user; use the logged-in admin's cookie. Three audience variants
  (admin / logged-in customer via flagged test account / anonymous).
- No QM dependency: build the lean profiler (section 2); QM used only as a dev-time
  cross-check. Smaller blobs, no wp-admin slowdown for users.
- A db.php drop-in IS required for per-query row counts and errors (Dave's catch - core
  SAVEQUERIES lacks them and `wpdb::flush()` makes hook-based capture impossible), but ours
  is conditional: inert stock-wpdb behaviour for every request without a profiling token.
- Blob retention: user-controlled delete button + share-before-delete prompt, never automatic.
- Results UI must use the super-speedy-settings JS tab pattern - no page-reload tabs, ever.
- Submission keeps normalised SQL (evidence preserved, PII stripped); full raw SQL for worst
  offenders stays local.
- superspeedy.org = WordPress + companion plugin + fast-ajax-style mu REST override; designed
  separately in `brainstorm-superspeedy-org-companion.md`; developed on this localhost first.
- Email profiling: local blackhole first; superspeedy.org SMTP sink later; receiver bucket
  only ever for synthetic data, parked.
- Redis/object-cache impact runs are in scope (db.php shim preferred, site-wide toggle
  fallback) - cache-effectiveness per plugin is a headline community dataset.
- Button: "Run Deep Analysis".
