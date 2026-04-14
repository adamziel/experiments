<?php
declare(strict_types=1);

use WpSync\Snapshot\RowNormalizer;
use WpSync\Encoding\CanonicalEncoder;

TestRun::group('RowNormalizer', function () {
    TestRun::it('columns encoded in alphabetical order', function () {
        $row = ['z' => 1, 'a' => 2, 'm' => 3];
        $norm = RowNormalizer::normalizeForEncode($row, 'https://a.test');
        assertEq(['a', 'm', 'z'], array_keys($norm));
    });

    TestRun::it('plain URL rewritten to placeholder and back', function () {
        $row = ['post_content' => '<img src="https://site-a.test/p.jpg">'];
        $n = RowNormalizer::normalizeForEncode($row, 'https://site-a.test');
        assertEq('<img src="{{SITE_URL}}/p.jpg">', $n['post_content']);
        $r = RowNormalizer::denormalizeForApply($n, 'https://site-b.test');
        assertEq('<img src="https://site-b.test/p.jpg">', $r['post_content']);
    });

    TestRun::it('serialized PHP with URL of different lengths roundtrips', function () {
        $value = serialize(['url' => 'https://site-a.test/img.png', 'n' => 1]);
        $row = ['option_value' => $value];
        // Encode as if on site A
        $n = RowNormalizer::normalizeForEncode($row, 'https://site-a.test');
        // Decode on site B with *shorter* URL: length prefixes must be right.
        $r = RowNormalizer::denormalizeForApply($n, 'https://b.test');
        $u = unserialize($r['option_value']);
        assertEq('https://b.test/img.png', $u['url']);
        assertEq(1, $u['n']);
    });

    TestRun::it('GMT columns drop their non-GMT siblings', function () {
        $row = [
            'post_date' => '2024-01-01 12:00:00',
            'post_date_gmt' => '2024-01-01 12:00:00',
            'title' => 'x',
        ];
        $n = RowNormalizer::normalizeForEncode($row, 'https://a.test');
        assertTrue(!isset($n['post_date']));
        assertTrue(isset($n['post_date_gmt']));
    });

    TestRun::it('excluded options list', function () {
        assertTrue(RowNormalizer::isExcludedOption('siteurl'));
        assertTrue(RowNormalizer::isExcludedOption('home'));
        assertTrue(RowNormalizer::isExcludedOption('_transient_foo'));
        assertTrue(RowNormalizer::isExcludedOption('_site_transient_bar'));
        assertTrue(!RowNormalizer::isExcludedOption('regular_option'));
    });

    TestRun::it('two sites with different insertion orders produce equal hashes', function () {
        // Site A: option serialized with keys in order [b,a]
        $valA = serialize(['b' => 1, 'a' => 2]);
        $valB = serialize(['a' => 2, 'b' => 1]);
        $rowA = ['option_name' => 'foo', 'option_value' => $valA];
        $rowB = ['option_name' => 'foo', 'option_value' => $valB];
        $nA = RowNormalizer::normalizeForEncode($rowA, 'https://a.test');
        $nB = RowNormalizer::normalizeForEncode($rowB, 'https://b.test');
        assertEq(CanonicalEncoder::hash($nA), CanonicalEncoder::hash($nB));
    });

    TestRun::it('NULL distinct from empty string', function () {
        $r1 = ['c' => null];
        $r2 = ['c' => ''];
        $n1 = RowNormalizer::normalizeForEncode($r1, 'https://a.test');
        $n2 = RowNormalizer::normalizeForEncode($r2, 'https://a.test');
        assertNotEq(CanonicalEncoder::hash($n1), CanonicalEncoder::hash($n2));
    });
});
