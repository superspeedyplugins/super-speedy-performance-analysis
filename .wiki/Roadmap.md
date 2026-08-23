# Roadmap

Where this is going. Nothing here is a commitment or a date, and the order changes when real sites turn up problems that matter more.

If you want something on this list, or want something moved up it, say so in [Discussions](https://github.com/superspeedyplugins/super-speedy-performance-analysis/discussions). That's the most useful thing you can do with this page. What people actually ask for moves faster than what looks tidy on a plan.

## In progress

**Traffic Performance Analysis.** The collector ships and is marked experimental: bounded collection windows, per-request events, cohorts, automation classification, WooCommerce funnel events where a shop is present, and a before-and-after comparison of two collections. It never transmits anything off the site.

The analysis half is partly built. Two opportunity headlines compute gross avoidable origin work. What's missing is the part that says what caching is *worth* on your traffic rather than what it could avoid, and progressive aggregation so report cost stops growing with collection length.

**Bot and AI crawler evidence.** Claimed search, shopping and generic crawlers are classified, and the generic bucket absorbs GPTBot, ClaudeBot, PerplexityBot, CCBot and Bytespider. Separating AI crawlers into their own class, and tying referrals to them, isn't built.

**Function-level profiling** has one phase left.

## Designed, not built

**Slow query log import.** Accept a pasted or uploaded `pt-query-digest` output from sites that already run the slow log. Parsing only, nothing to install. Low priority, because the `performance_schema` work covers most of its value already: see [[Server-Capabilities]].

**Exact call counts.** The Tools tab detects `tideways_xhprof` and says honestly that the plugin doesn't read it. A tracing deep-dive mode is planned, but sampling answers most questions without the overhead, so it sits behind things people ask for more.

## Wanted, and open to input

These are the ones where your opinion changes the outcome.

- **Non-WooCommerce adapters for traffic analysis.** The collector works on any site; the funnel events are WooCommerce-shaped. What would the equivalent be for a membership site, a jobs board, a publisher?
- **Browser-side evidence** to sit alongside the server-side measurement. Worth having, but only if it stays honest about what it can and can't attribute.
- **Cloudflare companion data.** Currently stubbed and reported as unavailable rather than guessed at.

## Not planned

**A per-request query inspector in the admin bar.** That's Query Monitor's job, it does it well, and the page panel already gives you the same data when you ask for it. Putting it on every page load would make the plugin expensive on every page load.

**Anything that installs software on your server.** The Tools tab writes the exact commands for your system and you or your host run them. Almost every deep PHP profiler is a compiled extension needing root, which is why the tab exists in that shape.

## How this list gets decided

Real measurements from real sites, mostly. If [[The-Community-Plugin-Database]] shows a pattern costing lots of sites time, that beats a feature that sounds good.

---

Related: [[Contributing-and-Reporting-a-Problem]] · [[The-Community-Plugin-Database]]
