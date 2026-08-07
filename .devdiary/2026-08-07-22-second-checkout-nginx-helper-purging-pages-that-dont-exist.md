---
title: "My WooCommerce Checkout Took 22 Seconds. The Culprit Was nginx-helper Purging Pages That Don't Exist"
date: 2026-08-07
status: draft
tags: [woocommerce, checkout, performance, nginx-helper, hpos, cache-purging, gridpane, super-speedy-performance-analysis]
---

# My WooCommerce Checkout Took 22 Seconds. The Culprit Was nginx-helper Purging Pages That Don't Exist

I pointed my own performance analysis plugin at my own checkout and found 18 seconds of cache purges fetching URLs that render nothing but 404s and timeouts - on default settings I never configured.

## What I was doing

I've just built a checkout flow profiler into [Super Speedy Performance Analysis](https://www.superspeedyplugins.com/) (free plugin). It buys something for real - full plugin stack active, nothing switched off - measures the server time at every step of the purchase, then cancels and deletes the order and puts the stock back. The point is to measure the checkout a customer actually experiences, because profiling the checkout *page* as a GET measures rendering an empty cart to a visitor with no session, which is a store nobody has.

First run against superspeedyplugins.com, one real purchase:

```
view product                      818 ms
add to cart                     1,030 ms
view cart                         847 ms
enter address (tax + shipping)    794 ms
view checkout                   1,110 ms
place order                    22,810 ms   616 queries
order received                    838 ms
-------------------------------------------
customer waited                ~28,300 ms
```

Twenty-two point eight seconds to place the order. My own store. And the component breakdown said the time belonged to nginx-helper, the little cache-purging helper plugin. That seemed absurd - it's a cache buster, it sends tiny purge requests to nginx. I didn't believe it either, so I went digging, and the digging is the interesting bit.

## What I found

The profiler records every outbound HTTP call a request makes, with duration and the function that made it. The place-order request contained 48 outbound calls totalling 20.9 seconds. The big ones:

```
4,685 ms  error:http_request_failed  www.superspeedyplugins.com/          <- nginx-helper do_remote_get()
4,502 ms  error:http_request_failed  www.superspeedyplugins.com/          <- nginx-helper do_remote_get()
4,500 ms  error:http_request_failed  www.superspeedyplugins.com/          <- nginx-helper do_remote_get()
  809 ms  404  www.superspeedyplugins.com/author/sspa-test/amp/           <- nginx-helper
  751 ms  404  www.superspeedyplugins.com/amp/                            <- nginx-helper
  ...six more /amp/ 404s at ~750 ms each...
  546 ms  200  api.mailgun.net/v3/.../messages                            <- mailgun (x3)
   55 ms  200  www.superspeedyplugins.com/purge/                          <- nginx-helper
```

Note the real purge request - the `/purge/` one nginx answers directly - takes 55 ms. That's what a cache purge is supposed to look like. Everything else is the story.

**The three 4.5-second "homepage" fetches weren't homepage fetches.** My display was truncating query strings, so `www.superspeedyplugins.com/` was actually `www.superspeedyplugins.com/?p=<order id>` - nginx-helper fetching the *order's own permalink* to purge it. Why does an order have a permalink? Modern WooCommerce stores orders in their own database tables (a feature called HPOS, High-Performance Order Storage), but it still creates a placeholder draft post for each order so the order's ID stays reserved in the posts table for older code. That placeholder isn't a page. Requesting `/?p=<its id>` sends WordPress off resolving a URL for a draft of a private post type, which on my site ground on long enough to hit the 5-second HTTP timeout and die. Three times per checkout, because the trigger fires on every order note.

And that trigger is the best part. nginx-helper purges a post's cache when a comment lands on it - reasonable for blog posts. But it hooks `wp_insert_comment` without checking the comment *type*, and WooCommerce order notes are comments. "Payment complete." is a comment. "Stock levels reduced" is a comment. Every note fired a full purge cascade: the order's phantom permalink, the homepage, the buyer's author archive, the feeds.

