<?php
declare(strict_types=1);

namespace WpSync\Snapshot;

/**
 * Normalizes a WordPress row before it is canonically encoded.
 *
 *  1. Column ordering: columns are keyed by name (alphabetical at encode time).
 *  2. Site-URL replacement: occurrences of the source site's URL are replaced
 *     with the placeholder {{SITE_URL}}. Works recursively inside serialized PHP
 *     values with correct length-prefix recomputation.
 *  3. Serialized PHP is re-serialized deterministically (sorted keys, correct
 *     lengths) so cross-site hashes match.
 *  4. GMT timestamps: non-GMT variants are dropped.
 *  5. Transients (in wp_options): skipped at the caller level.
 */
final class RowNormalizer
{
    public const SITE_URL_PLACEHOLDER = '{{SITE_URL}}';

    /** Map non-GMT -> GMT column names. Non-GMT is dropped on encode and reconstituted on apply. */
    public const NON_GMT_TO_GMT = [
        'post_date'     => 'post_date_gmt',
        'post_modified' => 'post_modified_gmt',
        'comment_date'  => 'comment_date_gmt',
    ];

    /**
     * @param array<string,mixed> $row Column-name => value from the source DB.
     * @param string $siteUrl Source site URL (no trailing slash enforced).
     * @return array<string,mixed> Normalized row.
     */
    public static function normalizeForEncode(array $row, string $siteUrl): array
    {
        $siteUrl = self::canonicalUrl($siteUrl);
        $out = [];
        foreach ($row as $col => $val) {
            // Drop non-GMT when GMT sibling is present; will be reconstituted on apply.
            if (isset(self::NON_GMT_TO_GMT[$col]) && array_key_exists(self::NON_GMT_TO_GMT[$col], $row)) {
                continue;
            }
            if ($val === null || !is_string($val) || $val === '') {
                $out[$col] = $val;
                continue;
            }
            $out[$col] = self::rewriteOutgoing($val, $siteUrl);
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * @param array<string,mixed> $row
     * @param string $siteUrl Destination site URL.
     * @return array<string,mixed>
     */
    public static function denormalizeForApply(array $row, string $siteUrl): array
    {
        $siteUrl = self::canonicalUrl($siteUrl);
        $out = [];
        foreach ($row as $col => $val) {
            if (!is_string($val) || $val === '') {
                $out[$col] = $val;
                continue;
            }
            $out[$col] = self::rewriteIncoming($val, $siteUrl);
        }
        // Reconstitute non-GMT siblings from GMT (best-effort: same value, as a NOT NULL placeholder).
        foreach (self::NON_GMT_TO_GMT as $local => $gmt) {
            if (!array_key_exists($local, $out) && array_key_exists($gmt, $out) && $out[$gmt] !== null) {
                $out[$local] = $out[$gmt];
            }
        }
        return $out;
    }

    /** Source -> canonical form: replace site URL with placeholder, re-serialize. */
    public static function rewriteOutgoing(string $raw, string $siteUrl): string
    {
        return SerializedPhpHandler::transformStrings(
            $raw,
            static fn(string $s): string => str_replace($siteUrl, self::SITE_URL_PLACEHOLDER, $s),
        );
    }

    /** Canonical -> destination form: replace placeholder with local site URL. */
    public static function rewriteIncoming(string $raw, string $siteUrl): string
    {
        return SerializedPhpHandler::transformStrings(
            $raw,
            static fn(string $s): string => str_replace(self::SITE_URL_PLACEHOLDER, $siteUrl, $s),
        );
    }

    private static function canonicalUrl(string $u): string
    {
        return rtrim($u, '/');
    }

    /** Options that must never be synced (they identify the site itself). */
    public static function isExcludedOption(string $name): bool
    {
        static $excluded = [
            'siteurl' => true,
            'home' => true,
            'blog_charset' => false, // safe to sync; left for example
        ];
        if (isset($excluded[$name]) && $excluded[$name]) {
            return true;
        }
        if (str_starts_with($name, '_transient_')) return true;
        if (str_starts_with($name, '_site_transient_')) return true;
        return false;
    }
}
