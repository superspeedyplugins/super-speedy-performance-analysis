# End-to-end test coverage - Super Speedy Performance Analysis

_Generated 2026-09-08. Audited feature tree31ecf46. 40 real-system scenarios: 37 PHP cases and 3 browser scenarios across the areas below. Convention: `.tests/run-tests.sh` discovers every PHP case under `.tests/cases/`, runs it through `wp eval-file` on a persistent isolated WordPress site, then runs three registered browser scripts; assertions inspect real requests, state or output. Pure/helper and directly fabricated evidence tests are excluded from this E2E count._

The runner currently discovers64 PHP files plus3 browser scenarios. That is an inventory count,
not67 proven end-to-end workflows or a coverage percentage. Failure means a nonzero PHP exit,
a FAIL line, no PASS line, a browser failure or zero matching cases.

## Verified execution versus available coverage

- The final leaf run after rebasing onto core adb471e passed traffic40–43:4/4 cases, and AJAX59/71–73 plus browser:5/5 cases. Raw logs were retained by the implementation session. These totals include contract-only40/59; they are not E2E counts.
- The final presentation-only browser followup passed on d480888. The real SPro fixture measured166ms→47ms server medians,3 samples each and0 HTTP errors; these are synthetic fixture measurements, not customer claims.
- Core has since advanced to e40350d (History0.36.0), while this audited feature31ecf46 is based on adb471e. Rebase/integrate the feature with current core and rerun both History and AJAX regressions before release; this audit changed no source or branch.
- The driver subsequently integrated community retention and provenance validation into31ecf46. This bounded audit did not rerun the entire suite or establish a new complete green run on31ecf46.
- The driver separately reported community20/21/22/23/70 checks passing, and a real conventional baseline yielding boot evidence accepted by receiver processing tests. Durable archive/database retrieval was not verified in that report.
- Source18 requires real web-SAPI Excimer. Its existence does not establish a passing compatible host; follow the README's host restriction rather than installing the known-problematic extension locally.
- The cross-plugin SPro runner provides additional actual WC AJAX and REST identity/owner evidence, but it is not counted as a PA runner case or as a complete AJAX before/after transport matrix.

## Coverage

### Installation, environment and runtime safety

- **01-health.php** - verifies the live schema, secret, helper installation, placeholder replacement and recovery from a stranded profiling drop-in.
- **06-qm-coexist.php** - proves a foreign Query Monitor-shaped `db.php` is preserved while profiling degrades safely and temporary hold/swap mechanics restore it.
- **44-file-mods-disallowed.php** - proves `DISALLOW_FILE_MODS` blocks helper writes, reports the reason and still permits byte-for-byte restoration/removal of owned files.
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
- **36-archive-profile.php** - profiles real archive requests and emits the measured archive sort, filter, query-plan and candidate-index contract.

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
- **38-turnstile-checkout.php** - drives a real checkout against a planted Turnstile contract and proves only the signed synthetic request receives the narrow bypass.
- **43-traffic-woocommerce.php** - records real guest basket, cart, logged-in, order and delayed-payment traffic with keyed joins and no customer, product or order identifiers.
- **50-admin-save.php** - profiles real classic-editor and block-editor save requests, captures slow callbacks and mail attempts, and strips the transport token before normal callbacks.
- **51-workflow-analysis.php** - discovers a live custom post type, resolves current targets and profiles a real no-change REST save with mail measured but not sent.

### Community sharing and public agent contracts

- **23-community-run-integration.php** - runs every analysis type through the controller with sharing enabled and proves one correctly scoped payload is queued per terminal run.

### Real traffic observation and Fast Ajax evidence

- **41-traffic-schema-lifecycle.php** - exercises additive traffic tables, database preflight, signed active-only observer installation, duration conflicts, normal/emergency stops and version mismatch retirement.
- **42-traffic-hot-path.php** - makes a real visitor request through the installed observer and verifies one bounded append, fixed-width path keys, hard event retirement and timestamp expiry.

