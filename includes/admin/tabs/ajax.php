<?php
defined('ABSPATH') || exit;
$sspa_windows = SSPA_Ajax_Profile::windows();
$sspa_active = SSPA_Traffic_Collection::active();
$sspa_report = SSPA_Report::endpoint_evidence();
?>
<section id="sspa-ajax-profile">
<h2><?php esc_html_e('Profile AJAX', 'super-speedy-performance-analysis'); ?></h2>
<p><?php esc_html_e('Record a before window, exercise the real workflow, then stop. Change the selected endpoint in Scalability Pro and repeat the same scenario in an after window. Only endpoints explicitly enabled in Scalability Pro change which plugins load.', 'super-speedy-performance-analysis'); ?></p>
<p><?php esc_html_e('Measurements stay on this site. No requests are replayed. Timings are server request time, not browser elapsed time. Avoid personal data in labels.', 'super-speedy-performance-analysis'); ?></p>
<form class="sspa-ajax-start">
<label><?php esc_html_e('Window name', 'super-speedy-performance-analysis'); ?> <input name="label" required maxlength="80" placeholder="<?php esc_attr_e('Before cart refresh', 'super-speedy-performance-analysis'); ?>"></label>
<label><?php esc_html_e('Scenario', 'super-speedy-performance-analysis'); ?> <input name="scenario" required maxlength="80" placeholder="<?php esc_attr_e('One item, refresh cart', 'super-speedy-performance-analysis'); ?>"></label>
<label><?php esc_html_e('Endpoint group', 'super-speedy-performance-analysis'); ?> <select class="sspa-ajax-endpoint-group"><option value=""><?php esc_html_e('All groups', 'super-speedy-performance-analysis'); ?></option>
<?php if (class_exists('SPRO_Fast_Ajax_Advice')) : foreach (SPRO_Fast_Ajax_Advice::groups() as $sspa_group => $sspa_label) : ?>
<option value="<?php echo esc_attr($sspa_group); ?>"><?php echo esc_html($sspa_label); ?></option>
<?php endforeach; endif; ?></select></label>
<label><?php esc_html_e('Endpoints', 'super-speedy-performance-analysis'); ?> <select name="endpoints[]" multiple size="5" aria-label="<?php esc_attr_e('Endpoints to profile', 'super-speedy-performance-analysis'); ?>">
<?php if (!is_wp_error($sspa_report)) : foreach ($sspa_report['endpoints'] as $sspa_endpoint) :
$sspa_id = $sspa_endpoint['identity']; $sspa_selector = $sspa_id['transport'] . ':' . ($sspa_id['action'] ?: $sspa_id['route_pattern']);
$sspa_classification = class_exists('SPRO_Fast_Ajax_Advice') ? SPRO_Fast_Ajax_Advice::endpoint(array('transport' => $sspa_id['transport'], 'endpoint' => $sspa_id['action'] ?: $sspa_id['route_pattern'], 'method' => $sspa_id['method'], 'context' => $sspa_id['auth_context'])) : array('group' => 'unknown'); ?>
<option data-group="<?php echo esc_attr($sspa_classification['group']); ?>" value="<?php echo esc_attr($sspa_selector); ?>"><?php echo esc_html($sspa_selector); ?></option>
<?php endforeach; endif; ?>
<?php if (is_wp_error($sspa_report) || empty($sspa_report['endpoints'])) : ?><option disabled><?php esc_html_e('No endpoints observed yet; start a discovery window.', 'super-speedy-performance-analysis'); ?></option><?php endif; ?>
</select></label>
<p class="description"><?php esc_html_e('Leave endpoints unselected to discover all registered AJAX/REST requests in this window.', 'super-speedy-performance-analysis'); ?></p>
<label><input type="checkbox" name="detail" value="1"> <?php esc_html_e('Sample plugin activity (diagnostic: at most 20 requests, 5 per action; adds overhead)', 'super-speedy-performance-analysis'); ?></label>
<p class="description"><?php esc_html_e('Named action execution, hook registration checkpoints and I/O attempts. Partial coverage cannot prove a plugin does no work. Direct REST callbacks and filters are not timed.', 'super-speedy-performance-analysis'); ?></p>
<p><button class="button button-primary" <?php disabled((bool) $sspa_active); ?>><?php esc_html_e('Start window (15 minutes / 200 observations)', 'super-speedy-performance-analysis'); ?></button></p>
</form>
<?php if ($sspa_active) : ?>
<p><?php /* translators: %d: collection ID. */ printf(esc_html__('Collection #%d is active. Exercise the workflow in another tab.', 'super-speedy-performance-analysis'), (int) $sspa_active['id']); ?></p>
<?php foreach ($sspa_windows as $sspa_window) : if ($sspa_window['collection_id'] === (int) $sspa_active['id']) : ?>
<button type="button" class="button sspa-ajax-stop" data-uuid="<?php echo esc_attr($sspa_window['uuid']); ?>"><?php esc_html_e('Stop window', 'super-speedy-performance-analysis'); ?></button>
<?php endif; endforeach; endif; ?>
<h3 id="sspa-ajax-endpoints-heading"><?php esc_html_e('Observed endpoints', 'super-speedy-performance-analysis'); ?></h3>
<?php if ($sspa_windows) : ?>
<label><?php esc_html_e('Window', 'super-speedy-performance-analysis'); ?> <select class="sspa-ajax-endpoints-window">
<?php foreach (array_reverse($sspa_windows) as $sspa_window) : ?>
<option value="<?php echo esc_attr($sspa_window['uuid']); ?>"><?php echo esc_html($sspa_window['label'] . ' · ' . $sspa_window['created']); ?></option>
<?php endforeach; ?></select></label>
<?php endif; ?>
<div class="sspa-ajax-endpoints-mount"><?php echo SSPA_Endpoint_Profile::table_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- every value is escaped inside table_html(). ?></div>
<h3><?php esc_html_e('Compare saved windows', 'super-speedy-performance-analysis'); ?></h3>
<label><?php esc_html_e('Saved comparison', 'super-speedy-performance-analysis'); ?> <select class="sspa-ajax-saved"><option value=""><?php esc_html_e('Choose a saved comparison', 'super-speedy-performance-analysis'); ?></option>
<?php foreach (SSPA_Ajax_Profile::comparisons() as $sspa_comparison) : ?>
<option value="<?php echo esc_attr(wp_json_encode($sspa_comparison)); ?>"><?php echo esc_html($sspa_comparison['name']); ?></option>
<?php endforeach; ?></select></label>
<form class="sspa-ajax-compare">
<label><?php esc_html_e('Save as (optional)', 'super-speedy-performance-analysis'); ?> <input name="comparison_name" maxlength="80" placeholder="<?php esc_attr_e('Cart AJAX optimisation', 'super-speedy-performance-analysis'); ?>"></label>
<?php foreach (array('before' => __('Before', 'super-speedy-performance-analysis'), 'after' => __('After', 'super-speedy-performance-analysis')) as $sspa_key => $sspa_label) : ?>
<label><?php echo esc_html($sspa_label); ?> <select name="<?php echo esc_attr($sspa_key); ?>">
<?php foreach (array_reverse($sspa_windows) as $sspa_window) : ?>
<option value="<?php echo esc_attr($sspa_window['uuid']); ?>"><?php echo esc_html($sspa_window['label'] . ' · ' . $sspa_window['created']); ?></option>
<?php endforeach; ?></select></label>
<?php endforeach; ?>
<button class="button button-primary"><?php esc_html_e('Compare', 'super-speedy-performance-analysis'); ?></button>
</form>
<p class="sspa-ajax-status" role="status"></p>
<div class="sspa-ajax-results" hidden>
<label><?php esc_html_e('Filter endpoint or scenario', 'super-speedy-performance-analysis'); ?> <input type="search" class="sspa-ajax-filter"></label>
<button type="button" class="button sspa-ajax-export"><?php esc_html_e('Export chart and measured summary', 'super-speedy-performance-analysis'); ?></button>
<div class="sspa-ajax-headlines"></div>
<div class="sspa-ajax-chart" style="height:460px;width:100%"></div>
<div class="sspa-ajax-summary"></div>
<details><summary><?php esc_html_e('Selected request: setup and plugin activity', 'super-speedy-performance-analysis'); ?></summary><pre class="sspa-ajax-point" style="max-height:450px;overflow:auto;white-space:pre-wrap"></pre></details>
</div>
</section>
