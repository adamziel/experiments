<?php
declare(strict_types=1);

use WpSync\Snapshot\SerializedPhpHandler;

TestRun::group('SerializedPhpHandler', function () {
    TestRun::it('detects serialized values', function () {
        assertTrue(SerializedPhpHandler::looksSerialized('a:1:{s:1:"a";i:1;}'));
        assertTrue(SerializedPhpHandler::looksSerialized('i:5;'));
        assertTrue(!SerializedPhpHandler::looksSerialized('plain text'));
    });

    TestRun::it('deterministic serialize with sorted keys', function () {
        $a = ['b' => 1, 'a' => 2];
        $b = ['a' => 2, 'b' => 1];
        assertEq(SerializedPhpHandler::serialize($a), SerializedPhpHandler::serialize($b));
    });

    TestRun::it('nested arrays sorted recursively', function () {
        $a = ['x' => ['z' => 1, 'y' => 2], 'w' => 3];
        $b = ['w' => 3, 'x' => ['y' => 2, 'z' => 1]];
        assertEq(SerializedPhpHandler::serialize($a), SerializedPhpHandler::serialize($b));
    });

    TestRun::it('lists preserve order', function () {
        $a = [10, 20, 30];
        $s = SerializedPhpHandler::serialize($a);
        $r = unserialize($s);
        assertEq([10, 20, 30], $r);
    });

    TestRun::it('transformStrings rewrites length prefixes correctly', function () {
        $orig = serialize(['url' => 'https://site-a.test/x', 'other' => 'ok']);
        $xformed = SerializedPhpHandler::transformStrings(
            $orig,
            fn(string $s) => str_replace('https://site-a.test', 'https://b.test', $s),
        );
        // Must still be valid unserialize-able
        $u = unserialize($xformed);
        assertEq('https://b.test/x', $u['url']);
        assertEq('ok', $u['other']);
    });

    TestRun::it('transformStrings handles nested serialized data', function () {
        $inner = serialize(['widget_url' => 'https://site-a.test/img.png']);
        $outer = serialize(['widget' => $inner]);
        $xformed = SerializedPhpHandler::transformStrings(
            $outer,
            fn(string $s) => str_replace('https://site-a.test', 'https://much-longer.example.com', $s),
        );
        // Outer unserialize, then inner (nested serialized inside a string)
        $ol = unserialize($xformed);
        $il = unserialize($ol['widget']);
        assertEq('https://much-longer.example.com/img.png', $il['widget_url']);
    });

    TestRun::it('5 URL length variants all roundtrip correctly', function () {
        $urls = [
            'https://a.test',
            'https://bb.test',
            'https://site-b.test',
            'https://very-long-hostname.example.com',
            'https://x.y',
        ];
        $base = 'https://site-a.test';
        foreach ($urls as $u) {
            $s = serialize(['a' => "prefix {$base}/img.png suffix", 'b' => [$base . '/logo.png']]);
            $x = SerializedPhpHandler::transformStrings($s, fn($str) => str_replace($base, $u, $str));
            $parsed = unserialize($x);
            assertEq("prefix {$u}/img.png suffix", $parsed['a']);
            assertEq("{$u}/logo.png", $parsed['b'][0]);
        }
    });

    TestRun::it('non-serialized plain string: transform applied directly', function () {
        $out = SerializedPhpHandler::transformStrings('abc', fn($s) => strtoupper($s));
        assertEq('ABC', $out);
    });

    TestRun::it('deterministic float repr', function () {
        $f = 1.0 / 3.0;
        $s1 = SerializedPhpHandler::serialize($f);
        $s2 = SerializedPhpHandler::serialize($f);
        assertEq($s1, $s2);
        assertEq($f, unserialize($s1));
    });
});
