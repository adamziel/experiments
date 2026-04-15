<?php

/**
 * Simple test runner for Copy-on-Write Clone tests.
 * Discovers and runs all test classes, reports pass/fail.
 *
 * Usage: php tests/TestRunner.php
 */

// Ensure we're in the right directory
$baseDir = __DIR__ . '/..';

// Load all source files
require_once $baseDir . '/src/QueryClassifier.php';
require_once $baseDir . '/src/LocalOverlayStore.php';
require_once $baseDir . '/src/RemoteMySQLConnection.php';
require_once $baseDir . '/src/SchemaCache.php';
require_once $baseDir . '/src/ResultMerger.php';
require_once $baseDir . '/src/CowDatabase.php';

// Load all test files
require_once __DIR__ . '/QueryClassifierTest.php';
require_once __DIR__ . '/ResultMergerTest.php';
require_once __DIR__ . '/IntegrationTest.php';
require_once __DIR__ . '/StartupTimeTest.php';

$testClasses = [
    CowClone\Tests\QueryClassifierTest::class,
    CowClone\Tests\ResultMergerTest::class,
    CowClone\Tests\IntegrationTest::class,
    CowClone\Tests\StartupTimeTest::class,
];

$totalPassed = 0;
$totalFailed = 0;
$allFailures = [];

echo "=== Copy-on-Write Clone - Test Suite ===\n\n";

foreach ($testClasses as $testClass) {
    $shortName = (new ReflectionClass($testClass))->getShortName();
    echo "Running {$shortName}...\n";

    $test = new $testClass();
    $result = $test->run();

    $totalPassed += $result['passed'];
    $totalFailed += $result['failed'];

    if ($result['failed'] > 0) {
        echo "  FAILED: {$result['passed']} passed, {$result['failed']} failed\n";
        foreach ($result['failures'] as $failure) {
            echo "    - {$failure}\n";
            $allFailures[] = "[{$shortName}] {$failure}";
        }
    } else {
        echo "  PASSED: {$result['passed']} assertions\n";
    }
}

echo "\n=== Results ===\n";
echo "Total: " . ($totalPassed + $totalFailed) . " assertions\n";
echo "Passed: {$totalPassed}\n";
echo "Failed: {$totalFailed}\n";

if ($totalFailed > 0) {
    echo "\nFailures:\n";
    foreach ($allFailures as $i => $failure) {
        echo "  " . ($i + 1) . ". {$failure}\n";
    }
    echo "\nRESULT: FAIL\n";
    exit(1);
} else {
    echo "\nRESULT: ALL TESTS PASSED\n";
    exit(0);
}
