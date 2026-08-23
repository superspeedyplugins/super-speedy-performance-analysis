# Checkout and order flow analysis

WooCommerce only. This is the one measurement in the plugin that makes a real purchase, and the only run type that sends real email.

## What you get to see

**The customer's wait, step by step, through one real purchase**, with every plugin on your site active, split at the payment boundary. Time before the money is taken can lose you the sale. Time after it is confirmation time. Those two numbers deserve different reactions, and a single "checkout takes 9 seconds" tells you nothing about which half to fix.

**Then the shop owner's side.** The run carries on past the purchase into order management. It opens the order in wp-admin and marks it completed, the two things a shop owner does most, so you also see how slow your order screen is and what marking an order completed sets off: the completed-order email, stock and downloads, and every plugin hooking order completion. That's staff time per order rather than something a customer waits through, so it's reported in its own section.

Each step carries the same diagnostics as any other profile: request phases, per-plugin cost, the slowest queries with the code that ran them, outbound HTTP calls, mail time and peak RAM.

## Why you can't see this any other way

A page profiler shows you a page render, in your browser, on a GET request. A checkout is none of those things. It's a chain of authenticated POSTs and redirects with a payment gateway in the middle, and a status transition afterwards that fires half your plugin list.

Debuggers that do follow ajax and REST requests report them as a summary in the response headers: total time, memory, PHP errors, redirect call stacks. That's not a query list with the plugin responsible for each one, and a summary per request still isn't the thing you need, which is the whole sequence timed as one flow and split where the money is taken.

Two things make the numbers real rather than indicative:

**Order emails are sent for real** during a checkout flow run, so your mail server's own time is in the measurement. Every other kind of run sends nothing. If a mail plugin replaces WordPress delivery, Mailgun's HTTP mode and similar, those messages are counted and reported as untimed, with their real cost visible under outbound calls, rather than showing as one message in 0ms.

**The payment boundary is stamped where the money is taken**, at entry to `payment_complete()`. Order emails, cache purges and integrations hanging off the status transition therefore land in post-payment confirmation time, not in the time that risks the sale.

Both the block checkout and the classic `[woocommerce_checkout]` shortcode checkout are supported.

## Before it runs, it tells you what it will set off

The pre-flight panel lists exactly which emails, webhooks and plugins the run will trigger, including the order view and completion steps, before anything is created. Read it, then decide.

```
wp sspa checkout-flow --dry-run
```

prints the same pre-flight without buying anything.

Afterwards the order is cancelled and deleted with stock restored. On stores that don't allow guest checkout, the customer account WooCommerce creates for the purchase is deleted along with the order, the pre-flight says so, and an hourly janitor sweeps up any that a crashed run left behind.

The purchase uses one hidden, non-stock-managed SSPA product. It never buys a catalogue product, follows only same-origin redirects, and keeps TLS certificate verification on throughout.

## How to run it

- **Admin bar:** the PA menu, Analyse checkout performance. Also on the Pages tab.
- **WP-CLI:** `wp sspa checkout-flow`, with `--dry-run` for the pre-flight only.
- **From an agent:** the `run-checkout-flow` ability, which requires explicit consent. See [[AI-Agents-and-MCP]].

Your mail setting decides what happens to the emails: `deliver` (the default), `construct` (built and timed, never sent) or `suppress`.

## How to read the result

The waterfall expands step by step. Start at the widest bar before the payment boundary.

Findings name the responsible plugin and what to do, the same as any other analysis. Misbehaviour signatures are matched by behaviour rather than by plugin name, so a badly behaved integration is caught whether or not anyone has seen it before.

Blocked or partial management sequences are reported honestly. If the run couldn't complete a step it says so rather than quietly reporting a shorter time. The flow's own instrumentation is excluded from the numbers, so you aren't reading our overhead back as your checkout's cost.

## Requirements and limits

WooCommerce, with a working checkout. A payment method that can complete without a live gateway redirect is what gets you a full flow.

This run type is loopback-only. If a WAF or Basic Auth stops your server fetching its own pages, see [[Troubleshooting]]. Other run types fall back to browser-driven transport; this one doesn't, though Cloudflare Turnstile's documented programmatic bypass is used for the flow's own synthetic requests.

One real order is created and then removed. Anything watching your order stream, an accounting integration or a fulfilment webhook, will see it. That's what the pre-flight is for.

---

Related: [[Per-Page-Analysis]] · [[Plugin-Impact-Analysis]] · [[Update-and-Save-Analysis]] · [[Cache-Optimisation-Analysis]]
