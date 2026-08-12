<?php
// The `sspa_component_state` filter: what another plugin publishes about its own configuration
// alongside a measurement, and - more importantly - what this plugin refuses to let it publish.
//
// Every assertion drives the REAL filter and the REAL exporter. The fixtures are registered as
// genuine filter callbacks and the payload is built by SSPA_Community_Exporter::build(), so a
// change to the grammar, the capture point or the exporter shows up here rather than in a
// hand-copied replica of any of them.
//
// The hostile-filter case is the one that matters most. SSPA_Community_Privacy::validate() aborts
// the WHOLE payload on a single forbidden value, so without per-record isolation one badly
// written third-party filter would silently stop a site submitting anything ever again.

function sspa_t($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $label\n";
}

global $wpdb;

/**
 * Start a run with a set of filters registered, let it finish, and hand back the built payload.
 * The filters are removed again afterwards so each case is independent.
 */
function sspa_state_run_with($callbacks) {
    foreach ($callbacks as $callback) {
        add_filter('sspa_component_state', $callback, 10, 2);
    }

    $run_id = SSPA_Run_Controller::start(array('type' => 'adhoc', 'url' => home_url('/'), 'trigger' => 'cli', 'user_id' => 1));

    foreach ($callbacks as $callback) {
        remove_filter('sspa_component_state', $callback, 10);
    }

    if (is_wp_error($run_id)) {
        return $run_id;
    }

    $deadline = time() + 180;
    do {
        SSPA_Run_Controller::process_batch($run_id);
        $status = SSPA_Run_Controller::status($run_id);
    } while ($status && in_array($status['status'], array('queued', 'crawling', 'analysing'), true) && time() < $deadline);

    return SSPA_Community_Exporter::build($run_id);
}

function sspa_state_record($payload, $slug) {
    if (is_wp_error($payload)) {
        return null;
    }
    foreach ((array) $payload['evidence'] as $item) {
        if ('sspa/component-state' === $item['type'] && $slug === $item['data']['component']['slug']) {
            return $item['data'];
        }
    }
    return null;
}

// ---------------------------------------------------------------------------
// 1. A well-behaved publisher.
// ---------------------------------------------------------------------------

$sspa_good = function ($records) {
    $records[] = array(
        'component' => array('type' => 'plugin', 'slug' => 'sspa-state-fixture'),
        'state_schema_version' => 4,
        'disclosure' => array(
            'label' => 'State Fixture',
            'publishes' => array('which of its optimisations are switched on'),
        ),
        'summary' => array('profile' => 'good', 'steps_applied' => 7),
        'options' => array('some_setting' => 'enabled', 'recount_time' => '02:00', 'columns' => array('post_date', 'menu_order')),
        'state' => array('table_built' => true, 'rows' => 0, 'nothing_here' => array()),
    );
    return $records;
};

$payload = sspa_state_run_with(array($sspa_good));
sspa_t(!is_wp_error($payload), 'a payload with a component-state record builds');

$record = sspa_state_record($payload, 'sspa-state-fixture');
sspa_t(is_array($record), 'the published record reaches the payload');
sspa_t(is_array($record) && 'good' === $record['summary']['profile'], 'the summary survives');
sspa_t(is_array($record) && 'enabled' === $record['options']['some_setting'], 'the option detail survives');
sspa_t(is_array($record) && '02:00' === $record['options']['recount_time'], 'a colon in a value survives - the grammar admits times');
sspa_t(is_array($record) && array('post_date', 'menu_order') === $record['options']['columns'], 'a list value survives');
// An empty list is a legitimate and extremely common value ("no meta keys configured"). It was
// dropped once, because range(0, -1) is [0, -1] rather than [], so a naive list check rejects it.
sspa_t(is_array($record) && array() === $record['state']['nothing_here'], 'an EMPTY list survives');
sspa_t(is_array($record) && 0 === (int) $record['omitted_keys'], 'nothing well-formed was counted as omitted');
sspa_t(is_array($record) && 4 === (int) $record['state_schema_version'], 'the publisher owns its own state schema version');

// The version is filled in by THIS plugin from the run's own inventory, never trusted from the
// filter, so a state record can never disagree with the rest of the payload about what was
// measured. The fixture is not a real plugin, so there is no version to find.
sspa_t(is_array($record) && null === $record['component_version'], 'a component that is not installed gets no version');

$manifest = array();
if (!is_wp_error($payload)) {
    foreach ((array) $payload['evidence_manifest'] as $entry) {
        $manifest[$entry['type']] = (int) $entry['count'];
    }
}
sspa_t(isset($manifest['sspa/component-state']) && $manifest['sspa/component-state'] >= 1, 'the manifest declares the record');

// ---------------------------------------------------------------------------
// 2. A hostile publisher must not leak, and must not take the payload with it.
// ---------------------------------------------------------------------------

