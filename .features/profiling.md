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
Profiles record the request method (since 0.11.0), so the POST steps of a checkout are not
listed as GETs.

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
Gap, small and worth fixing, still present at 0.18.0: HTTP calls carry `via` in the capture
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

### The database layer is skipped in attribution
**Since:** 0.9.4, 3 August 2026

With Query Monitor's `db.php` in place, every query used to be attributed to Query Monitor - its
database class is the innermost frame of every query stack, and attribution stopped there. Any
`wpdb` subclass (Query Monitor's, LudicrousDB's, anyone's) is now skipped, so queries land on the
plugin that actually ran them.

### Sampling discipline
**Since:** 0.2.0, 11 July 2026

A warm-up request plus 3 measured samples per page, with medians reported. Cache-served
responses are discarded via a response canary, so a page cache cannot fake a good result.

### Cache-busted profiling: a cached site is measured, not skipped
**Since:** 0.9.4, 3 August 2026

Every profiling request carries a unique cache-busting argument, signed into the token so it
cannot be stripped, guaranteeing the request reaches PHP rather than being answered by nginx,
Varnish, LiteSpeed or a CDN. Before this, the home page, shop and product pages of any cached
site produced no data at all. Profiled responses also send `Cache-Control: no-store`, so a
measurement can never be stored and served to a real visitor.

Pages that still could not be measured are reported loudly rather than quietly dropped: a
warning on the Overview counts them, the Pages tab marks each one "not measured - cache served
it", and the count is stored with the run. Where no per-query capture was possible, SQL time and
row totals are stored as **unknown** rather than zero, so a blind measurement cannot be mistaken
for a page that spent no time in SQL.

### The measurement path is disclosed, including the CDN gap
**Since:** 0.10.8, 4 August 2026

Profiling requests are loopbacks from the server to itself, and on many hosts those bypass the
CDN - arriving without the headers Cloudflare adds for real visitors (the country header, WAF
marks). Behaviour keyed on those headers then differs between the measured path and the visitor
path: WooCommerce's MaxMind lookup, for example, only runs when no country header is present.
Every capture records whether it passed through Cloudflare and whether the country header was
present, and the panel says so plainly - including a note when Cloudflare was traversed but IP
Geolocation is switched off.

<!-- internal -->
This is an honesty feature that competitors do not have and it is genuinely publishable: it
names a systematic measurement error rather than hiding it. It also has a KB article,
`.kb/geolocation-speed-boosts.md`.

### Bootstrap decomposition: where the flat per-request cost goes
**Since:** 0.9.7, 3 August 2026

The answer to "every page is slow but no single plugin shows up". Many sites pay a flat PHP cost
on every request - plugin loading plus init hooks - spread across dozens of plugins in slices
too small for one-at-a-time exclusion to see. Every profiled page records where that time went,
with **nothing to install**: the request broken into phases (core, plugin file loading, plugin
boot callbacks, theme setup, init, routing, render), the PHP cost of every plugin individually
(file load time plus its callbacks on the expensive hooks), and the slowest single hook
callbacks by name.

The instrument only runs on the analysis's own signed requests, adds two clock reads per
callback there, and touches nothing on normal traffic.

### The phase table accounts for the whole request
**Since:** 0.9.9, 4 August 2026

"Where the PHP time went" used to stop at routing, so its rows summed to well under the
generation time. Post-init boot and template render + output rows close the gap, and the phases
now sum to the page's generation time.

### Render breakdown
**Since:** 0.9.10, 4 August 2026 (extended 0.9.14)

The render phase is no longer a single number. Every `wp_head`/`wp_footer`/content-filter
callback, every shortcode and every widget is individually timed and attributed to its plugin -
widgets to the plugin that provides them, not to WordPress core. WooCommerce's template layout
hooks (shop loop, product summaries, sidebar) and every dynamic block render are timed too, so
the untimed remainder shrinks to genuinely just the theme's own template code, which is labelled
honestly rather than hidden.

<!-- internal -->
0.9.14 also fixed a real over-counting bug: on archive pages, hooks that fire once per post
(`the_content`, the WooCommerce loop hooks) had their callbacks re-wrapped on every firing,
nesting the timers and counting the same work more than once. Any timing screenshot taken from
an archive page before 0.9.14 is wrong.

### Expandable phases
**Since:** 0.9.14, 4 August 2026

Every phase in "Where the PHP time went" expands to the plugins its time belongs to - plugin
file loading per plugin, `plugins_loaded`/`init`/`wp_loaded` callbacks per plugin, the render
phase per plugin - with anything the instruments could not see labelled as such. Works on
already-stored results, because the data was always captured and simply not shown.

### Function-level profiling via excimer
**Since:** 0.10.0, 4 August 2026

When the `excimer` PHP extension is installed, every profiled page automatically gains a "By
function" breakdown: which exact functions the time was spent in, with self and inclusive
milliseconds, each attributed to its plugin or theme - **including inside theme template files**,
which the hook-wrapping instruments cannot see. It appears in both the page panel and the Pages
tab drill-down.

Because excimer records a complete call stack for every sample, attribution uses the same
shared-library-aware walk as the SQL analysis, so a plugin is never blamed for a vendor library
another plugin called into. Sampling overhead is negligible - the same profiler runs on every
Wikipedia request - so it runs during the normal measurement pass without distorting the numbers
being measured.

Everything works without excimer; the breakdown simply does not appear.

<!-- internal -->
Sampling period is 1ms (`SSPA_Excimer::PERIOD_MS`), ~500 samples on a 500ms page. It is
statistical: a function cheaper than the sample period is invisible, and milliseconds are
samples x period, not measured wall time. Do not write "exact call counts" - that is the
unbuilt phase 6 (XHProf family). Caps: 40 functions global, 10 per phase.

### Functions listed by self time, and split by who drove them
**Since:** 0.10.5 / 0.10.6, 4 August 2026

The "By function" table lists functions by SELF time first. Sorted by inclusive time the top
rows were WordPress's own bootstrap include chain - plumbing, not culprits.

Each row also names who was **driving** the function. Where a shared function's time is spent on
behalf of several plugins the row shows the split: *"WP_Object_Cache::get, 63ms self, driven by:
woocommerce 31ms, seo-by-rank-math 12ms"*. That is the difference between knowing the object
cache is busy and knowing which plugin is keeping it busy.

### Per-phase function lists
**Since:** 0.10.7, 4 August 2026

Every profiler sample carries a timestamp, so samples are bucketed into request phases. Clicking
the render remainder shows the functions sampled during render; clicking the plugin-boot gap
shows what ran during plugin boot. Each phase gets its own list rather than one global table
answering every click identically. The whole-request "By function" table remains at the bottom.

### Foreign drop-in detection
**Since:** 0.2.0, 11 July 2026

Detects an existing `db.php` from Query Monitor, LudicrousDB or W3 Total Cache. It rides Query
Monitor's drop-in where present, runs degraded alongside others, or (opt-in, with a clear
warning) temporarily swaps them out for the run with crash-safe restoration.

