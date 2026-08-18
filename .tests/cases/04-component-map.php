<?php
function sspa_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

require_once WP_PLUGIN_DIR . '/super-speedy-performance-analysis/profiler/class-sspa-component-map.php';
$map = new SSPA_Component_Map();

$c = $map->classify_file(WP_PLUGIN_DIR . '/woocommerce/includes/class-wc-query.php');
sspa_t($c['component'] === 'woocommerce' && $c['type'] === 'plugin', 'plugin file classified');

$c = $map->classify_file(ABSPATH . 'wp-includes/class-wp-query.php');
sspa_t($c['type'] === 'core', 'core file classified');

$c = $map->classify_file(WP_CONTENT_DIR . '/themes/twentytwentyfive/functions.php');
sspa_t($c['component'] === 'twentytwentyfive' && $c['type'] === 'theme', 'theme file classified');

$c = $map->classify_file(WPMU_PLUGIN_DIR . '/sspa-loader.php');
sspa_t($c['type'] === 'mu-plugin', 'mu-plugin classified');

// Attribution: innermost core frames skipped, first plugin frame wins.
$frames = array(
    array(ABSPATH . 'wp-includes/class-wp-query.php', 100, 'WP_Query::get_posts'),
    array(ABSPATH . 'wp-includes/post.php', 200, 'get_posts'),
    array(WP_PLUGIN_DIR . '/woocommerce/includes/wc-core-functions.php', 50, 'wc_get_products'),
    array(WP_CONTENT_DIR . '/themes/storefront/index.php', 10, 'require'),
);
$attr = $map->attribute($frames);
sspa_t($attr['component'] === 'woocommerce', 'code-owner mode (default): the executing plugin wins attribution');

// Our own frames never claim attribution.
//
// The path MUST come from SSPA_PLUGIN_DIR, not from WP_PLUGIN_DIR . '/<slug>'. Self-exclusion
// keys on `dirname(__DIR__)`, which PHP has already resolved through any symlink, and every
// path in a real `debug_backtrace()` is resolved the same way - so a resolved path is the only
// thing this code ever sees at runtime.
//
// This used to build the path from WP_PLUGIN_DIR and passed only because the old Docker
// harness COPIED the plugin into the container, making the two identical. Every parallel-dev
// site symlinks wp-content/plugins/<slug> to the repository, so the constructed path became
// /opt/homebrew/.../wp-content/plugins/... while own_dir is /Users/dave/dev/... - the frame
// was attributed to this plugin instead of being skipped. The code was right; the fixture
// was describing an installation layout that no longer exists.
sspa_t(strpos(SSPA_PLUGIN_DIR, WP_PLUGIN_DIR) !== 0 || realpath(WP_PLUGIN_DIR . '/super-speedy-performance-analysis') === rtrim(SSPA_PLUGIN_DIR, '/'),
    'fixture precondition: SSPA_PLUGIN_DIR is the resolved plugin path');
$frames = array(
    array(SSPA_PLUGIN_DIR . 'profiler/class-sspa-capture.php', 10, 'finalize'),
    array(ABSPATH . 'wp-includes/option.php', 20, 'get_option'),
);
$attr = $map->attribute($frames);
sspa_t($attr['component'] === 'core', 'profiler frames excluded from attribution');

// The complement, so the assertion above cannot pass by excluding everything: a frame from a
// DIFFERENT plugin in the same directory must still win attribution.
$frames = array(
    array(WP_PLUGIN_DIR . '/woocommerce/includes/wc-core-functions.php', 10, 'wc_get_products'),
    array(ABSPATH . 'wp-includes/option.php', 20, 'get_option'),
);
$attr = $map->attribute($frames);
sspa_t($attr['component'] === 'woocommerce', 'a non-profiler plugin frame is still attributed');

// Degraded mode: resolve callable names via reflection.
$attr = $map->attribute_from_summary("require('wp-load.php'), wp_not_installed, get_option");
sspa_t($attr['type'] === 'core', 'summary attribution resolves core callables: ' . $attr['component']);

// --- Shared vendored libraries must not blame whoever owns the files on disk ---

// plugin-b called into a Guzzle copy that happens to live inside plugin-a. PHP loads one
// copy, so the owning plugin is an accident of autoloader order. Blame the caller.
$frames = array(
    array(WP_PLUGIN_DIR . '/plugin-a/vendor/guzzlehttp/guzzle/src/Client.php', 100, 'Client->request'),
    array(WP_PLUGIN_DIR . '/plugin-b/src/Api.php', 20, 'Api->fetch'),
    array(ABSPATH . 'wp-includes/class-wp-hook.php', 300, 'do_action'),
);
$attr = $map->attribute($frames);
sspa_t($attr['component'] === 'plugin-b', 'shared vendor library blames the caller, not the owner: ' . $attr['component']);
sspa_t($attr['vendored'] === true, 'shared vendor call flagged vendored');
sspa_t(strpos((string) $attr['via'], 'guzzlehttp/guzzle') !== false && strpos((string) $attr['via'], 'plugin-a') !== false,
    'via names the library and the plugin it lives in: ' . $attr['via']);

