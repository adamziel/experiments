<?php

/**
 * Simple test runner for Solution 3: Table-Level Lazy Materialization.
 *
 * Usage: php tests/TestRunner.php
 */

// Ensure we're running from the project root or tests directory
$baseDir = __DIR__ . '/..';

// Load all source files
require_once $baseDir . '/src/SchemaTranslator.php';
require_once $baseDir . '/src/RemoteTableClient.php';
require_once $baseDir . '/src/MaterializationTracker.php';
require_once $baseDir . '/src/ChangeJournal.php';
require_once $baseDir . '/src/TableMaterializer.php';
require_once $baseDir . '/src/CowDatabase.php';

// Load test files
require_once __DIR__ . '/SchemaTranslatorTest.php';
require_once __DIR__ . '/ChangeJournalTest.php';
require_once __DIR__ . '/IntegrationTest.php';
require_once __DIR__ . '/StartupTimeTest.php';

echo "=== Solution 3: Table-Level Lazy Materialization with Change Journal ===\n\n";

$totalPassed = 0;
$totalFailed = 0;

$suites = [
    'SchemaTranslatorTest' => new \CowClone\Tests\SchemaTranslatorTest(),
    'ChangeJournalTest'    => new \CowClone\Tests\ChangeJournalTest(),
    'IntegrationTest'      => new \CowClone\Tests\IntegrationTest(),
    'StartupTimeTest'      => new \CowClone\Tests\StartupTimeTest(),
];

foreach ($suites as $name => $suite) {
    echo "[{$name}]\n";
    $result = $suite->run();
    $totalPassed += $result['passed'];
    $totalFailed += $result['failed'];
    $status = $result['failed'] === 0 ? 'PASS' : 'FAIL';
    echo "  Result: {$result['passed']} passed, {$result['failed']} failed [{$status}]\n\n";
}

echo "==========================================\n";
echo "Total: {$totalPassed} passed, {$totalFailed} failed\n";

if ($totalFailed > 0) {
    echo "STATUS: FAILED\n";
    exit(1);
} else {
    echo "STATUS: ALL TESTS PASSED\n";
    exit(0);
}
