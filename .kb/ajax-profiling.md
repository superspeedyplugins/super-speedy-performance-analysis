# Profile AJAX and compare plugin selections

Open **Performance Analysis → AJAX**. Start a named window, give the workflow a scenario label,
then perform it in another tab. Stop the window, change the endpoint's selected plugins in
Scalability Pro, and record an after window while repeating the same workflow.

Scalability Pro changes loading only for endpoints an administrator explicitly enables for
optimisation. PA measures the resulting requests. Starting a profile never enables optimisation,
changes selected plugins or replays a request.

Choose individual previously observed endpoints or select an endpoint group from SPro's
deterministic classification. Leave endpoints unselected to discover registered endpoints.
Unknown actions remain unknown. Windows stop after 15 minutes or 200 observations.

## Reading the chart

The AJAX chart reuses the History measurement renderer. Each point is an actual retained request;
median and p95 come from the successful requests, with failed response samples retained separately.
The metric is server time from MU observer entry to shutdown. Browser elapsed time is unavailable.
A fast HTTP 200 does not establish functional success: test the workflow's actual result.

Select Before and After windows. An optional comparison name saves the pair for reopening.
Export downloads a standalone HTML chart with the same measured summary and retained evidence.
These files stay local until you choose to share them yourself.

Different methods, authentication contexts, scenarios, environments or actual instrumentation
modes do not produce a comparison. Effective loaded plugin versions, theme and SPro policy are
recorded with each request. If a setup changes inside a window, its points and setup periods
remain visible but no combined headline reduction is shown. Repeat a stable window to compare it.
Installed plugin inventory at window start is kept separately from the effective loaded set.

## Optional plugin activity

Detailed sampling is off by default. Opt in only when investigating plugin activity: it adds
measurable overhead and has no approved production overhead budget. At most 20 requests per
window and five per AJAX action receive detail instrumentation. Other requests remain identity-only
and belong to a different instrumentation series.

The report separates include deltas, hook registrations at checkpoints, executed named actions
and SQL/HTTP/mail attempts. Registrations are not executions; include or init work is not evidence
that a plugin is required. Coverage is explicitly partial. Filters, direct REST permission and
handler callbacks, callbacks added during dispatch, and reference callbacks are not fully timed.
A callback that exits the request has a count but incomplete duration. Inclusive nested callback
times overlap and cannot be added together. SQL timing and mail delivery timing are unavailable;
HTTP timing covers only completed transports counted in `http_timed_count`.

## Sharing

Traffic and AJAX measurements stay local and are not submitted by run-sharing consent. Boot,
include, selected-hook, render and asset evidence from conventional saved profiling runs is
included only with consent version 5 or explicit **Share this analysis**. That separate submission
contract is payload 1.5, evidence `boot-profile@1`.
