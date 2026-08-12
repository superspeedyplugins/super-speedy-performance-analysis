# Planned work

Verified against the code on 2026-08-12 at 0.18.0, not taken from doc headers. Ordered within
each group by closeness to done.

## Partly built

### The superspeedy.org receiver and public site
Source: `.docs/superspeedy-org-site-cohorts-and-commerce-flows.md` (status: client COMPLETE in
0.18.0, receiver outstanding), `.docs/performance-analysis-submission-implementation.md`,
`.docs/brainstorm-superspeedy-org-companion.md`.

**This is by far the closest thing to done, and by far the biggest gap between what the plugin
can do and what a user gets.**

Built and verified end to end (2026-08-08): registration, reserve, direct R2 upload, complete,
receipt verification, outbox marking. A real submission was archived and processed; the
receiver's own live R2 round-trip and Go test suite passed. Schema 1.1 partials were correctly
flagged for a future processor.

Outstanding, all on the superspeedy.org side:

- **Receiver support for payload schema 1.2** - `sspa/site-snapshot@2` (cohort classification and
  the extended size bands) and `sspa/order-management-flow@1`. Purely additive; the receiver
  should accept 1.2 **before the first 0.18.0 payload arrives**, which given 0.18.0 is already
  released to customers means this is overdue rather than upcoming.
- **Any public page at all.** No rankings, no per-plugin performance profiles, no category
  comparisons, no sector benchmarks, no methodology page, no right of reply. Users opting in
  today are contributing to something with no visible output.
- Phase 7 hub items from `.docs/2026-08-05-implementation-plan-future-work.md`: fast-ajax mu
  acceleration for hot routes, charts, the LLM classifier plus review queue, anti-abuse
  reputation.

<!-- internal -->
The bundled WordPress hub plugin in `hub/` was DELETED (commit d1e0b1d). The server side is a
separate Go receiver plus R2 and lives in the superspeedy.org repository, not here. Any doc or
copy referring to `hub/` or to `super-speedy-performance-hub` as a thing in this repo is stale.

### Function-level profiling (phase 6 remains)
Source: `.docs/implementation-plan-profilers-and-digests.md` section B and the phasing table.
The doc header still says "planned, not started" - **that is wrong**; phases 1-5 are built.

- **Phases 1-4** shipped in 0.9.2 (attribution fix, `EXPLAIN`, Tools tab, `performance_schema`
  digests).
- **Phase 5 - Excimer** shipped in 0.10.0 and was extended through 0.10.9 (self-time ordering,
  driven-by splits, per-phase function lists, the Tools tab leading with it, a KB guide).
- **Phase 6 - XHProf-family collector.** Not started. Exact call counts, which is what *proves* a
  plugin runs the same query in a loop rather than inferring it. Higher overhead, so one page at
  a time. Loses stack context, which is why it does not replace Excimer. The Tools tab detects
  `tideways_xhprof`/`xhprof` and generates install steps today with `'used' => false`, so the gap
  is visible in the UI.

### Checkout flow: the two unbuilt tasks
Source: `.docs/2026-08-07-checkout-flow-profiling.md` (status: phase 1 in 0.11.0, phase 2 in
0.11.1).

- **T15 - sandbox gateway adapters.** `SSPA_Checkout_Flow::PAYMENT_MODES` is deliberately empty
  until an adapter exists, so only `no_payment` works. Every gateway's own time is therefore
  outside the measurement - on a real store the gateway call is often the slowest single step.
- **T13 - per-plugin checkout isolation.** Plugin Impact Analysis does not run over checkout
  steps, so checkout findings are attributed rather than measured. Given measured impact is the
  plugin's whole differentiator, checkout being attribution-only is a notable inconsistency.

### Phase 7 launch polish
Source: `.docs/2026-08-05-implementation-plan-future-work.md`.

