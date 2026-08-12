<?php
// Site cohort dimensions: what kind of site this is and roughly how big, in terms
// superspeedy.org can group by without ever learning which site it was.
//
// The classifier is driven with post-type count maps in exactly the shape
// SSPA_Demographics::snapshot() produces (post type => published count) - it is a pure
// function of those counts and the active plugin list, so that IS its real input. Everything
// downstream of it - the metrics row, the payload, the privacy scan - runs against a real
// snapshot of this site and a real exported payload, never a hand-built one.

function sspa_sc_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

// --- Banding: every boundary on the ladder, and the null that must stay null ---

$sspa_bands = array(
    array(0, '<10'), array(9, '<10'), array(10, '<100'), array(99, '<100'),
    array(100, '<1k'), array(999, '<1k'), array(1000, '<10k'), array(9999, '<10k'),
    array(10000, '<100k'), array(99999, '<100k'), array(100000, '<1m'), array(999999, '<1m'),
    array(1000000, '<10m'), array(9999999, '<10m'), array(10000000, '<100m'),
    array(99999999, '<100m'), array(100000000, '<1b'), array(999999999, '<1b'),
    array(1000000000, '1b+'), array(50000000000, '1b+'),
);
$sspa_band_fails = array();
foreach ($sspa_bands as $sspa_case) {
    $sspa_got = SSPA_Site_Characteristics::band($sspa_case[0]);
    if ($sspa_got !== $sspa_case[1]) {
        $sspa_band_fails[] = $sspa_case[0] . ' gave ' . var_export($sspa_got, true) . ' not ' . $sspa_case[1];
    }
}
sspa_sc_t(!$sspa_band_fails, 'every size band boundary holds' . ($sspa_band_fails ? ': ' . implode('; ', $sspa_band_fails) : ' (' . count($sspa_bands) . ' checked)'));
// An unknown size banded to '<10' would read as "a tiny site", which is a different claim
// from "we could not measure this cheaply".
sspa_sc_t(null === SSPA_Site_Characteristics::band(null), 'an unknown size stays null rather than banding to <10');

// --- Classification: one deterministic primary, honest secondaries ---

$sspa_shop = SSPA_Site_Characteristics::classify(
    array('post' => 120, 'page' => 12, 'product' => 5000),
    array('woocommerce', 'akismet')
);
sspa_sc_t('ecommerce' === $sspa_shop['primary_purpose'], 'a shop with a blog is primarily ecommerce (' . $sspa_shop['primary_purpose'] . ')');
sspa_sc_t(
    in_array('publishing', $sspa_shop['secondary_purposes'], true),
    'and publishing survives as a secondary purpose instead of being discarded'
);
sspa_sc_t('high' === $sspa_shop['confidence'], 'content plus stack gives high confidence (' . $sspa_shop['confidence'] . ')');
sspa_sc_t(
    in_array('product-content', $sspa_shop['signals'], true) && in_array('woocommerce-stack', $sspa_shop['signals'], true),
    'the coarse signals travel with the label so the receiver can reclassify later'
);
sspa_sc_t(
    $sspa_shop['taxonomy_version'] === SSPA_Rules::purpose_taxonomy()['version'] && $sspa_shop['taxonomy_version'] > 0,
    'the classification is stamped with the taxonomy version that produced it'
);

// Same inputs, different order: the answer may not move.
$sspa_shuffled = SSPA_Site_Characteristics::classify(
    array('product' => 5000, 'page' => 12, 'post' => 120),
    array('akismet', 'woocommerce')
);
sspa_sc_t($sspa_shuffled === $sspa_shop, 'classification is deterministic - input order changes nothing');

$sspa_jobs = SSPA_Site_Characteristics::classify(
    array('post' => 4, 'page' => 20, 'job_listing' => 900),
    array('wp-job-manager')
);
sspa_sc_t('jobs' === $sspa_jobs['primary_purpose'], 'a jobs board classifies as jobs (' . $sspa_jobs['primary_purpose'] . ')');

