<?php
declare(strict_types=1);

namespace WpSync\Snapshot;

/**
 * Deterministic re-serialization of PHP values (PHP serialize() format).
 *
 * Problems we address:
 *  - PHP serialize() preserves insertion order for assoc arrays.
 *    Two sites with keys inserted in different orders produce different bytes.
 *  - Length prefixes (s:N:"...") become incorrect when strings are mutated
 *    (e.g., after URL rewriting).
 *
 * This serializer:
 *  - Sorts associative-array keys lexicographically before serialization.
 *  - Recomputes string lengths based on current byte length.
 *  - Supports the subset WordPress uses: null, bool, int, double, string,
 *    array (assoc + list). Objects deserialize as stdClass but are re-serialized
 *    in object form with sorted property keys.
 *  - Rejects inputs containing references (R:, r:) — rare in WP and unsafe to
 *    "normalize" without breaking identity semantics.
 */
final class SerializedPhpHandler
{
    /** Quick heuristic: does this string look like a PHP serialized value? */
    public static function looksSerialized(string $s): bool
    {
        if ($s === '') return false;
        $c = $s[0];
        if ($c === 'N' && ($s === 'N;' || str_starts_with($s, 'N;'))) return true;
        if (!in_array($c, ['b','i','d','s','a','O'], true)) return false;
        if (strlen($s) < 4) return false;
        return $s[1] === ':';
    }

    /** Unserialize safely. Returns [ok, value]. */
    public static function tryUnserialize(string $s): array
    {
        // WordPress's `maybe_unserialize` uses @unserialize. We do the same
        // but also block references which we can't safely normalize.
        if (str_contains($s, 'R:') || str_contains($s, 'r:')) {
            return [false, null];
        }
        $old = error_reporting(0);
        try {
            $v = @unserialize($s, ['allowed_classes' => false]);
        } finally {
            error_reporting($old);
        }
        if ($v === false && $s !== 'b:0;') {
            return [false, null];
        }
        return [true, $v];
    }

    /**
     * Re-serialize deterministically.
     * - Sort associative-array keys (preserve list indices for sequential lists).
     * - Recompute string byte-lengths.
     * - objects (stdClass) are re-serialized as arrays to avoid class-name binding.
     */
    public static function serialize(mixed $v): string
    {
        if ($v === null) return 'N;';
        if (is_bool($v)) return 'b:' . ($v ? '1' : '0') . ';';
        if (is_int($v)) return 'i:' . $v . ';';
        if (is_float($v)) {
            // PHP's serialize() uses serialize_precision. Force a deterministic repr.
            return 'd:' . self::floatRepr($v) . ';';
        }
        if (is_string($v)) {
            return 's:' . strlen($v) . ':"' . $v . '";';
        }
        if (is_array($v)) {
            $isList = array_is_list($v);
            if (!$isList) {
                // Sort by key (string compare; numeric keys coerced to int by PHP).
                // Preserve key types; ksort with SORT_STRING.
                ksort($v, SORT_STRING);
            }
            $body = '';
            foreach ($v as $k => $val) {
                $body .= is_int($k) ? 'i:' . $k . ';' : 's:' . strlen((string)$k) . ':"' . $k . '";';
                $body .= self::serialize($val);
            }
            return 'a:' . count($v) . ':{' . $body . '}';
        }
        if ($v instanceof \stdClass) {
            // Serialize as array — we don't track class identity.
            return self::serialize((array)$v);
        }
        throw new \InvalidArgumentException('serialize: unsupported type ' . get_debug_type($v));
    }

    /** IEEE 754 double -> decimal repr guaranteed to round-trip. */
    private static function floatRepr(float $f): string
    {
        if (is_nan($f)) return 'NAN';
        if ($f === INF) return 'INF';
        if ($f === -INF) return '-INF';
        if ($f === 0.0) return '0';
        // PHP_FLOAT_DIG = 15 round-trips; use 17 to be lossless.
        $s = sprintf('%.17G', $f);
        // Round-trip check: if 15 digits suffices, prefer that for stability.
        $short = sprintf('%.15G', $f);
        if ((float)$short === $f) $s = $short;
        return $s;
    }

    /**
     * Apply a string transformation callback to every string inside a (maybe-serialized)
     * value, normalizing the result. The callback receives plain strings and returns
     * transformed plain strings.
     *
     * If $raw is not serialized, the transformation is applied to the raw string.
     * If $raw is serialized, it's unserialized, transformed recursively, and
     * re-serialized with correct length prefixes.
     */
    public static function transformStrings(string $raw, callable $xform): string
    {
        if (self::looksSerialized($raw)) {
            [$ok, $value] = self::tryUnserialize($raw);
            if ($ok) {
                $walked = self::walk($value, $xform);
                return self::serialize($walked);
            }
        }
        return $xform($raw);
    }

    public static function walk(mixed $v, callable $xform): mixed
    {
        if (is_string($v)) {
            // A string inside a serialized value may itself be serialized (nested).
            if (self::looksSerialized($v)) {
                [$ok, $inner] = self::tryUnserialize($v);
                if ($ok) {
                    return self::serialize(self::walk($inner, $xform));
                }
            }
            return $xform($v);
        }
        if (is_array($v)) {
            $out = [];
            foreach ($v as $k => $val) {
                $newKey = is_string($k) ? $xform($k) : $k;
                $out[$newKey] = self::walk($val, $xform);
            }
            return $out;
        }
        if ($v instanceof \stdClass) {
            $arr = (array)$v;
            $out = new \stdClass();
            foreach ($arr as $k => $val) {
                $newKey = is_string($k) ? $xform($k) : $k;
                $out->$newKey = self::walk($val, $xform);
            }
            return $out;
        }
        return $v;
    }
}
