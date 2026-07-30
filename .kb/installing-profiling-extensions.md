# Installing the optional profiling extras

Super Speedy Performance Analysis works fully on its own. Everything on this page is
**optional**.

Go to **Super Speedy → Performance Analysis → Tools**. It reports what your server can already
do, and for anything missing it generates the exact commands for *your* operating system, PHP
version and init system - not generic documentation you have to translate. There is a copy
button on every command, and a "paste this to your host" message for the many sites that
cannot run these themselves.

**The plugin never installs anything itself.** It does not edit `php.ini`, run `pecl`, or
restart anything. It shows you what to run.

## What is live today

| Extra | What it adds | Needs | Status |
|---|---|---|---|
| `EXPLAIN` query plans | Why a query is slow: missing index, filesort, temp table. Also catches queries that are fast now but will not scale | Nothing | **Live now** |
| Environment report | What your server supports, and the commands to enable the rest | Nothing | **Live now** |
| MySQL query fingerprints | **Actual** rows examined per query, not the optimiser's estimate | A one-line `GRANT`, sometimes a MySQL restart | Detected; not read yet |
| `excimer` | Which function is burning the time, safe on live sites | A PHP extension | Detected; not read yet |
| `tideways_xhprof` | Exact call counts, for finding N+1 loops | A PHP extension | Detected; not read yet |

**Read that status column before you install anything.** The Tools tab detects the extensions
and generates correct installation steps for them today, but the plugin does not yet *read*
them - that support is still being built. Installing them now gets you ready, and gets your
host's part done in advance, but it will not change your analysis yet. The Tools tab labels
each one the same way, so it will never claim to be using something it is not.

Nothing here sends data anywhere. All of it is read locally and stays on your server.

## Can I install these at all?

Open **Super Speedy → Performance Analysis → Tools**. Each row is marked **Active** (present
and in use), **Available** (present), **Needs permission** (present but the database user
cannot read it), or **Not installed**.

The short version:

- **Shared hosting**: you can almost certainly enable the MySQL query fingerprints by asking
  support, and almost certainly cannot install a PHP extension. The `EXPLAIN` analysis works
  already and needs nothing.
- **VPS, managed WordPress hosting with SSH, or your own server**: all of it is available.

Where something is missing, press **Show installation steps**. Underneath the commands there
is a ready-written message you can paste into a support ticket. Hosts turn down vague requests
far more often than specific ones.

---

## 1. MySQL query fingerprints

This uses MySQL's own `performance_schema`, which is built in. You are not installing
anything, you are being granted read access to statistics the database already collects.

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

Even with `performance_schema` on, a normal WordPress database user cannot read it. You will
see:

```
ERROR 1142 (42000): SELECT command denied to user 'youruser'@'localhost'
```

That is expected. Run this as a database administrator, substituting the user and host shown
on the Tools tab:

```sql
GRANT SELECT ON performance_schema.* TO 'youruser'@'localhost';
FLUSH PRIVILEGES;
```

This is **read-only**. It grants no ability to change any data, and it is the only permission
the plugin asks for.

### Step 3: re-check

Go back to the Tools tab and press Re-check. The status should change from "Needs permission"
to "Available".

Note that this gets the permission in place ready; the plugin does not read the digest
statistics yet. The `EXPLAIN` analysis below is what is doing the work today.

### What if my host says no?

You still get the `EXPLAIN` analysis, which is live today and covers the most common problem
by far: a query with no usable index. The difference is that `EXPLAIN` gives the optimiser's
*estimate* of how many rows will be read, and `performance_schema` gives what actually
happened.

One real limit worth knowing: `EXPLAIN` only runs on queries whose full SQL was kept, and to
protect your data the plugin only keeps the full text of the slowest and largest queries per
page - everything else is stored as a fingerprint with all values stripped. So query plans
cover the queries most worth explaining, not every query on the page.

---

