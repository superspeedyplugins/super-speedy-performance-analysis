---
version: 0.69.0
available_since: 0.69.0
areas: [tools, dns-check]
---
# DNS Check

Every licence check, update check and API call a plugin makes starts with a DNS lookup: the server asks its resolver for the address of the host it wants to reach. When that resolver drops lookups, PHP sits and waits before it tries again, and the wait lands inside whatever page made the request. The DNS check in Super Speedy Performance Analysis Pro tells you in under half a minute whether your server's resolvers answer reliably.

*Available in Super Speedy Performance Analysis Pro since 0.69.0.*

## Why a bad resolver makes admin pages slow

PHP's resolver waits for an answer for a fixed time (5 seconds unless your server sets something else), then tries again, usually twice in all. A resolver that loses even a third of lookups turns a 50 millisecond licence check into a 5 or 10 second one.

That cost hides well. It sits before any HTTP request starts, so a profile shows a slow outbound call from a licence check, an update check, Jetpack or the WordPress HTTP API, and nothing points at DNS. It hits wp-admin hardest, because that is where most plugins phone home, and it comes and goes with whichever lookups happen to be lost.

:::callout{variant="did-you-know"}
One store's server was losing about 40% of its DNS lookups on the host's default resolver. Admin pages took 5 to 10 seconds longer for weeks, until the resolver was switched to Cloudflare's.
:::

## Running the check

1. Go to **Super Speedy → Performance Analysis → Tools**.
2. Scroll to **DNS** and press **Check DNS**.
3. Wait up to 25 seconds. The result replaces the section and is kept, with the time it was taken, until you check again.

The check reads the nameservers PHP uses from `/etc/resolv.conf`. When the only nameserver there is systemd-resolved's local stub (`127.0.0.53`), it tests the upstream servers behind the stub as well. It then sends 12 real lookups straight to each nameserver, one at a time, and waits up to 1.5 seconds for each answer:

- 4 lookups for names WordPress needs, such as `api.wordpress.org`.
- 8 lookups for random names that cannot be in any cache, so the resolver has to do real work to answer them.

It changes nothing on the server.

## Reading the result

| Result | What it means |
|---|---|
| Healthy | All 12 lookups answered, at a normal speed |
| Dropping lookups | 2 or more of 12 failed or got no answer. This is the problem described above |
| One lookup lost, check again | A single lookup was lost. Run the check again to tell a blip from a pattern |
| Slow | Every lookup answered, but the typical answer for a new name took 500 ms or more |

The **Lookups** column gives the exact counts, for example "7 of 12 answered" and "5 of 12 got no answer". **Typical answer** is the median time for the uncacheable names; **Slowest answer** is the longest of all 12.

When a nameserver is dropping lookups, the section states how long PHP waits on this server, using its own `timeout` and `attempts` settings.

## Fixing a resolver that drops lookups

Point the server at a reliable public resolver, such as Cloudflare (`1.1.1.1`) or Google (`8.8.8.8`):

- **Managed or shared hosting:** ask your host to change the server's resolvers, and include the check's result in the ticket.
- **Your own server:** change the `nameserver` lines in `/etc/resolv.conf`, or the network configuration that writes that file (netplan, systemd-resolved, or your cloud provider's settings), then check again.

## When the configuration cannot be read

On some hosts PHP is not allowed to read `/etc/resolv.conf`, or cannot open network sockets of its own. The check then times the same 12 lookups through PHP's own resolver and says so above the result. A developer can name the resolvers to test with the `sspa_dns_check_nameservers` filter, which takes a list of addresses such as `1.1.1.1` or `10.0.0.2:53`.

## Further reading

- [Installing the Optional Profiling Extras](https://www.superspeedyplugins.com/kb/super-speedy-performance-analysis/setup-server/installing-profiling-extensions/) - everything else on the Tools tab
