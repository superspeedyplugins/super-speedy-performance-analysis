# Third-party performance tool integration - findings and options

Status: **research only, nothing built.** Written July 2026.

Brief: let site owners install and configure third-party perf tools from inside Super Speedy
Performance Analysis, prefer open source and self-hosted, earn affiliate revenue where they
pick a paid service, and eventually surface the results in an SSPA GUI so the tool's data
feeds the "which plugin is the problem" answer. Longer term, submit reports to plugin and
theme authors.

## 1. The finding that governs everything else

**Almost every deep PHP profiler is a compiled PHP extension.** It needs root, a package
install or a compile, an edit to `php.ini`, and a PHP restart (FPM pool reload). A WordPress
plugin cannot do any of that, and must never try. That single fact decides what SSPA can
honestly offer, and it splits site owners into three groups:

| Hosting tier | What they can install | Realistic option |
|---|---|---|
| Shared hosting (the majority of WP) | No extensions, no DB server access, no second box | SSPA's own profiler, plus the pure-PHP tools in section 3.4 |
| VPS / self-managed / a good managed host | Extensions, DB config, root | The full self-hosted set: SPX, XHProf, Excimer, PMM |
| Already running an ops stack | Everything | Elastic, SigNoz, Grafana/OTel, or a SaaS APM |

Two checks on this machine, which is a developer's box, not a shared host:

- `php -m` lists **none** of xhprof, tideways, spx, excimer, opentelemetry, newrelic, ddtrace
  or blackfire. Nothing is there by default, anywhere.
- `SELECT` on `performance_schema.events_statements_summary_by_digest` is **denied** to the
  WordPress DB user (MariaDB 12.1.2, local install). The normalised-query-digest route needs
  an explicit `GRANT`, so it is a guided setup step, not something SSPA can just switch on.

So the deliverable is not "SSPA installs New Relic for you". It is:

1. **Detect** what the server already has and what the hosting tier allows.
2. **Generate** the exact install commands, `php.ini` lines and `GRANT` statements for that
   specific server, ready to paste or hand to a host's support.
3. **Configure** the parts that live in PHP userland (composer packages, agent config written
   to files SSPA already owns, transaction naming).
4. **Read the results back** and merge them into SSPA's existing plugin-attribution GUI.

Step 4 is where the actual product value is, and it is the part nobody else does. Every one
of these tools tells you "this function took 400ms". None of them tell you "that function
belongs to WooCommerce Product Filters, and here is what happens when you disable it". SSPA
already has the plugin attribution and the virtual-disable proof. Bolting a real profiler
onto that is the differentiator.

## 2. Verdict on the tools in Dave's list

| Tool | Self-hostable, no SaaS? | Open source? | Free tier usable for a small WP site? | Verdict for SSPA |
|---|---|---|---|---|
| **New Relic** | No. SaaS only | No (agent binary is proprietary) | **Yes, genuinely.** 100GB/month ingest, 1 full-platform user, unlimited basic users, perpetual, no card | Best "paid tool that is actually free for our users". Integrate, do not resell |
| **Datadog APM** | No. SaaS only | No | No. APM is not in the free tier | Skip unless a user already has it |
| **Dynatrace** | No (managed/SaaS) | No | No | Skip. Enterprise pricing, wrong audience |
| **AppDynamics** | No | No | No | Skip. Same reason, worse fit |
| **Tideways** | **No.** SaaS. No current on-prem offering | The **XHProf extension is** (Apache 2.0). The APM service is not | No | Split verdict, see below. The extension is a top pick, the service is not |
| **Scout APM** | No. SaaS | No | Partial. 300k transactions free, but data retention is restricted | Low priority. Free tier too thin to build a GUI on |
| **Elastic APM** | **Yes**, fully. APM Server + Elasticsearch + Kibana, self-hosted Basic tier is free | Agents Apache 2.0; stack is Elastic 2.0 / SSPL / AGPL depending on component | N/A, self-hosted | Viable, but needs an Elasticsearch cluster. Heavy for a WP site owner |
| **Grafana + Tempo + OTel** | **Yes**, fully | Yes (AGPLv3 / Apache 2.0) | Grafana Cloud free: 50GB traces, 10k series, 3 users, 14-day retention | Viable, most assembly required. Best for users already running Grafana |

