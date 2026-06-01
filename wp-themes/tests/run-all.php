<?php

$root = dirname(__DIR__);
$themes = [];
$pass = 0;
$fail = 0;
$errors = [];

$theme_dirs = array_filter(glob($root . '/*'), function ($path) {
    return is_dir($path) && basename($path) !== 'tests' && file_exists($path . '/style.css');
});

sort($theme_dirs);

foreach ($theme_dirs as $theme_dir) {
    $slug = basename($theme_dir);
    $themes[] = $slug;
}

if (count($themes) < 10) {
    fwrite(STDERR, "ERROR: Expected 10 themes, found " . count($themes) . ": " . implode(', ', $themes) . "\n");
    exit(1);
}

function assert_check(string $desc, bool $condition, string $detail = ''): void {
    global $pass, $fail, $errors;
    if ($condition) {
        $pass++;
    } else {
        $fail++;
        $msg = "FAIL: $desc";
        if ($detail) $msg .= " — $detail";
        $errors[] = $msg;
        fwrite(STDERR, $msg . "\n");
    }
}

function parse_style_css_headers(string $path): array {
    $content = file_get_contents($path);
    $headers = [];
    if (preg_match_all('/^\s*([A-Za-z ]+?)\s*:\s*(.+)$/m', $content, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $headers[trim($m[1])] = trim($m[2]);
        }
    }
    return $headers;
}

function check_balanced_blocks(string $content, string $file_label): array {
    $issues = [];
    $stack = [];

    // Match all block comments
    preg_match_all('/<!-- (\/?)wp:([a-z0-9-]+(?:\/[a-z0-9-]+)?)\s*(.*?)(\/?)\s*-->/', $content, $matches, PREG_SET_ORDER);

    foreach ($matches as $m) {
        $is_closing = ($m[1] === '/');
        $block_name = $m[2];
        $is_self_closing = ($m[4] === '/');

        if ($is_self_closing) {
            continue;
        }

        if (!$is_closing) {
            $stack[] = $block_name;
        } else {
            if (empty($stack)) {
                $issues[] = "Unexpected closing for wp:$block_name with empty stack";
            } else {
                $top = array_pop($stack);
                if ($top !== $block_name) {
                    $issues[] = "Mismatched block: opened wp:$top but closed wp:$block_name";
                }
            }
        }
    }

    foreach ($stack as $unclosed) {
        $issues[] = "Unclosed block: wp:$unclosed";
    }

    return $issues;
}

function check_pattern_i18n(string $content, string $text_domain): array {
    $issues = [];
    // Find strings in block markup that look like human-readable text
    // We check that text content inside HTML tags uses translation functions
    // Strategy: find PHP echo/print statements with bare strings (not wrapped in __/esc_ functions)

    // Check for bare strings that should be translated
    // Look for PHP strings that contain alphabetic text but aren't in i18n wrappers
    $lines = explode("\n", $content);
    foreach ($lines as $line_num => $line) {
        // Skip comment lines and the header block
        if (preg_match('/^\s*\*/', $line)) continue;
        if (preg_match('/^<\?php/', $line)) continue;
        if (preg_match('/^\s*\?>/', $line)) continue;

        // Check for echo/print with bare strings (not i18n-wrapped)
        if (preg_match('/\becho\s+[\'"]([^\'"]*)[\'"]\s*;/', $line, $em)) {
            $str = $em[1];
            if (preg_match('/[a-zA-Z]{2,}/', $str) && !preg_match('/^(https?:|#|[a-z0-9-]+$|wp:|has-|is-|align)/', $str)) {
                $issues[] = "Line " . ($line_num + 1) . ": bare echo string '$str' not wrapped in i18n function";
            }
        }
    }

    // Check that i18n functions use the correct text domain
    if (preg_match_all("/(__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_x|_ex|esc_html_x|_n)\s*\([^)]*?['\"]([a-z0-9-]+)['\"]\s*\)/", $content, $dm, PREG_SET_ORDER)) {
        foreach ($dm as $d) {
            $found_domain = $d[2];
            // The last quoted string before the closing paren is the domain
            // Re-parse to be more precise
        }
    }

    // More targeted: find all i18n calls and verify the text domain
    if (preg_match_all("/(__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_x|_ex|esc_html_x|_n)\s*\(\s*'[^']*'\s*,\s*'([^']*)'\s*\)/", $content, $dm, PREG_SET_ORDER)) {
        foreach ($dm as $d) {
            if ($d[2] !== $text_domain) {
                $issues[] = "Wrong text domain '{$d[2]}' (expected '$text_domain') in {$d[1]}() call";
            }
        }
    }
    if (preg_match_all('/(__|_e|esc_html__|esc_html_e|esc_attr__|esc_attr_e|_x|_ex|esc_html_x|_n)\s*\(\s*"[^"]*"\s*,\s*\'([^\']*)\'\s*\)/', $content, $dm, PREG_SET_ORDER)) {
        foreach ($dm as $d) {
            if ($d[2] !== $text_domain) {
                $issues[] = "Wrong text domain '{$d[2]}' (expected '$text_domain') in {$d[1]}() call";
            }
        }
    }

    return $issues;
}

