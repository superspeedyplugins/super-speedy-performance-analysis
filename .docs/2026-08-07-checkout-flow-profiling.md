# Checkout flow profiling - implementation spec

Written 2026-08-07 against plugin 0.10.12, WooCommerce 10.9.1, WordPress 6.2+.
Status: designed, nothing built.

This doc is written to be built from directly. Part A is the rules and the verified facts. Part B is
the build, as numbered tasks with signatures, code and acceptance criteria. Part C is reference
material (payloads, keys, error codes). Build the tasks in order; each one is independently
testable.

---

# Part A - what this is and what is already true

## A1. The purpose, in one sentence

**Measure what a customer experiences while buying something: the server time they wait through, at
each step of the purchase funnel, with the site's full plugin set active and nothing switched off.**

A twenty-second checkout costs the store owner money. This feature finds out where those twenty
seconds go.

Everything follows from that sentence, including the scope rule below. When a design question comes
up that this doc does not answer, answer it by asking "does the customer wait for this?".

## A2. The scope rule: the customer's wait, nothing else

**If it does not happen before the response is sent to the customer, it is out of scope.**

Concretely:

- Work queued to Action Scheduler or WP-Cron during checkout is **out of scope**. If that background
  work is slow, that is a different problem for a different tool. What IS in scope is the small
  in-request cost of queueing it.
- Whether a store does a piece of work in-request or defers it is **the most valuable thing this
  feature can tell someone**. "Your order emails are sent inside the checkout request and add 1 240
  ms to your customer's wait" versus "your order emails are deferred and cost your customer
  nothing" is the same store with two very different checkouts.
- Browser-side cost (JS, hydration, third-party tags) is out of scope. This measures server
  generation time. Label every number "server time" so it is never confused with a RUM figure.
- The payment provider's own latency is out of scope, because we never place a real charge (A6).

## A3. Why the existing machinery cannot do this

`SSPA_Catalogue::build()` profiles `wc-cart` and `wc-checkout` as static GETs
(`includes/class-sspa-catalogue.php:68-73`), which measures rendering an EMPTY cart to a visitor
with no session. On a block checkout the real cost is in POSTs the crawler never sends
(`cart/add-item`, `cart/update-customer`, `cart/select-shipping-rate`, `checkout`); on a classic
checkout it is behind `?wc-ajax=update_order_review` and `?wc-ajax=checkout`
(`includes/class-wc-ajax.php:598` calls `WC()->checkout()->process_checkout()`).

Deep analysis is the opposite of what is wanted here: it switches plugins off. This leaves
everything on.

## A4. One purchase per run

No warm-up, no repeat samples, no "run it 3 times" option in the UI. A full checkout is heavy
enough that variance is not the dominant error term, and every repeat is another real order.

Consequences, which the UI must state rather than hide:

- there is no median and no spread, so the noise gate
  (`includes/class-sspa-run-controller.php:800-818`) cannot be computed. Nothing in phase 1
  compares two flows, so nothing needs it,
- the panel says "single measured purchase", never "median of N",
- first-run effects (cold opcache, cold transients) land in the measurement. Arguably that is what
  the store's next real customer gets, but two consecutive runs can differ. Say so in the panel.

`--repeats` stays on the CLI only, because phase 2 (plugin isolation, T13) needs spread.

## A5. Suppress nothing by default

Integrations firing on order creation are frequently the reason a checkout is slow. A run that
suppresses them measures a store nobody has. So the default is: real order, real emails, real
integrations.

The price is that a real order really happens, so the admin is shown exactly what it will set off
**before** it happens (T5, the pre-flight), and given switches to turn parts off if they want. Any
switch used is recorded in the run notes so a partial run cannot later be mistaken for a full one.

## A6. Payment: three modes, chosen in the pre-flight

Where the payment step is concerned there is a trade-off no single mode wins, so the flow offers
three and the admin picks one in the pre-flight panel. What each mode can and cannot see:

| Mode | Needs | Measures the gateway round trip | Measures the post-payment cascade | Available on |
|---|---|---|---|---|
| `no_payment` (default) | nothing | **no** | **yes**, in full | any store |
| `sandbox` | the gateway in test mode | **yes**, against the sandbox endpoint | **yes**, in full | dev and staging |
| `live_declined` | live keys, a declining card | yes, against the production endpoint | **no** - the order fails, so the cascade never runs | see A6.3, mostly blocked |

The two things that cost real time are the **gateway round trip** (a blocking HTTP call to the PSP,
typically the single largest outbound call in the whole flow) and the **post-payment cascade**
(`payment_complete()`, emails, stock, every integration hooked on it). `no_payment` gets the second,
`sandbox` gets both, `live_declined` gets the first and then stops. Sandbox is therefore the best
measurement available and should be preferred whenever the store is in test mode; `no_payment` is
the default because it is the only one that works everywhere with no configuration.

**`no_payment`: how it works.** An earlier draft used a 100% discount coupon to take the total to zero, because
`WC_Order::needs_payment()` is `has_status(...) && get_total() > 0` and a zero-total order skips the
gateway entirely. That mechanism is real and verified (A7, fact 3), but it was rejected. The
reasoning is recorded in A6.1 because it is exactly the kind of decision an implementer will
otherwise reopen.

Both of the relevant checks are filterable, so the same result is achieved with nothing created at
all:

```php
add_filter( 'woocommerce_order_needs_payment', '__return_false' );   // class-wc-order.php:1856
add_filter( 'woocommerce_cart_needs_payment',  '__return_false' );   // class-wc-cart.php:1554
```

applied **only on the flow's own profiled request** (gated on the token flag, T3). This is strictly
better than the coupon:

- nothing is created, so nothing needs cleaning up,
- the cart totals are untouched, so shipping and tax calculation run for real and are fully
  measured,
- no coupon lookup, validation or usage-count work pollutes the numbers,
- it works identically on block and classic checkouts (classic reads
  `woocommerce_cart_needs_payment` at `class-wc-checkout.php:1380`, block reads
  `$order->needs_payment()` at `Checkout.php:682`).

The checkout then takes the `process_without_payment()` branch, which calls
`$order->payment_complete()` (`src/StoreApi/Utilities/CheckoutTrait.php:70-78`) and runs the entire
post-payment cascade: status transition, stock reduction, `woocommerce_payment_complete`, order
emails, and every integration hooked onto those. That is the expensive part, and it is measured in
full.

Blind spot of this mode, which goes in the result panel next to the place-order number whenever
`no_payment` was used: **the payment provider's own latency is not included.** If checkout is slow
because Stripe is slow, this mode will not show it. Everything the store does around the payment is
shown. Sandbox mode (A6.2) is the answer where it is available; do not print this caveat on a
sandbox run, where it is untrue.

For reference, since the terms come up: an *offline gateway* is one of Woo's built-in no-processor
methods (`cod`, `bacs`, `cheque`), only usable if the store already has one enabled, otherwise using
one means changing the store's live settings. A *synthetic gateway* would be a gateway class the
plugin registers for itself. Neither is needed. Do not build either.

### A6.1 Rejected alternative: a coupon plus a shipping-cost filter

The coupon route can be made to work. A 100% product discount does not by itself zero the total when
shipping is charged, and giving the coupon `free_shipping` is not an acceptable fix, because that
leaves a genuine free-shipping capability sitting in the customer's store for as long as the coupon
exists. The correct version is a filter, scoped to the flow's own request, that zeroes the rates
after they have been calculated:

```php
add_filter( 'woocommerce_package_rates', function ( $rates ) {   // class-wc-shipping.php:398
    foreach ( $rates as $rate ) { $rate->set_cost( 0 ); $rate->set_taxes( array() ); }
    return $rates;
}, PHP_INT_MAX );
```

`woocommerce_package_rates` fires after every shipping method has calculated, so zone matching,
method instantiation and live carrier API calls all still happen and are still measured. Only the
resulting number is zeroed. That part of the idea is sound.

It was still rejected, for three reasons in increasing order of importance:

