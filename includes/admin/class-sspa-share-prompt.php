<?php
defined('ABSPATH') || exit;

/**
 * The share panel: a request to turn on automatic sharing, shown on Performance Analysis's own
 * screens only - after a completed analysis, in its result view, and on the Compare tab - while
 * sharing is off. Never an admin notice and never a modal: it sits at the top of the content and
 * the rest of the screen works with it there.
 *
 * Its primary button posts the Share tab checkbox's own action, so the option and the consent
 * record are exactly the ones the checkbox writes. Its other button hides it for this user for
 * 24 hours. Turning sharing on hides it for good; the only other permanent opt-out is the Share
 * tab's "Don't ask me again", which can be undone there.
 */
class SSPA_Share_Prompt {

    const SNOOZE_META = 'sspa_share_prompt_snoozed_until';
    const NEVER_META = 'sspa_share_prompt_never';

    /** Whether the current user should see the panel now. */
    public static function visible() {
        if (!current_user_can('manage_options') || SSPA_Submitter::opted_in()) {
            return false;
        }
        $user_id = get_current_user_id();
        if (get_user_meta($user_id, self::NEVER_META, true)) {
            return false;
        }
        return (int) get_user_meta($user_id, self::SNOOZE_META, true) <= time();
    }

    public static function never_asked($user_id = 0) {
        return (bool) get_user_meta($user_id ? $user_id : get_current_user_id(), self::NEVER_META, true);
    }

    /** The panel's markup, or '' when it should not be shown. */
    public static function html() {
        if (!self::visible()) {
            return '';
        }
        ob_start();
        include SSPA_PLUGIN_DIR . 'includes/admin/partials/share-prompt.php';
        return ob_get_clean();
    }

    public static function render() {
        echo wp_kses(self::html(), self::allowed_html());
    }

    public static function allowed_html() {
        return array(
            'section' => array('class' => true, 'aria-labelledby' => true),
            'h2' => array('id' => true),
            'p' => array('class' => true),
            'a' => array('href' => true, 'target' => true, 'rel' => true, 'class' => true, 'data-tab' => true),
            'strong' => array(),
            'button' => array('type' => true, 'class' => true),
            'span' => array('class' => true, 'role' => true, 'aria-live' => true),
        );
    }

    /** "Don't share for 24 hours": hidden for this user until the stored time passes. */
    public static function ajax_snooze() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(__('Permission denied.', 'super-speedy-performance-analysis'), 403); }
        $until = time() + DAY_IN_SECONDS;
        update_user_meta(get_current_user_id(), self::SNOOZE_META, $until);
        wp_send_json_success(array('snoozed_until' => $until));
    }

    /** The Share tab's "Don't ask me again", on or off. */
    public static function ajax_never() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) { wp_send_json_error(__('Permission denied.', 'super-speedy-performance-analysis'), 403); }
        if (!empty($_POST['never'])) {
            update_user_meta(get_current_user_id(), self::NEVER_META, 1);
        } else {
            delete_user_meta(get_current_user_id(), self::NEVER_META);
        }
        wp_send_json_success(array('never' => self::never_asked()));
    }
}