$sspa_blog = SSPA_Site_Characteristics::classify(array('post' => 400, 'page' => 8), array('akismet'));
sspa_sc_t('publishing' === $sspa_blog['primary_purpose'], 'a blog classifies as publishing (' . $sspa_blog['primary_purpose'] . ')');
sspa_sc_t('medium' === $sspa_blog['confidence'], 'with medium confidence - content but no stack to corroborate it (' . $sspa_blog['confidence'] . ')');

$sspa_brochure = SSPA_Site_Characteristics::classify(array('page' => 6), array('akismet'));
sspa_sc_t(
    'general' === $sspa_brochure['primary_purpose'] && 'low' === $sspa_brochure['confidence'] && array() === $sspa_brochure['signals'],
    'a six-page brochure site is general with low confidence and no invented signals'
);

// A bespoke CPT is the normal case on a client site, and its slug is often the client's name.
$sspa_bespoke = SSPA_Site_Characteristics::classify(
    array('post' => 3, 'acme_client_widget' => 4000),
    array()
);
sspa_sc_t('other' === SSPA_Site_Characteristics::content_class('acme_client_widget'), 'an unmapped post type maps to the class "other"');
sspa_sc_t(
    !in_array('acme_client_widget-content', $sspa_bespoke['signals'], true),
    'and never becomes a signal naming itself'
);
$sspa_primary = SSPA_Site_Characteristics::primary_content(array('post' => 3, 'acme_client_widget' => 4000));
sspa_sc_t(
    'other' === $sspa_primary['class'] && 4000 === $sspa_primary['count'],
    'primary content reports the size of the bespoke type under a canonical class'
);

// --- The real snapshot of this site, and both order-storage paths ---

$sspa_cpt_slug = 'sspa_acme_widget';
register_post_type($sspa_cpt_slug, array('public' => true, 'label' => 'SSPA Acme Widget'));

// Anything left by an aborted earlier run would be counted too, and this asserts an exact
// number.
foreach (get_posts(array('post_type' => $sspa_cpt_slug, 'numberposts' => -1, 'post_status' => 'any', 'fields' => 'ids')) as $sspa_stale) {
    wp_delete_post($sspa_stale, true);
}

// Enough of them to be this site's primary content, ahead of the sample store's products and
// posts - that is the case where a bespoke slug would escape if anything could let it.
$sspa_cpt_count = 200;
$sspa_cpt_posts = array();
for ($sspa_i = 0; $sspa_i < $sspa_cpt_count; $sspa_i++) {
    $sspa_cpt_posts[] = wp_insert_post(array(
        'post_type' => $sspa_cpt_slug,
        'post_title' => 'widget ' . $sspa_i,
        'post_status' => 'publish',
    ));
}

$sspa_snapshot = SSPA_Demographics::snapshot();
$sspa_metrics = $sspa_snapshot['metrics'];
$sspa_metrics_ids = array((int) $sspa_snapshot['id']);

