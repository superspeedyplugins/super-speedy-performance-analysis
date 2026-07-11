# Super Speedy Performance Analysis - Phased Implementation Plan

Status: active plan. 2026-07-11.
Design sources: `brainstorm-performance-analysis.md` (v2) and
`brainstorm-superspeedy-org-companion.md`. Where this plan and the brainstorms disagree, the
brainstorms' decisions log wins; update both when a decision changes.

Conventions used throughout: prefix `sspa_`, tables `{$wpdb->prefix}sspa_*`, text domain
`super-speedy-performance-analysis`. Results UI uses the super-speedy-settings JS tab pattern
(no page reloads). Tests live in `.tests/` with a README per the house convention.

## Proposed file layout

```
super-speedy-performance-analysis.php   # bootstrap, defines, includes
defines.php
uninstall.php                           # drop tables + remove shim/mu (opt-in "delete data")
includes/
  class-sspa-install.php                # activation: dbDelta tables, shim + mu install/verify
  class-sspa-schema.php                 # table definitions + versioned migrations
  class-sspa-token.php                  # HMAC signing/verify, single-use nonce store
  class-sspa-catalogue.php              # page catalogue builder (CPT/tax discovery)
  class-sspa-run-controller.php         # run state machine, batching (WP-Cron), crash safety
  class-sspa-crawler.php                # loopbacks, warmups, sampling, cache canary
  class-sspa-auth.php                   # admin cookie generation, test customer account
  class-sspa-profile-store.php          # blob compression, component-stats extraction
  class-sspa-analysis-engine.php        # runs heuristics, writes findings
  heuristics/                           # one class per heuristic (slow-query, big-rows, ...)
  class-sspa-isolation.php              # single-out + bisection planner (pure logic)
  class-sspa-dependency-map.php         # Requires Plugins header + rules feed data
  class-sspa-demographics.php           # site metrics + sector inference
  class-sspa-mail-interceptor.php       # phpmailer_init recipient override, timing
  class-sspa-security-detect.php        # blocked-response classifier + advice
  class-sspa-anonymiser.php             # submission payload builder + SQL normaliser reuse
  class-sspa-fingerprint.php            # SQL normalisation (shared: analysis + anonymiser)
  class-sspa-rules-feed.php             # fetch, signature verify, bundled snapshot fallback
  class-sspa-submitter.php              # POST to hub, install UUID + per-install secret
  admin/
    class-sspa-admin-page.php           # tabs shell (JS tab pattern), assets
    tabs/ (overview, pages, plugins, history, share)
    js/sspa-admin.js  css/sspa-admin.css
  cli/class-sspa-cli.php                # wp sspa ...
  abilities/class-sspa-abilities.php    # Abilities API registrations (phase 6)
profiler/                               # SELF-CONTAINED: loaded by shim/mu-loader via
  bootstrap.php                         # absolute path; no dependency on the rest of the
  class-sspa-profiling-wpdb.php         # plugin having loaded. Collectors + component map
  class-sspa-collector-*.php            # (sql, http, cache, overview, mail, conditionals)
  class-sspa-component-map.php
  class-sspa-serializer.php             # versioned profile JSON schema
dropins/db.php                          # the sspa shim (copied to WP_CONTENT_DIR/db.php)
mu/sspa-loader.php                      # copied to mu-plugins/
rules/rules-snapshot.json               # bundled offline rules feed
super-speedy-settings/                  # shared submodule (framework + update checker)
.tests/                                 # e2e harness + README (phase 1)
.docs/  .kb/                            # design docs, KB articles as features ship
```

Critical constraint baked into the layout: everything under `profiler/`, `dropins/` and `mu/`
must run standalone (the shim executes before plugins load; during isolation runs other
plugins are virtually absent - ours never is, but the profiler still must not assume plugin
bootstrap has happened yet).

---

## Phase 0 - Scaffold & plumbing  [DONE 2026-07-11]

Goal: installable empty plugin, repo pushed, framework in place.

- [x] Plugin skeleton: main file, `defines.php`, version 0.1.0, readme.txt with changelog
- [x] Add `super-speedy-settings` submodule; admin page as a submenu of the shared
      Super Speedy menu (own top-level fallback when the submodule/siblings are absent);
      JS tabs wired (empty tabs: Overview, Pages, Plugins, History, Share)
- [x] Update checker pointed at the public GitHub repo (releases-based). NOTE: we must NOT
      call `SuperSpeedySettings_1_0::init()` - it registers a superspeedyplugins.com PUC for
      our slug and PUC fatals on duplicate slugs; this free plugin uses the GitHub checker
      only (learned the hard way - the fatal took down the whole site/CLI)
