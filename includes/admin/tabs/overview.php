<?php
defined('ABSPATH') || exit;

global $wpdb;
$sspa_health = SSPA_Helper_Files::health();
$sspa_active_run = SSPA_Run_Controller::active_run_id();
$sspa_runs_table = SSPA_Schema::table('runs');
// Score/insights only exist on profiling runs - a deep or cache run here would render
// score 0 and an empty insight list.
$sspa_last_run = $wpdb->get_row($wpdb->prepare("SELECT * FROM %i WHERE status = 'done' AND run_type IN ('baseline','spot') ORDER BY id DESC LIMIT 1", $sspa_runs_table), ARRAY_A);
// Show the ownership notice for every foreign database drop-in.
$sspa_foreign_dropin = in_array($sspa_health['dropin'], array('foreign', 'qm'), true);
$sspa_run_notes = $sspa_last_run ? json_decode((string) $sspa_last_run['notes'], true) : null;
$sspa_demo = $sspa_last_run ? SSPA_Demographics::latest() : null;
?>
<?php
// After a completed analysis its result view opens with the share panel while sharing is off.
if ($sspa_last_run) {
    SSPA_Share_Prompt::render();
}
?>
<?php include SSPA_PLUGIN_DIR . 'includes/admin/partials/analysis-panel.php'; ?>

