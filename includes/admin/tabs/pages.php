<?php
defined('ABSPATH') || exit;

global $wpdb;
$sspa_runs_table = SSPA_Schema::table('runs');
// Profiling runs only - deep-run profiles include plugin-set-modified re-measurements
// of the same pages, which read as duplicate/garbled rows here.
$sspa_last_run_id = (int) $wpdb->get_var("SELECT id FROM $sspa_runs_table WHERE status = 'done' AND run_type IN ('baseline','spot') ORDER BY id DESC LIMIT 1");

// Checkout flow: a permanent entry point, because the pages below are all GETs of an
// EMPTY cart - the expensive parts of a real purchase are POSTs a crawler never sends.
if (class_exists('WooCommerce')) : ?>
    <p class="sspa-checkout-cta">
        <button type="button" class="button button-secondary sspa-ck-open">
            <?php esc_html_e('Analyse checkout &amp; order flow', 'super-speedy-performance-analysis'); ?>
        </button>
        <span class="description">
            <?php esc_html_e('Measures one real purchase AND handling the order afterwards - viewing it in wp-admin and marking it completed - with every plugin active. Order deleted afterwards. Shows you the disclosure first.', 'super-speedy-performance-analysis'); ?>
        </span>
    </p>
<?php endif;

if (!$sspa_last_run_id) : ?>
    <div class="sspa-placeholder">
        <p><?php esc_html_e('Per-page profiles (TTFB, SQL time, PHP time, RAM, query counts) will appear here after your first analysis.', 'super-speedy-performance-analysis'); ?></p>
    </div>
<?php else :
    /*
     * Newest measurement per page, not "the last full run's rows".
     *
     * An ad-hoc check of a page the catalogue knows - the shop, the home page - files under
     * that page's key (0.13.0), which makes it the freshest thing this site knows about that
     * page. Showing the older full-run row beside a fresher one-page result, with no way to
     * see either, was the quarantine outliving its reason.
     *
     * Two limits stay: one-off URLs (url-<hash>) are excluded, because a hashed key nothing
     * else will ever re-measure is noise in a table of named pages; and the SITE SCORE and the
     * component totals on Overview and Plugins still come from baseline/spot runs only. A
     * score computed over whichever pages happened to be checked by hand is not a site score.
     */
    $sspa_profiles = $wpdb->get_results($wpdb->prepare(
        'SELECT p.*, r.run_type FROM ' . SSPA_Schema::table('profiles') . ' p
         INNER JOIN ' . $sspa_runs_table . " r ON r.id = p.run_id
         WHERE r.status = 'done' AND p.plugin_set_hash = ''
           AND (p.run_id = %d OR (r.run_type = 'adhoc' AND p.page_key NOT LIKE 'url-%%'))
         ORDER BY p.id ASC",
        $sspa_last_run_id
    ), ARRAY_A);

    $sspa_newest = array();
    foreach ($sspa_profiles as $sspa_p) {
        // Ordered by id ascending, so the last write per key is the newest measurement.
        $sspa_newest[$sspa_p['page_key']] = $sspa_p;
    }
    uasort($sspa_newest, function ($a, $b) {
        return (float) $b['page_gen_ms'] <=> (float) $a['page_gen_ms'];
    });
    ?>
    <p class="description">
        <?php esc_html_e('The newest measurement of each page, whether it came from a full analysis or from analysing that page on its own. Click any row for the full profile: request phases, per-plugin attribution, the slowest queries, outbound calls and what has been measured by disabling plugins here.', 'super-speedy-performance-analysis'); ?>
    </p>
    <table class="widefat striped sspa-pages-table">
        <thead>
            <tr>
                <th><?php esc_html_e('Page', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Variant', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Measured', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Generation (ms)', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('SQL (ms)', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Queries', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Rows fetched', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('HTTP (ms)', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('PHP (ms)', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Peak RAM', 'super-speedy-performance-analysis'); ?></th>
                <th><?php esc_html_e('Status', 'super-speedy-performance-analysis'); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($sspa_newest as $p) : ?>
            <tr class="sspa-page-row" data-profile-id="<?php echo (int) $p['id']; ?>" title="<?php esc_attr_e('Click for the full profile of this page', 'super-speedy-performance-analysis'); ?>">
                <td><code><?php echo esc_html($p['page_key']); ?></code></td>
                <td><?php echo esc_html($p['variant']); ?></td>
                <td>
                    <?php echo esc_html(get_date_from_gmt($p['created'], get_option('date_format') . ' ' . get_option('time_format'))); ?>
                    <?php if ('adhoc' === $p['run_type']) : ?>
                        <br><span class="sspa-badge"><?php esc_html_e('one-page analysis', 'super-speedy-performance-analysis'); ?></span>
                    <?php endif; ?>
                </td>
                <td><?php echo $p['page_gen_ms'] !== null ? esc_html(number_format((float) $p['page_gen_ms'], 1)) : '-'; ?></td>
                <td><?php echo $p['sql_ms'] !== null ? esc_html(number_format((float) $p['sql_ms'], 1)) : '-'; ?></td>
                <td><?php echo $p['sql_count'] !== null ? (int) $p['sql_count'] : '-'; ?></td>
                <td><?php echo $p['rows_returned_total'] !== null ? esc_html(number_format((int) $p['rows_returned_total'])) : '-'; ?></td>
                <td><?php echo $p['http_ms'] !== null ? esc_html(number_format((float) $p['http_ms'], 1)) : '-'; ?></td>
                <td><?php echo $p['php_ms'] !== null ? esc_html(number_format((float) $p['php_ms'], 1)) : '-'; ?></td>
                <td><?php echo $p['peak_mem_bytes'] ? esc_html(size_format((int) $p['peak_mem_bytes'])) : '-'; ?></td>
                <td>
                    <?php
                    if ($p['blocked_by']) {
                        echo '<span class="sspa-blocked">' . esc_html(sprintf(__('blocked by %s', 'super-speedy-performance-analysis'), $p['blocked_by'])) . '</span>';
                    } elseif ($p['page_gen_ms'] === null && 'baseline' !== $p['page_key']) {
                        echo '<span class="sspa-blocked">' . esc_html__('not measured - cache served it', 'super-speedy-performance-analysis') . '</span>';
                    } else {
                        echo (int) $p['response_code'];
                    }
                    ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