- [x] `class-sspa-schema.php` + activation: create all six tables via dbDelta
      (runs, profiles, component_stats, findings, plugin_impacts, site_metrics) with
      `sspa_db_version` option + migration path; blog_id column present, unused
- [x] `uninstall.php`: honour a "remove all data on uninstall" setting (default off);
      signature-checked cleanup of shim/mu files + stale db.php.sspa-hold restore
- [x] `.tests/README.md` stub describing the harness we'll build in phase 1
- [x] Initial commit + push to `main`

Acceptance: verified on the local install - activates cleanly, all six tables exist,
tabbed page renders (5 tabs/5 panels), wp-cli loads without error.

## Phase 1 - Capture engine (the profiler + crawler)  [DONE 2026-07-11, except real-QM cross-check]

Goal: "Run Analysis" completes a full read-only baseline on the local dev store and stores
credible numbers. The hardest phase; everything else consumes what it produces.

Infrastructure:

- [x] `class-sspa-token.php`: HMAC token (URL + expiry + nonce), single-use enforcement via
      short-TTL option/transient store; header transport `X-SSPA-Token`
- [x] MU loader `mu/sspa-loader.php`: verify token; arm profiler (require
      `profiler/bootstrap.php` by absolute path); define DONOTCACHEPAGE/DONOTCACHEOBJECT;
      echo response canary; no-op fast path without token. Install/verify/remove logic in
      `class-sspa-install.php` with health check on admin page ("mu loader active: yes")
- [x] db.php shim `dropins/db.php`: token check -> define SAVEQUERIES + instantiate
      `SSPA_Profiling_WPDB`; else return inert (core creates stock wpdb). Install only when
      wp-content/db.php absent
- [x] db.php conflict handling (pre-run, user choice - see brainstorm 2.1a):
  - [x] Detect existing db.php + identify owner (QM / LudicrousDB / W3TC / unknown)
  - [x] QM's: skip prompt, read `result`/`trace` from QM_DB entries in the sql collector
  - [x] Foreign: pre-run modal - "Run degraded" vs "Temporarily swap db.php for this run"
        with the explicit warning (their DB caching OFF site-wide for the duration; advise
        low-traffic window)
  - [x] Swap mechanics: rename to `db.php.sspa-hold`, copy ours in, restore on run end AND
        run-controller shutdown handler AND a stale-hold check on every plugin load (restore
        + admin notice if no run active)
  - [x] Degraded mode: SAVEQUERIES via mu loader, row-count-dependent metrics marked
        unavailable, confidence flag plumbed through to findings

Profiler (`profiler/`):

- [x] `SSPA_Profiling_WPDB`: per query capture sql, duration, rows returned / affected,
      error, `debug_backtrace` frames (trimmed, args stripped)
- [x] `class-sspa-component-map.php`: stack frame file path -> plugin slug / theme / child /
      mu-plugin / core; top non-core caller per event
- [x] Collectors: http (pre_http_request + http_api_debug), overview (timer_stop, peak mem,
      response code, included file count), cache ($wp_object_cache hits/misses, backend,
      alloptions size), conditionals
- [x] `class-sspa-serializer.php`: profile JSON schema v1 (documented in
      `.docs/profile-schema.md`); full SQL kept for top-20 slowest + over-threshold queries,
      fingerprints elsewhere (`class-sspa-fingerprint.php` - literals -> ?, IN-list collapse,
      whitespace)
- [x] Delivery: profiler writes JSON to a temp row keyed by token at shutdown (not into the
      response body, which themes can mangle)

Crawler + catalogue:

- [x] `class-sspa-catalogue.php`: static keys (home, blog, search-many, search-zero, 404,
      feed, sitemap, cart/checkout/my-account when Woo, REST posts, heartbeat) + CPT/tax
      discovery with representative-entry selection (newest / most-commented / biggest term)
- [x] wp-admin GET catalogue: dashboard, plugins, media, posts/products/orders lists,
      orders-list-with-customer-search, edit + new post/product/order
- [x] `class-sspa-auth.php`: `wp_generate_auth_cookie` for the initiating admin; flagged
      `sspa-test-customer` account (create on demand, visible in UI, deletable)
- [x] `class-sspa-crawler.php`: warmups + 3 samples, median + spread, hello-world baseline
      endpoint, cached-response detection (headers + canary) with sample discard, timeout
      and 500 handling as recorded outcomes
- [x] `class-sspa-run-controller.php`: run state machine (queued -> crawling -> analysing ->
      done/failed), WP-Cron batches with politeness delay, progress reporting (polled by the
      admin JS), resumable after crash
- [x] `class-sspa-security-detect.php`: classify blocked responses (403/503/challenge/login
      bounce with valid cookie), identify security plugin from active list + response
      signatures, continue run, emit `security_block` finding with whitelisting advice
