# Quick Start Guide

Super Speedy Performance Analysis finds out what is actually slowing your WordPress or WooCommerce site down - and names the plugin responsible, with evidence.

## Install

1. Download the latest zip from https://github.com/superspeedyplugins/super-speedy-performance-analysis/releases/latest
2. Plugins -> Add New -> Upload Plugin -> activate.
3. Go to **Super Speedy -> Performance Analysis**.

On activation the plugin installs its conditional `db.php` drop-in only if that path is unoccupied. Open the existing Tools tab and click **Enable profiler** to install the mu-plugin loader before starting analysis; **Disable profiler** removes it. Opening the screen or starting a run never recreates a missing loader. The profiling helpers operate on signed profiling requests. Separately, an explicitly started traffic or AJAX collection window observes matching real requests on the site. The Overview tab's Health box shows their status.

## Your first analysis

Click **Run Analysis**. The plugin visits your key pages roughly four times each: home, shop, product pages, archives, search, checkout, and the wp-admin screens you use daily. It measures page generation time, SQL time, query counts, rows fetched and peak RAM, and attributes every query to the plugin or theme that ran it.

The first run is read-only and non-destructive. It creates no content, sends no emails, and changes nothing about your site. It does consume server resources while it runs, so on a busy production site pick a quieter moment.

When it finishes you get a site score and the Top Insights - plain-English findings like "plugin-x ran 70 queries on home" - each with a recommendation. [Understanding Your Results](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/understanding-your-results/) explains what each finding type means and how to read the score.

## What next

- **Pages tab**: click any row for a per-plugin breakdown and the slowest queries.
- **Plugins tab**: per-plugin totals, plus a **Measure** button per plugin - a targeted sweep proving that one plugin's real cost (or saving) on every page.
- **Run Plugin Impact Analysis**: the one-button sweep, in two phases. Phase 1 quickly screens every eligible plugin on its busiest pages; phase 2 automatically gives only the plugins that showed a measurable impact the full treatment - every page, plus object-cache-disabled and cache-priming measurements when you have Redis/Memcached. Start it, walk away, and the floating monitor shows where it is up to and roughly how long the current phase has left whenever you come back.
- **Function-level detail**: install the free `excimer` extension and every profile gains a by-function breakdown. See [Installing the Optional Profiling Extras](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/setup-server/installing-profiling-extensions/) and [Function-Level Profiling](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/features/function-level-profiling/).
- **Compare tab** (formerly History): compare saved page measurements, inspect the setup used for each measurement, and, when you choose to load them, see how similar sites measure on superspeedy.org.
- **AJAX tab**: record a Before window while performing a real workflow, stop it, change the endpoint selection in Scalability Pro, and repeat the same scenario in an After window. Compare equivalent requests and export the chart for review. Recording does not enable optimisation or replay requests.

## AJAX timing and plugin activity

Leave **Sample plugin activity** off for ordinary timing comparisons. Enable it when investigating what plugins do during sampled requests; it adds overhead and offers partial coverage. Hook registrations and executed callbacks are different evidence, and a missing observation does not prove a plugin is unnecessary.

AJAX windows stop after 15 minutes or 200 observations. Their data stays on your site and is not included in profiling-run submissions. Check returned data and workflow correctness alongside the measured server time.

## Things that trip people up

- **A speed plugin showing lots of SQL is not necessarily slow.** Attribution credits queries to whoever runs them, so a plugin that replaces a slow feature (search, filters) shows the work it does *instead of* the slower native code. Use its Measure button - "saves Xms" (green) means it is making your site faster.
- **Pages/Plugins tabs always show your last full analysis** (or spot check). Deep and Cache Impact runs add their results to the Plugins tab's "Measured impact" and "Object cache" columns; they do not replace the page profiles.
- **Progress lives in the floating monitor** (bottom right), visible on every tab and minimisable. It resumes automatically after a reload or when you come back later, and shows the plugin/page/cache-mode being tested plus elapsed time and time remaining. Keeping the browser tab open (minimised is fine) drives the run fastest; with it closed, WP-Cron continues the run in the background as your site receives traffic.
- **A run stuck mid-way** cleans itself up: staleness is judged by progress, so a wedged run is re-kicked after 30 minutes and only failed after 3 hours without any progress - an hours-long plugin impact run is never killed just for being long. You can also click Cancel at any time. Analysis never replaces another plugin's `db.php` or renames `object-cache.php`; recovery remains available for files held by versions before 0.43.4.

## Further reading

- [Understanding Your Results](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/understanding-your-results/) - what each finding type means and how the score is worked out
- [Installing the Optional Profiling Extras](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/setup-server/installing-profiling-extensions/) - add function-level detail and real rows-examined figures
- [Security Plugins and Profiling Requests](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/troubleshooting/security-whitelisting/) - what to do when a firewall blocks the profiling requests

### Database drop-in ownership

SSPA installs its database drop-in only when no other drop-in exists, honours `DISALLOW_FILE_MODS`, and removes only its own file. When another plugin owns `wp-content/db.php`, ordinary analysis uses degraded capture, or Query Monitor's query log when available; per-query row counts may be unavailable. The existing file is never replaced, including a leftover Query Monitor drop-in after deactivation. Remove a leftover file manually if you want SSPA to install its own.

Cache Impact Analysis requires SSPA's own drop-in so it can disable the object cache for the signed profiling request alone. If another plugin owns the database drop-in, the analysis returns an error naming that owner. It never renames the site's object-cache drop-in. Plugin Impact Analysis also requires SSPA's own drop-in for its destructive-query guard.