### Admin and durable state

- **46-admin-tabs-render.php** - renders every real admin tab against the live database and catches SQL errors, empty output and leaked PHP comment text.
- **56-test-account-login.php** - proves the live synthetic customer is low privilege, rejects every credential path and remains usable only through the short-lived profiling cookie.


### AJAX integration and current core response identity

- **69-response-cachebuster.php** - requests real paginated REST responses and proves profiling cache busters do not change output identity while meaningful changes still do.
- **71-fast-ajax-profile.php** - records real AJAX before/after windows and proves include/init/registration/SQL attribution, single-file ownership, immutable loaded sets and measured delay removal.
- **72-fast-ajax-spro.php** - uses actual SPro MU policies to remove a fixture plugin only on an enabled endpoint, retain versions/policy hashes and reject mixed-setup headline deltas.
- **73-fast-ajax-boundaries.php** - records failures and method/mode mismatches, verifies five-per-action sampling and selected-only reservations, expiry and rejection of corrupted saved provenance.

### Registered browser scenarios

- **admin-tabs-browser** - logs into wp-admin and checks tab fragments, browser history, refresh and invalid fragments without JavaScript errors.
- **share-preview-browser** - opens the real Share preview in both navigation orders and verifies ownership and persistence without opting in or sending data.
- **fast-ajax-browser** - opens real saved SPro windows, renders measured results and checks standalone chart export plus matching filtered chart, headline, detailed summary and JSON.

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
- Exposes identity-only endpoint evidence@1 and explicitly partial sampled activity@2 for Scalability Pro.
- Stores run history and durable jobs, supports cancellation/pruning and optional complete uninstall cleanup.
- Exposes reports and execution through the admin UI, WP-CLI, WordPress Abilities API and shared MCP bridge.
- Exports privacy-safe JSON and Markdown and optionally queues immutable anonymised community evidence with retry/backfill controls.

- Records local AJAX windows with selected endpoint/group discovery, immutable capture-time policy and loaded plugin/theme versions.
- Compares compatible AJAX requests by scenario, method, authentication, environment and actual instrumentation, retaining setup periods, failures and raw points.
- Renders prominent server-time comparisons and exports the same filtered chart, summary and JSON; named comparisons reopen saved UUID pairs.
- Limits diagnostic detail sampling to20 requests overall and5 per action, distinguishes registrations from execution and reports coverage gaps.
- Exports conventional saved-run boot/include/hook/render/asset evidence under consent5 or explicit per-run sharing, while passive traffic remains local.

## Gaps in e2e coverage

### High risk

- **Detailed tracing must preserve callback behaviour** - case71 proves ordinary init execution and registration separation, but does not exercise recursive actions, same-priority additions, removal during dispatch, changed accepted arguments, references or request-exiting callbacks as a behaviour-equivalence matrix. _Test:_ run a fixture with each behaviour with tracing off/on → compare response and retained state exactly → verify honest partial counts/timings; leave fixtures. This is the highest-value missing AJAX regression.
- **Boot evidence through durable receiver storage** - case70 starts from a constructed stored capture, and receiver processing acceptance cannot prove archived bytes and persisted data survive the full pipeline. _Test:_ perform a real baseline including includes/hooks/render/assets → explicitly share through an isolated receiver → retrieve the immutable compressed archive and persisted records → compare approved fields and verify prohibited fields absent.
- **AJAX profiling authorisation and control lifecycle** - the browser case compares windows prepared by PHP; it does not drive Start/Stop or refusal of unauthorised/cross-site requests. _Test:_ administrator starts, exercises and stops a real window through the UI → verify saved records → repeat mutation requests as anonymous/subscriber and with missing/invalid nonce → verify rejection and unchanged collector state.
- **Crash recovery across a killed profiling process** - helper recovery is covered, but not a process killed while a foreign database drop-in is held. _Test:_ begin a real profile → kill its worker after swapping the owned helper → make an ordinary request → assert original drop-in restored and the incomplete run reported.

