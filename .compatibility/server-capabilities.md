# PHP extensions and server capabilities

No PHP extension is **required**. Everything here is a graceful upgrade: without it the affected
view simply does not appear, and nothing is estimated in its place.

## Status at 0.18.0

| Extension | Detected | Used by the plugin | What it gives you |
|---|---|---|---|
| `excimer` | Yes | **Yes**, since 0.10.0 | Function-level profiling: the "By function" breakdown, self vs inclusive ms, driven-by splits, per-phase function lists |
| `performance_schema` (MySQL, not a PHP extension) | Yes | **Yes**, since 0.9.2 | The "reads far more rows than it returns" finding |
| `tideways_xhprof` / `xhprof` | Yes | **No** | Nothing today. Exact call counts are the unbuilt phase 6 |
| `spx` | Yes | **No** | Nothing today |
| `newrelic`, `ddtrace`, `blackfire`, `opentelemetry`, `elastic_apm` | Yes | No | Reported as present, informational only. The plugin never reads from them |

`'used' => false` is grep-verifiable in `includes/class-sspa-tools.php` for `tideways_xhprof`,
`xhprof` and `spx`. Do not describe flamegraphs or exact call counts as features.

## Excimer

Apache 2.0, sampling, negligible overhead - the same profiler runs on every Wikipedia request,
which is why the plugin is willing to run it during the normal measurement pass without
distorting the timings being measured.

What it is honestly not: it is **statistical**. The sampling period is 1ms, so a function cheaper
than that is invisible, and reported milliseconds are samples x period rather than measured wall
time. Caps are 40 functions globally and 10 per phase.

Install-step coverage, per distribution, all naming the exact PHP version the site runs so a
server carrying several PHPs enables it for the right one:

| Platform | Package |
|---|---|
| Debian / Ubuntu | `php8.3-excimer` style, from the distribution or Sury |
| RHEL / Fedora | Remi's `php-pecl-excimer` |
| Alpine | `php83-pecl-excimer` |
| macOS | Homebrew, with `brew services` for the restart |
| Anything else | `pecl`, only where no package exists - upstream no longer publishes excimer releases to PECL |

Where PECL is used, the steps include the `noexec /tmp` fix (temporary exec remount for the
build, `noexec` restored afterwards), which is the cause of the *"shtool ... does not exist or is
not executable"* failure on hardened servers. Proven on a real hardened server.

## Install-step generation

Derived from the detected operating system, distribution, package manager, PHP version, SAPI and
ini scan directory. Covers `apt`, `dnf`, `apk` and Homebrew, and derives the service restart from
the detected init system (systemd, OpenRC or `brew services`).

**The plugin never installs anything.** It does not edit `php.ini`, run `pecl`, invoke a package
manager, restart anything, or ship a compiled extension - enforced by rule and asserted by the
test suite (no `exec`, `shell_exec`, `passthru`, `proc_open`, `popen`, `system`,
`file_put_contents` or `ini_set` in that path).

For shared hosting, where the owner can install nothing themselves, the plugin generates a
support-ticket message for the host with the environment already filled in.

## Hosting tiers, and what each can realistically use

| Tier | Available | What they get |
|---|---|---|
| Shared hosting (most WordPress sites) | No extensions, no DB server access | Everything except the function view and the performance_schema finding: profiling, attribution, phase decomposition, `EXPLAIN`, plugin impact analysis, checkout flow |
| VPS / self-managed / a good managed host | Extensions and DB config | The full set, including excimer and `performance_schema` |
| Running an ops stack already | Everything | The full set. Existing APM agents are detected and reported but never read from |

The important point for copy: **bootstrap decomposition, `EXPLAIN` plans and measured plugin
impact all need nothing installed**. The tier ladder only decides whether the function view and
the rows-examined finding are available on top.
