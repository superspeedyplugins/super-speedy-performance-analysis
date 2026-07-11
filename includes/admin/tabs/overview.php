<?php
defined('ABSPATH') || exit;

global $wpdb;
$sspa_health = SSPA_Helper_Files::health();
$sspa_active_run = SSPA_Run_Controller::active_run_id();
$sspa_runs_table = SSPA_Schema::table('runs');
$sspa_last_run = $wpdb->get_row("SELECT * FROM $sspa_runs_table WHERE status = 'done' ORDER BY id DESC LIMIT 1", ARRAY_A);
$sspa_foreign_dropin = in_array($sspa_health['dropin'], array('foreign'), true);
?>

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
