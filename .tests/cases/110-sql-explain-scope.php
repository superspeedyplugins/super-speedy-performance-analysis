<?php
// Exercise real EXPLAIN and the metadata collector, observing actual issued statements.
require __DIR__ . '/../lib/native-guard.php';
global $wpdb;
wp_set_current_user(1);
$foreign = 'w2_foreign_index';
if (false === $wpdb->query($wpdb->prepare('CREATE TABLE IF NOT EXISTS %i LIKE %i', $foreign, $wpdb->posts))) {
    throw new RuntimeException($wpdb->last_error);
}
$failures = 0;
$check = static function ($ok, $label) use (&$failures) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) { $failures++; }
};
$plan = SSPA_Explain::explain($wpdb->prepare('SELECT ID FROM %i', $wpdb->posts));
$check(!empty($plan['relevant_indexes'][$wpdb->posts]), 'current prefixed table keeps its real indexes');
$plan = SSPA_Explain::explain($wpdb->prepare('SELECT ID FROM %i', $foreign));
$check(is_array($plan) && empty($plan['relevant_indexes']), 'unprefixed table EXPLAIN retains its plan but does not read indexes');
$method = new ReflectionMethod('SSPA_Explain', 'relevant_indexes');
$queries = array();
$observe = static function ($sql) use (&$queries) { if (stripos($sql, 'SHOW INDEX') === 0) { $queries[] = $sql; } return $sql; };
add_filter('query', $observe);
$method->invoke(null, 'SELECT * FROM `another_database`.`' . $wpdb->posts . '`');
remove_filter('query', $observe);
$check(!$queries, 'foreign database identifier issues no index query');
$indexes = $method->invoke(null, 'SELECT * FROM `' . DB_NAME . '`.`' . $wpdb->posts . '`');
$check(!empty($indexes[$wpdb->posts]), 'explicit current database retains prefixed indexes');
if ($failures) { WP_CLI::halt(1); }
