# Suggested features

Not in `.docs/`. Nothing here is committed - these are suggestions from the 2026-07-28 audit and
the 2026-08-02 refresh.

### Finish the "work done in X" note (S)
*Added 2026-08-02.* 0.9.2 captures the component chain for HTTP calls as well as queries, but
`slow_http()` never copies `via` into the finding and the renderer has no case for it, so the
explanation appears on query findings only. Duplicate-query findings are the same. Small, and it
closes a gap between what the changelog says and what a user sees.

### Record which data sources a run had (S)
*Added 2026-08-02.* A run's findings now depend on what the server allowed - whether
`performance_schema` was readable, how many queries kept full SQL, how often backtraces were
truncated. None of that is stored with the run, so a report read later (or submitted to the hub)
cannot be told apart from one taken on a fully-equipped server. Stamping the capability state
onto the run makes every report self-describing, and would make hub submissions comparable.

### Export a findings report (S)
The report JSON is agent-ready but there is no human-shareable artefact. A one-page HTML or PDF
export would let an agency hand the analysis to a client, and would spread the plugin.

### "What changed since last run" diff (M)
The History tab tracks score and medians over time, but not *what* changed. A per-plugin diff
between two runs would answer "we installed X last week, what did it cost us" directly, which
is the question the spot-check prompt already hints at.

### Suggest the Super Speedy plugin that fixes the finding (S)
Findings already name the responsible plugin and a recommendation. Where the finding is a class
Dave already solves - postmeta scans, slow search, slow filters, slow imports - the
recommendation could name the relevant Super Speedy plugin. This is the natural commercial
bridge from a free diagnostic to the paid range, and it needs care to stay honest rather than
becoming an advert.
