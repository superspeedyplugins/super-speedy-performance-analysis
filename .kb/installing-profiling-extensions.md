# Installing the Optional Profiling Extras

Super Speedy Performance Analysis works fully on its own. Everything on this page is **optional**.

Go to **Super Speedy → Performance Analysis → Tools**. It reports what your server can already do, and for anything missing it generates the exact commands for *your* operating system, PHP version and init system - not generic documentation you have to translate. There is a copy button on every command, and a "paste this to your host" message for the many sites that cannot run these themselves.

**The plugin never installs anything itself.** It does not edit `php.ini`, run `pecl`, or restart anything. It shows you what to run.

## What each extra adds

| Extra | What it adds | Needs | Status |
|---|---|---|---|
| `excimer` | Which function is burning the time, safe on live sites | A PHP extension | Used where installed |
| MySQL query fingerprints | **Actual** rows examined per query, not the optimiser's estimate | A one-line `GRANT`, sometimes a MySQL restart | Used where readable |
| `EXPLAIN` query plans | Why a query is slow: missing index, filesort, temp table. It also catches queries that are fast at your current size but will not scale | Nothing | Always on |
| Environment report | What your server supports, and the commands to enable the rest | Nothing | Always on |
| `tideways_xhprof` / `xhprof`, `spx` | Detected and reported, so you can see what the server offers | A PHP extension | Detected, not read |

**If you install one thing, install `excimer`.** It is the single biggest capability upgrade a site can make: without it a profile tells you which plugin spent the time, and with it the same profile names the function inside that plugin. [Function-Level Profiling](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/features/function-level-profiling/) shows what that view gives you.

**Read the status column before you install anything.** The Tools tab detects everything in the table, including the extensions the plugin does not read, and labels each one accordingly, so it never claims to be using something it is not. Installing an extension in the bottom row gets your host's part done in advance; it will not change your analysis.

Nothing here sends data anywhere. All of it is read locally and stays on your server.

## Can I install these at all?

Open **Super Speedy → Performance Analysis → Tools**. Each row is marked **Active** (present and in use), **Available** (present), **Needs permission** (present but the database user cannot read it), or **Not installed**.

The short version:

- **Shared hosting**: you can almost certainly enable the MySQL query fingerprints by asking support, and almost certainly cannot install a PHP extension. The `EXPLAIN` analysis works already and needs nothing.
- **VPS, managed WordPress hosting with SSH, or your own server**: all of it is available.

Where something is missing, press **Show installation steps**. Underneath the commands there is a ready-written message you can paste into a support ticket. Hosts turn down vague requests far more often than specific ones.

---

## 1. MySQL query fingerprints

This uses MySQL's own `performance_schema`, which is built in. You are not installing anything, you are being granted read access to statistics the database already collects.

### Step 1: check whether it is switched on

```sql
SHOW VARIABLES LIKE 'performance_schema';
```

`ON` means go to step 2. `OFF` means it needs enabling first, which needs a MySQL restart:

- **MySQL**: usually already `ON`.
- **MariaDB**: ships **off by default**. Add to `my.cnf` under `[mysqld]`, then restart MySQL:

```ini
[mysqld]
performance_schema = ON
```

### Step 2: grant read access

Even with `performance_schema` on, a normal WordPress database user cannot read it. You will see:

```
ERROR 1142 (42000): SELECT command denied to user 'youruser'@'localhost'
```

That is expected. Run this as a database administrator, substituting the user and host shown on the Tools tab:

```sql
GRANT SELECT ON performance_schema.* TO 'youruser'@'localhost';
FLUSH PRIVILEGES;
```

This is **read-only**. It grants no ability to change any data, and it is the only permission the plugin asks for.

### Step 3: re-check

Go back to the Tools tab and press Re-check. The status should change from "Needs permission" to "Available".

With the grant in place the plugin reads the digest statistics and reports rows examined against rows returned. Without it, that finding does not appear and nothing is estimated in its place.

### What if my host says no?

You still get the `EXPLAIN` analysis, which needs no permission and covers the most common problem by far: a query with no usable index. The difference is that `EXPLAIN` gives the optimiser's *estimate* of how many rows will be read, and `performance_schema` gives what actually happened.

One real limit worth knowing: `EXPLAIN` only runs on queries whose full SQL was kept. To protect your data the plugin keeps the full text of only the slowest and largest queries per page, and stores everything else as a fingerprint with all values stripped. So query plans cover the queries most worth explaining, not every query on the page.