$sspa_hostile = function ($records) {
    $records[] = array(
        'component' => array('type' => 'plugin', 'slug' => 'sspa-hostile-fixture'),
        'state_schema_version' => 1,
        'options' => array(
            'licence' => 'ABCD-1234-SECRET-KEY',
            'endpoint' => 'https://customer-site.example.com/wp-json',
            'log_path' => '/var/www/customer/wp-content/debug.log',
            'admin_email' => 'owner@customer-site.example.com',
            'selector' => '.col-sm-3.col-sm-pull-9',
            'server_ip' => '203.0.113.42',
            'nested' => array('deep' => array('deeper' => 'no')),
            'fine' => 'enabled',
        ),
    );
    return $records;
};

$payload = sspa_state_run_with(array($sspa_good, $sspa_hostile));

// The whole point: the good record and the payload both survive.
sspa_t(!is_wp_error($payload), 'a hostile filter does not stop the payload building');
sspa_t(is_array(sspa_state_record($payload, 'sspa-state-fixture')), 'the well-behaved record still travels alongside a hostile one');

$hostile = sspa_state_record($payload, 'sspa-hostile-fixture');
sspa_t(is_array($hostile), 'the hostile record is still published - censored, not silently dropped');
if (is_array($hostile)) {
    sspa_t(!isset($hostile['options']['endpoint']), 'a URL is dropped');
    sspa_t(!isset($hostile['options']['log_path']), 'a filesystem path is dropped');
    sspa_t(!isset($hostile['options']['admin_email']), 'an email address is dropped');
    sspa_t(!isset($hostile['options']['selector']), 'a free-text CSS selector is dropped');
    sspa_t(!isset($hostile['options']['nested']), 'a nested structure is dropped rather than walked');
    // An IP address is alphanumerics and dots, so the CHARACTER grammar admits it; it is the
    // privacy scanner that catches it. Censoring the one key rather than failing the record is
    // the difference between losing a stray value and losing a plugin's whole configuration.
    sspa_t(!isset($hostile['options']['server_ip']), 'an IP address is dropped even though it passes the character grammar');
    sspa_t('enabled' === $hostile['options']['fine'], 'a legitimate value alongside them still travels');
    // A receiver must be able to tell "no such setting" from "censored on the way out".
    sspa_t((int) $hostile['omitted_keys'] >= 6, 'the censored keys are counted, so the snapshot is not silently partial');
}

// A licence key is a plausible enum token and WILL pass the character grammar. That is the stated
// limit of what this plugin can enforce, and the reason the filter is a declaration rather than a
// dump: only the publishing plugin knows what its own settings mean. Asserted so the limit is
// recorded rather than assumed away.
sspa_t(
    is_array($hostile) && isset($hostile['options']['licence']),
    'a secret shaped like an enum token DOES pass - the publisher, not this plugin, decides what is safe to publish'
);

$json = is_wp_error($payload) ? '' : SSPA_Community_Schema::encode($payload);
sspa_t(is_string($json) && false === strpos($json, 'customer-site.example.com'), 'no hostname reaches the encoded payload');
sspa_t(is_string($json) && false === strpos($json, '/var/www/customer'), 'no server path reaches the encoded payload');
sspa_t(is_string($json) && false === strpos($json, '203.0.113.42'), 'no IP address reaches the encoded payload');

// ---------------------------------------------------------------------------
// 3. Capture happens at RUN START, not at export.
//
// This is the regression that would otherwise go unnoticed for months, because the wrong answer
// is still a perfectly well-formed payload: it would stamp today's settings onto last week's
// baseline and mislabel exactly the before/after pairs the evidence exists to tell apart.
// ---------------------------------------------------------------------------

$GLOBALS['sspa_state_value'] = 'before-value';
$sspa_moving = function ($records) {
    $records[] = array(
        'component' => array('type' => 'plugin', 'slug' => 'sspa-timing-fixture'),
        'state_schema_version' => 1,
        'options' => array('setting' => $GLOBALS['sspa_state_value']),
    );
    return $records;
};

add_filter('sspa_component_state', $sspa_moving, 10, 2);
$run_id = SSPA_Run_Controller::start(array('type' => 'adhoc', 'url' => home_url('/'), 'trigger' => 'cli', 'user_id' => 1));
$deadline = time() + 180;
do {
    SSPA_Run_Controller::process_batch($run_id);
    $status = SSPA_Run_Controller::status($run_id);
} while ($status && in_array($status['status'], array('queued', 'crawling', 'analysing'), true) && time() < $deadline);

// The setting changes AFTER the run was measured but BEFORE the payload is exported.
$GLOBALS['sspa_state_value'] = 'after-value';
$payload = SSPA_Community_Exporter::build($run_id);
remove_filter('sspa_component_state', $sspa_moving, 10);