1. **It is not terminal.** Shipping is not the only thing that can lift a total off zero. Fees added
   on `woocommerce_cart_calculate_fees` (`includes/class-wc-cart.php:2205`) would need the same
   treatment, and so would whatever the next plugin invents. The `needs_payment` filters cannot be
   defeated by anything a plugin adds.
2. **It creates an object in the customer's store.** Even with a random code, usage limit 1, an
   expiry and individual-use set, a 100% discount coupon exists in their store for the duration of
   the run, and a crashed run leaves it there until the janitor sweeps. The `needs_payment` filters
   create nothing, exist only for the lifetime of a signed, single-use, expiring loopback request,
   and cannot be left behind by a crash. Given the instruction not to leave gaps in customers'
   stores, creating nothing beats creating something and cleaning it up well.
3. **A £0 order can make integrations do LESS work, which under-measures the thing we are here to
   measure.** Fraud screening, accounting sync, affiliate commission and tax-commit integrations
   commonly short-circuit on zero-value orders. With the `needs_payment` filters the order total
   stays real (£47 stays £47), so every downstream integration sees a normal order and does its
   normal work; the only thing skipped is `process_payment()`. This is a known pattern in
   integration plugins rather than something measured on a specific plugin here, so treat it as a
   risk to avoid rather than a proven fact - but it is a risk with no upside, since the coupon buys
   us nothing the filters do not.

If this decision is ever revisited, the counter-argument to weigh is that "total £47, needs_payment
false" is a state WooCommerce would not itself produce, whereas a zero-total order is. Nothing in
the checkout path cares, but a plugin calling `$order->needs_payment()` for its own reasons would
see our answer.

### A6.2 Sandbox mode: the best measurement, where it is available

A store in test mode can be driven through a genuine payment, which is the only way to get both the
gateway round trip and the post-payment cascade in one measurement. This is the mode to prefer on
any dev or staging site, and it is worth building because that is where most people will first run
this.

**It is a per-gateway adapter, not a generic feature.** Three things differ per gateway: how to tell
it is in test mode, what to send as the payment instrument, and which `payment_data` key to send it
under. So define a small interface and implement gateways one at a time:

```php
interface SSPA_Gateway_Adapter {
    public function gateway_id();                 // 'stripe', 'ppcp-gateway', ...
    public function is_test_mode();               // bool
    public function payment_data();               // [ ['key' => ..., 'value' => ...], ... ]
    public function label();                      // "Stripe (test mode), test Visa"
    public function docs_url();                   // where the test instrument came from
}
```

Unknown gateway, or one not in test mode: sandbox mode is not offered for that store and the panel
says why. Never guess a payment payload.

**The blocker to understand before writing any of this.** Modern gateways tokenise card details
**in the browser**. Stripe's Payment Element sends the card to Stripe from the customer's browser
and hands WooCommerce a payment-method id; the raw card number never reaches the server. A loopback
flow has no browser, so it has no way to tokenise a card, and Stripe restricts raw-card-data APIs by
default. This is not a policy problem, it is an architectural one, and it is why the adapter returns
a **pre-made instrument** rather than a card number.

For Stripe that instrument exists: Stripe publishes predefined test payment-method tokens
(`pm_card_visa`, `pm_card_chargeDeclined` and others) that work server-side with test keys. So the
Stripe adapter sends `pm_card_visa` and never handles a card number at all. Gateways without an
equivalent cannot support sandbox mode through a server-side flow, and the adapter for them should
not be written.

**Verify before implementing (nothing gateway-specific could be checked while writing this - no
payment gateway is installed on the dev site):**

- the option name and test-mode key for each gateway (for the official Stripe plugin, believed to be
  `woocommerce_stripe_settings` with `testmode` = `yes`, unconfirmed),
- the exact `payment_data` key the gateway's Store API integration expects for a payment-method
  token,
- that `pm_card_visa` is accepted by the current WooCommerce Stripe plugin's `process_payment()`,
- as a generic fallback for unknown gateways, whether `$gateway->get_option('testmode')` or
  `sandbox` is set, which many `WC_Payment_Gateway` subclasses expose. Treat a generic hit as
  "possibly test mode" and make the admin confirm, never as licence to send a payment.

Do not store card numbers in the plugin for gateways that need them. If an adapter would require
posting a PAN, do not write that adapter.

### A6.3 Live gateway with a deliberately declining card

The question was whether running a test card against live keys, knowing it will be declined, buys
anything over `no_payment`. The honest answer is that it buys one thing and loses a bigger one.

**What it gains.** The real production gateway endpoint's latency from this server. A sandbox
endpoint runs on different infrastructure and does not necessarily represent it, so this is the only
mode that answers "how far away is our PSP in production".

**What it loses.** A declined payment fails the order, so `payment_complete()` never runs and the
entire post-payment cascade - emails, stock, every integration - is not measured. That is usually
the larger half of the place-order step. So it is not additive with `no_payment`, it is a different
slice: `no_payment` gets everything except the gateway call, `live_declined` gets up to and
including the gateway call and then stops.

**Why it is mostly not implementable anyway.** Same tokenisation problem as A6.2, with the escape
hatch removed: Stripe's `pm_card_*` test tokens only work with test keys, and a live key will not
accept them. With no browser to tokenise a real card, and no willingness to handle a PAN
server-side, there is nothing to send. It remains possible only for older direct gateways on a
classic checkout that accept raw posted card fields, which is a shrinking set.

**And the risks are real where it is possible.** Repeated declined attempts against a live merchant
account are failed payment attempts on the merchant's record; card networks monitor decline rates
and fraud tooling such as Stripe Radar scores repeated declines. A one-off is harmless, a scheduled
run is not.

**Decision.** Do not build `live_declined` in any early phase. Keep the mode name reserved in the
settings enum so the option exists if a specific gateway later justifies it. If the live endpoint's
latency is genuinely wanted, a far cheaper and completely safe substitute is a timed TLS handshake
to the gateway's API host during the flow, reported as "your PSP is 340 ms away from this server"
and clearly labelled network reachability rather than payment processing.

### A6.4 The insight that changes the report

The reasoning behind the question above is worth more than the answer, and it belongs in the output:
slowness **before** the money is captured risks the sale, slowness **after** it is captured is a bad
impression but the money is in. Those are different problems with different value, and a single
total hides the distinction.

So the waterfall splits at the payment boundary (T9): time the customer can still abandon during,
and time after the sale is secured. On a store where the damage is all post-capture, the honest
advice is different from one where the customer waits eight seconds staring at a spinner before the
card is charged.

## A7. Verified facts (safe to build on)

Each of these was read out of the source named. They are the load-bearing assumptions.

1. **The mu-loader arms the profiler for ANY request carrying a valid token**, keyed on
   `REQUEST_URI` (`mu/sspa-loader.php:13,45-49`). Nothing about it is GET-specific: REST and
   `?wc-ajax=` requests are armed exactly the same way. No change needed for POST support.
2. **A valid `Cart-Token` header removes the Store API's nonce requirement entirely.**
   ```php
   // woocommerce/src/StoreApi/Routes/V1/AbstractCartRoute.php:224
   protected function requires_nonce( \WP_REST_Request $request ) {
       return $this->is_update_request( $request ) && ! $this->has_cart_token( $request );
   }
   ```
   and `CartTokenUtils::get_cart_token( string $customer_id )` is a public static that mints one
   server-side (`src/StoreApi/Utilities/CartTokenUtils.php:22`). So the flow needs no nonce and no
   cookie jar for the block path.
3. **A zero-payment order takes the no-gateway branch.**
   `Checkout.php:682-686` branches on `$this->order->needs_payment()` into
   `process_without_payment()`, which calls `$order->payment_complete()`
   (`CheckoutTrait.php:70-78`). `payment_method` is optional in the route args
   (`Checkout.php:124`).
4. **WordPress's entire `shutdown` action completes before the capture writes.** Core registers
   `shutdown_action_hook` at `wp-settings.php:166`; mu-plugins load at line 497; PHP runs shutdown
   functions in registration order. So anything on `shutdown` (inline webhook delivery, deferred
   email dispatch) lands inside the measurement, exactly as the capture's header comment claims.
