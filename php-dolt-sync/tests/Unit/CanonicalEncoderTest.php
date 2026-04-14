<?php
declare(strict_types=1);

use WpSync\Encoding\CanonicalEncoder;

TestRun::group('CanonicalEncoder', function () {
    TestRun::it('primitive encodings', function () {
        assertEq('00', bin2hex(CanonicalEncoder::encode(0)));
        assertEq('17', bin2hex(CanonicalEncoder::encode(23)));
        assertEq('1864', bin2hex(CanonicalEncoder::encode(100)));
        assertEq('20', bin2hex(CanonicalEncoder::encode(-1)));
        assertEq('f4', bin2hex(CanonicalEncoder::encode(false)));
        assertEq('f5', bin2hex(CanonicalEncoder::encode(true)));
        assertEq('f6', bin2hex(CanonicalEncoder::encode(null)));
        assertEq('80', bin2hex(CanonicalEncoder::encode([])));
    });

    TestRun::it('determinism across 1000 encodings of same complex value', function () {
        $v = ['z' => 1, 'a' => ['b' => 2, 'a' => ['x' => null, 'w' => [1,2,3]]]];
        $first = CanonicalEncoder::encode($v);
        for ($i = 0; $i < 1000; $i++) {
            assertEq($first, CanonicalEncoder::encode($v));
        }
    });

    TestRun::it('map keys sort length-first', function () {
        $a = ['aa' => 1, 'b' => 2];
        $b = ['b' => 2, 'aa' => 1];
        assertEq(CanonicalEncoder::encode($a), CanonicalEncoder::encode($b));
        // 'b' is shorter -> comes first
        $enc = CanonicalEncoder::encode($a);
        // a2 (map len 2) + 61 'b' + 02 + 62 61 'aa' + 01
        // map(2) | 'b'(61 62) | 2(02) | 'aa'(62 61 61) | 1(01)
        assertEq('a261620262616101', bin2hex($enc));
    });

    TestRun::it('round trip nested', function () {
        $v = ['x' => [1, 2.5, 'hello', true, null, [-1, -2]]];
        $dec = CanonicalEncoder::decode(CanonicalEncoder::encode($v));
        assertEq($v, $dec);
    });

    TestRun::it('NaN canonical', function () {
        $a = CanonicalEncoder::encode(NAN);
        assertEq('fb7ff8000000000000', bin2hex($a));
    });

    TestRun::it('negative zero normalizes', function () {
        assertEq(CanonicalEncoder::encode(0.0), CanonicalEncoder::encode(-0.0));
    });

    TestRun::it('integer-valued float coerces', function () {
        assertEq(CanonicalEncoder::encode(3), CanonicalEncoder::encode(3.0));
        assertEq(CanonicalEncoder::encode(-1), CanonicalEncoder::encode(-1.0));
    });

    TestRun::it('null vs empty string distinct', function () {
        assertNotEq(CanonicalEncoder::hash(null), CanonicalEncoder::hash(''));
        assertNotEq(CanonicalEncoder::hash(null), CanonicalEncoder::hash(0));
        assertNotEq(CanonicalEncoder::hash(null), CanonicalEncoder::hash(false));
    });

    TestRun::it('UTF-8 NFC normalization', function () {
        $nfd = "cafe\xcc\x81";
        $nfc = "caf\xc3\xa9";
        assertEq(CanonicalEncoder::hash($nfd), CanonicalEncoder::hash($nfc));
    });

    TestRun::it('invalid UTF-8 rejected', function () {
        assertThrows(fn() => CanonicalEncoder::encode("\xff\xfe_bad"));
    });

    TestRun::it('float 1/3 round-trips deterministically', function () {
        $f = 1.0 / 3.0;
        $enc1 = CanonicalEncoder::encode($f);
        $enc2 = CanonicalEncoder::encode($f);
        assertEq($enc1, $enc2);
        $dec = CanonicalEncoder::decode($enc1);
        assertEq($f, $dec);
    });

    TestRun::it('large integers encode at 64-bit', function () {
        assertEq('1b7fffffffffffffff', bin2hex(CanonicalEncoder::encode(PHP_INT_MAX)));
    });
});
