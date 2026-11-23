<?php
defined('ABSPATH') || exit;
// Pro: the DNS section of the Tools tab. Deleted from the wordpress.org build.
$sspa_dns = SSPA_Dns_Check::last();
$sspa_dns_bad = false;
?>
<div id="sspa-dns-check">
    <h3><?php esc_html_e('DNS', 'super-speedy-performance-analysis'); ?></h3>
    <p class="description"><?php esc_html_e('Every licence check, update check and API call a plugin makes starts with a DNS lookup. A resolver that drops lookups makes PHP wait seconds before it tries again, which shows up as slow admin pages. This sends 12 real lookups to each nameserver this server uses and counts the ones that fail or get no answer. It changes nothing on the server.', 'super-speedy-performance-analysis'); ?></p>
    <p>
        <button type="button" class="button sspa-dns-check-run"><?php esc_html_e('Check DNS', 'super-speedy-performance-analysis'); ?></button>
        <span class="sspa-dns-check-status description" role="status"></span>
    </p>
    <?php if ($sspa_dns) : ?>
        <p class="description">
            <?php
            /* translators: %s: date and time of the last check */
            printf(esc_html__('Last checked %s.', 'super-speedy-performance-analysis'), esc_html(wp_date(get_option('date_format') . ' ' . get_option('time_format'), (int) $sspa_dns['time'])));
            if (!empty($sspa_dns['fallback'])) {
                echo ' ' . esc_html__('The resolver configuration could not be read here, so these are lookups through PHP\'s own resolver.', 'super-speedy-performance-analysis');
            }
            ?>
        </p>
        <div class="sspa-table-scroll"><table class="widefat striped sspa-dns-results">
            <thead><tr>
                <th><?php esc_html_e('Nameserver', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Result', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Lookups', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Typical answer', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Slowest answer', 'super-speedy-performance-analysis'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ((array) $sspa_dns['servers'] as $sspa_server) :
                $sspa_lost = (int) $sspa_server['failed'] + (int) $sspa_server['unanswered'];
                if ('dropping' === $sspa_server['verdict'] || 'one_lost' === $sspa_server['verdict'] || 'unreachable' === $sspa_server['verdict']) { $sspa_dns_bad = true; }
                ?>
                <tr>
                    <td><code><?php echo esc_html($sspa_server['address']); ?></code></td>
                    <td><strong class="sspa-dns-verdict-<?php echo esc_attr($sspa_server['verdict']); ?>"><?php echo esc_html(SSPA_Dns_Check::verdict_label($sspa_server['verdict'])); ?></strong></td>
                    <td>
                        <?php
                        if ($sspa_server['error']) {
                            echo esc_html($sspa_server['error']);
                        } else {
                            /* translators: 1: lookups answered, 2: lookups sent */
                            echo esc_html(sprintf(__('%1$d of %2$d answered', 'super-speedy-performance-analysis'), $sspa_server['answered'], $sspa_server['sent']));
                            if ($sspa_server['unanswered']) {
                                /* translators: 1: lookups with no answer, 2: lookups sent */
                                echo '<br>' . esc_html(sprintf(__('%1$d of %2$d got no answer', 'super-speedy-performance-analysis'), $sspa_server['unanswered'], $sspa_server['sent']));
                            }
                            if ($sspa_server['failed']) {
                                /* translators: 1: lookups refused or failed, 2: lookups sent */
                                echo '<br>' . esc_html(sprintf(__('%1$d of %2$d failed', 'super-speedy-performance-analysis'), $sspa_server['failed'], $sspa_server['sent']));
                            }
                            if ($sspa_server['skipped']) {
                                /* translators: %d: lookups not sent before the time limit */
                                echo '<br>' . esc_html(sprintf(__('%d not sent before the 25 second limit', 'super-speedy-performance-analysis'), $sspa_server['skipped']));
                            }
                        }
                        ?>
                    </td>
                    <td><?php echo null === $sspa_server['median_ms'] ? '&ndash;' : esc_html($sspa_server['median_ms'] . ' ms'); ?></td>
                    <td><?php echo null === $sspa_server['slowest_ms'] ? '&ndash;' : esc_html($sspa_server['slowest_ms'] . ' ms'); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <?php if ($sspa_dns_bad) :
            $sspa_wait = (int) $sspa_dns['timeout'];
            $sspa_most = $sspa_wait * (int) $sspa_dns['attempts'];
            ?>
            <p>
                <?php
                /* translators: 1: resolver timeout in seconds, 2: timeout multiplied by attempts */
                echo esc_html(sprintf(__('When a lookup gets no answer, PHP waits %1$d seconds before trying again, and up to %2$d seconds in all. That happens inside every outbound request a plugin makes, so licence checks, update checks and API calls stall for that long, on the admin pages and cron jobs that make them.', 'super-speedy-performance-analysis'), $sspa_wait, $sspa_most));
                ?>
            </p>
            <p>
                <?php esc_html_e('The fix is a reliable resolver, for example Cloudflare (1.1.1.1) or Google (8.8.8.8). Ask your host to change it, or change the nameserver lines in /etc/resolv.conf (or the network configuration that writes them) if you manage the server. Then check again.', 'super-speedy-performance-analysis'); ?>
            </p>
        <?php endif; ?>
    <?php endif; ?>
</div>
