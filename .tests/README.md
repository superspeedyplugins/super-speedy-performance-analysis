# Super Speedy Performance Analysis - test suite

The suite runs against a dedicated **native** WordPress site - NEVER against the local
superspeedy install (that install is used for other testing and gets `wp reset` regularly).

**No Docker.** Converted 16 August 2026. The environment is a parallel-dev site: nginx +
php-fpm + mariadb, created in about three seconds, costing a directory and a database.
The Docker pair it replaced needed a Colima VM reserving 6 GB and 4 CPUs before a single
WordPress started. `.tests/docker/` is gone; do not reintroduce it.

## Running the tests

```bash
.tests/setup-site.sh           # create/top up the test site (idempotent)
.tests/setup-site.sh --reset   # reset this dedicated site at entry, then retain it
.tests/run-tests.sh            # all PHP cases and registered browser journeys
.tests/run-tests.sh e2e        # run only cases whose filename contains "e2e"
.tests/run-tests.sh history-domain-unit # comparison rules without WordPress, storage or rendering
.tests/run-tests.sh history-graceful-unit # production comparison logic with storage adapters, no database
.tests/run-tests.sh 78-history-graceful # real WordPress retained-record comparison regressions
.tests/run-tests.sh 79-admin-bar # admin-bar "This site" nodes deep-link to Tools by fragment; no MySQL digests node exists
.tests/run-tests.sh 80-checkout-type # preflight names a checkout block, shortcode or unsupported page by its real content
.tests/run-tests.sh 143-checkout-address # GR store with no postcode completes block and classic purchases; a country with no real postcode stops with checkout_address_incomplete before the cart
.tests/run-tests.sh 144-overview-insight # Overview insights link to the measured URL and to that page's analysis (Pages deep link)
.tests/browser/run-insight-links.sh # after case 144 on the same site: Page analysis click opens the panel, the deep link selects Pages
.tests/run-tests.sh 145-endpoint-profile # wc-analytics/reports profiled as the admin (REST nonce) answers 200; logged-out answers 401 with a clear explanation, never "pruned"
.tests/run-tests.sh 146-slow-query # core-executed CTE search query: every shape (like before found_rows), a plan, runnable SQL, real caller, page_url
.tests/run-tests.sh 147-admin-render # pending change set + a synthetic account WordPress refuses to repair: ordinary admin screens and the front end load whole, no user is written, ordinary mail keeps its recipient
.tests/run-tests.sh 148-compare-paging # Compare shows 50 runs per page: Older/Newer links over real AJAX, a page-2 pair compares, Back returns to its page; adds 57 runs per run
.tests/run-tests.sh 81-temp-registry # checkout temp registry survives a cross-process write before cleanup
.tests/run-tests.sh 05-run-e2e # full baseline; installs the Home query fixture (four known queries on Home) to prove real query capture
.tests/run-tests.sh 83-history-declared # declared expectations judge the approved HTTP response code separately from the output signature
.tests/run-tests.sh 84-history-export # planted foreign fields never cross the History export boundary; every level holds its declared key set (JSON, CLI, Abilities)
.tests/run-tests.sh 85-version # one plugin-version normaliser at every History entry point, with explicit invalid/pre-release behaviour
.tests/run-tests.sh 86-plugin-change # one validated plugin-change record shared by capture, the run context and History
.tests/run-tests.sh 87-customer-variant # logged-in customer measurements: catalogue, fresh synthetic account, labels, export, History
.tests/run-tests.sh 88-query-monitor # real Query Monitor agreement on its dedicated site: tolerances, QM ownership preserved even for obsolete swap requests
.tests/browser/run-regressions.sh loopback-fallback # real loopback failure falls back to browser transport with complete evidence
.tests/browser/run-regressions.sh quick-comparison # detected plugin update -> notice -> one spot run -> History names the change
.tests/run-tests.sh 92-traffic-deactivation # dedicated lifecycle/concurrency regression, retains its own site
.tests/run-tests.sh 99-community-preview # real captured SQL literals/comments, manual preview, object privacy and immutable archive replay; HTTP intercepted
.tests/run-tests.sh 98-community-transport # HTTPS required and service HTTP arguments exclude site URL; all requests intercepted
.tests/run-tests.sh 96-token-consumption # signed requests stay one-use after capture read/discard
.tests/run-tests.sh 95-community-receipt # lost completion reply, valid existing receipt retry and identity/hash rejection
.tests/run-tests.sh 94-request-block-evidence # signed HTTP success/denial, challenge text, per-request reasons, legacy flags and profile wording
.tests/run-tests.sh 93-review-correctness # dedicated non-WooCommerce browser start, History mean and archive cap
.tests/run-tests.sh 91-markdown-sql-privacy # real duplicate-query finding exports its shape without data literals
.tests/run-tests.sh 90-write-probe-stock # real write run preserves catalogue stock and measures only its own temporary product
.tests/run-tests.sh 89-traffic-window # two real traffic windows with a controlled slowdown: normalised deltas, unavailable states, CLI and Abilities agree
.tests/observatory: npm test # 19 node tests, including sites-root discovery from parallel-dev and the preparation prerequisite checks
.tests/browser/run-regressions.sh translations # cookie-scoped PHP/JS translation catalogue, real AJAX controls and History loading/error states
.tests/browser/run-regressions.sh admin-mobile # all eleven tabs at phone/desktop widths, LTR and a real Hebrew (RTL) admin locale
.tests/browser/run-regressions.sh tab-retry # network/server tab errors and explicit recovery
.tests/browser/run-regressions.sh shared-link # real shared Plugin Impact link, reload and bookmark
.tests/browser/run-regressions.sh admin-journey # admin-bar Analyse this page and cache control, attribution switching, Re-run, Escape and focus restoration
.tests/browser/run-regressions.sh admin-lazy-panel # Analyse this page on core wp-admin screens: no panel files until the first click, which profiles that admin URL; ?sspa_open=page; editor stays eager
.tests/browser/run-regressions.sh panel-error-link # disabled profiler: the panel error links to Tools, where Enable profiler turns it back on (leaves it enabled)
.tests/run-tests.sh 82-management-from # order management records the real before-status, not a cached draft
.tests/run-tests.sh admin-tabs # real browser URL-fragment navigation regression
.tests/run-tests.sh 135-compare # History renamed Compare, the share panel on PA screens only, community figures fetched only on click
.tests/run-tests.sh 137-markdown # a real opt-in raw-stream read of a URL with a query token, an email and a number in its path; none reaches the page Markdown
bash .tests/compare/run.sh   # case 135 and the Compare/share-panel browser journey from the admin menu, on tests-compare
.tests/run-tests.sh share-preview-browser # preview ownership and refresh persistence
SSPA_START_AT=23 .tests/run-tests.sh # resume at the first case whose name sorts at/after 23
.tests/browser/run-history-chart.sh  # real wp-admin History chart journey in Chromium
```

The History browser journey verifies separate warning-square and error-triangle controls,
unchanged timing/evidence when highlighting is disabled, and preferences across comparison
refreshes, using retained warning and failed-request fixtures.
It also verifies that changing metrics preserves the page filter
in both the plotted categories and accessible comparison table.
It verifies vertical compact name labels and category dividers; the chart uses unique
scenario keys so simplified labels cannot combine separate measurements. Case 62 checks that
the retained Home URL reaches the chart without profiling arguments.

`.tests/run-tests.sh history-tooltips-browser` uses the retained completed Home comparison
without starting an analysis. It verifies actual axis/point hover, name-only labels, instant
HTML tooltip timing, escaped label/URL content, pointer exit and narrow-screen containment.
Prepare the usual History browser fixtures first if the isolated site has no completed runs.

While the suite owns a site, monitor its filesystem log rather than sending additional
HTTP or WP-CLI requests to that site. A separate WP-CLI bootstrap during an active traffic
collection also records an origin event. Such a status probe changes case 89's exact
request count and daily-request denominator; its controlled windows require exclusive use.

For a manually requested warning demonstration on a dedicated local site, install
`.tests/fixtures/history-shop-warning.php` as an MU plugin on that site only, then run a full
baseline. It emits a labelled PHP warning only while PA profiles the WooCommerce Shop page;
ordinary page visits are unaffected. Keep the healthy preceding run for comparison. This
fixture is not installed automatically by the suite and is excluded from customer builds.

For package smoke tests from a parallel worktree, supply a unique `SSPA_SMOKE_TAG` to
`.build/smoke-test.sh` so another checkout's retained edition site is not reset.

Run these from **bash**, not zsh: `env.sh` derives the plugin directory from `BASH_SOURCE`,
which zsh does not set when the file is sourced interactively.

