# Super Speedy Performance Analysis - test suite

Status: stub (Phase 0). The harness gets built in Phase 1 alongside the capture engine -
see `.docs/implementation-plan.md`.

## Planned harness (Phase 1)

- e2e tests driven by wp-cli against this local install (`wp sspa run` once the CLI exists;
  until then, direct invocation via `wp eval`).
- Assertions: a baseline run completes; profile rows exist for the expected page keys; sanity
  invariants hold (page_gen_ms > 0, sql_ms <= page_gen_ms, rows captured when the sspa db.php
  shim is active).
- Cross-check test: the same page profiled by our profiler and by Query Monitor (with its
  db.php symlink) - SQL count, SQL time and total rows must agree within tolerance.
- Unit-style tests (plain PHP via `wp eval-file`): SQL fingerprint normaliser, component map
  (stack frame -> plugin slug), token signing/verification, and later the bisection planner
  against synthetic cost functions.
- Crash-safety test: kill a run mid-flight and assert no `db.php.sspa-hold` is left behind
  after the next plugin load.

## Local-dev gotchas

See the superspeedy-dev-env notes: wp-cli can fatal when scalability-pro is active, plugins
can silently auto-deactivate, and app passwords over HTTP need WP_ENVIRONMENT_TYPE=local.
Check those before assuming a harness failure is real.
