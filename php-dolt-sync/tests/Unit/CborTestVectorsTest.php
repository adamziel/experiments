<?php
declare(strict_types=1);

use WpSync\Encoding\CanonicalEncoder;

TestRun::group('CBOR test vectors (RFC 8949 Appendix A subset)', function () {
    // (value, hex) pairs from Appendix A; we only include vectors we can represent.
    $vectors = [
        [0, '00'],
        [1, '01'],
        [10, '0a'],
        [23, '17'],
        [24, '1818'],
        [25, '1819'],
        [100, '1864'],
        [1000, '1903e8'],
        [1000000, '1a000f4240'],
        [-1, '20'],
        [-10, '29'],
        [-100, '3863'],
        [-1000, '3903e7'],
        [false, 'f4'],
        [true, 'f5'],
        [null, 'f6'],
        [[], '80'],
        [[1, 2, 3], '83010203'],
        ['', '60'],
        ['a', '6161'],
        ['IETF', '6449455446'],
        ["\"\\", '62225c'],
        [[1, [2, 3], [4, 5]], '8301820203820405'],
    ];
    foreach ($vectors as $i => [$val, $hex]) {
        TestRun::it("vector[{$i}]", function () use ($val, $hex) {
            assertEq($hex, bin2hex(CanonicalEncoder::encode($val)));
        });
    }
});
