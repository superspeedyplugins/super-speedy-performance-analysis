# Suggested features

Not in `.docs/`. Nothing here is committed - these are suggestions from the 2026-07-28 audit and
the 2026-08-02 and 2026-08-12 refreshes. Sized S/M/L. Each was checked against `.docs/` and
`planned.md` before being added.

## Still open from earlier audits

### Finish the "work done in X" note (S)
*Added 2026-08-02, re-verified 2026-08-12 - still present.* 0.9.2 captures the component chain
for HTTP calls as well as queries, but `slow_http()` never copies `via` into the finding and
`SSPA_Insights` has no case for it, so the explanation appears on query findings only.
Duplicate-query findings are the same. Small, and it closes a gap between what the changelog
implies and what a user sees.

### Record which data sources a run had (S)
*Added 2026-08-02, re-verified 2026-08-12 - still not stored.* A run's findings depend on what
the server allowed: whether `performance_schema` was readable, whether excimer was present, how
many queries kept full SQL, how often backtraces were truncated. None of that is stamped onto
the run, so a report read later cannot be told apart from one taken on a fully-equipped server.
This matters more now than it did in July, because submissions are archived permanently and
compared across sites - a cohort comparison that cannot tell "no finding" from "no capability"
will draw wrong conclusions at scale.

### Export a findings report (S)
The report JSON is agent-ready but there is no human-shareable artefact. A one-page HTML or PDF
export would let an agency hand the analysis to a client, and would spread the plugin. The panel
is already branded and screenshot-ready, so most of the design work is done.

### "What changed since last run" diff (M)
The History tab tracks score and medians over time, and 0.12.0 added the versions each analysis
measured - but not *what* changed. A per-plugin diff between two runs would answer "we installed
X last week, what did it cost us" directly, which is the question the spot-check prompt already
hints at. The version data added in 0.12.0 makes this cheaper than it was.

### Suggest the Super Speedy plugin that fixes the finding (S, partly there)
*Re-checked 2026-08-12: partly built.* The bundled rules snapshot already names `scalability-pro`
(5 references) and `super-speedy-search` (1). The gap is coverage and consistency, not the
mechanism - slow filters, slow imports and slow prices have no equivalent. This is the natural
commercial bridge from a free diagnostic to the paid range, and it needs care to stay honest
rather than becoming an advert.

## New, 2026-08-12

### Give the checkout flow the measured treatment (M)
*Cross-reference: this is doc task T13 in `.docs/2026-08-07-checkout-flow-profiling.md`, listed in
`planned.md` - noted here only because of how sharply it cuts against the positioning.* Every
other surface in the plugin ends in a measured verdict; checkout ends in attribution. Checkout is
also the page where the money is, so it is the one where "this plugin costs you 340ms" is worth
the most. Worth promoting above phase 6.

### A "what did this cost me" landing number (S)
The plugin measures per-plugin impact per page and knows the site's order volume band. Multiplying
the checkout delta by 30-day orders gives a shop owner a figure in seconds of customer waiting per
month, which is the number that makes someone act. Purely presentational - every input already
exists.

### Re-run the same measurement after a fix, and prove it (M)
The spot-check prompt fires on activation and deactivation, but there is no "I have just changed a
setting, re-measure exactly what you measured before" button. A stored measurement is a
reproducible recipe - plugin, page, cache mode, samples - so re-running it and showing before/after
is mostly plumbing, and it turns the plugin from a diagnostic into something that demonstrates its
own value after a fix.

### Warn when the profiled path and the visitor path have diverged (S)
0.10.8 records whether a capture passed through Cloudflare and whether the country header was
present, and discloses it in the panel. It stops short of a finding. Where the divergence is known
to change behaviour - the MaxMind lookup case the changelog itself cites - that deserves to be a
first-class finding with a recommendation, not a note.

### The 22-second checkout story - BLOCKED, do not propose again
*Do not raise this as a marketing suggestion.* The diagnosis behind the three checkout
misbehaviour signatures came from **Dave's own misconfigured site**, and he will not publish it
on that basis. The unblocking condition is finding the same fault on a client site; at that
point it becomes usable as an anonymised client case. The shipped signatures are fine to
describe as features - it is the origin story that is off-limits.

### Close the loop on the abilities doc (S)
`.docs/agent-api.md` still documents eight abilities; there are ten. It is the published contract
for agent builders, so it is wrong in the one place that is meant to be authoritative.
