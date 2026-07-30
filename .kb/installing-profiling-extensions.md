# Installing the optional profiling extras

Super Speedy Performance Analysis works fully on its own. Everything on this page is
**optional** and adds extra depth: which *function* inside a plugin is slow, and how many rows
MySQL really examined rather than how many it returned.

The Tools tab tells you which of these your server already has, and generates the exact
commands for your server, your PHP version and your operating system. Use this page if you
want to understand what you are installing and why before you run anything.

## What you get, and what it costs you

| Extra | What it adds | Needs |
|---|---|---|
| `EXPLAIN` analysis | How MySQL plans each query: missing indexes, filesorts, temp tables | Nothing. Already on |
| MySQL query fingerprints | **Actual** rows examined per query, not estimates | A one-line `GRANT`, sometimes a MySQL restart |
| `excimer` | Which function is burning the time, safe on live sites | A PHP extension |
| `tideways_xhprof` | Exact call counts, for finding N+1 loops | A PHP extension |

Nothing here sends data anywhere. All of it is read locally by the plugin and stays on your
server.

## Can I install these at all?

Open **Super Speedy → Performance Analysis → Tools**. Each card says Available, Not installed,
or Blocked by your hosting.

The short version:

- **Shared hosting**: you can almost certainly enable the MySQL query fingerprints by asking
  support, and almost certainly cannot install a PHP extension. The `EXPLAIN` analysis works
  already and needs nothing.
- **VPS, managed WordPress hosting with SSH, or your own server**: all of it is available.

If a card says Blocked by your hosting, use the **Send these steps to my host** button. It
writes a short, polite, specific message you can paste into a support ticket. Hosts turn these
down far more often when the request is vague.

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

### Step 3: re-run an analysis

Go back to the Tools tab and press Re-check. The card should turn green, and your next
analysis will include real `rows examined` figures per query.

### What if my host says no?

You still get the `EXPLAIN` analysis, which covers the most common problem by far: a query
with no usable index. The difference is that `EXPLAIN` gives the optimiser's *estimate* and
`performance_schema` gives what actually happened.

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

Tracing every single function call is expensive and it distorts timings. Because of that:

- The plugin only switches it on for the specific page you press **Deep dive** on.
- The plugin **refuses** to switch it on during a Deep Analysis measured-impact run, because
  it would corrupt the very numbers those runs exist to produce.

If you only install one of the two extensions, install `excimer`.

---

## 4. `spx` - a standalone profiler with its own interface

If you want flamegraphs and a timeline view, [php-spx](https://github.com/NoiseByNorthwest/php-spx)
is excellent, self-hosted, and keeps all data on your own server. Licence: GPL-3.

Super Speedy Performance Analysis **detects** it and links to it, but does not read its data,
because its own interface is already better than anything we would rebuild.

---

## Frequently asked

**Will any of this slow down my site?**
`excimer` and the MySQL fingerprints, no. `tideways_xhprof` does add real overhead, which is
why the plugin only enables it for a single page you explicitly ask about, and never during a
measurement run.

**Does any of this send data to Super Speedy Plugins, or anyone else?**
No. These are local extensions and a local database table. Nothing leaves your server. If you
separately opt in to sharing anonymised results on the Share tab, you see the exact payload
first, as always.

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