Browser cases require Node.js and the pinned Playwright 1.61.1 dependencies installed with
`npm ci --prefix .tests`. Install its matching Chromium with
`.tests/node_modules/.bin/playwright install chromium` if Chromium revision 1228 is absent. `SSPA_PLAYWRIGHT_MODULE` can select an existing Playwright
installation. It checks fresh History links, changed fragments, back/forward, reload and
invalid fragments through the authenticated admin page without changing analysis data.
The Share preview browser case uses the same dependency and verifies both navigation orders,
Share alone, preview toggling and persistence across a real tab refresh. It requires an
existing completed analysis and does not opt in or submit an analysis.

### Community boot evidence

`SSPA_SCENARIO=tests-ajax-retention bash .tests/run-tests.sh 70-community-boot`
checks the real run exporter with retained synthetic boot evidence: exact timings,
component versions, page relationships, consent version 5, custom callback redaction and
invalid metrics. It creates no outbox item and sends no submission. Set
`SSPA_BOOT_PAYLOAD_FILE=/tmp/sspa-boot-producer.json` to hand its complete payload to the
receiver's `TestManifestRealBootProducer` test. Cases 20–23 cover the existing immutable
outbox, evidence families, backfill and actual run controller.

### Central E2E observatory

The separate observatory measures PHP request time and correlated PHP faults across declared
parallel-development sites. Its permanent MU recorder is installed by parallel-dev but remains
dormant unless a signed measurement request arms it.

```bash
cd .tests/observatory
npm install
npm test
npm run observatory:prepare -- scalability-pro
npm run observatory:run -- scalability-pro
npm run observatory:view
```

The viewer runs at `http://127.0.0.1:8791/`. Raw request envelopes, SQLite data and screenshots
stay in the gitignored `.data/e2e-observatory/` directory. The current manifest measures the
Scalability Pro default and `tests-e2e` sites. It records the active theme slug, version and
measured classic/hybrid/block-FSE type with each run.

This is a local observatory, not the plugin's regression oracle. The suite below still decides
pass or fail for Performance Analysis itself.

The site is **persistent**, not per-run - 43 case files against a fresh install every time
would be slow, and the cases are written to be idempotent against a standing site. When one
leaves residue behind, `--reset` is the cure.

### A second environment alongside the default one

The scenario name is overridable, so a second checkout - a parallel session working on a
feature branch - can run its own site without colliding with the default one:

```bash
export SSPA_SCENARIO=mybranch
.tests/setup-site.sh && .tests/run-tests.sh
```

A test case file passes when it prints at least one `PASS` line and no `FAIL` lines.
`run-tests.sh` exits non-zero if any case fails.

### Compare and the share panel

`bash .tests/compare/run.sh` (site `tests-compare`, override with `SSPA_COMPARE_SCENARIO`) runs case 135 and then
`.tests/compare/journey.e2e.cjs`; `case` or `journey` runs one of them. Both clear sharing, the snooze and
"Don't ask me again" on the way in and leave the site as they finished. The `compare-http-observer.php` MU fixture
logs every WordPress HTTP API request to superspeedy.org in the `sspa_test_ssorg_log` option. With option
`sspa_test_ssorg_mode` set to `fixture` it answers `/api/v1/cohorts` and `/api/v1/plugin-impact` from
`.tests/fixtures/superspeedy-org/`, which hold the exact bodies superspeedy.org's own publication build emits
(see `provenance.json` there; regenerate them, never hand-edit them). With `live` it fetches the real documents and
hands back their real status and body, so case 135 checks the plugin against what superspeedy.org serves today.
Without a mode the regression boundary refuses the request, as for any external host.

### Request a fix quote

`bash .tests/quote-request/run.sh` (case 136 wraps it; `case` or `journey` runs one part) runs
`.tests/quote-request/assert-quote.php` and then `.tests/quote-request/journey.e2e.cjs` on `tests-quote`
(override with `SSPA_QUOTE_SCENARIO`). The receiver is a second retained site, `tests-quote-receiver` (override with
`SSPA_QUOTE_RECEIVER_SCENARIO`), running the real Super Speedy Contact code from
`superspeedyplugins.com/super-speedy-contact`, frozen at its commit with `git archive` (the commit is in its
`.regression-source-commit`). PA reaches it because the runner defines `SSPA_QUOTE_REQUEST_TEST_ENDPOINT` in
`tests-quote`'s wp-config.php: a test-only constant read by `SSPA_Quote_Request::endpoint()`, not a setting. Without
it PA posts to superspeedyplugins.com; tests never do, and the regression boundary refuses every external host
anyway. `quote-http-observer.php` records (never answers) every request to `/ssp-contact/v1/quote-request` in
`sspa_test_quote_log`. `quote-receiver.php` on the receiver records mail instead of sending it, applies
`sspa_test_receiver_limits` through Contact's own `ssp_contact_rate_limits` filter (the 429 step) and, with
`sspa_test_receiver_fail_storage`, makes WordPress refuse the lead insert (the real 503 path). The 413 step analyses a
page that reads 1,700 raw streams (`quote-raw-stream.php`), giving a real report over 200 KB. Leads, rate-limit
windows and knobs are cleared on the way in; evidence is under `.data/quote-request/`.

Case 137 (`137-markdown-raw-stream-urls`) runs on the suite site: a real opt-in raw-stream analysis of a page reading a
URL with a query token, an email address and a number in its path, checking none reaches the page Markdown. Case 138
(`138-profiler-cli`) checks `wp sspa profiler enable|disable` leave Tools exactly as the buttons do.

### WordPress.org behaviour regressions

Cases 103 to 109 cover build metadata, bundled-only rules, explicit profiler consent,
mail-recipient boundaries, Traffic JSON configuration, neutral recommendation links and
community consent notices. Run a focused case with its filename substring, for example
`.tests/run-tests.sh 105-profiler`. Case 106 captures real SMTP envelopes and replacement mailer recipients, checks owned-target
workflows for all five content types, verifies stored/rendered replacement warnings, cleanup,
untouched real records and stock, and counts the complete baseline/write mail sends.

Set `SSPA_SITE_SCOPE` to the exact resolved native site directory to forbid nested suite
scenarios from touching any other site. Cases requiring those separate sites report failures;
they are neither skipped nor counted as passing. `SSPA_SCENARIO=tests-wporg-readiness`
selects the W1 site. Fixture preparation explicitly enables the profiler; production
activation, screen opening and analysis start never enable it.

W5b cases 114 to 117 cover private workflow/write objects, deferred and late mail recipients, abandoned-object cleanup, explicit setup failures, visitor-safe admin upgrades, helper-lock retries and guarded authority HTTP access, plus a separate native legacy-CPT workflow browser case. Override its site with SSPA_LEGACY_SCENARIO; the main suite remains on HPOS. The registered workflows-private browser journey checks the saved status through the real page/product/order controls.

Case 110 drives real EXPLAIN/index discovery on prefixed, unprefixed and explicitly
qualified tables, and observes that a foreign database reference issues no index query.

Dedicated worktrees can override the nested scenarios with `SSPA_ISSUE46_SCENARIO`,
`SSPA_DEACTIVATION_SCENARIO`, `SSPA_REINSTALL_SCENARIO`, `SSPA_READONLY_SCENARIO` and
`SSPA_REVIEW_SCENARIO`; prepare those retained native sites against the invoking checkout.
The native path/source guards and all case assertions still apply. The lifecycle legacy
fixture reads the current collection's JSON and replays the archived production template
with its original missing-generation state; case 107 separately checks compatible migration.

## The environment

`setup-site.sh` builds, at `http://tests.super-speedy-performance-analysis.localhost:8081`:

- the plugin, **symlinked** to this repository, so edits are live with nothing to sync
- WooCommerce + its 18 sample products, 30 generated posts, a News category, 3 orders
- **HPOS enabled** - live runs HPOS and the plugin queries `wp_wc_orders` directly in raw
  SQL. With HPOS off, ownership lookups silently return nothing and the failures look like
  pricing bugs rather than a misconfigured environment
- **coming-soon mode off** - WooCommerce ships `woocommerce_coming_soon` ON, which hides the
  store from logged-out visitors. wp-admin looks fine while the crawler sees no product grid
  at all, so store assertions fail with nothing actually wrong

`wp` commands run through `cli()` in `env.sh`, which is just `wp --path=<site> --url=<site>`.

### Local SMTP mail sink

