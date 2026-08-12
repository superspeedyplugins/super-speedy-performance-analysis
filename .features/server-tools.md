# Server capabilities and the Tools tab

<!-- internal -->
Read this before writing marketing copy from this doc. The position CHANGED in 0.10.0 and the
old warning here is void: **excimer is now genuinely used** - `'used' => extension_loaded('excimer')`
with status `active`, and `SSPA_Excimer` is wired into `profiler/class-sspa-capture.php`. Function-level
profiling is a real, shipped feature (see `profiling.md`).
What is still detected-but-unused, grep-verified with `'used' => false`: **`tideways_xhprof`/`xhprof`**
and **`spx`**. Do not write "flamegraphs" or "exact call counts" - the XHProf-family collector is
phase 6 and is not built.

### Tools tab: what this server can do
**Since:** 0.9.2, 30 July 2026

A tab reporting which deeper-analysis capabilities this server has, each with what it would add
and whether the plugin uses it today. Four statuses, and the distinctions are deliberate:
**active** (present and used now), **available** (present, not used by the plugin yet),
**missing** (not installed) and **blocked** (installed but refused, e.g. a database permission).

Detected: `excimer`, MySQL `performance_schema`, `tideways_xhprof`/`xhprof`, `spx`. Used today:
`EXPLAIN` (always), `excimer` (where installed) and `performance_schema` (where readable).

Since 0.10.4 the Re-check button rechecks in place over ajax instead of reloading the page.

### Excimer leads the tab
**Since:** 0.10.9, 4 August 2026

The tab leads with excimer, because it is the single biggest capability upgrade a site can make,
and says so plainly: *"if you install one thing, install excimer"*. Its card describes everything
it unlocks - the function view, driven-by splits, per-phase drill-downs - and switches to Active
with an in-use description once installed. The tracing-profiler card (`tideways_xhprof`) states
honestly that the plugin does not read it: exact call counts are a planned deep-dive mode, and
sampling answers most questions without the overhead.

Function-level profiling has a full guide in the knowledge base
(`.kb/function-level-profiling.md`, published at
superspeedyplugins.com/kb/super-speedy-performance-analysis/features/function-level-profiling/):
what the function view gives you, how to read self versus inclusive time, and the exact install
steps including the noexec `/tmp` fix.

### Install steps generated for THIS server
**Since:** 0.9.2, 30 July 2026 (distribution packages 0.11.4)

For anything missing, the plugin writes the exact commands for the detected operating system,
distribution, package manager, PHP version, SAPI and ini scan directory - not generic
documentation the reader has to translate. Each step carries the reason it is needed, and each
command block has a copy button.

Excimer install steps use the **distribution's own package**, named for the exact PHP version the
site runs so a server carrying more than one PHP enables it for the right one: `php8.3-excimer`
style on Debian and Ubuntu, Remi's `php-pecl-excimer` on RHEL and Fedora, `php83-pecl-excimer` on
Alpine. `pecl` remains only where no package exists, because upstream no longer publishes excimer
releases there. `tideways_xhprof` steps build the extension from its source repository,
`github.com/tideways/php-xhprof-extension`.

Where PECL is still used, the steps include the fix for the *"shtool ... does not exist or is not
executable"* failure: hardened servers mount `/tmp` with `noexec`, which breaks pecl's build. The
verified fix is a temporary exec remount of `/tmp` for the build, restoring `noexec` afterwards -
proven on a real hardened server (0.10.4).

### It never installs anything itself
**Since:** 0.9.2, 30 July 2026

The plugin does not edit `php.ini`, run `pecl`, invoke a package manager, restart anything, or
ship a compiled extension. Everything is text for a human to read and run. Enforced by rule in
`includes/class-sspa-tools.php` and asserted by the test suite: no `exec`, `shell_exec`,
`passthru`, `proc_open`, `popen`, `system`, `file_put_contents` or `ini_set` in that path.

### A ready-written message for your host
**Since:** 0.9.2, 30 July 2026

Most WordPress sites are on shared hosting and can install nothing themselves, so "ask your
host" is a first-class output rather than a shrug. The plugin generates a support-ticket message
with the environment already filled in - PHP version, SAPI, distribution, ini directory, the
same install commands the tab shows - and states plainly that the extension is open source,
local, and sends nothing anywhere.

### The GRANT statement, with your real database user in it
**Since:** 0.9.2, 30 July 2026

Where `performance_schema` is on but unreadable, the plugin generates the exact `GRANT SELECT ON
performance_schema.*` statement with the site's own `CURRENT_USER()` filled in and quoted
correctly. It is read-only access to query statistics: it grants nothing over site data and
cannot change anything, and the UI says so.

### Backtrace truncation, measured on your site
**Since:** 0.9.2, 30 July 2026

Attribution walks the PHP call stack, and a stack cut short can hide the frame where one plugin
calls into another. The Tools tab reports how many queries on the most recent run had their
backtrace truncated, and what percentage that is - turning "we think 32 frames is enough" into a
number measured on the site in front of you.

### Existing APM agents detected
**Since:** 0.9.2, 30 July 2026

Reports New Relic, Datadog APM, Blackfire, OpenTelemetry and Elastic APM where their PHP
extensions are already loaded. Informational only - the plugin does not read from them.
