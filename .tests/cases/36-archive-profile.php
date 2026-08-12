<?php
// The archive query profile: what Super Speedy Archives needs in order to configure its mirror
// table from a measured run instead of from a human filling in a settings tab.
//
// Every assertion here drives a REAL profiled request against the real site and reads what the
// plugin actually recorded. The parsing is deliberately not re-implemented in this file - a
// hand-copied ORDER BY parser would keep passing after the real one broke, which is backwards.

function sspa_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

function sspa_archive_run($url) {
    $run_id = SSPA_Run_Controller::start(array('type' => 'adhoc', 'url' => $url, 'trigger' => 'cli', 'user_id' => 1));
    if (is_wp_error($run_id)) {
        return $run_id;
    }
    $deadline = time() + 180;
    do {
        SSPA_Run_Controller::process_batch($run_id);
        $status = SSPA_Run_Controller::status($run_id);
    } while ($status && 'crawling' === $status['status'] && time() < $deadline);
    return $run_id;
}

function sspa_archive_capture($run_id) {
    global $wpdb;
    $blob = $wpdb->get_var($wpdb->prepare(
        'SELECT profile_blob FROM ' . SSPA_Schema::table('profiles') . ' WHERE run_id = %d LIMIT 1',
        $run_id
    ));
    if (!$blob) {
        return null;
    }
    $json = @gzuncompress($blob);
    $capture = json_decode($json ? $json : $blob, true);
    return is_array($capture) ? $capture : null;
}

function sspa_main_archive($capture) {
    if (empty($capture['archives']['queries'])) {
        return null;
    }
    foreach ($capture['archives']['queries'] as $record) {
        if (!empty($record['main'])) {
            return $record;
        }
    }
    return null;
}

// --- A taxonomy archive is captured at all ---

$cat = get_terms(array('taxonomy' => 'category', 'hide_empty' => true, 'number' => 1));
$cat_link = (!is_wp_error($cat) && $cat) ? get_term_link($cat[0]) : home_url('/');

$run_id = sspa_archive_run($cat_link);
if (is_wp_error($run_id)) {
    echo 'FAIL: category archive run: ' . $run_id->get_error_message() . "\n";
    return;
}

$capture = sspa_archive_capture($run_id);
sspa_t(is_array($capture) && (int) $capture['schema'] >= 2, 'capture schema bumped for the archives section: ' . ($capture ? $capture['schema'] : 'null'));
sspa_t(is_array($capture) && array_key_exists('archives', $capture), 'archives section present');

$record = sspa_main_archive($capture);
sspa_t(is_array($record), 'the main archive query was recorded');
sspa_t(is_array($record) && !empty($record['filters']['taxonomy']), 'its taxonomy filter was recorded');
// found_rows is the TOTAL matching the archive, not the page size - that distinction is the
// whole point of recording it, since it is the growth signal.
sspa_t(is_array($record) && null !== $record['found_rows'] && $record['found_rows'] >= count($capture['archives']['queries']), 'found_rows recorded as the archive total');
sspa_t(is_array($record) && !empty($record['orderby_final']), 'the resolved ORDER BY was recorded');
// $query->request is matched exactly against the query log, so cost attribution is never a
// guess. When there is no match the query was answered from the object cache, and saying so is
// the honest answer - what must never happen is a silent null with no account of why.
sspa_t(is_array($record) && (null !== $record['ms'] || !empty($record['cached'])), 'cost either attributed to the WP_Query or explained as a cache hit');
// The statement is retained either way, because a warm archive never reaches the database and
// its plan would otherwise be unavailable on exactly the sites that need this most.
sspa_t(is_array($record) && !empty($record['request']), 'the statement is retained so its plan can still be read');

// --- The WooCommerce case: ordering that query vars alone cannot see ---
//
// Woo resolves orderby=price at posts_clauses into wc_product_meta_lookup.min_price. A capture
// that read only the query vars would report "price" and never learn the column, which is why
// both forms are recorded.

