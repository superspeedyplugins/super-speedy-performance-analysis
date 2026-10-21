<?php
// The Imports tab in each state, on its own retained site.
//   none     no importer active: the tab still shows the published figures and the Lite button
//   traffic  a real WooCommerce product CSV import and a slow admin-ajax endpoint were measured
//            by a Traffic collection: I3 and S8
//   lite     Super Speedy Imports Lite from wordpress.org active: only the Pro link
//   pro      Super Speedy Imports Pro active: no offer at all
// Usage: wp eval-file assert-imports.php <state>
require __DIR__ . '/lib.php';
lg_reset_dismissals();
$state = isset($args[0]) ? $args[0] : 'none';
$tab = lg_tab('imports');
$text = preg_replace('/\s+/', ' ', wp_strip_all_tags($tab));
$install = 'plugin-install.php?tab=plugin-information&amp;plugin=super-speedy-imports-lite';
lg_t('' !== $tab, $state . ': the Imports tab renders');
lg_t(false !== strpos($tab, 'benchmarks/super-speedy-imports/results/lite-2-99-21-import-speed/') && false !== strpos($text, '89.8 seconds'), $state . ': the published Lite figure is shown verbatim with its source');
lg_t(!preg_match('/would (save|take)|you could save|minutes faster/i', $text), $state . ': no per-site projection');
$bad = lg_identifying_links($tab);
lg_t(!$bad, $state . ': no link carries identifying parameters' . ($bad ? ': ' . implode(' ', $bad) : ''));

if ('none' === $state) {
    lg_t(false !== strpos($text, 'No importer plugin is active'), 'none: the tab says no importer was detected');
    lg_t(false !== strpos($tab, $install) && false !== strpos($text, 'Install Super Speedy Imports Lite (free)'), 'none: the Install Super Speedy Imports Lite (free) button opens WordPress\'s installer');
    lg_t('imports_imports_tab' === lg_campaign($tab, 'imports') && false !== strpos($text, 'Super Speedy Imports Pro'), 'none: the Super Speedy Imports Pro text link sits under the button');
    lg_t(!in_array('imports', lg_offer_ids(lg_tab('overview')), true), 'none: no importer, so no Imports offer on the Overview');
}
if ('traffic' === $state) {
    $evidence = SSPA_Report::endpoint_evidence();
    $wc = null;
    foreach ((array) ($evidence['endpoints'] ?? array()) as $endpoint) {
        if ('woocommerce_do_ajax_product_import' === ($endpoint['identity']['action'] ?? '')) {
            $wc = $endpoint;
        }
    }
    lg_t($wc && $wc['observations']['count'] > 0, 'traffic: the collection recorded the real WooCommerce product importer requests (' . ($wc ? $wc['observations']['count'] : 0) . ')');
    if ($wc) {
        lg_t(false !== strpos($text, 'WooCommerce product importer') && false !== strpos($text, number_format_i18n($wc['observations']['count']) . ' import request'), 'traffic: the tab reports the measured importer requests');
    }
    lg_t(false !== strpos($tab, 'benchmarks/super-speedy-imports-vs-woocommerce-built-in-importer/'), 'traffic: the WooCommerce importer comparison is quoted with its source link');
    $overview = lg_tab('overview');
    $offers = lg_offers($overview);
    $by = array();
    foreach ($offers as $offer) {
        $by[$offer['offer']] = $offer;
    }
    lg_t(isset($by['scalability_pro']) && 'S8' === $by['scalability_pro']['trigger'], 'traffic: the slow admin-ajax endpoint offers Scalability Pro (S8); got ' . wp_json_encode(array_map(function ($o) { return $o['offer'] . ':' . $o['trigger']; }, $offers)));
    lg_t(isset($by['scalability_pro']) && false !== stripos($by['scalability_pro']['html'], 'beta'), 'traffic: Fast Ajax is named as a beta');
    lg_t(isset($by['scalability_pro']) && false !== strpos($by['scalability_pro']['html'], 'lead_gen_fixture_slow_endpoint'), 'traffic: the S8 offer names the measured endpoint');
    lg_t(isset($by['imports']) && 'I3' === $by['imports']['trigger'], 'traffic: the measured WooCommerce import offers Super Speedy Imports (I3)');
    lg_t(count($offers) <= 2, 'traffic: at most two offers on the Overview');
}
if ('lite' === $state) {
    lg_t(false === strpos($tab, $install) && false === strpos($text, 'Install Super Speedy Imports Lite'), 'lite: with Lite active the install button gives way');
    lg_t('imports_imports_tab' === lg_campaign($tab, 'imports') && false !== strpos($text, 'Super Speedy Imports Pro'), 'lite: only the Super Speedy Imports Pro link remains');
    lg_t(false !== strpos($text, 'Super Speedy Imports Lite is active'), 'lite: the tab says Lite is active');
}
if ('pro' === $state) {
    lg_t(!lg_offers($tab) && false === strpos($tab, $install) && false === strpos($tab, 'utm_campaign=imports'), 'pro: with Pro active the tab shows no offer at all');
    lg_t(false !== strpos($text, 'Super Speedy Imports Pro is active'), 'pro: the tab says Pro is active');
    lg_t(!in_array('imports', lg_offer_ids(lg_tab('overview')), true), 'pro: no Imports offer on the Overview');
}