sspa_sc_t(
    isset($sspa_metrics['post_counts'][$sspa_cpt_slug]) && $sspa_cpt_count === (int) $sspa_metrics['post_counts'][$sspa_cpt_slug],
    'the local metrics row counts the bespoke post type exactly, ' . $sspa_cpt_count . ' (private to this site, got '
        . var_export(isset($sspa_metrics['post_counts'][$sspa_cpt_slug]) ? $sspa_metrics['post_counts'][$sspa_cpt_slug] : null, true) . ')'
);
sspa_sc_t(
    in_array($sspa_metrics['orders_total_basis'], array('table_estimate', 'maintained_count', 'skipped_large_table'), true),
    'the order total discloses how it was obtained (' . var_export($sspa_metrics['orders_total_basis'], true) . ')'
);
// The 30-day figure is a count of the whole window, not the number of rows fetched. The
// collector asks for a single row and reads the total, so a store with more orders than the
// page size still reports its real number - which is what a capped id fetch got wrong, and
// what this asserts as far as a fixture store can: the figure must equal WooCommerce's own
// unlimited list, and must not collapse to the page size. Reproducing the old cap itself would
// need 2,000 orders on the fixture, so this guards the mechanism rather than that volume.
$sspa_wc_orders = wc_get_orders(array(
    'date_created' => '>' . gmdate('Y-m-d', time() - 30 * DAY_IN_SECONDS),
    'return' => 'ids',
    'limit' => -1,
));
sspa_sc_t(
    'query_count' === $sspa_metrics['orders_30d_basis']
    && count($sspa_wc_orders) === (int) $sspa_metrics['orders_30d'],
    'the 30-day order count matches WooCommerce\'s own order list exactly ('
        . var_export($sspa_metrics['orders_30d'], true) . ' vs ' . count($sspa_wc_orders) . ')'
);
sspa_sc_t(
    count($sspa_wc_orders) > 1 && 1 !== (int) $sspa_metrics['orders_30d'],
    'and is the window total, not the one row the query asked for'
);
sspa_sc_t(
    is_bool($sspa_metrics['hpos']) && in_array($sspa_metrics['checkout_type'], array('block', 'classic', 'unknown'), true),
    'WooCommerce facts are recorded (hpos ' . var_export($sspa_metrics['hpos'], true) . ', checkout ' . $sspa_metrics['checkout_type'] . ')'
);

// Both storage paths, on this one site. WooCommerce refuses to switch the authoritative order
// table while any order is out of sync, so the mode is passed in rather than flipped - the
// counting code, and the information_schema row counts it works from, are the real ones.
$sspa_tables = SSPA_Demographics::table_rows();
$sspa_hpos_count = SSPA_Demographics::orders_total(true, $sspa_tables['rows']);
$sspa_legacy_count = SSPA_Demographics::orders_total(false, $sspa_tables['rows']);
sspa_sc_t(
    'table_estimate' === $sspa_hpos_count['basis'] && is_int($sspa_hpos_count['count']),
    'the HPOS route uses the order table row estimate, never COUNT(*) (' . var_export($sspa_hpos_count['basis'], true) . ')'
);
sspa_sc_t(
    'maintained_count' === $sspa_legacy_count['basis'] && is_int($sspa_legacy_count['count']),
    'the legacy route uses WordPress\'s own maintained count (' . var_export($sspa_legacy_count['basis'], true) . ')'
);
sspa_sc_t(
    null === SSPA_Demographics::orders_total(null, $sspa_tables['rows'])['count'],
    'and a site without WooCommerce counts nothing at all'
);

// The escape hatch on a table too large to count: real code, real row counts, ceiling
// lowered so this site qualifies as "too big".
add_filter('sspa_legacy_order_count_ceiling', 'sspa_sc_zero_ceiling');
function sspa_sc_zero_ceiling() {
    return 0;
}
$sspa_huge = SSPA_Demographics::orders_total(false, $sspa_tables['rows']);
remove_filter('sspa_legacy_order_count_ceiling', 'sspa_sc_zero_ceiling');
sspa_sc_t(
    'skipped_large_table' === $sspa_huge['basis'] && null === $sspa_huge['count'],
    'a posts table too big to count honestly reports nothing instead of a slow number'
);

// --- What leaves the site ---

