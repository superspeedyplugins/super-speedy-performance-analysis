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

## Dev: submitting to the local hub (localhost:8081)

The hub companion plugin (repo `hub/` folder) is symlinked into the LOCAL superspeedy
install and activated there, standing in for superspeedy.org during development.
`.tests/dev-local-hub.sh` points the docker analysis site at it
(`http://host.docker.internal:8081`), fires a submission + rules-feed fetch, and prints
the hub-side row counts. After a `wp reset` on the local install, re-activate the hub
plugin (recreates tables + keypair) and re-run the script. The client uses
`?rest_route=` URLs because the local install 301s pretty `/wp-json/` paths to trailing
slashes, which kills POSTs.

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
- `11-community.php` - anonymised submission, hub round trip, signed rules feed.
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

## Not yet covered (planned)

- Real Query Monitor cross-check: profile the same page with our profiler and QM's actual
  QM_DB drop-in and assert SQL count/time/rows agree within tolerance (06 uses a fake QM
  header; the real-QM path needs `wp plugin install query-monitor` + its symlink).
- Customer variant (flagged test account) - lands with phase 2 catalogue work.
- Crash-safety kill test: kill -9 mid-run, assert stale-hold self-heal on next load.
