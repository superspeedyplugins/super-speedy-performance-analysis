# Super Speedy Performance Analysis - test suite

The suite runs against a disposable Docker WordPress site - NEVER against the local
superspeedy install (that install is used for other testing and gets `wp reset` regularly).

## Running the tests

```bash
.tests/run-tests.sh            # starts the docker env if needed, syncs the plugin, runs all cases
.tests/run-tests.sh e2e        # run only cases whose filename contains "e2e"
.tests/docker/down.sh          # tear the environment down
```

Run these from **bash**, not zsh: `docker/env.sh` derives the plugin directory from
`BASH_SOURCE`, which zsh does not set when the file is sourced interactively, and
`sync_plugin` then silently copies the wrong tree (the parent directory) into the container.

### A second environment alongside the default one

Container names and the host port are overridable, so a second checkout - a parallel session
working on a feature branch - can run its own environment without colliding with `sspa-*` on
port 8090:

```bash
export SSPA_ENV_PREFIX=sspack SSPA_PORT=8092
.tests/run-tests.sh
```

A test case file passes when it prints at least one `PASS` line and no `FAIL` lines.
`run-tests.sh` exits non-zero if any case fails.

## The Docker environment

Plain `docker` commands, no compose (not installed on this Mac). `docker/up.sh` creates:

- `sspa-db` - mariadb 10.11
- `sspa-wp` - wordpress php8.3-apache, reachable at http://localhost:8090 (admin/admin);
  its internal site URL is `http://sspa-wp` so the plugin's loopback crawler works
  inside the container network
- WooCommerce + its sample products, 30 generated posts, a News category, 3 orders

`wp` commands run via a throwaway `wordpress:cli` container (`cli()` in `docker/env.sh`).

### Harness gotchas (learned the hard way)

- **No bind mounts**: `/opt/homebrew` is not in Docker Desktop's shared paths, so a bind
  mount appears as an EMPTY directory (silently). The plugin is instead copied in with
  `sync_plugin()` (tar pipe); `run-tests.sh` syncs before every run, so container code is
  always current.
- **wp-config reads env at runtime**: the wordpress image's wp-config.php uses
  `getenv_docker()`, so the CLI container needs the same `WORDPRESS_DB_*` env vars as the
  apache container or it tries to connect to host `mysql`.
- **mariadb client TLS**: the CLI image's mariadb client requires TLS by default, so DB
  readiness is probed with the mariadb container's own `healthcheck.sh`, not `wp db check`.
- **opcache revalidation**: apache revalidates changed PHP files at most every 2s
  (`opcache.revalidate_freq=2`). Tests that swap `wp-content/db.php` sleep 3s before
  sending profiled requests.
- **Sample data can vanish** (observed Jul 2026: 0 products, 0 orders in a long-lived
  env; up.sh's import line ends in `|| true` so a failed seed is silent). The symptom is
  a 5-case failure cluster: sector "general" instead of e-commerce, "product page
  profiled" fails, deep deltas tiny (~25ms - the bad plugin's queries are cheap on an
  empty postmeta), no save-product write profile. `run-tests.sh` now pre-flight checks
  the product count and reseeds automatically.

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
  residue, the payment boundary marked and the waterfall split at it, the pre-flight
  inventory naming a planted integration, a planted blocking HTTP call caught and
  attributed, mail really delivered in deliver mode, the Excimer roll-up, and both named
  failure paths. Plus the payment-mode safety assertions: a flow token with no `pm` flag,
  a junk one, or `pm=s` with no gateway adapter must all take the no-payment path.
  Two fixture plugins are planted and removed by the case itself.
  The whole purchase then runs again against the CLASSIC shortcode checkout: the store is
  pointed at throwaway `[woocommerce_cart]`/`[woocommerce_checkout]` pages and put back
  afterwards, so the block pages are never edited. Two assertions pin the nonce binding
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
  degrading rather than fatalling, catalogue-matched ad-hoc results merging into the Pages tab
  while one-off URLs stay out and the site score stays on baseline/spot. Then plugin impact
  scoped to one page: a sweep pointed at a URL no analysis ever profiled, the measurement count
  it queues matching the estimate the panel promised (1 plugin = 2, 2 plugins = 3), and
  `cache_modes => false` keeping phase 2 out of the cache modes.
- `29-isolation-writes.php` - a measurement must never change which plugins the site runs.
  Two fixtures: a dependency, and a dependant that calls `deactivate_plugins()` on itself when
  the dependency is missing. Sweeping the dependency must leave BOTH active. Without the
  mu-loader's `pre_update_option_active_plugins` guard this case fails with the plugin list
  shrunk by two, which is what happened to a real site's Rank Math install on 10th August 2026.

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
source .tests/docker/env.sh
sync_plugin
cli eval-file "$CONTAINER_PLUGIN_DIR/.tests/manual/live-r2.php" http://host.docker.internal:8788
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
source .tests/docker/env.sh
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

Any unrelated queued outbox items are paused for the duration and resumed afterwards, so a
local queue can never be flushed to the production archive as a side effect. The script prints
the submission UUID, receipt UUID and SHA-256, and never prints the installation secret or a
presigned upload URL.

`.tests/manual/live-production-runs.php` takes the same two arguments and the same host guard,
but delivers one REAL analysis of every run type - the most recent completed unshared run of
each - instead of a synthetic fixture. It uses the per-run consent path, so the site-wide
sharing setting stays off throughout, and it asserts that afterwards. Use it when the transport
or the exporters change: the synthetic fixture is ~1 KB with one evidence record and will not
notice a payload-size or evidence-volume problem.

Both scripts write real permanent objects to the production archive. Run them only from the
disposable Docker site, and expect `processing_status=partial` while the production processor
is 1.0 and the payload schema is 1.1.

## Not yet covered (planned)

- Real Query Monitor cross-check: profile the same page with our profiler and QM's actual
  QM_DB drop-in and assert SQL count/time/rows agree within tolerance (06 uses a fake QM
  header; the real-QM path needs `wp plugin install query-monitor` + its symlink).
- Customer variant (flagged test account) - lands with phase 2 catalogue work.
- Crash-safety kill test: kill -9 mid-run, assert stale-hold self-heal on next load.