- [x] `class-sspa-profile-store.php`: gzcompressed blob storage + `sspa_component_stats`
      extraction per profile

Testing (start the harness now, grow it each phase):

- [x] `.tests/` e2e: wp-cli-driven run against the local store; assert run completes, page
      count, profiles have sane values (page_gen > 0, sql_ms <= page_gen, rows captured)
- [ ] Cross-check test: same page profiled by us and by QM (with its symlink) - sql count,
      sql time and row totals within tolerance
- [x] Unit-style tests: fingerprint normaliser, component map, token verify
- [x] Note local-dev gotchas per superspeedy-dev-env skill in `.tests/README.md`

Acceptance: full baseline (3 variants where applicable) on the local dev store completes
unattended in < ~10 min, Pages tab shows real numbers, QM cross-check passes, kill -9 during
a run leaves no held db.php and the next load self-heals.

## Phase 2 - Analysis engine + insights UI

Goal: the numbers become plain-English findings; the plugin is genuinely useful (and
shareable) at the end of this phase.

- [ ] Heuristics (each a class emitting findings w/ evidence + recommendation_key):
      slow-query (+ shape classifier), big-result-set, query-count/N+1 (incl. content
      scaling comparison), duplicate-queries, slow-http, autoload-bloat, environment
      red flags, duplicate-functionality categories
- [ ] Bundled `rules/rules-snapshot.json` v1: recommendation texts, query-shape ->
      recommendation map (incl. Scalability Pro / SSS / SSF where honest), category map,
      sector signatures, dependency seed, security whitelisting advice texts
- [ ] `class-sspa-demographics.php`: full snapshot + sector inference
- [ ] Overview tab: site score, Top 5 insights narrative, demographics card, storage meter +
      "Delete detailed data older than last 5 runs" button + share-before-delete prompt
      (submission itself lands phase 5 - prompt links to Share tab explaining what's coming
      or is hidden until then)
- [ ] Pages tab: sortable metrics, drill-down (per-component breakdown, worst queries
      pretty-printed with caller stacks), variant switcher
- [ ] Plugins tab: per-component aggregates across pages (inferred badges only for now)
- [ ] History tab: metric trends across runs, "site growing slower?" callout
- [ ] readme.txt changelog + first tagged release (0.5.x): capture + insights, no isolation

Acceptance: on the local store with a deliberately bad test plugin (write one: N+1 loop, a
500-row query, a blocking HTTP call), all planted offences appear as findings naming the
plugin; e2e test asserts exactly that.

## Phase 3 - Deep analysis (culprit isolation)

Goal: "Run Deep Analysis" measures per-plugin cost with confidence labels.

- [ ] MU loader: plugin-set override via `option_active_plugins` (+ sitewide) for token
      requests; plugin-set hash echoed in canary and verified by crawler
- [ ] Theme isolation: `template`/`stylesheet` override to a default theme for token requests
- [ ] `class-sspa-dependency-map.php`: `Requires Plugins` headers + rules seed; fatal-probe
      handling (500 during a half = dependency evidence, re-split)
- [ ] `class-sspa-isolation.php` (pure logic, unit-tested against synthetic cost functions):
      single-out planner for flagged suspects (worst page each) + bisection planner for the
      slowest pages, multi-culprit recursion, request budget cap
- [ ] Noise gate: baseline re-measure at deep-run start, delta threshold
      max(3 x stddev, 30ms); "no measurable impact" recorded as a result, not discarded
- [ ] `sspa_plugin_impacts` writes + Plugins tab upgrade: measured deltas, confidence badges,
      "Measure this plugin" row action
- [ ] Run Deep Analysis button + warning copy (honest per-request-only wording) + progress
- [ ] Spot-profile prompt on `activated_plugin`/`deactivated_plugin` (admin notice, 1-page
      before/after, appends to plugin_impacts)
- [ ] e2e: bad test plugin's measured delta within tolerance of its planted cost; fatal-probe
      test with a dependent-plugin pair

Acceptance: deep analysis on the local store correctly attributes the planted costs, degrades
politely on noise, and never leaves the live plugin set touched (verified by test polling
`active_plugins` throughout the run).

## Phase 4 - Cache impact + mail/order profiling

Goal: the Redis-effectiveness dataset and the email/order-processing measurements.

- [ ] Shim: `enable_loading_object_cache_dropin` filter for token+cache-off requests;
      cache_impact run type re-profiling the top pages cache-on vs cache-off
- [ ] Site-wide toggle fallback (rename object-cache.php) behind the same pre-run
      warning pattern as the db.php swap (off-peak advice, crash-safe restore)