The single most important correction to the brief: **Tideways is two different things.** The
SaaS is paid and cannot be self-hosted, but `tideways_xhprof` is an Apache-2.0 PHP extension
that Tideways maintains as free open source, and it is the best-maintained XHProf successor.
SSPA should treat the extension as a first-class self-hosted option and treat the SaaS as
optional.

## 3. Tools missing from the list that fit better

The brief's list is an enterprise-observability list. For "a WordPress site owner needs to
find the slow plugin", these are better matches.

### 3.1 XHProf-family extensions - the highest-value integration

`tideways_xhprof` (Apache 2.0), or the original `xhprof` (Apache 2.0). Function-level
hierarchical profiling: wall time, CPU, memory, call counts per caller/callee pair.

**Why this one matters more than anything else in this doc:** you call
`tideways_xhprof_enable()` at the start of a request and `tideways_xhprof_disable()` at the
end, and it hands you **a plain PHP array**. No agent, no daemon, no server, no SaaS, no UI
to install, no data leaving the box. SSPA already runs signed loopback profiling requests
through its own mu-plugin, so it can enable the profiler for **those requests only**, capture
the array, and store it in its existing tables.

That upgrades every SSPA finding from "WooCommerce Product Filters costs 320ms" to
"WooCommerce Product Filters costs 320ms, 280ms of which is in
`WCPF\Query::build_meta_clause()`, called 47 times". That is the actual answer a site owner
or a plugin author needs. Requires an extension, so it is VPS-and-up only.

### 3.2 Excimer - the safe production option

Wikimedia's `excimer` extension, Apache 2.0. A **sampling** profiler with negligible overhead,
built for and running on Wikipedia in production. Also returns data to PHP, no daemon. Where
XHProf-style instrumentation is too heavy for a live site, Excimer samples it safely. It is
also what Sentry's PHP profiling uses under the bonnet.

### 3.3 SPX - the best standalone UI, zero integration work

`php-spx`, GPL-3. A profiling extension with a **built-in self-hosted web UI**: flamegraph,
timeline, flat profile. Explicitly designed to be "totally free and confined to your
infrastructure with no data leaks to a SaaS", which is exactly the brief.

For SSPA this is the easiest recommendation and the hardest integration: its report format is
its own and its UI is already good, so the sensible play is "detect it, link to it, tell the
user how to install it" rather than trying to re-render its data. Note the GPL-3 licence -
fine to recommend and document, do not vendor its code into a GPLv2-or-later plugin without
thinking it through.

### 3.4 The pure-PHP tier - works on shared hosting

The only tools that need **no extension at all**:

- **Sentry PHP SDK** (MIT SDK; Sentry server is FSL-1.1, converting to Apache 2.0 after two
  years). Composer package, pure PHP tracing. Self-hostable via docker compose, and the FSL
  explicitly permits running it for your own business. Note: Sentry's *profiling* still wants
  `excimer`, but its *tracing* does not. This is the one real "works everywhere" option.
- **OpenTelemetry PHP, manual instrumentation** (Apache 2.0). Auto-instrumentation needs the
  `opentelemetry` PECL extension, but manual spans are pure composer. Sends to any OTLP
  backend: SigNoz, Grafana Tempo, Elastic, Jaeger.
- **MySQL `performance_schema` digests.** Not a tool, a table. `events_statements_summary_by_digest`
  gives normalised, parameterised query fingerprints with total/average latency, rows examined
  and rows sent, which is the same normalisation the paid APMs charge for. Read over `$wpdb`
  with no extension and no second server. Blocked only by a missing `GRANT`, as proven above,
  and MariaDB ships `performance_schema` off by default. This is a very cheap, very high-value
  addition to SSPA's own SQL analysis.

### 3.5 The MySQL side - self-hosted and free