5. **WooCommerce webhooks are queued in-request and delivered asynchronously by default**
   (`includes/wc-webhook-functions.php:16-45`, `woocommerce_webhook_deliver_async` defaults true).
   Per A2 the delivery is out of scope; the queueing cost is in-request and is measured. Only if a
   store filters that hook to false is delivery inline, and then it is measured too.
6. **Transactional email dispatch is in-request or deferred depending on a feature flag**
   (`includes/class-wc-emails.php:129-141`, `woocommerce_defer_transactional_emails` /
   `deferred_transactional_emails`). Detect it and report it: it is the difference between emails
   costing the customer 1.2 s and costing them nothing.
7. **The `wp_mail` filter runs before `pre_wp_mail`** (`wp-includes/pluggable.php:209` versus
   `:233`), so timing hooked on `wp_mail` also covers API-based mail plugins (SendGrid, Mailgun,
   Postmark) that short-circuit `pre_wp_mail` and never touch PHPMailer. `wp_mail_succeeded` and
   `wp_mail_failed` exist at `:651,665`.
8. **`wp_create_nonce()` binds to the current request's session token**, read from
   `$_COOKIE[LOGGED_IN_COOKIE]` via `wp_get_session_token()`. Minting a checkout nonce inside the
   admin's AJAX request produces a nonce bound to the admin's session, which a logged-out loopback
   request cannot use. Only relevant to the classic path (T12).
9. **`POST /wc/store/v1/checkout` is rate limited to 3 per 60 s when the `rate_limit_checkout`
   feature is enabled** (`src/StoreApi/Authentication.php:186-205`). One purchase per run is inside
   that; CLI `--repeats` is not.
10. **Every "latest analysis" query filters `run_type IN ('baseline','spot')`** (6 call sites,
    e.g. `includes/admin/tabs/overview.php:10`). A new `checkout` run type therefore cannot
    masquerade as a site analysis, the same protection `adhoc` already gets. Do not add a
    `checkout` run type to those queries.
11. **`sspa_profiles.method` already exists** as `varchar(8)` defaulting to GET and has never been
    populated with anything else (`includes/class-sspa-schema.php:55`). No schema change is needed
    for this feature. Do not bump `SSPA_Schema::DB_VERSION`.

## A8. Verify before relying on (NOT yet confirmed)

Do these checks as part of the task that needs them. Do not write code that assumes an answer.

1. **T7:** that cancelled-then-deleted restores stock in Woo 10.9, for managed and unmanaged stock.
   Assert it in the test case; if it does not, restore explicitly from the snapshot.
2. **T6:** the exact required body of `POST /wc/store/v1/checkout` including country-conditional
   address fields. Read `src/StoreApi/Schemas/V1/BillingAddressSchema.php` and
   `AddressSchema.php` rather than guessing; C1 has the field list as observed.
3. **T5:** whether Woo 10.9 exposes a supported helper for block-versus-classic checkout detection.
   Prefer a core helper over `has_block()` against page content, because a block theme template can
   supply the block without it appearing in `post_content`.
4. **T4:** that `wp_remote_request()` round-trips WooCommerce's `wp_woocommerce_session_*` cookie
   intact. Only needed for the classic path (T12).
5. **T6:** whether this install's permalinks 301 pretty `/wp-json/` paths, which kills POSTs. The
   repo already hit this with the hub client (`.tests/README.md`). Mitigation is in C2.

---

# Part B - the build

Tasks are ordered. T1 to T11 are phase 1 and deliver the feature for block checkouts. T12 and T13
are later phases and must not block phase 1.

## T1. Transport: let the crawler send a profiled POST

**File:** `includes/class-sspa-crawler.php` (add a public method; keep the existing
`profile_job()` and `profiled_request()` untouched).

The existing `send()` calls `wp_remote_get()` and `profiled_request()` follows redirects, both of
which are wrong for a POST (a followed 3xx becomes a GET and silently measures the wrong thing).
Add a sibling that shares the private helpers already in the class (`bust_url()`,
`lower_headers()`, `fetch_capture()`, `discard_capture()`).

```php
/**
 * Send one profiled request of any method and return the sample plus the parsed body.
 * No redirect following: a 3xx here is a failure, not a hop.
 *
 * @param string $url    Absolute URL on this site.
 * @param array  $args   {
 *     @type string $method   GET|POST. Default GET.
 *     @type array|string $body  Array for form encoding, array for JSON when json=true.
 *     @type bool   $json     Send the body as application/json. Default false.
 *     @type array  $headers  Extra request headers, e.g. ['Cart-Token' => '...'].
 *     @type array  $cookies  Cookie jar to send (name => value or WP_Http_Cookie[]).
 *     @type array  $flags    Token flags, e.g. ['v' => 'guest', 'ck' => 'flow', 'mail' => 'd'].
 * }
 * @return array {
 *     wall_ms, code, cached, blocked_by, error, capture   - the sample shape
 *     SSPA_Profile_Store::save() expects, plus:
 *     body    string raw response body
 *     json    array|null decoded JSON body
 *     cookies array response cookies, to feed the next request
 * }
 */
public function send_profiled( $url, $args = array() ) {
    $method  = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'GET';
    $flags   = isset( $args['flags'] ) ? $args['flags'] : array();
    $hop_url = $this->bust_url( $url );          // guarantees a cache MISS; token signs the query
    $token   = SSPA_Token::mint( $hop_url, $flags );

    $headers = array( 'Cache-Control' => 'no-cache', SSPA_Token::HEADER => $token['header'] );
    if ( ! empty( $args['headers'] ) ) {
        $headers = array_merge( $headers, $args['headers'] );
    }
    $body = isset( $args['body'] ) ? $args['body'] : null;
    if ( ! empty( $args['json'] ) ) {
        $headers['Content-Type'] = 'application/json';
        $body = wp_json_encode( $body );
    }

    $start    = microtime( true );
    $response = wp_remote_request( $hop_url, array(
        'method'      => $method,
        'timeout'     => 60,
        'redirection' => 0,
        'sslverify'   => false,
        'headers'     => $headers,
        'body'        => $body,
        'cookies'     => isset( $args['cookies'] ) ? $args['cookies'] : array(),
    ) );
    $wall_ms = ( microtime( true ) - $start ) * 1000;

    $sample = array(
        'wall_ms' => round( $wall_ms, 1 ), 'code' => 0, 'cached' => false,
        'blocked_by' => null, 'error' => null, 'capture' => null,
        'body' => '', 'json' => null, 'cookies' => array(),
    );
    if ( is_wp_error( $response ) ) {
        $sample['error'] = $response->get_error_code();
        $this->discard_capture( $token['id'] );
        return $sample;
    }

    $sample['code']    = (int) wp_remote_retrieve_response_code( $response );
    $sample['body']    = (string) wp_remote_retrieve_body( $response );
    $sample['json']    = json_decode( $sample['body'], true );
    $sample['cookies'] = wp_remote_retrieve_cookies( $response );
    $headers_lc        = $this->lower_headers( $response );

    $sample['blocked_by'] = SSPA_Security_Detect::classify(
        $sample['code'], $headers_lc, substr( $sample['body'], 0, 20000 ), ! empty( $args['cookies'] )
    );
    if ( $sample['blocked_by'] ) {
        return $sample;
    }

    // Canary: the mu-loader echoes our token id. Missing means a cache answered, or the
    // mu-loader is not installed.
    $canary = isset( $headers_lc['x-sspa-profiled'] ) ? $headers_lc['x-sspa-profiled'] : null;
    if ( $canary !== $token['id'] ) {
        $sample['error'] = 'no_canary';
        return $sample;
    }
    $sample['capture'] = $this->fetch_capture( $token['id'] );
    if ( ! $sample['capture'] ) {
        $sample['error'] = 'capture_missing';
    }
    return $sample;
}
```

**Also in T1, one line elsewhere.** `SSPA_Profile_Store::save()` hard-codes `'method' => 'GET'`
(`includes/class-sspa-profile-store.php:79`). Change it to:

```php
'method' => isset( $result['method'] ) ? $result['method'] : 'GET',
```

