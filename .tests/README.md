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
.tests/setup-site.sh --reset   # destroy and rebuild it from scratch
.tests/run-tests.sh            # run all cases
.tests/run-tests.sh e2e        # run only cases whose filename contains "e2e"
```

Run these from **bash**, not zsh: `env.sh` derives the plugin directory from `BASH_SOURCE`,
which zsh does not set when the file is sourced interactively.

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

### Two capabilities the old Docker image provided

Both were restored natively on 16 August 2026, so the suite covers exactly what it did under
Docker. `setup-site.sh` prints the state of each; if either is missing, the case that needs it
FAILS rather than quietly passing, because a skip that looks like a pass is how coverage rots.

- **Excimer** (case 18, the sampling profiler). **Do not install it on this machine.**

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

  Still untested, and the one thing that could still exonerate Excimer: a Linux distro build
  (`php8.5-excimer` from deb.sury.org or Remi) on x86_64. Homebrew does not package Excimer,
  so macOS cannot install what a client server actually runs. The
  crashes are not confined to the suite: they kill php-fpm workers, so every parallel-dev
  site returns 502s while it is loaded. The collateral is easy to misread as plugin bugs
  (case 19 failed with `gen=0ms` on a step that is fine, and case 42 tripped a p95 timing
  ceiling).

  Case 18 therefore FAILS on this machine, and that is the honest state - it is not skipped,
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

- **opcache revalidation**: php-fpm revalidates changed PHP files at most every 2s
  (`opcache.revalidate_freq`). Tests that swap `wp-content/db.php` sleep 3s before
  sending profiled requests.
- **Sample data can vanish** (observed Jul 2026: 0 products, 0 orders in a long-lived
  env). The symptom is a 5-case failure cluster: sector "general" instead of e-commerce,
  "product page profiled" fails, deep deltas tiny (~25ms - the bad plugin's queries are
  cheap on an empty postmeta), no save-product write profile. `run-tests.sh` pre-flight
  checks the product count and re-runs `setup-site.sh` automatically.
- **`wp eval-file` runs in function scope**, so a top-level `$fails = 0` in a case file is a
  LOCAL variable. A helper that does `global $fails` then increments a DIFFERENT variable and
  the summary line reads 0 no matter what happened. Use `$GLOBALS['...']` on both sides.

## Cases

- `01-health.php` - tables, secret, helper-file install (mu-loader + db.php shim),
  placeholder replacement. Self-heals a leftover fake db.php from a crashed 06.
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
  gracefully; hold/swap mechanics displace and restore it correctly.
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
  method, the cart and checkout pages rendering a real cart, zero order/stock/session
  residue, fulfilment identifiers retained for the local overlay after cleanup, the payment
  boundary marked and the waterfall split at it, the pre-flight
  inventory naming a planted integration, a planted blocking HTTP call caught and
  attributed, mail really delivered in deliver mode, the Excimer roll-up, and both named
  failure paths. Plus the payment-mode safety assertions: a flow token with no `pm` flag,
  a junk one, or `pm=s` with no gateway adapter must all take the no-payment path.
  Two fixture plugins are planted and removed by the case itself.
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
  page profiles, checkout flows, spot checks, deep scans and Excimer data.
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

- `32-loopback-timeout.php` - measurement requests impose no response timeout, proven with a
  real page that sleeps 12s while the obsolete stored timeout is 10s. It also forces a real
  WordPress transport failure through the crawler, profile store and result panel, asserting
  that the exact transport explanation is retained and displayed. Gotcha: the slow-page probe
  query arg must NOT be `sspa_`-prefixed - the catalogue matcher strips `sspa_*` keys and the
  run would file under `home` and measure the catalogue URL without the sleep.

- `33-order-management.php` - the order-management steps the checkout flow appends (view order
  in wp-admin, mark processing -> completed). Drives the real `start(['type' => 'checkout'])`
  path; asserts both steps measured, the view ran as admin and rendered, a fixture hooking
  `woocommerce_order_status_completed` fired inside the measured step, the transition was
  processing -> completed, the waterfall's `management` bucket holds both steps with real time
  and expandable per-step component diagnostics, management findings never use customer-checkout wording,
  while the customer `total_ms` excludes them, and the completed order was still deleted. Gotcha:
  the transition runs in the loopback, so the flow must bust its order cache (`wp_cache_delete($id,
  'orders')` + `clean_post_cache`) before reading the resulting status or it reads back stale.

- `38-turnstile-checkout.php` - plants Cloudflare Turnstile's documented bypass contract
  and both WooCommerce checkout validation surfaces, then proves the real synthetic checkout
  completes, records the scoped bypass and removes its temporary order.
- `39-cache-safety.php` - the shared-cache safety scan keeps cookie/nonce names but
  never their values, recognises existing Type A/Type B coverage and private surfaces, ignores
  source-code comments, separates edge cookies, records nonce containers, scores repeated
  site-wide signals once, filters admin-oriented source evidence, ranks corroborated
  visitor-state/output evidence, produces stable cache-safety status and review difficulty,
  and excludes checkout, wp-admin and builder template pseudo-pages from shared-cache candidates.
- `40-traffic-contracts.php` - freezes the cache optimisation, provisional/final traffic and
  optional Cloudflare JSON contracts; every fixture passes the shared forbidden-property
  validator, while planted customer identity fields fail closed. Also pins the clearer immediate
  analysis GUI name and keeps the existing `sspa/shared-cache-safety-report@2` machine schema.
- `41-traffic-schema-lifecycle.php` - all five additive traffic tables, database insert
  pre-flight, collection HMAC key separation, generated active-only MU observer, one/two/four-hour
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
  discovery, latest-modified target selection, both WooCommerce order transports, validated
  controlled-editor launches, and a real no-change REST save whose attempted mail is measured
  but suppressed before transport.
- `52-release-hardening.php` - dedicated hidden checkout product, verified/bounded profiling
  HTTP arguments, atomic owner leases, lazy anonymous bootstrap and the History uninstall
  control. The build smoke separately proves opt-in uninstall drops every table and removes
  owned options, the checkout product and test customer.

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
written reached 99.9 million row combinations and an 82-second home page at 464 posts. Cases 07
and 09 both use `(SELECT ID FROM posts LIMIT 120)` aliases for this reason. The symptom is never
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

## Not yet covered (planned)

- Real Query Monitor cross-check: profile the same page with our profiler and QM's actual
  QM_DB drop-in and assert SQL count/time/rows agree within tolerance (06 uses a fake QM
  header; the real-QM path needs `wp plugin install query-monitor` + its symlink).
- Customer variant (flagged test account) - lands with phase 2 catalogue work.
- Crash-safety kill test: kill -9 mid-run, assert stale-hold self-heal on next load.
