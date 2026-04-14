<?php
declare(strict_types=1);

namespace WpSync\Encoding;

/**
 * Minimal deterministic CBOR encoder (RFC 8949, deterministic profile §4.2).
 * Supports: unsigned/negative ints, byte/text strings, arrays, maps, floats, null, bool.
 */
final class CborEncoder
{
    public const MT_UINT  = 0;
    public const MT_NINT  = 1;
    public const MT_BSTR  = 2;
    public const MT_TSTR  = 3;
    public const MT_ARRAY = 4;
    public const MT_MAP   = 5;
    public const MT_TAG   = 6;
    public const MT_SIMPLE= 7;

    /** Encode a header byte for a major type with argument length. */
    public static function head(int $mt, int $arg): string
    {
        if ($arg < 0) {
            throw new \InvalidArgumentException('negative arg');
        }
        if ($arg < 24) {
            return chr(($mt << 5) | $arg);
        }
        if ($arg <= 0xff) {
            return chr(($mt << 5) | 24) . chr($arg);
        }
        if ($arg <= 0xffff) {
            return chr(($mt << 5) | 25) . pack('n', $arg);
        }
        if ($arg <= 0xffffffff) {
            return chr(($mt << 5) | 26) . pack('N', $arg);
        }
        return chr(($mt << 5) | 27) . pack('J', $arg);
    }

    /** Encode an unsigned 64-bit value. Accepts int; for values > PHP_INT_MAX use string decimal. */
    public static function encodeUint(int $v): string
    {
        if ($v < 0) {
            throw new \InvalidArgumentException('encodeUint negative');
        }
        return self::head(self::MT_UINT, $v);
    }

    public static function encodeNint(int $v): string
    {
        if ($v >= 0) {
            throw new \InvalidArgumentException('encodeNint non-negative');
        }
        // negative: encoded as -(n+1) so v=-1 -> 0, v=-2 -> 1, etc.
        $n = -($v + 1);
        return self::head(self::MT_NINT, $n);
    }

    public static function encodeInt(int $v): string
    {
        return $v >= 0 ? self::encodeUint($v) : self::encodeNint($v);
    }

    public static function encodeBytes(string $b): string
    {
        return self::head(self::MT_BSTR, strlen($b)) . $b;
    }

    public static function encodeText(string $s): string
    {
        return self::head(self::MT_TSTR, strlen($s)) . $s;
    }

    public static function encodeNull(): string
    {
        return "\xf6";
    }

    public static function encodeBool(bool $b): string
    {
        return $b ? "\xf5" : "\xf4";
    }

    /**
     * Deterministic float encoding per RFC 8949 §4.2.2:
     * Use the shortest representation that preserves value. For simplicity and
     * full determinism across platforms, always emit 64-bit (0xfb).
     * Callers must normalize NaN and -0.0 before invoking.
     */
    public static function encodeDouble(float $f): string
    {
        // Normalize NaN to canonical quiet NaN.
        if (is_nan($f)) {
            return "\xfb" . "\x7f\xf8\x00\x00\x00\x00\x00\x00";
        }
        // Normalize -0.0 to 0.0.
        if ($f === 0.0) {
            $f = 0.0;
        }
        // pack('E', ...) = big-endian double (IEEE 754).
        return "\xfb" . pack('E', $f);
    }

    /** RFC 8949 §4.2.3: length-first lexicographic sort of encoded map keys. */
    public static function sortMapPairs(array $pairs): array
    {
        usort($pairs, static function (array $a, array $b): int {
            $la = strlen($a[0]);
            $lb = strlen($b[0]);
            if ($la !== $lb) {
                return $la <=> $lb;
            }
            return strcmp($a[0], $b[0]);
        });
        return $pairs;
    }
}
