<?php
/**
 * forkpress backup — consistent hot-copy of a running site.fp.
 *
 * Uses SQLite's `VACUUM INTO`, which takes a database-level read lock,
 * copies every page into the destination file, and releases it. Safe to
 * run while the server is up: concurrent writers are blocked only for
 * the duration of the copy (seconds), not its aftermath.
 *
 * Usage: php scripts/backup.php <source.fp> <dest.fp>
 */

require_once __DIR__ . '/sqlite_retry.php';

if ($argc < 3) {
    fwrite(STDERR, "Usage: php backup.php <source.fp> <dest.fp>\n");
    exit(1);
}

[$_, $src, $dst] = $argv;

if (!file_exists($src)) {
    fwrite(STDERR, "backup: source not found: $src\n");
    exit(2);
}
if (file_exists($dst)) {
    fwrite(STDERR, "backup: refusing to overwrite existing file: $dst\n");
    exit(2);
}

$dst_dir = dirname($dst);
if ($dst_dir !== '' && !is_dir($dst_dir)) {
    fwrite(STDERR, "backup: destination directory does not exist: $dst_dir\n");
    exit(2);
}

$db = new SQLite3($src, SQLITE3_OPEN_READONLY);
$db->busyTimeout(15000);

try {
    sqlite_retry_busy(function() use ($db, $dst) {
        // VACUUM INTO emits a fully-checkpointed, defragmented copy. The
        // source file is not modified in any way. The -wal / -shm files
        // are not produced at the destination; the result is a single
        // standalone .fp.
        $db->exec("VACUUM INTO '" . SQLite3::escapeString($dst) . "'");
    });
} catch (\Throwable $e) {
    fwrite(STDERR, "backup: VACUUM INTO failed: " . $e->getMessage() . "\n");
    $db->close();
    exit(3);
}
$db->close();

if (!file_exists($dst)) {
    fwrite(STDERR, "backup: destination was not created: $dst\n");
    exit(3);
}

printf("backup: %s -> %s (%d bytes)\n", $src, $dst, filesize($dst));
