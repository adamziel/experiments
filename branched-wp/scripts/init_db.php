<?php
/**
 * Initialize the BranchFS SQLite database with schema and seed data.
 */

$db_path = $argv[1] ?? __DIR__ . '/../branchfs.db';
$schema_path = __DIR__ . '/../sql/schema.sql';

echo "Initializing BranchFS database at: $db_path\n";

$db = new SQLite3($db_path);
$db->exec('PRAGMA journal_mode = WAL');
$db->exec('PRAGMA foreign_keys = ON');

$schema = file_get_contents($schema_path);
if (!$schema) {
    die("ERROR: Cannot read schema from $schema_path\n");
}

$result = $db->exec($schema);
if (!$result) {
    die("ERROR: " . $db->lastErrorMsg() . "\n");
}

echo "Database initialized successfully.\n";
echo "  - 'main' branch created\n";

$db->close();
