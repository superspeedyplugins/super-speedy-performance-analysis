# Server capabilities

Two optional server extras unlock the deepest views. Neither is required, and the analysis runs the same without them, but if you install one thing, install excimer.

## Excimer: which function, not just which plugin

Most tools stop at "this plugin is slow". With the excimer PHP extension installed, every profile gains a by-function breakdown: self time and inclusive time per function, attributed to its plugin or theme, including inside theme template files. Functions are listed by self time and split by who drove them, with per-phase drill-downs.

Excimer is a sampling profiler built by the Wikimedia Foundation and run on every Wikipedia request. It's free.

The full guide, including how to read self versus inclusive time, is in the knowledge base: [Function-Level Profiling](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/features/function-level-profiling/).

## MySQL performance_schema: rows examined versus rows returned

A query that reads 400,000 rows to hand back 12 is doing a hidden full scan. Our own capture sees only what came back, and `EXPLAIN` only estimates, so this is invisible to every other measurement the plugin takes.

Where MySQL's own digest counters are readable, the analysis compares rows examined against rows returned and flags the gap. The default thresholds are 100x more rows read than returned, with at least 1,000 rows read.

Without it, the analysis runs exactly as before and this one finding doesn't appear. Nothing is estimated or invented in its place.

## The Tools tab writes the commands for your server

For anything missing, the plugin generates the exact commands for the detected operating system, distribution, package manager, PHP version, SAPI and ini scan directory. Not generic documentation you have to translate. Each step says why it's needed and has a copy button.

Excimer steps use your distribution's own package, named for the exact PHP version the site runs, so a server carrying more than one PHP enables it for the right one: `php8.3-excimer` style on Debian and Ubuntu, Remi's `php-pecl-excimer` on RHEL and Fedora, `php83-pecl-excimer` on Alpine. `pecl` is used only where no package exists.

Where PECL is used, the steps include the fix for the *"shtool ... does not exist or is not executable"* failure. Hardened servers mount `/tmp` with `noexec`, which breaks pecl's build.

For `performance_schema`, the tab writes out the one read-only `GRANT` needed, with your real database user in it.

**It never installs anything itself.** It writes the steps; you or your host run them.

## If you don't administer the server

The Tools tab also produces a ready-written message for your host, explaining what you need and why. Paste it into a support ticket.

## Checking what you've got

The PA admin-bar menu shows both, on every screen, under **This site**: whether excimer is installed and whether MySQL digests are readable, each linking straight to the Tools tab. An amber line means the capability is missing and you're getting less detail than the plugin can give you.

## Other things the tab detects

Existing APM agents, backtrace truncation measured on your own site, and whether a tracing profiler such as `tideways_xhprof` is present. The tab says honestly that the plugin doesn't read `tideways_xhprof`: exact call counts are a planned mode, and sampling answers most questions without the overhead.

---

Related: [[Per-Page-Analysis]] · [[Troubleshooting]] · [[How-It-Measures]]
