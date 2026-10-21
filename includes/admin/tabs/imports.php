<?php
defined('ABSPATH') || exit;

// Imports: this site's own measured import speed, beside the published Super Speedy Imports
// benchmarks, quoted verbatim with their source and conditions. The two are never combined:
// no projection of what this site would save is shown.

$sspa_imports_offer = SSPA_Offers::get('imports');
$sspa_imports_importers = SSPA_Offers::active_in('importer');
$sspa_imports_runs = SSPA_Imports::wp_all_import_runs();
$sspa_imports_measured = SSPA_Offers::measured_importers();
$sspa_imports_pro_active = SSPA_Offers::product_active('imports');
$sspa_imports_lite_active = SSPA_Offers::lite_active();
$sspa_imports_figures = array('lite');
foreach (array_merge(array_keys($sspa_imports_importers), array_keys($sspa_imports_measured)) as $sspa_imports_slug) {
    if (isset($sspa_imports_offer['importer_figures'][$sspa_imports_slug])) {
        $sspa_imports_figures[] = $sspa_imports_offer['importer_figures'][$sspa_imports_slug];
    }
}
$sspa_imports_figures = array_values(array_unique($sspa_imports_figures));
?>
<div class="sspa-imports-tab">
    <div class="sspa-placeholder">
        <h2><?php esc_html_e('Import speed', 'super-speedy-performance-analysis'); ?></h2>
        <p class="description"><?php esc_html_e('How long your imports take on this site, measured from your importer\'s own records, beside the published Super Speedy Imports benchmarks.', 'super-speedy-performance-analysis'); ?></p>
        <p>
            <?php
            if ($sspa_imports_importers) {
                /* translators: %s: comma-separated importer plugin names */
                echo esc_html(sprintf(__('Importer active on this site: %s.', 'super-speedy-performance-analysis'), implode(', ', $sspa_imports_importers)));
            } else {
                esc_html_e('No importer plugin is active on this site.', 'super-speedy-performance-analysis');
            }
            ?>
        </p>
    </div>

    <div class="sspa-placeholder sspa-imports-measured">
        <h2><?php esc_html_e('Your site, measured', 'super-speedy-performance-analysis'); ?></h2>
        <?php if ($sspa_imports_runs) : ?>
            <p class="description"><?php esc_html_e('From WP All Import\'s own run history: the latest finished run of each import.', 'super-speedy-performance-analysis'); ?></p>
            <table class="widefat striped sspa-imports-history">
                <thead>
                    <tr>
                        <th scope="col"><?php esc_html_e('Import', 'super-speedy-performance-analysis'); ?></th>
                        <th scope="col"><?php esc_html_e('Run started', 'super-speedy-performance-analysis'); ?></th>
                        <th scope="col"><?php esc_html_e('Records', 'super-speedy-performance-analysis'); ?></th>
                        <th scope="col"><?php esc_html_e('Run time', 'super-speedy-performance-analysis'); ?></th>
                        <th scope="col"><?php esc_html_e('Speed', 'super-speedy-performance-analysis'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sspa_imports_runs as $sspa_imports_run) : ?>
                        <tr>
                            <td><?php echo esc_html($sspa_imports_run['name']); ?></td>
                            <td><?php echo esc_html(mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $sspa_imports_run['date'])); ?></td>
                            <td>
                                <?php
                                echo esc_html(sprintf(
                                    /* translators: 1: records processed, 2: created, 3: updated, 4: skipped */
                                    _n('%1$s record (%2$s created, %3$s updated, %4$s skipped)', '%1$s records (%2$s created, %3$s updated, %4$s skipped)', $sspa_imports_run['records'], 'super-speedy-performance-analysis'),
                                    number_format_i18n($sspa_imports_run['records']),
                                    number_format_i18n($sspa_imports_run['created']),
                                    number_format_i18n($sspa_imports_run['updated']),
                                    number_format_i18n($sspa_imports_run['skipped'])
                                ));
                                ?>
                            </td>
                            <td><?php echo esc_html(SSPA_Imports::duration($sspa_imports_run['seconds'])); ?></td>
                            <td>
                                <?php
                                /* translators: %s: records per second */
                                echo esc_html(sprintf(__('%s records per second', 'super-speedy-performance-analysis'), number_format_i18n($sspa_imports_run['records_per_second'], 1)));
                                ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php foreach ($sspa_imports_measured as $sspa_imports_key => $sspa_imports_row) : ?>
            <p class="sspa-imports-traffic">
                <?php
                $sspa_imports_label = 'woocommerce' === $sspa_imports_key ? __('WooCommerce product importer', 'super-speedy-performance-analysis') : $sspa_imports_row['action'];
                echo esc_html(sprintf(
                    /* translators: 1: importer name, 2: number of requests, 3: collection start date, 4: median milliseconds, 5: p95 milliseconds */
                    _n('%1$s: %2$s import request in the Traffic collection from %3$s, median %4$s ms and 95th percentile %5$s ms.', '%1$s: %2$s import requests in the Traffic collection from %3$s, median %4$s ms and 95th percentile %5$s ms each.', $sspa_imports_row['count'], 'super-speedy-performance-analysis'),
                    $sspa_imports_label,
                    number_format_i18n($sspa_imports_row['count']),
                    $sspa_imports_row['started_at'] ? date_i18n(get_option('date_format'), strtotime($sspa_imports_row['started_at'])) : '',
                    number_format_i18n((float) $sspa_imports_row['median_ms'], 0),
                    number_format_i18n((float) $sspa_imports_row['p95_ms'], 0)
                ));
                ?>
            </p>
        <?php endforeach; ?>
        <?php if (!$sspa_imports_runs && !$sspa_imports_measured) : ?>
            <p>
                <?php
                if (SSPA_Imports::wp_all_import_active()) {
                    esc_html_e('WP All Import has no finished run in its history yet. Run an import and its time appears here.', 'super-speedy-performance-analysis');
                } else {
                    esc_html_e('No import has been measured on this site. To measure one, start a Traffic collection on the Traffic tab, run your import, then stop the collection: the importer\'s requests appear here.', 'super-speedy-performance-analysis');
                }
                ?>
            </p>
        <?php endif; ?>
    </div>

    <div class="sspa-placeholder sspa-imports-published">
        <h2><?php esc_html_e('Published Super Speedy Imports benchmarks', 'super-speedy-performance-analysis'); ?></h2>
        <p class="description"><?php esc_html_e('Quoted from the published benchmark pages, with their conditions. Your times depend on your server, data and images.', 'super-speedy-performance-analysis'); ?></p>
        <?php
        foreach ($sspa_imports_figures as $sspa_imports_figure) {
            if (isset($sspa_imports_offer['figures'][$sspa_imports_figure])) {
                echo wp_kses(SSPA_Offers::quote_html($sspa_imports_offer['figures'][$sspa_imports_figure], 'sspa-cache-quote sspa-imports-figure'), SSPA_Offers::allowed_html());
            }
        }
        ?>
    </div>

    <div class="sspa-placeholder sspa-imports-offers">
        <h2><?php esc_html_e('Super Speedy Imports', 'super-speedy-performance-analysis'); ?></h2>
        <?php
        if ($sspa_imports_pro_active) {
            echo '<p>' . esc_html__('Super Speedy Imports Pro is active on this site.', 'super-speedy-performance-analysis') . '</p>';
        } else {
            $sspa_imports_matches = array();
            if (!SSPA_Offers::is_dismissed('imports')) {
                $sspa_imports_matches[] = array('offer' => 'imports', 'trigger' => 'imports_tab', 'role' => 'primary', 'importer' => '', 'evidence' => '', 'free_fix' => '', 'context' => '', 'anchor' => '');
            }
            if (SSPA_Imports::wp_all_import_active() && !SSPA_Offers::product_active('scalability_pro') && !SSPA_Offers::is_dismissed('scalability_pro')) {
                $sspa_imports_matches[] = array('offer' => 'scalability_pro', 'trigger' => 'imports_tab', 'role' => $sspa_imports_matches ? 'secondary' : 'primary', 'importer' => '', 'evidence' => '', 'free_fix' => '', 'context' => __('For sites importing with WP All Import:', 'super-speedy-performance-analysis'), 'anchor' => '');
            }
            if ($sspa_imports_lite_active) {
                echo '<p>' . esc_html__('Super Speedy Imports Lite is active on this site.', 'super-speedy-performance-analysis') . '</p>';
            }
            foreach ($sspa_imports_matches as $sspa_imports_match) {
                echo wp_kses(SSPA_Offers::render($sspa_imports_match, 'imports_tab', false, false), SSPA_Offers::allowed_html());
            }
        }
        ?>
    </div>
</div>