### Medium risk

- **Detail-wide20-sample ceiling and concurrency** - case73 proves5/action and no reservations for excluded endpoints, but not the total ceiling across actions or simultaneous requests/starts/stops. _Test:_ send parallel requests across six real actions → assert at most20 detailed captures, at most5/action, no cross-window claims and successful responses.
- **Detailed HTTP and mail attribution** - case71 verifies SQL attempts; conventional profiler mail/HTTP tests do not exercise the separate AJAX sampler. _Test:_ an AJAX fixture performs one real local HTTP call, a short-circuited HTTP call and a suppressed mail attempt → verify plugin ownership, counts, completed HTTP timing count and no false mail-delivery claim.
- **AJAX authentication, scenario and environment boundaries** - real method/mode and mid-window keep-list changes are covered; separate logged-in/anonymous, changed scenario, plugin/theme upgrade and environment cases are absent. _Test:_ repeat the same action in each controlled context/change → assert intended unmatched series or setup boundaries and unchanged old evidence.
- **AJAX WC/REST before/after matrix** - SPro's contract runner covers transport discovery, while PA71–73 use admin-AJAX actions. _Test:_ profile a real WC AJAX operation and parameterised REST route before/after → assert route-pattern identity, method/auth separation, errors, saved policy and identical export values without literal IDs.
- **Named comparison persistence and endpoint-group selection** - comparison rendering is covered, but saved-name reopening and deterministic group choices are not driven through the browser. _Test:_ discover two classified groups → choose one → start/stop and save a named pair → reload/reopen → assert selected endpoints and comparison UUIDs are unchanged.
- **Nonempty chart filtering and slower/zero/missing states** - the browser verifies an empty filter selection export, not a one-of-several selection; slower wording, zero baseline and missing before data have no rendered-output matrix. _Test:_ retain several real endpoints with controlled faster/slower and failed samples → filter one → verify displayed/exported points, counts and direction; add zero/missing evidence cases without inventing a successful measurement.
- **Helper crash, failed append and schema upgrade in AJAX windows** - traffic lifecycle tests cover general helper state; there is no full upgrade-from-pre-JSON database with active AJAX window, failed capture append or interrupted stop. _Test:_ upgrade a retained old schema/site and exercise a window; separately force a real append failure → assert explicit failure, no fabricated comparison and safe remaining requests.
- **Browser measurement fallback** - actual server-loopback failures are covered, but completing the alternative browser transport is not. _Test:_ block one signed loopback target → complete its offered browser measurement → assert the correct saved profile and attribution.
- **Real Query Monitor cross-check** - case06 uses a Query Monitor-shaped foreign file; it does not validate real QM_DB row/time/count agreement. _Test:_ install real Query Monitor on the dedicated site → measure the same seeded request with both collectors → compare against documented tolerance.

### Low risk / deliberate limits

- **Long-window and many-plugin overhead** - the five-request exploratory benchmark is not a sustained or worst-case test. _Test:_ use many real fixture plugins and hooks over the bounded window → report off/identity/detail wall time, memory, JSON bytes and limit behaviour; do not promote detail by default from the small current measurement.
- **Native page/History renderer compatibility with newly merged core** - AJAX extracted the pure renderer from History70f0276 rather than merging the complete History feature; core has now advanced with History0.36.0. _Test:_ after rebasing this feature, open real page/workflow and AJAX comparisons and export each without changing their distinct compatibility rules.
- **Pinned runtime coverage** - local green does not prove minimum declared PHP/WP or multisite behaviour; multisite is refused by this collector. _Test:_ run the relevant existing cases on supported runtime hosts and verify an actual multisite refusal without writes.

## Notes and excluded checks

The following 27 PHP files remain useful, but are not counted as real-capture E2E here
because their principal assertions use pure helpers, constructed evidence, source checks or
isolated component rendering rather than generating the measurement being claimed:

- `02-token.php`
- `03-fingerprint.php`
- `04-component-map.php`
- `12-agents.php`
- `15-tools.php`
- `20-community-outbox.php`
- `21-community-evidence.php`
- `22-community-backfill.php`
- `24-rules-feed-backoff.php`
- `25-admin-assets.php`
- `34-site-characteristics.php`
- `35-flow-evidence.php`
- `37-component-state.php`
- `39-cache-safety.php`
- `40-traffic-contracts.php`
- `45-http-api-contract.php`
- `45-sql-identifiers.php`
- `46-page-plugin-usage.php`
- `47-markdown-export.php`
- `48-excimer-prompts.php`
- `49-production-test-identity.php`
- `53-review-followup.php`
- `54-run-job-table.php`
- `55-installed-file-versions.php`
- `57-admin-bar-parent.php`
- `59-fast-ajax-endpoint-evidence.php`
- `70-community-boot-evidence.php`

Case59 genuinely starts/stops collection but directly inserts its percentile rows; cases71–73
supply real capture evidence. Case70 tests consent/redaction/encoding of constructed boot data;
case23 creates real runs and outbox payloads but does not complete remote durable storage.
Database-backed fixture tests are not discarded: they simply do not substitute for the missing
capture-to-consumer paths. The new incomplete-provenance regression in73 corrupts one real
retained fixture and verifies explicit rejection, then restores it; this previously identified
boundary is now covered and is not listed as an outstanding missing-provenance gap.

Browser timing, full filter/direct-REST callback timing, SQL timing and mail-delivery timing are
explicitly unavailable in the current AJAX product contract. Their absence is a product limit,
not a claim that tests prove those capabilities. HTTP200 alone never proves workflow correctness.

## Release verdict

The central administrator-controlled admin-AJAX/SPro before-after path has meaningful regression
coverage. Coverage is not yet sufficient to call the new diagnostic sampler and newly shared boot
evidence fully release-ready: close the callback-equivalence, control-authorisation and durable
retention gaps, then rebase onto the newly advanced History core and record the final integrated suite on the exact release candidate. Detail
must remain explicitly opt-in with its partial-coverage language. SQL approval and independent
release/CI enforcement are separate gates; a green focused suite does not satisfy them.

## History core coverage retained during September 8 rebase

The audit above records the earlier AJAX feature inventory. Core History scenarios and the
updated regression fixtures are now included; the following core audit is retained as
historical coverage evidence, not an assertion that either earlier total is the new total.

# End-to-end test coverage - Super Speedy Performance Analysis

_Generated 2026-09-03. 47 end-to-end tests across eight areas. Convention: the main runner executes each `.tests/cases/*.php` file through `wp eval-file` on a persistent native WordPress and WooCommerce site; Playwright drives the real Observatory viewer and the wp-admin History chart. Unit, mock, planted-row-only, static and pure contract tests are excluded by design._

## Coverage

### Installation and measurement health

- **01-health.php** - creates the real schema, secret, MU-loader and database drop-in, then checks placeholder replacement and stale-file recovery.
- **05-run-e2e.php** - crawls the real WordPress and WooCommerce catalogue and admin pages, then checks captures, profiles, component statistics, row counts and cleanup.
- **06-qm-coexist.php** - proves a real run degrades safely around a Query Monitor-shaped foreign database drop-in and restores it after a controlled swap.
- **15-tools.php** - reads the real server capabilities, generates host-specific installation and database grant instructions, and proves the Tools path executes none of them.
- **16-digests.php** - profiles a real query and records performance-schema digest evidence when the server permits it, or reports the real unavailable state.
- **18-excimer.php** - profiles a real request and checks Excimer samples, timings, component attribution and phase buckets when the extension is available.
- **32-loopback-timeout.php** - completes a real 12-second request without a client timeout and preserves a genuine WordPress transport failure through storage and rendering.
- **44-file-mods-disallowed.php** - proves helper installation and run startup refuse file writes when `DISALLOW_FILE_MODS` is active while owned files remain removable.
- **52-release-hardening.php** - exercises the hidden checkout product, run arguments, HTTP wrapper, ownership leases, anonymous bootstrap and persisted uninstall controls.
- **54-run-job-table.php** - persists and advances a real run queue without rewriting immutable jobs, then removes terminal rows.
- **56-test-account-login.php** - blocks every normal login and credential path for the measurement customer while the short-lived signed measurement cookie still works.
- **60-crash-recovery.php** - kills a separate controller process with a real run and foreign drop-in hold active, then proves stale-run recovery restores ownership and permits another run.