**Acceptance.** In the docker environment, `send_profiled( rest_url('wc/store/v1/cart'), [] )`
returns `code` 200, a non-null `capture`, and `json` containing an `items` key.

## T2. Capture: a third mail mode that lets mail actually go

**File:** `profiler/class-sspa-capture.php`.

Today the capture guarantees no profiled request ever sends real mail (`:51-64`). That is correct
for page profiling and stays the default for every other run type. The checkout flow needs a third
option, because construct mode measures the wrong half: it builds the message (template cost) but
strips the recipients before transport, so a slow SMTP handshake - a classic reason a checkout
takes four seconds, and squarely in scope by A2 - is invisible.

| `mail` flag | Mode | Measures |
|---|---|---|
| absent | `suppress` | Call count only |
| `c` | `construct` | Template rendering and mail-plugin setup. **Not** the SMTP handshake |
| `d` | `deliver` (new) | The whole thing, transport included |

In `__construct()`, extend the mode selection:

```php
if ( isset( $flags['mail'] ) && 'c' === $flags['mail'] ) {
    $this->mail_mode = 'construct';
} elseif ( isset( $flags['mail'] ) && 'd' === $flags['mail'] ) {
    $this->mail_mode = 'deliver';
}
```

In `arm()`, add the deliver branch **before** the existing construct/suppress branches. It must not
alter the message in any way - timing only, so the measurement is of the real thing:

```php
} elseif ( 'deliver' === $this->mail_mode ) {
    // Timing only, nothing intercepted. The wp_mail filter runs BEFORE pre_wp_mail
    // (pluggable.php:209 vs :233), so this also times API mailers that never touch PHPMailer.
    add_filter( 'wp_mail', array( $this, 'mail_deliver_start' ), 1 );
    add_action( 'wp_mail_succeeded', array( $this, 'mail_deliver_end' ) );
    add_action( 'wp_mail_failed', array( $this, 'mail_deliver_end' ) );
}
```

`mail_deliver_start( $atts )` stamps `microtime(true)` and the trigger frames into
`$this->mail_pending` and returns `$atts` unchanged. `mail_deliver_end()` appends to
`$this->mail_calls` with the elapsed time and clears the pending entry. Reuse the shapes
`mail_construct_end()` already writes so `collect_mail()` needs no change beyond reporting the mode.

**Safety note for the implementer.** This deliberately opens a hole in an invariant the plugin
otherwise keeps absolutely. `mail=d` must only ever be reachable from the checkout flow, whose
tokens are only minted after an administrator accepted the disclosure (T8). Do not offer `mail=d`
anywhere else, and do not make it the default of any other run type.

**Also in T2: request marks.** The report needs to know WHEN inside the place-order request the
payment boundary fell (A6.4), and the capture has no way to carry a point-in-time marker today. Add
a tiny generic section to the payload in `finalize()`, next to `boot` and `profile`:

```php
// Point-in-time marks in ms since $timestart, set by whatever is driving the request.
// Generic on purpose: the capture does not know or care what a mark means.
'marks' => ( isset( $GLOBALS['sspa_marks'] ) && is_array( $GLOBALS['sspa_marks'] ) )
    ? array_map( function ( $ms ) { return round( $ms, 1 ); }, $GLOBALS['sspa_marks'] )
    : null,
```

The flow sets `$GLOBALS['sspa_marks']['payment_complete']` from T3. Nothing else writes marks yet.

**Acceptance.** A profiled request with `mail=d` that calls `wp_mail()` to a real address delivers
it, and the capture's `mail.calls[0].construct_ms` is greater than zero. With `mail=c` the same
request delivers nothing. A request that sets `$GLOBALS['sspa_marks']['x'] = 12.3` produces
`capture['marks']['x'] === 12.3`; one that sets nothing produces `capture['marks'] === null`.

## T3. The no-payment filter, gated on the flow flag

**File:** `includes/class-sspa-checkout-flow.php` (new; this is the class T6 fills in).

Register a lightweight hook that runs on every request but does nothing unless the request is one of
the flow's own profiled requests. Same gating pattern as `SSPA_Probes`: the flags are in
`$GLOBALS['sspa_flags']`, set by `profiler/bootstrap.php:11`.

```php
class SSPA_Checkout_Flow {

    /** Called from the main plugin file, unconditionally. */
    public static function register() {
        add_action( 'plugins_loaded', array( __CLASS__, 'maybe_arm_request' ), 1 );
    }

    private static function flag( $key ) {
        return ( isset( $GLOBALS['sspa_flags'][ $key ] ) ) ? $GLOBALS['sspa_flags'][ $key ] : null;
    }

    /**
     * Runs INSIDE a profiled flow request. See doc A6.
     */
    public static function maybe_arm_request() {
        if ( 'flow' !== self::flag( 'ck' ) ) {
            return;
        }

        // One purchase per run stays inside Woo's checkout rate limit, but a CLI --repeats run
        // does not. Signed, single-use, expiring loopback requests only; real traffic unaffected.
        add_filter( 'woocommerce_store_api_rate_limit_options', function ( $options ) {
            $options['enabled'] = false;
            return $options;
        }, PHP_INT_MAX );

        // The payment-boundary mark, used by the report to split at-risk time from post-capture
        // time (A6.4). Set in EVERY payment mode - in sandbox mode this is the moment the gateway
        // came back successful, which is exactly the boundary we want.
        add_action( 'woocommerce_payment_complete', function () {
            if ( ! isset( $GLOBALS['sspa_marks']['payment_complete'] ) && isset( $GLOBALS['timestart'] ) ) {
                $GLOBALS['sspa_marks']['payment_complete'] = ( microtime( true ) - $GLOBALS['timestart'] ) * 1000;
            }
        }, PHP_INT_MIN );

        // Skip the gateway WITHOUT touching cart totals, so shipping and tax still calculate in
        // full and are measured. Only in no_payment mode: in sandbox mode the whole point is that
        // the payment really happens.
        // Whitelist, not blacklist: only a mode that is explicitly meant to take a payment is
        // allowed to. A missing, unknown or malformed flag falls through to no payment.
        if ( ! in_array( self::flag( 'pm' ), self::PAYMENT_MODES, true ) ) {
            add_filter( 'woocommerce_order_needs_payment', '__return_false', PHP_INT_MAX );
            add_filter( 'woocommerce_cart_needs_payment', '__return_false', PHP_INT_MAX );
        }
    }
}
```

The `pm` flag is the payment mode: `n` = `no_payment`, `s` = `sandbox`, and `PAYMENT_MODES` is
`array( 's' )` until an adapter justifies more. **Write this as a whitelist of modes allowed to take
a payment, never as "not sandbox".** A missing, unknown or future flag value must fall through to no
payment: getting the default wrong in the other direction charges someone real money.

Register it in `super-speedy-performance-analysis.php` next to the other `::register()` calls
(around line 101):

```php
require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-checkout-flow.php';
SSPA_Checkout_Flow::register();
```

**Acceptance.** A profiled request with `ck=flow` and no `pm` flag reports `false` from
`WC()->cart->needs_payment()` even with a non-zero total; the same request with `pm=s` reports the
real value; an ordinary front-end request reports the real value.

## T4. Probes: pre-flight inventory and delete-order

**File:** `includes/class-sspa-probes.php` (extend `maybe_handle()`).

Both new probes follow the existing pattern exactly: they only run when the profiler is armed AND
the token flags request them, they echo a plain-text or JSON result, and they `exit` (the capture
still writes, because it runs on PHP shutdown).

Add to `maybe_handle()`:

```php
if ( 'pre' === ( isset( $flags['ck'] ) ? $flags['ck'] : '' ) ) {
    require_once SSPA_PLUGIN_DIR . 'includes/class-sspa-checkout-preflight.php';
    header( 'Content-Type: application/json' );
    echo wp_json_encode( SSPA_Checkout_Preflight::inventory() );
    exit;
}

if ( 'del' === ( isset( $flags['ck'] ) ? $flags['ck'] : '' ) && ! empty( $flags['oid'] ) ) {
    $order = function_exists( 'wc_get_order' ) ? wc_get_order( (int) $flags['oid'] ) : null;
    // The one check that must never be relaxed: no order without our own marker is ever
    // deleted, whatever the flags say.
    if ( $order && $order->get_meta( '_sspa_temp' ) ) {
        $order->update_status( 'cancelled' );   // so wc_maybe_increase_stock_levels runs
        $order->delete( true );                 // HPOS-safe
        self::done( 'flow-delete-order' );
    }
    self::done( 'flow-delete-order-skipped' );
}
```