$timing = sspa_state_record($payload, 'sspa-timing-fixture');
sspa_t(is_array($timing), 'the timing fixture published a record');
sspa_t(is_array($timing) && 'before-value' === $timing['options']['setting'], 'the payload carries the configuration as it was WHEN MEASURED, not as it is now');

// ---------------------------------------------------------------------------
// 4. No publisher, no record. A plugin that has not opted in contributes nothing.
// ---------------------------------------------------------------------------

$payload = sspa_state_run_with(array());
$found = 0;
if (!is_wp_error($payload)) {
    foreach ((array) $payload['evidence'] as $item) {
        if ('sspa/component-state' === $item['type']) {
            $found++;
        }
    }
}
sspa_t(!is_wp_error($payload), 'a payload with no publishers still builds');
sspa_t(0 === $found, 'a site whose plugins have not opted in publishes no settings at all');

// ---------------------------------------------------------------------------
// 5. The before/after pairing.
// ---------------------------------------------------------------------------

$cycle_uuid = wp_generate_uuid4();
$run_id = SSPA_Run_Controller::start(array(
    'type' => 'adhoc',
    'url' => home_url('/'),
    'trigger' => 'cli',
    'user_id' => 1,
    'share_context' => array('change_cycle' => array(
        'cycle_uuid' => $cycle_uuid,
        'phase' => 'before',
        'driver' => 'sspa-state-fixture',
    )),
));
$deadline = time() + 180;
do {
    SSPA_Run_Controller::process_batch($run_id);
    $status = SSPA_Run_Controller::status($run_id);
} while ($status && in_array($status['status'], array('queued', 'crawling', 'analysing'), true) && time() < $deadline);

$payload = SSPA_Community_Exporter::build($run_id);
sspa_t(!is_wp_error($payload) && isset($payload['run']['change_cycle']), 'a cycle run carries its pairing on the run object');
sspa_t(!is_wp_error($payload) && $cycle_uuid === $payload['run']['change_cycle']['cycle_uuid'], 'the cycle identifier survives');
sspa_t(!is_wp_error($payload) && 'before' === $payload['run']['change_cycle']['phase'], 'the phase survives');

// A phase outside the closed set is not a cycle at all, and must not become one.
$run_id = SSPA_Run_Controller::start(array(
    'type' => 'adhoc',
    'url' => home_url('/'),
    'trigger' => 'cli',
    'user_id' => 1,
    'share_context' => array('change_cycle' => array(
        'cycle_uuid' => wp_generate_uuid4(),
        'phase' => 'sideways',
        'driver' => 'sspa-state-fixture',
    )),
));
$deadline = time() + 180;
do {
    SSPA_Run_Controller::process_batch($run_id);
    $status = SSPA_Run_Controller::status($run_id);
} while ($status && in_array($status['status'], array('queued', 'crawling', 'analysing'), true) && time() < $deadline);

$payload = SSPA_Community_Exporter::build($run_id);
sspa_t(!is_wp_error($payload) && null === $payload['run']['change_cycle'], 'an unrecognised phase is refused rather than published');

// ---------------------------------------------------------------------------
// 6. The disclosure names who published, so the Share tab can answer "whose settings are these".
// ---------------------------------------------------------------------------

$payload = sspa_state_run_with(array($sspa_good));

// The label is read from the LIVE plugin, not from the payload, because the payload deliberately
// carries no consent text. So the filter has to be registered while the disclosure is rendered -
// which is exactly the real case, since the Share tab renders it on a site with the plugin
// active.
add_filter('sspa_component_state', $sspa_good, 10, 2);
$described = SSPA_Submitter::describe_payload(SSPA_Community_Schema::encode($payload));
remove_filter('sspa_component_state', $sspa_good, 10);
sspa_t(
    isset($described['state_components']) && in_array('State Fixture', $described['state_components'], true),
    'the disclosure names the publisher by its own label, not by its slug'
);

// ...and with the plugin gone, the slug is all there is to go on. A historical payload whose
// publisher has since been deactivated still has to be describable.
$described = SSPA_Submitter::describe_payload(SSPA_Community_Schema::encode($payload));
sspa_t(
    isset($described['state_components']) && in_array('sspa-state-fixture', $described['state_components'], true),
    'a payload whose publisher is no longer active falls back to the slug'
);

// ---------------------------------------------------------------------------
// 7. The plain-English declaration, and the site owner's per-plugin switch.
//
// The switch is the reason this section exists: it has to be honoured at CAPTURE time, so that a
// switched-off plugin leaves nothing on the run at all. Filtering it out at export would leave
// the configuration sitting in the database of a site whose owner had declined to publish it.
// ---------------------------------------------------------------------------

add_filter('sspa_component_state', $sspa_good, 10, 2);