// Several frames deep inside the same vendored tree before reaching the real caller.
$frames = array(
    array(WP_PLUGIN_DIR . '/plugin-a/vendor/guzzlehttp/guzzle/src/Handler/CurlHandler.php', 10, 'CurlHandler->__invoke'),
    array(WP_PLUGIN_DIR . '/plugin-a/vendor/guzzlehttp/guzzle/src/Client.php', 100, 'Client->request'),
    array(WP_PLUGIN_DIR . '/plugin-b/src/Api.php', 20, 'Api->fetch'),
);
$attr = $map->attribute($frames);
sspa_t($attr['component'] === 'plugin-b', 'deep vendor chain still reaches the real caller: ' . $attr['component']);

// A plugin using its OWN bundled library is genuinely its own cost - no redirect.
$frames = array(
    array(WP_PLUGIN_DIR . '/plugin-a/vendor/guzzlehttp/guzzle/src/Client.php', 100, 'Client->request'),
    array(WP_PLUGIN_DIR . '/plugin-a/src/Sync.php', 30, 'Sync->run'),
);
$attr = $map->attribute($frames);
sspa_t($attr['component'] === 'plugin-a', 'private vendor tree stays with its own plugin: ' . $attr['component']);
sspa_t($attr['vendored'] === true, 'private vendor tree is still vendored code');

// Action Scheduler bundled in WooCommerce, scheduled by another plugin.
$frames = array(
    array(WP_PLUGIN_DIR . '/woocommerce/packages/action-scheduler/classes/ActionScheduler_Store.php', 44, 'save_action'),
    array(WP_PLUGIN_DIR . '/wp-all-import/src/Importer.php', 88, 'Importer->queue'),
);
$attr = $map->attribute($frames);
sspa_t($attr['component'] === 'wp-all-import', 'bundled action-scheduler blames the scheduling plugin: ' . $attr['component']);

// --- Two attribution modes over the same captured chain ---

$caller_map = new SSPA_Component_Map(SSPA_Component_Map::MODE_CALLER);

// A plugin calling wc_get_product() in a loop is the PLUGIN's fault, not WooCommerce's.
// Caller mode says so; code-owner mode names the code that actually ran.
$loop_frames = array(
    array(WP_PLUGIN_DIR . '/woocommerce/includes/wc-product-functions.php', 60, 'wc_get_product'),
    array(WP_PLUGIN_DIR . '/plugin-b/src/Widget.php', 12, 'Widget->render'),
);
$attr = $caller_map->attribute($loop_frames);
sspa_t($attr['component'] === 'plugin-b', 'caller mode blames the plugin looping over a WC API: ' . $attr['component']);
sspa_t($attr['via'] === 'woocommerce', 'caller mode records where the work ran: ' . $attr['via']);

$attr = $map->attribute($loop_frames);
sspa_t($attr['component'] === 'woocommerce', 'code-owner mode names the API owner: ' . $attr['component']);
sspa_t($attr['via'] === null, 'code-owner mode needs no via when it is the executor');

// The theme sharp edge: code-owner mode must NOT charge the theme for WooCommerce rendering
// its own shop page, which is what a global caller-mode default would do.
$shop = array(
    array(WP_PLUGIN_DIR . '/woocommerce/includes/wc-template-functions.php', 80, 'woocommerce_content'),
    array(WP_CONTENT_DIR . '/themes/storefront/woocommerce.php', 4, 'require'),
);
sspa_t($map->attribute($shop)['component'] === 'woocommerce', 'code-owner mode leaves shop rendering with woocommerce');
sspa_t($caller_map->attribute($shop)['component'] === 'storefront', 'caller mode does charge the theme - why it is not the default');

// A vendored library is nobody's own code, so BOTH modes blame the caller for it.
$vendor = array(
    array(WP_PLUGIN_DIR . '/plugin-a/vendor/guzzlehttp/guzzle/src/Client.php', 100, 'Client->request'),
    array(WP_PLUGIN_DIR . '/plugin-b/src/Api.php', 20, 'Api->fetch'),
);
sspa_t($map->attribute($vendor)['component'] === 'plugin-b'
    && $caller_map->attribute($vendor)['component'] === 'plugin-b', 'both modes blame the caller for vendored code');

// Both modes agree when only one component is involved.
$own = array(
    array(WP_PLUGIN_DIR . '/woocommerce/includes/class-wc-query.php', 5, 'q'),
    array(WP_PLUGIN_DIR . '/woocommerce/templates/archive-product.php', 9, 'tpl'),
);
sspa_t($map->attribute($own)['component'] === 'woocommerce'
    && $caller_map->attribute($own)['component'] === 'woocommerce', 'both modes agree on single-component work');

// The chain is captured once and re-resolvable into either mode without re-profiling.
$attr = $map->attribute($loop_frames);
sspa_t($attr['chain'] === array('plugin:woocommerce', 'plugin:plugin-b'),
    'chain captured innermost-first, typed for re-resolution: ' . implode(' <- ', $attr['chain']));
sspa_t(SSPA_Component_Map::resolve(
    array(array('component' => 'woocommerce', 'type' => 'plugin', 'label' => '', 'library' => null),
          array('component' => 'plugin-b', 'type' => 'plugin', 'label' => '', 'library' => null)),
    SSPA_Component_Map::MODE_CALLER
)['component'] === 'plugin-b', 'a stored chain re-resolves into the other mode');

// Unchanged shape: ordinary plugin frames carry the new keys with null/false.
$frames = array(array(WP_PLUGIN_DIR . '/woocommerce/includes/class-wc-query.php', 5, 'q'));
$attr = $map->attribute($frames);
sspa_t(array_key_exists('via', $attr) && $attr['via'] === null && $attr['vendored'] === false,
    'ordinary attribution carries via=null, vendored=false');