`setup-site.sh`, `run-tests.sh` and the runners under `browser/`, `compare/`, `quote-request/`,
`lead-gen/` and `cache-performance/` source `local-services.sh`, which starts
`fixtures/mail-sink.py` for the scenario and points the site's `sspa-regression-delivery.php` MU
fixture at it (option `sspa_regression_smtp_port`). The shell that starts the sink owns it and
stops it when it exits, whether it passes, fails, calls `exit` or receives SIGTERM, SIGINT or
SIGHUP. A nested run on the same scenario (`run-regressions.sh` calling `run-tests.sh`) reuses
the outer sink and leaves it running. Captured mail stays in
`.data/local-services/<scenario>/mail.jsonl`, with the sink's own log in `smtp.log`. Until
22 October 2026 nothing stopped the sink, so every run left one running indefinitely.

Backstops for an owner killed outright: the sink exits within about 2 seconds of its owner PID
disappearing, and after `SSPA_MAIL_SINK_IDLE_SECONDS` (default 14400) without a connection. Each
records the reason in `smtp.log`. Between runs the site has no SMTP listener, so mail sent while
you click around a retained site fails to deliver. Start a run, or source `env.sh` and
`local-services.sh` in a shell, to capture it.

`SSPA_SCENARIO=<scenario> bash .tests/harness/mail-sink-lifecycle.sh` is the regression for this.
It runs a passing run (case 106), a failing run, `setup-site.sh`, a SIGTERM, a SIGKILL, a nested
run and the idle timeout, and requires that none leaves a sink for that scenario. It only
inspects and stops sinks serving its own checkout's scenario.

### Browser resource boundaries

The synthetic site disables avatars and uses native emoji, so page-load assertions do not
depend on Gravatar or the WordPress emoji CDN. The normal 30-second navigation limit and
all application assertions remain in force. The AJAX browser journey records redacted
request lifecycle data in `navigation.json`; `SSPA_AJAX_ARTIFACT_DIR` selects its output
folder without changing the site target.

Case 71 loads the installed SPro fixture before its baseline preparation so its own policy
writer can disable a retained named-endpoint MU policy before the first measured request.
The original 120ms fixture and measured reduction assertions are unchanged.

### Two capabilities the old Docker image provided

Both were restored natively on 16 August 2026, so the suite covers exactly what it did under
Docker. `setup-site.sh` prints the state of each; if either is missing, the case that needs it
FAILS rather than quietly passing, because a skip that looks like a pass is how coverage rots.

- **Excimer** (case 18, the sampling profiler). **Do not enable it in shared PHP just to pass this test.**

  The crash evidence below belongs to the original macOS/Homebrew PHP 8.5 environment. It is
  not evidence that Excimer fails on every Linux or WSL runtime. Verify each proposed combination
  in a separate PHP-FPM process, first with bounded repeated sampling and then with case 18 through
  the actual HTTP-serving SAPI. Keep the process logs and check for worker crashes. A successful
  CLI extension check alone is not sufficient. Do not restart shared services for this check.

  `pecl install excimer` builds and works, and case 18 passes with it - but it SEGFAULTS
  php-fpm under load. Measured 16 August 2026 across three identical full-suite runs:

  | Excimer | Xdebug | New SIGSEGVs in php-fpm |
  |---|---|---:|
  | 1.2.6 (pecl) | on | 8 |
  | none | on | **0** |
  | 1.2.6 (pecl) | off | 12 |
  | none | on | **0** |
  | 1.2.7 (upstream source) | on | 14 |

  Two things that were checked and ruled out. It is **not an Xdebug conflict** - the crash
  count is driven entirely by the Excimer column. And it is **not a stale extension**:
  `pecl install excimer` gives 1.2.6, the last release ever published to PECL (upstream has
  left PECL - see includes/class-sspa-tools.php), so the obvious theory was that 1.2.6 simply
  predates PHP 8.5. Building 1.2.7 from current upstream - whose newest commit is "Fix build
  with PHP 8.6.0alpha3", so it certainly targets 8.5 - made it WORSE, not better.

  Not tested in that original comparison: a Linux distro build
  (`php8.5-excimer` from deb.sury.org or Remi) on x86_64. Homebrew does not package Excimer,
  so macOS cannot install what a client server actually runs. The
  crashes are not confined to the suite: they kill php-fpm workers, so every parallel-dev
  site returns 502s while it is loaded. The collateral is easy to misread as plugin bugs
  (case 19 failed with `gen=0ms` on a step that is fine, and case 42 tripped a p95 timing
  ceiling).

  **PHP 8.5 on Linux with a distro build: works.** Measured 14 September 2026 on WSL2 Ubuntu
  24.04 with deb.sury.org's `php8.5-excimer` 1.2.6 loaded into the shared PHP 8.5.10 php-fpm
  that serves every parallel-dev site. Case 18 passed all 13 assertions through the real
  HTTP-serving SAPI (462 samples against 564ms generation, phases and attribution intact),
  and a bounded run of 100 further requests produced no non-200 responses and zero
  segfault or "exited on signal" lines in the php-fpm and kernel journals, with the FPM
  master keeping its PID throughout. So the crash count above is a property of the
  Homebrew/PECL build, not of Excimer on PHP 8.5. Install it with
  `sudo apt install php8.5-excimer`; the package enables itself and apt restarts php-fpm.

  Case 18 therefore FAILS on the original environment without Excimer, and that is the honest state - it is not skipped,
  because a skip that reads as a pass is how coverage rots. If you need it, the options are a
  different PHP build or a pinned-version environment, decided deliberately.

  If you do try again: it must be loaded in **php-fpm**, not just the CLI - the profiler
  samples the web request, and `wp eval` proving `extension_loaded('excimer')` says nothing
  about the SAPI serving the profiled page. Check with a script fetched over HTTP, and watch
  `/opt/homebrew/var/log/php-fpm.log` for SIGSEGV.

- **A persistent object cache** (case 10, and the extra cache modes case 09 measures):

  ```bash
  pecl install redis && brew services restart php && brew services start redis
  ```

  `setup-site.sh` then installs the `redis-cache` plugin and runs `wp redis enable` to place
  the `object-cache.php` drop-in. parallel-dev already gives every site its own
  `WP_REDIS_DATABASE` and `WP_CACHE_KEY_SALT`, so two sites cannot share a keyspace. Our
  `db.php` shim and the Redis drop-in coexist - both appear in `wp redis status`.

### Harness gotchas (learned the hard way)

- **Nested site creation:** `env.sh` exports the newly resolved shim target before calling
  a creation script; otherwise an inherited executable shim can install into the parent
  suite's database while leaving the new site uninstalled.
- **Drop-in fixture symlinks:** after archiving or removing a test drop-in, clear PHP's
  cached realpath before installing a replacement. Without that invalidation a cached
  symlink can direct a write into the vendor source. Case 88 retains its real vendor file;
  case-entry preparation must leave those bytes unchanged.

- **opcache revalidation**: php-fpm revalidates changed PHP files at most every 2s
  (`opcache.revalidate_freq`). Tests that install a `wp-content/db.php` fixture or regenerate the
  traffic observer from CLI sleep 3s before sending HTTP requests. CLI invalidation
  does not invalidate FPM's cache. Case 73 waits after each generated-observer change
  and verifies the GET window has a real request before comparing methods.
  When relocating a retained site to another checkout, refresh its dedicated FPM
  process so cached resolved plugin paths cannot produce stale asset URLs.
- **Sample data can vanish** (observed Jul 2026: 0 products, 0 orders in a long-lived
  env). The symptom is a 5-case failure cluster: sector "general" instead of e-commerce,
  "product page profiled" fails, deep deltas tiny (~25ms - the bad plugin's queries are
  cheap on an empty postmeta), no save-product write profile. `run-tests.sh` pre-flight
  checks the product count and re-runs `setup-site.sh` automatically.
- **`wp eval-file` runs in function scope**, so a top-level `$fails = 0` in a case file is a
  LOCAL variable. A helper that does `global $fails` then increments a DIFFERENT variable and
  the summary line reads 0 no matter what happened. Use `$GLOBALS['...']` on both sides.

## Cases

- `69-response-cachebuster.php` - real REST pagination reflects PA cache busters without
  making identical output unstable; meaningful body and pagination changes still differ.
- `68-profile-target.php` - saved-page navigation keeps ordinary GET URLs and editor links,
  rejects action/POST/profiling targets and unsafe URL schemes, and checks real authenticated
  HTTP responses for administrators, subscribers, invalid nonces and missing profiles. The History browser journey
  checks the matching measured-page link and target context above profile action buttons.