The pre-flight probe runs on the FRONT END, not in wp-admin, because that is where checkout hooks
are actually registered. An inventory taken from an admin screen misses front-end-only
registrations.

**Acceptance.** `GET /?sspa_flow_probe=1` with flags `{ck:'del', oid:<id of an order without
_sspa_temp>}` leaves that order untouched and returns `sspa-probe-ok:flow-delete-order-skipped`.

## T5. The pre-flight inventory

**File:** `includes/class-sspa-checkout-preflight.php` (new).

```php
class SSPA_Checkout_Preflight {
    /** @return array The disclosure shown to the admin before any order is created. */
    public static function inventory() { ... }
}
```

Returns:

| Key | Built from | Shown as |
|---|---|---|
| `emails` | `WC()->mailer()->get_emails()`, each one's `is_enabled()`, `get_recipient()`, `id`, `title` | "New order to shop@yourdomain.com; Processing order to the test address below" |
| `emails_deferred` | `apply_filters( 'woocommerce_defer_transactional_emails', FeaturesUtil::feature_is_enabled( 'deferred_transactional_emails' ) )` | Decides whether email cost is in the customer's wait at all (A7 fact 6) |
| `webhooks` | `wc_load_webhooks( 'active' )`, delivery URL reduced to host | "3 webhooks will fire to hooks.zapier.com (queued in the background, so not part of your customer's wait)" |
| `webhooks_inline` | `! apply_filters( 'woocommerce_webhook_deliver_async', true, null, null )` | When true, delivery is in-request and IS measured |
| `order_hooks` | `$wp_filter` for the six hooks below, each callback resolved to a file by reflection then classified | "9 plugins will run code when this order is created: ..." |
| `needs_payment_filtered` | `has_filter( 'woocommerce_cart_needs_payment' ) \|\| has_filter( 'woocommerce_order_needs_payment' )` | Warns that another plugin also manipulates this, so A6's filter may interact |
| `payment_modes` | The gateway adapters (T15): for each enabled gateway, whether an adapter exists and whether it reports test mode | Drives the mode choice in the panel. `no_payment` is always present; `sandbox` appears only with a matching adapter in test mode, and names the gateway and instrument it would use |

Hooks to inventory: `woocommerce_checkout_order_processed`,
`woocommerce_store_api_checkout_order_processed`, `woocommerce_new_order`,
`woocommerce_order_status_changed`, `woocommerce_payment_complete`, `woocommerce_thankyou`.

Resolving a callback to a plugin, using the existing component map:

```php
require_once SSPA_PLUGIN_DIR . 'profiler/class-sspa-component-map.php';
$map = new SSPA_Component_Map();

foreach ( $GLOBALS['wp_filter'][ $hook ]->callbacks as $priority => $callbacks ) {
    foreach ( $callbacks as $cb ) {
        $fn = $cb['function'];
        try {
            if ( is_array( $fn ) ) {
                $ref = new ReflectionMethod( is_object( $fn[0] ) ? get_class( $fn[0] ) : $fn[0], $fn[1] );
            } elseif ( is_string( $fn ) && function_exists( $fn ) ) {
                $ref = new ReflectionFunction( $fn );
            } elseif ( $fn instanceof Closure ) {
                $ref = new ReflectionFunction( $fn );
            } else {
                continue;
            }
            $file = (string) $ref->getFileName();
        } catch ( Throwable $e ) {
            continue;   // internal callable, nothing to attribute
        }
        if ( $file ) {
            $cls = $map->classify_file( $file );   // returns ['component' => ..., 'type' => ...]
            $components[ $cls['component'] ] = true;
        }
    }
}
```

`SSPA_Component_Map::classify_file()` is public (`profiler/class-sspa-component-map.php:78`) and
returns the component slug and type. Deduplicate by component, sort by name, and cap the list at 40
with a "and N more" count.

**Acceptance.** On the docker store, `inventory()` returns a non-empty `emails` array, and
`order_hooks` includes `woocommerce` as a component.

## T6. The flow driver

**File:** `includes/class-sspa-checkout-flow.php` (the class started in T3).

```php
/**
 * Runs one complete purchase over loopback and returns per-step results.
 *
 * @param array $opts {product_id, quantity, address, email, mail_mode, payment_mode,
 *                     allow_integrations, allow_webhooks, plugin_set_hash}
 * @return array {steps: [ {key, method, url, sample} ], order_id, outcome, notes}
 *               outcome is 'ok' or one of the failure codes in C4.
 */
public static function run( $opts );
```

**Rules the implementer must follow.**

1. **Steps run in order and later steps use earlier steps' output.** The cart item key, the shipping
   rate id, the order id and the order-received URL are all only knowable at run time. This is why
   the flow is one routine and not N independent queue jobs.
2. **Every step's flags include `ck=flow` and `v=guest`**, plus `mail=d` unless the admin chose
   another mail mode, plus `pm=s` **only** when the admin chose sandbox mode and an adapter
   confirmed test mode (T15). `ck=flow` is what activates T3's hooks; omitting `pm` is what keeps
   the payment gateway out of it.
   - In `sandbox` mode, `flow-place-order` also sends the adapter's `payment_method` and
     `payment_data` in the body. In `no_payment` mode it sends neither.
3. **The `Cart-Token` header is minted once and sent on every Store API request:**
   ```php
   $customer_id = 't_' . bin2hex( random_bytes( 8 ) );   // synthetic guest session id
   $cart_token  = \Automattic\WooCommerce\StoreApi\Utilities\CartTokenUtils::get_cart_token( $customer_id );
   ```
   Guard the class with `class_exists()`; if it is absent (older Woo), fall back to the cookie jar
   path of T12 or report `unsupported_store`.
4. **Record the order id the moment it is known** (both the draft from `flow-checkout-draft` and the
   final one from `flow-place-order`) into the `sspa_flow_temp` option, BEFORE sending the next
   request. See T7.
5. **Cleanup runs in a `finally`**, never on the happy path. Any failure after an order exists still
   runs the delete step.
6. **Stop at the first failed step.** Record the failure code, run cleanup, return. Do not continue
   with a broken cart.

Step list and payloads: C1. Store API URL construction and the 301 gotcha: C2.

**Acceptance.** On the docker store, `run()` returns `outcome` `ok`, 10 steps each with a non-null
`sample['capture']`, and an `order_id` that no longer exists when the method returns.

## T7. Run controller wiring and cleanup

**File:** `includes/class-sspa-run-controller.php`.

1. Accept `'checkout'` in `start()`, building a two-job queue: the pre-flight, then the flow. This
   keeps the existing status polling, cancel button, lock and stale-run janitor working unchanged.
2. In `process_batch()`, dispatch `checkout` jobs to `SSPA_Checkout_Flow`. One flow can exceed
   `BATCH_SECONDS` (15); that is already tolerated, because the deadline is checked BEFORE starting
   a job and never during one (`:588`).
3. Add `finish_checkout( $run_id )`: save one `sspa_profiles` row per step through
   `SSPA_Profile_Store::save()` (single-element samples array, `method` set per step), write the run
   notes, run the findings pass, mark the run done.
4. **Cleanup option.** `sspa_flow_temp` holds `[ ['type' => 'order', 'id' => 123, 'ts' => ...], ... ]`.
   Extend `cleanup()` (`:1028`) to delete anything in it older than an hour, checking `_sspa_temp`
   first, exactly as the delete probe does. This is the crash-safety net; the same janitor already
   restores a held db.php drop-in.
5. **Stock.** Snapshot the product's stock quantity before the flow and compare after. If
   cancelled-then-deleted did not restore it (A8 item 1), restore it explicitly and record that it
   was needed.

