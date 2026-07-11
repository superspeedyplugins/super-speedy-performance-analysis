# Super Speedy Performance Analysis - test suite

The suite runs against a disposable Docker WordPress site - NEVER against the local
superspeedy install (that install is used for other testing and gets `wp reset` regularly).

## Running the tests

```bash
.tests/run-tests.sh            # starts the docker env if needed, syncs the plugin, runs all cases
.tests/run-tests.sh e2e        # run only cases whose filename contains "e2e"
.tests/docker/down.sh          # tear the environment down
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

## Not yet covered (planned)

- Real Query Monitor cross-check: profile the same page with our profiler and QM's actual
  QM_DB drop-in and assert SQL count/time/rows agree within tolerance (06 uses a fake QM
  header; the real-QM path needs `wp plugin install query-monitor` + its symlink).
- Customer variant (flagged test account) - lands with phase 2 catalogue work.
- Crash-safety kill test: kill -9 mid-run, assert stale-hold self-heal on next load.
