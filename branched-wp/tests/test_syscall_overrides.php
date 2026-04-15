<?php
/**
 * Syscall-bypass override coverage.
 *
 * These are all the PHP functions that *don't* honor stream wrappers
 * (they call libc syscalls directly via VCWD_*). If any of these is
 * un-intercepted, WordPress will hit an "empty response" / "missing
 * file" symptom somewhere non-obvious. Pin coverage here so regressions
 * are caught by the test suite instead of by the browser.
 *
 *  file_exists, realpath, glob
 *  is_file, is_dir, is_readable, is_writable, is_executable, is_link
 *  chmod, chown, chgrp, touch, lstat
 */

$pass = 0; $fail = 0;
function assert_true($cond, $msg) {
    global $pass, $fail;
    if ($cond) { echo "  PASS: $msg\n"; $pass++; }
    else       { echo "  FAIL: $msg\n"; $fail++; }
}

$DB   = '/tmp/branchfs_sc_' . getmypid() . '.db';
$ROOT = '/tmp/branchfs_sc_root_' . getmypid();
@unlink($DB);

$db = new SQLite3($DB);
$db->exec(file_get_contents(__DIR__ . '/../sql/schema.sql'));
$db->close();

branchfs_set_db($DB);
branchfs_set_root($ROOT);
branchfs_set_branch('main');
branchfs_create_branch('main', null);

// Seed files via the explicit branchfs:// wrapper before activation.
// Create the parent dirs as explicit dir entries first, matching what
// import_wp.php does when bringing a real WP tree into the store.
$dirs = [
    'wp-admin', 'wp-admin/css',
    'wp-content', 'wp-content/themes', 'wp-content/themes/example',
    'wp-content/themes/example/patterns',
    'wp-content/uploads', 'wp-content/uploads/2026', 'wp-content/uploads/2026/04',
];
foreach ($dirs as $d) {
    mkdir("branchfs://main/$d", 0755, true);
}
file_put_contents("branchfs://main/wp-admin/css/colors-fresh.css", "body{color:red}");
file_put_contents("branchfs://main/wp-content/themes/example/patterns/home.php", "<?php // home pattern\n");
file_put_contents("branchfs://main/wp-content/themes/example/patterns/archive.php", "<?php // archive\n");
file_put_contents("branchfs://main/wp-content/themes/example/patterns/single.html", "not-php");
file_put_contents("branchfs://main/wp-content/themes/example/style.css", ".a{}");

branchfs_activate();

echo "=== syscall-bypass overrides ===\n";

$css = "$ROOT/wp-admin/css/colors-fresh.css";

echo "# file_exists / realpath\n";
assert_true(file_exists($css),                 'file_exists: branchfs file found');
assert_true(!file_exists("$ROOT/nope.css"),    'file_exists: missing file absent');
assert_true(realpath($css) === $css,           'realpath: returns canonical path');
assert_true(realpath("$ROOT/wp-admin/./css/../css/colors-fresh.css") === $css,
                                                'realpath: collapses . and ..');

echo "# stat family (is_file/is_dir/is_readable/is_writable/is_executable/is_link)\n";
assert_true(is_file($css),                      'is_file:    true for file');
assert_true(!is_file("$ROOT/wp-admin"),         'is_file:    false for dir');
assert_true(is_dir("$ROOT/wp-admin"),           'is_dir:     true for dir');
assert_true(!is_dir($css),                      'is_dir:     false for file');
assert_true(is_readable($css),                  'is_readable: file');
assert_true(is_readable("$ROOT/wp-admin"),      'is_readable: dir');
assert_true(is_writable($css),                  'is_writable: existing file');
assert_true(is_writable("$ROOT/wp-content/uploads"),
                                                'is_writable: dir');
assert_true(is_writable("$ROOT/wp-content/uploads/future.jpg"),
                                                'is_writable: nonexistent under wp_root (WP pattern)');
assert_true(!is_executable($css),               'is_executable: false for file');
assert_true(is_executable("$ROOT/wp-admin"),    'is_executable: true for dir (traversable)');
assert_true(is_link($css) === false,            'is_link: false for file');
assert_true(is_link("$ROOT/wp-admin") === false,'is_link: false for dir');

echo "# glob\n";
$g = glob("$ROOT/wp-content/themes/example/patterns/*.php");
assert_true(is_array($g),                       'glob: returns array');
assert_true(count($g) === 2,                    'glob *.php count: ' . count($g));
$names = array_map('basename', $g);
sort($names);
assert_true($names === ['archive.php','home.php'], 'glob lexically sorted');
$all = glob("$ROOT/wp-content/themes/example/patterns/*");
assert_true(count($all) === 3,                  'glob * (no ext) count: ' . count($all));
$none = glob("$ROOT/wp-content/nonexistent-dir/*.php");
assert_true($none === [] || $none === false,    'glob on missing dir: [] or false');

echo "# chmod/chown/chgrp\n";
assert_true(chmod($css, 0644) === true,         'chmod: no-op returns true');
assert_true(chmod("$ROOT/wp-admin", 0755) === true, 'chmod on dir: true');
assert_true(@chown($css, 'nobody') === true,    'chown: no-op returns true');
assert_true(@chgrp($css, 'nobody') === true,    'chgrp: no-op returns true');

echo "# touch\n";
assert_true(touch($css) === true,               'touch existing: true');
$new_touch = "$ROOT/wp-content/uploads/touched.txt";
assert_true(touch($new_touch) === true,         'touch new: creates file');
assert_true(file_exists($new_touch),            'touch: file now exists');

echo "# lstat / stat\n";
$s = lstat($css);
assert_true(is_array($s) && isset($s['size']),  'lstat returns stat array');
assert_true($s['size'] === strlen('body{color:red}'), 'lstat size matches');
$s2 = stat($css);
assert_true(is_array($s2) && $s2['size'] === $s['size'], 'stat agrees with lstat');
assert_true(filesize($css) === strlen('body{color:red}'), 'filesize works');

echo "# path outside wp_root falls through to OS\n";
assert_true(file_exists('/etc/hostname') || file_exists('/etc/passwd'),
                                                'file_exists outside wp_root defers to OS');
$os_glob = glob('/etc/*.conf');
assert_true($os_glob !== false,                 'glob outside wp_root defers to OS');

branchfs_deactivate();
@unlink($DB); @unlink($DB . '-wal'); @unlink($DB . '-shm');

echo "\n=== syscall overrides: $pass passed, $fail failed ===\n";
exit($fail ? 1 : 0);
