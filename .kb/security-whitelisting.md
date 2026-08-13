# Security plugins and profiling requests

Super Speedy Performance Analysis profiles your site by sending requests from your server
to itself ("loopbacks"). Each carries a signed, single-use token; wp-admin pages are
profiled using a short-lived cookie for your own admin account.

Security plugins sometimes block these requests - to them, a server IP presenting an
admin cookie can look suspicious. When that happens the analysis DOES NOT fail: blocked
pages are marked, the run continues, and a finding tells you which security layer blocked
it.

## What to whitelist

Add your own server's IP address to the allowlist in the named security plugin, then
re-run the analysis:

- **Wordfence**: Firewall -> All Firewall Options -> Allowlisted IP addresses.
- **Solid Security**: Settings -> Global Settings -> Authorized Hosts.
- **All-In-One Security**: sometimes triggers on the login-cookie pattern; temporarily
  disable its brute-force cookie check while profiling.
- **Cloudflare**: create a WAF skip rule for requests from your origin server's IP.
- **Sucuri WAF**: Allowlist your hosting IP in the firewall dashboard.

Your server's public IP is usually the site's own A record; on shared hosting ask your
host. The plugin never needs anything whitelisted for normal visitors - only the
server-to-itself path.

## Still blocked?

Some hosts route loopbacks through the CDN. If whitelisting the server IP does not help,
check whether your host offers a "direct" hostname that bypasses the proxy layer, and ask
in the Super Speedy Discord - detection improves with every report.

## Checkout-flow verification failures

Checkout security can stop the synthetic purchase before WooCommerce creates an order.
The result will show `place_order_failed` together with the validation message returned by
the site, such as a CAPTCHA or human-verification error. No order-management timings can be
collected until that validation succeeds.

When this happens, identify the security plugin responsible for the current message and
handle plugins one at a time. Super Speedy Performance Analysis should use that plugin's
documented, request-scoped bypass or test hook only for its signed synthetic checkout.
It should not disable every plugin whose name contains words such as `captcha` or
`security`: that could change unrelated store behaviour, disable the wrong plugin, and miss
security products whose names do not contain those words.

After adding or configuring one explicit integration, re-run the checkout flow. If another
security layer then blocks the request, the new error identifies the next integration to
handle. Continue until WooCommerce creates the temporary order and the order-management
steps can run. Each integration must leave protection enabled for normal customer requests.

For example, Simple CAPTCHA with Cloudflare Turnstile provides a documented disable filter.
SSPA applies it only to the signed synthetic checkout request, so Turnstile remains active
for real customers. If a security plugin provides no suitably narrow bypass, follow its
allowlisting guidance or temporarily configure it yourself for the test rather than having
SSPA silently deactivate the whole plugin.
