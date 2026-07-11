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
