# End-to-end test coverage - Super Speedy Performance Analysis

_Generated 2026-09-04. 49 real-data E2E cases across 7 areas. Convention: `.tests/run-tests.sh` runs every self-contained PHP file in `.tests/cases/` through `wp eval-file` against the persistent parallel-dev WordPress site; this audit counts cases that exercise live WordPress/WooCommerce state, database rows, filesystem changes or real HTTP measurement requests. Pure helper, static-fixture and source-hash cases are excluded by design._

## Coverage

### Installation, environment and runtime safety

- **01-health.php** - verifies the live schema, secret, helper installation, placeholder replacement and recovery from a stranded profiling drop-in.
- **06-qm-coexist.php** - proves a foreign Query Monitor-shaped `db.php` is preserved while profiling degrades safely and temporary hold/swap mechanics restore it.
- **15-tools.php** - detects the real PHP, database, operating system and package manager and generates host-specific installation guidance without executing it.
- **44-file-mods-disallowed.php** - proves `DISALLOW_FILE_MODS` blocks helper writes, reports the reason and still permits byte-for-byte restoration/removal of owned files.
- **45-sql-identifiers.php** - executes the `%i`-prepared table queries against the live schema and checks both SQL success and returned rows.
- **49-production-test-identity.php** - isolates a production check behind a fresh installation identity and restores the site's original credentials and options exactly.
- **52-release-hardening.php** - checks the reusable hidden checkout product, bounded signed profiling requests, atomic run ownership, lazy bootstrap and opt-in uninstall state on the live site.

### Baseline analysis, attribution and reports

- **05-run-e2e.php** - runs a complete baseline from catalogue through real loopbacks, capture ingestion, profile/stat storage and capture draining without changing active plugins.
- **07-analysis.php** - plants a deliberately slow plugin, profiles it and proves the findings engine reports its slow query, large result, loop, duplicate and HTTP offences.
- **13-attribution-modes.php** - profiles a plugin calling WooCommerce in a loop and proves code-owner and caller attribution produce the intended different owners.
- **14-explain.php** - runs real `EXPLAIN` plans safely, preserves full plan/index evidence and identifies an unindexed query without mutating data.
- **16-digests.php** - exercises the live performance-schema capability path when available and otherwise proves the real graceful no-op path and digest lifecycle.
- **17-adhoc.php** - runs Analyse this page end to end, normalises and guards the URL, stores the result and keeps ad-hoc runs out of site-wide latest-run queries.
- **18-excimer.php** - drives a normal profiled request and requires real web-SAPI Excimer samples and attribution when the extension is available.
- **26-option-coverage.php** - records real option reads through the early drop-in and turns them into correct autoload recommendations without leaking plugin bookkeeping.
- **28-profile-panel.php** - renders one stored profile consistently by ID and URL, merges catalogue-matched ad-hoc results and runs a correctly estimated one-page impact sweep.
- **32-loopback-timeout.php** - profiles a real 12-second page and preserves an actual WordPress transport failure through storage and the rendered profile panel.
- **34-site-characteristics.php** - snapshots the real site's cohort dimensions, stores them, exports privacy-safe size bands and verifies unsafe exact values do not escape.
- **36-archive-profile.php** - profiles real archive requests and emits the measured archive sort, filter, query-plan and candidate-index contract.
- **37-component-state.php** - runs real measurements with registered component-state filters and proves valid settings export while hostile component data is isolated.
- **45-http-api-contract.php** - persists supported capture generations and verifies the privacy-safe outbound HTTP inventory through PHP, Abilities, WP-CLI and community evidence.
- **47-markdown-export.php** - builds page, site and checkout reports from stored evidence and verifies both UI actions use the same privacy-safe Markdown.
- **48-excimer-prompts.php** - renders stored profiles with and without samples and keeps phase detail usable while linking missing data to the correct installation guidance.

### Plugin Impact Analysis and isolation safety

- **09-deep-e2e.php** - runs the full two-phase plugin sweep against a planted slow plugin across available cache modes and proves the live plugin set is untouched.
- **27-deep-scoped.php** - proves `--pages` and one-page remeasurement constrain real impact work, preserve other verdicts and refresh the intended plugin row.
- **29-isolation-writes.php** - reproduces a dependant self-deactivation during a real isolation request and proves the active-plugin write guard prevents a permanent site change.
- **30-dependency-groups.php** - discovers a real dependency pair, excludes it together, avoids orphan reactions and records the grouped verdict without bypassing fragile-plugin rules.
- **31-reaction-guards.php** - executes destructive reaction paths with guards disarmed, then proves the real sweep silences hooks, refuses destructive SQL, records the reaction and learns the group.
- **58-managed-host-guards.php** - rejects platform-owned cache-off evidence and preflights the exact Plugin Impact and checkout paths before queueing work or creating an order.

