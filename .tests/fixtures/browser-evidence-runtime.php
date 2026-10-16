<?php
// Synthetic response only on the guarded native regression sites, never a customer build.
if (!defined('ABSPATH') || !preg_match('/^tests(-|$)/', basename(rtrim(ABSPATH, '/')))) return;
if (empty($_GET['sspa_w3_browser_evidence'])) return;
add_action('template_redirect', static function() {
    header('Content-Type: text/html; charset=UTF-8');
    header('Cache-Control: private, no-store');
    header('X-Frame-Options: SAMEORIGIN');
    $nonce = wp_create_nonce('sspa-browser-evidence');
    $csp = base64_encode(random_bytes(12));
    header("Content-Security-Policy: script-src 'nonce-" . $csp . "'");
    echo '<!doctype html><html><head><script id="sspa-evidence-data" nonce="' . esc_attr($csp) . '">';
    echo 'window.sspaEvidence=' . wp_json_encode(array('nonce'=>$nonce)) . ';';
    echo 'document.cookie.indexOf("wordpress_logged_in");localStorage.getItem("synthetic");sessionStorage.getItem("synthetic");';
    echo '</script></head><body><p>Wordfence is ordinary page text here. Unicode: café Ελληνικά.</p>';
    echo '<form class="wishlist" action="' . esc_url(wp_nonce_url(home_url('/'), 'sspa-browser-evidence')) . '">';
    wp_nonce_field('sspa-browser-evidence', 'sspa_fixture_nonce');
    echo '</form><div data-ssap-visitor="synthetic-cart" data-ssap-auth="in" data-ssap-frag="cart" data-refresh-nonce="synthetic">Synthetic region</div>';
    echo '<a class="customer-download" href="' . esc_url(wp_nonce_url(home_url('/'), 'sspa-browser-evidence', 'download_nonce')) . '">Synthetic download</a></body></html>';
    exit;
}, -1000);
