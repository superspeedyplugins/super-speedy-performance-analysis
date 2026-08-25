---
name: wordpress-performance-analysis
description: Analyse a WordPress/WooCommerce site's performance using the free Super Speedy Performance Analysis plugin - profile key pages, attribute SQL/RAM/time to individual plugins, isolate culprits by virtually disabling plugins for test requests only, and explain the findings in plain English. Use when someone asks why their WordPress site is slow, which plugin is slowing it down, or wants a performance audit.
---

# WordPress performance analysis

You are driving Super Speedy Performance Analysis - a free diagnostic plugin
(https://www.superspeedyplugins.com/). It profiles the
site's key pages via signed loopback requests, attributes SQL time / row counts / RAM /
query counts to individual plugins and the theme, and can PROVE a plugin's cost by
re-measuring with it virtually disabled (test requests only - visitors are never affected,
nothing is ever really deactivated).

## 1. Install (skip if already active)

With wp-cli access:

```bash
wp plugin install https://github.com/superspeedyplugins/super-speedy-performance-analysis/releases/latest/download/super-speedy-performance-analysis.zip --activate
```

Or download that zip and upload via Plugins -> Add New. Verify: `wp plugin is-active
super-speedy-performance-analysis`.

## 2. Run the analysis

Preferred (wp-cli - synchronous, shows progress):

```bash
wp sspa run            # full baseline: every key page, read-only, non-destructive
wp sspa report         # full JSON report of the latest run
```

Via MCP/Abilities (when connected to the site's MCP server or the core abilities REST
controller): call `run-analysis` (async), poll `get-status` until `active` is false, then
`get-report`. Readonly abilities also answer plain GETs at
`/wp-json/wp-abilities/v1/abilities/super-speedy-performance/<name>/run`.

The first run is read-only: it sends roughly four GET requests per page profiled and
changes nothing. Expect 1-5 minutes on a typical site.

## 3. Interpret the report

The report JSON (`wp sspa report`, or the `get-report` ability - schema in
`.kb/agent-api.md`) contains:

- `score` - 0-100; below 80 means real findings exist.
- `insights` - the top findings, each with a plain-English `headline`, `evidence` and an
  explicit `recommendation` object. Lead your summary with these.
- `pages` - per-page medians: `generation_ms`, `sql_ms`, `sql_count`, `rows_fetched`,
  `peak_mem_bytes`. Pages over ~500ms generation deserve attention; `rows_fetched` in the
  thousands explains high RAM.
- `findings` - the full list. Key types: `slow_query` (with a `shape` - postmeta scans,
  ORDER BY rand() etc), `big_result_set` (RAM culprits), `query_loop` (N+1 - grows with
  content), `dupe_queries`, `slow_http` (blocking remote calls), `cache_blind` (ignores
  Redis), `blocking_mail`, `security_block` (profiling was blocked - relay the whitelist
  advice).
- `impacts` - only present after Plugin Impact Analysis: PROVEN per-plugin costs with a noise floor.

When summarising for the user: name the responsible plugin, quote the measured numbers,
and include the recommendation. Do not soften "confidence: measured" findings - they were
proven by re-measurement. Treat `confidence: inferred` as attribution, not proof, and
offer Plugin Impact Analysis to confirm.

## 4. Offer Plugin Impact Analysis (culprit isolation)

If findings name suspect plugins, offer to prove their real cost:

```bash
wp sspa run --type=deep                       # all suspects + bisection of the slowest page
wp sspa run --type=deep --suspects=some-slug  # one plugin only
wp sspa impacts                               # the measured results
```

Explain honestly: suspect plugins are disabled FOR THE PLUGIN'S OWN TEST REQUESTS ONLY via
a per-request filter; real visitors always get the full site; a few dozen extra requests
hit the site while it runs.

If the site has Redis/Memcached, `wp sspa run --type=cache_impact` additionally reveals
which plugins ignore the object cache.

## 5. Cache optimisation and experimental traffic evidence

The normal analysis immediately produces a cache optimisation analysis. It assesses hazards
and likely implementation difficulty without running a traffic collector:

```bash
wp sspa cache-optimisation-report --format=json
```

The `get-cache-optimisation-analysis` ability returns the same versioned document. The older
`wp sspa cache-scan` and `get-cache-safety-report` names remain compatibility aliases.

On WooCommerce sites, an owner may explicitly start the experimental lightweight traffic
collector for 1, 2, 4, 24 or 72 hours, or 7 days:

```bash
wp sspa traffic start --duration=24h
wp sspa traffic status --format=json
wp sspa traffic observations
wp sspa traffic compare <before-collection-id> <after-collection-id>
wp sspa traffic stop
```

The matching abilities are `start-traffic-collection`, `get-traffic-collection-status`,
`get-traffic-observations`, `compare-traffic-collections` and `stop-traffic-collection`. Explain that this writes anonymous
keyed event rows, observes logged-in and non-empty-basket origin requests exactly, samples broad
anonymous origin traffic, and cannot see requests served entirely at the CDN/page-cache edge.
The `sspa/traffic-collector-observations@1` output is experimental evidence, not the finished
Traffic Performance Analysis. A normal stop ends request collection and keeps the bounded order
outcome window; `emergency: true` removes the observer immediately.

The observations include comparable actor-state by surface by page-class performance groups,
origin generation average/p95 and SSF/cache-fragment opportunity headlines. The comparison
normalises request volumes and processing totals to 24 hours and never labels traffic not
identified as automation as human.

Raw deletion is deliberately not an MCP ability. After downloading the observations, the owner
can use the Traffic tab or `wp sspa traffic delete <collection-id> --yes`.

## 6. WooCommerce: measure the checkout the customer actually experiences

Everything above profiles pages as GETs, which for the cart and checkout means an EMPTY
cart. On a block checkout the real cost is in POSTs a crawler never sends. The checkout flow
measures one complete purchase instead, step by step, and splits the result at the payment
boundary: time the customer can still abandon during, versus time after the sale is secured.

**This buys something for real.** A real order is created, real order emails are sent and
real integrations fire. The order is cancelled and deleted afterwards and stock is restored,
but you must show the owner what it will set off and get their agreement first:

```bash
wp sspa checkout-flow --dry-run   # the pre-flight inventory - creates nothing
wp sspa checkout-flow             # one measured purchase
```

Via MCP/Abilities: call `run-checkout-flow` with `dry_run: true`, relay the inventory to the
owner **and wait for them to agree**, then call it again without `dry_run`, poll `get-status`,
and read `get-checkout-flow`. That ability is marked destructive; treat it as one.

The pre-flight names the order emails and their recipients, whether emails are deferred or
sent inside the request, active webhooks by host, and every plugin that will run code when
the order is created. Relay all of it - a plugin that emails a supplier or a dropshipper on a
new order really will email them.

Reading the result: `at_risk_ms` is the half that costs sales, `secured_ms` is the half that
only costs goodwill. `slowest` names the worst step. New finding types are
`checkout_slow_step`, `checkout_component_cost`, `checkout_blocking_http` (the classic cause
of a six-second checkout), `checkout_mail_inline` and `checkout_dupe_queries`. In the default
`no_payment` mode the payment provider's own latency is NOT included - say so rather than
implying the total is everything the customer waits for.

Both the block checkout and the classic `[woocommerce_checkout]` shortcode are supported; the
step list differs slightly between them (the classic one has no separate cart-API or
rate-selection call and no draft order). A checkout page that is neither - one rendered by a
page builder - reports `unsupported_checkout` rather than guessing at the payload.

## 7. Cautions

- Do not enable community sharing yourself - only the site owner may opt in (Share tab).
- If pages report `blocked_by`, a security plugin blocked the loopbacks: relay the
  whitelisting advice from the finding, then re-run.
- On measurement noise: deltas below the reported `noise_floor_ms` are reported as "no
  measurable impact" - trust that, do not re-interpret tiny deltas as findings.
