# Troubleshooting

Symptom, cause, fix.

## "Not measured, cache served it"

**Cause:** something answered the request before PHP ran. Every profiling request carries a signed cache-busting argument, so this usually means an edge cache or CDN rule ignoring query strings.

**Fix:** exclude the profiling requests at the edge, or use browser-driven transport, which the plugin falls back to when it detects the server can't fetch its own pages. Cache-served responses are discarded rather than reported as fast pages, so a run with this warning has fewer measurements, not wrong ones.

## The analysis can't reach your pages at all

**Cause:** loopback requests blocked. Common culprits are a security plugin, Basic Auth on a staging site, a WAF, or a host that blocks a server calling its own hostname.

**Fix:** the plugin detects security plugins blocking loopbacks and names exactly what to whitelist, and the run continues rather than failing. For Basic Auth, a WAF or a CDN, browser-driven transport profiles through your own browser instead. Baseline, spot and ad-hoc runs support it; Plugin Impact Analysis and cache analysis stay loopback-only.

## "An analysis is already running" and nothing is running

**Cause:** a run that died without releasing its claim, usually a PHP timeout or a fatal mid-run. The claim is held for six hours.

**Fix:** cancel the run from the Overview tab. If there's nothing to cancel, the claim clears itself when it expires.

## The Health box says the mu-plugin loader isn't installed

**Cause:** either `wp-content/mu-plugins` genuinely isn't writable, or the site defines `DISALLOW_FILE_MODS`, which forbids plugins from writing files.

**Fix:** the Health box distinguishes the three cases and says which one applies, including "out of date, it will be refreshed the next time an analysis runs". Only the not-writable case needs a permissions change.

## Another plugin owns db.php

**Cause:** Query Monitor, or another profiler, installed its own drop-in. Ours doesn't overwrite it.

**Fix:** the plugin says whose drop-in is in place and offers a temporary swap for the duration of a run. Plugin Impact Analysis requires our drop-in, because the database guard that refuses destructive statements during a measurement lives in it.

## No function-level breakdown

**Cause:** the excimer extension isn't installed, or the run predates installing it.

**Fix:** [[Server-Capabilities]]. The panels distinguish an install requirement from a run that simply needs repeating, and the Tools tab writes the commands for your exact server.

## No rows-examined data

**Cause:** MySQL `performance_schema` is off, or the database user can't read it.

**Fix:** the Tools tab writes out the one read-only `GRANT` needed, with your real database user in it. Without it the analysis runs the same and that single finding doesn't appear.

## The shop pages profile as empty

**Cause:** WooCommerce ships with coming-soon mode on, which hides the store from logged-out visitors. Front-end pages are profiled as a logged-out visitor, so the profiler sees what a visitor sees: nothing.

**Fix:** turn off `woocommerce_coming_soon` and `woocommerce_store_pages_only`, or expect empty shop measurements until you launch.

## The checkout flow won't complete

**Cause:** no payment method that can complete without a live gateway redirect, guest checkout disabled in a way the flow can't satisfy, or the run is being blocked before the payment step.

**Fix:** run `wp sspa checkout-flow --dry-run` and read the pre-flight, which lists exactly what the run will trigger and what it needs. Blocked or partial management sequences are reported as blocked rather than silently reporting a shorter time.

## A measurement seems to have changed my site

It can't, and if you have evidence otherwise that's a bug worth reporting. Suspects are disabled for the analysis's own test requests only, activation and deactivation routines are silenced, and destructive database statements are refused during a measurement. A plugin that reacts to another being excluded is caught, reported as a finding, and measured together with it from the next run on. See [[Plugin-Impact-Analysis]].

## Still stuck

Download the privacy-safe Markdown report for the page or run in question and attach it. It strips SQL literals, query values and identifiers. See [[Contributing-and-Reporting-a-Problem]].

---

Related: [[Installation-and-Requirements]] · [[Server-Capabilities]] · [[How-It-Measures]]