### Profiling, findings and reports

- **07-analysis.php** - profiles a deliberately bad plugin and turns its slow query, large result, N+1, duplicate query and blocking HTTP call into attributed findings and a lower score.
- **10-cache-mail-write.php** - distinguishes cache-friendly and cache-blind work with Redis, profiles mail construction without delivery and profiles temporary writes without residue.
- **13-attribution-modes.php** - proves code-owner attribution names WooCommerce while caller attribution names the plugin that initiated the work.
- **14-explain.php** - profiles an unindexed query and attaches its real `EXPLAIN` evidence to the finding.
- **17-adhoc.php** - profiles real front-end and catalogue URLs through the single-page path, reuses catalogue identities and keeps ad-hoc work out of the latest full analysis.
- **26-option-coverage.php** - records real option reads across six pages and distinguishes a large unused autoloaded option from a frequently read non-autoloaded option.
- **28-profile-panel.php** - proves the shared profile panel, private export, catalogue merging, pruned-detail state and page-scoped impact measurement against real profiles.
- **34-site-characteristics.php** - stores and exports a real site-characteristics snapshot with privacy-safe cohort values.
- **36-archive-profile.php** - profiles a real taxonomy archive and records filters, row counts, ordering, SQL and measured versus cached plan costs.
- **37-component-state.php** - captures plugin-published configuration during real ad-hoc runs while rejecting hostile or private state from exported evidence.
- **45-http-api-contract.php** - exposes stored outbound HTTP observations through the report, Ability and CLI paths with privacy-safe aggregation and explicit completeness.
- **47-markdown-export.php** - produces the same privacy-safe Markdown service output for page, site, checkout and history result shapes.
- **61-taxonomy-placeholder-catalogue.php** - resolves a custom post type archive placeholder to its largest non-empty taxonomy term, skips an unresolvable archive and measures the resolved page.

### Plugin Impact Analysis

- **09-deep-e2e.php** - measures a slow plugin by virtual exclusion across cache modes without changing the active plugin set or leaving isolation state behind.
- **27-deep-scoped.php** - limits Plugin Impact Analysis to the requested pages and updates only that measured scope.
- **29-isolation-writes.php** - prevents a dependant plugin from deactivating itself while its dependency is virtually excluded.
- **30-dependency-groups.php** - detects a dependency pair, measures it as one group and keeps fragile groups ineligible.
- **31-reaction-guards.php** - proves destructive hooks and SQL can run when guards are disarmed, then proves the real sweep blocks them, records the reaction and learns the group.

### Checkout and workflow analysis

- **19-checkout-flow.php** - completes real block and classic WooCommerce purchases while checking payment safety, sessions, stock, orders, refunds, HTTP calls, mail and named failure paths.
- **33-order-management.php** - measures viewing, processing, completing, refunding and trashing one real order while keeping management time out of the customer wait.
- **38-turnstile-checkout.php** - completes a real synthetic checkout through Cloudflare Turnstile's scoped bypass and leaves the refunded order recoverable in Trash.
- **50-admin-save.php** - profiles real classic-editor POST and block-editor REST saves, including slow hooks, token stripping and mail evidence.
- **51-workflow-analysis.php** - discovers a real custom post type, launches its controlled editor and profiles a no-change REST save with attempted mail retained as evidence.
- **58-managed-host-guards.php** - refuses a blocked product path before Plugin Impact Analysis or checkout can queue work or create an order.