<?php if ($sspa_last_run) : ?>
    <div class="sspa-placeholder sspa-score-row">
        <div class="sspa-score <?php echo (int) $sspa_run_notes['score'] >= 80 ? 'good' : ((int) $sspa_run_notes['score'] >= 50 ? 'ok' : 'bad'); ?>">
            <span class="sspa-score-number"><?php echo (int) $sspa_run_notes['score']; ?><span class="sspa-score-denom">/100</span></span>
            <span class="sspa-score-label"><?php esc_html_e('site score', 'super-speedy-performance-analysis'); ?></span>
        </div>
        <div class="sspa-score-meta">
            <h2><?php esc_html_e('Top insights', 'super-speedy-performance-analysis'); ?></h2>
            <?php
            $sspa_top = SSPA_Insights::top($sspa_last_run['id'], 5);
            if (!$sspa_top) {
                echo '<p>' . esc_html__('No performance problems found - nice site.', 'super-speedy-performance-analysis') . '</p>';
            }
            // At most one primary and one secondary offer on this screen, each placed inside
            // the finding or block whose measured evidence triggered it.
            $sspa_offers = SSPA_Offers::overview($sspa_last_run['id']);
            $sspa_offer_at = array();
            $sspa_offer_loose = array();
            $sspa_top_ids = array_map('intval', array_column($sspa_top, 'id'));
            foreach ($sspa_offers as $sspa_offer) {
                if ($sspa_offer['finding_id'] && in_array((int) $sspa_offer['finding_id'], $sspa_top_ids, true)) {
                    $sspa_offer_at[(int) $sspa_offer['finding_id']] = $sspa_offer;
                } elseif (in_array($sspa_offer['anchor'], array('autoload', 'deep'), true)) {
                    $sspa_offer_at[$sspa_offer['anchor']] = $sspa_offer;
                } else {
                    $sspa_offer_loose[] = $sspa_offer;
                }
            }
            ?>
            <ol class="sspa-insights">
            <?php foreach ($sspa_top as $sspa_f) :
                $sspa_r = SSPA_Insights::render($sspa_f); ?>
                <li class="sspa-insight sspa-sev-<?php echo esc_attr($sspa_f['severity']); ?>">
                    <strong><?php echo esc_html($sspa_r['headline']); ?></strong>
                    <?php if ($sspa_r['detail']) : ?>
                        <div class="sspa-insight-detail"><code><?php echo esc_html(mb_substr($sspa_r['detail'], 0, 400)); ?></code></div>
                    <?php endif; ?>
                    <?php if (!empty($sspa_r['sql'])) : ?>
                        <?php // Not truncated: a statement cut off part way through is worse than useless. ?>
                        <pre class="sspa-insight-sql"><?php echo esc_html($sspa_r['sql']); ?></pre>
                    <?php endif; ?>
                    <?php if ($sspa_r['rec_body']) : ?>
                        <p class="sspa-insight-rec"><?php echo esc_html($sspa_r['rec_body']); ?></p>
                    <?php endif; ?>
                    <?php echo wp_kses_post(SSPA_Insights::page_links_html($sspa_f)); ?>
                    <?php
                    if (isset($sspa_offer_at[(int) $sspa_f['id']])) {
                        echo wp_kses(SSPA_Offers::render($sspa_offer_at[(int) $sspa_f['id']], 'overview'), SSPA_Offers::allowed_html());
                    }
                    ?>
                </li>
            <?php endforeach; ?>
            </ol>
            <?php foreach ($sspa_offer_loose as $sspa_offer) : ?>
                <div class="sspa-offer-next">
                    <h3><?php esc_html_e('Suggested next step', 'super-speedy-performance-analysis'); ?></h3>
                    <?php
                    $sspa_loose_row = null;
                    if ($sspa_offer['finding_id']) {
                        // A measured finding outside the top five: show it with its free fix.
                        $sspa_loose_finding = null;
                        $sspa_loose_row = null;
                        foreach (SSPA_Insights::top($sspa_last_run['id'], 1000) as $sspa_candidate) {
                            if ((int) $sspa_candidate['id'] === (int) $sspa_offer['finding_id']) {
                                $sspa_loose_row = $sspa_candidate;
                                $sspa_loose_finding = SSPA_Insights::render($sspa_candidate);
                                break;
                            }
                        }
                        if ($sspa_loose_finding) {
                            $sspa_offer['evidence'] = $sspa_loose_finding['headline'];
                            $sspa_offer['free_fix'] = $sspa_loose_finding['rec_body'];
                        }
                    }
                    echo wp_kses(SSPA_Offers::render($sspa_offer, 'overview', true), SSPA_Offers::allowed_html());
                    if (!empty($sspa_loose_row)) {
                        echo wp_kses_post(SSPA_Insights::page_links_html($sspa_loose_row));
                    }
                    ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <?php
    // Autoload sits in its own block, not the top-5 list. It is one site-wide setting rather
    // than a per-page problem, it competes badly against critical query findings, and its
    // copy-and-paste SQL needs more room than a list item.
    $sspa_autoload = SSPA_Insights::standalone($sspa_last_run['id'], 'autoload_coverage');
    if ($sspa_autoload) :
        $sspa_a = SSPA_Insights::render($sspa_autoload);
        ?>
        <div class="sspa-placeholder">
            <h2><?php esc_html_e('Autoloaded options', 'super-speedy-performance-analysis'); ?></h2>
            <p><strong><?php echo esc_html($sspa_a['headline']); ?></strong></p>
            <?php if ($sspa_a['rec_body']) : ?>
                <p class="description"><?php echo esc_html($sspa_a['rec_body']); ?></p>
            <?php endif; ?>
            <?php if (!empty($sspa_a['sql'])) : ?>
                <pre class="sspa-insight-sql"><?php echo esc_html($sspa_a['sql']); ?></pre>
            <?php endif; ?>
            <?php
            // The SQL above is the manual fix. Scalability Pro manages autoloading for you,
            // which is the same job done continuously, so its offer belongs here, next to the
            // finding, when the screen's offer selection chose it for this finding.
            if (isset($sspa_offer_at['autoload'])) {
                echo wp_kses(SSPA_Offers::render($sspa_offer_at['autoload'], 'overview'), SSPA_Offers::allowed_html());
            }
            ?>
        </div>
    <?php endif; ?>

    <?php
    // Reactions get their own block for the opposite reason to autoload: nothing was measured,
    // so they rank last among the warns and vanish from the top-5 - and this is the one finding
    // that is about the site owner's plugins doing something to his site rather than about
    // speed. Every reaction of the run is listed, not just the first.
    $sspa_reactions = SSPA_Insights::all_of_type($sspa_last_run['id'], 'isolation_reaction');
    if ($sspa_reactions) :
        ?>
        <div class="sspa-placeholder">
            <h2><?php esc_html_e('Plugins that reacted to being measured', 'super-speedy-performance-analysis'); ?></h2>
            <ul class="sspa-insights">
                <?php foreach ($sspa_reactions as $sspa_reaction) :
                    $sspa_r = SSPA_Insights::render($sspa_reaction); ?>
                    <li class="sspa-insight sspa-sev-warn">
                        <strong><?php echo esc_html($sspa_r['headline']); ?></strong>
                        <?php if ($sspa_r['detail']) : ?>
                            <p class="description"><?php echo esc_html($sspa_r['detail']); ?></p>
                        <?php endif; ?>
                        <?php if ($sspa_r['rec_body']) : ?>
                            <p class="sspa-insight-rec"><?php echo esc_html($sspa_r['rec_body']); ?></p>
                        <?php endif; ?>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!empty($sspa_run_notes['unmeasured_pages'])) : ?>
    <div class="notice notice-error inline">
        <p>
            <strong><?php esc_html_e('Incomplete run:', 'super-speedy-performance-analysis'); ?></strong>
            <?php
            printf(
                /* translators: %d: number of pages */
                esc_html__('%d page(s) could not be measured - a page cache or CDN answered before PHP ran, so they have no data and the score above ignores them. See the Pages tab for which ones. If this persists after updating the plugin, exclude requests carrying the X-SSPA-Token header (or the sspa_nc query argument) from your cache.', 'super-speedy-performance-analysis'),
                (int) $sspa_run_notes['unmeasured_pages']
            );
            ?>
        </p>
    </div>
    <?php endif; ?>

    <?php
    // Deep runs land back on this tab when they finish - surface their outcome here
    // instead of leaving the results buried in the Plugins tab.
    $sspa_deep_run = SSPA_Report::latest_deep_run();
    if ($sspa_deep_run) :
        $sspa_deep_notes = json_decode((string) $sspa_deep_run['notes'], true);
        $sspa_deep_notes = is_array($sspa_deep_notes) ? $sspa_deep_notes : array(); ?>
    <div class="sspa-placeholder">
        <h2><?php esc_html_e('Latest Plugin Impact Analysis', 'super-speedy-performance-analysis'); ?></h2>
        <p>
            <?php
            printf(
                /* translators: 1: date, 2: plugins, 3: pages, 4: measurable impacts */
                esc_html__('%1$s: swept %2$d plugin(s) across %3$d page(s) - %4$d measurable impact(s) found.', 'super-speedy-performance-analysis'),
                esc_html(get_date_from_gmt($sspa_deep_run['finished'], get_option('date_format') . ' ' . get_option('time_format'))),
                isset($sspa_deep_notes['plugins']) ? (int) $sspa_deep_notes['plugins'] : 0,
                isset($sspa_deep_notes['pages']) ? (int) $sspa_deep_notes['pages'] : 0,
                isset($sspa_deep_notes['impacts']) ? (int) $sspa_deep_notes['impacts'] : 0
            );
            if (!empty($sspa_deep_notes['phase2_plugins'])) {
                echo ' ' . esc_html(sprintf(
                    /* translators: 1: number of plugins, 2: optional extra clause about cache modes */
                    __('%1$d plugin(s) showed impact in the screening pass and got the full page-by-page treatment%2$s.', 'super-speedy-performance-analysis'),
                    (int) $sspa_deep_notes['phase2_plugins'],
                    count((array) ($sspa_deep_notes['modes'] ?? array())) > 1 ? __(' including object-cache-disabled and priming measurements', 'super-speedy-performance-analysis') : ''
                ));
            }
            $sspa_unres = $sspa_deep_notes['unresolved'] ?? 0;
            $sspa_unres = is_array($sspa_unres) ? count($sspa_unres) : (int) $sspa_unres;
            if ($sspa_unres) {
                /* translators: %d: number of plugin/page cells that could not be measured */
                echo ' ' . esc_html(sprintf(__('%d cell(s) could not be measured (page failed without the plugin - usually a dependency).', 'super-speedy-performance-analysis'), $sspa_unres));
            }
            ?>
            <?php esc_html_e('Results are in the Plugins tab, "Measured impact" column.', 'super-speedy-performance-analysis'); ?>
        </p>
        <?php
        if (isset($sspa_offer_at['deep'])) {
            echo wp_kses(SSPA_Offers::render($sspa_offer_at['deep'], 'overview', true), SSPA_Offers::allowed_html());
        }
        ?>
        <?php if (!empty($sspa_deep_notes['fatal_cells'])) : ?>
        <div class="notice notice-info inline">
            <p>
                <strong><?php esc_html_e('Hard dependencies discovered:', 'super-speedy-performance-analysis'); ?></strong>
                <?php
                $sspa_fatal_bits = array();
                foreach ((array) $sspa_deep_notes['fatal_cells'] as $sspa_fc) {
                    $sspa_fatal_bits[] = sprintf(
                        /* translators: 1: plugin slug, 2: page key */
                        __('%1$s (page: %2$s)', 'super-speedy-performance-analysis'),
                        $sspa_fc['plugin'],
                        $sspa_fc['page_key']
                    );
                }
                echo esc_html(implode(', ', $sspa_fatal_bits) . '. ');
                esc_html_e('These pages fatally error when that plugin is disabled, so its cost there cannot be measured - other code depends on it at runtime. This only ever happens inside the analysis\'s own test requests: no visitor saw an error, and the plugin was never actually deactivated. If WordPress emailed you a "technical issue" warning during the run, it was reacting to one of these test requests and usually blames the plugin whose file threw (often WooCommerce) rather than the excluded one.', 'super-speedy-performance-analysis');
                ?>
            </p>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if ($sspa_demo) :
        $sspa_m = $sspa_demo['metrics']; ?>
    <div class="sspa-placeholder">
        <h2><?php esc_html_e('Site profile', 'super-speedy-performance-analysis'); ?></h2>
        <p>
            <?php
            $sspa_bits = array();
            /* translators: %s: the site sector, e.g. "retail" */
            $sspa_bits[] = sprintf(__('Sector: %s', 'super-speedy-performance-analysis'), $sspa_demo['sector']);
            foreach (array('post' => 'posts', 'page' => 'pages', 'product' => 'products') as $sspa_pt => $sspa_label) {
                if (!empty($sspa_m['post_counts'][$sspa_pt])) {
                    $sspa_bits[] = number_format($sspa_m['post_counts'][$sspa_pt]) . ' ' . $sspa_label;
                }
            }
            $sspa_bits[] = number_format($sspa_m['users']) . ' users';
            if (!empty($sspa_m['postmeta_rows'])) {
                $sspa_bits[] = '~' . number_format($sspa_m['postmeta_rows']) . ' postmeta rows';
            }
            $sspa_bits[] = 'DB ' . size_format((int) $sspa_m['db_bytes']);
            $sspa_bits[] = 'PHP ' . $sspa_m['php'];
            $sspa_bits[] = $sspa_m['object_cache'] ? __('persistent object cache', 'super-speedy-performance-analysis') : __('no persistent object cache', 'super-speedy-performance-analysis');
            echo esc_html(implode(' &middot; ', $sspa_bits));
            ?>
        </p>
    </div>
    <?php endif; ?>

    <?php
    $sspa_blob_bytes = (int) $wpdb->get_var($wpdb->prepare('SELECT COALESCE(SUM(LENGTH(profile_blob)), 0) FROM %i', SSPA_Schema::table('profiles')));
    $sspa_run_count = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM %i', $sspa_runs_table));
    $sspa_retention = (int) sspa_get_option('blob_retention_runs');
    ?>
    <div class="sspa-placeholder">
        <h2><?php esc_html_e('Stored data', 'super-speedy-performance-analysis'); ?></h2>
        <p>
            <?php /* translators: 1: total size of stored profile data, 2: number of runs */ printf(esc_html__('Detailed profile data: %1$s across %2$d runs. Summary metrics and findings are kept forever; only the detailed per-query data below is prunable.', 'super-speedy-performance-analysis'), esc_html(size_format($sspa_blob_bytes)), (int) $sspa_run_count); ?>
        </p>
        <p>
            <button type="button" class="button" id="sspa-prune-blobs" data-keep="<?php echo esc_attr($sspa_retention); ?>">
                <?php /* translators: %d: number of runs of detailed data kept */ printf(esc_html__('Delete detailed data older than the last %d runs', 'super-speedy-performance-analysis'), (int) $sspa_retention); ?>
            </button>
            <span class="description"><?php esc_html_e('When sharing is enabled, affected runs are saved to the durable local submission queue before their detailed source data is deleted.', 'super-speedy-performance-analysis'); ?></span>
        </p>
    </div>
