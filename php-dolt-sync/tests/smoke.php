<?php
declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use WpSync\Encoding\CanonicalEncoder;
use WpSync\Store\SQLiteChunkStore;
use WpSync\Store\Chunk;
use WpSync\Tree\TableTree;

function check(bool $cond, string $msg): void {
    if (!$cond) {
        fwrite(STDERR, "FAIL: $msg\n");
        exit(1);
    }
    echo "ok: $msg\n";
}

// --- Canonical encoder determinism ---
$a = ['b' => 1, 'a' => 2];
$b = ['a' => 2, 'b' => 1];
check(CanonicalEncoder::encode($a) === CanonicalEncoder::encode($b), 'map key sort: different insertion order -> same bytes');

// RFC 8949 Appendix A examples
check(bin2hex(CanonicalEncoder::encode(0)) === '00', 'uint 0 -> 0x00');
check(bin2hex(CanonicalEncoder::encode(1)) === '01', 'uint 1 -> 0x01');
check(bin2hex(CanonicalEncoder::encode(10)) === '0a', 'uint 10 -> 0x0a');
check(bin2hex(CanonicalEncoder::encode(23)) === '17', 'uint 23 -> 0x17');
check(bin2hex(CanonicalEncoder::encode(24)) === '1818', 'uint 24 -> 0x1818');
check(bin2hex(CanonicalEncoder::encode(100)) === '1864', 'uint 100 -> 0x1864');
check(bin2hex(CanonicalEncoder::encode(1000)) === '1903e8', 'uint 1000 -> 0x1903e8');
check(bin2hex(CanonicalEncoder::encode(-1)) === '20', 'nint -1 -> 0x20');
check(bin2hex(CanonicalEncoder::encode(-10)) === '29', 'nint -10 -> 0x29');
check(bin2hex(CanonicalEncoder::encode(-100)) === '3863', 'nint -100 -> 0x3863');
check(bin2hex(CanonicalEncoder::encode(false)) === 'f4', 'false -> 0xf4');
check(bin2hex(CanonicalEncoder::encode(true)) === 'f5', 'true -> 0xf5');
check(bin2hex(CanonicalEncoder::encode(null)) === 'f6', 'null -> 0xf6');
check(bin2hex(CanonicalEncoder::encode([])) === '80', 'empty array -> 0x80');
check(bin2hex(CanonicalEncoder::encode([1,2,3])) === '83010203', '[1,2,3] -> 0x83010203');

// Float NaN normalization
$n1 = CanonicalEncoder::encode(NAN);
$n2 = CanonicalEncoder::encode(NAN);
check($n1 === $n2, 'NaN encodes deterministically');
check(bin2hex($n1) === 'fb7ff8000000000000', 'NaN -> canonical quiet NaN');

// -0 normalization
check(CanonicalEncoder::encode(-0.0) === CanonicalEncoder::encode(0.0), '-0.0 == 0.0');

// Integer-float coercion
check(CanonicalEncoder::encode(3.0) === CanonicalEncoder::encode(3), '3.0 coerces to 3');

// Round trip
$v = ['x' => [1, 2.5, 'hello', true, null], 'y' => 'world'];
check(CanonicalEncoder::decode(CanonicalEncoder::encode($v)) == $v, 'round trip map/list/scalars');

// NULL vs ''
check(CanonicalEncoder::hash(null) !== CanonicalEncoder::hash(''), 'null distinct from empty string');

// UTF-8 NFC
$nfd = "cafe\xcc\x81"; // café with combining acute
$nfc = "caf\xc3\xa9";
check(CanonicalEncoder::hash($nfd) === CanonicalEncoder::hash($nfc), 'NFC normalization unifies accents');

// Invalid UTF-8 rejected
$caught = false;
try { CanonicalEncoder::encode("\xff\xfe"); } catch (\InvalidArgumentException) { $caught = true; }
check($caught, 'invalid UTF-8 is rejected');

// --- Chunk store ---
$tmp = tempnam(sys_get_temp_dir(), 'chunks_');
unlink($tmp);
$cs = new SQLiteChunkStore($tmp);
$c = Chunk::of('hello world');
$cs->putMany([$c]);
$has = $cs->hasMany([$c->hash, 'abc']);
check(isset($has[$c->hash]) && !isset($has['abc']), 'hasMany works');
$got = $cs->getMany([$c->hash]);
check($got[$c->hash]->bytes === 'hello world', 'getMany works');

// Bad hash rejected
$bad = new Chunk(str_repeat('a', 64), 'different');
$rejected = false;
try { $cs->putMany([$bad]); } catch (\RuntimeException $e) { $rejected = true; }
check($rejected, 'bad hash rejected');

// Idempotent put
$cs->putMany([$c]);
check($cs->count() === 1, 'idempotent put');

// --- TableTree determinism ---
$entries1 = [];
for ($i = 1; $i <= 100; $i++) {
    $entries1[] = [pack('J', $i), "value_$i"];
}
$entries2 = $entries1;
shuffle($entries2);

$r1 = TableTree::build($entries1, $cs);
$r2 = TableTree::build($entries2, $cs);
check($r1 === $r2, 'tree determinism across insertion order');

$val = TableTree::get($r1, pack('J', 42), $cs);
check($val === 'value_42', 'tree get returns correct value');

$iterated = iterator_to_array(TableTree::iterate($r1, $cs), false);
check(count($iterated) === 100, 'iterate yields all entries');
check($iterated[0][1] === 'value_1', 'iterate is sorted');

// Empty tree
$re = TableTree::build([], $cs);
$re2 = TableTree::build([], $cs);
check($re === $re2, 'empty tree deterministic');
check(iterator_to_array(TableTree::iterate($re, $cs), false) === [], 'empty tree iterates empty');

// Diff
$entriesMod = $entries1;
$entriesMod[42] = [pack('J', 43), 'CHANGED']; // changing the 43rd entry
$rMod = TableTree::build($entriesMod, $cs);
$diff = iterator_to_array(TableTree::diff($r1, $rMod, $cs), false);
$changed = array_filter($diff, fn($d) => $d[0] === pack('J', 43));
check(count($diff) === 1 && count($changed) === 1, 'diff yields exactly changed entry');

echo "\nAll smoke tests passed.\n";
