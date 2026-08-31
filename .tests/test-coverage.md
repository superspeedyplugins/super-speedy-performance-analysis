# End-to-end test coverage - Super Speedy Performance Analysis

_Generated 2026-08-31. 43 end-to-end tests across eight areas. Convention: the main runner executes each `.tests/cases/*.php` file through `wp eval-file` on a persistent native WordPress and WooCommerce site; the Observatory adds one Chromium test against its real PHP viewer. Unit, mock, planted-row-only, static and pure contract tests are excluded by design._

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
- Export privacy-safe JSON and Markdown through admin, WP-CLI, Abilities and MCP-compatible surfaces.
- Queue opted-in analysis evidence through a durable signed community outbox.
- Collect bounded local traffic and WooCommerce funnel evidence without transmitting visitor data.
- Compare traffic collections and expose performance groups, cache opportunities and automation classes.
- Detect server profiling capabilities and generate installation or host-support instructions without executing commands.
- Retain and explore cross-site development measurements in the local Observatory viewer.
- Prune detailed history, cancel or resume work and optionally remove owned data on uninstall.

## Gaps in e2e coverage

### High risk

- **Crash recovery after process death** - no test kills a run while helper-file holds or queue ownership are active, so a fatal interruption could leave measurement state blocking later work. _Test:_ start a long real run, wait until the hold and job exist, kill the controller process, load WordPress again, then assert stale holds self-heal, the plugin list is unchanged and a new run can start.
- **Browser-driven fallback measurement** - the plugin's fallback for WAF, Basic Auth or loopback blocking has no browser test that completes a real analysis. A regression could leave affected customers unable to measure anything. _Test:_ make the server loopback fail for one target, start the analysis in a browser, drive the browser transport to completion and assert the same profiles and diagnostics as a loopback run.
- **Real Query Monitor coexistence and measurement agreement** - case 06 uses a fake Query Monitor header and proves ownership safety, but it does not run against Query Monitor's real database drop-in or compare query count, time and rows. _Test:_ install and activate Query Monitor on the retained site, profile one deterministic page through both collectors and assert agreement within declared tolerances while Query Monitor keeps ownership of `db.php`.

### Medium risk

- **Logged-in customer page variant** - the synthetic customer cannot use ordinary credentials, but no catalogue run proves a flagged test customer receives the intended logged-in page variant without touching a real account. _Test:_ create the flagged customer and customer-only page output, run the customer variant, then assert the output and profile belong to the synthetic account and its normal login paths remain blocked.
- **Activation-triggered quick spot check** - update-change capture is covered, but the automatic lightweight measurement offered after activation is not driven from activation notice through completed run. _Test:_ activate a fixture plugin, accept the prompt in wp-admin, then assert one bounded spot run records the fixture version and leaves the active plugin set intact.
- **Traffic comparison between two real collection windows** - the CLI and Ability schema are exercised, but not with two distinct collections produced by real requests and changed behaviour. _Test:_ collect a bounded first request set, change one measured delay, collect the second set, compare them and assert duration-normalised deltas, missing-data quality and unchanged privacy fields.
- **Abilities API absence is counted as a pass** - case 12 prints `PASS: SKIP` when the API is unavailable, so the main suite can be green without testing the registered abilities. _Test:_ run the suite on its declared WordPress 6.9+ site, fail setup when the Abilities API is absent, then invoke every registered read and execute ability against real stored evidence.
- **Customer admin interface journeys** - PHP cases render or call most admin paths directly, but no browser test navigates the eight tabs, starts a run, follows live progress, opens a profile or downloads an export. JavaScript and nonce wiring can regress while server tests stay green. _Test:_ use Playwright on the retained site to run one spot analysis from Overview, follow completion, open its page profile and History comparison, then download and validate the export.

### Low risk / nice-to-have

- **Admin-bar controls and deep links** - direct PHP checks cover parts of the menu, but not browser clicks for cache clearing, `sspa_open` links, Markdown download or the first submenu action. _Test:_ open a measured page as administrator, use each visible control and assert the intended panel, cache action or download without a page-navigation mistake.
- **Clipboard and profile-panel interaction** - rendered content is covered but click-to-copy queries, Escape close, attribution switching and cached/fresh presentation have no browser regression. _Test:_ open a stored profile in Playwright, exercise each interaction and assert persistent text, clipboard contents and focus restoration.
- **Environment-specific profiler cards** - the current host exercises one capability combination; installed APM agents, blocked `performance_schema`, XHProf and SPX combinations are not exercised end to end. _Test:_ run the Tools page on controlled hosts or PHP configurations for each status and assert the detected state and generated instructions.
- **Taxonomy-placeholder archive discovery** - archive profiling is covered, but no retained-site case proves a custom post type archive containing a taxonomy placeholder resolves to a real term before profiling. _Test:_ register the archive and term, run catalogue discovery and assert the resolved URL is measured while an empty taxonomy is skipped.

## Notes

- The main suite contains 59 PHP case files. Seventeen are intentionally absent from the count because their central evidence is fabricated arrays or database rows, mocked HTTP, direct helper calls, static source checks or template rendering rather than a real end-to-end scenario.
- Cases 12, 16 and 18 are conditional. In particular, case 18 honestly fails on the current macOS PHP-FPM setup because Excimer is not installed; it is not counted as passing coverage on that machine.
- Cases 20, 45, 47, 52 and 54 are subsystem-level end-to-end tests rather than complete customer journeys. They remain in the count because they exercise real persisted state and the shipped service boundary.
- The build smoke covers opted-in uninstall cleanup separately. It is not counted because this report follows the `.tests` end-to-end convention.
- The Observatory's model, manifest and recorder tests are excluded. Only its Chromium test crosses the viewer's HTTP and browser boundary.
- Cases 21, 22, 35, 39 and 40 remain valuable integration or contract coverage even though they fall outside this report's strict scope.
