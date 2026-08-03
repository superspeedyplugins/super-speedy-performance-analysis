# Server capabilities and the Tools tab

<!-- internal -->
Read this paragraph before writing any marketing copy from this doc. The Tools tab **detects**
four things and **uses** two of them. `excimer`, `tideways_xhprof`/`xhprof` and `spx` are
detected and their install steps are generated, but no code anywhere in the plugin reads them -
grep-verified, they appear only in `includes/class-sspa-tools.php`, and every one carries
`'used' => false`. Do not write "function-level profiling" or "flamegraphs" as a feature. What
exists today is capability detection plus honest install instructions. Function-level profiling
is phases 5-6 in `.roadmap/planned.md`.

### Tools tab: what this server can do
**Since:** 0.9.2, 30 July 2026

A tab reporting which deeper-analysis capabilities this server has, each with what it would add
and whether the plugin uses it today. Four statuses, and the distinctions are deliberate:
**active** (present and used now), **available** (present, not used by the plugin yet),
**missing** (not installed) and **blocked** (installed but refused, e.g. a database permission).

Detected: MySQL `performance_schema`, `excimer`, `tideways_xhprof`/`xhprof`, `spx`. Used today:
`EXPLAIN` (always) and `performance_schema` (where readable).

### Install steps generated for THIS server
**Since:** 0.9.2, 30 July 2026

For anything missing, the plugin writes the exact commands for the detected operating system,
distribution, package manager, PHP version, SAPI and ini scan directory - not generic
documentation the reader has to translate. An Alpine server gets `apk`, `php83-dev` and
`rc-service`; a Debian one gets `apt`, `php8.3-dev` and `systemctl`; a Mac gets `brew services`.
Each step carries the reason it is needed, and each command block has a copy button.

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
usual install commands - and states plainly that the extension is open source, local, and sends
nothing anywhere.

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