### History, agents and external evidence

- **12-agents.php** - exercises the registered Abilities API and WP-CLI report, findings, impacts, metrics and traffic surfaces against stored runs.
- **59-history-comparisons.php** - captures plugin changes, runs two real spot measurements and proves comparisons, expectations, privacy export, History rendering, WP-CLI and read-only Abilities output.
- **62-history-setup-series.php** - measures contiguous versioned plugin setups, keeps an A-B-A return separate, excludes failed samples and incompatible runs, and produces the chart's public data document.
- **history-chart.e2e.cjs** - opens wp-admin in Chromium and proves lazy ECharts loading, exact plotted values, metric switching, page filtering, reduced motion and the 480-pixel layout.

### Community sharing

- **20-community-outbox.php** - creates the real privacy-filtered compressed outbox artefact and proves immutable bytes, retries, queue controls and receiver-compatible signatures.
- **23-community-run-integration.php** - turns real baseline, deep and checkout runs into one correctly scoped community payload each, including per-run opt-out and manual sharing.
- **49-production-test-identity.php** - gives a production collector check a fresh identity and registration, then restores the site's original identity and credentials exactly.

### Traffic collection

- **41-traffic-schema-lifecycle.php** - drives real collection start, conflict, normal stop, emergency stop, deletion, observer mismatch and inactive-write protection.
- **42-traffic-hot-path.php** - sends real visitor requests and proves bounded privacy-safe event writes, event-cap retirement and hard expiry without cron.
- **43-traffic-woocommerce.php** - records real guest basket, cart, account, order and payment behaviour without retaining customer identifiers.

### Development Observatory

- **viewer-browser.e2e.js** - opens the real PHP viewer in Chromium and proves feature drill-down, build and state filters, repeated-request evidence, additive selection, URL state and keyboard access.

## Functionality map

- Install and maintain the database schema, MU-loader and database drop-in without overwriting foreign owners.
- Run baseline, spot and single-page measurements over signed, cache-busted front-end and admin requests.
- Capture generation, SQL, HTTP, memory, cache, mail, hook, include, asset and function-level observations.
- Attribute work by code owner or initiating caller and explain slow queries with database plans.
- Turn measurements into findings, recommendations, site scores and per-page or per-plugin reports.
- Measure plugin impact through virtual exclusion, cache modes, dependency groups and reaction guards.
- Profile real WooCommerce checkout, order management, classic-editor saves and block-editor REST saves.
- Detect blocked loopbacks and support browser-driven measurement where the server cannot fetch itself.
- Record site characteristics, component versions, component settings and option usage.
- Compare saved analyses, update-triggered change sets, declared expectations and learned output signatures.
- Group adjacent runs by measured plugin and theme versions, compare every retained point and median, and expose the same chart evidence as an accessible table.
- Export privacy-safe JSON and Markdown through admin, WP-CLI, Abilities and MCP-compatible surfaces.
- Queue opted-in analysis evidence through a durable signed community outbox.
- Collect bounded local traffic and WooCommerce funnel evidence without transmitting visitor data.
- Compare traffic collections and expose performance groups, cache opportunities and automation classes.
- Detect server profiling capabilities and generate installation or host-support instructions without executing commands.
- Retain and explore cross-site development measurements in the local Observatory viewer.
- Prune detailed history, cancel or resume work and optionally remove owned data on uninstall.

## Gaps in e2e coverage

### High risk