- **Percona Monitoring and Management (PMM)**, open source, self-hosted via Docker. Its Query
  Analytics ranks every query by load with proper fingerprinting. Complete MySQL observability
  with no commercial licence. Needs a second container and a DB agent, so VPS-and-up.
- **Slow query log + `pt-query-digest`** (Percona Toolkit, GPL). No server to run at all: turn
  the log on, run one command, get a ranked digest. The lowest-effort real answer on the SQL
  side, and SSPA could parse the digest output directly.

### 3.6 Self-hosted OTel backends, if a user wants a real stack

- **SigNoz** - MIT core with a separately-licensed enterprise module, ClickHouse-backed,
  OpenTelemetry-native from day one, free self-hosted Community edition. The closest thing to
  "self-hosted Datadog" and the one I would point a technical user at.
- **Jaeger** (Apache 2.0) - tracing only, lighter.
- **Uptrace / HyperDX / OpenObserve** - same family, varying open-core boundaries.

## 4. What SSPA should actually build, ranked

Ordered by value delivered per unit of effort.

1. **A "Tools" tab that detects and grades the environment.** Read `php -m` equivalent via
   `extension_loaded()`, check for `newrelic`, `ddtrace`, `tideways_xhprof`, `xhprof`,
   `excimer`, `spx`, `opentelemetry`, `blackfire`. Check whether `performance_schema` is
   enabled and readable. Check whether the slow query log is on. Then tell the user, in plain
   English, which tier they are in and exactly what they can add. Cheap to build, useful
   immediately, and it is the foundation for everything below.
2. **`performance_schema` digest reader.** Pure SQL, no install, no extension. Generates the
   `GRANT` line the user needs if it is denied. Merges normalised query digests into the
   existing findings. Highest value for lowest effort of anything here.
3. **XHProf-family capture inside SSPA's own profiling requests.** If `tideways_xhprof` or
   `xhprof` is present, enable it for SSPA's signed loopback requests only, store the array,
   and render function-level attribution under the existing per-plugin drill-down. This is the
   feature that makes SSPA meaningfully better than everything else on the list.
4. **Copy-paste install kits.** Per detected OS/PHP version, generate the exact
   `pecl install` / `apt install` lines, the `php.ini` line, the restart command, and a short
   note the user can send to their host's support if they cannot run it themselves. Never
   attempt the install.
5. **Slow-query-log + `pt-query-digest` importer.** Parse a pasted or uploaded digest and fold
   it into SSPA's findings.
6. **New Relic integration, both directions.** If the agent is present, call `newrelic_name_transaction()`
   so NR's data becomes WordPress-aware (named by template/route rather than `index.php`), and
   add plugin-attribution custom attributes. Optionally pull data back via NerdGraph with a
   user-supplied API key. Worth doing because the NR free tier genuinely covers a small site.
7. **SPX / SigNoz / PMM: detect, document, link.** Do not try to re-render their UIs.

Explicit non-goals: never edit `php.ini`, never run installs as the web user, never bundle a
compiled extension in the plugin zip, never proxy a user's SaaS credentials through
superspeedy.org.

## 5. Affiliate revenue - an honest assessment

The affiliate money is not where the brief assumes it is.

- **Datadog** runs a referral track paying up to 10% of first-year subscription value. Real,
  but our users are small WP sites who will not buy Datadog.
- **New Relic** has a partner programme rather than a self-serve affiliate, and the users we
  would send them fit inside the free tier, so the expected revenue is approximately zero.
- **Blackfire** is owned by Platform.sh, is SaaS-only (the old on-premise Enterprise edition is
  gone), costs from roughly $30 to $290 a month depending on plan and trace quota, and has no
  public affiliate programme.
- **Tideways** has a partner programme, oriented at hosting companies rather than plugin
  vendors.
- **Scout APM** is small, and its free tier plus a $19/mo entry plan means small commissions.

**Where the money actually is: hosting.** Kinsta pays $50 to $500 per referral plus 10%
recurring for life, with a 60-day cookie. WP Engine pays a minimum of $200 per referral with a
180-day cookie. That matters here because the honest diagnostic outcome for a large share of
SSPA users is *"you cannot install any of these, and your host is part of the problem"*. The
recommendation to move to a host that gives you a real PHP environment is both the correct
technical advice and the one that pays.

