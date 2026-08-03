# Profiling and capture

### Lean built-in profiler
**Since:** 0.2.0, 11 July 2026

A conditional `db.php` drop-in plus an mu-plugin loader that are completely inert for normal
traffic and only activate for token-signed profiling requests. Query Monitor is not required
and is not a dependency.

### Signed loopback profiling of your key pages
**Since:** 0.2.0, 11 July 2026

Profiles front-end, WooCommerce and wp-admin pages via signed loopback requests, storing
per-page generation time, SQL time, query counts, returned rows, HTTP API time and peak RAM.

### Per-query attribution to plugins and theme
**Since:** 0.2.0, 11 July 2026

Row counts, errors and plugin/theme attribution captured per query via backtraces. Full SQL is
kept for the slowest and largest queries (the slowest 20 distinct queries per page); the rest
are stored as privacy-safe fingerprints.

### Shared library misattribution, fixed
**Since:** 0.9.2, 30 July 2026

When two plugins bundle the same library - Freemius, Action Scheduler, Guzzle, anything under a
`vendor` directory - PHP loads only one copy, and which plugin owns that copy on disk is decided
by autoloader order rather than by whose work it is. Every other tool charges that plugin for
every other plugin's use of the library.

Attribution now walks past the shared library to the component that actually called in, and
names the library separately: *"WP All Import, work done in action-scheduler (in woocommerce),
charged here because this is what called it"*. A plugin using its OWN bundled library is still
charged for it, and ordinary cross-plugin API calls - a plugin calling `wc_get_product()` -
still resolve to the API's owner rather than the caller.

<!-- internal -->
This is the flaw Dave hit in Code Profiler Pro and the reason the whole 0.9.2 attribution work
happened. It is the strongest honest differentiator in the plugin, and it is worth saying out
loud on a sales page that other profilers get this wrong. Note the deliberate exclusion: bare
`lib`, `libs` and `libraries` are NOT treated as vendor markers, because plugins routinely put
their own code there.

### Full component chain captured per query
**Since:** 0.9.2, 30 July 2026

Every query and HTTP call records the whole chain of components involved, innermost first, not
just one name. The same capture therefore answers both attribution questions - whose code ran,
and who asked - without re-profiling, and the chain is stored so a run can be re-read either way
later.

<!-- internal -->
Gap, small and worth fixing: HTTP calls carry `via` in the capture
(`profiler/class-sspa-capture.php`), but `slow_http()` does not copy it into the finding's
evidence and `SSPA_Insights` does not render it for that type. So the "work done in X" line
appears on query findings but never on HTTP ones. Same for `dupe_queries`.

### Deeper call stacks
**Since:** 0.9.2, 30 July 2026

Query backtraces capture 32 frames, up from 14, so attribution can see the frame where one
component calls into another. How often a stack was still cut short is reported on the Tools
tab rather than assumed.

<!-- internal -->
Measured, not guessed: raising 14 to 32 on a plain WooCommerce docker site recovered zero
additional cross-component chains, so the absence of chains there is genuine rather than a
truncation artefact. Truncation still occurs at 32 (~6% of queries in that test). Deeper
backtraces do very slightly bias measured impact towards query-heavy plugins.

### Sampling discipline
**Since:** 0.2.0, 11 July 2026

A warm-up request plus 3 measured samples per page, with medians reported. Cache-served
responses are discarded via a response canary, so a page cache cannot fake a good result.

### Foreign drop-in detection
**Since:** 0.2.0, 11 July 2026

Detects an existing `db.php` from Query Monitor, LudicrousDB or W3 Total Cache. It rides Query
Monitor's drop-in where present, runs degraded alongside others, or (opt-in, with a clear
warning) temporarily swaps them out for the run with crash-safe restoration.

### Security-plugin loopback detection
**Since:** 0.2.0, 11 July 2026

Detects security plugins blocking loopback requests and names exactly what to whitelist. The
run continues rather than failing.

### Email construction profiling
**Since:** 0.5.0, 11 July 2026

Measures the mail stack's construction cost via an intercepted test email. Nothing is ever
delivered - recipients are stripped before any transport work. Slow email construction becomes
a finding attributed to the responsible plugin.

### Opt-in write profiles
**Since:** 0.5.0, 11 July 2026

Saves a temporary copy of a post or product and steps a temporary order through pending ->
processing -> completed, measuring the full save and status-hook cascade including the emails
each transition builds. Temporary objects are created and deleted around each measurement: no
residue, no real content touched, no mail sent.
