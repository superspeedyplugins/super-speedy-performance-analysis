<?php
defined('ABSPATH') || exit;
$windows = SSPA_Ajax_Profile::windows();
$active = SSPA_Traffic_Collection::active();
$report = SSPA_Report::endpoint_evidence();
?>
<section id="sspa-ajax-profile">
<h2><?php esc_html_e('Profile AJAX', 'super-speedy-performance-analysis'); ?></h2>
<p>Record a before window, exercise the real workflow, then stop. Change the selected endpoint in Scalability Pro and repeat the same scenario in an after window. Only endpoints explicitly enabled in Scalability Pro change which plugins load.</p>
<p>Measurements stay on this site. No requests are replayed. Timings are server request time, not browser elapsed time. Avoid personal data in labels.</p>
<form class="sspa-ajax-start">
<label>Window name <input name="label" required maxlength="80" placeholder="Before cart refresh"></label>
<label>Scenario <input name="scenario" required maxlength="80" placeholder="One item, refresh cart"></label>
<label>Endpoint group <select class="sspa-ajax-endpoint-group"><option value="">All groups</option>
<?php if (class_exists('SPRO_Fast_Ajax_Advice')) : foreach (SPRO_Fast_Ajax_Advice::groups() as $group => $label) : ?>
<option value="<?php echo esc_attr($group); ?>"><?php echo esc_html($label); ?></option>
<?php endforeach; endif; ?></select></label>
<label>Endpoints <select name="endpoints[]" multiple size="5" style="min-width:300px;min-height:120px;max-width:100%" aria-label="Endpoints to profile">
<?php if (!is_wp_error($report)) : foreach ($report['endpoints'] as $endpoint) :
$id = $endpoint['identity']; $selector = $id['transport'] . ':' . ($id['action'] ?: $id['route_pattern']);
$classification = class_exists('SPRO_Fast_Ajax_Advice') ? SPRO_Fast_Ajax_Advice::endpoint(array('transport' => $id['transport'], 'endpoint' => $id['action'] ?: $id['route_pattern'], 'method' => $id['method'], 'context' => $id['auth_context'])) : array('group' => 'unknown'); ?>
<option data-group="<?php echo esc_attr($classification['group']); ?>" value="<?php echo esc_attr($selector); ?>"><?php echo esc_html($selector); ?></option>
<?php endforeach; endif; ?>
<?php if (is_wp_error($report) || empty($report['endpoints'])) : ?><option disabled>No endpoints observed yet; start a discovery window.</option><?php endif; ?>
</select></label>
<p class="description">Leave endpoints unselected to discover all registered AJAX/REST requests in this window.</p>
<label><input type="checkbox" name="detail" value="1"> Sample plugin activity (diagnostic: at most 20 requests, 5 per action; adds overhead)</label>
<p class="description">Named action execution, hook registration checkpoints and I/O attempts. Partial coverage cannot prove a plugin does no work. Direct REST callbacks and filters are not timed.</p>
<p><button class="button button-primary" <?php disabled((bool) $active); ?>>Start window (15 minutes / 200 observations)</button></p>
</form>
<?php if ($active) : ?>
<p>Collection #<?php echo (int) $active['id']; ?> is active. Exercise the workflow in another tab.</p>
<?php foreach ($windows as $window) : if ($window['collection_id'] === (int) $active['id']) : ?>
<button type="button" class="button sspa-ajax-stop" data-uuid="<?php echo esc_attr($window['uuid']); ?>">Stop window</button>
<?php endif; endforeach; endif; ?>
<h3>Compare saved windows</h3>
<label>Saved comparison <select class="sspa-ajax-saved"><option value="">Choose a saved comparison</option>
<?php foreach (SSPA_Ajax_Profile::comparisons() as $comparison) : ?>
<option value="<?php echo esc_attr(wp_json_encode($comparison)); ?>"><?php echo esc_html($comparison['name']); ?></option>
<?php endforeach; ?></select></label>
<form class="sspa-ajax-compare">
<label>Save as (optional) <input name="comparison_name" maxlength="80" placeholder="Cart AJAX optimisation"></label>
<?php foreach (array('before' => 'Before', 'after' => 'After') as $key => $label) : ?>
<label><?php echo esc_html($label); ?> <select name="<?php echo esc_attr($key); ?>">
<?php foreach (array_reverse($windows) as $window) : ?>
<option value="<?php echo esc_attr($window['uuid']); ?>"><?php echo esc_html($window['label'] . ' · ' . $window['created']); ?></option>
<?php endforeach; ?></select></label>
<?php endforeach; ?>
<button class="button button-primary">Compare</button>
</form>
<p class="sspa-ajax-status" role="status"></p>
<div class="sspa-ajax-results" hidden>
<label>Filter endpoint or scenario <input type="search" class="sspa-ajax-filter"></label>
<button type="button" class="button sspa-ajax-export">Export chart and measured summary</button>
<div class="sspa-ajax-headlines"></div>
<div class="sspa-ajax-chart" style="height:460px;width:100%"></div>
<div class="sspa-ajax-summary"></div>
<details><summary>Selected request: setup and plugin activity</summary><pre class="sspa-ajax-point" style="max-height:450px;overflow:auto;white-space:pre-wrap"></pre></details>
</div>
</section>
