# Installation and requirements

## Install it

Download the zip from the [latest release](https://github.com/superspeedyplugins/super-speedy-performance-analysis/releases/latest), then Plugins, Add New, Upload Plugin, Activate.

Or over SSH:

```
wp plugin install https://github.com/superspeedyplugins/super-speedy-performance-analysis/releases/latest/download/super-speedy-performance-analysis.zip --force --activate
```

`--force` replaces an existing or incomplete copy. Add `--allow-root` if you're deliberately running WP-CLI as root.

If you already run another Super Speedy plugin, there's an Install Performance Analysis FREE button on the shared Super Speedy dashboard that does the same thing in one click.

## Updates

Free, automatic, and no licence key. New versions appear on your Plugins screen like any other update, and the download comes from the public GitHub release.

A free key exists and does exactly one thing: it lets an AI agent install and configure the rest of the Super Speedy range for you. No feature of this plugin sits behind it.

## Requirements

- WordPress 6.2 or later. The Abilities API surface needs 6.9+.
- PHP 7.4 or later.
- Working loopback requests, so the server can fetch its own pages. The plugin detects security layers that block them and names what to whitelist. See [[Troubleshooting]].
- A writable `wp-content/` for the two helpers below.

Optional, and worth having: the excimer PHP extension for function-level detail, and MySQL `performance_schema` for rows-examined. Neither is required. [[Server-Capabilities]] covers both, including the exact commands for your server.

## What it installs, and why

Two small helpers, both inert for normal traffic:

**An mu-plugin loader** at `wp-content/mu-plugins/sspa-loader.php`. On a request with no profiling token it reads two request values and returns. Nothing else.

**A conditional `db.php` drop-in** at `wp-content/db.php`. Without a valid token it returns before creating `$wpdb`, so WordPress instantiates its own stock class and per-query overhead is zero. With a token it swaps in a profiling subclass that records row counts, errors and backtraces per query.

Both only wake for requests carrying a signed profiling token, both are removed when you deactivate the plugin, and the Overview tab's Health box shows their status. If another plugin already owns `db.php`, Query Monitor being the common case, the plugin says so and offers a temporary swap rather than overwriting it.

## Removing it

Deactivating removes the mu-plugin loader and the drop-in, and stops all scheduled work.

Deleting leaves your stored analyses in place unless you say otherwise. The History tab has an opt-in **Delete all SSPA data when the plugin is deleted** switch. With it on, uninstall drops the profiling tables and removes the plugin's options, transients, scheduled events, the hidden checkout product and the low-privilege test customer.

## Editions

The GitHub release and the copy on superspeedyplugins.com are the same plugin. A separate wordpress.org build omits the shared settings submodule, because wordpress.org doesn't allow a bundled update checker. No feature differs between them.

---

Related: [[Your-First-Analysis]] · [[Server-Capabilities]] · [[Troubleshooting]]
