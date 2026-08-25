# WP-CLI reference

Every command the plugin exposes. JSON output throughout, so these compose into scripts and CI checks.

## Running an analysis

```
wp sspa run [--type=<type>] [--pages=<keys>] [--suspects=<slugs>] [--url=<url>] [--no-cache-modes]
```

Runs synchronously with progress and waits for the result.

| Option | Meaning |
|---|---|
| `--type=` | `baseline` (default, all key pages), `spot`, `deep` (Plugin Impact Analysis) or `cache_impact` |
| `--pages=` | Comma-separated page keys, for example `home,shop`. Works on deep runs, which is how you re-measure one plugin on one page in minutes instead of sweeping everything |
| `--suspects=` | Deep only. Comma-separated plugin slugs to isolate |
| `--url=` | Deep only. Scope the whole sweep to one URL, including one no analysis has profiled |
| `--no-cache-modes` | Deep only. Skip the object-cache-off and priming measurements |

A deep run is allowed up to six hours. Everything else finishes in seconds to minutes.

```
wp sspa run --type=spot
wp sspa run --type=deep --suspects=my-plugin --pages=admin-orders-search
wp sspa run --type=deep --url=https://example.com/shop/?orderby=popularity
```

## Reading the results

```
wp sspa status
wp sspa findings [--format=json]
wp sspa impacts
wp sspa report
```

`status` prints the current or last run. `findings` lists the plain-English findings with the component responsible. `impacts` lists measured per-plugin impact from Plugin Impact Analysis. `report` is the full agent-readable document, and its schema is documented in [`.kb/agent-api.md`](https://github.com/superspeedyplugins/super-speedy-performance-analysis/blob/main/.kb/agent-api.md).

```
wp sspa http-calls [--run-id=<id>] --format=json
wp sspa page-plugin-usage [--run-id=<id>] [--page-key=<key>] --format=json
```

`http-calls` is the privacy-normalised inventory of outbound WordPress HTTP API calls for a run. `page-plugin-usage` is per-page evidence for every active plugin, with query, HTTP, mail, include, hook and asset observations plus a fail-safe unload classification.

## Cache optimisation

```
wp sspa cache-optimisation-report
wp sspa cache-scan
```

The report described in [[Cache-Optimisation-Analysis]]. `cache-scan` is a compatibility alias for the same thing.

## Checkout and order flow

```
wp sspa checkout-flow [--dry-run] [--product=<id>] [--repeats=<n>] [--payment=<mode>]
```

**Read [[Checkout-and-Order-Flow-Analysis]] before running this without `--dry-run`.** It makes a real purchase and sends real email.

| Option | Meaning |
|---|---|
| `--dry-run` | Print the pre-flight and buy nothing |
| `--product=` | Product to buy. Defaults to the cheapest purchasable, in-stock, shippable one |
| `--repeats=` | Run the flow n times, default 1. Every repeat is another real order |
| `--payment=` | `no_payment` (default) or `sandbox`, which needs a supported gateway in test mode |

## Traffic collector

```
wp sspa traffic start|status|stop|observations|delete
wp sspa traffic compare <before-id> <after-id> [--output=<path>]
```

Bounded, time-boxed collection of real visitor traffic. `compare` produces the duration-normalised before-and-after comparison of two retained collections. This never transmits anything off the site.

## Exit behaviour

Commands exit non-zero on failure, so `wp sspa run --type=spot && wp sspa findings --format=json` behaves in a script the way you'd expect.

## A worked check

Run an analysis and fail a build if anything critical was found:

```
wp sspa run --type=spot
wp sspa findings --format=json | jq -e '[.[] | select(.severity=="critical")] | length == 0'
```

---

Related: [[AI-Agents-and-MCP]] · [[Plugin-Impact-Analysis]] · [[Troubleshooting]]
