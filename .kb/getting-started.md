# Getting started with Super Speedy Performance Analysis

Super Speedy Performance Analysis finds out what is actually slowing your WordPress or
WooCommerce site down - and names the plugin responsible, with evidence.

## Install

1. Download the latest zip from
   https://www.superspeedyplugins.com/assets/plugins/super-speedy-performance-analysis.zip
2. Plugins -> Add New -> Upload Plugin -> activate.
3. Go to **Super Speedy -> Performance Analysis**.

On activation the plugin installs two small helpers: an mu-plugin loader and a
conditional `db.php` drop-in. Both are completely inert for normal traffic - they only
wake up for the plugin's own signed profiling requests - and both are removed
automatically when you deactivate the plugin. The Overview tab's Health box shows their
status.

## Your first analysis

Click **Run Analysis**. The plugin visits your key pages (home, shop, product pages,
archives, search, checkout, the wp-admin screens you use daily) roughly four times each,
measuring page generation time, SQL time, query counts, rows fetched and peak RAM - and
attributing every query to the plugin or theme that ran it.

The first run is read-only and non-destructive. It creates no content, sends no emails,
and changes nothing about your site. It does consume server resources while it runs, so
on a busy production site pick a quieter moment.

When it finishes you get a site score and the Top Insights - plain-English findings like
"plugin-x ran 70 queries on home" - each with a recommendation.

## What next

- **Pages tab**: click any row for a per-plugin breakdown and the slowest queries.
- **Plugins tab**: per-plugin totals, plus a **Measure** button per plugin - a targeted
  sweep proving that one plugin's real cost (or saving) on every page.
- **Run Deep Analysis**: the one-button sweep, in two phases. Phase 1 quickly screens
  every eligible plugin on its busiest pages; phase 2 automatically gives only the
  plugins that showed a measurable impact the full treatment - every page, plus
  object-cache-disabled and cache-priming measurements when you have Redis/Memcached.
  Start it, walk away, and the floating monitor shows where it is up to and roughly how
  long the current phase has left whenever you come back.
- **History tab**: re-run monthly - if your site is getting slower as it grows, this is
  where you see it.

## Things that trip people up

- **A speed plugin showing lots of SQL is not necessarily slow.** Attribution credits
  queries to whoever runs them, so a plugin that replaces a slow feature (search,
  filters) shows the work it does *instead of* the slower native code. Use its Measure
  button - "saves Xms" (green) means it is making your site faster.
- **Pages/Plugins tabs always show your last full analysis** (or spot check). Deep and
  Cache Impact runs add their results to the Plugins tab's "Measured impact" and
  "Object cache" columns; they do not replace the page profiles.
- **Progress lives in the floating monitor** (bottom right), visible on every tab and
  minimisable. It resumes automatically after a reload or when you come back later, and
  shows the plugin/page/cache-mode being tested plus elapsed time and time remaining.
  Keeping the browser tab open (minimised is fine) drives the run fastest; with it
  closed, WP-Cron continues the run in the background as your site receives traffic.
- **A run stuck mid-way** cleans itself up: staleness is judged by progress, so a
  wedged run is re-kicked after 30 minutes and only failed after 3 hours without any
  progress - an hours-long deep sweep is never killed just for being long. Any held
  db.php/object-cache.php drop-in is restored on finish, cancel or failure. You can
  also click Cancel at any time - restoration is immediate.