if (class_exists('WooCommerce')) {
    $woo_run = sspa_archive_run(home_url('/?post_type=product&orderby=price'));
    $woo_record = is_wp_error($woo_run) ? null : sspa_main_archive(sspa_archive_capture($woo_run));

    $requested = '';
    if (is_array($woo_record) && !empty($woo_record['orderby_requested'])) {
        $requested = $woo_record['orderby_requested'][0]['by'];
    }
    sspa_t('price' === $requested, 'requested ordering kept as the intent: ' . $requested);

    $lookup = null;
    if (is_array($woo_record)) {
        foreach ($woo_record['orderby_final'] as $term) {
            if ('other_table' === $term['source']) {
                $lookup = $term;
                break;
            }
        }
    }
    sspa_t(is_array($lookup), 'ordering resolved to a joined lookup table, not to postmeta');
    sspa_t(is_array($lookup) && 'wc_product_meta_lookup' === $lookup['table'], 'lookup table named: ' . ($lookup ? $lookup['table'] : 'none'));
    sspa_t(is_array($lookup) && 'min_price' === $lookup['column'], 'lookup column named: ' . ($lookup ? $lookup['column'] : 'none'));

    // --- The composite must not be prefixed on an exclusion ---
    //
    // Woo adds a product_visibility NOT IN clause to every product query. NOT IN excludes, it
    // does not narrow, and Archives serves it with a NOT EXISTS subquery rather than a leading
    // index column - so an index prefixed on it would help nothing.
    $profile = SSPA_Report::archive_profile($woo_run);
    $entry = null;
    if (!is_wp_error($profile)) {
        foreach ($profile['archives'] as $candidate) {
            if (!empty($candidate['main'])) {
                $entry = $candidate;
                break;
            }
        }
    }
    sspa_t(is_array($entry) && !in_array('product_visibility', $entry['taxonomy'], true), 'an excluding taxonomy is kept out of the narrowing list');
    sspa_t(is_array($entry) && in_array('product_visibility', $entry['taxonomy_excluded'], true), 'and is reported as excluding instead');

    $term_columns_without_taxonomy = false;
    $visibility_indexed = false;
    if (!is_wp_error($profile)) {
        foreach ($profile['candidate_composites'] as $composite) {
            // Term columns may only appear when something actually narrows by term.
            if (in_array('topterm_id', $composite['columns'], true) && empty($composite['taxonomy'])) {
                $term_columns_without_taxonomy = true;
            }
            if (in_array('product_visibility', $composite['taxonomy'], true)) {
                $visibility_indexed = true;
            }
        }
    }
    sspa_t(!$term_columns_without_taxonomy, 'no composite carries term columns without a narrowing taxonomy');
    sspa_t(!$visibility_indexed, 'no composite is built to serve the product_visibility exclusion');

    // The lookup column's type is read from INFORMATION_SCHEMA, so it is exact rather than
    // sampled - there is nothing to infer about a declared column.
    $min_price = null;
    if (!is_wp_error($profile) && isset($profile['materialise']['other_table']['wc_product_meta_lookup.min_price'])) {
        $min_price = $profile['materialise']['other_table']['wc_product_meta_lookup.min_price'];
    }
    sspa_t(is_array($min_price) && 'schema' === $min_price['confidence'], 'lookup column type taken from the schema, not sampled');
    sspa_t(is_array($min_price) && 'decimal' === $min_price['sql_type'], 'min_price typed decimal: ' . ($min_price ? $min_price['sql_type'] : 'none'));
}

// --- Type inference: the field only this plugin can supply ---
//
// An index cannot serve an ORDER BY on a CAST, so the STORED type decides whether a
// materialised column is usable. It is not recoverable from the SQL, and getting it wrong is
// worse than not configuring the key: _price as varchar sorts "100" before "99".