- **Browser-driven fallback measurement** - the plugin's fallback for WAF, Basic Auth or loopback blocking has no browser test that completes a real analysis. A regression could leave affected customers unable to measure anything. _Test:_ make the server loopback fail for one target, start the analysis in a browser, drive the browser transport to completion and assert the same profiles and diagnostics as a loopback run.
- **Real Query Monitor coexistence and measurement agreement** - case 06 uses a fake Query Monitor header and proves ownership safety, but it does not run against Query Monitor's real database drop-in or compare query count, time and rows. _Test:_ install and activate Query Monitor on the retained site, profile one deterministic page through both collectors and assert agreement within declared tolerances while Query Monitor keeps ownership of `db.php`.

### Medium risk

- **Logged-in customer page variant** - the synthetic customer cannot use ordinary credentials, but no catalogue run proves a flagged test customer receives the intended logged-in page variant without touching a real account. _Test:_ create the flagged customer and customer-only page output, run the customer variant, then assert the output and profile belong to the synthetic account and its normal login paths remain blocked.
- **Activation-triggered quick spot check** - update-change capture is covered, but the automatic lightweight measurement offered after activation is not driven from activation notice through completed run. _Test:_ activate a fixture plugin, accept the prompt in wp-admin, then assert one bounded spot run records the fixture version and leaves the active plugin set intact.
- **Traffic comparison between two real collection windows** - the CLI and Ability schema are exercised, but not with two distinct collections produced by real requests and changed behaviour. _Test:_ collect a bounded first request set, change one measured delay, collect the second set, compare them and assert duration-normalised deltas, missing-data quality and unchanged privacy fields.
- **Abilities API absence is counted as a pass** - case 12 prints `PASS: SKIP` when the API is unavailable, so the main suite can be green without testing the registered abilities. _Test:_ run the suite on its declared WordPress 6.9+ site, fail setup when the Abilities API is absent, then invoke every registered read and execute ability against real stored evidence.
- **Customer admin interface journeys outside History** - the History chart now has a real browser journey, but no browser test starts a run, follows live progress, opens a profile or downloads an export. JavaScript and nonce wiring outside History can regress while server tests stay green. _Test:_ use Playwright on the retained site to run one spot analysis from Overview, follow completion, open its page profile, then download and validate the export.

### Low risk / nice-to-have

- **Admin-bar controls and deep links** - direct PHP checks cover parts of the menu, but not browser clicks for cache clearing, `sspa_open` links, Markdown download or the first submenu action. _Test:_ open a measured page as administrator, use each visible control and assert the intended panel, cache action or download without a page-navigation mistake.
- **Clipboard and profile-panel interaction** - rendered content is covered but click-to-copy queries, Escape close, attribution switching and cached/fresh presentation have no browser regression. _Test:_ open a stored profile in Playwright, exercise each interaction and assert persistent text, clipboard contents and focus restoration.
- **Environment-specific profiler cards** - the current host exercises one capability combination; installed APM agents, blocked `performance_schema`, XHProf and SPX combinations are not exercised end to end. _Test:_ run the Tools page on controlled hosts or PHP configurations for each status and assert the detected state and generated instructions.

## Notes

- The main suite contains 62 PHP case files. Seventeen are intentionally absent from the count because their central evidence is fabricated arrays or database rows, mocked HTTP, direct helper calls, static source checks or template rendering rather than a real end-to-end scenario.
- Cases 12, 16 and 18 are conditional. In particular, case 18 honestly fails on the current macOS PHP-FPM setup because Excimer is not installed; it is not counted as passing coverage on that machine.
- Cases 20, 45, 47, 52 and 54 are subsystem-level end-to-end tests rather than complete customer journeys. They remain in the count because they exercise real persisted state and the shipped service boundary.
- The build smoke covers opted-in uninstall cleanup separately. It is not counted because this report follows the `.tests` end-to-end convention.
- The Observatory's model, manifest and recorder tests are excluded. Its Chromium viewer journey and the separate wp-admin History chart journey cross real HTTP and browser boundaries.
- Cases 21, 22, 35, 39 and 40 remain valuable integration or contract coverage even though they fall outside this report's strict scope.
