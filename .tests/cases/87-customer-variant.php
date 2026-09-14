<?php
// Logged-in customer measurements (issue 18, decision on issue 11). The catalogue measures
// My Account, Orders, the shop and one product page as a synthetic customer; the account is
// marked, unusable by a person and recreated fresh for every run; and every surface names
// the variant "logged-in customer" and keeps it apart from anonymous measurements.
require __DIR__ . '/../lib/fleet.php';

function sspa_87_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    if (!$ok) { $GLOBALS['sspa_87_failures']++; }
}
$GLOBALS['sspa_87_failures'] = 0;
function sspa_87_run($page_keys) {
    $id = SSPA_Run_Controller::start(array('type' => 'spot', 'page_keys' => $page_keys, 'user_id' => 1));
    if (is_wp_error($id)) { throw new RuntimeException($id->get_error_message()); }
    $deadline = time() + 240;
    do {
        SSPA_Run_Controller::process_batch($id);
        $status = SSPA_Run_Controller::status($id);
    } while ($status && in_array($status['status'], array('crawling', 'analysing'), true) && time() < $deadline);
    if (!$status || 'done' !== $status['status']) { throw new RuntimeException('Customer-variant run did not complete'); }
    return (int) $id;
}
function sspa_87_profiles($run_id) {
    global $wpdb;
    $out = array();
    foreach ($wpdb->get_results($wpdb->prepare('SELECT * FROM %i WHERE run_id = %d', SSPA_Schema::table('profiles'), $run_id), ARRAY_A) as $row) {
        $out[$row['page_key']] = $row;
    }
    return $out;
}

wp_set_current_user(1);
global $wpdb;
sspa_87_t(class_exists('WooCommerce'), 'WooCommerce is active on the test site');