$post_id = (int) $wpdb->get_var("SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' LIMIT 1");
$types_fixture = array(
    'sspa_t_int' => array('1', '42', '9999'),
    'sspa_t_big' => array('9223372036', '4294967296'),
    'sspa_t_decimal' => array('9.99', '129.50', '0.01'),
    'sspa_t_float' => array('1.23456', '9.87654'),
    'sspa_t_date' => array('2026-08-12', '2025-01-01'),
    'sspa_t_datetime' => array('2026-08-12 09:30:00', '2025-01-01 00:00:00'),
    'sspa_t_bit' => array('0', '1'),
    'sspa_t_text' => array('instock', 'outofstock', 'onbackorder'),
    'sspa_t_mixed' => array('9.99', 'call for price', '19.99'),
);
foreach ($types_fixture as $key => $values) {
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", $key));
    // Above MIN_CONFIDENT so the sample is allowed to name a type at all.
    for ($i = 0; $i < 30; $i++) {
        add_post_meta($post_id, $key, $values[$i % count($values)]);
    }
}

$needed = array('postmeta' => array());
foreach (array_keys($types_fixture) as $key) {
    $needed['postmeta'][$key] = array('order');
}
$types = SSPA_Archive_Types::infer($needed);

$expect = array(
    'sspa_t_int' => 'int',
    'sspa_t_big' => 'bigint',
    'sspa_t_decimal' => 'decimal',
    'sspa_t_float' => 'float',
    'sspa_t_date' => 'date',
    'sspa_t_datetime' => 'datetime',
    'sspa_t_bit' => 'bit',
    'sspa_t_text' => 'varchar',
);
foreach ($expect as $key => $want) {
    $got = isset($types['postmeta:' . $key]['sql_type']) ? $types['postmeta:' . $key]['sql_type'] : 'none';
    sspa_t($got === $want, "$key inferred as $want (got $got)");
}

// Values that parse as numbers in some rows and not others must be reported as mixed and NEVER
// rounded to the majority - either choice is wrong for part of the data, so the consumer has to
// be told to refuse it.
$mixed = isset($types['postmeta:sspa_t_mixed']) ? $types['postmeta:sspa_t_mixed'] : array();
sspa_t(isset($mixed['confidence']) && 'mixed' === $mixed['confidence'], 'mixed values reported as mixed, not rounded to the majority: ' . (isset($mixed['confidence']) ? $mixed['confidence'] : 'none'));
sspa_t(isset($mixed['sql_type']) && 'varchar' === $mixed['sql_type'], 'and typed conservatively as varchar');

// A thin sample cannot support a confident verdict.
$wpdb->query("DELETE FROM {$wpdb->postmeta} WHERE meta_key = 'sspa_t_thin'");
add_post_meta($post_id, 'sspa_t_thin', '5');
$thin = SSPA_Archive_Types::infer(array('postmeta' => array('sspa_t_thin' => array('order'))));
sspa_t('low' === $thin['postmeta:sspa_t_thin']['confidence'], 'a thin sample is reported low-confidence rather than guessed');

// No meta values may appear anywhere in the output, only keys, types and counts.
$types_json = wp_json_encode($types);
sspa_t(false === strpos($types_json, 'outofstock') && false === strpos($types_json, 'call for price'), 'no meta VALUES leak into the type verdicts');

foreach (array_merge(array_keys($types_fixture), array('sspa_t_thin')) as $key) {
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s", $key));
}

// --- What may be shared with superspeedy.org ---
//
// profile_blob is never submitted, so the shared block is built from the aggregate. Meta keys
// are the first site-authored strings that would ever leave an install, so a recognised key
// (software) is shared by name and an unrecognised one (a business) is not.