<?php endif; ?>

<?php
// The full cache analysis lives on the Cache performance tab. Overview keeps two lines.
$sspa_cache_check = SSPA_Cache_Delivery::latest();
// Shown with or without a completed analysis: the check itself needs none.
$sspa_cache_recon = $sspa_last_run ? SSPA_Insights::standalone($sspa_last_run['id'], 'cache_safety') : null;
?>
<div class="sspa-placeholder sspa-cache-overview">
    <h2><?php esc_html_e('Cache performance', 'super-speedy-performance-analysis'); ?></h2>
    <p>
        <?php
        if ($sspa_cache_check) {
            $sspa_cache_words = array(
                'cached' => __('cached', 'super-speedy-performance-analysis'),
                'partly_cached' => __('partly cached', 'super-speedy-performance-analysis'),
                'not_cached' => __('not cached', 'super-speedy-performance-analysis'),
                'unknown' => __('unknown', 'super-speedy-performance-analysis'),
            );
            printf(
                /* translators: 1: cache state for anonymous visitors, 2: cache state for visitors with a basket, 3: cache state for logged-in customers */
                esc_html__('Visitors: %1$s. Visitors with a basket: %2$s. Logged-in customers: %3$s.', 'super-speedy-performance-analysis'),
                '<strong>' . esc_html($sspa_cache_words[$sspa_cache_check['audiences']['anonymous']['headline']]) . '</strong>',
                '<strong>' . esc_html($sspa_cache_words[$sspa_cache_check['audiences']['basket']['headline']]) . '</strong>',
                '<strong>' . esc_html($sspa_cache_words[$sspa_cache_check['audiences']['customer']['headline']]) . '</strong>'
            );
        } else {
            esc_html_e('Not checked yet: see whether visitors, visitors with a basket and logged-in customers get cached pages.', 'super-speedy-performance-analysis');
        }
        ?>
    </p>
    <p>
        <?php if ($sspa_cache_recon) :
            $sspa_cache_rendered = SSPA_Insights::render($sspa_cache_recon); ?>
            <?php echo esc_html($sspa_cache_rendered['headline']); ?>
        <?php endif; ?>
        <a href="#cache" class="sspa-goto-tab" data-tab="cache"><?php esc_html_e('Open Cache performance', 'super-speedy-performance-analysis'); ?> &rarr;</a>
    </p>
