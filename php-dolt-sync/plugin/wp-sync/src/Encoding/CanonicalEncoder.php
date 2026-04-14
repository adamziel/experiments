<?php
declare(strict_types=1);

namespace WpSync\Encoding;

/**
 * Canonical encoder: PHP value -> deterministic CBOR bytes.
 *
 * Rules:
 *  - PHP null        -> CBOR null
 *  - PHP bool        -> CBOR bool
 *  - PHP int         -> CBOR uint/nint
 *  - PHP float       -> CBOR double; integer-valued floats are re-coerced to int;
 *                       NaN normalized to canonical quiet NaN; -0.0 -> 0.0
 *  - PHP string      -> CBOR text string (NFC-normalized, must be valid UTF-8)
 *  - PHP list (sequential 0-indexed) -> CBOR array
 *  - PHP map (any other array)        -> CBOR map with length-first sorted keys
 *
 * Byte strings: not emitted by this encoder; use encodeBytes() explicitly.
 */
final class CanonicalEncoder
{
    /** Sentinel wrapper for byte-strings that should encode as CBOR byte strings. */
    public static function bytes(string $raw): \stdClass
    {
        $o = new \stdClass();
        $o->__cbor_bytes = $raw;
        return $o;
    }

    public static function encode(mixed $value): string
    {
        if ($value === null) {
            return CborEncoder::encodeNull();
        }
        if (is_bool($value)) {
            return CborEncoder::encodeBool($value);
        }
        if (is_int($value)) {
            return CborEncoder::encodeInt($value);
        }
        if (is_float($value)) {
            return self::encodeFloat($value);
        }
        if (is_string($value)) {
            return self::encodeString($value);
        }
        if (is_array($value)) {
            return self::encodeArray($value);
        }
        if (is_object($value) && isset($value->__cbor_bytes) && is_string($value->__cbor_bytes)) {
            $raw = $value->__cbor_bytes;
            return CborEncoder::head(CborEncoder::MT_BSTR, strlen($raw)) . $raw;
        }
        throw new \InvalidArgumentException('CanonicalEncoder: unsupported type ' . get_debug_type($value));
    }

    public static function decode(string $bytes): mixed
    {
        return CborDecoder::decode($bytes);
    }

    public static function hash(mixed $value): string
    {
        return hash('sha256', self::encode($value));
    }

    public static function hashBytes(string $bytes): string
    {
        return hash('sha256', $bytes);
    }

    /** Sort map pairs by raw encoded key bytes, length-first (RFC 8949 §4.2.3). */
    private static function encodeArray(array $a): string
    {
        // Determine if sequential list.
        if (self::isList($a)) {
            $body = '';
            foreach ($a as $v) {
                $body .= self::encode($v);
            }
            return CborEncoder::head(CborEncoder::MT_ARRAY, count($a)) . $body;
        }

        // Map: encode each key, sort pairs by key bytes.
        $pairs = [];
        foreach ($a as $k => $v) {
            // PHP coerces numeric-string keys to int. We preserve PHP's native key type.
            if (is_int($k)) {
                $ek = CborEncoder::encodeInt($k);
            } else {
                $ek = self::encodeString((string)$k);
            }
            $pairs[] = [$ek, self::encode($v)];
        }
        $pairs = CborEncoder::sortMapPairs($pairs);
        $body = '';
        foreach ($pairs as $p) {
            $body .= $p[0] . $p[1];
        }
        return CborEncoder::head(CborEncoder::MT_MAP, count($pairs)) . $body;
    }

    private static function isList(array $a): bool
    {
        if ($a === []) {
            return true;
        }
        // array_is_list is PHP 8.1+.
        return array_is_list($a);
    }

    private static function encodeFloat(float $f): string
    {
        if (is_nan($f)) {
            return CborEncoder::encodeDouble(NAN);
        }
        if (!is_finite($f)) {
            return CborEncoder::encodeDouble($f);
        }
        // Coerce integer-valued floats to int encoding.
        if ($f === floor($f) && $f >= PHP_INT_MIN && $f <= PHP_INT_MAX) {
            // beware of precision: ensure exact integer representation
            $i = (int)$f;
            if ((float)$i === $f) {
                return CborEncoder::encodeInt($i);
            }
        }
        // -0.0 normalize to 0.0
        if ($f === 0.0) {
            return CborEncoder::encodeDouble(0.0);
        }
        return CborEncoder::encodeDouble($f);
    }

    private static function encodeString(string $s): string
    {
        // Must be valid UTF-8.
        if ($s !== '' && !mb_check_encoding($s, 'UTF-8')) {
            throw new \InvalidArgumentException('CanonicalEncoder: invalid UTF-8 string');
        }
        // NFC normalize if intl available.
        if (class_exists(\Normalizer::class)) {
            if (!\Normalizer::isNormalized($s, \Normalizer::FORM_C)) {
                $n = \Normalizer::normalize($s, \Normalizer::FORM_C);
                if ($n !== false) {
                    $s = $n;
                }
            }
        }
        return CborEncoder::head(CborEncoder::MT_TSTR, strlen($s)) . $s;
    }
}
