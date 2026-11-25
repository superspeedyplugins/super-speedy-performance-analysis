---
version: 0.72.0
available_since: 0.72.0
areas: [watchdog, compare, checkout, agents]
---
# Update Watchdog

An update can slow a site down without breaking it: the pages still load, nobody reports a problem, and the store quietly gets slower. The update watchdog in Super Speedy Performance Analysis Pro measures your site after every plugin, theme or WordPress update, compares it with the analysis before the update, and emails you only when something got worse, naming the update, the page and the milliseconds.

*Available in Super Speedy Performance Analysis Pro since 0.72.0.*

## What starts a check

- **An update.** Plugin, theme and WordPress core updates are all noticed, whether they come from the Updates screen, from WordPress's automatic background updates or from WP-CLI. A core version change is noticed however it happened, including a host replacing the files.
- **A batch of updates gives one check.** The watchdog waits 10 minutes after the latest update before it measures. Each further update pushes the check back, so updating twelve plugins in a row gives one check that covers all twelve.
- **The weekly check.** Once a week, on the day you choose (03:00 site time), the watchdog runs the same check with no update. It catches slow drift, and its email tells you the watchdog is working.
- **The Run watchdog now button** in the settings, or the `run-watchdog-now` ability.

Updates to Performance Analysis itself do not start a check: they change the measuring instrument, not your site.

## What a check measures

The watchdog measures the pages your last full analysis measured: the front end as a logged-out visitor, the logged-in customer pages, and the wp-admin screens as the administrator. Nobody needs to be at the screen. It then runs the checkout test: a hidden zero-price order is placed, completed, refunded and moved to Trash, exactly as the checkout analysis does, and its order emails go to the site administrator.

Before measuring, the watchdog refreshes WordPress's own update information, which every update empties. Otherwise the first wp-admin page measured would include WordPress contacting WordPress.org, a cost every administrator pays once after updating rather than a slowdown the update caused.

Each page is measured as your **Measurement runs per page** setting says (one cold request, or a warm-up and three measured requests).

The check is compared with the most recent analysis from before the updates that measured the same pages. When there is none, the check becomes the first point to compare with, and one email says so.

The measurements are ordinary analyses, so they appear in Compare with the rest of your history. The run table marks each one: the page check, any re-measurement and the checkout test, with the check's verdict.

## What counts as a regression

- A page or checkout step that is at least **25% and at least 150 ms** slower. Both conditions must hold, so a fast page that gains 30 ms or a slow page that gains 160 ms out of 4 seconds does not count. Both numbers are settings.
- A page or step that loaded before and fails after the update, or a page that answers with a different HTTP status.
- A new PHP fatal error or PHP error, with the plugin or theme it came from.
- The checkout test failing.
- A page whose output you approved in Compare that does not match it after the update. This works with the default of one run per page.

**Every regression is measured twice.** A page or step that crosses a threshold is measured again before it counts, so one slow request (a cron job, a cache warming up, a busy neighbour on shared hosting) cannot send an alert. A slowdown is reported at the smaller of its two measurements.

## The email

The watchdog emails the site administrator, or the addresses you set, only when something regressed. The weekly check emails every week. The subject names the worst finding, for example "Shop page 304 ms slower after WooCommerce 10.6". The body lists:

- every update in the batch, with its old and new version;
- each regressed page or step with its time before, after and the difference;
- new errors with the plugin or theme responsible;
- anything that was slower the first time but not when measured again (not counted);
- a link that opens Compare with the two runs selected, and links to the Markdown report of the watchdog run and of the checkout test.

The Markdown links download the report for a logged-in administrator. Paste it into your AI assistant or send it to your developer.

## Settings

The settings are in **Performance Analysis > Compare > Advanced Compare settings**, under Update watchdog:

| Setting | Default | What it does |
|---|---|---|
| Watch for updates that slow the site down | On | Switches the watchdog and its weekly check on or off |
| Include the checkout test | On | Runs the checkout test in every check |
| Email alerts to | The site administrator | One or more addresses, separated by commas |
| Slower by at least | 25% and 150 ms | Both must be exceeded for a page or step to count |
| Weekly check on | Monday | The day of the weekly check, at 03:00 site time |

## Safety

- The watchdog's requests are the same signed test requests as every analysis: visitors are never affected and nothing is deactivated.
- One check runs at a time. When another analysis is running, the watchdog waits and tries again 10 minutes later.
- The load on the site is the same as pressing Run Analysis for those pages, plus one checkout test.
- A check that cannot finish within 4 hours is stopped, and its email says why.

## WP-Cron

The watchdog runs on WP-Cron, which fires on site traffic. A site with `DISABLE_WP_CRON` needs a system cron calling `wp-cron.php`, as it does for scheduled posts and automatic updates.

## For AI agents

Three abilities control the watchdog over MCP:

- `get-watchdog-status`: the settings, whether a check is running, the updates waiting for the next check, when the next checks are due, and the latest verdicts with their regressions.
- `update-watchdog-settings`: change any setting. An invalid value changes nothing.
- `run-watchdog-now`: start a check. While a check runs, each call moves it on a step, so calling it in a loop until `running` is false finishes a check on a site where WP-Cron does not fire.

## Further reading

- [MCP and AI Agent Control](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/mcp-ai-agent-control/) - connecting an assistant to the abilities
- [Understanding Your Results](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/understanding-your-results/) - reading the pages the watchdog measured