</div>

<div class="sspa-placeholder">
    <h2><?php esc_html_e('Health', 'super-speedy-performance-analysis'); ?></h2>
    <ul class="sspa-health">
        <li>
            <?php echo $sspa_health['mu'] ? '&#9989;' : '&#10060;'; ?>
            <?php esc_html_e('Profiler loader (mu-plugin)', 'super-speedy-performance-analysis'); ?>:
            <?php
            if ($sspa_health['mu']) {
                esc_html_e('installed', 'super-speedy-performance-analysis');
            } elseif (!empty($sspa_health['file_mods_blocked'])) {
                // A deliberate site setting, not a permissions problem. Saying "not writable"
                // here would send the user to chmod something that is not the cause.
                esc_html_e('not installed - this site sets DISALLOW_FILE_MODS, which forbids plugins from writing files', 'super-speedy-performance-analysis');
            } elseif (isset($sspa_health['mu_reason']) && 'stale' === $sspa_health['mu_reason']) {
                // Writable, just not refreshed yet. Telling someone to chmod a directory that
                // is already writable is the worst kind of wrong advice.
                esc_html_e('out of date - use Enable profiler in Tools to update the loader', 'super-speedy-performance-analysis');
            } elseif (isset($sspa_health['mu_reason']) && 'disabled' === $sspa_health['mu_reason']) {
                esc_html_e('not enabled - use Enable profiler in Tools', 'super-speedy-performance-analysis');
            } else {
                esc_html_e('not installed - wp-content/mu-plugins is not writable', 'super-speedy-performance-analysis');
            }
            ?>
        </li>
        <li>
            <?php
            switch ($sspa_health['dropin']) {
                case 'ours':
                    if (!empty($sspa_health['dropin_stale'])) {
                        echo '&#10060; ' . esc_html__('Query capture (db.php) is stale. Use Enable profiler in Tools to refresh it.', 'super-speedy-performance-analysis');
                        break;
                    }
                    echo '&#9989; ' . esc_html__('Query capture (db.php): full detail - row counts and errors per query', 'super-speedy-performance-analysis');
                    break;
                case 'qm':
                    if (!empty($sspa_health['stale_qm'])) {
                        echo '&#10060; ' . esc_html__('Query capture: Query Monitor\'s db.php is still installed but Query Monitor is DEACTIVATED. Runs use degraded capture. Remove the leftover file manually if you want SSPA to install its own drop-in.', 'super-speedy-performance-analysis');

                    } else {
                        echo '&#9888;&#65039; ' . esc_html__('Query capture: riding Query Monitor\'s db.php. Logged-in page profiles get full detail, but Query Monitor collects nothing for anonymous (logged-out) requests, so front-end profiles will have no per-query data - and its per-query backtraces inflate every timing. The existing drop-in is never replaced.', 'super-speedy-performance-analysis');
                    }
                    break;
                case 'foreign':
                    echo '&#9888;&#65039; ' . sprintf(
                        /* translators: %s: plugin name owning db.php */
                        esc_html__('Query capture: db.php is owned by %s - runs use degraded capture without per-query row counts; the existing drop-in is never replaced', 'super-speedy-performance-analysis'),
                        esc_html($sspa_health['dropin_owner'])
                    );
                    break;
                default:
                    echo '&#10060; ' . esc_html__('Query capture (db.php): not installed - wp-content is not writable; runs will use degraded capture', 'super-speedy-performance-analysis');
            }
            ?>
        </li>
    </ul>
</div>