$sspa_old_optin = get_option('sspa_share_optin', null);
update_option('sspa_share_optin', 1, false);
$sspa_now = gmdate('Y-m-d H:i:s');
$wpdb->insert(SSPA_Schema::table('runs'), array(
    'run_uuid' => wp_generate_uuid4(),
    'blog_id' => 1,
    'run_type' => 'baseline',
    'measurement_version' => 1,
    'trigger_source' => 'test',
    'status' => 'done',
    'plugin_set' => wp_json_encode(array('components' => array(
        array('type' => 'plugin', 'slug' => 'woocommerce', 'version' => '10.1.0'),
    ))),
    'plugin_set_hash' => md5('site-characteristics'),
    'site_metrics_id' => (int) $sspa_snapshot['id'],
    'started' => $sspa_now,
    'finished' => $sspa_now,
));
$sspa_run_id = (int) $wpdb->insert_id;
$wpdb->insert(SSPA_Schema::table('profiles'), array(
    'run_id' => $sspa_run_id,
    'page_key' => 'home',
    'url' => home_url('/'),
    'method' => 'GET',
    'variant' => 'anon',
    'plugin_set_hash' => md5('site-characteristics'),
    'object_cache_mode' => 'normal',
    'samples' => wp_json_encode(array(array('wall_ms' => 80, 'code' => 200))),
    'page_gen_ms' => 70,
    'sql_ms' => 8,
    'sql_count' => 12,
    'response_code' => 200,
    'created' => $sspa_now,
));
$sspa_profile_id = (int) $wpdb->insert_id;

$sspa_payload = SSPA_Community_Exporter::build($sspa_run_id);
sspa_sc_t(!is_wp_error($sspa_payload), 'the payload builds'
    . (is_wp_error($sspa_payload) ? ' (' . $sspa_payload->get_error_message() . ')' : ''));

if (!is_wp_error($sspa_payload)) {
    $sspa_snap = null;
    $sspa_snap_version = null;
    foreach ((array) $sspa_payload['evidence'] as $sspa_item) {
        if ('sspa/site-snapshot' === $sspa_item['type']) {
            $sspa_snap = $sspa_item['data'];
            $sspa_snap_version = (int) $sspa_item['version'];
        }
    }
    sspa_sc_t(2 === $sspa_snap_version, 'the site snapshot is evidence v2 (' . var_export($sspa_snap_version, true) . ')');

    $sspa_manifest_ok = false;
    foreach ((array) $sspa_payload['evidence_manifest'] as $sspa_entry) {
        if ('sspa/site-snapshot' === $sspa_entry['type'] && 2 === (int) $sspa_entry['version']) {
            $sspa_manifest_ok = true;
        }
    }
    sspa_sc_t($sspa_manifest_ok, 'and the manifest declares v2, which is how the receiver decides to reprocess');

    // v1 keys are still where a receiver that has not been upgraded expects them.
    $sspa_v1_intact = array_key_exists('wordpress_version', $sspa_snap)
        && array_key_exists('php_version', $sspa_snap)
        && array_key_exists('database_version', $sspa_snap)
        && array_key_exists('object_cache', $sspa_snap)
        && array_key_exists('sector', $sspa_snap)
        && array_key_exists('theme', $sspa_snap)
        && array_key_exists('posts', $sspa_snap['sizes'])
        && array_key_exists('products', $sspa_snap['sizes'])
        && array_key_exists('postmeta', $sspa_snap['sizes'])
        && array_key_exists('users', $sspa_snap['sizes'])
        && array_key_exists('database_bytes', $sspa_snap['sizes']);
    sspa_sc_t($sspa_v1_intact, 'v2 is a superset - every v1 field is still present and unrenamed');

    $sspa_expected_sizes = array(
        'posts', 'pages', 'primary_content_items', 'products', 'orders_total', 'orders_30d',
        'users', 'comments', 'postmeta', 'database_bytes', 'active_plugins', 'banding_version',
    );
    sspa_sc_t(
        array() === array_diff($sspa_expected_sizes, array_keys($sspa_snap['sizes'])),
        'the cohort sizes are all present (' . implode(', ', array_keys($sspa_snap['sizes'])) . ')'
    );
    sspa_sc_t(
        1 === $sspa_snap['sizes']['banding_version'],
        'the banding version is explicit, so a future ladder cannot be mistaken for this one'
    );

    $sspa_unbanded = array();
    foreach ($sspa_snap['sizes'] as $sspa_key => $sspa_value) {
        if ('banding_version' === $sspa_key || null === $sspa_value) {
            continue;
        }
        if (!is_string($sspa_value) || !preg_match('/^(<[0-9]+[kmb]?|1b\+)$/', $sspa_value)) {
            $sspa_unbanded[] = $sspa_key . '=' . var_export($sspa_value, true);
        }
    }
    sspa_sc_t(!$sspa_unbanded, 'every shared size is a band, never an exact count' . ($sspa_unbanded ? ': ' . implode(', ', $sspa_unbanded) : ''));

    sspa_sc_t(
        isset($sspa_snap['classification']['primary_purpose'])
        && in_array($sspa_snap['classification']['primary_purpose'], SSPA_Rules::purpose_taxonomy()['labels'], true),
        'the primary purpose is a canonical label (' . $sspa_snap['classification']['primary_purpose'] . ')'
    );
    sspa_sc_t(
        array_key_exists('checkout_type', $sspa_snap['environment'])
        && array_key_exists('woocommerce_hpos', $sspa_snap['environment'])
        && array_key_exists('object_cache_category', $sspa_snap['environment'])
        && array_key_exists('locale_family', $sspa_snap['environment']),
        'the environment block carries the stack facts a cohort control needs'
    );

    // The bespoke type is the largest thing on this site by some distance, so if a raw CPT
    // slug can escape anywhere, it escapes here.
    $sspa_json = wp_json_encode($sspa_payload);
    sspa_sc_t(false === strpos($sspa_json, $sspa_cpt_slug), 'the bespoke post type slug is nowhere in the payload');
    sspa_sc_t(
        'other' === $sspa_snap['primary_content']['class'],
        'it is reported only as primary content of class "other" (got ' . var_export($sspa_snap['primary_content']['class'], true) . ')'
    );

    // The final scan is what actually stops a payload leaving; run it over the richer shape.
    $sspa_valid = SSPA_Community_Privacy::validate($sspa_payload);
    sspa_sc_t(true === $sspa_valid, 'the richer snapshot passes the privacy scan'
        . (is_wp_error($sspa_valid) ? ' (' . $sspa_valid->get_error_message() . ')' : ''));
}

