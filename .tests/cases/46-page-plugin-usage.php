<?php
// The page-plugin-usage contract (SSPA_Report::page_plugin_usage) and the pieces under
// it: the unload-safety classifier, the normalised body hash, and the output-identity
// verdict. Pure-logic assertions run everywhere; the contract-shape assertions run
// against whatever completed run the site has and SKIP (not fail) without one.

function sspa_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

// --- Unload-safety classifier ---------------------------------------------------

$v = SSPA_Unload_Safety::classify('woocommerce');
sspa_t('never' === $v['classification'], 'woocommerce classifies never (commerce-core)');

$v = SSPA_Unload_Safety::classify('wordfence');
sspa_t('never' === $v['classification'], 'wordfence classifies never (security)');

$v = SSPA_Unload_Safety::classify('complianz-gdpr');
sspa_t('never' === $v['classification'], 'consent banner classifies never');

$v = SSPA_Unload_Safety::classify('paid-memberships-pro');
sspa_t('never' === $v['classification'], 'membership classifies never');

$v = SSPA_Unload_Safety::classify('super-speedy-performance-analysis');
sspa_t('never' === $v['classification'], 'we classify ourselves never');

$v = SSPA_Unload_Safety::classify('contact-form-7');
sspa_t('candidate' === $v['classification'], 'contact form classifies candidate');

$v = SSPA_Unload_Safety::classify('a-plugin-nobody-has-heard-of');
sspa_t('review' === $v['classification'], 'unknown plugin fails safe to review');

// --- Normalised body hash -------------------------------------------------------

$a = SSPA_Crawler::body_hash('<input type="hidden" name="_wpnonce" value="abc123def456"><p>same</p>');
$b = SSPA_Crawler::body_hash('<input type="hidden" name="_wpnonce" value="0f0f0f0f0f0f"><p>same</p>');
sspa_t(null !== $a && $a === $b, 'nonce-only differences hash identical');

$c = SSPA_Crawler::body_hash('<p>actually different content</p>');
sspa_t($a !== $c, 'real content differences hash differently');

$d1 = SSPA_Crawler::body_hash('<a href="/x?_wpnonce=aabbccddee11&y=2">l</a>');
$d2 = SSPA_Crawler::body_hash('<a href="/x?_wpnonce=ff00ff00ff00&y=2">l</a>');
sspa_t($d1 === $d2, 'URL nonces stripped before hashing');

sspa_t(null === SSPA_Crawler::body_hash(''), 'empty body hashes to null, not a value');

// --- Output-identity verdict ----------------------------------------------------

$m = new ReflectionMethod('SSPA_Run_Controller', 'output_identical');
$m->setAccessible(true);
sspa_t(1 === $m->invoke(null, array('h1', 'h1'), array('h1', 'h1')), 'stable baseline + matching cell => 1');
sspa_t(0 === $m->invoke(null, array('h1', 'h1'), array('h1', 'h2')), 'stable baseline + differing cell => 0');
sspa_t(null === $m->invoke(null, array('h1', 'h2'), array('h1')), 'unstable baseline => null (unknowable)');
sspa_t(null === $m->invoke(null, array(), array('h1')), 'missing baseline hashes => null');
sspa_t(null === $m->invoke(null, array('h1'), array()), 'missing cell hashes => null');

// --- Contract shape (needs a completed run) -------------------------------------

$usage = SSPA_Report::page_plugin_usage();
if (is_wp_error($usage)) {
    echo "SKIP: no completed run on this site - contract shape not exercised ({$usage->get_error_code()})\n";
} else {
    sspa_t(1 === $usage['schema'], 'contract schema is 1');
    sspa_t(is_bool($usage['complete']) && is_array($usage['incomplete_reasons']), 'complete flag + reasons present');
    sspa_t(is_array($usage['pages']), 'pages array present');
    $checked_plugin = false;
    foreach ($usage['pages'] as $page) {
        sspa_t(array_key_exists('output_stable', $page), 'page carries output_stable: ' . $page['page_key']);
        break;
    }
    foreach ($usage['pages'] as $page) {
        foreach ($page['plugins'] as $p) {
            $checked_plugin = true;
            sspa_t(in_array($p['classification'], array('never', 'review', 'candidate'), true), 'classification uses the ladder: ' . $p['plugin']);
            sspa_t(array_key_exists('include_ms', $p['evidence']) && array_key_exists('assets_count', $p['evidence']), 'evidence keys present: ' . $p['plugin']);
            break 2;
        }
    }
    if (!$checked_plugin) {
        echo "SKIP: run has no full-set profiles to carry plugin entries\n";
    }
    // Our own row must never be offered.
    foreach ($usage['pages'] as $page) {
        foreach ($page['plugins'] as $p) {
            if ('super-speedy-performance-analysis' === $p['plugin']) {
                sspa_t(false, 'the contract must not list this plugin itself');
            }
        }
    }
    sspa_t(true, 'this plugin excluded from every page entry');
}