---

## 2. `excimer` - which function is slow

A sampling profiler maintained by the Wikimedia Foundation and used in production on Wikipedia. Overhead is negligible, which is why it is the one to install first.

Licence: Apache 2.0, free and open source.

### Install

```bash
# Debian / Ubuntu
sudo apt-get install php-pear php-dev
sudo pecl install excimer

# RHEL / Rocky / AlmaLinux
sudo dnf install php-pear php-devel
sudo pecl install excimer

# macOS with Homebrew PHP
pecl install excimer
```

### Enable

Create a dedicated ini file rather than editing the main `php.ini`, so a PHP upgrade does not wipe it. The Tools tab shows the exact directory for your server. Typically:

```bash
echo "extension=excimer.so" | sudo tee /etc/php/8.3/fpm/conf.d/20-excimer.ini
echo "extension=excimer.so" | sudo tee /etc/php/8.3/cli/conf.d/20-excimer.ini
```

### Restart PHP

The correct command depends on your init system - the Tools tab shows the right one for your server (systemd, OpenRC on Alpine, or Homebrew on macOS). On a typical systemd host:

```bash
sudo systemctl restart php8.3-fpm     # or php-fpm, depending on your distribution
```

### Verify

```bash
php -m | grep excimer
```

Then press Re-check on the Tools tab. Note that `php -m` on the command line and the extension list seen by your website can differ: PHP-FPM and the CLI often have separate ini directories, which is why the commands above write to both.

---

## 3. `tideways_xhprof` - detected, not read

A tracing profiler, where `excimer` samples. Super Speedy Performance Analysis **detects** it and reports it on the Tools tab, and does not read its data, so installing it does not change your analysis. It is listed here because the Tools tab names it and people ask what it is for.

Licence: Apache 2.0, free and open source. This is the free extension published by Tideways. It is **not** the paid Tideways service and it sends nothing to them.

### Install

```bash
sudo pecl install tideways_xhprof
echo "extension=tideways_xhprof.so" | sudo tee /etc/php/8.3/fpm/conf.d/20-tideways_xhprof.ini
sudo systemctl restart php8.3-fpm
```

### Important: it is slower on purpose

Tracing every single function call is expensive and it distorts timings. That is the reason a measured-impact run relies on sampling: a tracing profiler would corrupt the numbers those runs exist to produce.

If you only install one extension, install `excimer`.

---

## 4. `spx` - a standalone profiler with its own interface

If you want flamegraphs and a timeline view, [php-spx](https://github.com/NoiseByNorthwest/php-spx) is excellent, self-hosted, and keeps all data on your own server. Licence: GPL-3.

Super Speedy Performance Analysis **detects** it, but does not read its data: its own interface is already better than a reimplementation would be.

---

## Frequently asked

**Will any of this slow down my site?** Having `excimer` loaded, or granting the MySQL permission, costs you nothing measurable. `tideways_xhprof` adds real overhead whenever it is actively profiling, which is one reason the plugin does not drive it.

**Does any of this send data to Super Speedy Plugins, or anyone else?** No. These are local extensions and a local database table. Nothing leaves your server. If you separately opt in to sharing anonymised results on the Share tab, you see the exact payload first, as always.

**Which should I install first?** `excimer`, by a distance. It is read as soon as it is present and it changes every profile you take. The `performance_schema` GRANT is worth requesting at the same time, because host tickets are slow. `tideways_xhprof` and `spx` are detected and not read, so there is nothing to gain from installing them for this plugin.

**I installed the extension and the Tools tab still says Not installed.** Almost always PHP-FPM was not restarted, or the extension was enabled for the CLI but not for FPM. Check both ini directories, then restart PHP rather than reloading it.

**My host will not install extensions.** That is common and it is not a fault in your setup. You still get the full analysis, plugin attribution and Plugin Impact Analysis measured impact, which is where the answer usually is. The extensions add function-level detail on top.

**Is the `GRANT` safe?** Yes. `SELECT` on `performance_schema` is read-only access to performance statistics. It grants nothing over your site's data and cannot modify anything.

## Further reading

- [Function-Level Profiling](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/features/function-level-profiling/) - what excimer gives you once it is installed
- [Methodology](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/advanced/methodology/) - how the measurements are made
- [Quick Start Guide](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/quick-start-guide/) - running your first analysis