Since 0.9.4 it also detects an **orphaned** Query Monitor `db.php` - the plugin deactivated but
its drop-in left behind, which blocks the capture layer - and offers a one-click replacement
that renames the old file rather than deleting it. With Query Monitor active, anonymous page
profiles capture no per-query data at all (QM only collects for logged-in viewers); the health
check warns about that instead of claiming full detail.

### Security-plugin loopback detection
**Since:** 0.2.0, 11 July 2026

Detects security plugins blocking loopback requests and names exactly what to whitelist. The
run continues rather than failing.

### Configurable measurement timeout
**Since:** 0.16.1, 11 August 2026

A Settings tab carrying how long each measurement request may wait for a page before giving up -
10 to 900 seconds, default 60. A page slower than the limit records nothing at all, and on a
struggling site those are exactly the pages worth measuring: the owner's own browser loads them
fine with the server timeouts they raised, while the analysis abandons every sample.

### Profiled fatals do not trigger recovery mode
**Since:** 0.9.6, 3 August 2026

A page that fatally errors while a plugin is virtually excluded used to trigger WordPress's
built-in fatal-error email, telling the owner their site had a technical issue and blaming the
plugin whose file threw. Profiling requests now set the same sandbox flag WordPress uses for its
own loopback tests, so these controlled test failures send no recovery-mode email and do not
trigger recovery mode. Real visitor requests keep WordPress's full protection.

### CPT archive URLs with taxonomy placeholders are resolved
**Since:** 0.9.5, 3 August 2026

A custom post type whose archive URL embeds a taxonomy placeholder (a knowledge base at
`/kb/%kb_category%/`) used to be profiled at the literal placeholder URL, which can never verify
the signed token, so the page silently produced no data. The placeholder is filled in with the
taxonomy's biggest term, and the page skipped entirely if no term exists.

### Email construction profiling
**Since:** 0.5.0, 11 July 2026

Measures the mail stack's construction cost via an intercepted test email. Nothing is ever
delivered - recipients are stripped before any transport work. Slow email construction becomes
a finding attributed to the responsible plugin. (The checkout flow is the deliberate exception:
see `checkout-flow.md`.)

### Opt-in write profiles
**Since:** 0.5.0, 11 July 2026

Saves a temporary copy of a post or product and steps a temporary order through pending ->
processing -> completed, measuring the full save and status-hook cascade including the emails
each transition builds. Temporary objects are created and deleted around each measurement: no
residue, no real content touched, no mail sent.
