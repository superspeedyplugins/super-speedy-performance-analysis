<?php
defined('ABSPATH') || exit;

/**
 * Signed profiling tokens. Format: id.expiry.flags.signature where signature is an HMAC
 * over id, expiry, flags AND the exact request path+query, so a token only works for the
 * one URL it was minted for and only until it expires. The same verification code runs in
 * the db.php shim and mu-loader (inlined there at install time - keep sspa_token_verify()
 * in sync with the copies in dropins/db.php and mu/sspa-loader.php).
 *
 * Single-use enforcement happens in the mu-loader (it has DB access); the shim relies on
 * expiry + path binding alone because it runs before the DB layer exists.
 */
class SSPA_Token {

    const HEADER = 'X-SSPA-Token';
    const LIFETIME = 120; // seconds

    public static function secret() {
        $secret = get_option('sspa_secret');
        if (!$secret) {
            $secret = bin2hex(random_bytes(32));
            add_option('sspa_secret', $secret, '', false);
        }
        return $secret;
    }

    /**
     * @param string $url Full URL the request will be sent to.
     * @param array $flags e.g. ['v' => 'admin', 'oc' => '0', 'bl' => '1', 'ps' => hash]
     */
    public static function mint($url, $flags = array()) {
        $id = bin2hex(random_bytes(16));
        $expiry = time() + self::LIFETIME;
        $flag_str = self::encode_flags($flags);
        $path = self::request_path($url);
        $sig = hash_hmac('sha256', $id . '|' . $expiry . '|' . $flag_str . '|' . $path, self::secret());
        return array(
            'id' => $id,
            'header' => $id . '.' . $expiry . '.' . $flag_str . '.' . $sig,
        );
    }

    public static function encode_flags($flags) {
        $parts = array();
        foreach ($flags as $k => $v) {
            $parts[] = $k . ':' . $v;
        }
        return $parts ? implode(';', $parts) : '-';
    }

    public static function request_path($url) {
        $parts = wp_parse_url($url);
        $path = isset($parts['path']) ? $parts['path'] : '/';
        if (isset($parts['query']) && $parts['query'] !== '') {
            $path .= '?' . $parts['query'];
        }
        return $path;
    }

    /**
     * Plugin-side verification (tests + any future REST use). The shim/mu-loader carry
     * their own inlined copy of this logic.
     */
    public static function verify($header_value, $request_path) {
        return sspa_token_verify($header_value, $request_path, self::secret());
    }
}

if (!function_exists('sspa_token_verify')) {
    // Canonical implementation - the installer embeds this same function into the
    // mu-loader and db.php shim templates. Returns false or ['id' =>, 'flags' => array].
    function sspa_token_verify($header_value, $request_path, $secret) {
        $parts = explode('.', (string) $header_value);
        if (count($parts) !== 4) {
            return false;
        }
        list($id, $expiry, $flag_str, $sig) = $parts;
        if (!preg_match('/^[a-f0-9]{32}$/', $id) || (int) $expiry < time()) {
            return false;
        }
        $expected = hash_hmac('sha256', $id . '|' . $expiry . '|' . $flag_str . '|' . $request_path, $secret);
        if (!hash_equals($expected, $sig)) {
            return false;
        }
        $flags = array();
        if ($flag_str !== '-') {
            foreach (explode(';', $flag_str) as $pair) {
                $kv = explode(':', $pair, 2);
                if (count($kv) === 2) {
                    $flags[$kv[0]] = $kv[1];
                }
            }
        }
        return array('id' => $id, 'flags' => $flags);
    }
}
