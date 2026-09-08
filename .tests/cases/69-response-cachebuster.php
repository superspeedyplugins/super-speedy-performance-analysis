<?php
// Real REST pagination reflects PA's random cache buster into otherwise stable headers.
// This is core output-identity behaviour, independent of the History feature.
function sspa_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
}
$responses = array();
foreach (array('aaaaaaaaaaaa', 'bbbbbbbbbbbb') as $buster) {
    $response = wp_remote_get(add_query_arg(array('per_page' => 1, '_fields' => 'id', 'sspa_nc' => $buster), rest_url('wp/v2/posts')));
    sspa_t(!is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response), 'REST fixture responds successfully');
    if (is_wp_error($response)) {
        return;
    }
    sspa_t(false !== strpos(wp_remote_retrieve_header($response, 'link'), 'sspa_nc=' . $buster), 'pagination really reflects the changing cache buster');
    $responses[] = $response;
}
$body = wp_remote_retrieve_body($responses[0]);
sspa_t($body === wp_remote_retrieve_body($responses[1]), 'real response bodies are identical');
$a = SSPA_Crawler::body_hash($body, wp_remote_retrieve_headers($responses[0]));
$b = SSPA_Crawler::body_hash($body, wp_remote_retrieve_headers($responses[1]));
sspa_t($a === $b, 'identical REST output remains stable despite PA cache busters');
$link = wp_remote_retrieve_header($responses[0], 'link');
sspa_t(SSPA_Crawler::body_hash($body, array('link' => $link)) !== SSPA_Crawler::body_hash($body, array('link' => str_replace('page=2', 'page=999', $link))), 'meaningful pagination changes still change the signature');
sspa_t(SSPA_Crawler::body_hash('{"id":1}') !== SSPA_Crawler::body_hash('{"id":2}'), 'meaningful body changes still change the signature');
sspa_t(SSPA_Crawler::body_hash('<a href="/?sspa_nc=aaaaaaaaaaaa&page=2">Next</a>') === SSPA_Crawler::body_hash('<a href="/?sspa_nc=bbbbbbbbbbbb&page=2">Next</a>'), 'reflected cache busters in response bodies are also normalised');
