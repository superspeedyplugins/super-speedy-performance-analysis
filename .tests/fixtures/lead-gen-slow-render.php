<?php
/**
 * Plugin Name: SSPA test fixture - slow, mostly unattributed page
 * Description: Test-only MU plugin for the lead-generation R2 check. The page with the slug
 * lead-gen-slow-render is served by lead-gen-slow-render-template.php, which does about
 * 1,200 ms of real PHP hashing directly in the template, outside any hook callback, so the
 * analysis measures a genuinely slow page whose time is mostly unattributed. Installed only on
 * the retained tests-lead-gen-wpai site; every other page is untouched.
 */
defined('ABSPATH') || exit;
if (!preg_match('/^tests(-|$)/', basename(rtrim(ABSPATH, '/')))) {
    return;
}
add_filter('template_include', static function ($template) {
    return is_page('lead-gen-slow-render') ? __DIR__ . '/lead-gen-slow-render-template.php' : $template;
}, 999);
