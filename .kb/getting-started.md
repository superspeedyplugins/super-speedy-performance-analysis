# Getting started with Super Speedy Performance Analysis

Super Speedy Performance Analysis finds out what is actually slowing your WordPress or
WooCommerce site down - and names the plugin responsible, with evidence.

## Install

1. Download the latest release zip from
   https://github.com/superspeedyplugins/super-speedy-performance-analysis/releases
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
- **Run Deep Analysis**: proves each suspect plugin's true cost. See the deep analysis
  article.
- **History tab**: re-run monthly - if your site is getting slower as it grows, this is
  where you see it.
