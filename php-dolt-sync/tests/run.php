<?php
declare(strict_types=1);
/**
 * Test runner. Discovers tests/*Test.php files and runs assertions.
 * Zero-dependency: uses a tiny in-file test framework.
 */

require __DIR__ . '/../plugin/wp-sync/src/autoload.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'WpSync\\Test\\';
    if (!str_starts_with($class, $prefix)) return;
    $rel = substr($class, strlen($prefix));
    $path = __DIR__ . '/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($path)) require $path;
});

final class TestRun {
    public static int $pass = 0;
    public static int $fail = 0;
    public static array $currentGroup = [];

    public static function group(string $name, callable $fn): void {
        echo "\n=== {$name} ===\n";
        self::$currentGroup[] = $name;
        try { $fn(); } catch (\Throwable $e) {
            self::$fail++;
            echo "  FAIL (uncaught): " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
        }
        array_pop(self::$currentGroup);
    }

    public static function it(string $name, callable $fn): void {
        try {
            $fn();
            self::$pass++;
            echo "  ok: {$name}\n";
        } catch (\Throwable $e) {
            self::$fail++;
            echo "  FAIL: {$name}\n    " . $e->getMessage() . "\n";
        }
    }
}

function assertTrue(bool $cond, string $msg = ''): void {
    if (!$cond) throw new \RuntimeException('assertTrue: ' . $msg);
}
function assertEq(mixed $expected, mixed $actual, string $msg = ''): void {
    if ($expected !== $actual) {
        throw new \RuntimeException("assertEq failed ({$msg}): expected=" . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}
function assertNotEq(mixed $a, mixed $b, string $msg = ''): void {
    if ($a === $b) throw new \RuntimeException('assertNotEq failed: ' . $msg);
}
function assertThrows(callable $fn, string $msg = ''): \Throwable {
    try { $fn(); } catch (\Throwable $e) { return $e; }
    throw new \RuntimeException('assertThrows: no exception thrown: ' . $msg);
}

$dir = __DIR__;
$tests = [
    $dir . '/Unit/CanonicalEncoderTest.php',
    $dir . '/Unit/CborTestVectorsTest.php',
    $dir . '/Unit/SQLiteChunkStoreTest.php',
    $dir . '/Unit/SQLiteRefStoreTest.php',
    $dir . '/Unit/TableTreeTest.php',
    $dir . '/Unit/TreeDiffTest.php',
    $dir . '/Unit/SyncEngineTest.php',
    $dir . '/Unit/RowNormalizerTest.php',
    $dir . '/Unit/SerializedPhpHandlerTest.php',
    $dir . '/Integration/SnapshotRoundTripTest.php',
    $dir . '/Integration/SyncBetweenStoresTest.php',
    $dir . '/Integration/IncrementalSyncTest.php',
    $dir . '/Integration/DirtyTrackingTest.php',
    $dir . '/Integration/FailureModesTest.php',
    $dir . '/EndToEnd/FullPushPullCycleTest.php',
    $dir . '/EndToEnd/ConflictDetectionTest.php',
];

foreach ($tests as $f) {
    if (is_file($f)) require $f;
}

echo "\n--- TOTAL: " . TestRun::$pass . " passed, " . TestRun::$fail . " failed ---\n";
exit(TestRun::$fail === 0 ? 0 : 1);