**Acceptance.** Kill PHP mid-flow (or throw deliberately after `flow-place-order`), then run
`SSPA_Run_Controller::cleanup()` with the option's timestamp backdated: the orphaned order is gone.

## T8. Admin bar, disclosure panel, AJAX

**Files:** `includes/class-sspa-adhoc.php` (second node), `includes/admin/js/sspa-checkout.js` (new),
run controller AJAX handlers.

- Admin-bar node "Analyse checkout flow", shown only when `is_cart() || is_checkout() || is_product()`
  (the same conditional family the capture already snapshots at
  `profiler/class-sspa-capture.php:156`). Plus a permanent button on the Pages tab.
- **Clicking it does NOT start a purchase.** It calls `wp_ajax_sspa_checkout_preflight`, which runs
  the pre-flight and returns the inventory. The JS renders the disclosure panel, naming: the
  product, the email addresses that will receive mail, the webhook hosts, the plugins that will run
  code, and the fact that stock is restored and the order deleted afterwards. Three toggles, all
  defaulting to on: emails, integrations, webhooks.
- **The payment mode choice lives in this panel** (A6). Radio buttons built from the inventory's
  `payment_modes`. `no_payment` is always offered and preselected. `sandbox` is offered only when an
  adapter matched a gateway in test mode, labelled with the gateway and the instrument, for example
  "Stripe (test mode), test Visa - also measures the gateway round trip". When no adapter matched,
  show one line explaining that sandbox mode needs a supported gateway in test mode, rather than a
  disabled control with no explanation.
- A second, explicit click calls `wp_ajax_sspa_checkout_start`. Acknowledgement is stored in
  `sspa_options['checkout_consent']` so the dialog copy is only shown in full once, but the
  pre-flight itself runs every time, because the answer changes whenever the store's plugins do.
- Reuse the adhoc popover CSS and its reattach-to-running-run logic
  (`includes/class-sspa-adhoc.php:125-141`).
- Nonce and capability: `check_ajax_referer('sspa_admin','nonce')` plus
  `current_user_can('manage_options')`, identical to `SSPA_Adhoc::guard()`.

## T9. The result panel

Waterfall, one row per step, in flow order, with the slowest step marked, **split at the payment
boundary** per A6.4:

```
AT RISK - the customer can still abandon during this
  view product        180 ms   ▇▇
  add to cart         240 ms   ▇▇▇
  view cart           310 ms   ▇▇▇▇
  update customer   1 240 ms   ▇▇▇▇▇▇▇▇▇▇▇▇▇▇▇▇   ← slowest step
  select shipping     260 ms   ▇▇▇
  view checkout       420 ms   ▇▇▇▇▇
  create draft        380 ms   ▇▇▇▇
  place order: to payment
                      840 ms   ▇▇▇▇▇▇▇▇▇▇
                    -------
  at-risk total     3 870 ms   every second here can cost you the sale

SECURED - the money is taken, the customer is waiting on a confirmation
  place order: after payment
                    1 340 ms   ▇▇▇▇▇▇▇▇▇▇▇▇▇▇▇▇
  order received      190 ms   ▇▇
                    -------
  post-capture      1 530 ms   a bad impression, not a lost sale

customer waited     5 400 ms   single measured purchase, server time only
delete order          340 ms   (admin cleanup, nobody waits for this)
```

Two rules for this panel:

1. **The delete step sits below the total and is excluded from it.** Per A2 no customer waits for it.
   It is measured because a slow delete cascade is worth knowing about, never added to the
   customer-facing figure.
2. **The place-order step is split into before and after the payment boundary.** The boundary is
   `capture['marks']['payment_complete']`, in ms since `$timestart`, written by T3's hook and
   emitted by T2's marks section. At-risk time is that value; post-capture time is the step's
   `gen_ms` minus it. In `sandbox` mode the gateway round trip falls before the mark and therefore
   sits in the at-risk half, which is the point: it is time the customer waits before the sale is
   secured. **When the mark is absent** (the hook never fired, e.g. a gateway that completes
   asynchronously) show one combined place-order row and say the boundary could not be determined.
   Never guess it.

Below the waterfall: top components across the flow, slowest queries with owning component, every
outbound HTTP call with host and duration (the PSP call should be identifiable here), mail count and
time with whether it was in-request or deferred, and the safety report (order deleted, stock
restored).

Below the waterfall: top components across the flow, slowest queries with owning component, every
outbound HTTP call with host and duration, mail count and time with whether it was in-request or
deferred, and the safety report (order deleted, stock restored).

## T10. Findings

New types for the existing findings table and analysis engine. Thresholds go in the rules feed, not
hard-coded.

| Type | Trigger |
|---|---|
| `checkout_slow_step` | A step above threshold, with its dominant component named |
| `checkout_component_cost` | One component above a set share of flow server time |
| `checkout_blocking_http` | Any blocking outbound HTTP call during `place-order` or `update-customer`, with host and duration. The classic cause of a six-second checkout |
| `checkout_mail_inline` | Order emails dispatched in-request rather than deferred, with the cost, paired with the recommendation to enable deferred transactional emails |
| `checkout_dupe_queries` | Duplicate query count within a single step |

## T11. Excimer roll-up

**File:** `profiler/class-sspa-excimer.php` (one small change) plus a roll-up method on the flow.

Per-step Excimer data is already free: the capture starts an `ExcimerProfiler` at arm time when the
extension is loaded and attaches the report as `capture['profile']`
(`profiler/class-sspa-capture.php:42-48,218`). The flow's steps are ordinary profiled requests, so
each one already has per-function inclusive and self ms, per-component ms via the same attribution
walk used for SQL, and a per-phase breakdown. **No new profiler code per step.**

Two properties matter more here than on a page load:

- **`EXCIMER_REAL`, not CPU time** (`:40`). A checkout blocked 800 ms inside `curl_exec` against a
  tax service or fraud check shows as 800 samples in `curl_exec`, which is exactly the answer
  wanted, and exactly what the customer waited for. A CPU-time profiler would show nothing there.
- **Negligible sampling overhead**, which is why the collector runs during the measurement pass at
  all. Inherited from the collector's design, not re-argued here.

Extension absent: `capture['profile']` is null, everything else still works, and the panel shows one
line pointing at the Tools tab, which already detects Excimer and generates install steps. The Mac's
own PHP has no Excimer; the docker harness installs it (`.tests/docker/install-excimer.sh`), so this
task can only be verified there.

**New: `SSPA_Checkout_Flow::rollup_profile( array $step_profiles )`** returning:

- **By component** - sum each step's `profile.components` map across steps, plus a per-step column
  so a component expensive at only one step is visible as such. Headline: "your checkout spent 2.4 s
  in PHP, 1.1 s of it in `some-plugin`".
- **By function** - sum `incl_ms` and `self_ms` per function name across steps, keeping the
  component and the step where it was heaviest.
- **Totals** - total samples, total sampled wall ms, and sampled ms as a proportion of summed step
  generation time, which is the sanity check that the sampler saw the requests it thinks it saw.

Two limitations to print in the output, not hide:

1. Each step's report is already truncated to the top 40 functions (`:22`), so the roll-up is a sum
   of top-40 lists, not a true whole-flow profile. Label it "top functions seen across steps".
   Consider raising `MAX_FUNCTIONS` for flow steps; it is a per-request constant today and the blob
   cost is small.
2. `incl_ms` cannot be summed with `self_ms` to make a total, for the same reason it cannot within
   one request.

**Small fix in the collector.** `phase_windows()` already handles `template_redirect` never firing
by running the final window to `request_end` (`:77-79`), but it labels that window
`render_and_output`, which is misleading on a Store API POST where it holds nearly everything.
Relabel it `endpoint_work` when the request is REST or `wc-ajax`. The boot timer already times
`rest_api_init` callbacks (`profiler/class-sspa-boot-timer.php:30`), so the earlier phases stay
meaningful.

## T12. Phase 2 - the classic checkout path

Only after phase 1 works end to end. Steps and payloads in C3.

Two things make classic harder than blocks:

1. **Cookie jar.** Feed each response's cookies into the next request. `wp_remote_*` returns
   `WP_Http_Cookie` objects; `send_profiled()` already accepts and returns them.
   WooCommerce's cart lives in `wp_woocommerce_session_<hash>`.
2. **Nonces, and the trap in A7 fact 8.** Mint them as a logged-out visitor, using core's own
   function rather than reimplementing `wp_hash()`:

```php
$saved   = $_COOKIE;
$current = get_current_user_id();
try {
    unset( $_COOKIE[ LOGGED_IN_COOKIE ], $_COOKIE[ AUTH_COOKIE ], $_COOKIE[ SECURE_AUTH_COOKIE ] );
    wp_set_current_user( 0 );
    $nonce = wp_create_nonce( 'woocommerce-process_checkout' );
} finally {
    wp_set_current_user( $current );
    $_COOKIE = $saved;
}
```

Get this wrong and every classic checkout POST returns "we were unable to process your order, please
try again", which reads like a broken store rather than a broken profiler.

## T13. Phase 4 - which plugin is making checkout slow

Both halves already exist: run the flow with the virtual plugin-set override applied
(`mu/sspa-loader.php:98-127`), once per suspect, and diff against a flow baseline.

> Excluding `some-plugin` takes 1 340 ms off your checkout, 1 180 ms of it in the place-order step.

Reuses the deep-analysis machinery wholesale: isolation payload options, the `X-SSPA-PS` canary that
proves the override applied, and the `plugin_impacts` table with `page_key = 'flow-place-order'`.

Two things that are not free:

- **Repeats become mandatory**, which is where the CLI `--repeats` earns its place. A single sample
  has no spread, and the noise gate must be recalibrated against measured flow spread rather than
  inherited from page loads: the flow is noisier than a page load because it creates rows.
- **Excluding a plugin the checkout depends on breaks the flow rather than slowing it.** That is a
  measurement result (the existing sweep already reports fatal cells), and the phrasing should be
  "checkout does not complete without this plugin", which is itself useful.

Do not make this hard in phase 1: `SSPA_Checkout_Flow::run()` takes `plugin_set_hash` in `$opts` and
passes it through as the `ps` flag, exactly as `SSPA_Crawler::profile_job()` does today.

## T14. Tests

**File:** `.tests/cases/19-checkout-flow.php`. Runs in the docker harness, never against the local
superspeedy install (`.tests/README.md`). Follow the existing case style: print `PASS:` / `FAIL:`
lines, no framework.

Per the `writing-tests` rule, drive the real code path, assert the user-visible outcome, and make it
fail for the intended reason before trusting it.

1. Snapshot order count, product stock, and `wp_woocommerce_sessions` row count.
2. Run the flow through `SSPA_Run_Controller::start(['type' => 'checkout', ...])` and the batch loop,
   the same entry point the button uses. Not a hand-assembled sequence in the test file.
3. Assert a `sspa_profiles` row exists for every expected step key, with `page_gen_ms` not null and
   `method` = POST on the POST steps. A step recorded with null metrics is a failure, not a pass.
4. Assert order count and stock returned to their starting values, the flow's orders and draft
   orders are gone, and `sspa_flow_temp` is empty.
5. Assert the order really completed: the place-order step returned an order whose status is one
   `payment_complete()` produces, not a draft left behind by a silent failure.
6. Assert mail behaviour matches the mode: `deliver` dispatches with a recorded duration; `construct`
   builds and sends nothing.
7. Assert the pre-flight inventory is non-empty and names `woocommerce` among the order-hook
   components.
8. Assert `active_plugins` is untouched and no db.php hold is left behind, matching case 05.
9. Assert the Excimer roll-up: `components` non-empty, summed sampled ms within a sane factor of
   summed step generation time, WooCommerce named for the place-order step. Skip with a clear
   message when the extension is missing rather than passing vacuously.
10. Deliberate failure case: an out-of-stock product produces `add_to_cart_failed` with nothing
    created and nothing left behind.
11. Payment-mode safety: a flow request whose token carries no `pm` flag, and one carrying a junk
    value, both take the no-payment path. This is the assertion that stops a future refactor from
    charging someone real money.
12. The payment boundary: `capture['marks']['payment_complete']` is present on the place-order step
    and is greater than zero and less than that step's `gen_ms`.

**Before trusting any of it, break it once on purpose:** comment out the delete step and confirm the
test fails on the order-count assertion. A cleanup test that has never failed is not evidence that
cleanup works.

## T15. Phase 3 - sandbox payment adapters

Design and rationale in A6.2. Do this after phase 1 works end to end, because everything it adds
sits on top of a working flow.

**Files:** `includes/gateways/interface-sspa-gateway-adapter.php`,
`includes/gateways/class-sspa-gateway-stripe.php`, and a registry on `SSPA_Checkout_Flow` that asks
each adapter whether it matches an enabled gateway.

Build order within the task:

1. The interface and the registry, with zero adapters. `payment_modes` in the pre-flight then
   correctly reports "no supported gateway in test mode" on every store, which is the honest state.
2. The Stripe adapter, gated behind the verification list in A6.2. **Verify each item against a real
   install with the gateway present before writing the code that depends on it** - none of it could
   be checked while writing this doc, because no payment gateway is installed on the dev site.
3. A generic "possibly in test mode" detector for unknown gateways, which only ever produces a
   warning in the panel. It must never cause a payment to be attempted.

Hard rules:

- **Never store or post a card number.** Adapters return pre-made instruments (Stripe's `pm_card_*`
  test tokens). If an adapter would need a PAN, do not write that adapter.
- **Never send a payment against live keys.** The adapter must confirm test mode itself, and the flow
  must re-confirm before selecting `sandbox` mode. Two independent checks, because getting this wrong
  charges someone real money.
- `live_declined` stays unbuilt (A6.3). Reserve the name in the settings enum, nothing more.

**Acceptance.** On a store with the gateway in test mode, a `sandbox` run completes with an order in
a paid status, and the capture for `flow-place-order` contains a blocking HTTP call to the gateway's
sandbox host with a recorded duration. On a store with the gateway in live mode, the panel does not
offer sandbox mode and `SSPA_Checkout_Flow::run()` refuses `sandbox` if asked for it directly.

---

# Part C - reference

## C1. Block checkout steps and payloads

All Store API requests carry: `X-SSPA-Token` (minted by `send_profiled()`), `Cart-Token`
(constant for the run), `Content-Type: application/json`, and token flags
`{v: 'guest', ck: 'flow', mail: 'd'}` plus `pm: 's'` in sandbox mode only.

| # | Step key | Method | URL | Body | Take from response |
|---|---|---|---|---|---|
| 1 | `flow-preflight` | GET | `home_url('/?sspa_flow_probe=1')` | - | The inventory JSON. Flags `{ck:'pre'}`, not `flow` |
| 2 | `flow-view-product` | GET | product permalink | - | - |
| 3 | `flow-add-to-cart` | POST | `wc/store/v1/cart/add-item` | `{"id": <product_id>, "quantity": 1}` | `items[0].key` |
| 4 | `flow-view-cart` | GET | cart page permalink | - | - |
| 5 | `flow-cart-api` | GET | `wc/store/v1/cart` | - | - |
| 6 | `flow-update-customer` | POST | `wc/store/v1/cart/update-customer` | `{"billing_address": {...}, "shipping_address": {...}}` | `shipping_rates[0].package_id`, `shipping_rates[0].shipping_rates[0].rate_id` |
| 7 | `flow-select-shipping` | POST | `wc/store/v1/cart/select-shipping-rate` | `{"package_id": <id>, "rate_id": "<rate>"}` | Skip and record as skipped when step 6 returned no rates |
| 8 | `flow-view-checkout` | GET | checkout page permalink | - | - |
| 9 | `flow-checkout-draft` | GET | `wc/store/v1/checkout` | - | `order_id` (the `wc-checkout-draft`). Record it immediately |
| 10 | `flow-place-order` | POST | `wc/store/v1/checkout` | `{"billing_address": {...}, "shipping_address": {...}}` | `order_id`, `status`, `payment_result.redirect_url` |
| 11 | `flow-order-received` | GET | `payment_result.redirect_url`, or `$order->get_checkout_order_received_url()` | - | - |
| 12 | `flow-delete-order` | GET | `home_url('/?sspa_flow_probe=1')` | - | Flags `{ck:'del', oid:<order_id>}` |

