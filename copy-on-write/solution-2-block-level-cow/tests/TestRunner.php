<?php

declare(strict_types=1);

echo "=== Solution 2: SQLite Binary Format Page-Level COW ===\n";
echo "=== Test Suite ===\n\n";

require_once __DIR__ . '/CowPageProviderTest.php';
require_once __DIR__ . '/BTreeReaderTest.php';
require_once __DIR__ . '/IntegrationTest.php';
require_once __DIR__ . '/StartupTimeTest.php';

$totalPassed = 0;
$totalFailed = 0;

$tests = [
    'CowPageProviderTest' => new CowPageProviderTest(),
    'BTreeReaderTest' => new BTreeReaderTest(),
    'IntegrationTest' => new IntegrationTest(),
    'StartupTimeTest' => new StartupTimeTest(),
];

foreach ($tests as $name => $test) {
    echo "[{$name}]\n";
    $test->run();
    [$passed, $failed] = $test->getResults();
    $status = $failed === 0 ? 'PASS' : 'FAIL';
    echo "  Result: {$passed} passed, {$failed} failed [{$status}]\n\n";
    $totalPassed += $passed;
    $totalFailed += $failed;
}

echo "==========================================\n";
echo "Total: {$totalPassed} passed, {$totalFailed} failed\n";
echo "STATUS: " . ($totalFailed === 0 ? "ALL TESTS PASSED" : "SOME TESTS FAILED") . "\n";

exit($totalFailed > 0 ? 1 : 0);
