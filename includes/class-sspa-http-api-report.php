<?php
defined('ABSPATH') || exit;

/** Stable, privacy-safe outbound WordPress HTTP API inventory. */
class SSPA_HTTP_API_Report {

    const SCHEMA = 1;
    const CAPTURE_SCHEMA = 3;

    /** Build the public object consumed by Scalability Pro, Abilities and WP-CLI. */
    public static function build($run_id = 0) {
        global $wpdb;
        $run_id = $run_id ? (int) $run_id : (int) $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM %i WHERE status = 'done' AND run_type IN ('baseline','spot') ORDER BY id DESC LIMIT 1",
            SSPA_Schema::table('runs')
        ));
        if (!$run_id) {
            return new WP_Error('sspa_no_run', __('No completed baseline or spot analysis was found.', 'super-speedy-performance-analysis'));
        }
        $run = $wpdb->get_row($wpdb->prepare(
            "SELECT id, finished FROM %i WHERE id = %d AND status = 'done' AND run_type IN ('baseline','spot')",
            SSPA_Schema::table('runs'),
            $run_id
        ), ARRAY_A);
        if (!$run) {
            return new WP_Error('sspa_no_run', __('That run is not a completed baseline or spot analysis.', 'super-speedy-performance-analysis'));
        }

        $profiles = $wpdb->get_results($wpdb->prepare(
            'SELECT page_key, variant, profile_blob FROM %i WHERE run_id = %d ORDER BY id ASC',
            SSPA_Schema::table('profiles'),
            $run_id
        ), ARRAY_A);
        $aggregates = array();
        $reasons = array();
        $has_admin = false;

        foreach ($profiles as $profile) {
            if ('admin' === $profile['variant'] || 0 === strpos((string) $profile['page_key'], 'admin-') || 0 === strpos((string) $profile['page_key'], 'wp-admin-')) {
                $has_admin = true;
            }
            if (empty($profile['profile_blob'])) {
                $reasons['capture_unavailable'] = true;
                continue;
            }
            $json = @gzuncompress($profile['profile_blob']);
            $capture = $json ? json_decode($json, true) : null;
            if (!is_array($capture)) {
                $reasons['capture_unreadable'] = true;
                continue;
            }
            if (!isset($capture['schema']) || (int) $capture['schema'] < self::CAPTURE_SCHEMA) {
                $reasons['old_capture_schema'] = true;
            }
            if (!empty($capture['http']['truncated'])) {
                $reasons['http_capture_truncated'] = true;
            }
            foreach ((array) (isset($capture['http']['calls']) ? $capture['http']['calls'] : array()) as $call) {
                self::aggregate($aggregates, $call, (string) $profile['page_key']);
            }
        }
        if (!$has_admin) {
            $reasons['no_wp_admin_profiles'] = true;
        }

        $calls = array_values($aggregates);
        foreach ($calls as &$call) {
            $call['calls'] = (int) $call['calls'];
            $call['total_ms'] = round((float) $call['total_ms'], 1);
            $call['worst_ms'] = null === $call['worst_ms'] ? null : round((float) $call['worst_ms'], 1);
            $call['query_keys'] = array_values(array_keys($call['query_keys']));
            sort($call['query_keys'], SORT_STRING);
            $call['page_keys'] = array_values(array_keys($call['page_keys']));
            sort($call['page_keys'], SORT_STRING);
            $call['response_class'] = count($call['response_classes']) === 1
                ? (string) key($call['response_classes'])
                : (count($call['response_classes']) ? 'mixed' : 'unknown');
            unset($call['response_classes']);
            list($purpose, $confidence) = self::purpose($call);
            $call['purpose'] = $purpose;
            $call['purpose_confidence'] = $confidence;
            list($safety, $safety_reasons) = self::block_safety($call);
            $call['block_safety'] = $safety;
            $call['block_safety_reasons'] = $safety_reasons;
        }
        unset($call);
        usort($calls, function ($a, $b) {
            return array($a['endpoint'], $a['method'], $a['component']) <=> array($b['endpoint'], $b['method'], $b['component']);
        });

        $captured = strtotime((string) $run['finished'] . ' UTC');
        return array(
            'schema' => self::SCHEMA,
            'run_id' => (int) $run['id'],
            'captured_at' => $captured ? gmdate('c', $captured) : null,
            'complete' => !$reasons,
            'incomplete_reasons' => array_values(array_keys($reasons)),
            'calls' => $calls,
        );
    }

    private static function aggregate(&$aggregates, $call, $page_key) {
        if (!is_array($call) || empty($call['url'])) {
            return;
        }
        $parts = self::url_parts($call);
        if (!$parts['host']) {
            return;
        }
        $method = strtoupper(isset($call['method']) ? (string) $call['method'] : 'GET');
        $component = isset($call['component']) ? (string) $call['component'] : 'core';
        $component_type = isset($call['ctype']) ? (string) $call['ctype'] : ('core' === $component ? 'core' : 'plugin');
        $key = implode('|', array($parts['scheme'], $parts['endpoint'], $method, $component));
        $ms = isset($call['ms']) && is_numeric($call['ms']) ? (float) $call['ms'] : null;
        $response_class = self::response_class(isset($call['code']) ? $call['code'] : null);
        $query_keys = self::query_keys(isset($call['q']) ? $call['q'] : '');
        $caller = !empty($call['trace']) ? (string) strtok((string) $call['trace'], '<') : (isset($call['caller']) ? (string) $call['caller'] : null);
        $caller = $caller ? trim($caller) : null;

        if (!isset($aggregates[$key])) {
            $aggregates[$key] = array(
                'endpoint' => $parts['endpoint'],
                'scheme' => $parts['scheme'],
                'host' => $parts['host'],
                'path' => $parts['path'],
                'query_keys' => array(),
                'method' => $method,
                'blocking' => !isset($call['blocking']) || (bool) $call['blocking'],
                'sslverify' => array_key_exists('sslverify', $call) ? (bool) $call['sslverify'] : null,
                'response_classes' => array(),
                'calls' => 0,
                'total_ms' => 0.0,
                'worst_ms' => null,
                'component' => $component,
                'component_type' => $component_type,
                'caller' => $caller,
                'page_keys' => array(),
            );
        }
        $row =& $aggregates[$key];
        $row['calls']++;
        if (null !== $ms) {
            $row['total_ms'] += $ms;
            if (null === $row['worst_ms'] || $ms > $row['worst_ms']) {
                $row['worst_ms'] = $ms;
                $row['caller'] = $caller;
            }
        }
        $row['blocking'] = $row['blocking'] || !isset($call['blocking']) || (bool) $call['blocking'];
        if (array_key_exists('sslverify', $call)) {
            $row['sslverify'] = (null === $row['sslverify']) ? (bool) $call['sslverify'] : ($row['sslverify'] && (bool) $call['sslverify']);
        }
        foreach ($query_keys as $query_key) {
            $row['query_keys'][$query_key] = true;
        }
        $row['response_classes'][$response_class] = true;
        $row['page_keys'][$page_key] = true;
        unset($row);
    }

    private static function url_parts($call) {
        $raw = trim((string) $call['url']);
        $scheme = isset($call['scheme']) ? strtolower((string) $call['scheme']) : '';
        if (preg_match('#^https?://#i', $raw)) {
            $parsed = wp_parse_url($raw);
            $scheme = isset($parsed['scheme']) ? strtolower((string) $parsed['scheme']) : $scheme;
        } else {
            $parsed = wp_parse_url(($scheme ? $scheme : 'https') . '://' . ltrim($raw, '/'));
        }
        if (!in_array($scheme, array('http', 'https'), true)) {
            $scheme = null;
        }
        $host = isset($parsed['host']) ? strtolower((string) $parsed['host']) : '';
        $path = isset($parsed['path']) && '' !== $parsed['path'] ? self::normalise_path($parsed['path']) : '/';
        return array('scheme' => $scheme, 'host' => $host, 'path' => $path, 'endpoint' => $host . $path);
    }

    private static function normalise_path($path) {
        $dynamic_parents = array('account', 'accounts', 'customer', 'customers', 'install', 'installs', 'order', 'orders', 'site', 'sites', 'subscription', 'subscriptions', 'user', 'users');
        $parts = explode('/', (string) $path);
        $normal = array();
        foreach ($parts as $index => $part) {
            if (0 === $index || '' === $part) {
                $normal[] = $part;
                continue;
            }
            $decoded = rawurldecode($part);
            $previous = $index > 0 ? strtolower(rawurldecode($parts[$index - 1])) : '';
            if (in_array($previous, $dynamic_parents, true) || preg_match('/^\d+$/', $decoded)) {
                $part = '{id}';
            } elseif (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $decoded)) {
                $part = '{uuid}';
            } elseif (false !== strpos($part, '%') || false !== strpos($decoded, '@')) {
                $part = '{value}';
            } elseif (preg_match('/^(?:pi|ch|cs|seti|tok|src|cus|sub|in|evt)_[A-Za-z0-9_-]{8,}$/', $decoded)
                || (strlen($decoded) >= 16 && preg_match('/[A-Za-z]/', $decoded) && preg_match('/\d/', $decoded))) {
                $part = '{token}';
            }
            $normal[] = $part;
        }
        $path = implode('/', $normal);
        return '' === $path ? '/' : $path;
    }

    private static function query_keys($query) {
        if (!is_string($query) || '' === $query) {
            return array();
        }
        $keys = array();
        foreach (explode('&', $query) as $pair) {
            $key = rawurldecode((string) strtok($pair, '='));
            $key = preg_replace('/[^A-Za-z0-9_.-]/', '', $key);
            if ('' !== $key) {
                $keys[$key] = true;
            }
        }
        return array_keys($keys);
    }

    private static function response_class($code) {
        if (is_numeric($code)) {
            $code = (int) $code;
            return ($code >= 100 && $code <= 599) ? floor($code / 100) . 'xx' : 'unknown';
        }
        return is_string($code) && 0 === strpos($code, 'error:') ? 'error' : 'unknown';
    }

    private static function purpose($call) {
        $host = strtolower((string) $call['host']);
        $haystack = strtolower($host . $call['path'] . ' ' . $call['component'] . ' ' . $call['caller'] . ' ' . implode(' ', $call['query_keys']));
        if (self::payment_host($host) || preg_match('/(?:payment[_-]?intent|process[_-]?payment|refund|fraud|charge|checkout)/', $haystack)) {
            return array('payment', 'high');
        }
        if (false !== strpos($host, 'freemius.com') && false !== strpos($call['path'], '/installs/')) {
            return array('licence', 'high');
        }
        if (preg_match('/(?:licen[cs]e|activation|entitlement|subscription[_-]?status)/', $haystack)) {
            return array('licence', 'medium');
        }
        if ('api.wordpress.org' === $host || 0 === strpos($host, 'update.') || false !== strpos($host, '.update.')
            || preg_match('/(?:update-check|plugin-information|\/updates?\/|\/upgrade|download)/', $haystack)) {
            return array('update', ('api.wordpress.org' === $host || 0 === strpos($host, 'update.') || false !== strpos($host, '.update.')) ? 'high' : 'medium');
        }
        if (preg_match('/(?:telemetry|analytics|tracking|usage|beacon|collector)/', $haystack)) {
            return array('telemetry', 'medium');
        }
        if (preg_match('/(?:marketing|campaign|advert|audience)/', $haystack)) {
            return array('marketing', 'medium');
        }
        if (preg_match('/(?:webhook|shipping|tax|status|health|sync)/', $haystack)) {
            return array('operational', 'medium');
        }
        return array('unknown', 'low');
    }

    private static function block_safety($call) {
        $reasons = array();
        $haystack = strtolower($call['host'] . $call['path'] . ' ' . $call['caller']);
        if ('payment' === $call['purpose']) {
            $reasons[] = 'payment_or_money_movement';
        }
        foreach ($call['page_keys'] as $page_key) {
            if (preg_match('/(?:checkout|cart|order|refund|shipping|payment|webhook|fulfil)/i', $page_key)) {
                $reasons[] = 'order_fulfilment_surface';
                break;
            }
        }
        if (preg_match('/(?:refund|fraud|tax|shipping|webhook|fulfil|payment|charge)/', $haystack)) {
            $reasons[] = 'commerce_or_fulfilment_call';
        }
        if (preg_match('/(?:security|firewall|malware|vulnerability|two.factor|2fa)/', $haystack)) {
            $reasons[] = 'security_call';
        }
        $reasons = array_values(array_unique($reasons));
        return $reasons ? array('never', $reasons) : array('review', array());
    }

    private static function payment_host($host) {
        foreach (array('stripe.com', 'paypal.com', 'paypalobjects.com', 'braintreegateway.com', 'squareup.com', 'adyen.com', 'checkout.com', 'mollie.com', 'klarna.com', 'amazonpay.com', 'authorize.net', 'cybersource.com', 'worldpay.com', 'opayo.co.uk', 'payments.woocommerce.com') as $protected) {
            if ($host === $protected || substr($host, -strlen('.' . $protected)) === '.' . $protected) {
                return true;
            }
        }
        return false;
    }
}