- `01-health.php` - tables, secret, helper-file install (mu-loader + db.php shim),
  placeholder replacement. Self-heals a leftover fake db.php from a crashed 06.
- `60-crash-recovery.php` - kills a separate controller process after a real run inherits a simulated
  pre-0.43.4 foreign database hold, ages the killed worker's durable queue, and proves the janitor
  fails the run, restores the exact legacy drop-in, releases its state, preserves the plugin list
  and permits a new run.
- `61-taxonomy-placeholder-catalogue.php` - registers real custom post types whose archive
  links contain taxonomy placeholders, proves the largest non-empty term resolves the URL,
  an empty taxonomy skips the page, and the resolved archive is profiled successfully.
- `62-history-setup-series.php` - runs adjacent measurements before and after changing only
  an active plugin version, then proves the public History chart document separates the two
  measured setup periods and retains their request points and medians.
- `63-history-quick-baseline.php` - measures a full baseline and a version-changed quick scan,
  proving that the chart retains the exact offered baseline despite different page coverage
    and marks pages outside the quick scan as missing instead of improvements.
- `64-history-exact-pairs.php` - verifies exact selected run pairs, unchanged setups, incompatible
  selections, retained profile identities and missing samples without invented zero values.
- `65-history-saved-view.php` - opens exact retained reports, checks profile links and permissions,
  and rejects missing IDs without starting another analysis.
- `66-history-request-diagnostics.php` - captures real PHP warnings per request, preserves other
  error handlers, reports unavailable legacy coverage and excludes diagnostic text from exports.
- `67-history-headline-coverage.php` - uses labelled retained scalar fixtures to prevent unmatched
  or failed pages from creating headline improvements, while retaining valid zero measurements.
- `02-token.php` - HMAC token mint/verify: path binding, tamper, expiry, flags.
- `03-fingerprint.php` - SQL normaliser: literals stripped, IN-lists collapsed, design
  smells (ORDER BY rand() etc) preserved, no PII survives.
- `04-component-map.php` - stack frame -> plugin/theme/mu/core attribution, including the
  degraded (callable-name reflection) path.
- `05-run-e2e.php` - full baseline run: catalogue (>= 10 pages incl. Woo + wp-admin),
  loopbacks with admin auth cookies, captures ingested, medians + component stats stored,
  row counts present (shim active), captures table drained, `active_plugins` untouched,
  no db.php hold left behind.
- `06-qm-coexist.php` - a foreign/QM db.php is detected, never clobbered, runs degrade
  gracefully; obsolete swap requests and helper removal preserve it byte-for-byte.
- `07-analysis.php` - findings engine against a planted bad plugin (slow query, big
  result set, N+1, dupes), score dented, sector inferred.
- `09-deep-e2e.php` - the deep sweep end to end: the bad plugin measured by virtual
  exclusion in every cache mode (disabled/prime/warm with Redis present), impacts
  attributed and linked, live plugin set untouched, isolation options cleaned up.
  (`08-isolation-planner.php` was the retired adaptive planner/bisection unit test -
  the 0.8.0 exhaustive sweep supersedes it.)
- `10-cache-mail-write.php` - cache-impact run, mail construction profiling, write
  profiles against temporary objects.
- `12-agents.php` - Abilities API + WP-CLI surfaces and the report schema.
- `19-checkout-flow.php` - one complete purchase through
  `SSPA_Run_Controller::start(['type' => 'checkout'])`: every step profiled with the right
  method, the cart and checkout pages rendering a real cart, no active order/stock/session
  residue, a fully refunded order retained in WooCommerce Trash, fulfilment identifiers kept
  for the local overlay, the payment
  boundary marked and the waterfall split at it, the pre-flight
  inventory naming a planted integration, a planted blocking HTTP call caught and
  attributed, mail really delivered in deliver mode, the Excimer roll-up, and both named
  failure paths. Plus the payment-mode safety assertions: a flow token with no `pm` flag,
  a junk one, or `pm=s` with no gateway adapter must all take the no-payment path.
  Two fixture plugins are planted by the case and retained afterward; the next PHP case resets their activation at entry.
  The whole purchase then runs again against the CLASSIC shortcode checkout: the store is
  pointed at throwaway `[woocommerce_cart]`/`[woocommerce_checkout]` pages and put back
  afterwards, so the block pages are never edited. Its session cookie is renamed through
  WooCommerce's public filter, proving cache/session plugins do not cause a false `no_session`.
  Two assertions pin the nonce binding
  that path depends on - the place-order nonce must differ from both an unbound one and one
  minted as the current admin, while the `update-order-review` nonce must not.
- `20-community-outbox.php` - privacy gate, immutable gzip outbox artefacts, retry scheduling,
  pause/resume queue controls, and the cross-language HMAC fixtures: the reservation signature
  against the receiver's golden Go vector, plus the completion and status canonical strings
  against the shapes the Go tests declare (those two have no golden signature on the Go side).
- `21-community-evidence.php` - payload generation for every supported run type, including
  page profiles, checkout flows, spot checks, Plugin Impact Analysis and Excimer data.
- `22-community-backfill.php` - bounded historical backfill, consent metadata and exact
  per-outbox preview data.
- `23-community-run-integration.php` - the join between the two: REAL runs of every type
  driven through `SSPA_Run_Controller::start()` with sharing ON, asserting each one
  automatically queues exactly one payload carrying the evidence that analysis is for.
  Cases 20-22 build payloads from their own inserted run rows, and cases 05/09/10/17/19 drive
  real runs with sharing off, so until this case existed nothing exercised an analysis a site
  actually performs turning into a queued payload. It also covers the per-run consent scope:
  an opted-out run queues nothing, `share_run()` on that same run queues it as `manual`
  without enabling the site-wide setting, and only that manual row is then due for delivery
  while the automatically queued ones stay put. Takes several minutes - it runs a baseline, a
  deep sweep and a full checkout purchase.
- `28-profile-panel.php` - the one profile panel: every section it must carry, both attribution
  modes in the markup, a URL and a profile id rendering byte-identical HTML, a pruned profile
  degrading rather than fatalling, its versioned private diagnostic JSON export carrying the
  raw capture without the compressed database blob, catalogue-matched ad-hoc results merging into the Pages tab
  while one-off URLs stay out and the site score stays on baseline/spot. Then plugin impact
  scoped to one page: a sweep pointed at a URL no analysis ever profiled, the measurement count
  it queues matching the estimate the panel promised (1 plugin = 2, 2 plugins = 3), and
  `cache_modes => false` keeping phase 2 out of the cache modes.
- `29-isolation-writes.php` - a measurement must never change which plugins the site runs.
  Two fixtures: a dependency, and a dependant that calls `deactivate_plugins()` on itself when
  the dependency is missing. Sweeping the dependency must leave BOTH active. Without the
  mu-loader's `pre_update_option_active_plugins` guard this case fails with the plugin list
  shrunk by two, which is what happened to a real site's Rank Math install on 10th August 2026.
  Its dependant assembles the dependency path at run time ON PURPOSE, so the code scanner
  cannot see it and grouping (case 30) does not cover the pair - otherwise the dependant would
  never be orphaned and the case would pass while testing nothing. Two assertions pin that:
  grouping must not cover the pair, and the dependant must actually have tried to deactivate.
- `30-dependency-groups.php` - the complement: a Rank Math Pro shaped pair, dependency named as
  a literal with `activate_plugin()`/`deactivate_plugins()` in the main file. Asserts the scan
  reads the edge and its direction, the sweep excludes both in one cell, the dependant is never
  orphaned (0 times, against 4 with grouping stubbed out), the verdict records `group_members`,
  and the Plugins tab names the group. Also that grouping is not a way round the fragile list:
  a fixture using the `wordfence` slug (on the bundled security list) makes both itself and the
  plugin it depends on ineligible.

- `31-reaction-guards.php` - the reaction guards, each proven able to fail before being proven
  to hold: a scalability-pro-shaped fixture whose deactivation hook drops a real index on
  `wp_options`, plus an INLINE index drop and a runtime-assembled dependency the scanner cannot
  see. Runs both destructive paths with the guards disarmed first (the hook really drops the
  index; the same ALTER really executes through the shim with the guard off), then the e2e
  sweep: routine never runs (hook silencing), index survives (statement refusal), both plugins
  stay active, the reaction becomes a finding + run notes + a learned group, and a second sweep
  groups the pair, provokes nothing, and finally yields a verdict with `group_members`.
  Gotcha the case documents: `wp eval-file` textually replaces `__FILE__` before eval'ing, so a
  fixture written via heredoc must name its plugin basename explicitly - `__FILE__` inside the
  heredoc silently becomes the CASE file's path and hooks register under a name that never
  fires, making guard assertions vacuous.