**The /amp/ 404s are a separate gem.** nginx-helper has a "Purge AMP URL" option, on by default in my install, and its idea of purging an AMP page is to fetch `<url>/amp/` raw - not through the purge endpoint, an actual page request. I don't have an AMP plugin. There are no AMP pages. So every "purge" was WordPress rendering a full 404 page, ~750 ms a time, eight times per checkout, while a customer would be staring at the spinner.

**The author archive purges revealed a third thing.** My store disallows guest checkout (I sell subscriptions), so WooCommerce silently created a customer account for the purchase, and nginx-helper then purged that brand new user's author archive and its /amp/ variant too. (This also caught a cleanup gap in my own plugin - it deleted the test order but not the auto-created account. Fixed, and the pre-flight disclosure now tells you an account will be created and removed.)

Here's the thing about my nginx-helper config: I never touched it. GridPane installs and configures nginx-helper automatically on every site they host - that's part of what you pay them for, and normally it's great. But that means the defaults I measured are the defaults a very large number of WooCommerce stores are running right now. If you're on GridPane with an HPOS store, I'd bet money your checkout is doing some version of this.

For the honesty file: my plugin's first version of this report overstated nginx-helper by about 6 seconds, because the profiler's own cleanup step (deleting the test order triggers the same purge cascade!) leaked into the totals. That's fixed - the harness now excludes its own work from every customer-facing number - but it's a nice reminder that the first job of a measuring tool is to not measure itself.

## The fix

Two kinds of fix, because there are two audiences.

**If it's your store**, tonight's version: in nginx-helper's settings, turn off "Purge AMP URL" if you don't have AMP pages, and look hard at the purge-on-comment and purge-homepage-on-edit options. Also turn on WooCommerce's deferred transactional emails (WooCommerce > Settings > Advanced > Features) - my order emails were adding ~1.6 seconds inline via Mailgun's API, and deferring them costs the customer nothing.

**The plugin fix**: I'm building a "checkout purge shield" into Scalability Pro - filters only, so deactivating restores everything exactly. It re-dispatches nginx-helper's comment purge only for real comments on publicly viewable post types (an order note is not a page edit), short-circuits any self-request for a permalink of a non-viewable post type or a phantom /amp/ URL at the transport layer (so white-labelled forks are covered too), and makes the surviving `/purge/` traffic non-blocking, because nothing ever reads a purge response anyway.

The arithmetic on my store: 22.8 seconds of place-order, minus ~13.7 s of phantom permalink timeouts, minus ~3 s of /amp/ 404 renders, minus ~1.6 s of inline email, leaves roughly 1.5 seconds of genuine order processing. TODO: the measured after-figure once the shield is deployed and the flow re-run - I'll update this entry with the real waterfall.

## Check your own site

The reason I built this feature is that none of the above shows up in a page speed test. The checkout page itself loaded in ~1.1 seconds; the 22 seconds only exists inside the POST that places the order, and no crawler sends that request. You find it by buying something and measuring the purchase.

Performance Analysis 0.11.3 now detects all three behaviours deterministically - no AI, no guesswork, just predicates over the measured calls. `checkout_purge_order_pages` fires when something fetched your order's own placeholder permalink (it matches the `?p=` id against the test order's id, so it can't false-positive). `checkout_amp_purge_missing` fires on /amp/ purges when no AMP plugin exists. `checkout_self_fetch_failed` fires when your site called itself and the call died. Each one names the plugin that made the call and the specific fix, and they match on behaviour rather than plugin name, so hosting-company forks get caught identically.

It's free, and the checkout analyser shows you a disclosure of exactly what a test purchase will set off before it buys anything. If your place-order step is over about 2 seconds, something on your stack is doing work your customer shouldn't be waiting for - go and find out what. Don't let them away with it.

## What I took from this

The number that looked wrong was the truest number on the page. My instinct said "a cache buster can't cost 18 seconds", and my instinct was measuring the plugin's *purpose* rather than its *behaviour*. It sends HTTP requests inline during the request your customer is waiting on; whether those requests are "just purges" is irrelevant to the person watching the spinner. Measure behaviour, not intent - and when a measurement offends your intuition, pull the callers before you dismiss it, because that's exactly where the good stuff hides.
