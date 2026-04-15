<?php
/**
 * BranchFS router for PHP built-in server.
 *
 * Branch resolution:
 *   - <root-domain>      -> main
 *   - <sub>.<root-domain> -> branch "<sub>"
 *
 * The root domain is BRANCHFS_ROOT_HOST (default: wp.localhost).
 * No cookies, no signed tokens: the subdomain IS the branch.
 *
 * To try it on a Mac add a wildcard entry to /etc/hosts, e.g.
 *     127.0.0.1   wp.localhost feature.wp.localhost marketing.wp.localhost
 * (or use dnsmasq for true wildcarding) and visit
 *     http://wp.localhost:18080/            -> main
 *     http://feature.wp.localhost:18080/    -> branch "feature"
 */

error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);

$db_path   = getenv('BRANCHFS_DB');
$wp_root   = getenv('BRANCHFS_WP_ROOT');
$root_host = getenv('BRANCHFS_ROOT_HOST') ?: 'wp.localhost';

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

// --- Branch resolution from HTTP Host header ---

$host = $_SERVER['HTTP_HOST'] ?? '';
// Strip :port
$host_noport = preg_replace('/:\d+$/', '', $host);
$host_noport = strtolower($host_noport);
$root_host_lc = strtolower($root_host);

$branch = 'main';
if ($host_noport === $root_host_lc || $host_noport === '' || $host_noport === '127.0.0.1' || $host_noport === 'localhost') {
    $branch = 'main';
} elseif (substr($host_noport, -strlen('.' . $root_host_lc)) === '.' . $root_host_lc) {
    $sub = substr($host_noport, 0, -strlen('.' . $root_host_lc));
    // sub must be a single label (no further dots) and match our name regex
    if (strpos($sub, '.') === false && preg_match('/^[a-zA-Z0-9_\-]{1,63}$/', $sub)) {
        $branch = $sub;
    }
}

// --- Init branchfs ---
branchfs_set_db($db_path);
branchfs_set_root($wp_root);
branchfs_set_branch($branch);
branchfs_activate();

chdir($wp_root);

// Expose branch for mu-plugin / debugging
$_SERVER['BRANCHFS_BRANCH'] = $branch;
header('X-BranchFS-Branch: ' . $branch);

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

if (is_dir($file)) {
    $index = rtrim($file, '/') . '/index.php';
    if (file_exists($index)) {
        $_SERVER['DOCUMENT_ROOT']   = $wp_root;
        $_SERVER['SCRIPT_FILENAME'] = $index;
        require $index;
        return true;
    }
}

$_SERVER['DOCUMENT_ROOT']   = $wp_root;
$_SERVER['SCRIPT_FILENAME'] = $wp_root . '/index.php';
require $wp_root . '/wp-blog-header.php';
return true;
