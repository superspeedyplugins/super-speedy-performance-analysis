<?php
/**
 * Steps of the update watchdog journey, run with `wp eval-file helper.php <command> [args]`.
 * Every step uses WordPress's own code: Plugin_Upgrader and Theme_Upgrader for updates, the
 * run-analysis ability and the run controller for analyses, WP-Cron's event list for scheduling.
 */
// phpcs:ignoreFile

$command = isset($args[0]) ? $args[0] : '';
$rest = array_slice($args, 1);

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
require_once ABSPATH . 'wp-admin/includes/theme.php';

function sspa_wd_out($value) {
    echo is_scalar($value) ? $value : wp_json_encode($value);
    echo "\n";
}

/** Put a plugin's files in place from a zip WITHOUT an update or activation hook (test setup). */
function sspa_wd_place($zip, $dir) {
    $zipper = new ZipArchive();
    if (true !== $zipper->open($zip)) {
        return false;
    }
    $ok = $zipper->extractTo($dir);
    $zipper->close();
    return $ok;
}

switch ($command) {
    case 'reset':
        // Clear on the way in: watchdog state, its events and the recorded mail. Nothing at the end.
        delete_option('sspa_watchdog_state');
        delete_option('sspa_watchdog_log');
        delete_option('sspa_test_watchdog_mail');
        delete_option('sspa_wd_noisy_remaining');
        foreach (array('sspa_watchdog_run', 'sspa_watchdog_step') as $hook) {
            wp_clear_scheduled_hook($hook);
        }
        if (class_exists('SSPA_Change_Set')) {
            SSPA_Change_Set::dismiss();
        }
        sspa_wd_out('reset');
        break;

    case 'settings':
        // settings <json>: the update-watchdog-settings ability, as an agent would call it.
        wp_set_current_user(1);
        $ability = function_exists('wp_get_ability') ? wp_get_ability('sspa/update-watchdog-settings') : null;
        if (!$ability) {
            sspa_wd_out('missing-ability');
            break;
        }
        $result = $ability->execute(json_decode($rest[0], true));
        sspa_wd_out(is_wp_error($result) ? 'error: ' . $result->get_error_message() : 'ok');
        break;

    case 'ability':
        // ability <name> [json]: run one sspa ability through the Abilities API as the administrator.
        wp_set_current_user(1);
        $ability = function_exists('wp_get_ability') ? wp_get_ability('sspa/' . $rest[0]) : null;
        if (!$ability) {
            sspa_wd_out(array('error' => 'missing ability ' . $rest[0]));
            break;
        }
        $result = $ability->execute(isset($rest[1]) ? json_decode($rest[1], true) : array());
        sspa_wd_out(is_wp_error($result) ? array('error' => $result->get_error_message()) : $result);
        break;

    case 'place-plugin':
        // place-plugin <slug> <zip>: install files and activate silently (no change recorded).
        list($slug, $zip) = $rest;
        $file = $slug . '/' . $slug . '.php';
        deactivate_plugins($file, true);
        $dir = WP_PLUGIN_DIR . '/' . $slug;
        if (is_dir($dir)) {
            global $wp_filesystem;
            WP_Filesystem();
            $wp_filesystem->delete($dir, true);
        }
        sspa_wd_place($zip, WP_PLUGIN_DIR);
        wp_clean_plugins_cache(false);
        $activated = activate_plugin($file, '', false, true);
        sspa_wd_out(is_wp_error($activated) ? 'error: ' . $activated->get_error_message() : get_plugin_data(WP_PLUGIN_DIR . '/' . $file, false, false)['Version']);
        break;

    case 'place-theme':
        list($slug, $zip) = $rest;
        $dir = get_theme_root() . '/' . $slug;
        if (is_dir($dir)) {
            global $wp_filesystem;
            WP_Filesystem();
            $wp_filesystem->delete($dir, true);
        }
        sspa_wd_place($zip, get_theme_root());
        wp_clean_themes_cache();
        sspa_wd_out(wp_get_theme($slug)->get('Version'));
        break;

    case 'update-plugin':
        // update-plugin <slug> <version> <zip>: offer the update the way wordpress.org does (the
        // update_plugins transient) and run WordPress's own Plugin_Upgrader on it.
        list($slug, $version, $zip) = $rest;
        $file = $slug . '/' . $slug . '.php';
        $was_active = is_plugin_active($file);
        $offer = get_site_transient('update_plugins');
        if (!is_object($offer)) {
            $offer = new stdClass();
        }
        $offer->response = isset($offer->response) && is_array($offer->response) ? $offer->response : array();
        $offer->response[$file] = (object) array(
            'slug' => $slug, 'plugin' => $file, 'new_version' => $version, 'package' => $zip, 'url' => '',
        );
        set_site_transient('update_plugins', $offer);
        $upgrader = new Plugin_Upgrader(new Automatic_Upgrader_Skin());
        $result = $upgrader->upgrade($file);
        // WordPress reactivates an updated plugin in a separate request (wp-admin) or keeps it
        // active (WP-Cron); WP-CLI's plugin update reactivates it. Do the same here.
        if ($was_active && !is_plugin_active($file)) {
            activate_plugin($file, '', false, true);
        }
        wp_clean_plugins_cache(false);
        sspa_wd_out((true === $result ? 'updated ' : 'failed ') . get_plugin_data(WP_PLUGIN_DIR . '/' . $file, false, false)['Version']);
        break;

    case 'update-theme':
        list($slug, $version, $zip) = $rest;
        $offer = get_site_transient('update_themes');
        if (!is_object($offer)) {
            $offer = new stdClass();
        }
        $offer->response = isset($offer->response) && is_array($offer->response) ? $offer->response : array();
        $offer->response[$slug] = array('theme' => $slug, 'new_version' => $version, 'package' => $zip, 'url' => '');
        set_site_transient('update_themes', $offer);
        $upgrader = new Theme_Upgrader(new Automatic_Upgrader_Skin());
        $result = $upgrader->upgrade($slug);
        wp_clean_themes_cache();
        sspa_wd_out((true === $result ? 'updated ' : 'failed ') . wp_get_theme($slug)->get('Version'));
        break;

    case 'events':
        // events <hook>: how many times the hook is scheduled, and seconds until the next one.
        $count = 0;
        $next = 0;
        foreach ((array) _get_cron_array() as $timestamp => $hooks) {
            if (isset($hooks[$rest[0]])) {
                $count += count($hooks[$rest[0]]);
                $next = $next ? min($next, $timestamp) : $timestamp;
            }
        }
        sspa_wd_out($count . ' ' . ($next ? $next - time() : -1));
        break;

    case 'analysis':
        // analysis <type> <page,keys>: the run-analysis ability (or the checkout flow), driven to
        // the end with the run controller's own batches, as advance-run does.
        wp_set_current_user(1);
        if ('checkout' === $rest[0]) {
            $run_id = SSPA_Run_Controller::start(array('type' => 'checkout', 'trigger' => 'cli'));
        } else {
            $input = array('type' => $rest[0]);
            if (!empty($rest[1])) {
                $input['pages'] = explode(',', $rest[1]);
            }
            $started = wp_get_ability('sspa/run-analysis')->execute($input);
            $run_id = is_wp_error($started) ? $started : (int) $started['run_id'];
        }
        if (is_wp_error($run_id)) {
            sspa_wd_out('error: ' . $run_id->get_error_message());
            break;
        }
        $deadline = time() + 900;
        do {
            SSPA_Run_Controller::process_batch($run_id);
            $run = SSPA_Run_Controller::run_row($run_id);
        } while (in_array($run['status'], array('queued', 'crawling', 'analysing'), true) && time() < $deadline);
        sspa_wd_out($run_id . ' ' . $run['status']);
        break;

    case 'state':
        sspa_wd_out(get_option('sspa_watchdog_state', array()));
        break;

    case 'busy':
        // Whether a watchdog check is in progress (current set) or due (run event scheduled).
        $state = get_option('sspa_watchdog_state', array());
        sspa_wd_out(!empty($state['current']) ? 'running' : 'idle');
        break;

    case 'log':
        sspa_wd_out(get_option('sspa_watchdog_log', array()));
        break;

    case 'mail':
        // mail <header-substring>: the recorded mails whose headers contain it.
        $log = get_option('sspa_test_watchdog_mail', array());
        $out = array();
        foreach ((array) $log as $mail) {
            if (false !== strpos($mail['headers'], $rest[0])) {
                $out[] = $mail;
            }
        }
        sspa_wd_out($out);
        break;

    case 'runs-since':
        // runs-since <id>: runs after it, with type, trigger and status.
        global $wpdb;
        sspa_wd_out($wpdb->get_results($wpdb->prepare(
            'SELECT id, run_type, trigger_source, status FROM %i WHERE id > %d ORDER BY id',
            SSPA_Schema::table('runs'), (int) $rest[0]
        ), ARRAY_A));
        break;

    case 'profile':
        // profile <run> <page_key>: response code, variant and generation time.
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT response_code, variant, page_gen_ms FROM %i WHERE run_id = %d AND page_key = %s',
            SSPA_Schema::table('profiles'), (int) $rest[0], $rest[1]
        ), ARRAY_A);
        sspa_wd_out($row ? ((int) $row['response_code'] . ' ' . $row['variant'] . ' ' . round((float) $row['page_gen_ms'])) : 'missing');
        break;

    case 'max-run':
        global $wpdb;
        sspa_wd_out((int) $wpdb->get_var($wpdb->prepare('SELECT MAX(id) FROM %i', SSPA_Schema::table('runs'))));
        break;

    case 'requests-per-page':
        // What one page costs in requests under the site's Measurement runs per page.
        $discipline = SSPA_Measurement::discipline();
        sspa_wd_out((int) $discipline['warmups'] + (int) $discipline['samples']);
        break;

    default:
        sspa_wd_out('unknown command ' . $command);
}
