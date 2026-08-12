<?php
defined('ABSPATH') || exit;

global $wpdb;
$sspa_health = SSPA_Helper_Files::health();
$sspa_active_run = SSPA_Run_Controller::active_run_id();
$sspa_runs_table = SSPA_Schema::table('runs');
// Score/insights only exist on profiling runs - a deep or cache run here would render
// score 0 and an empty insight list.
$sspa_last_run = $wpdb->get_row("SELECT * FROM $sspa_runs_table WHERE status = 'done' AND run_type IN ('baseline','spot') ORDER BY id DESC LIMIT 1", ARRAY_A);
// Offer the temporary swap for foreign drop-ins AND an active Query Monitor's (anon
// requests capture nothing through QM's). A stale QM drop-in gets the permanent
// replace button on the health line instead.
$sspa_foreign_dropin = in_array($sspa_health['dropin'], array('foreign'), true)
    || ('qm' === $sspa_health['dropin'] && empty($sspa_health['stale_qm']));
$sspa_run_notes = $sspa_last_run ? json_decode((string) $sspa_last_run['notes'], true) : null;
$sspa_demo = $sspa_last_run ? SSPA_Demographics::latest() : null;
?>

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
            ?>
            <ol class="sspa-insights">
            <?php foreach ($sspa_top as $sspa_f) :
                $r = SSPA_Insights::render($sspa_f); ?>
                <li class="sspa-insight sspa-sev-<?php echo esc_attr($sspa_f['severity']); ?>">
                    <strong><?php echo esc_html($r['headline']); ?></strong>
                    <?php if ($r['detail']) : ?>
                        <div class="sspa-insight-detail"><code><?php echo esc_html(mb_substr($r['detail'], 0, 400)); ?></code></div>
                    <?php endif; ?>
                    <?php if (!empty($r['sql'])) : ?>
                        <?php // Not truncated: a statement cut off part way through is worse than useless. ?>
                        <pre class="sspa-insight-sql"><?php echo esc_html($r['sql']); ?></pre>
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
    $sspa_deep_run = $wpdb->get_row("SELECT * FROM $sspa_runs_table WHERE status = 'done' AND run_type = 'deep' ORDER BY id DESC LIMIT 1", ARRAY_A);
    if ($sspa_deep_run) :
        $sspa_deep_notes = json_decode((string) $sspa_deep_run['notes'], true);
        $sspa_deep_notes = is_array($sspa_deep_notes) ? $sspa_deep_notes : array(); ?>
    <div class="sspa-placeholder">
        <h2><?php esc_html_e('Latest plugin impact analysis', 'super-speedy-performance-analysis'); ?></h2>
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
                    __('%d plugin(s) showed impact in the screening pass and got the full page-by-page treatment%s.', 'super-speedy-performance-analysis'),
                    (int) $sspa_deep_notes['phase2_plugins'],
                    count((array) ($sspa_deep_notes['modes'] ?? array())) > 1 ? __(' including object-cache-disabled and priming measurements', 'super-speedy-performance-analysis') : ''
                ));
            }
            $sspa_unres = $sspa_deep_notes['unresolved'] ?? 0;
            $sspa_unres = is_array($sspa_unres) ? count($sspa_unres) : (int) $sspa_unres;
            if ($sspa_unres) {
                echo ' ' . esc_html(sprintf(__('%d cell(s) could not be measured (page failed without the plugin - usually a dependency).', 'super-speedy-performance-analysis'), $sspa_unres));
            }
            ?>
            <?php esc_html_e('Results are in the Plugins tab, "Measured impact" column.', 'super-speedy-performance-analysis'); ?>
        </p>
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
            <span class="description"><?php esc_html_e('When sharing is enabled, affected runs are saved to the durable local submission queue before their detailed source data is deleted.', 'super-speedy-performance-analysis'); ?></span>
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
                    if (!empty($sspa_health['stale_qm'])) {
                        echo '&#10060; ' . esc_html__('Query capture: Query Monitor\'s db.php is still installed but Query Monitor is DEACTIVATED. This orphaned drop-in blocks our capture layer and misattributes every query.', 'super-speedy-performance-analysis');
                        echo ' <button type="button" class="button button-small" id="sspa-replace-stale-dropin">' . esc_html__('Replace it with ours', 'super-speedy-performance-analysis') . '</button>';
                    } else {
                        echo '&#9888;&#65039; ' . esc_html__('Query capture: riding Query Monitor\'s db.php. Logged-in page profiles get full detail, but Query Monitor collects nothing for anonymous (logged-out) requests, so front-end profiles will have no per-query data - and its per-query backtraces inflate every timing. Tick the swap below (or deactivate Query Monitor) for clean, complete capture.', 'super-speedy-performance-analysis');
                    }
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
        <label style="margin-right:1em">
            <input type="checkbox" id="sspa-include-writes" value="1">
            <?php esc_html_e('Include write profiles (saves a TEMPORARY copy of a post/product and steps a TEMPORARY order through processing - created and deleted automatically, no real content touched, no emails sent)', 'super-speedy-performance-analysis'); ?>
        </label>
    </p>
    <p>
        <button type="button" class="button button-primary" id="sspa-run-analysis"<?php disabled((bool) $sspa_active_run); ?>>
            <?php esc_html_e('Run Analysis', 'super-speedy-performance-analysis'); ?>
        </button>
        <?php if ($sspa_last_run) : ?>
        <button type="button" class="button" id="sspa-run-deep"<?php disabled((bool) $sspa_active_run); ?>>
            <?php esc_html_e('Run Plugin Impact Analysis', 'super-speedy-performance-analysis'); ?>
        </button>
        <?php if (wp_using_ext_object_cache() || file_exists(WP_CONTENT_DIR . '/object-cache.php')) : ?>
        <button type="button" class="button" id="sspa-run-cache"<?php disabled((bool) $sspa_active_run); ?>>
            <?php esc_html_e('Run Cache Impact Analysis', 'super-speedy-performance-analysis'); ?>
        </button>
        <?php endif; ?>
        <?php endif; ?>
        <button type="button" class="button" id="sspa-cancel-run" <?php echo $sspa_active_run ? '' : 'style="display:none"'; ?>>
            <?php esc_html_e('Cancel', 'super-speedy-performance-analysis'); ?>
        </button>
    </p>
    <?php if ($sspa_last_run) : ?>
    <p class="description">
        <?php esc_html_e('Plugin Impact Analysis opens a chooser first: nothing is measured until you pick the plugins yourself, and the number of measurements and a time estimate are shown before anything starts. Each chosen plugin is then re-measured with that plugin excluded FOR THE TEST REQUESTS ONLY - your visitors always get the full site, no plugins are ever really deactivated, and nothing fires activation or deactivation hooks. Choosing every plugin runs the two-phase sweep: a cheap screen of each plugin on its busiest pages first, then the full treatment only for the plugins that showed a measurable impact. Start it, walk away - the floating monitor shows exactly where it is up to. Keeping this tab open (minimised is fine) is fastest; if you close it, WP-Cron continues the run in the background as your site receives traffic.', 'super-speedy-performance-analysis'); ?>
    </p>
    <?php endif; ?>

</div>
