<?php
/**
 * BranchFS E2E Router for PHP built-in server.
 *
 * Resolves branch from cookie/header, sets up branchfs interception,
 * and routes requests to WordPress files in the SQLite store.
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);

$db_path = getenv('BRANCHFS_DB');
$wp_root = getenv('BRANCHFS_WP_ROOT');
$secret  = getenv('BRANCHFS_SECRET') ?: 'e2e-test-secret';

if (!$db_path || !$wp_root) {
    http_response_code(500);
    echo "BRANCHFS_DB and BRANCHFS_WP_ROOT env vars required\n";
    return true;
}

if (!extension_loaded('branchfs')) {
    http_response_code(500);
    echo "branchfs extension not loaded\n";
    return true;
}

// --- Branch resolution ---
$branch = 'main';

if (!empty($_SERVER['HTTP_X_BRANCH'])) {
    $h = $_SERVER['HTTP_X_BRANCH'];
    if (preg_match('/^[a-zA-Z0-9_\-\/\.]{1,128}$/', $h)) {
        $branch = $h;
    }
} elseif (!empty($_COOKIE['wp_branch'])) {
    $parts = explode(':', $_COOKIE['wp_branch'], 2);
    if (count($parts) === 2) {
        $expected = hash_hmac('sha256', $parts[0], $secret);
        if (hash_equals($expected, $parts[1])) {
            $branch = $parts[0];
        }
    }
}

// --- Init branchfs ---
branchfs_set_db($db_path);
branchfs_set_root($wp_root);
branchfs_set_branch($branch);
branchfs_activate();

chdir($wp_root);

// --- Route request ---
$uri  = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH) ?: '/';
$file = $wp_root . $path;

if (substr($path, -1) === '/') {
    $file .= 'index.php';
}

if (file_exists($file) && !is_dir($file)) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    if ($ext === 'php') {
        $_SERVER['DOCUMENT_ROOT']   = $wp_root;
        $_SERVER['SCRIPT_FILENAME'] = $file;
        require $file;
        return true;
    }

    $mimes = [
        'css'   => 'text/css',
        'js'    => 'application/javascript',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'jpeg'  => 'image/jpeg',
        'gif'   => 'image/gif',
        'svg'   => 'image/svg+xml',
        'ico'   => 'image/x-icon',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf'   => 'font/ttf',
        'json'  => 'application/json',
        'xml'   => 'application/xml',
        'txt'   => 'text/plain',
        'html'  => 'text/html',
        'map'   => 'application/json',
    ];

    header('Content-Type: ' . ($mimes[$ext] ?? 'application/octet-stream'));
    $content = file_get_contents($file);
    if ($content !== false) {
        header('Content-Length: ' . strlen($content));
        echo $content;
    } else {
        http_response_code(404);
    }
    return true;
}

// If path is a directory, try index.php inside it
if (is_dir($file)) {
    $index = rtrim($file, '/') . '/index.php';
    if (file_exists($index)) {
        $_SERVER['DOCUMENT_ROOT']   = $wp_root;
        $_SERVER['SCRIPT_FILENAME'] = $index;
        require $index;
        return true;
    }
}

// WP front controller for pretty URLs
$_SERVER['DOCUMENT_ROOT']   = $wp_root;
$_SERVER['SCRIPT_FILENAME'] = $wp_root . '/index.php';
require $wp_root . '/wp-blog-header.php';
return true;
