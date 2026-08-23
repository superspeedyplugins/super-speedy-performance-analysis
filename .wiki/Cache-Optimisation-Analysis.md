# Cache optimisation analysis

The evidence you need before switching full-page caching on for logged-in customers and basket holders.

## What you get to see

A downloadable report covering four things for your site:

- **Visitor-specific cookies and nonces, by name.** Never their values. These are the things that make a page different for one visitor, and each one is a reason a shared cache would serve the wrong page to somebody.
- **Your existing cache coverage**, separated into pages a shared cache already answers and pages it doesn't.
- **The genuinely private surfaces**, the pages that must never be shared between visitors.
- **Ranked source candidates** for each blocker, so you know which plugin or theme is setting the cookie rather than only that a cookie exists.

Every analysis includes it. The machine-readable schema is `sspa/shared-cache-safety-report@2`.

## Why this is the hard part of caching

Full-page caching for logged-out visitors is a solved problem. Caching for logged-in customers is where stores get burned, because the failure mode isn't a slow page, it's one customer seeing another customer's basket. So the honest sequence is to find every reason a page varies per visitor, deal with each one, and only then turn caching on.

That list of reasons is what this produces. It's ordinary work done by hand with browser dev tools and a lot of guessing, and the guessing is what this removes.

## How to run it

- **Any full analysis** produces the report. Download it from the results.
- **WP-CLI:** `wp sspa cache-optimisation-report`. The older `wp sspa cache-scan` still works as an alias.

## How to read the result

Work down the blockers in the order given. For each one you have three options, and the report's source candidates tell you which is available: stop setting the cookie, move the personalised part out of the cached HTML so it arrives separately, or accept that this surface stays uncached.

The second option is the one that turns a "can't cache this" into a cached page. Prices and stock are the usual example on a WooCommerce store: if the cached HTML carries a placeholder and the real per-visitor value arrives after page load, the page itself becomes cacheable for everyone. [Super Speedy AJAX Prices](https://www.superspeedyplugins.com/product/super-speedy-ajax-prices/) does exactly that, which is why it pairs with this report.

## Related measurement

Object cache modes in [[Plugin-Impact-Analysis]] answer a different question: not "can this page be shared between visitors" but "which plugins depend on your object cache and which ignore it". Each plugin and page cell can be measured with the object cache disabled, priming, and warmed.

## Requirements and limits

Nothing to install. The report is built from the same profiled requests as the rest of the analysis.

It tells you what varies per visitor. It doesn't configure your cache for you, and it can't see a rule your CDN applies above WordPress. Pair it with your cache plugin's own exclusion list, and read the [Cloudflare setup guide](https://www.superspeedyplugins.com/kb/performance-optimization/site-owner-tips/cloudflare-for-wordpress-woocommerce/) if the edge is where your caching happens.

---

Related: [[Plugin-Impact-Analysis]] · [[Per-Page-Analysis]] · [[Troubleshooting]]