- `58-managed-host-guards.php` - managed-host refusal paths: a request already carrying a
  platform-owned cache implementation cannot be recorded as cache disabled, a home-page
  loopback passing does not hide a product-path 403, Plugin Impact Analysis refuses the
  blocked target before queueing work, and checkout refuses the exact blocked entry point
  without creating an order.
- `125-cache-delivery-validator.php` - the cache check resolves only home, shop, one populated
  product category and one ordinary product; real server legs; the browser-result VALIDATOR with
  a labelled hand-written browser payload (the real browser leg is cases 126 and 127); version 3
  storage, export and Markdown; Code Snippets use/counts without names or source.
- `126-cache-audiences-real-cache.php` - `.tests/cache-performance/run.sh enabler`: the real
  Cache Enabler from wordpress.org on the retained `tests-cache-enabler` site; the browser journey
  presses "Check cache performance"; visitors and visitors with a basket are served from the
  cache (Cache Enabler's default cookie rules do not exclude WooCommerce's basket cookies),
  logged-in customers are not; leak check, cURL TTFB, stored-privacy and C3 assertions; the basket
  warning (no cart fragments, Mini-Cart block or Ajax Prices on the page), then a second pass with
  WooCommerce's cart-fragments script enqueued (`.tests/fixtures/cart-fragments.php`) and no warning.
- `127-cache-no-page-cache.php` - `.tests/cache-performance/run.sh nocache`: no page cache and a
  shop page that really waits 600 ms (`.tests/fixtures/slow-shop.php`) on the retained
  `tests-cache-nocache` site; a real spot analysis, the browser journey, all three audiences Not
  cached, C3 from generation time, then Ajax Prices detection from real WooCommerce geolocation
  and the real Advanced Dynamic Pricing plugin, suppressed once Super Speedy Ajax Prices is active.