// The same selection SSPA_Anonymiser::build() makes - adhoc runs are deliberately excluded
// from it, so seeding any other run id would leave this asserting against the wrong data.
$share_run = (int) $wpdb->get_var('SELECT id FROM ' . SSPA_Schema::table('runs') . " WHERE status = 'done' AND run_type IN ('baseline','spot') ORDER BY id DESC LIMIT 1");
set_transient('sspa_archive_profile_' . $share_run, array(
    'run_id' => $share_run,
    'schema' => 1,
    'complete' => true,
    'pages_seen' => 1,
    'archives' => array(array(
        'page_key' => 'cpt-listing-archive',
        'main' => true,
        'post_type' => array('listing'),
        'taxonomy' => array('listing_cat'),
        'taxonomy_excluded' => array(),
        'found_rows' => 480000,
        'ms' => 910.2,
        'explain' => array('filesort' => true, 'scan' => true, 'key' => null, 'est_rows' => 1904322),
        'orderby' => array(
            array('source' => 'postmeta', 'table' => null, 'column' => 'meta_value', 'meta_key' => '_acme_client_ref', 'order' => 'ASC', 'cast' => null),
            array('source' => 'postmeta', 'table' => null, 'column' => 'meta_value', 'meta_key' => '_price', 'order' => 'DESC', 'cast' => 'DECIMAL'),
        ),
        'orderby_requested' => array(),
    )),
    'candidate_composites' => array(array(
        'columns' => array('post_type', 'post_status', 'taxonomy', 'topterm_id', 'meta__acme_client_ref ASC', 'meta__price DESC'),
        'seen_on' => array('cpt-listing-archive'),
        'max_rows' => 480000,
    )),
    'qualified_by' => array(),
    'materialise' => array(
        'postmeta' => array(
            '_acme_client_ref' => array('sql_type' => 'varchar', 'confidence' => 'sampled', 'used_for' => array('order')),
            '_price' => array('sql_type' => 'decimal', 'confidence' => 'sampled', 'used_for' => array('order')),
        ),
        'other_table' => array(),
        'posts_columns' => array('post_date'),
    ),
    'largest_archive_rows' => 480000,
    'unsupported' => array(),
), 300);

$payload = SSPA_Anonymiser::build();
$shared = (!is_wp_error($payload) && isset($payload['archives'])) ? $payload['archives'] : null;
$shared_json = wp_json_encode($shared);

sspa_t($share_run > 0, 'a shareable run exists to build the payload from');
sspa_t(is_array($shared) && !empty($shared['archives']), 'the shared payload carries an archives block');
sspa_t(is_string($shared_json) && false === strpos($shared_json, '_acme_client_ref'), 'a bespoke meta key never leaves the site');
sspa_t(is_string($shared_json) && false !== strpos($shared_json, '_price'), 'a known WooCommerce key is shared by name');
sspa_t(is_string($shared_json) && false !== strpos($shared_json, 'meta__price DESC'), 'and keeps its sort direction, which is part of the index definition');
sspa_t(is_string($shared_json) && false !== strpos($shared_json, 'meta_custom ASC'), 'the redacted key keeps its direction too');
// Row counts are bucketed exactly like every other size in the payload.
$first_shared = (is_array($shared) && !empty($shared['archives'])) ? $shared['archives'][0] : array();
sspa_t(isset($first_shared['rows']) && '<1m' === $first_shared['rows'], 'archive size bucketed, not sent exactly: ' . (isset($first_shared['rows']) ? $first_shared['rows'] : 'none'));
sspa_t(!empty($first_shared['filesort']), 'the plan verdict is shared - the reason, not just the timing');

delete_transient('sspa_archive_profile_' . $share_run);

// --- Runs that predate the contract ---
//
// A schema-1 capture has no archives key. That is "unknown", never "this site has no
// archives": the two are indistinguishable from the payload, and reading one as the other
// would silently under-report every older run.
$legacy_capture = sspa_archive_capture($run_id);
unset($legacy_capture['archives']);
$wpdb->update(
    SSPA_Schema::table('profiles'),
    array('profile_blob' => gzcompress(wp_json_encode($legacy_capture), 6)),
    array('run_id' => $run_id)
);
delete_transient('sspa_archive_profile_' . $run_id);
$legacy = SSPA_Report::archive_profile($run_id);
sspa_t(!is_wp_error($legacy) && $legacy['predates_contract'] >= 1, 'a capture with no archives key is counted as predating the contract');
sspa_t(!is_wp_error($legacy) && false === $legacy['complete'], 'and the run is reported incomplete rather than empty');