$required_headers = [
    'Theme Name', 'Theme URI', 'Author', 'Author URI', 'Description',
    'Version', 'Requires at least', 'Tested up to', 'Requires PHP',
    'License', 'License URI', 'Text Domain', 'Tags'
];

$required_files = [
    'style.css',
    'theme.json',
    'templates/index.html',
    'parts/header.html',
    'parts/footer.html',
    'patterns/hero.php',
];

foreach ($themes as $slug) {
    $dir = $root . '/' . $slug;
    echo "Testing theme: $slug\n";

    // 1. Required files exist
    foreach ($required_files as $rf) {
        assert_check(
            "[$slug] File exists: $rf",
            file_exists($dir . '/' . $rf)
        );
    }

    // Check for at least one style variation
    $style_variations = glob($dir . '/styles/*.json');
    assert_check(
        "[$slug] Has at least 1 style variation in styles/",
        count($style_variations) >= 1,
        "Found " . count($style_variations) . " style variations"
    );

    // 2. Parse style.css headers
    $headers = parse_style_css_headers($dir . '/style.css');
    foreach ($required_headers as $rh) {
        assert_check(
            "[$slug] style.css header '$rh' present and non-empty",
            !empty($headers[$rh]),
            "Value: " . ($headers[$rh] ?? '(missing)')
        );
    }

    // 3. Text Domain matches slug
    assert_check(
        "[$slug] Text Domain matches directory name",
        isset($headers['Text Domain']) && $headers['Text Domain'] === $slug,
        "Text Domain: '" . ($headers['Text Domain'] ?? '(missing)') . "', slug: '$slug'"
    );

    // 4. theme.json validation
    $theme_json_path = $dir . '/theme.json';
    if (file_exists($theme_json_path)) {
        $theme_json_raw = file_get_contents($theme_json_path);
        $theme_json = json_decode($theme_json_raw, true);
        assert_check(
            "[$slug] theme.json is valid JSON",
            $theme_json !== null,
            json_last_error_msg()
        );

        if ($theme_json !== null) {
            assert_check(
                "[$slug] theme.json has \$schema",
                !empty($theme_json['$schema'])
            );
            assert_check(
                "[$slug] theme.json version is 3",
                isset($theme_json['version']) && $theme_json['version'] === 3
            );
            assert_check(
                "[$slug] theme.json has non-empty settings.color.palette",
                !empty($theme_json['settings']['color']['palette'])
            );
            assert_check(
                "[$slug] theme.json has non-empty settings.typography.fontFamilies",
                !empty($theme_json['settings']['typography']['fontFamilies'])
            );
            assert_check(
                "[$slug] theme.json has non-empty settings.typography.fontSizes",
                !empty($theme_json['settings']['typography']['fontSizes'])
            );
            assert_check(
                "[$slug] theme.json has settings.layout.contentSize",
                !empty($theme_json['settings']['layout']['contentSize'])
            );
            assert_check(
                "[$slug] theme.json has settings.layout.wideSize",
                !empty($theme_json['settings']['layout']['wideSize'])
            );
            assert_check(
                "[$slug] theme.json has styles key",
                isset($theme_json['styles'])
            );
        }
    }

    // 5. Style variation JSON validation
    foreach ($style_variations as $sv_path) {
        $sv_name = basename($sv_path);
        $sv_raw = file_get_contents($sv_path);
        $sv_json = json_decode($sv_raw, true);
        assert_check(
            "[$slug] styles/$sv_name is valid JSON",
            $sv_json !== null,
            json_last_error_msg()
        );
        if ($sv_json !== null) {
            assert_check(
                "[$slug] styles/$sv_name has \$schema",
                !empty($sv_json['$schema'])
            );
            assert_check(
                "[$slug] styles/$sv_name version is 3",
                isset($sv_json['version']) && $sv_json['version'] === 3
            );
            assert_check(
                "[$slug] styles/$sv_name has non-empty settings.color.palette",
                !empty($sv_json['settings']['color']['palette'])
            );
            assert_check(
                "[$slug] styles/$sv_name has non-empty settings.typography.fontFamilies",
                !empty($sv_json['settings']['typography']['fontFamilies'])
            );
        }
    }

    // 6. Template/part balanced block check
    $html_files = array_merge(
        glob($dir . '/templates/*.html') ?: [],
        glob($dir . '/parts/*.html') ?: []
    );
    foreach ($html_files as $html_path) {
        $rel = str_replace($dir . '/', '', $html_path);
        $content = file_get_contents($html_path);
        $issues = check_balanced_blocks($content, $rel);
        assert_check(
            "[$slug] $rel has balanced block markup",
            empty($issues),
            implode('; ', $issues)
        );
    }

    // 7. Pattern PHP checks
    $patterns = glob($dir . '/patterns/*.php') ?: [];
    foreach ($patterns as $pattern_path) {
        $pattern_name = basename($pattern_path);

        // PHP lint
        $lint_output = [];
        $lint_code = 0;
        exec('php -l ' . escapeshellarg($pattern_path) . ' 2>&1', $lint_output, $lint_code);
        assert_check(
            "[$slug] patterns/$pattern_name passes php -l",
            $lint_code === 0,
            implode("\n", $lint_output)
        );

        // Pattern header keys
        $pattern_content = file_get_contents($pattern_path);
        assert_check(
            "[$slug] patterns/$pattern_name has Title header",
            (bool)preg_match('/\*\s*Title\s*:/', $pattern_content)
        );
        assert_check(
            "[$slug] patterns/$pattern_name has Slug header",
            (bool)preg_match('/\*\s*Slug\s*:/', $pattern_content)
        );

        // Slug should match theme
        if (preg_match('/\*\s*Slug\s*:\s*(\S+)/', $pattern_content, $sm)) {
            assert_check(
                "[$slug] patterns/$pattern_name Slug starts with theme slug",
                strpos($sm[1], $slug . '/') === 0,
                "Slug: {$sm[1]}"
            );
        }

        // i18n check
        $i18n_issues = check_pattern_i18n($pattern_content, $slug);
        assert_check(
            "[$slug] patterns/$pattern_name i18n strings use correct text domain",
            empty($i18n_issues),
            implode('; ', $i18n_issues)
        );

        // Balanced blocks in pattern
        $block_issues = check_balanced_blocks($pattern_content, "patterns/$pattern_name");
        assert_check(
            "[$slug] patterns/$pattern_name has balanced block markup",
            empty($block_issues),
            implode('; ', $block_issues)
        );
    }
}

echo "\n" . str_repeat('=', 60) . "\n";

$total = $pass + $fail;

if ($fail > 0) {
    echo "FAILED — $fail failures out of $total assertions across " . count($themes) . " themes\n\n";
    echo "Failures:\n";
    foreach ($errors as $e) {
        echo "  $e\n";
    }
    exit(1);
} else {
    echo "ALL TESTS PASSED — " . count($themes) . " themes × " . intval($total / count($themes)) . " checks each = $total total assertions\n";
    exit(0);
}