- [ ] Cache-blind / cache-friendly findings per component; Plugins tab column
- [ ] `class-sspa-mail-interceptor.php`: forced recipient override during ALL profiled
      requests (safety rail); wp_mail overhead profile (construct-only mode timing build +
      transport swap); mail timings attributed to trigger component
- [ ] Write profiles (opt-in, never run 1): temp duplicate product/post/order, POST with
      server-generated nonce under the admin session (spike first - see risks), delete after;
      order-processing profile: temp order pending -> processing -> completed, hook cascade +
      email count/time per transition
- [ ] e2e: cache_impact run distinguishes a planted cache-blind plugin from a cache-friendly
      one; write profile leaves zero residue (post counts identical before/after)

Acceptance: cache and mail findings appear with credible numbers on the local store; no mail
ever leaves the box during profiled requests (asserted by a mail-log check in e2e).

## Phase 5 - Community (submission + rules feed + hub MVP)

Goal: opt-in data flows to the hub; rules flow back. Hub companion plugin developed on this
localhost per its own brainstorm - only its MVP endpoints block this phase.

- [ ] `class-sspa-anonymiser.php`: payload builder (normalised SQL only, install UUID,
      salted domain hash, bucketed counts, plugin slugs+versions) + full payload preview UI
      before first submission
- [ ] `class-sspa-submitter.php`: register install (UUID + per-install secret), signed
      submissions, retry queue
- [ ] `class-sspa-rules-feed.php`: fetch + signature verification (public key shipped),
      24h cache, bundled snapshot fallback; rules consumed by heuristics, dependency map,
      security advice, whitelist/blacklist/fragile lists for isolation
- [ ] Share tab: opt-in flow, payload preview, submission history, link to site's anonymous
      entry; wire the share-before-delete prompt to the real flow
- [ ] Settings-snapshot opt-in (feature detection data; allowlisted keys, bucketed values)
- [ ] Hub MVP (separate plugin `super-speedy-performance-hub`, this localhost):
      `POST /submissions` (validate, quarantine, store), `GET /rules` (compiled + signed from
      admin-edited rules), ingest tables + basic rollup cron. Fast-ajax mu route can come
      later; normal REST is fine for MVP. Track detail in the companion brainstorm.
- [ ] e2e: submit from analysis plugin -> hub tables populated -> rules edit on hub ->
      analysis plugin picks it up and a recommendation text changes

Acceptance: round trip works end to end on localhost with anonymisation verified (test greps
payload for domain, emails, raw SQL literals - all absent).

## Phase 6 - Agents (MCP, WP-CLI, skills)

Goal: an LLM can install, run, and interpret the analysis unaided.

- [ ] WP-CLI: `wp sspa run [--type=] [--pages=]`, `wp sspa status`, `wp sspa findings
      --format=json`, `wp sspa impacts --format=json`
- [ ] Abilities API registrations (readonly = GET) + MCP Adapter exposure; follow the
      wp-abilities-api skill gotchas; abilities mirror the CLI surface
- [ ] Findings JSON polish for LLM consumption: stable keys, evidence strings, explicit
      recommendation objects, schema documented in `.docs/`
- [ ] SKILL.md in repo (Claude skill: install -> run -> interpret -> offer deep analysis) +
      OpenAI-equivalent instructions doc; publish
- [ ] KB articles in `.kb/` (getting started, understanding results, security whitelisting,
      methodology) ready for kb-publishing

Acceptance: from a bare Claude Code session pointed at a WP install with only the skill
available, "analyse my site's performance" completes an analysis and produces a correct
plain-English summary without human help.

## Phase 7 - Launch & later

- [ ] Public repo polish: README with screenshots, CONTRIBUTING (rules-data PRs), licence,
      issue templates
- [ ] superspeedy.org promotion pages + methodology page (hub side)
- [ ] Hub: fast-ajax mu acceleration for hot routes, public plugin/category pages + charts,
      LLM classifier + review queue, anti-abuse reputation (companion doc sections 4-6)
- [ ] Parked: SMTP sink full-send mode, deliverability bucket (synthetic only), sector
      benchmark pages, wp.org listing decision, multisite

## Cross-cutting rules (every phase)

- Crash safety first: any state that touches shared files (db.php hold, object-cache rename,
  mu loader) gets install-time health checks, run-end restore, shutdown-handler restore, and
  stale-state self-heal on load. This is the reputational risk; treat it like data loss.
- The plugin must pass its own analysis: no queries without indexes, no autoload bloat, no
  blocking HTTP on page loads, mu loader and shim fast-path measured in ns not ms.
- Every run type is resumable and abortable from the UI.
- readme.txt changelog updated per release (plain text, no HTML); version bumps semver.
- New user-facing behaviour ships with an e2e test in `.tests/` and, where user-visible, a
  `.kb/` article draft.
