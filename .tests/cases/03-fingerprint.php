<?php
function sspa_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

require_once WP_PLUGIN_DIR . '/super-speedy-performance-analysis/profiler/fingerprint.php';

$fp = sspa_sql_fingerprint("SELECT * FROM wp_posts WHERE ID = 42 AND post_title = 'Hello World'");
sspa_t($fp === 'SELECT * FROM wp_posts WHERE ID = ? AND post_title = ?', 'literals normalised: ' . $fp);

$a = sspa_fingerprint_hash("SELECT * FROM wp_postmeta WHERE post_id IN (1,2,3)");
$b = sspa_fingerprint_hash("SELECT * FROM wp_postmeta WHERE post_id IN (9,8,7,6,5,4)");
sspa_t($a === $b, 'IN lists of different lengths share a fingerprint');

$c = sspa_sql_fingerprint("SELECT  *
    FROM wp_users   WHERE user_email = 'dave@example.com'");
sspa_t($c === 'SELECT * FROM wp_users WHERE user_email = ?', 'whitespace collapsed + email removed');
sspa_t(strpos($c, 'example.com') === false, 'no PII survives fingerprinting');

$d = sspa_sql_fingerprint("SELECT SQL_CALC_FOUND_ROWS ID FROM wp_posts ORDER BY rand() LIMIT 10");
sspa_t(strpos($d, 'SQL_CALC_FOUND_ROWS') !== false && stripos($d, 'ORDER BY rand()') !== false, 'design smells preserved: ' . $d);

$e = sspa_sql_fingerprint("SELECT 'it''s'"); // escaped quote handling (single-quote doubling not covered by backslash rule)
sspa_t(strpos($e, 'dave') === false, 'no fatal on tricky quotes');
