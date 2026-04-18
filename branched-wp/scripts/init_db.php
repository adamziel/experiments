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

// Cap WAL growth under heavy write bursts (SFTP upload + multi-branch
// merges + concurrent HTTP). The default 1000-page threshold (~4 MB)
// lets the WAL balloon to many megabytes between writes; 500 keeps the
// file closer to 2 MB. See PRD F10 (WAL management) for details.
$db->exec('PRAGMA wal_autocheckpoint = 500');

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
