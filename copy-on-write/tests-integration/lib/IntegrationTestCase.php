<?php
/**
 * Minimal self-contained test base for integration tests.
 *
 * Keeps the same shape as the unit-test base in each solution (a run() method
 * that returns ['passed'=>int, 'failed'=>int, 'failures'=>string[]]).
 */

namespace CowClone\IntegrationHarness;

use PDO;

abstract class IntegrationTestCase
{
    protected int $passed = 0;
    protected int $failed = 0;
    /** @var string[] */
    protected array $failures = [];

    public function run(): array
    {
        $methods = array_filter(
            get_class_methods($this),
            fn($m) => str_starts_with($m, 'test')
        );
        sort($methods);

        foreach ($methods as $m) {
            try {
                $this->setUp();
                $this->$m();
            } catch (\Throwable $e) {
                $this->failed++;
                $this->failures[] = "{$m}: EXCEPTION " . $e->getMessage()
                    . " @ " . basename($e->getFile()) . ":" . $e->getLine();
            } finally {
                $this->tearDown();
            }
        }

        return [
            'passed'   => $this->passed,
            'failed'   => $this->failed,
            'failures' => $this->failures,
        ];
    }

    protected function setUp(): void {}
    protected function tearDown(): void {}

    protected function assertTrue(bool $cond, string $message = ''): void
    {
        if ($cond) { $this->passed++; return; }
        $this->failed++;
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? '?';
        $this->failures[] = "{$caller}: assertTrue FAILED - {$message}";
    }

    protected function assertFalse(bool $cond, string $message = ''): void
    {
        $this->assertTrue(!$cond, $message);
    }

    protected function assertEquals($expected, $actual, string $message = ''): void
    {
        if ($expected == $actual) { $this->passed++; return; }
        $this->failed++;
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? '?';
        $exp = var_export($expected, true);
        $act = var_export($actual, true);
        $this->failures[] = "{$caller}: expected {$exp} got {$act} - {$message}";
    }

    protected function assertLessThan(float $ceiling, float $actual, string $message = ''): void
    {
        if ($actual < $ceiling) { $this->passed++; return; }
        $this->failed++;
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? '?';
        $this->failures[] = "{$caller}: expected <{$ceiling} got {$actual} - {$message}";
    }

    protected function assertGreaterThan(float $floor, float $actual, string $message = ''): void
    {
        if ($actual > $floor) { $this->passed++; return; }
        $this->failed++;
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'] ?? '?';
        $this->failures[] = "{$caller}: expected >{$floor} got {$actual} - {$message}";
    }

    protected function assertContains($needle, array $haystack, string $message = ''): void
    {
        $this->assertTrue(in_array($needle, $haystack, false), $message ?: "expected " . var_export($needle, true) . " to be in array");
    }
}


/**
 * Load the harness .env and return a PDO to the test DB plus env keys.
 */
function harness_env(): array
{
    $envFile = __DIR__ . '/../harness/.env';
    if (!file_exists($envFile)) {
        throw new \RuntimeException("harness/.env not found. Start MariaDB first.");
    }
    $env = parse_ini_file($envFile);
    if (!isset($env['PORT'])) {
        throw new \RuntimeException("PORT missing from harness/.env");
    }
    return [
        'host' => $env['HOST'] ?? '127.0.0.1',
        'port' => (int) $env['PORT'],
        'user' => $env['USER'] ?? 'root',
        'pass' => $env['PASSWORD'] ?? '',
        'db'   => $env['DBNAME'] ?? 'wptest',
    ];
}

function harness_pdo(): PDO
{
    $e = harness_env();
    return new PDO(
        "mysql:host={$e['host']};port={$e['port']};dbname={$e['db']};charset=utf8mb4",
        $e['user'], $e['pass'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

/**
 * Counters used to verify "zero writes reached the remote".
 *
 * Com_insert/Com_update/Com_delete are global session-level counters on
 * MariaDB; they accumulate across connections. By snapshotting them before
 * and after a block of COW queries, we can assert delta=0.
 *
 * Note: these are GLOBAL counters. Other connections to the same server
 * (e.g. the harness-level PDO used to snapshot) must not issue DML inside
 * the measurement window. We call this via a dedicated admin connection
 * that only runs SHOW GLOBAL STATUS.
 */
function remote_status_snapshot(PDO $pdo): array
{
    $snap = [];
    $stmt = $pdo->query(
        "SHOW GLOBAL STATUS WHERE Variable_name IN
            ('Com_insert','Com_update','Com_delete','Com_replace',
             'Com_select','Bytes_sent','Bytes_received',
             'Com_create_table','Com_drop_table')"
    );
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $snap[$row['Variable_name']] = (int) $row['Value'];
    }
    return $snap;
}

function remote_status_delta(array $before, array $after): array
{
    $out = [];
    foreach ($after as $k => $v) {
        $out[$k] = $v - ($before[$k] ?? 0);
    }
    return $out;
}