**The conflict of interest is the real risk.** superspeedy.org's whole proposition is a
neutral community database of plugin performance. The moment SSPA recommends a paid tool or a
host for money, that neutrality is challenged. Rules to set now, before any of this is built:

- Never let an affiliate relationship change a *measurement* or a *ranking*. Attribution and
  measured impact stay untouched.
- Disclose every affiliate link in the UI at the point of the link, not in a footer.
- Always present the free and self-hosted option first and at equal prominence, because that
  is also the better advice.
- Keep affiliate recommendations out of the community submission payload and out of the rules
  feed entirely. See `.docs/superspeedy-org-hub-launch.md`.

## 6. Submitting reports to plugin and theme authors

Genuinely valuable and genuinely dangerous. Notes for whoever specs it.

**What makes a report worth sending:** it must be reproducible and specific. "Your plugin is
slow" gets ignored. "On WP 6.9 / PHP 8.3 / WooCommerce 9.x with 50k products,
`WCPF\Query::build_meta_clause()` runs 47 times per shop page, 280ms total, here is the
normalised SQL and here is the measured delta when the plugin is virtually disabled" gets
fixed. SSPA can already produce most of that; XHProf capture (section 4.3) supplies the
function-level part that turns it from a complaint into a bug report.

**Channels**, in order of likely success: a public GitHub repo issue, then the wp.org support
forum, then the author email in the readme. Aggregation matters more than any single report -
"this pattern appears on 340 of the 1,200 sites running your plugin" is far more persuasive
than one site's numbers, and only the hub can produce that.

**Constraints:**

- Semi-authored and approved by Dave before sending, as specified. Never auto-send.
- Never include anything identifying the reporting site. The anonymisation rules in
  `.docs/brainstorm-performance-analysis.md` already cover this and must apply unchanged.
- Give the author a right of reply and a version window before anything is published on
  superspeedy.org. The brainstorm already commits to this at section 485.
- Always name the exact plugin **version** measured. A report against a version fixed six
  months ago is worse than no report.
- Be ready for the attribution trap the Plugins tab already documents: a plugin that replaces a
  slow core feature carries the queries it runs even when it is faster than what it replaced.
  Sending an author a report that fails to account for that damages credibility permanently.

## 7. Recommendation

Build sections 4.1 and 4.2 first: environment detection plus the `performance_schema` digest
reader. Neither needs an extension, both work on shared hosting, and together they make the
Tools tab worth opening. Then 4.3, the XHProf capture, which is the feature that makes SSPA
better than the tools it is integrating with rather than a launcher for them.

Treat the SaaS APMs as detect-and-enhance, not as products to sell. Put the affiliate effort
into hosting, where the payouts are real and the advice is honest, and write the disclosure
rules into the product before the first affiliate link exists.

## Sources

- [New Relic free tier](https://newrelic.com/pricing/free-tier)
- [Elastic APM PHP agent](https://www.elastic.co/docs/reference/apm/agents/php)
- [SigNoz](https://signoz.io/)
- [php-spx](https://github.com/NoiseByNorthwest/php-spx)
- [Percona Monitoring and Management](https://percona.community/projects/pmm/)
- [Blackfire pricing](https://www.blackfire.io/pricing/) and [is Blackfire available on premises](https://support.blackfire.platform.sh/hc/en-us/articles/4792478608146-Is-Blackfire-available-as-on-premises-install)
- [Sentry licensing](https://open.sentry.io/licensing) and [self-hosted Sentry](https://develop.sentry.dev/self-hosted/)
- [Scout Monitoring pricing](https://www.scoutapm.com/pricing)
- [Grafana pricing](https://grafana.com/pricing/)
- [Datadog Partner Network](https://www.datadoghq.com/partner/network/)
- [Kinsta affiliate programme](https://kinsta.com/affiliates/) and [WP Engine affiliate programme](https://wpengine.com/affiliate-program/)
- [Tideways](https://tideways.com/)
