<?php
defined('ABSPATH') || exit;

/**
 * "Request a fix quote": send one page's Markdown report to superspeedyplugins.com.
 *
 * Nothing is sent until an administrator opens the dialog on a Performance Analysis screen,
 * reads the preview, ticks the unticked consent box and presses Send. The request is made by
 * this site's server with wp_remote_post(), never by the browser. The report sent is the exact
 * Markdown built for the preview (kept server side under a one-time request ID), so what was
 * read is what is sent, byte for byte. The JSON export is never sent.
 *
 * Receiving side: Super Speedy Contact's POST /wp-json/ssp-contact/v1/quote-request.
 * Privacy: https://www.superspeedyplugins.com/privacy-policy/#performance-review-requests
 */
class SSPA_Quote_Request {

    const ENDPOINT = 'https://www.superspeedyplugins.com/wp-json/ssp-contact/v1/quote-request';
    const PRIVACY_URL = 'https://www.superspeedyplugins.com/privacy-policy/#performance-review-requests';
    const TERMS_URL = 'https://www.superspeedyplugins.com/terms-conditions/';
    /** Changes whenever the consent wording changes; stored by the receiver with the request. */
    const CONSENT_VERSION = 'pa-quote-2026-10-21';
    /** Where in PA the request was opened. The receiver accepts exactly these values. */
    const SOURCES = array('page-analysis', 'cache-tab', 'summary', 'compare');
    /** The receiver's Markdown limit (200 KB), shown beside the preview. The receiver enforces it. */
    const MAX_MARKDOWN_BYTES = 204800;
    const DRAFT_TTL = 7200;

    /**
     * The receiving URL. Regression sites point this at a local copy of the real receiver
     * by defining SSPA_QUOTE_REQUEST_TEST_ENDPOINT in their wp-config.php (see
     * .tests/quote-request/run.sh). It is not a setting: without that constant the request
     * always goes to superspeedyplugins.com.
     */
    public static function endpoint() {
        if (defined('SSPA_QUOTE_REQUEST_TEST_ENDPOINT') && preg_match('#^https?://#i', (string) SSPA_QUOTE_REQUEST_TEST_ENDPOINT)) {
            return (string) SSPA_QUOTE_REQUEST_TEST_ENDPOINT;
        }
        return self::ENDPOINT;
    }

    /**
     * The button that opens the dialog for one stored page profile. Empty when the user
     * cannot manage options or there is no profile to send.
     */
    public static function button_html($profile_id, $source, $class = 'button') {
        $profile_id = (int) $profile_id;
        if ($profile_id < 1 || !current_user_can('manage_options') || !in_array($source, self::SOURCES, true)) {
            return '';
        }
        return '<button type="button" class="' . esc_attr($class) . ' sspa-quote-open" data-profile-id="' . $profile_id . '" data-source="' . esc_attr($source) . '" aria-haspopup="dialog">'
            . esc_html__('Request a fix quote', 'super-speedy-performance-analysis') . '</button>';
    }

    /** Allowed markup for button_html() where a caller runs output through wp_kses. */
    public static function allowed_button_html() {
        return array('button' => array('type' => true, 'class' => true, 'data-profile-id' => true, 'data-source' => true, 'aria-haspopup' => true));
    }

    private static function draft_key($request_uuid) {
        return 'sspa_quote_' . md5((string) $request_uuid);
    }

