<?php
// Served by lead-gen-slow-render.php for one fixture page. Real CPU work directly in the
// template, not in a callback, for about 1,200 ms.
defined('ABSPATH') || exit;
$until = hrtime(true) + 1200000000;
$digest = '';
while (hrtime(true) < $until) {
    $digest = hash('sha256', $digest . 'lead-gen-slow-render');
}
?><!doctype html>
<html><head><meta charset="utf-8"><title>Slow render fixture</title><?php wp_head(); ?></head>
<body><p>Slow render fixture: <?php echo esc_html(substr($digest, 0, 12)); ?></p><?php wp_footer(); ?></body></html>
