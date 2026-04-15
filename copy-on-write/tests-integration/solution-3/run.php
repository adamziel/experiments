<?php
/**
 * Integration test runner for Solution 3 (table-level materialization).
 */

$root = dirname(__DIR__, 2);

require_once $root . '/solution-3-table-materialization/src/SchemaTranslator.php';
require_once $root . '/solution-3-table-materialization/src/RemoteTableClient.php';
require_once $root . '/solution-3-table-materialization/src/MaterializationTracker.php';
require_once $root . '/solution-3-table-materialization/src/ChangeJournal.php';
require_once $root . '/solution-3-table-materialization/src/TableMaterializer.php';
require_once $root . '/solution-3-table-materialization/src/CowDatabase.php';

require_once dirname(__DIR__) . '/lib/IntegrationTestCase.php';
require_once dirname(__DIR__) . '/lib/RealRemoteTableClient.php';
require_once __DIR__ . '/Solution3IntegrationTest.php';

$test = new \CowClone\IntegrationHarness\Solution3\Solution3IntegrationTest();
$result = $test->run();

echo "Solution 3 integration: passed={$result['passed']} failed={$result['failed']}\n";
foreach ($result['failures'] as $f) {
    echo "  - {$f}\n";
}

echo "INTEGRATION_SUMMARY passed={$result['passed']} failed={$result['failed']}\n";
exit($result['failed'] > 0 ? 1 : 0);