### Checkout, commerce and write workflows

- **10-cache-mail-write.php** - measures cache-aware and cache-blind fixtures, real mail construction and temporary write profiles while leaving no object residue.
- **19-checkout-flow.php** - drives a full WooCommerce purchase through the public run controller, including payment safety, mail modes, integrations, cleanup and classic checkout.
- **33-order-management.php** - profiles real order view, completion, refund and Trash actions and keeps staff-management time separate from the customer's checkout wait.
- **35-flow-evidence.php** - persists blocked, partial and legacy management sequences and exports their outcome accurately instead of inventing complete flow evidence.
- **38-turnstile-checkout.php** - drives a real checkout against a planted Turnstile contract and proves only the signed synthetic request receives the narrow bypass.
- **43-traffic-woocommerce.php** - records real guest basket, cart, logged-in, order and delayed-payment traffic with keyed joins and no customer, product or order identifiers.
- **50-admin-save.php** - profiles real classic-editor and block-editor save requests, captures slow callbacks and mail attempts, and strips the transport token before normal callbacks.
- **51-workflow-analysis.php** - discovers a live custom post type, resolves current targets and profiles a real no-change REST save with mail measured but not sent.

### Community sharing and public agent contracts

- **12-agents.php** - executes the registered Abilities and WP-CLI surfaces through their real permission, validation and output-schema paths.
- **20-community-outbox.php** - writes real outbox rows and proves consent gating, immutable compressed bytes, idempotency, retry and pause/resume behaviour.
- **21-community-evidence.php** - stores representative run evidence for every run type and builds one privacy-checked versioned payload for each.
- **22-community-backfill.php** - operates on stored historical runs to prove bounded, resumable backfill and exact per-outbox previews.
- **23-community-run-integration.php** - runs every analysis type through the controller with sharing enabled and proves one correctly scoped payload is queued per terminal run.

### Real traffic observation and Fast Ajax evidence

- **41-traffic-schema-lifecycle.php** - exercises additive traffic tables, database preflight, signed active-only observer installation, duration conflicts, normal/emergency stops and version mismatch retirement.
- **42-traffic-hot-path.php** - makes a real visitor request through the installed observer and verifies one bounded append, fixed-width path keys, hard event retirement and timestamp expiry.
- **59-fast-ajax-endpoint-evidence.php** - drives the public endpoint start/status/stop/report lifecycle over bounded stored observations and verifies grouping, percentiles, failure distribution, finalised-report readability, honest unknown activity and privacy boundaries.
- **Scalability Pro e2e/run-fast-ajax-pa-contract.sh** - sends real admin-AJAX, WooCommerce AJAX and patterned REST requests through the installed observer and verifies exact identity, status, timing, query, overhead, owner, permission and recursive-dependency evidence through the public contract.

### Admin and durable state

- **46-admin-tabs-render.php** - renders every real admin tab against the live database and catches SQL errors, empty output and leaked PHP comment text.
- **53-review-followup.php** - verifies deferred tab rendering, retry grace, conflicting admin-change refusal and cancellation consuming the stored digest snapshot.
- **54-run-job-table.php** - writes and reloads ordered immutable job rows, advances only mutable state, appends phase jobs and deletes terminal queue rows.
- **56-test-account-login.php** - proves the live synthetic customer is low privilege, rejects every credential path and remains usable only through the short-lived profiling cookie.

## Functionality map

