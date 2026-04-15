<?php
/**
 * Integration test runner for Solution 2 (SQLite page-level COW).
 */

$root = dirname(__DIR__, 2);

// Solution 2 sources.
require_once $root . '/solution-2-block-level-cow/src/PageProvider.php';
require_once $root . '/solution-2-block-level-cow/src/FilePageProvider.php';
require_once $root . '/solution-2-block-level-cow/src/CowPageProvider.php';
require_once $root . '/solution-2-block-level-cow/src/BTreeReader.php';
require_once $root . '/solution-2-block-level-cow/src/CowDatabase.php';

require_once dirname(__DIR__) . '/lib/IntegrationTestCase.php';
require_once __DIR__ . '/Solution2IntegrationTest.php';

$test = new \CowClone\IntegrationHarness\Solution2\Solution2IntegrationTest();
$result = $test->run();

echo "Solution 2 integration: passed={$result['passed']} failed={$result['failed']}\n";
foreach ($result['failures'] as $f) {
    echo "  - {$f}\n";
}

echo "INTEGRATION_SUMMARY passed={$result['passed']} failed={$result['failed']}\n";
exit($result['failed'] > 0 ? 1 : 0);
