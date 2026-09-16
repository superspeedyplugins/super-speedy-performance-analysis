<?php
// Exercise the real outbox/client with the receiver's two endpoint response shapes.
require __DIR__ . '/../lib/fleet.php';
wp_set_current_user(1);
$run = SSPA_Run_Controller::start(array('type'=>'spot', 'page_keys'=>array('home'), 'user_id'=>1));
if (is_wp_error($run)) { throw new RuntimeException($run->get_error_message()); }
sspa_fleet_complete($run);
$row = SSPA_Community_Outbox::queue_run($run, 'manual');
if (is_wp_error($row)) { throw new RuntimeException($row->get_error_message()); }
$receipt = wp_generate_uuid4();
$reservation = wp_generate_uuid4();
$mode = 'lost';
$calls = array();
$filter = function ($pre, $args, $url) use ($row, $receipt, $reservation, &$mode, &$calls) {
    $calls[] = $url;
    if (substr($url, -14) === '/installations') {
        $body = array('registered'=>true, 'install_uuid'=>SSPA_Community_Identity::install_uuid());
    } elseif (substr($url, -9) === '/complete') {
        if ('lost' === $mode) { return new WP_Error('http_request_failed', 'Completion response lost after remote archive'); }
        $body = array('submission_uuid'=>$row['submission_uuid'], 'receipt_uuid'=>$receipt, 'payload_sha256'=>$row['payload_sha256'], 'storage_status'=>'archived');
    } else {
        $body = array('submission_uuid'=>$row['submission_uuid'], 'reservation_uuid'=>$reservation, 'receipt_uuid'=>$receipt, 'payload_sha256'=>$row['payload_sha256'], 'storage_status'=>in_array($mode, array('lost','normal'), true) ? 'uploaded' : 'complete');
        if ('hash' === $mode) { $body['payload_sha256'] = str_repeat('0', 64); }
        if ('identity' === $mode) { $body['submission_uuid'] = wp_generate_uuid4(); }
        if ('receipt' === $mode) { $body['receipt_uuid'] = 'invalid'; }
    }
    return array('headers'=>array(), 'body'=>wp_json_encode($body), 'response'=>array('code'=>200, 'message'=>'OK'), 'cookies'=>array());
};
add_filter('pre_http_request', $filter, PHP_INT_MAX, 3);
try {
    $lost = SSPA_Community_Client::submit($row);
    sspa_fleet_assert(is_wp_error($lost) && 'sspa_collector_network_error' === $lost->get_error_code(), 'lost completion reply remains a retryable network error');
    if (is_wp_error($lost)) { SSPA_Community_Outbox::failed($row['id'], $lost, false); }
    $mode = 'retry'; $calls = array();
    $result = SSPA_Community_Client::submit(SSPA_Community_Outbox::get($row['id']));
    sspa_fleet_assert(!is_wp_error($result) && $receipt === $result['receipt_uuid'], 'reservation retry accepts the existing receipt with matching identity and hash');
    sspa_fleet_assert(count($calls) === 1 && substr($calls[0], -12) === '/submissions', 'retry retrieves one receipt without another upload or completion');
    if (!is_wp_error($result)) { SSPA_Community_Outbox::sent($row['id'], $result['receipt_uuid'], $result['http_status']); }
    $stored = SSPA_Community_Outbox::get($row['id']);
    sspa_fleet_assert('sent' === $stored['state'] && $receipt === $stored['receipt_uuid'] && $row['payload_sha256'] === $stored['payload_sha256'], 'outbox stores the verified receipt against the original immutable payload');
    foreach (array('hash','identity','receipt') as $mode) {
        $invalid = SSPA_Community_Client::submit($row);
        sspa_fleet_assert(is_wp_error($invalid) && !empty($invalid->get_error_data()['permanent']), 'reject changed receipt ' . $mode);
    }
    $mode = 'normal';
    $normal = SSPA_Community_Client::submit($row);
    sspa_fleet_assert(!is_wp_error($normal) && $receipt === $normal['receipt_uuid'], 'normal completion still accepts the archived receipt');
} finally {
    remove_filter('pre_http_request', $filter, PHP_INT_MAX);
}
sspa_fleet_done();
