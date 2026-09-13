<?php
require __DIR__ . '/../lib/fleet.php';
// Exercise a legitimate same-driver renewal in one second, as fast browser jobs do.
$key = 'sspa_test_browser_lease_' . wp_rand();
$owner = wp_generate_uuid4();
while (microtime(true) - floor(microtime(true)) > 0.1) usleep(1000);
$second = time();
$first = SSPA_Atomic_Claim::acquire($key, 45, $owner);
$renewed = SSPA_Atomic_Claim::acquire($key, 45, $owner);
sspa_fleet_assert(time() === $second, 'renewal control executes within the same wall-clock second');
sspa_fleet_assert($first === $owner, 'first real browser-driver lease succeeds');
sspa_fleet_assert($renewed === $owner, 'same driver can renew its unexpired lease immediately');
sspa_fleet_assert(SSPA_Atomic_Claim::acquire($key, 45, wp_generate_uuid4()) === false, 'different driver remains excluded');
sspa_fleet_done();
