# WooCommerce and commerce flows

## What is WooCommerce-specific

| Feature | Needs WooCommerce | Note |
|---|---|---|
| Shop, product and cart/checkout pages profiled | Yes | Profiled alongside front-end and wp-admin pages |
| Write profiles stepping an order through its statuses | Yes | Temporary order, deleted after; no mail sent |
| Checkout & order flow analysis | Yes | The whole feature. `wp sspa checkout-flow` errors out plainly when WooCommerce is not active |
| Order-count cohort dimensions | Yes | Total and 30-day order bands; absent on non-Woo sites |
| WooCommerce template hook timing in the render breakdown | Yes | Shop loop, product summaries, sidebar |
| Everything else | No | Profiling, attribution, phases, `EXPLAIN`, plugin impact analysis all work on any WordPress site |

**No minimum WooCommerce version is declared** in the plugin header or `readme.txt`. Verified
against WooCommerce 10.9.1 (when the checkout spec was written) and 11.0.0 in the docker harness.
Feature detection is by `class_exists` / `function_exists` throughout rather than by version
comparison, so state "verified against 10.9-11.0" rather than inventing a floor.

## HPOS

**Both storage backends are handled**, checked at runtime rather than assumed:

- Order edit URLs are built per backend - `OrderUtil::custom_orders_table_usage_is_enabled()`
  decides, and the HPOS admin URL differs from the legacy post-edit one.
- Order meta cleanup checks for `wp_wc_orders_meta` and uses it where present, rather than
  assuming a layout.
- Order deletion uses `$order->delete(true)`, which is HPOS-safe.
- Order counts take the HPOS route where enabled and WordPress's maintained count otherwise; the
  30-day window relies on the indexed date column that exists under both (`type_status_date` /
  `date_created`).

HPOS also changes a diagnosis: with HPOS, an order's "URL" is `/?p=<order id>`, which renders a
slow 404. That is exactly what the `checkout_purge_order_pages` signature catches, and the
plugin records whether HPOS is on so a shared management timing is comparable only against sites
on the same backend.

## Both checkouts

The block checkout and the classic `[woocommerce_checkout]` shortcode are both measured (block
0.11.0, shortcode 0.11.1). A store on the shortcode is not told the feature is unsupported.

<!-- internal -->
The shortcode path was harder than the spec expected and the gotcha is worth keeping: WooCommerce
filters `nonce_user_logged_out` to return the cart session's customer id for any action whose
name starts with `woocommerce`. So `woocommerce-process_checkout` must be bound to the LOOPBACK
visitor's cart session id, which does not exist until add-to-cart has run and comes back in the
`wp_woocommerce_session_*` cookie - while `update-order-review` does not start with `woocommerce`
and must NOT be rebound. Full detail in `.docs/2026-08-07-checkout-flow-profiling.md`.

## Payment

Only `no_payment` works. `SSPA_Checkout_Flow::PAYMENT_MODES` is deliberately **empty**, so
`sandbox` is never offered and cannot be forced via the query argument; `live_declined` is
reserved and unbuilt. No gateway adapter exists. This is unbuilt task T15, not a limitation of
any particular gateway.

## Mail

The checkout flow is the **only** run type that sends real email, and it does so deliberately so
the mail server's own time is measured. Controlled by `checkout_mail_mode`: `deliver` (default),
`construct` or `suppress`. Every other run type constructs and discards - recipients are stripped
before any transport work.

Mail plugins that replace WordPress delivery entirely (Mailgun's HTTP mode and similar) cannot be
timed at the `wp_mail` boundary. Those messages are counted and reported as **untimed**, with
their real cost visible under outbound HTTP calls, rather than shown as one message in 0ms.

## Guest checkout

On stores that disallow guest checkout, WooCommerce creates a customer account for the purchase.
That account is deleted with the order, the pre-flight discloses it before anything is created,
and an hourly janitor sweeps any left behind by a crashed run.

## Stock, webhooks and integrations

The order is cancelled and deleted afterwards with stock restored. Integrations and webhooks are
allowed by default (`checkout_allow_integrations`, `checkout_allow_webhooks`) because switching
them off would not measure the site the customer actually experiences; both can be turned off,
and the pre-flight lists exactly what a run will set off before anything is created.
