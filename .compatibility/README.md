# Compatibility - Super Speedy Performance Analysis

Compiled 2026-07-28, refreshed 2026-08-02 against 0.9.2 (git c9d785a).

## Declared requirements

| Requirement | Value | Note |
|---|---|---|
| WordPress | **6.2 minimum** | Declared in both `readme.txt` and the plugin header |
| Tested up to | **6.9** | Current at time of compile |
| PHP | **7.4 minimum** | |
| Licence | GPLv3 (since 2026-08-06, was GPLv2 or later) | The plugin is free and ungated |

**WordPress 6.9+ is required for the Abilities API surface only.** Everything else works from
6.2. The bootstrap gates on `function_exists('wp_register_ability')`, so on older WordPress the
abilities simply do not register rather than erroring.

## What it needs from the host

- **Working loopback requests.** The whole capture engine profiles pages via signed loopback
  HTTP requests. A host or security plugin that blocks loopbacks stops the run. The plugin
  detects this case and names what to whitelist rather than failing silently.
- **A writable `wp-content/`** for the conditional `db.php` drop-in and the mu-plugin loader.
- **Time.** A deep sweep measures every plugin on every profiled page. `wp sspa run --type=deep`
  allows 6 hours, and the run controller only fails a run after 3 hours without progress.

## The `db.php` drop-in question

WordPress allows exactly one `db.php`. This plugin ships a conditional one that is inert for
normal traffic. It detects a foreign drop-in and handles it three ways:

| Existing drop-in | Behaviour |
|---|---|
| Query Monitor | Rides Query Monitor's drop-in |
| LudicrousDB, W3 Total Cache, other | Runs degraded alongside |
| Any, with explicit opt-in | Temporarily swaps it out for the run, with crash-safe restoration and a clear warning |

## Object cache

Cache-mode measurement (disabled / priming / normal) needs a **persistent object cache** -
Redis or Memcached - and the SSPA `db.php` shim present. Without a persistent object cache the
analysis still runs; only the three-way cache comparison is unavailable.

## Database

Everything below is a **graceful upgrade**: the analysis runs in full without any of it. Nothing
is estimated or invented when a source is unavailable - the affected finding simply does not
appear.

| Capability | Needs | Without it |
|---|---|---|
| Query plans (`EXPLAIN`) | Nothing. Works on any MySQL or MariaDB with the SELECT permission the site already has | n/a - always available |
| MySQL query fingerprints | `performance_schema` **on** AND `GRANT SELECT ON performance_schema.*` for the site's database user | The "reads far more rows than it returns" finding does not appear |

**MariaDB ships with `performance_schema` off by default**, and switching it on needs a `my.cnf`
change and a MySQL restart. Managed hosts vary widely on whether either the setting or the GRANT
is possible. The Tools tab reports which rung of that ladder this server is on - off, on but
unreadable, or on and readable - and generates both the config snippet and the GRANT with the
site's real database user filled in.

The GRANT is read-only access to query statistics. It confers no access to site data and cannot
modify anything.

## PHP extensions

| Extension | Status in 0.9.2 |
|---|---|
| `excimer` | Detected, install steps generated, **not used by the plugin** |
| `tideways_xhprof` / `xhprof` | Detected, install steps generated, **not used by the plugin** |
| `spx` | Detected, install steps generated, **not used by the plugin** |
| `newrelic`, `ddtrace`, `blackfire`, `opentelemetry`, `elastic_apm` | Detected and reported, informational only |

No PHP extension is required by anything the plugin does today. Install-step generation covers
apt, dnf, apk and Homebrew, and derives the service restart from the detected init system
(systemd, OpenRC or `brew services`).

## Integrations detected

WooCommerce pages are profiled specifically (shop, archives, checkout) alongside front-end and
wp-admin pages. Write profiling steps a temporary WooCommerce order through its status
transitions.

The MCP Adapter plugin is optional: with it installed, the eight abilities appear as MCP tools
automatically. Without it, they remain available over the core abilities REST controller.
