<?php
defined('ABSPATH') || exit;

// The shared-cache safety scan from the latest analysis, shown on the Cache performance tab.
// Expects $sspa_cache_recon (the standalone cache_safety finding) and $sspa_cache_run_id.
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
                            array_map(function ($sspa_name) { return 'infrastructure cookie: ' . $sspa_name; }, (array) ($sspa_cache_page['infrastructure_cookie_names'] ?? array())),
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
                            <span class="description"><?php echo esc_html(implode(', ', array_map(function ($sspa_signal) { return str_replace('_', ' ', $sspa_signal); }, (array) $sspa_candidate['signals']))); ?></span>
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
            <button type="button" class="button sspa-cache-safety-download" data-run-id="<?php echo (int) $sspa_cache_run_id; ?>"><?php esc_html_e('Download cache optimisation analysis', 'super-speedy-performance-analysis'); ?></button>
            <span class="description sspa-cache-safety-download-status" aria-live="polite"></span>
        </p>
        <p class="description"><?php esc_html_e('No traffic collector was used. This immediate report assesses implementation difficulty and hazards, not daily traffic or expected caching benefit.', 'super-speedy-performance-analysis'); ?></p>
    </div>
