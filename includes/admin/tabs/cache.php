<?php
defined('ABSPATH') || exit;

// Cache performance: the site's cache system, measured cache behaviour for three audiences,
// what the published service and plugin pages say about caching basket users and
// customers, and the offers. Every number on this tab is either PA's own measurement,
// labelled with its boundary, or a verbatim published quotation beside its source link.

$sspa_cache_run_id = SSPA_Report::latest_done_run_id();
$sspa_cache_run = $sspa_cache_run_id ? SSPA_Run_Controller::run_row($sspa_cache_run_id) : null;
$sspa_cache_report = SSPA_Cache_Delivery::latest();
$sspa_cache_targets = SSPA_Cache_Delivery::targets();
$sspa_cache_plan = SSPA_Cache_Delivery::request_plan(count($sspa_cache_targets));
$sspa_cache_system = SSPA_Cache_System::inventory();
$sspa_cache_offers = SSPA_Offers::cache_tab($sspa_cache_run_id, $sspa_cache_report);
$sspa_cache_education = SSPA_Rules::cache_education();

$sspa_cache_audience_labels = array(
    'anonymous' => __('Visitors', 'super-speedy-performance-analysis'),
    'basket' => __('Visitors with a basket', 'super-speedy-performance-analysis'),
    'customer' => __('Logged-in customers', 'super-speedy-performance-analysis'),
);
$sspa_cache_audience_notes = array(
    'anonymous' => __('No cookies.', 'super-speedy-performance-analysis'),
    'basket' => __('A request carrying WooCommerce\'s basket cookies. No basket or session is created.', 'super-speedy-performance-analysis'),
    'customer' => __('Logged-in customer (test account, no orders).', 'super-speedy-performance-analysis'),
);
$sspa_cache_headlines = array(
    'cached' => __('Cached', 'super-speedy-performance-analysis'),
    'partly_cached' => __('Partly cached', 'super-speedy-performance-analysis'),
    'not_cached' => __('Not cached', 'super-speedy-performance-analysis'),
    'unknown' => __('Unknown', 'super-speedy-performance-analysis'),
);
$sspa_cache_reasons = array(
    'woocommerce_not_active' => __('WooCommerce is not active, so there is no basket to represent.', 'super-speedy-performance-analysis'),
    'test_customer_unavailable' => __('The synthetic test customer account could not be created.', 'super-speedy-performance-analysis'),
    'application_session_cookie_set' => __('The first response set a cookie, so the page was not requested again.', 'super-speedy-performance-analysis'),
    'cached_bodies_differ' => __('A cache reported a hit, but the two responses differed.', 'super-speedy-performance-analysis'),
    'anonymous_cache_layer_not_used_for_this_audience' => __('The cache that served visitors did not serve this audience.', 'super-speedy-performance-analysis'),
    'http_status_not_200' => __('The page did not answer with HTTP 200.', 'super-speedy-performance-analysis'),
);
$sspa_cache_ms = static function ($value) {
    return null === $value ? '' : number_format_i18n((float) $value, 0) . ' ms';
};
$sspa_cache_education_headings = array(
    'what_usually_happens' => __('What usually happens', 'super-speedy-performance-analysis'),
    'what_is_possible' => __('What is possible', 'super-speedy-performance-analysis'),
    'how_it_works' => __('How it works', 'super-speedy-performance-analysis'),
    'what_stays_private' => __('What stays private', 'super-speedy-performance-analysis'),
    'why_not_a_setting' => __('Why it is not a setting', 'super-speedy-performance-analysis'),
    'where_ajax_prices_fits' => __('Where Ajax Prices fits', 'super-speedy-performance-analysis'),
    'object_cache_is_different' => __('Object cache is a different thing', 'super-speedy-performance-analysis'),
    'reading_the_numbers' => __('How to read the numbers', 'super-speedy-performance-analysis'),
);
$sspa_cache_quote = static function ($quote) {
    $host = (string) wp_parse_url($quote['source_url'], PHP_URL_HOST);
    $checked = mysql2date(get_option('date_format'), $quote['source_checked'] . ' 00:00:00', false);
    echo '<blockquote class="sspa-cache-quote"><p>&ldquo;' . esc_html($quote['text']) . '&rdquo;</p><cite>';
    echo '<a href="' . esc_url($quote['source_url']) . '" target="_blank" rel="noopener noreferrer">' . esc_html($host . wp_parse_url($quote['source_url'], PHP_URL_PATH)) . '</a> ';
    /* translators: %s: date the published page was last checked */
    echo esc_html(sprintf(__('(checked %s)', 'super-speedy-performance-analysis'), $checked));
    echo '</cite></blockquote>';
};
?>
<div class="sspa-cache-tab">
    <div class="sspa-placeholder sspa-cache-summary">
        <h2><?php esc_html_e('Cache performance', 'super-speedy-performance-analysis'); ?></h2>
        <p class="description"><?php esc_html_e('Most page caches serve anonymous visitors and step aside for visitors with a basket and logged-in customers. This tab measures what your cache does for each of them.', 'super-speedy-performance-analysis'); ?></p>

        <?php if ($sspa_cache_report) : ?>
            <ul class="sspa-cache-audiences">
                <?php foreach (SSPA_Cache_Delivery::AUDIENCES as $sspa_cache_audience) :
                    $sspa_cache_row = $sspa_cache_report['audiences'][$sspa_cache_audience];
                    ?>
                    <li class="sspa-cache-audience sspa-cache-state-<?php echo esc_attr($sspa_cache_row['headline']); ?>" data-audience="<?php echo esc_attr($sspa_cache_audience); ?>">
                        <span class="sspa-cache-audience-name"><?php echo esc_html($sspa_cache_audience_labels[$sspa_cache_audience]); ?></span>
                        <strong class="sspa-cache-audience-word"><?php echo esc_html($sspa_cache_headlines[$sspa_cache_row['headline']]); ?></strong>
                        <span class="sspa-cache-audience-detail">
                            <?php
                            if (null !== $sspa_cache_row['median_server_ttfb_ms']) {
                                /* translators: %s: time in milliseconds, e.g. "42 ms" */
                                echo esc_html(sprintf(__('median %s to first byte (server-side request)', 'super-speedy-performance-analysis'), $sspa_cache_ms($sspa_cache_row['median_server_ttfb_ms'])));
                            }
                            if ($sspa_cache_row['unknown_pages'] && 'unknown' !== $sspa_cache_row['headline']) {
                                echo ' &middot; ';
                                /* translators: %d: number of pages */
                                echo esc_html(sprintf(_n('%d page could not be determined', '%d pages could not be determined', $sspa_cache_row['unknown_pages'], 'super-speedy-performance-analysis'), $sspa_cache_row['unknown_pages']));
                            }
                            ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
            <p class="description">
                <?php
                $sspa_cache_checked = mysql2date(get_option('date_format') . ' ' . get_option('time_format'), get_date_from_gmt(gmdate('Y-m-d H:i:s', strtotime($sspa_cache_report['generated_at']))), true);
                if ($sspa_cache_report['run_id']) {
                    /* translators: 1: date and time, 2: analysis number */
                    echo esc_html(sprintf(__('Checked %1$s, alongside Analysis #%2$d.', 'super-speedy-performance-analysis'), $sspa_cache_checked, (int) $sspa_cache_report['run_id']));
                } else {
                    /* translators: %s: date and time */
                    echo esc_html(sprintf(__('Checked %s, before any analysis was completed.', 'super-speedy-performance-analysis'), $sspa_cache_checked));
                }
                ?>
            </p>
        <?php else : ?>
            <p><strong><?php esc_html_e('Not checked yet.', 'super-speedy-performance-analysis'); ?></strong> <?php esc_html_e('Run the check below to see whether visitors, visitors with a basket and logged-in customers get cached pages.', 'super-speedy-performance-analysis'); ?></p>
        <?php endif; ?>
    </div>

    <?php if ($sspa_cache_report && in_array($sspa_cache_report['leak_check']['status'], array('logged_in_content_found', 'anonymous_page_changed'), true)) : ?>
        <div class="notice notice-error inline sspa-cache-leak" role="alert">
            <p><strong><?php esc_html_e('Logged-in content may reach anonymous visitors.', 'super-speedy-performance-analysis'); ?></strong></p>
            <p>
                <?php
                if ('logged_in_content_found' === $sspa_cache_report['leak_check']['status']) {
                    esc_html_e('After the check requested these pages as the logged-in test customer, an anonymous request received a page marked as logged in or showing the test account\'s name. Real customers on this site can cause the same effect.', 'super-speedy-performance-analysis');
                } else {
                    esc_html_e('An anonymous page that was identical on two requests changed after the check requested it as the logged-in test customer. The cache may have stored a logged-in response for anonymous visitors.', 'super-speedy-performance-analysis');
                }
                ?>
            </p>
            <ul>
                <?php foreach ($sspa_cache_report['leak_check']['pages'] as $sspa_cache_leak_page) :
                    if (!in_array($sspa_cache_leak_page['result'], array('logged_in_content_found', 'anonymous_page_changed'), true)) {
                        continue;
                    } ?>
                    <li><?php echo esc_html(SSPA_Cache_Delivery::page_label($sspa_cache_leak_page['page_key'])); ?>: <code><?php echo esc_html($sspa_cache_leak_page['url']); ?></code></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="sspa-placeholder sspa-cache-delivery">
        <h2><?php esc_html_e('Check cache performance', 'super-speedy-performance-analysis'); ?></h2>
        <?php if ($sspa_cache_targets) : ?>
            <p class="description"><?php esc_html_e('The check requests these fixed public pages of this site. It accepts no other address.', 'super-speedy-performance-analysis'); ?></p>
            <ul class="sspa-cache-delivery-targets">
                <?php foreach ($sspa_cache_targets as $sspa_cache_target) : ?>
                    <li><strong><?php echo esc_html($sspa_cache_target['label']); ?></strong> <code><?php echo esc_html($sspa_cache_target['url']); ?></code></li>
                <?php endforeach; ?>
            </ul>
            <p class="description sspa-cache-request-plan">
                <?php
                printf(
                    /* translators: 1: total request count, 2: browser request count, 3: server request count */
                    esc_html__('It sends %1$d sequential GET requests to this site and nowhere else: %2$d from your browser without cookies, and %3$d from the server, two each as an anonymous visitor, as a visitor carrying WooCommerce\'s basket cookies and as the logged-in test customer, then one final anonymous request per page that checks no logged-in content reached the anonymous cache. It does not purge or change any cache and creates no basket; WooCommerce may save a session for the test account, as it does for any logged-in customer.', 'super-speedy-performance-analysis'),
                    (int) $sspa_cache_plan['request_count'],
                    (int) $sspa_cache_plan['browser_requests'],
                    (int) $sspa_cache_plan['server_requests']
                );
                ?>
            </p>
            <p>
                <button type="button" class="button button-primary sspa-cache-delivery-start"><?php esc_html_e('Check cache performance', 'super-speedy-performance-analysis'); ?></button>
                <span class="spinner sspa-cache-delivery-spinner"></span>
                <span class="description sspa-cache-delivery-status" aria-live="polite"></span>
            </p>
            <div class="sspa-progress-bar sspa-cache-delivery-progress" hidden><div class="sspa-progress-fill" style="width:0"></div></div>
        <?php else : ?>
            <p class="notice notice-warning inline"><?php esc_html_e('This check needs a public home page.', 'super-speedy-performance-analysis'); ?></p>
        <?php endif; ?>

        <?php if ($sspa_cache_report) :
            $sspa_cache_origin = array();
            foreach ((array) $sspa_cache_report['origin_profiles'] as $sspa_cache_origin_row) {
                $sspa_cache_origin[$sspa_cache_origin_row['page_key']] = $sspa_cache_origin_row;
            }
            $sspa_cache_browser = array();
            foreach ((array) $sspa_cache_report['opportunity'] as $sspa_cache_opportunity) {
                $sspa_cache_browser[$sspa_cache_opportunity['page_key']] = $sspa_cache_opportunity;
            }
            ?>
            <h3><?php esc_html_e('Results by page', 'super-speedy-performance-analysis'); ?></h3>
            <table class="widefat striped sspa-cache-results sspa-stack-table">
                <thead><tr>
                    <th scope="col"><?php esc_html_e('Page', 'super-speedy-performance-analysis'); ?></th>
                    <?php foreach (SSPA_Cache_Delivery::AUDIENCES as $sspa_cache_audience) : ?>
                        <th scope="col"><?php echo esc_html($sspa_cache_audience_labels[$sspa_cache_audience]); ?></th>
                    <?php endforeach; ?>
                    <th scope="col"><?php esc_html_e('Page generation without cache', 'super-speedy-performance-analysis'); ?></th>
                </tr></thead>
                <tbody>
                <?php foreach ($sspa_cache_report['audience_verdicts']['anonymous'] as $sspa_cache_index => $sspa_cache_page) :
                    $sspa_cache_key = $sspa_cache_page['page_key'];
                    ?>
                    <tr data-page="<?php echo esc_attr($sspa_cache_key); ?>">
                        <th scope="row" data-label="<?php esc_attr_e('Page', 'super-speedy-performance-analysis'); ?>">
                            <?php echo esc_html(SSPA_Cache_Delivery::page_label($sspa_cache_key)); ?>
                            <br><a href="<?php echo esc_url($sspa_cache_page['url']); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Open page', 'super-speedy-performance-analysis'); ?></a>
                        </th>
                        <?php foreach (SSPA_Cache_Delivery::AUDIENCES as $sspa_cache_audience) :
                            $sspa_cache_cell = $sspa_cache_report['audience_verdicts'][$sspa_cache_audience][$sspa_cache_index];
                            ?>
                            <td data-label="<?php echo esc_attr($sspa_cache_audience_labels[$sspa_cache_audience]); ?>" data-state="<?php echo esc_attr($sspa_cache_cell['cache_state']); ?>">
                                <strong class="sspa-cache-word sspa-cache-state-<?php echo esc_attr($sspa_cache_cell['cache_state']); ?>"><?php echo esc_html($sspa_cache_headlines[$sspa_cache_cell['cache_state']]); ?></strong>
                                <?php if ('cached' === $sspa_cache_cell['cache_state'] && $sspa_cache_cell['layer']) : ?>
                                    <span class="description">
                                        <?php
                                        /* translators: %s: cache name, e.g. "Cloudflare" */
                                        echo esc_html(sprintf(__('by %s', 'super-speedy-performance-analysis'), SSPA_Offers::layer_label($sspa_cache_cell['layer'])));
                                        ?>
                                    </span>
                                <?php endif; ?>
                                <br>
                                <?php if (null !== $sspa_cache_cell['server_ttfb_ms']) : ?>
                                    <?php
                                    /* translators: %s: milliseconds */
                                    echo esc_html(sprintf(__('First byte %s', 'super-speedy-performance-analysis'), $sspa_cache_ms($sspa_cache_cell['server_ttfb_ms'])));
                                    ?><br>
                                <?php endif; ?>
                                <?php if (null !== $sspa_cache_cell['server_total_ms']) : ?>
                                    <span class="description">
                                        <?php
                                        /* translators: %s: milliseconds */
                                        echo esc_html(sprintf(__('Complete %s', 'super-speedy-performance-analysis'), $sspa_cache_ms($sspa_cache_cell['server_total_ms'])));
                                        ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ('anonymous' === $sspa_cache_audience && isset($sspa_cache_browser[$sspa_cache_key]) && null !== $sspa_cache_browser[$sspa_cache_key]['anonymous_browser_ttfb_ms']) : ?>
                                    <br><span class="description">
                                        <?php
                                        /* translators: %s: milliseconds */
                                        echo esc_html(sprintf(__('Your browser: first byte %s', 'super-speedy-performance-analysis'), $sspa_cache_ms($sspa_cache_browser[$sspa_cache_key]['anonymous_browser_ttfb_ms'])));
                                        ?>
                                    </span>
                                <?php endif; ?>
                                <?php if ('unknown' === $sspa_cache_cell['cache_state'] && isset($sspa_cache_reasons[$sspa_cache_cell['reason']])) : ?>
                                    <br><span class="description"><?php echo esc_html($sspa_cache_reasons[$sspa_cache_cell['reason']]); ?></span>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <td data-label="<?php esc_attr_e('Page generation without cache', 'super-speedy-performance-analysis'); ?>">
                            <?php
                            $sspa_cache_generation = isset($sspa_cache_origin[$sspa_cache_key]) ? $sspa_cache_origin[$sspa_cache_key] : null;
                            if (!$sspa_cache_report['run_id']) {
                                esc_html_e('No analysis yet', 'super-speedy-performance-analysis');
                            } elseif ($sspa_cache_generation && null !== $sspa_cache_generation['generation_ms']) {
                                /* translators: %s: milliseconds */
                                echo esc_html(sprintf(__('Visitor %s', 'super-speedy-performance-analysis'), $sspa_cache_ms($sspa_cache_generation['generation_ms'])));
                                if (null !== $sspa_cache_generation['customer_generation_ms']) {
                                    echo '<br>';
                                    /* translators: %s: milliseconds */
                                    echo esc_html(sprintf(__('Logged-in customer %s', 'super-speedy-performance-analysis'), $sspa_cache_ms($sspa_cache_generation['customer_generation_ms'])));
                                }
                            } else {
                                esc_html_e('Not measured', 'super-speedy-performance-analysis');
                            }
                            ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <ul class="sspa-cache-notes description">
                <?php foreach (SSPA_Cache_Delivery::AUDIENCES as $sspa_cache_audience) : ?>
                    <li><strong><?php echo esc_html($sspa_cache_audience_labels[$sspa_cache_audience]); ?>:</strong> <?php echo esc_html($sspa_cache_audience_notes[$sspa_cache_audience]); ?></li>
                <?php endforeach; ?>
                <li><?php esc_html_e('"First byte" and "Complete" are measured by a request from this server to the page, the same boundary for all three audiences. "Your browser" is your own browser\'s anonymous request. "Page generation without cache" comes from the latest analysis; visitors with a basket are generated like visitors. These are separate measurements and are never subtracted from each other.', 'super-speedy-performance-analysis'); ?></li>
            </ul>
        <?php endif; ?>
    </div>

    <div class="sspa-placeholder sspa-cache-system">
        <h2><?php esc_html_e('Your cache system', 'super-speedy-performance-analysis'); ?></h2>
        <table class="widefat striped sspa-stack-table">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e('Page cache plugins', 'super-speedy-performance-analysis'); ?></th>
                    <td>
                        <?php if ($sspa_cache_system['page_cache_plugins']) : ?>
                            <?php echo esc_html(implode(', ', array_map(function ($sspa_plugin) {
                                return 'integration' === $sspa_plugin['kind']
                                    /* translators: %s: plugin name */
                                    ? sprintf(__('%s (cache integration)', 'super-speedy-performance-analysis'), $sspa_plugin['title'])
                                    : $sspa_plugin['title'];
                            }, $sspa_cache_system['page_cache_plugins']))); ?>
                        <?php else : ?>
                            <?php esc_html_e('None active', 'super-speedy-performance-analysis'); ?>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('advanced-cache.php drop-in', 'super-speedy-performance-analysis'); ?></th>
                    <td>
                        <?php
                        if ($sspa_cache_system['advanced_cache_dropin']) {
                            $sspa_dropin = $sspa_cache_system['advanced_cache_dropin'];
                            // WordPress reports the file name when the drop-in carries no plugin header.
                            echo esc_html('' !== $sspa_dropin['title'] && 'advanced-cache.php' !== $sspa_dropin['title'] ? trim($sspa_dropin['title'] . ' ' . $sspa_dropin['version']) : __('Present, without a plugin header naming its owner', 'super-speedy-performance-analysis'));
                        } else {
                            esc_html_e('None', 'super-speedy-performance-analysis');
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Cache seen in responses', 'super-speedy-performance-analysis'); ?></th>
                    <td>
                        <?php
                        $sspa_cache_layers = array();
                        if ($sspa_cache_report) {
                            foreach ((array) $sspa_cache_report['cache_layers'] as $sspa_cache_layer) {
                                if ('served_response' === $sspa_cache_layer['confidence'] && 'browser' !== $sspa_cache_layer['position']) {
                                    $sspa_cache_layers[$sspa_cache_layer['layer']] = SSPA_Offers::layer_label($sspa_cache_layer['layer']);
                                }
                            }
                        }
                        if (!$sspa_cache_report) {
                            esc_html_e('Run the check to see which cache answered.', 'super-speedy-performance-analysis');
                        } elseif ($sspa_cache_layers) {
                            echo esc_html(implode(', ', $sspa_cache_layers));
                        } else {
                            esc_html_e('No cache identified itself in the checked responses.', 'super-speedy-performance-analysis');
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Web server', 'super-speedy-performance-analysis'); ?></th>
                    <td><?php echo esc_html('unknown' === $sspa_cache_system['server_software'] ? __('Not reported', 'super-speedy-performance-analysis') : ('other' === $sspa_cache_system['server_software'] ? __('Other', 'super-speedy-performance-analysis') : $sspa_cache_system['server_software'])); ?></td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Persistent object cache', 'super-speedy-performance-analysis'); ?></th>
                    <td>
                        <?php
                        $sspa_object_cache = $sspa_cache_system['object_cache'];
                        if (!$sspa_object_cache['persistent']) {
                            esc_html_e('Not active', 'super-speedy-performance-analysis');
                        } else {
                            $sspa_object_parts = array(__('Active', 'super-speedy-performance-analysis'));
                            if ($sspa_object_cache['technology'] && 'other' !== $sspa_object_cache['technology']) {
                                $sspa_object_parts[] = $sspa_object_cache['technology'];
                            }
                            if ('platform-managed' === $sspa_object_cache['management']) {
                                $sspa_object_parts[] = __('managed by the host', 'super-speedy-performance-analysis');
                            }
                            echo esc_html(implode(', ', $sspa_object_parts));
                        }
                        ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('PHP OPcache', 'super-speedy-performance-analysis'); ?></th>
                    <td>
                        <?php
                        $sspa_opcache = $sspa_cache_system['opcache'];
                        if (!$sspa_opcache['readable']) {
                            $sspa_opcache_reasons = array(
                                'extension_not_loaded' => __('Not installed', 'super-speedy-performance-analysis'),
                                'disabled' => __('Disabled', 'super-speedy-performance-analysis'),
                                'restricted_by_opcache_restrict_api' => __('Not readable: the host restricts the OPcache status to another path', 'super-speedy-performance-analysis'),
                                'status_unavailable' => __('Not readable', 'super-speedy-performance-analysis'),
                            );
                            echo esc_html(isset($sspa_opcache_reasons[$sspa_opcache['reason']]) ? $sspa_opcache_reasons[$sspa_opcache['reason']] : __('Not readable', 'super-speedy-performance-analysis'));
                        } elseif (!$sspa_opcache['enabled']) {
                            esc_html_e('Disabled', 'super-speedy-performance-analysis');
                        } else {
                            printf(
                                /* translators: 1: memory used, 2: memory free, 3: hit rate percentage, 4: cached script count, 5: restart count */
                                esc_html__('Enabled: %1$s used, %2$s free, %3$s%% hit rate, %4$s cached scripts, %5$s restarts', 'super-speedy-performance-analysis'),
                                esc_html(size_format((int) $sspa_opcache['memory_used_bytes'])),
                                esc_html(size_format((int) $sspa_opcache['memory_free_bytes'])),
                                esc_html(number_format_i18n((float) $sspa_opcache['hit_rate'], 1)),
                                esc_html(number_format_i18n((int) $sspa_opcache['cached_scripts'])),
                                esc_html(number_format_i18n((int) $sspa_opcache['restarts']))
                            );
                        }
                        ?>
                    </td>
                </tr>
                <?php if ($sspa_cache_system['woocommerce']) : ?>
                    <tr>
                        <th scope="row"><?php esc_html_e('Super Speedy Ajax Prices', 'super-speedy-performance-analysis'); ?></th>
                        <td><?php echo $sspa_cache_system['ajax_prices_active'] ? esc_html__('Active', 'super-speedy-performance-analysis') : esc_html__('Not active', 'super-speedy-performance-analysis'); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
        $sspa_run_notes = $sspa_cache_run ? json_decode((string) $sspa_cache_run['notes'], true) : array();
        $sspa_unmeasured = is_array($sspa_run_notes) && !empty($sspa_run_notes['unmeasured_pages']) ? (int) $sspa_run_notes['unmeasured_pages'] : 0;
        if ($sspa_unmeasured) :
            ?>
            <p class="description">
                <?php
                /* translators: %d: number of pages */
                echo esc_html(sprintf(_n('In the latest analysis, %d page was answered by a cache before WordPress ran, even with a unique query string. Your cache serves those addresses regardless of query strings.', 'In the latest analysis, %d pages were answered by a cache before WordPress ran, even with a unique query string. Your cache serves those addresses regardless of query strings.', $sspa_unmeasured, 'super-speedy-performance-analysis'), $sspa_unmeasured));
                ?>
            </p>
        <?php endif; ?>
        <?php if (!empty($sspa_cache_offers['cache_service']['supported_systems'])) : ?>
            <p class="description"><?php esc_html_e('Systems the caching service supports:', 'super-speedy-performance-analysis'); ?></p>
            <?php $sspa_cache_quote($sspa_cache_offers['cache_service']['supported_systems']); ?>
        <?php endif; ?>
    </div>

    <div class="sspa-placeholder sspa-cache-education">
        <h2><?php esc_html_e('You can cache visitors with a basket and logged-in customers', 'super-speedy-performance-analysis'); ?></h2>
        <p class="description"><?php esc_html_e('In the words of the published Super Speedy Plugins pages:', 'super-speedy-performance-analysis'); ?></p>
        <?php
        $sspa_cache_last_topic = '';
        foreach ($sspa_cache_education as $sspa_cache_item) {
            if (!isset($sspa_cache_education_headings[$sspa_cache_item['topic']])) {
                continue;
            }
            if ($sspa_cache_item['topic'] !== $sspa_cache_last_topic) {
                echo '<h3>' . esc_html($sspa_cache_education_headings[$sspa_cache_item['topic']]) . '</h3>';
                $sspa_cache_last_topic = $sspa_cache_item['topic'];
            }
            $sspa_cache_quote($sspa_cache_item);
        }
        ?>
    </div>

    <?php
    $sspa_cache_offer_order = 'ajax_prices' === $sspa_cache_offers['primary'] ? array('ajax_prices', 'cache_service') : array('cache_service', 'ajax_prices');
    foreach ($sspa_cache_offer_order as $sspa_cache_offer_id) :
        $sspa_cache_offer = $sspa_cache_offers[$sspa_cache_offer_id];
        if (!$sspa_cache_offer) {
            continue;
        }
        $sspa_cache_is_primary = $sspa_cache_offer_id === $sspa_cache_offers['primary'];
        ?>
        <div class="sspa-placeholder sspa-cache-offer sspa-cache-offer-<?php echo esc_attr($sspa_cache_offer_id); ?><?php echo $sspa_cache_is_primary ? ' sspa-cache-offer-primary' : ''; ?>" data-offer="<?php echo esc_attr($sspa_cache_offer_id); ?>">
            <?php if ('cache_service' === $sspa_cache_offer_id) : ?>
                <h2><?php esc_html_e('Have it implemented for you', 'super-speedy-performance-analysis'); ?></h2>
                <?php if ($sspa_cache_offer['evidence']) : ?>
                    <div class="sspa-cache-evidence">
                        <?php foreach ($sspa_cache_offer['evidence'] as $sspa_cache_sentence) : ?>
                            <p><strong><?php echo esc_html($sspa_cache_sentence); ?></strong></p>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <?php foreach ($sspa_cache_offer['quotes'] as $sspa_cache_item) {
                    $sspa_cache_quote($sspa_cache_item);
                } ?>
                <?php foreach ($sspa_cache_offer['figures'] as $sspa_cache_item) {
                    $sspa_cache_quote($sspa_cache_item);
                } ?>
                <p class="description"><?php esc_html_e('Want me to implement this for you?', 'super-speedy-performance-analysis'); ?></p>
            <?php else : ?>
                <h2><?php esc_html_e('Cache pages whose prices vary per visitor', 'super-speedy-performance-analysis'); ?></h2>
                <p><strong><?php echo esc_html($sspa_cache_offer['sentence']); ?></strong></p>
                <?php foreach ($sspa_cache_offer['quotes'] as $sspa_cache_item) {
                    $sspa_cache_quote($sspa_cache_item);
                } ?>
            <?php endif; ?>
            <p>
                <a class="button<?php echo $sspa_cache_is_primary ? ' button-primary' : ''; ?> sspa-cache-offer-link" href="<?php echo esc_url($sspa_cache_offer['url']); ?>" target="_blank" rel="noopener noreferrer">
                    <?php
                    if ('cache_service' === $sspa_cache_offer_id) {
                        /* translators: %s: service name */
                        echo esc_html(sprintf(__('View the %s service', 'super-speedy-performance-analysis'), $sspa_cache_offer['name']));
                    } else {
                        /* translators: %s: plugin name */
                        echo esc_html(sprintf(__('View %s', 'super-speedy-performance-analysis'), $sspa_cache_offer['name']));
                    }
                    ?>
                </a>
            </p>
        </div>
    <?php endforeach; ?>

    <?php
    $sspa_cache_recon = $sspa_cache_run_id ? SSPA_Insights::standalone($sspa_cache_run_id, 'cache_safety') : null;
    if ($sspa_cache_recon) {
        include SSPA_PLUGIN_DIR . 'includes/admin/partials/cache-safety-scan.php';
    }
    ?>
</div>
