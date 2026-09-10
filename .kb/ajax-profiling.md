# Profile AJAX and compare plugin selections

The **AJAX** tab in Performance Analysis records the requests made while you perform a workflow. Capture a Before window, change the plugins selected for that endpoint in Scalability Pro, then capture an After window to see the measured difference.

*Available since 0.37.*

Performance Analysis measures requests. Scalability Pro controls which plugins load, and only for endpoints you explicitly enable for optimisation. Starting a recording does not change plugin loading or replay requests.

## Record the Before window

1. Open **Performance Analysis → AJAX**.
2. Enter a **Window name**, such as “Before product variation”.
3. Enter a **Scenario** describing repeatable inputs, such as “Same product, select blue, quantity one”. Use the same scenario for the After window and avoid personal information in labels.
4. Select observed endpoints, or leave the endpoint selection empty to discover registered AJAX and REST requests. When Scalability Pro supplies purpose groups, use **Endpoint group** to narrow the choices.
5. Leave **Sample plugin activity** unticked for an ordinary timing comparison.
6. Choose **Start window**, perform the workflow in another tab, then choose **Stop window**.

A window stops after 15 minutes or 200 observations. Repeat the interaction enough times to see normal variation, keeping its inputs, login state and cache conditions consistent. Do not mix unrelated journeys into one scenario.

## Apply the endpoint selection and record After

In Scalability Pro, review the endpoint's required and suggested plugins, decide whether its active theme is needed, and enable the exact rule. Return to Performance Analysis and record a second window while repeating the same scenario.

Keep the site's environment and plugin versions stable between recordings. Change the plugin selection you want to test between windows, rather than halfway through a window.

:::callout{variant="recommended" title="Check correctness alongside speed"}
For a variation lookup, check the selected variation and price. For a cart or checkout request, check totals, discounts, tax, shipping and the resulting state. HTTP 200 only tells you that the request returned successfully at the HTTP level; it does not prove the business result is correct.
:::

## Compare the windows

Under **Compare saved windows**, select **Before** and **After**, optionally enter **Save as**, then choose **Compare**. A saved comparison lets you reopen the same pair.

The chart uses the History measurement renderer, in a separate AJAX view. Each point represents a retained request. The summary calculates median and p95 from successful requests and keeps failed response samples separate. Use **Filter endpoint or scenario** to focus the view and select a point to inspect its setup and available activity evidence.

Timings run from the MU observer's entry to request shutdown. They include server work beyond the endpoint handler. They are not a browser waterfall, network transfer time or a measurement of when the page finishes updating.

:::callout{variant="note" title="Use your measured result"}
The before-and-after figures come from the requests captured on your site. There is no promised target time or reduction: the saving depends on the plugins omitted and the work the endpoint still needs to perform.
:::

## Why a comparison may be unavailable

Different HTTP methods, authentication contexts, scenarios, environments or actual instrumentation modes do not form an equivalent pair. Check these details before drawing a conclusion from two windows.

Each request records the effective loaded plugin versions, theme and Scalability Pro policy. If the setup changes within a window, its points and setup periods remain visible, but the report withholds a combined headline reduction. Record another window with a stable setup.

The installed plugin inventory at the start of a window is distinct from the plugins that actually loaded for a request. Use the selected request's setup when checking whether the intended optimisation ran.

## Investigate plugin activity

Tick **Sample plugin activity** when you need more evidence about what plugins do during the request. Detailed sampling is a diagnostic option and adds overhead. It covers at most 20 requests per window and five per AJAX action; other requests use a different instrumentation series.

| Evidence | What it helps you investigate | What it does not prove |
| --- | --- | --- |
| Include deltas | Work while plugin files load | That the plugin is required |
| Hook-registration checkpoints | Hooks the plugin has attached callbacks to | That those callbacks ran |
| Executed named actions | Observed callback activity during sampled actions | A complete trace of all PHP execution |
| SQL, HTTP and mail attempts | Recorded external work attributed during sampling | Complete SQL duration or mail delivery time |

Coverage is partial. Filters and direct REST permission and handler callbacks are not fully timed, nor are callbacks added during dispatch or reference callbacks. A callback that exits the request can have a count with incomplete duration. Nested inclusive callback times overlap, so adding them together overstates total time. HTTP timing covers completed transports counted by the report.

A plugin that only appears during startup may be worth investigating, but missing activity is not proof that it does nothing. Use the evidence to choose what to test in Scalability Pro, not as an automatic instruction to unload a plugin.

## Use the evidence in Scalability Pro

Performance Analysis supplies registered endpoint identities, request metrics, callback ownership and available activity evidence. Scalability Pro uses that information alongside its deterministic purpose groups and plugin suggestions.

An endpoint selection remains an administrator decision. Collecting evidence does not enable a rule or alter the list of plugins that load.

## Export and privacy

Choose **Export chart and measured summary** to download a standalone HTML report. It contains the measured summary and retained evidence. Review it before sharing it with another person.

Traffic observations and AJAX measurements stay on your site. They are not sent by profiling-run sharing consent. The endpoint identity record excludes request bodies, response bodies, cookies and literal dynamic REST paths.

Sharing a conventional saved performance analysis is a separate action. Its boot, include, selected-hook, render and asset evidence can be included with the applicable run-sharing consent or **Share this analysis**. That does not submit your AJAX windows.

:::related{slugs="super-speedy-performance-analysis,scalability-pro"}
:::