$publishers = SSPA_Community_State::publishers();
$fixture = null;
foreach ($publishers as $entry) {
    if ('sspa-state-fixture' === $entry['slug']) {
        $fixture = $entry;
    }
}
sspa_t(is_array($fixture), 'a registered publisher is listed for the consent screen');
sspa_t(is_array($fixture) && 'State Fixture' === $fixture['label'], 'the listing carries the label the plugin gave itself');
sspa_t(is_array($fixture) && !empty($fixture['declared']), 'the listing knows the plugin declared what it publishes');
sspa_t(
    is_array($fixture) && in_array('which of its optimisations are switched on', $fixture['publishes'], true),
    'the listing carries the plain-English lines'
);
sspa_t(is_array($fixture) && !empty($fixture['enabled']), 'a publisher is on by default - the plugin author already opted in');

// An undeclared publisher must still be listed, and must be visibly undeclared. Refusing to
// publish it would mean a plugin author's omission silently removes configuration from a payload
// the owner already agreed to.
$sspa_silent = function ($records) {
    $records[] = array(
        'component' => array('type' => 'plugin', 'slug' => 'sspa-silent-fixture'),
        'state_schema_version' => 1,
        'options' => array('setting' => 'enabled'),
    );
    return $records;
};
add_filter('sspa_component_state', $sspa_silent, 10, 2);

$silent = null;
foreach (SSPA_Community_State::publishers() as $entry) {
    if ('sspa-silent-fixture' === $entry['slug']) {
        $silent = $entry;
    }
}
sspa_t(is_array($silent), 'a publisher that declared nothing is still listed');
sspa_t(is_array($silent) && empty($silent['declared']), 'and is marked as having declared nothing');
sspa_t(is_array($silent) && 'sspa-silent-fixture' === $silent['label'], 'and falls back to its slug so the row can still be identified');

remove_filter('sspa_component_state', $sspa_silent, 10);

// Now switch the fixture off and run.
SSPA_Community_State::set_enabled('sspa-state-fixture', false);
sspa_t(in_array('sspa-state-fixture', SSPA_Community_State::disabled(), true), 'switching a publisher off records the decision');

$off = null;
foreach (SSPA_Community_State::publishers() as $entry) {
    if ('sspa-state-fixture' === $entry['slug']) {
        $off = $entry;
    }
}
sspa_t(is_array($off) && empty($off['enabled']), 'a switched-off publisher is still listed, so the decision can be reversed');

$run_id = SSPA_Run_Controller::start(array('type' => 'adhoc', 'url' => home_url('/'), 'trigger' => 'cli', 'user_id' => 1));
$deadline = time() + 180;
do {
    SSPA_Run_Controller::process_batch($run_id);
    $status = SSPA_Run_Controller::status($run_id);
} while ($status && in_array($status['status'], array('queued', 'crawling', 'analysing'), true) && time() < $deadline);

// Captured, not exported: the run's own stored context must not mention it either.
global $wpdb;
$stored = (string) $wpdb->get_var($wpdb->prepare(
    'SELECT share_context FROM ' . SSPA_Schema::table('runs') . ' WHERE id = %d',
    (int) $run_id
));
sspa_t(false === strpos($stored, 'sspa-state-fixture'), 'a switched-off publisher is never even stored on the run');

$payload = SSPA_Community_Exporter::build($run_id);
sspa_t(null === sspa_state_record($payload, 'sspa-state-fixture'), 'and never reaches the payload');
sspa_t(!is_wp_error($payload), 'the payload still builds with a publisher switched off');

// Switching it back on restores it, or the control is a one-way door.
SSPA_Community_State::set_enabled('sspa-state-fixture', true);
sspa_t(!in_array('sspa-state-fixture', SSPA_Community_State::disabled(), true), 'switching it back on clears the decision');

$run_id = SSPA_Run_Controller::start(array('type' => 'adhoc', 'url' => home_url('/'), 'trigger' => 'cli', 'user_id' => 1));
$deadline = time() + 180;
do {
    SSPA_Run_Controller::process_batch($run_id);
    $status = SSPA_Run_Controller::status($run_id);
} while ($status && in_array($status['status'], array('queued', 'crawling', 'analysing'), true) && time() < $deadline);

$payload = SSPA_Community_Exporter::build($run_id);
sspa_t(is_array(sspa_state_record($payload, 'sspa-state-fixture')), 'and the publisher is captured again on the next run');

// The consent text is not payload data and must not travel.
$json = is_wp_error($payload) ? '' : SSPA_Community_Schema::encode($payload);
sspa_t(is_string($json) && false === strpos($json, 'which of its optimisations'), 'the plain-English declaration is never submitted');
sspa_t(is_string($json) && false === strpos($json, 'disclosure'), 'and the disclosure block is stripped from the record entirely');

remove_filter('sspa_component_state', $sspa_good, 10);
delete_option('sspa_component_state_disabled');
