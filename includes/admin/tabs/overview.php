<?php
defined('ABSPATH') || exit;

global $wpdb;
$sspa_health = SSPA_Helper_Files::health();
$sspa_active_run = SSPA_Run_Controller::active_run_id();
$sspa_runs_table = SSPA_Schema::table('runs');
$sspa_last_run = $wpdb->get_row("SELECT * FROM $sspa_runs_table WHERE status = 'done' ORDER BY id DESC LIMIT 1", ARRAY_A);
$sspa_foreign_dropin = in_array($sspa_health['dropin'], array('foreign'), true);
$sspa_run_notes = $sspa_last_run ? json_decode((string) $sspa_last_run['notes'], true) : null;
$sspa_demo = $sspa_last_run ? SSPA_Demographics::latest() : null;
?>

<?php if ($sspa_last_run) : ?>
    <div class="sspa-placeholder sspa-score-row">
        <div class="sspa-score <?php echo (int) $sspa_run_notes['score'] >= 80 ? 'good' : ((int) $sspa_run_notes['score'] >= 50 ? 'ok' : 'bad'); ?>">
            <span class="sspa-score-number"><?php echo (int) $sspa_run_notes['score']; ?></span>
            <span class="sspa-score-label"><?php esc_html_e('site score', 'super-speedy-performance-analysis'); ?></span>
        </div>
        <div class="sspa-score-meta">
            <h2><?php esc_html_e('Top insights', 'super-speedy-performance-analysis'); ?></h2>
            <?php
            $sspa_top = SSPA_Insights::top($sspa_last_run['id'], 5);
            if (!$sspa_top) {
                echo '<p>' . esc_html__('No performance problems found - nice site.', 'super-speedy-performance-analysis') . '</p>';
            }
            ?>
            <ol class="sspa-insights">
            <?php foreach ($sspa_top as $sspa_f) :
                $r = SSPA_Insights::render($sspa_f); ?>
                <li class="sspa-insight sspa-sev-<?php echo esc_attr($sspa_f['severity']); ?>">
                    <strong><?php echo esc_html($r['headline']); ?></strong>
                    <?php if ($r['detail']) : ?>
                        <div class="sspa-insight-detail"><code><?php echo esc_html(mb_substr($r['detail'], 0, 400)); ?></code></div>
                    <?php endif; ?>
                    <?php if ($r['rec_body']) : ?>
                        <p class="sspa-insight-rec"><?php echo esc_html($r['rec_body']); ?>
                        <?php if ($r['rec_link']) : ?>
                            <a href="<?php echo esc_url($r['rec_link']); ?>" target="_blank"><?php echo esc_html($r['rec_title']); ?> &rarr;</a>
                        <?php endif; ?>
                        </p>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
            </ol>
        </div>
    </div>

    <?php if ($sspa_demo) :
        $m = $sspa_demo['metrics']; ?>
    <div class="sspa-placeholder">
        <h2><?php esc_html_e('Site profile', 'super-speedy-performance-analysis'); ?></h2>
        <p>
            <?php
            $bits = array();
            $bits[] = sprintf(__('Sector: %s', 'super-speedy-performance-analysis'), $sspa_demo['sector']);
            foreach (array('post' => 'posts', 'page' => 'pages', 'product' => 'products') as $pt => $label) {
                if (!empty($m['post_counts'][$pt])) {
                    $bits[] = number_format($m['post_counts'][$pt]) . ' ' . $label;
                }
            }
            $bits[] = number_format($m['users']) . ' users';
            if (!empty($m['postmeta_rows'])) {
                $bits[] = '~' . number_format($m['postmeta_rows']) . ' postmeta rows';
            }
            $bits[] = 'DB ' . size_format((int) $m['db_bytes']);
            $bits[] = 'PHP ' . $m['php'];
            $bits[] = $m['object_cache'] ? __('persistent object cache', 'super-speedy-performance-analysis') : __('no persistent object cache', 'super-speedy-performance-analysis');
            echo esc_html(implode(' &middot; ', $bits));
            ?>
        </p>
    </div>
    <?php endif; ?>

    <?php
    $sspa_blob_bytes = (int) $wpdb->get_var('SELECT COALESCE(SUM(LENGTH(profile_blob)), 0) FROM ' . SSPA_Schema::table('profiles'));
    $sspa_run_count = (int) $wpdb->get_var("SELECT COUNT(*) FROM $sspa_runs_table");
    $sspa_retention = (int) sspa_get_option('blob_retention_runs');
    ?>
    <div class="sspa-placeholder">
        <h2><?php esc_html_e('Stored data', 'super-speedy-performance-analysis'); ?></h2>
        <p>
            <?php printf(esc_html__('Detailed profile data: %1$s across %2$d runs. Summary metrics and findings are kept forever; only the detailed per-query data below is prunable.', 'super-speedy-performance-analysis'), esc_html(size_format($sspa_blob_bytes)), $sspa_run_count); ?>
        </p>
        <p>
            <button type="button" class="button" id="sspa-prune-blobs" data-keep="<?php echo esc_attr($sspa_retention); ?>">
                <?php printf(esc_html__('Delete detailed data older than the last %d runs', 'super-speedy-performance-analysis'), $sspa_retention); ?>
            </button>
            <span class="description"><?php esc_html_e('Coming soon: contribute your anonymised results to the community database at superspeedy.org before deleting.', 'super-speedy-performance-analysis'); ?></span>
        </p>
    </div>
