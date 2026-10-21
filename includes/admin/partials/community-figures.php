<?php
defined('ABSPATH') || exit;
// Community figures, rendered by SSPA_Community_Figures::render() after the administrator pressed
// "Load community figures". $sspa_figures is SSPA_Community_Figures::load()'s result.
$sspa_operator = __('superspeedy.org is operated by WP Intense OÜ, which also runs superspeedyplugins.com.', 'super-speedy-performance-analysis');

if ('not_published' === $sspa_figures['state']) : ?>
    <div class="sspa-community-figures-state" role="status">
        <p><strong><?php esc_html_e('superspeedy.org has not published community figures yet.', 'super-speedy-performance-analysis'); ?></strong>
            <?php esc_html_e('The public cohort and plugin-impact documents are not on the server yet, so there is nothing to compare with today. Nothing about this site was sent.', 'super-speedy-performance-analysis'); ?></p>
        <p><?php esc_html_e('You can browse what superspeedy.org already publishes:', 'super-speedy-performance-analysis'); ?> <a href="https://superspeedy.org/" target="_blank" rel="noopener noreferrer">https://superspeedy.org/</a></p>
        <p class="description"><?php echo esc_html($sspa_operator); ?></p>
    </div>
    <?php
    return;
endif;

if ('error' === $sspa_figures['state']) : ?>
    <div class="sspa-community-figures-state notice notice-error inline" role="alert">
        <p><?php esc_html_e('The community figures could not be loaded.', 'super-speedy-performance-analysis'); ?> <?php echo esc_html($sspa_figures['detail']); ?></p>
    </div>
    <?php
    return;
endif;

