# Super Speedy Performance Analysis

Free performance analysis for WordPress and WooCommerce. Finds which plugins are costing you
SQL time, RAM and page speed, with evidence.

**[Download the plugin zip](https://github.com/superspeedyplugins/super-speedy-performance-analysis/releases/latest/download/super-speedy-performance-analysis.zip)**
from the [Releases page](https://github.com/superspeedyplugins/super-speedy-performance-analysis/releases).
Once installed it checks for its own updates, so new versions appear on your Plugins screen
like any other update.

## What it does

Most performance tools tell you your site is slow. This one tells you **who** is making it
slow, and proves it.

- **Profiles your key pages** - home, shop, archives, search, checkout, wp-admin lists and
  editors - with a lean built-in profiler. No Query Monitor required, and zero overhead on
  normal traffic: the capture layer is completely inert unless a request carries a signed
  profiling token.
- **Attributes the cost to individual plugins and your theme**: SQL time, query counts,
  returned rows, HTTP API time and peak RAM per plugin, per page, using shared-library-aware
  call-stack attribution (a plugin is never blamed for a vendor library another plugin
  called into).
- **Plain-English findings**: the slowest queries and who ran them, queries fetching hundreds
  of rows, N+1 query loops, blocking HTTP calls during render, autoload bloat, queries with
  no usable index (via `EXPLAIN`) and more - each with a recommendation.
- **Deep Analysis measures, rather than infers.** Suspects are virtually disabled for the
  plugin's own test requests only - visitors always get the full site, nothing is ever
  really deactivated - and each plugin's true cost is the measured difference. Plugins that
  speed your site up show up as measured savings.
- **"Analyse this page"** in the admin toolbar profiles the exact URL you are looking at and
  shows where the time went in a results panel: request phases, per-plugin costs, slowest
  queries, all expandable in place.
- **Checkout and order analysis never touches a catalogue product.** It reuses one hidden,
  non-stock-managed SSPA product, follows only same-origin redirects and keeps TLS certificate
  verification enabled while measuring the purchase and order-management requests.
- **Function-level profiling** when the free `excimer` PHP extension is present: every
  profile gains a by-function breakdown with self and inclusive time, attributed to its
  plugin or theme - including inside theme template files. The Tools tab generates the exact
  install commands for your server (or a ready-to-paste message for your host).
- **Real database numbers** where available: MySQL `performance_schema` digests reveal rows
  actually examined versus returned, catching hidden full scans no other measurement sees.
  The Tools tab writes out the one read-only `GRANT` needed.

## For AI agents and the command line

The whole plugin is drivable without the GUI:

- **WP-CLI**: `wp sspa run`, `wp sspa status`, `wp sspa findings`, `wp sspa impacts`,
  `wp sspa report`, `wp sspa cache-optimisation-report` and the experimental
  `wp sspa traffic start|status|stop|observations|compare|delete` commands - JSON output throughout.
- **WordPress Abilities API** (WP 6.9+) with the same surface, exposed through the one shared
  Super Speedy MCP bridge without AI Engine or the WordPress MCP Adapter.
- **[SKILL.md](SKILL.md)** teaches Claude-based agents the install -> run -> interpret
  workflow; `docs/agent-instructions-openai.md` is the OpenAI equivalent. The report schema
  is documented in `docs/agent-api.md`.

## Requirements

- WordPress 6.2+ (6.9+ only for the Abilities API surface), PHP 7.4+
- Working loopback requests (the plugin detects security layers that block them and names
  what to whitelist)
- A writable `wp-content/` for the conditional `db.php` drop-in and mu-plugin loader, both
  inert for normal traffic and removed cleanly on uninstall

The History tab includes an opt-in **Delete all SSPA data when the plugin is deleted** switch.
When enabled, uninstall drops the profiling tables and removes SSPA options, transients,
scheduled events, the hidden checkout product and the low-privilege test customer.

## Installation

1. [Download the zip](https://github.com/superspeedyplugins/super-speedy-performance-analysis/releases/latest/download/super-speedy-performance-analysis.zip)
   and upload it via Plugins -> Add New -> Upload, or unzip into `wp-content/plugins/`.
2. Activate it through the Plugins menu.
3. Go to Super Speedy -> Performance Analysis and run your first analysis.

Or install the public release zip directly over SSH with WP-CLI:

```
wp plugin install https://github.com/superspeedyplugins/super-speedy-performance-analysis/releases/latest/download/super-speedy-performance-analysis.zip --force --activate
```

`--force` replaces an existing or incomplete copy. If WP-CLI is deliberately being run as
root, add `--allow-root` to the end of the command.

## Documentation

The knowledge base lives at
[superspeedyplugins.com/kb/super-speedy-performance-analysis/](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/) -
getting started, understanding results (including the attribution trap every profiler has),
methodology, security whitelisting and the function-level profiling guide. The article
sources are in [`.kb/`](.kb/) in this repo.

## Community sharing

Sharing anonymised results is strictly opt-in, and the Share tab shows the exact payload
before anything is sent. Never shared: your domain, URLs, raw SQL, emails or customer data -
your site is a random ID. The public community database this feeds is still being built.

## Licence

[GPLv3](LICENSE). The plugin is free and the download is ungated.
