# Geolocation Speed Boosts

WooCommerce works out each visitor's country for tax, for the default customer location, and for country-based pricing. On a lot of stores that lookup is far more expensive than anyone realises: the bundled MaxMind integration opens and scans a GeoIP database file on the server for every request that is not answered by a page cache. Measured on a live WooCommerce store with a sampling profiler, that file scan costs around 70ms per request - often the single biggest avoidable cost on the page.

The fix is to hand the lookup to infrastructure that has done the work anyway. A CDN or the web server itself can attach the visitor's country to the request as a header for effectively nothing, and WooCommerce prefers those headers over its own database lookup.

:::callout{variant="result"}
On a live store profiled function by function, switching country lookups from the MaxMind database file to the Cloudflare header removed about <strong>70ms from every uncached page view</strong> - with geolocation accuracy kept, not sacrificed.
:::

## How WooCommerce decides a visitor's country

`WC_Geolocation::geolocate_ip()` resolves the country in a fixed order:

1. **Request headers first.** It checks `MM_COUNTRY_CODE`, `GEOIP_COUNTRY_CODE`, `CF-IPCountry` and `X-Country-Code`, in that order, and uses the first one present.
2. **The MaxMind database second.** The bundled MaxMind integration hooks in behind the headers and bails out immediately when a header supplied the country. It only opens its database file when no header answered.
3. **Filters throughout.** `woocommerce_geolocate_ip` and `woocommerce_get_geolocation` let plugins override either step.

The whole optimisation rests on step 1: put the country in a header and the expensive step never runs. Nothing needs configuring inside WooCommerce - the preference is built in.

## The free win on Cloudflare

Cloudflare knows every visitor's country before your server ever sees the request, and it will attach that knowledge as a `CF-IPCountry` header on every request it proxies. The feature is free on every plan - it just ships switched off.

1. Open the Cloudflare dashboard and select your domain.
2. Go to **Network** and switch **IP Geolocation** on.
3. That is the whole job. From the next request, `CF-IPCountry` reaches your server and WooCommerce uses it.

To confirm it is working, check a response from your server for the header's effect, or profile a page before and after (see the measuring section below): the MaxMind reader functions disappear from the profile entirely.

:::callout{variant="performance-tip"}
Cloudflare sends the country code for the <strong>connecting visitor</strong>, so this stays accurate behind Cloudflare's proxy - unlike server-side IP lookups, which see Cloudflare's own IPs unless the real IP is restored first.
:::

A couple of small notes: Cloudflare sends `XX` for unknown locations and `T1` for Tor exit nodes; WooCommerce treats an unrecognised code the same as no match and falls back to your store's default customer location. The header only exists on traffic that flows through Cloudflare's proxy (orange-clouded DNS records).

## Not behind Cloudflare?

The same header mechanism works with any front end that knows the visitor's country:

- **Other CDNs.** Most can attach a country header - for example CloudFront's `CloudFront-Viewer-Country`. WooCommerce does not read that name directly, so map it to one it does read: a one-line web-server rule that copies the CDN's header into `X-Country-Code`.
- **The web server itself.** nginx's geoip2 module or Apache's mod_maxminddb do the lookup in native code against a memory-mapped database and can set `GEOIP_COUNTRY_CODE` for PHP. The database opens once when the server starts, not once per request, so the per-request cost is close to zero. This is the strongest option for stores not behind any CDN.
- **No geolocation needed at all?** If every price, tax rate and shipping option on your store is the same regardless of visitor location, set **WooCommerce → Settings → General → Default customer location** to "Shop base address" and skip the lookup entirely.

:::callout{variant="did-you-know"}
The header names WooCommerce reads - `MM_COUNTRY_CODE`, `GEOIP_COUNTRY_CODE`, `CF-IPCountry`, `X-Country-Code` - cover MaxMind's own Apache module, the nginx/Apache geoip modules, Cloudflare, and a generic name you can map any other source onto. Whatever your stack, one of them is reachable.
:::

## Geolocation and page caching

Per-request geolocation and full-page caching pull in opposite directions. A cached page is served without PHP running, so a server-side lookup cannot personalise it - which means "Default customer location: Geolocate" pays the lookup cost on every cache miss while doing nothing for cache hits. WooCommerce's "Geolocate (with page caching support)" variant works around this with a redirect-based approach that adds its own overhead to first visits.

:::callout{variant="mistake"}
Paying 70ms of geolocation on every uncached request while serving location-blind cached pages to everyone else is the worst of both worlds. Decide what location actually changes on your store - and if the answer is prices, solve it in a way that keeps pages cacheable.
:::

For stores that genuinely show different prices by country, the cache-friendly pattern is to keep the page itself identical for everyone and fetch the per-visitor prices over ajax - which is exactly what [Super Speedy Ajax Prices](https://www.superspeedyplugins.com/product/super-speedy-ajax-prices/) and its Country Prices add-on do. Its country resolution uses the customer's saved address first, then WooCommerce's geolocation - so it benefits from the header optimisation on this page automatically - and falls back to your store's default country, so visitors on stacks with no geolocation at all still see correct base prices.

## Measure it on your own site

Guessing is how 70ms hides in plain sight for years. [Super Speedy Performance Analysis](https://www.superspeedyplugins.com/) is free and shows exactly where each page's time goes: click "Analyse this page" in the admin toolbar on any page, and read the "By function" table in the results. A store paying the MaxMind cost shows rows like `MaxMind\Db\Reader::findMetadataStart` with tens of milliseconds of self time; after the header is in place those rows vanish from a re-run. The same panel breaks the page into request phases and per-plugin costs, so the geolocation saving can be seen in context rather than taken on faith.

## Further reading

- [Installing the fastest WordPress stack on Ubuntu 24.04 LTS](https://www.superspeedyplugins.com/kb/performance-optimization/stack-guides-tips/installing-the-fastest-wordpress-stack-ubuntu-24-04/) - the server stack this guide's nginx-level options belong to.
- [Choosing an Ajax Type](https://www.superspeedyplugins.com/kb/super-speedy-ajax-prices/getting-started/choosing-an-ajax-type/) - how per-visitor data is fetched without giving up full-page caching.
- [Configuring WP Rocket for Super Speedy AJAX Prices](https://www.superspeedyplugins.com/kb/super-speedy-ajax-prices/getting-started/wp-rocket-configuration/) - page caching and per-visitor prices working together.

:::related{slugs="super-speedy-ajax-prices,scalability-pro"}
:::
