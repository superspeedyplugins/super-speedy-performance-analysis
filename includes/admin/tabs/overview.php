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
    $sspa_cache_recon = SSPA_Insights::standalone($sspa_last_run['id'], 'cache_safety');
    if ($sspa_cache_recon) :
        $sspa_cache_rendered = SSPA_Insights::render($sspa_cache_recon);
        $sspa_cache_evidence = json_decode((string) $sspa_cache_recon['evidence'], true);
        $sspa_cache_evidence = is_array($sspa_cache_evidence) ? $sspa_cache_evidence : array();
        $sspa_shared_cache_labels = array(
            'visitor_specific_content_review_recommended' => __('Visitor-specific content review recommended', 'super-speedy-performance-analysis'),
            'no_visitor_specific_content_hazards_detected' => __('No visitor-specific content hazards detected', 'super-speedy-performance-analysis'),
        );
        $sspa_hazard_labels = array(
            'set_cookie_on_cache_candidate' => __('A shared-cache candidate sets cookies', 'super-speedy-performance-analysis'),
            'nonces_in_shared_html' => __('Nonce fields or keys occur in shared HTML', 'super-speedy-performance-analysis'),
            'hard_coded_cookie_reads' => __('Front-end code reads legacy cache-sensitive cookies', 'super-speedy-performance-analysis'),
            'browser_state_driven_output' => __('A private feature also reads browser storage', 'super-speedy-performance-analysis'),
            'private_features_need_route_review' => __('Private-feature surfaces need route and region review', 'super-speedy-performance-analysis'),
            'partial_browser_scan' => __('Browser transport could inspect only a response prefix', 'super-speedy-performance-analysis'),
            'stored_php_needs_manual_review' => __('Database-stored PHP snippets need manual review', 'super-speedy-performance-analysis'),
        );
        $sspa_shared_cache_status = isset($sspa_cache_evidence['shared_cache_status']) ? $sspa_cache_evidence['shared_cache_status'] : '';
        $sspa_cache_totals = isset($sspa_cache_evidence['totals']) ? (array) $sspa_cache_evidence['totals'] : array();
        ?>
        <div class="sspa-placeholder sspa-cache-recon">
            <h2><?php esc_html_e('Cache optimisation analysis', 'super-speedy-performance-analysis'); ?></h2>
            <p><strong><?php echo esc_html($sspa_cache_rendered['headline']); ?></strong></p>
            <p>
                <span class="sspa-badge"><?php echo esc_html(isset($sspa_shared_cache_labels[$sspa_shared_cache_status]) ? $sspa_shared_cache_labels[$sspa_shared_cache_status] : __('Not assessed', 'super-speedy-performance-analysis')); ?></span>
                <span class="sspa-badge"><?php /* translators: %s: review difficulty, e.g. "low" */ printf(esc_html__('Review difficulty: %s', 'super-speedy-performance-analysis'), esc_html(isset($sspa_cache_evidence['difficulty']) ? $sspa_cache_evidence['difficulty'] : __('unknown', 'super-speedy-performance-analysis'))); ?></span>
            </p>
            <p class="description"><?php echo esc_html($sspa_cache_rendered['detail']); ?></p>
            <p class="description">
                <?php printf(
                    /* translators: 1: Type A marker count, 2: Type B region count, 3: cart-fragment marker count, 4: files inspected, 5: components covered */
                    esc_html__('Existing coverage found: %1$d Type A marker(s), %2$d Type B region(s), %3$d cart-fragment marker(s). The bounded source scan inspected %4$d file(s) across %5$d component(s).', 'super-speedy-performance-analysis'),
                    (int) ($sspa_cache_totals['type_a_markers'] ?? 0),
                    (int) ($sspa_cache_totals['type_b_regions'] ?? 0),
                    (int) ($sspa_cache_totals['cart_fragment_markers'] ?? 0),
                    (int) ($sspa_cache_evidence['source_scan']['files_scanned'] ?? 0),
                    (int) ($sspa_cache_evidence['source_scan']['components_scanned'] ?? 0)
                ); ?>
            </p>
            <?php if (!empty($sspa_cache_evidence['source_scan']['truncated'])) : ?>
                <p class="description"><?php printf(
                    /* translators: %d: number of components whose source scan hit the safety limit */
                    esc_html__('Source inspection reached a safety limit for %d component(s); those components are listed in the download rather than silently treated as fully scanned.', 'super-speedy-performance-analysis'),
                    count((array) ($sspa_cache_evidence['source_scan']['components_truncated'] ?? array()))
                ); ?></p>
            <?php endif; ?>

            <?php if (!empty($sspa_cache_evidence['hazards'])) : ?>
                <h3><?php esc_html_e('Potential hazards', 'super-speedy-performance-analysis'); ?></h3>
                <ul class="sspa-health">
                    <?php foreach ((array) $sspa_cache_evidence['hazards'] as $sspa_hazard) : ?>
                        <li>
                            <?php
                            $sspa_hazard_type = isset($sspa_hazard['type']) ? $sspa_hazard['type'] : '';
                            echo esc_html(isset($sspa_hazard_labels[$sspa_hazard_type]) ? $sspa_hazard_labels[$sspa_hazard_type] : str_replace('_', ' ', $sspa_hazard_type));
                            ?>
                            <?php if (!empty($sspa_hazard['pages'])) : ?>
                                <?php /* translators: %d: number of page types */ printf(esc_html__('%d page type(s)', 'super-speedy-performance-analysis'), (int) $sspa_hazard['pages']); ?>
                            <?php endif; ?>
                            <?php if (!empty($sspa_hazard['names'])) : ?>
                                <br><code><?php echo esc_html(implode(', ', (array) $sspa_hazard['names'])); ?></code>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php else : ?>
                <p><strong><?php esc_html_e('No passive hazards were detected in the responses scanned.', 'super-speedy-performance-analysis'); ?></strong></p>
            <?php endif; ?>

            <?php if (!empty($sspa_cache_evidence['pages'])) : ?>
                <details>
                    <summary><?php /* translators: %d: number of page types scanned */ printf(esc_html__('%d page type(s) scanned', 'super-speedy-performance-analysis'), count((array) $sspa_cache_evidence['pages'])); ?></summary>
                    <table class="widefat striped">
                        <thead><tr>
                            <th><?php esc_html_e('Page', 'super-speedy-performance-analysis'); ?></th>
                            <th><?php esc_html_e('Passive signals', 'super-speedy-performance-analysis'); ?></th>
                            <th><?php esc_html_e('Existing coverage', 'super-speedy-performance-analysis'); ?></th>
                        </tr></thead>
                        <tbody>
                        <?php foreach ((array) $sspa_cache_evidence['pages'] as $sspa_cache_page) :
                            $sspa_page_signals = array_merge(
                                (array) ($sspa_cache_page['set_cookie_names'] ?? array()),
                                array_map(function ($name) { return 'infrastructure cookie: ' . $name; }, (array) ($sspa_cache_page['infrastructure_cookie_names'] ?? array())),
                                (array) ($sspa_cache_page['nonce_names'] ?? array()),
                                (array) ($sspa_cache_page['legacy_cookie_reads'] ?? array()),
                                (array) ($sspa_cache_page['private_surface_hints'] ?? array())
                            );
                            $sspa_page_coverage = isset($sspa_cache_page['coverage']) ? (array) $sspa_cache_page['coverage'] : array();
                            $sspa_page_coverage_count = (int) ($sspa_page_coverage['type_a_in'] ?? 0)
                                + (int) ($sspa_page_coverage['type_a_out'] ?? 0)
                                + count((array) ($sspa_page_coverage['type_b_regions'] ?? array()))
                                + (int) ($sspa_page_coverage['cart_fragment_markers'] ?? 0);
                            ?>
                            <tr>
                                <td>
                                    <code><?php echo esc_html($sspa_cache_page['page_key']); ?></code>
                                    <?php if (!empty($sspa_cache_page['url'])) : ?><br><a href="<?php echo esc_url($sspa_cache_page['url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open page', 'super-speedy-performance-analysis'); ?></a><?php endif; ?>
                                </td>
                                <td><?php echo $sspa_page_signals ? esc_html(implode(', ', array_unique($sspa_page_signals))) : esc_html__('None found', 'super-speedy-performance-analysis'); ?></td>
                                <td><?php echo esc_html((string) $sspa_page_coverage_count); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </details>
            <?php endif; ?>

            <?php if (!empty($sspa_cache_evidence['candidate_components'])) : ?>
                <details>
                    <summary><?php /* translators: %d: number of components worth inspecting first */ printf(esc_html__('%d component(s) worth inspecting first', 'super-speedy-performance-analysis'), count((array) $sspa_cache_evidence['candidate_components'])); ?></summary>
                    <ol class="sspa-insights">
                        <?php foreach (array_slice((array) $sspa_cache_evidence['candidate_components'], 0, 15) as $sspa_candidate) : ?>
                            <li>
                                <strong><?php echo esc_html($sspa_candidate['component']); ?></strong>
                                <span class="sspa-badge"><?php echo esc_html(isset($sspa_candidate['review_priority']) ? $sspa_candidate['review_priority'] : ($sspa_candidate['risk'] ?? __('unknown', 'super-speedy-performance-analysis'))); ?></span>
                                <span class="description"><?php echo esc_html(implode(', ', array_map(function ($signal) { return str_replace('_', ' ', $signal); }, (array) $sspa_candidate['signals']))); ?></span>
                                <?php if (!empty($sspa_candidate['observed_pages'])) : ?>
                                    <br><span class="description"><?php /* translators: %s: comma-separated page keys the component was observed on */ printf(esc_html__('Observed while rendering: %s', 'super-speedy-performance-analysis'), esc_html(implode(', ', (array) $sspa_candidate['observed_pages']))); ?></span>
                                <?php endif; ?>
                                <?php if (!empty($sspa_candidate['evidence'])) : ?>
                                    <ul>
                                        <?php foreach (array_slice((array) $sspa_candidate['evidence'], 0, 5) as $sspa_source) : ?>
                                            <li><code><?php echo esc_html($sspa_source['file'] . ':' . (int) $sspa_source['line']); ?></code></li>
                                        <?php endforeach; ?>
                                    </ul>
                                <?php endif; ?>
                            </li>
                        <?php endforeach; ?>
                    </ol>
                </details>
            <?php endif; ?>

            <p class="description">
                <?php esc_html_e('This scan uses anonymous responses and source indicators. It identifies where controlled guest, customer and basket testing should begin; it does not prove that shared caching is safe or that a candidate component owns a changed region.', 'super-speedy-performance-analysis'); ?>
            </p>
            <p>
                <button type="button" class="button sspa-cache-safety-download" data-run-id="<?php echo (int) $sspa_last_run['id']; ?>"><?php esc_html_e('Download cache optimisation analysis', 'super-speedy-performance-analysis'); ?></button>
                <span class="description sspa-cache-safety-download-status" aria-live="polite"></span>
            </p>
            <p class="description">
                <?php esc_html_e('Want me to implement this for you?', 'super-speedy-performance-analysis'); ?>
                <a href="<?php echo esc_url(SSPA_Markdown_Export::CACHE_SERVICE_URL); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('View the full-page caching implementation service.', 'super-speedy-performance-analysis'); ?></a>
            </p>
            <p class="description"><?php esc_html_e('No traffic collector was used. This immediate report assesses implementation difficulty and hazards, not daily traffic or expected caching benefit.', 'super-speedy-performance-analysis'); ?></p>
        </div>
    <?php endif; ?>

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
            // which is the same job done continuously rather than once - so it belongs here,
            // next to the finding, rather than as a banner somewhere. Only shown when the
            // plugin is not already doing it.
            if (!function_exists('wpi_getIndexes')) :
                ?>
                <p class="description">
                    <?php
                    printf(
                        /* translators: %s: link to the Scalability Pro product page. */
                        esc_html__('Prefer not to run this by hand every time the site changes? %s includes an options manager that loads what your site actually uses and unloads what it does not, keeping this list under control as plugins come and go.', 'super-speedy-performance-analysis'),
                        '<a href="' . esc_url('https://www.superspeedyplugins.com/product/scalability-pro/?utm_source=sspa&utm_content=autoload') . '" target="_blank" rel="noopener">' . esc_html__('Scalability Pro', 'super-speedy-performance-analysis') . '</a>'
                    );
                    ?>
                </p>
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
            /* translators: %s: the site sector, e.g. "retail" */
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
                esc_html_e('out of date - it will be refreshed the next time an analysis runs', 'super-speedy-performance-analysis');
            } else {
                esc_html_e('not installed - wp-content/mu-plugins is not writable', 'super-speedy-performance-analysis');
            }
            ?>
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