- `130-cache-no-woocommerce.php` - `.tests/cache-performance/run.sh nowoo`: a plain WordPress site
  (`tests-cache-nowoo`, parallel-dev's own, no WooCommerce); the browser journey checks the home
  page with no basket requests; the tab and the caching-service pitch render, without evidence
  sentences or Ajax Prices.
- `128-cache-basket-cookies.php` - the basket requests carry exactly WooCommerce's two basket
  cookies and create no WooCommerce session row; the server enforces the request order.
- `129-cache-tab-render.php` - the tab through the real lazy loader: always-present service pitch,
  evidence sentences exactly when C1 to C4 fire, every quotation with its source link and check
  date, no price or slot count, the Overview summary, and the export pitch only with evidence.
- `.tests/browser/run-regressions.sh cache-performance` - the same browser journey on the suite
  site: same-origin requests only, the declared request count, wp.i18n progress text, 390px fit.

### Lead-generation offers (phase 3)

Each kind owns a retained site, clears its own state on the way in and leaves everything behind,
with evidence under `.data/lead-gen/<kind>/`.

- `131-lead-gen-slow-store.php` - `.tests/lead-gen/run.sh slow` on `tests-lead-gen`:
  `seed-volume.php` tops the store up to 120,000 published products and 250,000 HPOS orders in the
  ordinary WordPress and WooCommerce tables; the persistent object cache is switched off (with it,
  WordPress answers the catalogue queries from memory); a real spot analysis of eleven pages. Then
  the rendered Overview (two offers, primary Search for the 700+ ms search query, secondary
  Scalability Pro, each after its finding's free fix), page summaries (S1, S5, Q1/Q2, Q3), exports,
  dismissal, suppression with the retained Scalability Pro active, no offer on other admin screens;
  then HUSKY from wordpress.org and a fresh analysis for F1/F3.
- `132-lead-gen-wpai.php` - `.tests/lead-gen/run.sh wpai` on `tests-lead-gen-wpai`: WP All Import
  from wordpress.org and a real 300-row import through its own wizard (`wpai-import.cjs`); a fast
  store, so no product offer fires, and no fix quote on the fast wp-admin screens even where 35% or
  more of their generation is unattributed (R2 needs >= 1,000 ms); I1 on the Overview; the Imports
  tab's run history checked against WP All Import's own History screen, the published figures and
  the Lite button. Then R2's positive case: an ad hoc analysis of a page whose template does about
  1,200 ms of real PHP work outside any callback (`.tests/fixtures/lead-gen-slow-render.php`) offers
  the fix quote in its summary and export.
- `133-lead-gen-imports.php` - `.tests/lead-gen/run.sh imports` on `tests-lead-gen-imports`: the
  Imports tab with no importer; a Traffic collection over a real WooCommerce product CSV import
  (`woocommerce-import.cjs`) and 60 requests to a 350 ms admin-ajax endpoint
  (`.tests/fixtures/lead-gen-slow-endpoint.php`) for I3 and S8; Super Speedy Imports Lite from
  wordpress.org (Pro link only); Super Speedy Imports Pro from its repository (no offer).
- `134-checkout-offers.php` - S6 on the checkout panel and export from a real checkout analysis the
  case runs itself (fixture `sspa-case134-purge` fetches the order permalink in payment_complete), so
  it does not depend on case 19, which runs after it; it starts from the block cart and checkout
  pages, because case 80 and the checkout browser journey leave other checkout pages behind; suppressed with Scalability Pro active; no
  offer outside PA screens.
- `.tests/lead-gen/journey.e2e.cjs full` - the administrator's browser journey on `tests-lead-gen`
  from the admin menu: Run Analysis, finding then free fix then offer, dismiss, reload, copied
  export, Imports tab. Screenshots in `.data/lead-gen/journey/`.
- `python3 -I .tests/lead-gen/verify-published-quotes.py` - fetches every `source_url` in the rules
  snapshot and checks each offer quotation verbatim on the live page. Needs network access, so it
  is not part of the suite.

- `32-loopback-timeout.php` - measurement requests impose no response timeout, proven with a
  real page that sleeps 12s while the obsolete stored timeout is 10s. It also forces a real
  WordPress transport failure through the crawler, profile store and result panel, asserting
  that the exact transport explanation is retained and displayed. Gotcha: the slow-page probe
  query arg must NOT be `sspa_`-prefixed - the catalogue matcher strips `sspa_*` keys and the
  run would file under `home` and measure the catalogue URL without the sleep.

- `33-order-management.php` - the order-management lifecycle the checkout flow appends: view
  the order in wp-admin, mark processing -> completed, refund it in full without contacting the
  gateway, then move it to Trash. Drives the real `start(['type' => 'checkout'])` path; asserts
  all four actions are measured, their WooCommerce hooks fire for the same order, and the
  waterfall's `management` bucket holds all four with real time
  and expandable per-step component diagnostics, management findings never use customer-checkout wording,
  while the customer `total_ms` excludes them. It also proves the refund stays attached to the
  recoverable trashed order. Four real runs cover every combination of the integration and
  webhook controls. They prove enabled integration callbacks and native
  WooCommerce webhooks still execute, while explicit opt-outs suppress callbacks and webhook
  transport through purchase, completion, refund and Trash. The webhook HTTP boundary is
  intercepted locally, and its fixture and observations remain for inspection. Gotcha:
  the transition runs in the loopback, so the flow must bust its order cache (`wp_cache_delete($id,
  'orders')` + `clean_post_cache`) before reading the resulting status or it reads back stale.

- `38-turnstile-checkout.php` - plants Cloudflare Turnstile's documented bypass contract
  and both WooCommerce checkout validation surfaces, then proves the real synthetic checkout
  completes, records the scoped bypass and moves its refunded temporary order to Trash.
- `39-cache-safety.php` - the shared-cache safety scan keeps cookie/nonce names but
  never their values, recognises existing Type A/Type B coverage and private surfaces, ignores
  source-code comments, separates edge cookies, records nonce containers, scores repeated
  site-wide signals once, filters admin-oriented source evidence, ranks corroborated
  visitor-state/output evidence, produces stable cache-safety status and review difficulty,
  and excludes checkout, wp-admin and builder template pseudo-pages from shared-cache candidates.
- `40-traffic-contracts.php` - freezes the cache optimisation, provisional/final traffic and
  optional Cloudflare JSON contracts; every fixture passes the shared forbidden-property
  validator, while planted customer identity fields fail closed. Also pins the clearer immediate
  analysis GUI name, keeps `sspa/shared-cache-safety-report@2` frozen for compatibility and
  records the additive `sspa/cache-optimisation-analysis@3` contract.
- `41-traffic-schema-lifecycle.php` - all five additive traffic tables, database insert
  pre-flight that permits deliberately delayed successful inserts, collection HMAC key
  separation, generated active-only MU observer, one/two/four-hour
  duration scheduling and idempotency/conflict, normal outcome stop, emergency stop,
  plugin-update mismatch and proof that an inactive collector performs no event write.
- `42-traffic-hot-path.php` - a normal visitor request remains successful and appends one bounded
  request event, keyed paths are fixed-width, the hard event-id ceiling retires the MU observer,
  and the embedded timestamp prevents writes without WP-Cron.
- `43-traffic-woocommerce.php` - real WooCommerce guest basket/cart and logged-in requests plus
  classic order creation, delayed payment and an excluded admin-created order. It proves the
  actor and commerce joins, minor-unit/currency fields, privacy-safe observations and prohibited
  customer-data columns.
- `45-http-api-contract.php` - the stable outbound WordPress HTTP API inventory consumed by
  Scalability Pro: complete below-threshold coverage, endpoint/method/component aggregation,
  variable path and query-value privacy, licence/update/telemetry/payment classification,
  fail-safe block verdicts, identical PHP/Abilities/WP-CLI surfaces, old-capture completeness
  reporting, and opt-in privacy-safe community evidence for superspeedy.org.
- `47-markdown-export.php` - page, complete-site and checkout/order results produce the same
  privacy-safe Markdown used by Download Markdown and Copy Markdown. Raw SQL literals, HTTP
  query values, variable endpoint IDs and checkout fulfilment identifiers cannot leave in the
  document; all three result surfaces expose both actions, and cache advice carries the optional
  implementation-service link.
- `48-excimer-prompts.php` - an Excimer phase remains expandable when wrapper-based phase
  attribution is empty, and measurements captured without Excimer link each missing
  function-level view to the Tools tab's server-specific installation instructions.
- `50-admin-save.php` - profiles WordPress's real authenticated classic `post.php` update and
  block-editor REST update, not the editor reload: a planted slow `save_post` callback must
  appear in each `admin_save` write profile with full diagnostics, mail still runs normally,
  and the classic transport token is removed before ordinary save callbacks see the submitted
  fields. It also fetches real post, product and HPOS order editors and pins their control and
  object-specific configuration.
- `51-workflow-analysis.php` - the settings-page Workflows tab: automatic public-CPT
  discovery, exclusion of existing targets, both WooCommerce order transports, validated
  controlled-editor launches, and a real REST save of an owned temporary CPT with SMTP delivery,
  measured send time, cleanup and untouched existing content.
- `52-release-hardening.php` - dedicated hidden checkout product, verified/bounded profiling
  HTTP arguments, atomic owner leases, lazy anonymous bootstrap and the History uninstall
  control. The build smoke separately proves opt-in uninstall drops every table and removes
  owned options, the checkout product and test customer.
- `53-review-followup.php` - all eight admin tab containers remain while hidden tab data is
  deferred, permanent-failure grace retains a retry deadline, in-flight submissions reject
  conflicting admin changes, and cancellation consumes the run's digest snapshot.
- `54-run-job-table.php` - immutable ordered job rows, small mutable cursor/state storage,
  append-only phase extension, no queue option writes and terminal row cleanup.
- `57-admin-bar-parent.php` - the top-level Performance Analysis admin-bar link performs the
  first submenu action, Analyse this page, rather than navigating to the full report.
- `59-history-comparisons.php` - default-enabled plugin-change detection, single and bulk
  updater hooks, durable coalesced change sets, exact before/after binding, setup-version
  and publisher-declared configuration changes, stable-output comparison and declared
  expectations, hostile privacy export fixtures,
  History rendering, and matching WP-CLI plus readonly Abilities/MCP adapters, plus two real
  retained-site spot runs whose second response is deliberately slower and meaningfully
  different. Its bounded fault-injection switch is cleared at suite
  startup and disarmed on exit so a killed case cannot poison later measurements.

**Cross-process option reads.** A fixture that records into an option from inside the loopback
requests, read back by the controller process, is only reliable if the controller busts BOTH the
value cache (`wp_cache_delete('key', 'options')`) AND the `notoptions` array
(`wp_cache_delete('notoptions', 'options')`) before reading - especially when the controller
`delete_option`'d the key earlier, which caches it as known-missing. Case 19's mail-observer read
hit this in 0.17.0: the DB row was correct, the controller's `get_option` returned the stale empty
default. Symptom: a mail/observer assertion reads 0 while a raw `SELECT option_value` shows the
rows. Verify with a raw `$wpdb->get_var` before concluding it is a product bug - here it was not.

**Bounded fixtures.** Any test fixture doing deliberately expensive work must bound it against
the table it reads. `run-tests.sh` reseeds the WooCommerce sample data whenever products drop
below five, so `wp_posts` grows across runs, and an O(posts^3) join that cost ~800ms when it was
written reached 99.9 million row combinations and an 82-second home page at 464 posts. Case 09
uses `(SELECT ID FROM posts LIMIT 120)` aliases for this reason. Case 07 uses one synthetic
row with `SLEEP(0.35)` and `ORDER BY rand()` to test critical severity independently of CPU
speed, with no table scan. The symptom of an unbounded fixture is never
a slow-query failure: it is the crawler timing out, so the case fails with "deep run done:
crawling" or "the analysis engine found nothing".

Case 23 parks any pre-existing pending/retry outbox rows for its duration, because `due()`
returns the oldest eligible row and a leftover would otherwise answer its assertions.

## Live receiver and R2 compatibility

The normal suite does not require Cloudflare credentials. The manual live check exercises the
real Go receiver, Postgres and R2 using the same background worker that production uses.

Start the receiver integration stack from `/opt/homebrew/var/www/superspeedy.org`:

```bash
make integration-r2
```

Then, from this plugin repository in bash:

```bash
source .tests/env.sh
sync_plugin
cli eval-file "$CONTAINER_PLUGIN_DIR/.tests/manual/live-r2.php" http://localhost:8788
```

This is deliberately manual because it writes a real object to R2 and requires the receiver's
ignored `.env.r2` credentials file.

Port 8788 is the R2-backed stack (`make integration-r2`, `compose.r2-test.yaml`). Do not point
`live-r2.php` at port 8787 - that is the ordinary `make up` stack, which is MinIO-backed and
hands back an `http://minio:9000/...` upload URL. The client rejects that with
`sspa_invalid_upload_authorisation` by design: `wp_http_validate_url()` refuses a non-standard
port, so the MinIO stack cannot exercise the direct-upload phase at all.

## Production collector smoke test

`.tests/manual/live-production.php` is the release check against the live collector. It refuses
every host except `collector.superspeedy.org`, needs an explicit opt-in token, and writes one
real permanent synthetic object to the production archive:

```bash
source .tests/env.sh
sync_plugin
cli eval-file "$CONTAINER_PLUGIN_DIR/.tests/manual/live-production.php" \
    https://collector.superspeedy.org production
```

The opt-in token is the bare word `production`, not `--production`: wp-cli parses any
leading-dash argument as a flag belonging to `eval-file` itself and errors with
`unknown --production parameter` rather than passing it through.

It runs in two phases in one invocation. First it queues the payload with the collector
deliberately unreachable (same host, discard port 9 - a genuine connection failure, no mocking)
and asserts the item is left retryable with its exact bytes and hash intact. Then it restores
the production URL and asserts the same submission UUID and hash receive a verified receipt.
Expect the first phase to sit for up to 30 seconds waiting on the real connection timeout.

Each production script registers a fresh one-use test installation and restores the site's
ordinary installation UUID and collector credentials afterwards. Collector registrations are
permanent, so this isolation makes repeated checks independent of historical production state.

Any unrelated queued outbox items are paused for the duration and resumed afterwards, so a
local queue can never be flushed to the production archive as a side effect. The script prints
the submission UUID, receipt UUID and SHA-256, and never prints the installation secret or a
presigned upload URL.

`.tests/manual/live-production-runs.php` takes the same two arguments and the same host guard,
but delivers one REAL analysis of every run type, including admin update/save - the most recent
completed unshared run of each - instead of a synthetic fixture. It uses the per-run consent
path, so the site-wide sharing setting stays off throughout, and it asserts that afterwards.
Use it when the transport or the exporters change: the synthetic fixture is small and will not
notice a payload-size or evidence-volume problem.

After diagnosing a failed processor outcome, a comma-separated third argument can repeat only
the affected types without creating unnecessary permanent objects, for example
`production spot,baseline`. Omitting it checks all seven types.

Both scripts write real permanent objects to the production archive. Run them only from the
disposable test site. A payload containing only supported evidence must finish with
`processing_status=complete`; `partial` means the manifest retained an explicitly unsupported
record and needs inspection.

## Coverage added since the original test plan

- Case 88 checks genuine Query Monitor query counts and timing on the same requests,
  preserving its drop-in even for obsolete swap parameters.
- Case 87 checks the restricted synthetic-customer variant.
- Cases 60 and 102 cover killed-worker recovery and legacy upgrade/uninstall holds;
  production code no longer creates new foreign holds.

## AJAX profiling integration

`SSPA_SCENARIO=<dedicated-scenario> .tests/run-tests.sh fast-ajax` runs cases 59, 71–73 and the
registered chart browser test. Case 72 requires the SPro endpoint feature installed on the same
isolated site; case 71 prepares retained owner/delay fixtures for it. The runner deactivates
SPro before case 19 (deliberately wasteful checkout calls) and case 37 (no settings publisher),
then activates it before case 72. Those are explicit fixture preconditions; SPro's own suite
covers its Purge Shield and its settings publishing. SPro remains active after integration.
The suite refuses a
non-isolated site and provides a real `wp` shim to child scripts. Nonzero PHP exits and zero matched
cases fail the runner.

Use the installed Node 20+ runtime and `SSPA_PLAYWRIGHT_MODULE` for an existing Playwright package.
The AJAX browser wrapper records two real History spot checks so it can verify both tabs
share one lazily loaded chart engine, including on a fresh site.
Browser evidence is saved in `.data/ajax-profile-browser/`. The separate
`.tests/manual/ajax-overhead.php` measures identical local fixture requests with observer off,
identity-only and explicit detail capture; it does not approve a production overhead budget.

Case 71 was observed failing when execution evidence was deliberately removed, then passing with
it restored. Case 72 exercises SPro's actual generated MU policy with unchanged installed versions.
Case 73 checks real failed requests, method/mode compatibility, detail caps and collection expiry.
Database append preflight rejects actual insert failures; successful insert latency is informational
following core adb471e. A refused start is never a skipped/passed fixture or automatically retried.

### Endpoint start-up and endpoint profiles (cases 140, 141)

`.tests/run-tests.sh 140-` and `.tests/run-tests.sh 141-` cover additions B, C, E and F of
`.docs/2026-10-22-endpoint-startup-profiling-plan.md`. The fixtures in
`.tests/fixtures/endpoint-startup/` give a GET REST route and a GET admin-ajax action with known
costs (30 ms slept before the MU observer, 40 ms on `plugins_loaded`, 30 ms in the handler), and a
real MU plugin that trims both to WooCommerce plus the fixture, as a Fast Ajax/REST MU plugin
does. Case 140 checks the window's pre-MU split, outside-handler, CPU and recorded setup, and the
AJAX tab's observed endpoints table. Case 141 checks "Profile this endpoint" (URL validation,
N samples, the trim honoured, the endpoint key on the Pages and AJAX tabs) and the capture's
environment evidence. php-fpm's opcache is shared by every local site and is whatever state it is
in, so case 141 checks the probe against an independent `opcache_get_status()` from the same
php-fpm (fixture `opcache-status.php`, copied to the docroot) and each warning against the
figures. Opcache off and a deliberately tiny opcache are served for real by a private
`php -S` the case starts with its own `-d` flags on the site's docroot and stops afterwards; the
shared php-fpm configuration is never changed. Server logs stay in `.data/case-141/`.

`bash .tests/browser/run-endpoint-profile.sh` (registered as `endpoint-profile-browser`) is the
same journey in a browser: start a window, a logged-out visitor uses the endpoint, stop, read the
row, refuse an unfilled `{placeholder}`, profile, read the environment block, then find the
result on the Pages tab. Screenshots and `summary.json` go to `.data/endpoint-profile-browser/`.
Point it at another real GET endpoint with `SSPA_ENDPOINT_ROUTE`, `SSPA_ENDPOINT_MATCH`,
`SSPA_ENDPOINT_PLACEHOLDER` and `SSPA_ENDPOINT_TERM`; the script header shows Super Speedy
Search's Fast REST suggestions route as the example.

### Traffic observer with the plugin folder missing (case 142)

`.tests/run-tests.sh 142-` delegates to `.tests/observer-guard/run.sh`, which keeps its own site,
`tests-observer-copy`, with Performance Analysis installed as a real folder copied (rsync) from the
invoking checkout on every run. On the symlinked suite sites WP-CLI resolves the plugin to the
checkout, so the generated traffic observer loads from there and renaming the site's plugins entry
cannot show the fault. The case starts a real AJAX window, renames the plugin folder as a customer does
over FTP, and requires `/`, `/wp-login.php`, `/wp-json/` and a query URL to answer and send
`X-SSPA-Traffic-Observer: inactive; plugin files missing`; then it renames the folder back and stops
the window. A run interrupted mid-rename is repaired at the next run's entry.

### Feature regression journeys

The default scenario is `tests-feature-regressions`; any `SSPA_SCENARIO` site works, as the
journeys take the site `env.sh` resolved. The plugin symlink must resolve to the
invoking checkout, and the guard's error names the site it found and the checkout it loads. Detached worktrees use `SUPERSPEEDY_WORKSPACE` for the shared native tools;
all subprocesses receive the guarded WordPress path through the exported executable shim.
Direct browser scripts also reject a missing or mismatched native target.

`bash .tests/browser/run-regressions.sh` runs the existing History chart journey and all new
browser journeys. An optional filename substring selects one journey. The normal PHP
runner registers this aggregate as `feature-browser`. Cases74–77 cover actual authenticated
Abilities HTTP, all registered CLI commands, and real linked-plugin attribution. Failing
product expectations remain failing; the harness does not turn known defects into passes.

Browser preparation measures two actual Home captures and retains them; no successful report
or waterfall is fabricated. Each journey resets its own prior test state at entry. Evidence
is written under `.data/feature-browser/`. `SSPA_NEGATIVE=panel-hidden` deliberately hides the
actual report panel for the report-panels visibility negative control. Remove that variable
and rerun the focused journey for restored evidence.

Setup uses local SMTP capture and a WordPress HTTP boundary that rejects external requests.
The sharing journey permits only its local registration/reservation fixture; remote receipt
and delivery remain unverified. Mail construction and actual SMTP hooks still run. Local
servers, captures, orders in Trash, fixtures and database records remain available afterward.
Redis uses the site's key prefix with selective flushing enabled; do not globally flush Redis.
Scalability Pro is copied from its recorded immutable revision rather than linked to a mutable
checkout. Excimer and other unavailable runtime branches are reported explicitly; seeing a
capability message does not prove that runtime works.

The runner prints each suite-owned plugin deactivated at a new PHP case's entry. Each case
reactivates the fixtures it needs and retains their files, final observations and results.
Recorded post/run identifiers are reset only when that same case next begins; actual
product cleanup/deletion assertions remain intact. Retained synthetic plugin headers carry
explicit versions so later History comparisons can evaluate their real setup inventory.

### Direct entry and narrow History labels

The feature PHP helpers, retained-fixture resets and browser preparation require WP-CLI,
the declared dedicated native site, its configured native root and the invoking checkout's
plugin symlink. Direct `wp eval-file` callers must first source `.tests/env.sh` and call
`sspa_require_site`. Missing targets and mismatched sources fail before fixture mutations.
Retention messages use `RETAINED`, not successful assertion markers.

`bash .tests/browser/run-regressions.sh history-mobile` checks actual rendered History
axis and legend text rectangles at 1280px and 320px, retaining both screenshots. It is
included in the default feature-browser run. An unmatched browser filter exits nonzero.
The current narrow-label overlap is tracked in issue38; the separate mobile report action
clipping remains tracked in issue35.

### Optional profiler environment matrix

`.tests/capability-matrix/README.md` documents the explicitly approved isolated extension and
performance_schema environments for capability-card detection and rendered guidance. This runs
separately from the native suite because it requires distinct system capabilities.

### Performance Analysis release measurements

After the full suite finishes on `tests-release-20260915`, run the Observatory's prepare and run
commands with `performance-analysis-release`. Its retained admin and public request measurements
complement the full-suite assertion log; they do not replace the suite's pass/fail results.

## Translation template

Run `.build/update-translations.sh` after changing translatable strings or the release version. It regenerates `languages/super-speedy-performance-analysis.pot` with WP-CLI, including PHP and JavaScript and excluding bundled/shared libraries. An optional destination argument supports comparing a fresh extraction with the retained template; the generation timestamp can differ. This ships a source template, not a translated language pack.

Cache action scope regression: `bash .tests/run-tests.sh 97-cache-action` uses the dedicated persistent-cache site to check menu availability and preservation of owned and foreign entries when an obsolete selective-action URL is used.

The existing `translations` browser journey also checks translated History/AJAX chart legends,
accessible descriptions and HTML-safe tooltip strings. `report-mobile` opens the saved profile
with Enter, verifies focus enters its named dialog, and verifies Escape restores the row.
`admin-mobile` checks the real Overview score-label contrast before its 54 tab/width/direction
combinations. These checks reuse the normal browser preparation and retained native site.

Case 99 captures block, dash and hash comments plus hexadecimal, binary and exponent literals
through normal Home analysis. It also measures real database errors from unterminated SQL
strings/comments and verifies that their values are removed while the failed-query evidence
is retained. It checks the local manual preview, complete quoted SQL tokens,
object-shaped evidence and the empty JSON object receiver contract. Its legacy finding fixture
contains only a synthetic comment query and measured count/time captured using the pre-fix
archive. The current client rejects that finding, opaque old comments/literals and malformed
JSON before any HTTP request; a valid stored archive reaches an intercepted upload unchanged.
All transport calls are intercepted, and the fixture site and archive records remain for inspection.

### Issue 46 and legacy drop-in recovery

`SSPA_SCENARIO=tests-issue46 .tests/setup-site.sh` prepares the retained dedicated site.
Run `SSPA_SCENARIO=tests-issue46 .tests/run-tests.sh 101-foreign` there, or select case 101 from another suite site and its wrapper delegates to that dedicated site. Case 101 supplies the actual database connection from a stand-in foreign `db.php` while stock credentials are unusable, submits the authenticated UI AJAX start action with the old swap parameter, checks both HTTP entry points, completes the degraded run, and requires an owner-naming Cache Impact error with object-cache.php untouched. Its deliberate connection fault is disarmed after a successful run. A red run on pre-fix code leaves the fault and hold for manual recovery, with the original configuration backed up in `.data/w0/issue46-wp-config.backup`.

Case 102 plants only states that versions before 0.43.4 could create, and exercises controller cancellation, idle recovery, conflicting foreign files/dangling symlinks and actual uninstall. Test fixture preparation archives a preceding foreign drop-in explicitly on the guarded test site; production code has no displacement operation.

### W3 WordPress.org checks

`bash .tests/run-tests.sh 111-w3` checks the production History adapter, uploads locations and agent discovery.
`bash .tests/run-tests.sh 112-browser` compares the production JavaScript response extractor with PHP on a real HTTP Home response.
`bash .tests/run-tests.sh 113-loader` checks a real dangling foreign loader symlink and restores the test fixture in a finally block.
`bash .tests/browser/run-regressions.sh derived-evidence` completes a real browser-transport spot measurement and asserts that no response HTML is posted.
`bash .tests/browser/run-regressions.sh checkout-translations` loads a French catalogue into WordPress i18n, opens the actual checkout disclosure and checks translated controls, plural messages and footer without creating an order.
The translations journey keeps its original five-second canvas assertion; it uses the same entry preparation as the other browser journeys.

Case 97 verifies the removed Super Speedy caches node and action URL are absent with both cache backend modes, while the remaining conditional controls stay available. Case 111 includes a dedicated native multisite ownership regression.

Case 83 requires Twenty Twenty-Five to be installed. It explicitly selects that theme
for its real Home captures, records the entry theme and restores it in `finally`.
This establishes a stable-output prerequisite independently of the site's fleet theme:
Blocksy's random search-form IDs correctly prevent approving its ordinary Home output.
The response-code, output-signature and missing-evidence assertions are unchanged.

Case 93's dedicated non-WooCommerce site explicitly rejects server-side external HTTP
through `WP_HTTP_BLOCK_EXTERNAL`, and asserts the real `http_request_not_executed`
error. Local HTTP and anonymous Traffic recording stay real. Its browser fixture turns
off synthetic-user avatars and WordPress's optional emoji image loader, so an unrelated
Gravatar or s.w.org connection cannot hold the existing thirty-second page-load assertion.
The W5b traces retain the actual 207 ms PA response and pending external images; no
browser timeout or PA assertion was loosened.
The dedicated anonymous observation also selects and asserts the bundled Twenty Twenty-Five
theme, whose fonts are local. Hestia's real Google Fonts dependency reproduced the guest
navigation failure under a bounded 35-second network stall; the local-theme fixture passes
the same journey under that fault condition with the original thirty-second deadline.

W5c: case 79 checks the signed Clear all caches node; the registered cache-controls browser journey clicks it and verifies ordered effects and rendered outcomes, including absent/refused/throwing page caches. Case 119 runs the real SiteGround file-cache browser regression on SSPA_SG_SCENARIO (default tests-siteground-cache). Supply SSPA_SG_ZIP as an absolute path to the official sg-cachepress ZIP when that retained site does not already have the vendor installed; SSPA_SG_OUTPUT selects its evidence directory. No hosted SiteGround cache is claimed by this local file-cache test.

### Actionable page summary

`SSPA_SCENARIO=tests-actionable-summary .tests/run-tests.sh 120-actionable-summary` installs a bounded render fixture with a 120 ms shortcode, a 160 ms direct template include, a 180 ms raw HTTP stream, a 100 ms CPU loop, a 140 ms sleep and a 110 ms custom render callback. The direct part uses native `load_template()` outside any callback, and the fixture uses the normal theme for other pages. A query-scoped variant adds a 30 ms callback and a 40 ms child load to verify that neither is counted in its parent template residual. The all-hook pass explicitly disables Excimer sampling and asserts there are no PHP diagnostics. The fixture page is a classic theme template, so on a block fleet theme (no `header.php`/`footer.php`) the case switches to Twenty Twenty-One from the parallel-dev theme library for its own process and restores the entry theme at shutdown; cases 121, 122 and 124 inherit this because they require case 120.

Cases 121, 122 and 123 exercise real three-sample export reconciliation, a first-measured-sample-only delay and PHP reference callback semantics. The browser aggregate registers `actionable-summary`, which opens the normal admin-bar panel, checks 320 px containment, reviews suspect selections and completes a single-page sweep with cache modes while a real per-request hosts route resets the server's TLS handshake. Its local reset listener closes on every exit path. Prepare case 120 on that scenario before running the browser journey alone.

When the full suite runs from this worktree, point the nested lifecycle, non-WooCommerce and foreign-drop-in regressions at dedicated retained sites with the core overrides above (`SSPA_ISSUE46_SCENARIO`, `SSPA_DEACTIVATION_SCENARIO`, `SSPA_REINSTALL_SCENARIO`, `SSPA_READONLY_SCENARIO`, `SSPA_REVIEW_SCENARIO`) instead of sharing sites with another worktree. Test expectations are unchanged. Evidence for this implementation is under the ignored `.data/summary/` directory.

The manual `summary-overhead.php` drives the real crawler through none, selected, all callbacks and all callbacks with opt-in streams, alternating six requests per mode without Excimer. The standalone `summary-stream-contract.php` is a diagnostic experiment: after sourcing `env.sh`, run it with PHP to observe the expected nonzero result when a userland HTTP wrapper loses caller-scoped response headers. Ordinary measurements never wrap streams. Case 124 verifies the explicitly requested raw-stream pass, its 180 ms caller evidence, reduced residual and visible header-scope warning. The normal admin-bar browser journey invokes that warned action. Template boundaries and CPU/wall coverage remain available.

The page-impact browser journey verifies the first-screen next action at 320 px and 1280 px, the site-wide chooser's single-URL plan, all cache-mode phases and rejection of an inaccessible browser baseline. Cleanup closes its browser and reset listener even if a fixture-option command fails.

The measurement journey clears the reviewed shortlist before requesting exactly one plugin. The sharing journey holds a real worker lease only while consent controls are changing, releases it before each asserted worker call and keeps every consent/revocation assertion. `SSPA_NEGATIVE=sharing-autoworker` deliberately leaves that clock uncontrolled and waits for an allowed cron submission to demonstrate why earlier delivery cannot be attributed to a later revoked worker call; its expected result is failure with an `opted_in: true` receiver record. The synthetic receiver records time and consent state and never contacts the real service.

Case 121 also loads a SQL/HTTP projection of an actual archived pre-fix 0.43.4 request to verify read-only correction while preserving its stored blob and private JSON. Case 123 verifies static string callbacks, references, actual execution phases and an autoloaded class whose priority depends on an earlier callback.
