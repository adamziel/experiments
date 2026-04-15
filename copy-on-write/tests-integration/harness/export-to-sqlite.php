<?php
/**
 * Export the wptest MariaDB into a single SQLite file.
 *
 * Solution 2 reads a SQLite file at the binary level, so it needs real
 * SQLite input. We reuse Solution 3's SchemaTranslator to turn each
 * table's MySQL DDL into SQLite DDL, then stream all rows across.
 *
 * Output: /tmp/cow-wordpress-export.sqlite
 *
 * Idempotent: if the file exists and contains wp_options.siteurl we skip.
 */

$harness = __DIR__;
$envFile = $harness . '/.env';
if (!file_exists($envFile)) {
    fwrite(STDERR, "harness/.env missing\n");
    exit(1);
}
$env = parse_ini_file($envFile);
$host = $env['HOST'] ?? '127.0.0.1';
$port = (int) ($env['PORT'] ?? 0);
$user = $env['USER'] ?? 'root';
$pass = $env['PASSWORD'] ?? '';
$db   = $env['DBNAME'] ?? 'wptest';

$outPath = '/tmp/cow-wordpress-export.sqlite';

// Load Solution 3's SchemaTranslator.
require_once __DIR__ . '/../../solution-3-table-materialization/src/SchemaTranslator.php';

// Fast path.
if (file_exists($outPath)) {
    try {
        $q = new PDO('sqlite:' . $outPath);
        $row = $q->query("SELECT option_value FROM wp_options WHERE option_name='siteurl'")->fetch();
        if ($row && !empty($row['option_value'])) {
            echo "Export already present at {$outPath}\n";
            exit(0);
        }
    } catch (Throwable $_) { /* fall through */ }
    @unlink($outPath);
}

echo "Exporting MariaDB {$db} -> SQLite {$outPath}...\n";

$mysqlPdo = new PDO("mysql:host={$host};port={$port};dbname={$db};charset=utf8mb4",
    $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Critical: solution 2's BTreeReader only handles rollback journal mode and
// assumes a single-file SQLite database (no WAL, no -wal, no -shm). Force
// DELETE journal, disable WAL, and checkpoint on close so the file is
// self-contained.
$sqlitePdo = new PDO('sqlite:' . $outPath);
$sqlitePdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$sqlitePdo->exec('PRAGMA journal_mode = DELETE');
$sqlitePdo->exec('PRAGMA synchronous = OFF');

$translator = new \CowClone\SchemaTranslator();

$tables = $mysqlPdo->prepare(
    "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :db ORDER BY TABLE_NAME"
);
$tables->execute([':db' => $db]);
$tableList = $tables->fetchAll(PDO::FETCH_COLUMN);

foreach ($tableList as $t) {
    // Fetch MySQL CREATE TABLE.
    $createRow = $mysqlPdo->query("SHOW CREATE TABLE `{$t}`")->fetch(PDO::FETCH_ASSOC);
    $mysqlDdl = $createRow['Create Table'];

    // Translate to SQLite.
    $sqliteDdl = $translator->translateCreateTable($mysqlDdl);

    // Execute DDL (may contain multiple statements separated by ;)
    foreach (array_filter(array_map('trim', explode(';', $sqliteDdl))) as $stmt) {
        if ($stmt !== '') {
            $sqlitePdo->exec($stmt);
        }
    }

    // Stream rows.
    $rows = $mysqlPdo->query("SELECT * FROM `{$t}`");
    $firstRow = $rows->fetch(PDO::FETCH_ASSOC);
    if ($firstRow === false) {
        continue;
    }
    $cols = array_keys($firstRow);
    $colsSql = implode(', ', array_map(fn($c) => '"' . $c . '"', $cols));
    $placeholders = implode(', ', array_fill(0, count($cols), '?'));
    $insertSql = "INSERT INTO \"{$t}\" ({$colsSql}) VALUES ({$placeholders})";
    $ins = $sqlitePdo->prepare($insertSql);

    $sqlitePdo->beginTransaction();
    $count = 0;
    $insertOne = function ($row) use ($ins, $cols) {
        $vals = [];
        foreach ($cols as $c) {
            $vals[] = $row[$c] ?? null;
        }
        $ins->execute($vals);
    };
    $insertOne($firstRow);
    $count++;
    while (($row = $rows->fetch(PDO::FETCH_ASSOC)) !== false) {
        $insertOne($row);
        $count++;
    }
    $sqlitePdo->commit();
    echo "  {$t}: {$count}\n";
}

$sqlitePdo = null;
echo "Done. " . filesize($outPath) . " bytes at {$outPath}\n";