    private static function guard() {
        check_ajax_referer('sspa_admin', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('You are not allowed to request a quote from this site.', 'super-speedy-performance-analysis')), 403);
        }
    }

    /**
     * Open the dialog: build the page Markdown once, keep it under a new request ID for the
     * Send that may follow, and return the dialog markup with that exact Markdown in it.
     * Nothing leaves the site here.
     */
    public static function ajax_preview() {
        self::guard();
        $profile_id = isset($_POST['profile_id']) ? (int) $_POST['profile_id'] : 0;
        $source = isset($_POST['source']) ? sanitize_key(wp_unslash($_POST['source'])) : '';
        if (!in_array($source, self::SOURCES, true)) {
            wp_send_json_error(array('message' => __('Unknown request source.', 'super-speedy-performance-analysis')), 400);
        }
        $built = SSPA_Markdown_Export::build('page', $profile_id);
        if (is_wp_error($built)) {
            wp_send_json_error(array('message' => $built->get_error_message()));
        }
        $request_uuid = wp_generate_uuid4();
        set_transient(self::draft_key($request_uuid), array(
            'user_id' => get_current_user_id(),
            'profile_id' => $profile_id,
            'source' => $source,
            'markdown' => $built['markdown'],
            'sha256' => hash('sha256', $built['markdown']),
        ), self::DRAFT_TTL);
        wp_send_json_success(array(
            'request_uuid' => $request_uuid,
            'html' => self::dialog_html($request_uuid, $built['markdown']),
        ));
    }

    /** The dialog. Every choice starts unticked; the Markdown is shown in full and read-only. */
    public static function dialog_html($request_uuid, $markdown) {
        $user = wp_get_current_user();
        $bytes = strlen($markdown);
        $html = '<div class="sspa-quote-dialog" role="dialog" aria-modal="true" aria-labelledby="sspa-quote-title" data-request-uuid="' . esc_attr($request_uuid) . '">';
        $html .= '<form class="sspa-quote-form" novalidate>';
        $html .= '<h2 id="sspa-quote-title">' . esc_html__('Request a fix quote', 'super-speedy-performance-analysis') . '</h2>';
        $html .= '<p>' . esc_html__('Send this page report to Super Speedy Plugins and get a fixed-price quote for fixing what it found. Nothing is sent until you tick the consent box and press Send. The request goes from this site\'s server to superspeedyplugins.com.', 'super-speedy-performance-analysis') . '</p>';
        $html .= '<p><label for="sspa-quote-markdown"><strong>' . esc_html__('The report that will be sent', 'super-speedy-performance-analysis') . '</strong></label> ';
        $html .= '<span class="sspa-quote-size">' . esc_html(sprintf(
            /* translators: 1: report size, 2: largest size accepted */
            __('(page Markdown, %1$s; the limit is %2$s)', 'super-speedy-performance-analysis'),
            size_format($bytes, 1),
            size_format(self::MAX_MARKDOWN_BYTES)
        )) . '</span></p>';
        if ($bytes > self::MAX_MARKDOWN_BYTES) {
            $html .= '<p class="sspa-quote-warning">' . esc_html__('This report is larger than superspeedyplugins.com accepts, so it will be refused.', 'super-speedy-performance-analysis') . '</p>';
        }
        $html .= '<textarea id="sspa-quote-markdown" class="sspa-quote-markdown" readonly rows="14">' . esc_textarea($markdown) . '</textarea>';
        $html .= '<div class="sspa-quote-fields">';
        $html .= '<p><label for="sspa-quote-name">' . esc_html__('Your name', 'super-speedy-performance-analysis') . '</label><input type="text" id="sspa-quote-name" name="name" maxlength="200" autocomplete="name" value="' . esc_attr($user->display_name) . '"></p>';
        $html .= '<p><label for="sspa-quote-email">' . esc_html__('Your email address', 'super-speedy-performance-analysis') . '</label><input type="email" id="sspa-quote-email" name="email" maxlength="254" autocomplete="email" value="' . esc_attr($user->user_email) . '"></p>';
        $html .= '<p><label for="sspa-quote-site">' . esc_html__('Site address (optional; clear it to leave it out)', 'super-speedy-performance-analysis') . '</label><input type="url" id="sspa-quote-site" name="site_url" maxlength="2048" value="' . esc_attr(home_url('/')) . '"></p>';
        $html .= '<p><label for="sspa-quote-message">' . esc_html__('What would you like fixed? (optional)', 'super-speedy-performance-analysis') . '</label><textarea id="sspa-quote-message" name="message" maxlength="5000" rows="3"></textarea></p>';
        $html .= '</div>';
        $html .= '<p><label><input type="checkbox" name="ongoing_care" value="1"> ' . esc_html__('I am also interested in ongoing performance care', 'super-speedy-performance-analysis') . '</label></p>';
        $html .= '<p><label><input type="checkbox" name="marketing_opt_in" value="1"> ' . esc_html__('Email me performance tips and offers', 'super-speedy-performance-analysis') . '</label></p>';
        $html .= '<p class="sspa-quote-consent"><label><input type="checkbox" name="consent" value="1"> ' . esc_html(self::consent_text()) . '</label> '
            . '<a href="' . esc_url(self::PRIVACY_URL) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('How superspeedyplugins.com handles performance review requests', 'super-speedy-performance-analysis') . '</a> · '
            . '<a href="' . esc_url(self::TERMS_URL) . '" target="_blank" rel="noopener noreferrer">' . esc_html__('Terms and conditions', 'super-speedy-performance-analysis') . '</a></p>';
        $html .= '<p class="sspa-quote-status" role="status" aria-live="polite"></p>';
        $html .= '<p class="sspa-quote-actions"><button type="submit" class="button button-primary sspa-quote-send">' . esc_html__('Send', 'super-speedy-performance-analysis') . '</button> '
            . '<button type="button" class="button sspa-quote-close">' . esc_html__('Cancel', 'super-speedy-performance-analysis') . '</button></p>';
        $html .= '</form></div>';
        return $html;
    }

    /** The consent wording. Change CONSENT_VERSION whenever this changes. */
    public static function consent_text() {
        return __('I agree to send this report, my name and email address, the site address if given, my message and the choices above to Super Speedy Plugins (WP Intense OÜ) at superspeedyplugins.com, so that they can reply and prepare a fixed-price quote. The request also records my consent, the time, this web server\'s IP address and the plugin version. It is never joined to anything shared with superspeedy.org.', 'super-speedy-performance-analysis');
    }

    /**
     * Send. Refused without the consent tick; otherwise posts the previewed Markdown exactly as
     * shown. A retry with the same request ID is safe: the receiver answers a repeat with the
     * original reference and stores nothing new.
     */
    public static function ajax_send() {
        self::guard();
        $request_uuid = isset($_POST['request_uuid']) ? sanitize_text_field(wp_unslash($_POST['request_uuid'])) : '';
        $draft = $request_uuid ? get_transient(self::draft_key($request_uuid)) : false;
        if (!is_array($draft) || (int) $draft['user_id'] !== get_current_user_id()) {
            wp_send_json_error(array('code' => 'expired', 'message' => __('This request has expired. Close it and open Request a fix quote again.', 'super-speedy-performance-analysis')));
        }
        if (!isset($_POST['consent']) || '1' !== sanitize_text_field(wp_unslash($_POST['consent']))) {
            wp_send_json_error(array('code' => 'consent_required', 'message' => __('Tick the consent box to send the request. Nothing has been sent.', 'super-speedy-performance-analysis')));
        }
        $site_url = isset($_POST['site_url']) ? trim(sanitize_text_field(wp_unslash($_POST['site_url']))) : '';
        $body = array(
            'name' => isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '',
            'email' => isset($_POST['email']) ? trim(sanitize_text_field(wp_unslash($_POST['email']))) : '',
            'site_url' => '' === $site_url ? '' : esc_url_raw($site_url, array('http', 'https')),
            'markdown' => $draft['markdown'],
            'source' => $draft['source'],
            'ongoing_care' => isset($_POST['ongoing_care']) && '1' === sanitize_text_field(wp_unslash($_POST['ongoing_care'])),
            'marketing_opt_in' => isset($_POST['marketing_opt_in']) && '1' === sanitize_text_field(wp_unslash($_POST['marketing_opt_in'])),
            'consent' => true,
            'consent_version' => self::CONSENT_VERSION,
            'pa_version' => SSPA_VERSION,
            'message' => isset($_POST['message']) ? sanitize_textarea_field(wp_unslash($_POST['message'])) : '',
            'request_uuid' => $request_uuid,
        );
        $result = self::send($body);
        if (is_wp_error($result)) {
            wp_send_json_error(array('code' => $result->get_error_code(), 'message' => $result->get_error_message()));
        }
        wp_send_json_success($result);
    }

    /**
     * One request to the receiver.
     *
     * @return array|WP_Error {reference, status, message}
     */
    public static function send($body) {
        $response = wp_remote_post(self::endpoint(), array(
            // The receiver stores the request and emails it before answering.
            'timeout' => 30,
            'redirection' => 0,
            // WordPress's default User-Agent carries the site address, which the user may have
            // chosen to leave out.
            'user-agent' => 'Super Speedy Performance Analysis/' . SSPA_VERSION,
            'headers' => array('Content-Type' => 'application/json; charset=utf-8', 'Accept' => 'application/json'),
            'body' => wp_json_encode($body),
            'data_format' => 'body',
        ));
        if (is_wp_error($response)) {
            return new WP_Error('transport', sprintf(
                /* translators: %s: the transport error, e.g. a connection timeout */
                __('The request could not reach superspeedyplugins.com (%s). Press Send to try again; a repeat is never stored twice.', 'super-speedy-performance-analysis'),
                $response->get_error_message()
            ));
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        $data = is_array($data) ? $data : array();
        if ((201 === $status || 200 === $status) && !empty($data['reference']) && preg_match('/^SSPQ-[A-Z0-9]+$/', (string) $data['reference'])) {
            $reference = (string) $data['reference'];
            return array(
                'reference' => $reference,
                'status' => isset($data['status']) ? sanitize_key($data['status']) : '',
                'message' => sprintf(
                    /* translators: %s: request reference, e.g. SSPQ-AB12CD34 */
                    __('Sent. Your reference is %s. Super Speedy Plugins will reply by email.', 'super-speedy-performance-analysis'),
                    $reference
                ),
            );
        }
        $code = isset($data['code']) ? sanitize_key($data['code']) : 'http_' . $status;
        if (429 === $status) {
            $retry = (int) wp_remote_retrieve_header($response, 'retry-after');
            if ($retry < 1 && isset($data['data']['retry_after'])) {
                $retry = (int) $data['data']['retry_after'];
            }
            $minutes = max(1, (int) ceil($retry / MINUTE_IN_SECONDS));
            return new WP_Error($code, sprintf(
                /* translators: %d: minutes to wait */
                _n('Too many requests have been sent from this site recently. Try again in %d minute.', 'Too many requests have been sent from this site recently. Try again in %d minutes.', $minutes, 'super-speedy-performance-analysis'),
                $minutes
            ));
        }
        if (413 === $status) {
            return new WP_Error($code, __('The report is larger than superspeedyplugins.com accepts (200 KB), so nothing was stored.', 'super-speedy-performance-analysis'));
        }
        if (415 === $status) {
            return new WP_Error($code, __('superspeedyplugins.com did not accept the request format. Update Performance Analysis and try again.', 'super-speedy-performance-analysis'));
        }
        if (503 === $status) {
            return new WP_Error($code, __('superspeedyplugins.com could not store the request just now. Press Send to try again later; a repeat is never stored twice.', 'super-speedy-performance-analysis'));
        }
        if (400 === $status) {
            $field_names = array(
                'name' => __('your name', 'super-speedy-performance-analysis'),
                'email' => __('your email address', 'super-speedy-performance-analysis'),
                'site_url' => __('the site address', 'super-speedy-performance-analysis'),
                'message' => __('the message', 'super-speedy-performance-analysis'),
            );
            $field = isset($data['data']['field']) ? (string) $data['data']['field'] : '';
            if ('invalid_field' === $code && isset($field_names[$field])) {
                return new WP_Error($code, sprintf(
                    /* translators: %s: the field that was refused, e.g. "your email address" */
                    __('superspeedyplugins.com refused the request: check %s. Nothing was stored.', 'super-speedy-performance-analysis'),
                    $field_names[$field]
                ));
            }
            return new WP_Error($code, sprintf(
                /* translators: %s: the receiver's error code */
                __('superspeedyplugins.com refused the request (%s). Nothing was stored.', 'super-speedy-performance-analysis'),
                $code
            ));
        }
        return new WP_Error($code, sprintf(
            /* translators: %d: HTTP status code */
            __('superspeedyplugins.com answered with an unexpected HTTP status (%d). The request may not have been stored.', 'super-speedy-performance-analysis'),
            $status
        ));
    }
}