Address object fields (both billing and shipping; `email` and `phone` are billing only):

```json
{
  "first_name": "SSPA", "last_name": "Test", "company": "",
  "address_1": "1 Test Street", "address_2": "",
  "city": "<from settings>", "state": "<from settings>",
  "postcode": "<from settings>", "country": "<store base country>",
  "email": "admin+sspa-perf-<run_id>@<admin domain>", "phone": "01234567890"
}
```

Source: `src/StoreApi/Schemas/V1/BillingAddressSchema.php:108-118`. Confirm the country-conditional
required set per A8 item 2.

**Where the email goes, and why it is not filtered.** The billing email is the site's `admin_email`
with a per-run tag: `dave@example.com` becomes `dave+sspa-perf-<run_id>@example.com`. Customer-facing
order emails then reach the admin's own inbox simply because that is the customer address on the
order, uniquely tagged so they can be filtered or deleted in bulk. No mail filtering is involved,
which is what keeps the measurement faithful. Admin-recipient emails (new order, failed order) go
where they always go. A setting overrides the address for mail hosts that reject plus-addressing.

A plugin that emails a third party on a new order (a supplier, a dropshipper, a fulfilment service)
will really email them. That is inseparable from measuring the real thing, and it is exactly what
the pre-flight list exists to show.

## C2. Store API URL construction, and the 301 trap

Build URLs with `rest_url( 'wc/store/v1/cart/add-item' )`. Some installs 301 pretty `/wp-json/`
paths to a trailing slash, and a 301 on a POST loses the body - the repo already hit this with the
hub client (`.tests/README.md`, "the local install 301s pretty `/wp-json/` paths to trailing slashes,
which kills POSTs").

`send_profiled()` does not follow redirects, so this surfaces as a 301 rather than a silent wrong
measurement. On a 301 from a Store API URL, retry once with the query form:

```php
home_url( '/?rest_route=/wc/store/v1/cart/add-item' )
```

WooCommerce explicitly supports the `rest_route` form for Store API requests
(`src/StoreApi/Authentication.php:117-130`). The profiling token signs path plus query, so either
form is fine; just mint the token for the URL actually sent.

## C3. Classic checkout steps (T12)

| # | Step key | Method | URL | Nonce |
|---|---|---|---|---|
| 1 | `flow-view-product` | GET | product permalink | - |
| 2 | `flow-add-to-cart` | POST | `/?wc-ajax=add_to_cart` `{product_id, quantity}` | none |
| 3 | `flow-view-cart` | GET | cart page | - |
| 4 | `flow-update-order-review` | POST | `/?wc-ajax=update_order_review` | `update-order-review`, field `security` (`class-wc-ajax.php:397`) |
| 5 | `flow-view-checkout` | GET | checkout page | - |
| 6 | `flow-place-order` | POST | `/?wc-ajax=checkout` with the full billing field set | `woocommerce-process_checkout`, field `woocommerce-process-checkout-nonce` (`class-wc-checkout.php:1286-1293`) |
| 7 | `flow-order-received` | GET | order-received URL | - |
| 8 | `flow-delete-order` | GET | probe | - |

## C4. Failure codes

Every failure is a named outcome, never a silent zero. `blocked_by` on the profile row and `error` on
the sample are where these land.

| Code | Meaning |
|---|---|
| `no_store` | WooCommerce not active |
| `no_product` | No purchasable, in-stock product found |
| `unsupported_checkout` | Checkout page is neither the block nor the shortcode |
| `unsupported_store` | `CartTokenUtils` absent (Woo too old for the block path) |
| `add_to_cart_failed` | Store API error on add-item, with its error code |
| `place_order_failed` | Store API error on checkout, with its error code. Draft order cleaned up |
| `rate_limited` | 429 from the Store API. Never silently retried |
| `no_canary` | The mu-loader did not arm: a cache answered, or helper files are missing |
| `capture_missing` | Request ran but wrote no capture |
| `blocked_*` | From the existing `SSPA_Security_Detect::classify()` |

A fatal during a step is already handled: the mu-loader defines `WP_SANDBOX_SCRAPING`
(`mu/sspa-loader.php:65-67`) so core does not email the admin a recovery-mode warning. The step is
unresolved and cleanup still runs.

## C5. Settings

New keys in `sspa_options` via `sspa_get_option()` / `sspa_update_option()` (`defines.php`). All
have working defaults so the button works unconfigured.

| Key | Default | Notes |
|---|---|---|
| `checkout_product_id` | auto | Cheapest purchasable, in-stock, non-variable product. A variable product needs a variation id and is a poor default |
| `checkout_quantity` | 1 | |
| `checkout_address` | store base country, valid postcode for it | Country drives tax and shipping lookups. An invalid postcode silently changes which zone matches |
| `checkout_email` | `admin_email` tagged `+sspa-perf-<run_id>` | Overridable for mail hosts without plus-addressing |
| `checkout_mail_mode` | `deliver` | `construct` and `suppress` available |
| `checkout_payment_mode` | `no_payment` | Enum: `no_payment`, `sandbox`, `live_declined`. `sandbox` is only selectable when an adapter matched a gateway in test mode (A6.2); `live_declined` is reserved and unbuilt (A6.3) |
| `checkout_allow_integrations` | true | Off unhooks the third-party order callbacks the pre-flight listed. Off means the number is no longer a real-store number; recorded in the run notes |
| `checkout_allow_webhooks` | true | Off skips webhook queueing. A privacy control, not a speed control: per A7 fact 5 the delivery is out of scope anyway |
| `checkout_consent` | false | Set once the disclosure is accepted |

## C6. Files

New:

- `includes/class-sspa-checkout-flow.php` - T3, T6, T11 roll-up
- `includes/class-sspa-checkout-preflight.php` - T5
- `includes/admin/js/sspa-checkout.js` - T8
- `.tests/cases/19-checkout-flow.php` - T14
- `includes/gateways/interface-sspa-gateway-adapter.php`, `includes/gateways/class-sspa-gateway-stripe.php` - T15, phase 3

Changed:

- `includes/class-sspa-crawler.php` - T1 `send_profiled()`
- `includes/class-sspa-profile-store.php` - T1, the hard-coded `'method' => 'GET'` at line 79
- `profiler/class-sspa-capture.php` - T2 deliver mail mode, and the `marks` section the payment-boundary split depends on
- `includes/class-sspa-probes.php` - T4 pre-flight and delete probes
- `includes/class-sspa-run-controller.php` - T7 run type, jobs, finish, janitor
- `includes/class-sspa-adhoc.php` - T8 second admin-bar node
- `profiler/class-sspa-excimer.php` - T11 phase relabel
- `defines.php` - C5 settings keys
- `super-speedy-performance-analysis.php` - require and register the new classes (near line 101)
- `includes/cli/class-sspa-cli.php`, `includes/class-sspa-abilities.php` - `wp sspa checkout-flow
  [--product=<id>] [--repeats=1] [--dry-run]` (dry-run prints the pre-flight only), and ability
  `sspa/run-checkout-flow` plus the flow summary in `sspa/get-report`

**No change to `includes/class-sspa-schema.php`.** The `method` column already exists (A7 fact 11).
Do not bump `DB_VERSION`.

## C7. Phasing

| Phase | Tasks | Delivers |
|---|---|---|
| 1 | T1-T11, T14 | The button and the answer, on block checkouts, in `no_payment` mode |
| 2 | T12 | Classic checkout coverage |
| 3 | T15 | Sandbox payment adapters: the gateway round trip measured too, on dev and staging |
| 4 | T13 | "This plugin costs your checkout 1.3 s" |
| 5 | Logged-in customer variant | Needs `SSPA_Auth::cookies_for()` to create the session token explicitly and pass it to `wp_generate_auth_cookie()` (`includes/class-sspa-auth.php:27`), so nonces can be minted for that exact session |
