# Planned work

Verified against the code on 2026-08-02 at 0.9.2, not taken from doc headers.

## Designed, not built

### Function-level profiling (phases 5-6)
Source: `.docs/implementation-plan-profilers-and-digests.md` section B and the phasing table.

Phases 1-4 of that plan are built and in 0.9.2 (attribution fix, `EXPLAIN`, the Tools tab,
`performance_schema` digests). Phases 5 and 6 are designed in detail and **not started**:

- **Phase 5 - collector interface plus Excimer.** A sampling profiler, Apache 2.0, negligible
  overhead, safe in production, keeps full stacks so it inherits the fixed attribution.
- **Phase 6 - XHProf-family collector.** Exact call counts, which is what proves a plugin runs
  the same query in a loop. Higher overhead, so one page at a time. Loses stack context, which
  is why it does not replace Excimer.

The plan's own target output: *"320ms measured, of which 280ms is in
`WCPF\Query::build_meta_clause()`, called 47 times."* Measured impact stays the headline;
function detail is evidence, not verdict.

The Tools tab already **detects** both extensions and generates install steps for them, but
nothing reads them - grep-verified, `'used' => false` on both. That gap is visible in the UI
today, which is a reason to do this sooner rather than later.

### Function detail in hub submissions and author reports (phase 7)
Source: same doc, phasing table. Depends on phases 1, 5 and 6, and on the hub existing. Not
started.

### Slow query log import (A2)
Source: same doc, section A2. Accept a pasted or uploaded `pt-query-digest` output where the
user already has the slow log on. Parsing only, nothing to install on our side. The doc itself
marks it low priority, and the `performance_schema` work in 0.9.2 covers most of its value.

## Not started

### The superspeedy.org hub site
Source: `.docs/superspeedy-org-hub-launch.md` (status in the doc: "not started"), design in
`.docs/brainstorm-superspeedy-org-companion.md`.

The client half is **built and working** - registration, HMAC-signed submissions, the RSA-signed
rules feed, and a companion hub plugin in `hub/`. What does not exist is the destination: the
superspeedy.org site itself. Until it does, community sharing has nowhere public to go and the
public plugin-performance rankings do not exist.

This is the single biggest gap between what the plugin can do and what a user can benefit from.

### Publishing 0.9.2 itself
Not a doc-sourced item, but it blocks everything user-facing. The plugin now updates from
superspeedyplugins.com rather than GitHub, and **neither the zip nor the update JSON exists on
the site yet**, so no installed site can be offered an update. Dave's stated plan is to test the
whole 0.9.2 body of work on a friendly client site with real performance problems before
publishing anything.

## Verification work

### Test the 0.9.2 work on a real site
The attribution fix, `EXPLAIN` findings and `performance_schema` digests have all been proven on
the docker harness and on Dave's local install, both of which are small, clean and
two-plugin-ish. The shared-library case they were built for barely occurs there: a plain
WooCommerce site produced zero cross-component chains even with 32-frame backtraces. The
evidence that this work matters has to come from a site that actually has the problem.

### Cross-check timings against real Query Monitor
Source: `.todo/todo.md`, and the unticked "real-QM cross-check" item in
`.docs/implementation-plan.md` Phase 1.

Profile the same pages with this plugin's profiler and with Query Monitor's actual `QM_DB`
drop-in installed from wp.org in the docker environment, then compare SQL count, SQL time, row
totals and page generation time. Confirms the numbers are trustworthy and quantifies profiling
overhead versus QM's.

**Worth doing before launch.** The product's whole claim is that its numbers are right; this is
the test that proves it, and it is still unticked.

## Decisions still open

### Whether the Caller attribution mode stays
Added in 0.9.2 as a Plugins-tab view switch, explicitly exploratory: Dave's instruction was to
keep it if it helps diagnose real problems on a client site and remove it if it does not. Until
that call is made it should not appear in any customer-facing material.

## Released and no longer planned

The adaptive bisection planner (`.docs/implementation-plan.md` Phase 3) was built in 0.4.0 and
**retired in 0.9.0**, superseded by the every-plugin-every-page sweep. Verified absent from the
code.
