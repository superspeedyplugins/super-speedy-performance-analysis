<?php
defined('ABSPATH') || exit;

/**
 * RSA keypair for signing the rules feed. Generated once on activation; the public key
 * is what analysis-plugin installs use to verify the feed.
 */
class SSPH_Keys {

    public static function ensure_keypair() {
        if (get_option('ssph_privkey') && get_option('ssph_pubkey')) {
            return true;
        }
        if (!function_exists('openssl_pkey_new')) {
            return false;
        }
        $res = openssl_pkey_new(array(
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ));
        if (!$res) {
            return false;
        }
        openssl_pkey_export($res, $privkey);
        $details = openssl_pkey_get_details($res);
        add_option('ssph_privkey', $privkey, '', false);
        add_option('ssph_pubkey', $details['key'], '', false);
        return true;
    }

    public static function sign($data) {
        $privkey = get_option('ssph_privkey');
        if (!$privkey || !openssl_sign($data, $signature, $privkey, OPENSSL_ALGO_SHA256)) {
            return null;
        }
        return base64_encode($signature);
    }
}
