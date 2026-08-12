# Checkout and order flow analysis

WooCommerce only. The one measurement in the plugin that involves a real purchase, and the only
run type that sends real email.

### Analyse checkout & order flow
**Since:** 0.11.0, 7 August 2026 (renamed and extended to order management in 0.17.0, 11 August 2026)

Measures the server time your customer waits through at **every step of one real purchase**,
with every plugin on your site active, split at the payment boundary so time that risks the sale
is shown separately from time after the money is in. The button sits on the admin bar and the
Pages tab.

Since 0.17.0 it continues past the purchase into order management: it opens the order in
wp-admin and marks it completed - the two things a shop owner does most - so you can see how
slow your order screen is and what marking an order completed sets off (the completed-order
email, stock and downloads, and every plugin hooking order completion). That time is shown in
its own section below the customer's wait, because it is staff time per order rather than
something a customer waits through.

### The pre-flight tells you what it will set off, before anything is created
**Since:** 0.11.0, 7 August 2026

A pre-flight panel lists exactly which emails, webhooks and plugins the run will trigger before
anything is created, and since 0.17.0 it lists the order view and completion steps too. The
order is cancelled and deleted afterwards with stock restored. On stores that disallow guest
checkout, the customer account WooCommerce creates for the purchase is deleted with the order,
the pre-flight discloses it, and an hourly janitor sweeps any that a crashed run left behind
(0.11.2).

`wp sspa checkout-flow --dry-run` prints the pre-flight without buying anything.

### Both checkouts: block and shortcode
**Since:** 0.11.0 (block), 0.11.1, 7 August 2026 (shortcode)

Measures the classic `[woocommerce_checkout]` shortcode checkout as well as the block checkout,
so a store on the shortcode is not told "only the block checkout is supported so far".

### The payment boundary is stamped where the money is taken
**Since:** 0.11.2, 7 August 2026

The boundary is stamped at entry to `payment_complete()`, so order emails, cache purges and
integrations hanging off the status transition are reported as post-payment confirmation time
rather than as time that risks the sale.

### Real email, timed
**Since:** 0.11.0, 7 August 2026

Order emails are sent for real during a checkout flow run so your mail server's own time is
measured. **Every other kind of run still sends nothing.** The completed-order email in the
management sequence is timed or intercepted exactly as the other order emails are, following
your mail setting (`deliver`, `construct` or `suppress`; default `deliver`).

Emails sent through a mail plugin that replaces WordPress delivery - Mailgun's HTTP mode and
similar - are all counted and reported as **untimed**, with their real cost visible under
outbound calls, instead of showing as one message in 0ms (0.11.2).

### Checkout findings
**Since:** 0.11.0, 7 August 2026

`checkout_slow_step`, `checkout_component_cost`, `checkout_blocking_http`, `checkout_mail_inline`
and `checkout_dupe_queries`. Blocking HTTP is reported as one finding per plugin per step with
the call count, total time and the worst call, rather than one finding per call (0.11.2).

Outbound calls show their method, query-string keys and calling function, so a purge of `/?p=123`
no longer displays as a fetch of the bare homepage. Only the values of safe routing keys (`p`,
`page_id` and similar) are kept - never anything else.

### Misbehaviour signatures, matched by behaviour
**Since:** 0.11.3, 7 August 2026

Deterministic signatures matched by what a plugin does rather than by its name, so white-labelled
and hosting-installed forks are caught identically:

- **`checkout_purge_order_pages`** - a cache plugin "purging" the order itself. With HPOS that is
  a request to `/?p=<order id>`, which renders a slow 404, fired on every order note.
- **`checkout_amp_purge_missing`** - purging `/amp/` pages on a site with no AMP plugin, each one
  a full 404 render.
- **`checkout_self_fetch_failed`** - the site calling its own URL and timing out while the
  customer waits.

Each finding names the calling plugin and the specific fix.

<!-- internal -->
These came out of a real diagnosis - a 22-second checkout caused by nginx-helper purging pages
that do not exist. **The origin story is NOT publishable**: it was Dave's own misconfigured
site. Describe the three signatures as features freely; never cite where they came from until
the same fault is found on a client site.

### The flow's own instrumentation is excluded from the numbers
**Since:** 0.11.2, 7 August 2026

The checkout flow's own pre-flight and cleanup steps used to leak into the component roll-up,
the outbound-call list and the mail totals, which could overstate a cache-purge plugin's cost by
a quarter. They are now excluded.

### Blocked or partial management sequences are reported honestly
**Since:** 0.18.0, 12 August 2026

A management sequence blocked by a security plugin, or that only got half way, is reported and
shared as `blocked` or `partial` rather than quietly left out. Outcomes are
complete / partial / blocked / failed.

### Configured, or sensible unconfigured
**Since:** 0.11.0, 7 August 2026

Every setting has a working default so the button does something sensible on a store that has
never opened them: the cheapest purchasable in-stock simple product, quantity 1, the store's base
country with a valid postcode for it, and `admin_email` tagged `+sspa-perf-<run_id>`. Integrations
and webhooks are allowed by default and can be switched off. A consent flag records that the
disclosure was accepted.

<!-- internal -->
Not built, and the code is explicit about it: `SSPA_Checkout_Flow::PAYMENT_MODES` is deliberately
EMPTY, so the `sandbox` payment mode is never offered and `pm=s` cannot enable a payment. The
only working mode is `no_payment`; `live_declined` is reserved and unbuilt. That is doc task T15
(gateway adapters). T13, per-plugin checkout isolation, is also not built - Plugin Impact
Analysis does not run over checkout steps. Do not imply either on a sales page.
