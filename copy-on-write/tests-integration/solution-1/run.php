<?php
/**
 * Integration test runner for Solution 1 (query-level proxy).
 * Runs in its own PHP process so Solution 1's CowClone\CowDatabase class
 * doesn't collide with Solution 3's identically-named class.
 */

$root = dirname(__DIR__, 2);

// Solution 1 sources.
require_once $root . '/solution-1-query-proxy/src/QueryClassifier.php';
require_once $root . '/solution-1-query-proxy/src/LocalOverlayStore.php';
require_once $root . '/solution-1-query-proxy/src/RemoteMySQLConnection.php';
require_once $root . '/solution-1-query-proxy/src/SchemaCache.php';
require_once $root . '/solution-1-query-proxy/src/ResultMerger.php';
require_once $root . '/solution-1-query-proxy/src/CowDatabase.php';

// Shared integration harness.
require_once dirname(__DIR__) . '/lib/IntegrationTestCase.php';
require_once dirname(__DIR__) . '/lib/RealMySQLConnection.php';
require_once __DIR__ . '/Solution1IntegrationTest.php';

$test = new \CowClone\IntegrationHarness\Solution1\Solution1IntegrationTest();
$result = $test->run();

echo "Solution 1 integration: passed={$result['passed']} failed={$result['failed']}\n";
foreach ($result['failures'] as $f) {
    echo "  - {$f}\n";
}

// Emit a machine-readable summary line for the parent to grep.
echo "INTEGRATION_SUMMARY passed={$result['passed']} failed={$result['failed']}\n";
exit($result['failed'] > 0 ? 1 : 0);