## 2. `excimer` - which function is slow

A sampling profiler maintained by the Wikimedia Foundation and used in production on
Wikipedia. Overhead is negligible, which is why this is the one we recommend first.

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

Create a dedicated ini file rather than editing the main `php.ini`, so a PHP upgrade does not
wipe it. The Tools tab shows the exact directory for your server. Typically:

```bash
echo "extension=excimer.so" | sudo tee /etc/php/8.3/fpm/conf.d/20-excimer.ini
echo "extension=excimer.so" | sudo tee /etc/php/8.3/cli/conf.d/20-excimer.ini
```

### Restart PHP

The correct command depends on your init system - the Tools tab shows the right one for your
server (systemd, OpenRC on Alpine, or Homebrew on macOS). On a typical systemd host:

```bash
sudo systemctl restart php8.3-fpm     # or php-fpm, depending on your distribution
```

### Verify

```bash
php -m | grep excimer
```

Then press Re-check on the Tools tab. Note that `php -m` on the command line and the extension
list seen by your website can differ: PHP-FPM and the CLI often have separate ini directories,
which is why the commands above write to both.

---

## 3. `tideways_xhprof` - exact call counts

Use this when you need to prove a plugin is running the same query in a loop. It counts every
call exactly, where `excimer` samples.

Licence: Apache 2.0, free and open source. This is the free extension published by Tideways.
It is **not** the paid Tideways service and it sends nothing to them.

### Install

```bash
sudo pecl install tideways_xhprof
echo "extension=tideways_xhprof.so" | sudo tee /etc/php/8.3/fpm/conf.d/20-tideways_xhprof.ini
sudo systemctl restart php8.3-fpm
```

### Important: it is slower on purpose

Tracing every single function call is expensive and it distorts timings. When support for it
is built, it will be enabled only for a single page you explicitly ask about, and never during
a Deep Analysis measured-impact run, because it would corrupt the very numbers those runs
exist to produce.

If you only install one of the two extensions, install `excimer`.

---

## 4. `spx` - a standalone profiler with its own interface

If you want flamegraphs and a timeline view, [php-spx](https://github.com/NoiseByNorthwest/php-spx)
is excellent, self-hosted, and keeps all data on your own server. Licence: GPL-3.

Super Speedy Performance Analysis **detects** it, but does not read its data: its own
interface is already better than anything we would rebuild.

---

## Frequently asked

**Will any of this slow down my site?**
Simply having `excimer` loaded, or granting the MySQL permission, costs you nothing measurable.
`tideways_xhprof` does add real overhead while it is actively profiling, which is why it will
only ever be enabled for a single page you explicitly ask about, and never during a
measurement run.

**Does any of this send data to Super Speedy Plugins, or anyone else?**
No. These are local extensions and a local database table. Nothing leaves your server. If you
separately opt in to sharing anonymised results on the Share tab, you see the exact payload
first, as always.

**Should I install these now?**
Only if you want to be ready, or if getting a host to act takes you a while. `excimer` and
`tideways_xhprof` are detected but not yet read by the plugin. The `performance_schema` GRANT
is worth requesting early, because host tickets are slow.

**I installed the extension and the Tools tab still says Not installed.**
Almost always PHP-FPM was not restarted, or the extension was enabled for the CLI but not for
FPM. Check both ini directories, then restart PHP rather than reloading it.

**My host will not install extensions.**
That is common and it is not a fault in your setup. You still get the full analysis, plugin
attribution and Deep Analysis measured impact, which is where the answer usually is. The
extensions add function-level detail on top.

**Is the `GRANT` safe?**
Yes. `SELECT` on `performance_schema` is read-only access to performance statistics. It grants
nothing over your site's data and cannot modify anything.

## See also

- [Methodology](methodology.md) - how the measurements are made
- [Understanding results](understanding-results.md) - reading the attribution and measured
  impact figures
- [Getting started](getting-started.md)
