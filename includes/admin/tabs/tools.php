<?php
defined('ABSPATH') || exit;

$sspa_env = SSPA_Tools::environment();
$sspa_caps = SSPA_Tools::capabilities();
$sspa_apms = SSPA_Tools::detected_apms();

$sspa_status_label = array(
    SSPA_Tools::STATUS_ACTIVE => __('Active', 'super-speedy-performance-analysis'),
    SSPA_Tools::STATUS_AVAILABLE => __('Available', 'super-speedy-performance-analysis'),
    SSPA_Tools::STATUS_BLOCKED => __('Needs permission', 'super-speedy-performance-analysis'),
    SSPA_Tools::STATUS_MISSING => __('Not installed', 'super-speedy-performance-analysis'),
);
?>

<p class="description">
    <?php esc_html_e('Everything on this page is optional. The analysis works without any of it. These add depth: which function inside a plugin is slow, and how many rows MySQL really examined rather than an estimate.', 'super-speedy-performance-analysis'); ?>
</p>
<p class="description">
    <strong><?php esc_html_e('This plugin never installs anything itself.', 'super-speedy-performance-analysis'); ?></strong>
    <?php esc_html_e('It does not edit php.ini, run pecl, or restart anything. It shows you the exact commands for this server, and you or your host run them.', 'super-speedy-performance-analysis'); ?>
</p>

<table class="widefat sspa-tools">
    <thead>
        <tr>
            <th><?php esc_html_e('Capability', 'super-speedy-performance-analysis'); ?></th>
            <th><?php esc_html_e('What it adds', 'super-speedy-performance-analysis'); ?></th>
            <th><?php esc_html_e('Status', 'super-speedy-performance-analysis'); ?></th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($sspa_caps as $sspa_key => $sspa_cap) :
        $sspa_steps = $sspa_cap['install'] ? SSPA_Tools::install_steps($sspa_cap['install']) : array();
        $sspa_needs = in_array($sspa_cap['status'], array(SSPA_Tools::STATUS_MISSING, SSPA_Tools::STATUS_BLOCKED), true);
        ?>
        <tr>
            <td><strong><?php echo esc_html($sspa_cap['label']); ?></strong></td>
            <td>
                <?php echo esc_html($sspa_cap['adds']); ?>
                <?php if ($sspa_cap['detail']) : ?>
                    <br><em><?php echo esc_html($sspa_cap['detail']); ?></em>
                <?php endif; ?>
                <?php if (!$sspa_cap['used'] && $sspa_cap['status'] !== SSPA_Tools::STATUS_MISSING) : ?>
                    <br><span class="sspa-not-used"><?php esc_html_e('Detected, but this plugin does not read it yet - support is being built.', 'super-speedy-performance-analysis'); ?></span>
                <?php endif; ?>
            </td>
            <td>
                <span class="sspa-status sspa-status-<?php echo esc_attr($sspa_cap['status']); ?>">
                    <?php echo esc_html($sspa_status_label[$sspa_cap['status']]); ?>
                </span>
                <?php if ($sspa_needs && $sspa_steps) : ?>
                    <br>
                    <button type="button" class="button button-small sspa-steps-toggle"
                            data-target="sspa-steps-<?php echo esc_attr($sspa_key); ?>"
                            aria-expanded="false">
                        <?php esc_html_e('Show installation steps', 'super-speedy-performance-analysis'); ?>
                    </button>
                <?php endif; ?>
            </td>
        </tr>
        <?php if ($sspa_needs && $sspa_steps) : ?>
            <tr class="sspa-steps-row" id="sspa-steps-<?php echo esc_attr($sspa_key); ?>" style="display:none">
                <td colspan="3">
                    <?php foreach ($sspa_steps as $sspa_step) : ?>
                        <h4><?php echo esc_html($sspa_step['title']); ?></h4>
                        <p class="description"><?php echo esc_html($sspa_step['why']); ?></p>
                        <div class="sspa-code-block">
                            <pre><code><?php echo esc_html($sspa_step['code']); ?></code></pre>
                            <button type="button" class="button button-small sspa-copy"><?php esc_html_e('Copy', 'super-speedy-performance-analysis'); ?></button>
                        </div>
                    <?php endforeach; ?>

                    <h4><?php esc_html_e('Cannot run these yourself?', 'super-speedy-performance-analysis'); ?></h4>
                    <p class="description"><?php esc_html_e('Most shared hosting cannot install PHP extensions, and that is not a fault in your setup. Paste this into a support ticket - hosts turn down vague requests far more often than specific ones.', 'super-speedy-performance-analysis'); ?></p>
                    <div class="sspa-code-block">
                        <pre><code><?php echo esc_html(SSPA_Tools::host_message($sspa_key)); ?></code></pre>
                        <button type="button" class="button button-small sspa-copy"><?php esc_html_e('Copy message', 'super-speedy-performance-analysis'); ?></button>
                    </div>
                </td>
            </tr>
        <?php endif; ?>
    <?php endforeach; ?>
    </tbody>
</table>

<p>
    <a href="<?php echo esc_url(add_query_arg('sspa_recheck', time())); ?>" class="button">
        <?php esc_html_e('Re-check', 'super-speedy-performance-analysis'); ?>
    </a>
    <span class="description"><?php esc_html_e('After installing an extension, restart PHP (not just reload) before re-checking.', 'super-speedy-performance-analysis'); ?></span>
</p>

<?php if ($sspa_apms) : ?>
    <h3><?php esc_html_e('Other monitoring already on this server', 'super-speedy-performance-analysis'); ?></h3>
    <p class="description">
        <?php esc_html_e('These are third-party agents we detected. This plugin does not send anything to them and does not read from them.', 'super-speedy-performance-analysis'); ?>
    </p>
    <ul>
        <?php foreach ($sspa_apms as $sspa_ext => $sspa_name) : ?>
            <li><strong><?php echo esc_html($sspa_name); ?></strong> <code><?php echo esc_html($sspa_ext); ?></code></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<h3><?php esc_html_e('This server', 'super-speedy-performance-analysis'); ?></h3>
<table class="widefat striped sspa-env">
    <tbody>
        <tr><td><?php esc_html_e('Operating system', 'super-speedy-performance-analysis'); ?></td><td><?php echo esc_html($sspa_env['distro'] . ' (' . $sspa_env['uname'] . ')'); ?></td></tr>
        <tr><td><?php esc_html_e('PHP', 'super-speedy-performance-analysis'); ?></td><td><?php echo esc_html($sspa_env['php'] . ' - ' . $sspa_env['sapi'] . ($sspa_env['zts'] ? ' (thread safe)' : '')); ?></td></tr>
        <tr><td><?php esc_html_e('PHP ini scan directory', 'super-speedy-performance-analysis'); ?></td><td><code><?php echo esc_html($sspa_env['ini_dir'] !== '' ? $sspa_env['ini_dir'] : __('not reported', 'super-speedy-performance-analysis')); ?></code></td></tr>
        <tr><td><?php esc_html_e('Database', 'super-speedy-performance-analysis'); ?></td><td><?php echo esc_html($sspa_env['mysql']); ?></td></tr>
    </tbody>
</table>