try {
    // 1. The catalogue emits the four customer jobs, each on the same URL as its anonymous twin.
    $jobs = array();
    foreach (SSPA_Catalogue::build() as $job) { $jobs[$job['page_key']] = $job; }
    $expected_pairs = array(
        'customer-account' => 'wc-myaccount',
        'customer-shop' => 'shop',
        'customer-product' => 'product-single',
    );
    foreach ($expected_pairs as $customer_key => $anon_key) {
        sspa_87_t(isset($jobs[$customer_key]) && 'customer' === $jobs[$customer_key]['variant'], 'the catalogue offers ' . $customer_key . ' as a customer measurement');
        sspa_87_t(isset($jobs[$customer_key], $jobs[$anon_key]) && $jobs[$customer_key]['url'] === $jobs[$anon_key]['url'], $customer_key . ' measures the same URL as ' . $anon_key . ' (' . ($jobs[$customer_key]['url'] ?? '-') . ')');
    }
    sspa_87_t(isset($jobs['customer-orders']) && 'customer' === $jobs['customer-orders']['variant'] && $jobs['customer-orders']['url'] === wc_get_account_endpoint_url('orders'), 'the catalogue offers the Orders endpoint as a customer measurement');
    sspa_87_t(isset($jobs['wc-myaccount']) && 'anon' === $jobs['wc-myaccount']['variant'], 'the anonymous My Account page (the login form) is still measured as a visitor');

    // 2. A real run as the customer: authenticated pages, not the login form.
    $first_run = sspa_87_run(array('customer-account', 'customer-orders', 'wc-myaccount'));
    $profiles = sspa_87_profiles($first_run);
    foreach (array('customer-account', 'customer-orders', 'wc-myaccount') as $key) {
        sspa_87_t(isset($profiles[$key]) && 200 === (int) $profiles[$key]['response_code'] && null === $profiles[$key]['blocked_by'], $key . ' measured with HTTP 200 (' . ($profiles[$key]['response_code'] ?? 'missing') . ')');
    }
    sspa_87_t(isset($profiles['customer-account']) && 'customer' === $profiles['customer-account']['variant'], 'the stored profile carries the customer variant');
    $account_samples = isset($profiles['customer-account']) ? json_decode((string) $profiles['customer-account']['samples'], true) : array();
    $login_samples = isset($profiles['wc-myaccount']) ? json_decode((string) $profiles['wc-myaccount']['samples'], true) : array();
    sspa_87_t(!empty($account_samples[0]['body_hash']) && !empty($login_samples[0]['body_hash']) && $account_samples[0]['body_hash'] !== $login_samples[0]['body_hash'], 'the customer saw a different page from the visitor (account, not the login form)');
    $customer_id = SSPA_Auth::test_customer_id();
    $page = wp_remote_get(wc_get_page_permalink('myaccount'), array('cookies' => SSPA_Auth::cookies_for('customer', 0), 'timeout' => 20, 'sslverify' => false));
    $body = is_wp_error($page) ? '' : wp_remote_retrieve_body($page);
    sspa_87_t(false !== strpos($body, 'customer-logout') && false === strpos($body, 'woocommerce-form-login'), 'the customer cookie authenticates: My Account shows the logout link and no login form');

    // 3. The synthetic account: marked, low-privilege, unusable by a person.
    $user = get_userdata($customer_id);
    sspa_87_t($user && SSPA_Auth::is_test_account($customer_id) && in_array('customer', (array) $user->roles, true), 'the synthetic account is marked and has the customer role (' . implode(',', $user ? (array) $user->roles : array()) . ')');
    $signon = wp_signon(array('user_login' => $user ? $user->user_login : '', 'user_password' => 'anything', 'remember' => false), false);
    sspa_87_t(is_wp_error($signon) && 'sspa_test_account' === $signon->get_error_code(), 'interactive login is refused before any password check');
    sspa_87_t(false === apply_filters('allow_password_reset', true, $customer_id), 'password reset is refused');
    sspa_87_t(!in_array('administrator', (array) ($user ? $user->roles : array()), true) && !user_can($customer_id, 'edit_posts'), 'the account can edit nothing');

    // 4. Recreated fresh on the next run: the old account is gone, a new one exists.
    $second_run = sspa_87_run(array('customer-account'));
    $fresh_id = SSPA_Auth::test_customer_id();
    sspa_87_t($fresh_id > 0 && $fresh_id !== $customer_id && false === get_userdata($customer_id), 'a new run replaces the synthetic account (' . $customer_id . ' -> ' . $fresh_id . ')');
    sspa_87_t(1 === count(get_users(array('meta_key' => SSPA_Auth::TEST_ACCOUNT_META, 'meta_value' => '1', 'fields' => 'ID'))), 'exactly one synthetic account exists');

    // 5. Surfaces name the variant and keep it apart.
    $panel = SSPA_Profile_Panel::render((int) $profiles['customer-account']['id']);
    sspa_87_t(false !== strpos($panel, 'profiled as a logged-in customer'), 'the profile panel says "profiled as a logged-in customer"');
    sspa_87_t('logged-in customer' === SSPA_Catalogue::variant_label('customer') && 'logged-out visitor' === SSPA_Catalogue::variant_label('anon'), 'variant labels are the agreed wording');
    sspa_87_t('custom-frontend' !== SSPA_Community_Privacy::page_class('customer-account', 'customer'), 'the privacy classifier knows the customer pages (' . SSPA_Community_Privacy::page_class('customer-account', 'customer') . ')');
    $export = SSPA_Community_Exporter::build($first_run);
    $exported_variants = array();
    if (!is_array($export) || is_wp_error($export)) {
        sspa_87_t(false, 'the community export builds');
    } else {
        foreach ((array) $export['evidence'] as $item) {
            if ('sspa/page-profile' === $item['type'] && isset($item['data']['page_class'], $item['data']['variant'])) {
                $exported_variants[$item['data']['page_class']] = $item['data']['variant'];
            }
        }
        sspa_87_t(isset($exported_variants['customer-account']) && 'customer' === $exported_variants['customer-account'], 'the community export keeps variant "customer" (' . ($exported_variants['customer-account'] ?? 'missing') . ')');
    }
    $comparison = SSPA_History::compare($first_run, $second_run);
    $keys = is_wp_error($comparison) ? array() : wp_list_pluck($comparison['pages'], 'variant', 'page_key');
    sspa_87_t(isset($keys['customer-account'], $keys['wc-myaccount']) && 'customer' === $keys['customer-account'] && 'anon' === $keys['wc-myaccount'], 'History keeps the customer and visitor measurements as separate rows');
} catch (Throwable $error) {
    sspa_87_t(false, $error->getMessage());
}

if ($GLOBALS['sspa_87_failures']) { exit(1); }