$sspa_site = $sspa_figures['site'];
$sspa_cohorts = $sspa_figures['cohorts'];
$sspa_impact = $sspa_figures['impact'];
$sspa_build = $sspa_cohorts ? $sspa_cohorts['build'] : ($sspa_impact ? $sspa_impact['build'] : array());
?>
<div class="sspa-community-figures-result">
    <p>
        <?php
        printf(
            /* translators: %s: UTC date and time of the superspeedy.org public build */
            esc_html__('Public figures from the superspeedy.org build of %s UTC.', 'super-speedy-performance-analysis'),
            esc_html(isset($sspa_build['built_at']) ? str_replace(array('T', 'Z'), array(' ', ''), (string) $sspa_build['built_at']) : '?')
        );
        ?>
        <?php echo esc_html($sspa_operator); ?>
        <?php esc_html_e('Every figure shows how many installations it comes from and a label for how much weight it can bear. Each installation counts once, as its own median. If this site shares its results, they may be part of these figures.', 'super-speedy-performance-analysis'); ?>
    </p>
    <p>
        <strong><?php esc_html_e('This site\'s cohort:', 'super-speedy-performance-analysis'); ?></strong>
        <?php
        echo esc_html($sspa_site['cohort']
            ? SSPA_Community_Figures::describe_cohort($sspa_site['cohort'])
            : __('not known until an analysis completes, so only figures for all sites are shown.', 'super-speedy-performance-analysis'));
        ?>
        <span class="description"><?php esc_html_e('Worked out on this site from its latest analysis; superspeedy.org was not told.', 'super-speedy-performance-analysis'); ?></span>
    </p>

    <h4><?php esc_html_e('Page generation time compared with similar sites', 'super-speedy-performance-analysis'); ?></h4>
    <?php if (!$sspa_cohorts) : ?>
        <p><?php esc_html_e('superspeedy.org has not published cohort figures yet.', 'super-speedy-performance-analysis'); ?></p>
    <?php elseif (!$sspa_figures['pages']) : ?>
        <p><?php esc_html_e('superspeedy.org has no published figures yet for the pages this site measured.', 'super-speedy-performance-analysis'); ?></p>
    <?php else : ?>
        <div class="sspa-table-scroll"><table class="widefat striped sspa-community-figures-table">
            <thead><tr>
                <th scope="col"><?php esc_html_e('Page', 'super-speedy-performance-analysis'); ?></th>
                <th scope="col"><?php esc_html_e('This site (ms)', 'super-speedy-performance-analysis'); ?></th>
                <th scope="col"><?php esc_html_e('Median (ms)', 'super-speedy-performance-analysis'); ?></th>
                <th scope="col"><?php esc_html_e('p75 (ms)', 'super-speedy-performance-analysis'); ?></th>
                <th scope="col"><?php esc_html_e('p95 (ms)', 'super-speedy-performance-analysis'); ?></th>
                <th scope="col"><?php esc_html_e('Installations', 'super-speedy-performance-analysis'); ?></th>
                <th scope="col"><?php esc_html_e('Confidence', 'super-speedy-performance-analysis'); ?></th>
                <th scope="col"><?php esc_html_e('Compared with', 'super-speedy-performance-analysis'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($sspa_figures['pages'] as $sspa_row) :
                $sspa_cell = $sspa_row['cell'];
                $sspa_metric = isset($sspa_cell['metrics']['page_gen_ms']) && is_array($sspa_cell['metrics']['page_gen_ms']) ? $sspa_cell['metrics']['page_gen_ms'] : null;
                $sspa_n = $sspa_metric ? (int) $sspa_metric['distinct_installations'] : (int) $sspa_cell['distinct_installations'];
                $sspa_label = $sspa_metric ? $sspa_metric['confidence'] : $sspa_cell['confidence'];
                $sspa_rung = (int) $sspa_cell['rung'];
                if (0 === $sspa_rung) {
                    $sspa_against = __('All sites', 'super-speedy-performance-analysis');
                } elseif (empty($sspa_cell['relaxed'])) {
                    $sspa_against = __('Sites like this one', 'super-speedy-performance-analysis');
                } else {
                    /* translators: %s: comma-separated cohort dimensions that were not matched */
                    $sspa_against = sprintf(__('Similar sites, not matched on %s', 'super-speedy-performance-analysis'), implode(', ', array_map(array('SSPA_Community_Figures', 'dimension_label'), (array) $sspa_cell['relaxed'])));
                }
                ?>
                <tr data-page-class="<?php echo esc_attr($sspa_cell['page_class']); ?>" data-rung="<?php echo esc_attr($sspa_rung); ?>" data-n="<?php echo esc_attr($sspa_n); ?>" data-confidence="<?php echo esc_attr($sspa_label); ?>">
                    <th scope="row"><code><?php echo esc_html($sspa_cell['page_class']); ?></code><br><small><?php echo esc_html($sspa_cell['variant'] . ' · ' . $sspa_cell['object_cache_mode']); ?></small></th>
                    <td class="sspa-figure-own"><?php echo esc_html($sspa_row['own'] ? SSPA_Community_Figures::ms($sspa_row['own']['page_gen_ms']) : '-'); ?></td>
                    <td class="sspa-figure-median"><?php echo esc_html($sspa_metric ? SSPA_Community_Figures::ms($sspa_metric['median']) : '-'); ?></td>
                    <td class="sspa-figure-p75"><?php echo esc_html($sspa_metric ? SSPA_Community_Figures::ms($sspa_metric['p75']) : '-'); ?></td>
                    <td class="sspa-figure-p95"><?php echo esc_html($sspa_metric ? SSPA_Community_Figures::ms($sspa_metric['p95']) : '-'); ?></td>
                    <td class="sspa-figure-n"><?php echo esc_html(SSPA_Community_Figures::installations($sspa_n)); ?></td>
                    <td class="sspa-figure-confidence"><?php echo esc_html(SSPA_Community_Figures::confidence_label($sspa_label, $sspa_cohorts)); ?></td>
                    <td class="sspa-figure-cohort"><?php echo esc_html($sspa_against); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <p class="description"><?php esc_html_e('p75 is published from 4 installations and p95 from 20; below that it is shown as -. This site\'s figure is the median from its latest full scan or spot check.', 'super-speedy-performance-analysis'); ?></p>
    <?php endif; ?>

    <h4><?php esc_html_e('Your active plugins, measured in isolation on other sites', 'super-speedy-performance-analysis'); ?></h4>
    <?php if (!$sspa_impact) : ?>
        <p><?php esc_html_e('superspeedy.org has not published plugin-impact figures yet.', 'super-speedy-performance-analysis'); ?></p>
    <?php elseif (!$sspa_figures['plugins']) : ?>
        <p><?php esc_html_e('superspeedy.org has no published isolation measurements yet for the plugins active here.', 'super-speedy-performance-analysis'); ?></p>
    <?php else : ?>
        <div class="sspa-table-scroll"><table class="widefat striped sspa-community-figures-table sspa-community-plugins-table">
            <thead><tr>
                <th scope="col"><?php esc_html_e('Plugin', 'super-speedy-performance-analysis'); ?></th>
                <th scope="col"><?php esc_html_e('Page', 'super-speedy-performance-analysis'); ?></th>
                <th scope="col"><?php esc_html_e('Time added (ms, median)', 'super-speedy-performance-analysis'); ?></th>
                <th scope="col"><?php esc_html_e('SQL time added (ms, median)', 'super-speedy-performance-analysis'); ?></th>
                <th scope="col"><?php esc_html_e('Installations', 'super-speedy-performance-analysis'); ?></th>
                <th scope="col"><?php esc_html_e('Confidence', 'super-speedy-performance-analysis'); ?></th>
            </tr></thead>
            <tbody>
            <?php foreach ($sspa_figures['plugins'] as $sspa_cell) :
                $sspa_time = isset($sspa_cell['metrics']['delta_ttfb_ms']) && is_array($sspa_cell['metrics']['delta_ttfb_ms']) ? $sspa_cell['metrics']['delta_ttfb_ms'] : null;
                $sspa_sql = isset($sspa_cell['metrics']['delta_sql_ms']) && is_array($sspa_cell['metrics']['delta_sql_ms']) ? $sspa_cell['metrics']['delta_sql_ms'] : null;
                $sspa_n = $sspa_time ? (int) $sspa_time['distinct_installations'] : (int) $sspa_cell['distinct_installations'];
                $sspa_label = $sspa_time ? $sspa_time['confidence'] : $sspa_cell['confidence'];
                ?>
                <tr data-plugin="<?php echo esc_attr($sspa_cell['slug']); ?>" data-page-class="<?php echo esc_attr($sspa_cell['page_class']); ?>" data-n="<?php echo esc_attr($sspa_n); ?>" data-confidence="<?php echo esc_attr($sspa_label); ?>">
                    <th scope="row"><code><?php echo esc_html($sspa_cell['slug']); ?></code></th>
                    <td><code><?php echo esc_html($sspa_cell['page_class']); ?></code></td>
                    <td class="sspa-figure-median"><?php echo esc_html($sspa_time ? SSPA_Community_Figures::ms($sspa_time['median']) : '-'); ?></td>
                    <td><?php echo esc_html($sspa_sql ? SSPA_Community_Figures::ms($sspa_sql['median']) : '-'); ?></td>
                    <td class="sspa-figure-n"><?php echo esc_html(SSPA_Community_Figures::installations($sspa_n)); ?></td>
                    <td class="sspa-figure-confidence"><?php echo esc_html(SSPA_Community_Figures::confidence_label($sspa_label, $sspa_impact)); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
        <p class="description"><?php esc_html_e('Isolation evidence: each installation measured the page with and without the plugin for its own test requests. A positive number is time the plugin added; a negative one is time it saved.', 'super-speedy-performance-analysis'); ?></p>
    <?php endif; ?>
</div>