- Installs, upgrades, repairs and removes the profiling schema, MU loader and conditional database drop-in.
- Catalogues public, WooCommerce, archive, search and wp-admin targets for baseline, spot and ad-hoc analysis.
- Sends signed loopback requests, detects page-cache interception and can use an authenticated browser transport when ordinary loopbacks are blocked.
- Captures query time, rows, errors, stack attribution, memory, HTTP calls, object-cache activity, mail and option reads.
- Attributes work by code owner or initiating caller and enriches expensive SQL with safe `EXPLAIN` evidence and optional MySQL digests.
- Produces scored findings for slow SQL, large results, query loops, duplicates, blocking HTTP, cache behaviour, mail and autoload bloat.
- Runs dependency-aware Plugin Impact Analysis by virtually excluding plugins only for signed measurement requests.
- Prevents isolated plugins from changing the live plugin set, running activation/deactivation reactions or issuing destructive SQL.
- Profiles function-level CPU work with Excimer when the web SAPI provides it.
- Profiles real WooCommerce checkout, payment, order-management and refund/Trash workflows using isolated synthetic objects.
- Profiles authenticated classic and REST editor saves without sending mail or leaking measurement tokens into normal callbacks.
- Records archive query shapes and exposes a stable archive-index recommendation contract.
- Reports per-page plugin usage, output identity, dependencies and unload-safety classifications.
- Scans shared-cache hazards and reports existing dynamic-fragment coverage without persisting response values.
- Inventories outbound WordPress HTTP calls with privacy-safe endpoints, ownership, purpose and blocking safety.
- Observes bounded real traffic, WooCommerce funnel events and before/after opportunities using a generated active-only MU observer.
- Exposes bounded Fast Ajax endpoint observations through `sspa/endpoint-evidence@1` for Scalability Pro.
- Stores run history and durable jobs, supports cancellation/pruning and optional complete uninstall cleanup.
- Exposes reports and execution through the admin UI, WP-CLI, WordPress Abilities API and shared MCP bridge.
- Exports privacy-safe JSON and Markdown and optionally queues immutable anonymised community evidence with retry/backfill controls.

## Gaps in e2e coverage

### High risk

- **Crash recovery is not tested across a killed PHP process** - the suite repairs leftover helper state but never kills a run while a foreign `db.php` is held, so a crash could strand the site on the profiling drop-in. _Test:_ begin a real run in a separate process -> kill it after the hold/swap -> load the next ordinary request -> assert the original drop-in is restored and the stale run surfaces its failure._

### Medium risk

- **The real Query Monitor integration is not cross-checked** - case 06 uses a fake header and only proves coexistence/degraded operation, not query count, time or returned-row agreement with `QM_DB`. _Test:_ install real Query Monitor -> profile the same seeded page with both collectors -> assert counts, timings and rows agree within documented tolerance._
- **Browser-driven fallback is not exercised by the automated suite** - ordinary loopbacks and transport failures are covered, but a security-blocked target completed through the browser path could regress unnoticed. _Test:_ block signed server loopbacks for one fixture page -> complete the offered browser measurement -> assert the capture, attribution and stored profile match the requested page._
- **Logged-in customer catalogue coverage remains indirect** - case 56 proves the synthetic account can be authenticated by cookie and checkout covers a customer session, but a baseline catalogue run does not prove ordinary customer-only pages are discovered and profiled. _Test:_ expose a fixture account page only to the synthetic customer -> run a baseline with the customer variant -> assert that page is catalogued, requested with the measurement cookie and stored under the correct variant._

### Low risk / nice-to-have

- **Fast Ajax route-shape edges are not represented in stored evidence** - parameterised REST routes, non-2xx status classes and method sets beyond POST have no real request matrix. _Test:_ register one parameterised REST route with GET/PUT plus 3xx/4xx/5xx responses -> request each -> assert normalised route, method and status buckets without raw IDs._
- **Live collector/R2 delivery remains manual** - local cases cover immutable payloads and retry state, but credentials and permanent external writes correctly keep the production transport out of the automatic runner. _Test:_ retain the guarded manual production scripts as the release check and record their receipt UUID/hash evidence when exporter or transport code changes._
- **Excimer has no passing local web-SAPI environment on this machine** - case 18 correctly fails without a safe extension build, so function sampling is specified but not continuously green here. _Test:_ run the existing case on an x86_64 PHP-FPM host with a distro-packaged Excimer build and retain the resulting capture evidence._

## Notes

- The 10 runner cases excluded from the E2E count are `02-token.php`, `03-fingerprint.php`, `04-component-map.php`, `24-rules-feed-backoff.php`, `25-admin-assets.php`, `39-cache-safety.php`, `40-traffic-contracts.php`, `46-page-plugin-usage.php`, `55-installed-file-versions.php` and `57-admin-bar-parent.php`; they remain useful pure logic, static fixture, source/file hash or component-rendering checks.
- Case 59 uses controlled direct inserts to pin aggregation and lifecycle semantics. The retained cross-plugin runner supplies the separate real-request evidence for the MU observer hot path and ownership resolver.
- Opt-in complete uninstall cleanup is covered by the separate build smoke described by case 52, not by `.tests/run-tests.sh` itself.
- The suite's existing retained-site model intentionally clears fixtures on the way in and leaves completed evidence available for inspection.