<?php endif; ?>

<div class="sspa-placeholder">
    <h2><?php esc_html_e('Health', 'super-speedy-performance-analysis'); ?></h2>
    <ul class="sspa-health">
        <li>
            <?php echo $sspa_health['mu'] ? '&#9989;' : '&#10060;'; ?>
            <?php esc_html_e('Profiler loader (mu-plugin)', 'super-speedy-performance-analysis'); ?>:
            <?php echo $sspa_health['mu'] ? esc_html__('installed', 'super-speedy-performance-analysis') : esc_html__('not installed - wp-content/mu-plugins is not writable', 'super-speedy-performance-analysis'); ?>
        </li>
        <li>
            <?php
            switch ($sspa_health['dropin']) {
                case 'ours':
                    echo '&#9989; ' . esc_html__('Query capture (db.php): full detail - row counts and errors per query', 'super-speedy-performance-analysis');
                    break;
                case 'qm':
                    echo '&#9989; ' . esc_html__('Query capture: riding Query Monitor\'s db.php - full detail available', 'super-speedy-performance-analysis');
                    break;
                case 'foreign':
                    echo '&#9888;&#65039; ' . sprintf(
                        /* translators: %s: plugin name owning db.php */
                        esc_html__('Query capture: db.php is owned by %s - runs will miss per-query row counts unless you choose the temporary swap below', 'super-speedy-performance-analysis'),
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

<div class="sspa-placeholder" id="sspa-run-panel"
     data-active-run="<?php echo esc_attr($sspa_active_run); ?>">
    <h2><?php esc_html_e('Analysis', 'super-speedy-performance-analysis'); ?></h2>

    <?php if ($sspa_last_run) : ?>
        <p>
            <?php
            printf(
                /* translators: 1: date, 2: page count */
                esc_html__('Last analysis: %1$s (%2$d pages profiled).', 'super-speedy-performance-analysis'),
                esc_html(get_date_from_gmt($sspa_last_run['finished'], get_option('date_format') . ' ' . get_option('time_format'))),
                (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . SSPA_Schema::table('profiles') . ' WHERE run_id = %d', $sspa_last_run['id']))
            );
            ?>
            <?php esc_html_e('See the Pages tab for results.', 'super-speedy-performance-analysis'); ?>
        </p>
    <?php else : ?>
        <p><?php esc_html_e('Run your first analysis to profile your key pages and see which plugins are costing you SQL time, RAM and page speed. The first run is read-only and non-destructive: it sends normal page requests to your own site (roughly four per page profiled).', 'super-speedy-performance-analysis'); ?></p>
    <?php endif; ?>

    <?php if ($sspa_foreign_dropin) : ?>
        <div class="notice notice-warning inline">
            <p>
                <strong><?php esc_html_e('Before you run:', 'super-speedy-performance-analysis'); ?></strong>
                <?php
                printf(
                    /* translators: %s: plugin owning db.php */
                    esc_html__('%s owns your database drop-in (wp-content/db.php). You can run degraded (no per-query row counts), or temporarily swap it for ours during the run. The swap disables that plugin\'s database layer SITE-WIDE for the duration - only choose it at a low-traffic time. It is restored automatically the moment the run finishes (or fails).', 'super-speedy-performance-analysis'),
                    esc_html($sspa_health['dropin_owner'])
                );
                ?>
            </p>
            <p>
                <label>
                    <input type="checkbox" id="sspa-swap-dropin" value="1">
                    <?php esc_html_e('Temporarily swap db.php for this run (I understand the warning above)', 'super-speedy-performance-analysis'); ?>
                </label>
            </p>
        </div>
    <?php endif; ?>

    <p>
        <button type="button" class="button button-primary" id="sspa-run-analysis"<?php disabled((bool) $sspa_active_run); ?>>
            <?php esc_html_e('Run Analysis', 'super-speedy-performance-analysis'); ?>
        </button>
        <button type="button" class="button" id="sspa-cancel-run" <?php echo $sspa_active_run ? '' : 'style="display:none"'; ?>>
            <?php esc_html_e('Cancel', 'super-speedy-performance-analysis'); ?>
        </button>
    </p>

    <div id="sspa-progress" style="display:none">
        <div class="sspa-progress-bar"><div class="sspa-progress-fill" style="width:0%"></div></div>
        <p class="sspa-progress-text"></p>
    </div>
</div>
