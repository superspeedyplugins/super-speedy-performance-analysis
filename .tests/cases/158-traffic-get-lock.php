<?php
// The traffic authority lock is MySQL GET_LOCK() since 0.61.6 (.docs/2026-11-05-traffic-lock-get-lock.md):
// a reader takes one of N slot locks, a writer takes every slot. A second PHP process holds one reader
// slot through its own database connection, exactly as an observed visitor request would, and this
// process checks the guarantees against it:
//  - a writer (revoke and the other lifecycle changes) waits for that reader to finish;
//  - other readers do not wait for it, and a reader that lands on the busy slot skips the request
//    after READER_TIMEOUT and is counted, never silently lost;
//  - another site on the same database server (another table prefix) is not blocked by it.

function sspa_t158($ok, $label) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $label . "\n";
    return $ok;
}

global $wpdb;
$base_method = new ReflectionMethod('SSPA_Traffic_Authority', 'base');
$base_method->setAccessible(true);
$slot = $base_method->invoke(null) . ':r3';

/** Hold one named lock in a separate PHP process (its own connection) for $seconds. */
function sspa_t158_hold($name, $seconds) {
    $code = 'global $wpdb; echo $wpdb->get_var($wpdb->prepare("SELECT GET_LOCK(%s, 5)", ' . var_export($name, true) . ')) === "1" ? "HELD\n" : "NOT HELD\n"; sleep(' . (int) $seconds . ');';
    $process = proc_open(array('wp', '--path=' . ABSPATH, 'eval', $code), array(0 => array('pipe', 'r'), 1 => array('pipe', 'w'), 2 => array('pipe', 'w')), $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Cannot start the lock holder process'); }
    fclose($pipes[0]);
    $line = trim((string) fgets($pipes[1]));
    if ('HELD' !== $line) { throw new RuntimeException('The holder process did not get its lock: ' . $line . ' ' . stream_get_contents($pipes[2])); }
    return array($process, $pipes);
}
function sspa_t158_stop($holder) {
    proc_terminate($holder[0]);
    fclose($holder[1][1]); fclose($holder[1][2]);
    proc_close($holder[0]);
}

// 1. A writer waits for an in-flight reader.
$holder = sspa_t158_hold($slot, 3);
$start = microtime(true);
SSPA_Traffic_Authority::synchronized(true, static function() { return null; });
$waited = microtime(true) - $start;
sspa_t158_stop($holder);
sspa_t158($waited >= 1.5 && $waited < SSPA_Traffic_Authority::WRITER_TIMEOUT, sprintf('a writer waits for a reader holding its slot (waited %.2f s for a 3 s reader)', $waited));

// 2. Readers do not wait for one another; one on the busy slot skips and is counted.
$generation = 'case-158-' . bin2hex(random_bytes(4));
$holder = sspa_t158_hold($slot, 60);
$skips_before = SSPA_Traffic_Authority::skips($generation);
$fast = 0; $slow = 0;
for ($i = 0; $i < 16; $i++) {
    $start = microtime(true);
    SSPA_Traffic_Authority::observe(array('generation' => $generation), static function() { return true; });
    $took = microtime(true) - $start;
    if ($took < 0.5) { $fast++; } elseif ($took >= 0.9) { $slow++; }
}
$counted = SSPA_Traffic_Authority::skips($generation) - $skips_before;
sspa_t158($fast >= 1, "readers on other slots proceed without waiting ($fast of 16 under 0.5 s)");
sspa_t158($counted === $slow, "every reader that timed out on the busy slot was counted ($slow slow, $counted counted)");

// 3. Another site on the same server (another table prefix) has its own lock names.
$prefix = $wpdb->prefix;
$wpdb->prefix = 'zz158_';
$start = microtime(true);
try {
    SSPA_Traffic_Authority::synchronized(true, static function() { return null; });
} finally {
    $wpdb->prefix = $prefix;
}
$other_site = microtime(true) - $start;
sspa_t158_stop($holder);
sspa_t158($other_site < 0.5, sprintf('another table prefix is not blocked by this site\'s reader (%.2f s)', $other_site));

// 4. The lock is released after the callback, including when it throws.
try {
    SSPA_Traffic_Authority::synchronized(true, static function() { throw new LogicException('case 158'); });
} catch (LogicException $expected) {}
$free = $wpdb->get_var($wpdb->prepare('SELECT IS_FREE_LOCK(%s)', $base_method->invoke(null) . ':w'));
sspa_t158('1' === (string) $free, 'the writer lock is released when the callback throws');

// 5. Plugin Check's finding is gone: no file handle, no flock().
$calls = array();
$tokens = token_get_all(file_get_contents(SSPA_PLUGIN_DIR . 'includes/traffic/class-sspa-traffic-authority.php'));
foreach ($tokens as $i => $token) {
    // Function calls only: comments mention the old flock() and must not count.
    if (is_array($token) && T_STRING === $token[0] && in_array(strtolower($token[1]), array('fopen', 'flock', 'fclose'), true)) {
        $calls[] = $token[1];
    }
}
sspa_t158(!$calls, 'the authority opens no file and calls no flock()' . ($calls ? ': ' . implode(', ', $calls) : ''));
