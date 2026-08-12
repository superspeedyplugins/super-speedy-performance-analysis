# Compatibility - Super Speedy Performance Analysis

Compiled 2026-07-28, refreshed 2026-08-12 against **0.18.0** (git 70f5123).

| Doc | Covers |
|---|---|
| `README.md` | Declared requirements, hosting, the drop-in question, object cache, database |
| `server-capabilities.md` | PHP extensions, what each unlocks, install-step coverage |
| `woocommerce.md` | What the checkout and order flow needs, HPOS, mail |

## Declared requirements

| Requirement | Value | Note |
|---|---|---|
| WordPress | **6.2 minimum** | Declared in both `readme.txt` and the plugin header |
| Tested up to | **7.0** | Current as of 0.9.13; WordPress 7.0.3 verified in the docker harness |
| PHP | **7.4 minimum** | |
| Licence | GPLv3 (since 2026-08-06, was GPLv2 or later) | The plugin is free and ungated |

**Why 6.2:** verify before publishing anything that depends on it. The 6.2 floor predates the
features that now carry their own floors, and two are higher:

- **WordPress 6.1** for option access tracking - it uses the generic `pre_option` filter, added
  in 6.1. Below the plugin's own 6.2 floor, so not a constraint in practice.
- **WordPress 6.9+** for the Abilities API surface **only**. The bootstrap gates on
  `function_exists('wp_register_ability')`, so on older WordPress the abilities do not register
  rather than erroring. Everything else works from 6.2.

## What it needs from the host

- **Working loopback requests.** The whole capture engine profiles pages via signed loopback
  HTTP requests. A host or security plugin that blocks loopbacks stops the run. The plugin
  detects this case and names what to whitelist rather than failing silently.
- **A writable `wp-content/`** for the conditional `db.php` drop-in and the mu-plugin loader.
- **Time.** A sweep measures every chosen plugin on every profiled page. `wp sspa run
  --type=deep` allows 6 hours, and the run controller only fails a run after 3 hours without
  progress.
- **A page that responds inside the measurement timeout.** Default 60 seconds, settable 10-900
  on the Settings tab (0.16.1). A page slower than the limit records nothing at all.

**Loopbacks and your CDN.** On many hosts a loopback bypasses the CDN, so the profiled request
arrives without the headers Cloudflare adds for real visitors. Behaviour keyed on those headers
then differs between the measured path and the visitor path. The plugin records and discloses
this per capture rather than pretending the two paths are identical - see `profiling.md`.

## The `db.php` drop-in question

WordPress allows exactly one `db.php`. This plugin ships a conditional one that is inert for
normal traffic. It detects a foreign drop-in and handles it three ways:

| Existing drop-in | Behaviour |
|---|---|
| Query Monitor | Rides Query Monitor's drop-in |
| LudicrousDB, W3 Total Cache, other | Runs degraded alongside |
| Any, with explicit opt-in | Temporarily swaps it out for the run, with crash-safe restoration and a clear warning |

Two caveats, both surfaced in the UI:

- **With Query Monitor active, anonymous page profiles capture no per-query data at all** - QM
  only collects for logged-in viewers. The health check warns rather than claiming full detail,
  and the temporary swap is offered for Query Monitor too.
- **An orphaned QM `db.php`** (plugin deactivated, drop-in left behind) blocks the capture layer.
  It is detected and a one-click replacement offered; the old file is renamed, never deleted.

**Plugin Impact Analysis needs THIS plugin's own `db.php`** (since 0.16.0), because the guard
that refuses destructive statements during a measurement lives in that shim. Sites where another
plugin owns the drop-in must use the temporary swap option to run it.

## Object cache

Cache-mode measurement (disabled / priming / normal) needs a **persistent object cache** - Redis
or Memcached - and the SSPA `db.php` shim present. Without a persistent object cache the analysis
still runs; only the three-way cache comparison is unavailable. Since 0.16.0 the cache modes are
opt-in from the chooser rather than automatic.

## Full-page caches and CDNs

Supported, and specifically handled since 0.9.4. Every profiling request carries a
cache-busting argument signed into the token, so nginx, Varnish, LiteSpeed and CDN caches cannot
answer it. Profiled responses send `Cache-Control: no-store`. Cache-served responses are
recognised from several signals (`x-fastcgi-cache`, the `Age` header and others) and classified
as cached rather than reported as a generic failure. Pages that still could not be measured are
counted and named, not silently dropped.

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

Row counts for big tables use `information_schema` estimates rather than `COUNT(*)`, so the
plugin does not do to a customer's 10M-row postmeta what it advises against.

## Multisite

**Untested and unclaimed.** Listed as parked in the phase 7 future-work doc. Nothing in the code
handles network activation specially. Do not claim multisite support.