Public repo polish: README with screenshots, CONTRIBUTING (for rules-data PRs), issue templates.
The licence item is done (GPLv3, 2026-08-06).

## Designed, not built

### Slow query log import (A2)
Source: `.docs/implementation-plan-profilers-and-digests.md` section A2. Accept a pasted or
uploaded `pt-query-digest` output where the user already has the slow log on. Parsing only,
nothing to install on our side. The doc marks it low priority and the `performance_schema` work
in 0.9.2 covers most of its value.

## Research only

### Third-party perf tool integration
Source: `.docs/third-party-perf-tool-integration.md` (status: research only, nothing built).
Install and configure third-party tools from inside the plugin, earn affiliate revenue on paid
services, surface their data in the UI, and eventually submit reports to plugin and theme
authors. The governing finding - almost every deep PHP profiler is a compiled extension needing
root - is what produced the Tools tab instead.

## Parked

From `.docs/2026-08-05-implementation-plan-future-work.md`, explicitly parked: SMTP sink
full-send mode, the deliverability bucket (synthetic only), sector benchmark pages, the wp.org
listing decision, and multisite.

## Verification work

### Cross-check timings against real Query Monitor
Source: `.todo/todo.md` and the unticked item in the archived `.docs/archive/implementation-plan.md`
Phase 1. Still unticked.

Profile the same pages with this plugin's profiler and with Query Monitor's actual `QM_DB`
drop-in installed from wp.org, then compare SQL count, SQL time, row totals and generation time.
Confirms the numbers are trustworthy and quantifies profiling overhead versus QM's.

**The product's whole claim is that its numbers are right, and this is the test that proves it.**
It has been outstanding since Phase 1 and the plugin is now released to customers at 0.18.0.

### Prove the attribution work on a site that has the problem
The shared-library attribution fix, `EXPLAIN` findings and `performance_schema` digests were
proven on the docker harness and Dave's local install - all small, clean and two-plugin-ish. A
plain WooCommerce site produced **zero** cross-component chains even at 32-frame backtraces, so
the case the work exists for barely occurs there. The evidence that it matters has to come from a
site that actually has the problem.

## Open questions for Dave

1. **Does the Caller attribution mode stay?** Added 0.9.2 as explicitly exploratory: keep it if
   it helps diagnose real problems on a client site, remove it if not. It has since been made
   in-place (0.12.0) and carried into the unified page panel (0.14.0), which reads as a de facto
   keep. Worth closing the decision so it can be used in customer-facing material.
2. **Should the payload flag replacement-category plugins** (search/filter) so superspeedy.org
   ranks them by measured impact rather than attributed SQL? Otherwise the hub reproduces the
   attribution trap at scale. Source: `.docs/2026-08-05-run-analysis-review-future-work.md`.
3. **Baseline sample count.** 3 samples with a 1-request warm-up is thin for wp-admin pages on
   busy sites. An adaptive scheme (sample until spread stabilises, cap at ~7) would tighten the
   noise gate at modest request cost. Worth it? Same source.

## Delivered since the last compile - no longer planned

- **Phase 5, Excimer function-level profiling** - 0.10.0.
- **Publishing the plugin.** 0.18.0 is released to customers: update JSON, deployed `readme.txt`
  and GitHub Release all at 0.18.0, `download_url` on the authorising endpoint (verified
  2026-08-12). The previous compile listed this as the blocker for everything user-facing.
- **The community submission client** - 0.12.0, verified against the production collector.
- **Option access tracking** - 0.12.0. `.docs/2026-08-09-option-access-tracking.md` still says
  "design, nothing built"; it shipped.
- **Page analysis unification** - 0.14.0 (`.docs/2026-08-10-page-analysis-unification.md`
  sections 1, 2, 3, 5, 6.1; section 5 in 0.13.1). Section 4 (flows) deliberately deferred and
  needs its own doc.
- **The adaptive bisection planner** - built 0.4.0, retired 0.9.0, verified absent from the code.