// A site with no WooCommerce must say "unknown", not "zero".
$sspa_no_wc = SSPA_Site_Characteristics::cohort_dimensions(array(
    'post_counts' => array('post' => 30, 'page' => 5),
    'active_plugins' => array('akismet'),
    'users' => 2,
    'db_bytes' => 4000000,
    'orders_total' => null,
    'orders_30d' => null,
));
sspa_sc_t(
    null === $sspa_no_wc['sizes']['orders_total'] && null === $sspa_no_wc['sizes']['orders_30d'],
    'a site with no shop reports null orders, never a band that reads as "no sales"'
);
sspa_sc_t(
    null === $sspa_no_wc['environment']['woocommerce_hpos'] && null === $sspa_no_wc['environment']['checkout_type'],
    'and null for every WooCommerce-only environment fact'
);

// --- Cleanup ---

$wpdb->delete(SSPA_Schema::table('profiles'), array('id' => $sspa_profile_id));
$wpdb->delete(SSPA_Schema::table('runs'), array('id' => $sspa_run_id));
foreach ($sspa_metrics_ids as $sspa_metrics_id) {
    $wpdb->delete(SSPA_Schema::table('site_metrics'), array('id' => $sspa_metrics_id));
}
foreach ($sspa_cpt_posts as $sspa_post_id) {
    wp_delete_post($sspa_post_id, true);
}
if (null === $sspa_old_optin) {
    delete_option('sspa_share_optin');
} else {
    update_option('sspa_share_optin', $sspa_old_optin, false);
}
sspa_sc_t(true, 'fixtures removed');
