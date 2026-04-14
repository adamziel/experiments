<?php
declare(strict_types=1);

namespace WpSync\Test\Harness;

/** Minimal WordPress-like SQLite schema for integration tests. */
final class WpFixture
{
    public static function createDb(string $siteUrl = 'https://site-a.test'): \SQLite3
    {
        $p = tempnam(sys_get_temp_dir(), 'wp_'); unlink($p);
        $db = new \SQLite3($p);
        $db->enableExceptions(true);
        $db->exec('PRAGMA journal_mode=WAL');
        $db->exec(<<<SQL
            CREATE TABLE wp_posts (
                ID INTEGER PRIMARY KEY AUTOINCREMENT,
                post_author INTEGER NOT NULL DEFAULT 0,
                post_date TEXT NOT NULL,
                post_date_gmt TEXT NOT NULL,
                post_content TEXT NOT NULL,
                post_title TEXT NOT NULL,
                post_excerpt TEXT NOT NULL DEFAULT '',
                post_status TEXT NOT NULL DEFAULT 'publish',
                post_modified TEXT NOT NULL,
                post_modified_gmt TEXT NOT NULL,
                guid TEXT NOT NULL
            )
        SQL);
        $db->exec(<<<SQL
            CREATE TABLE wp_options (
                option_id INTEGER PRIMARY KEY AUTOINCREMENT,
                option_name TEXT NOT NULL UNIQUE,
                option_value TEXT NOT NULL,
                autoload TEXT NOT NULL DEFAULT 'yes'
            )
        SQL);
        $db->exec(<<<SQL
            CREATE TABLE wp_postmeta (
                meta_id INTEGER PRIMARY KEY AUTOINCREMENT,
                post_id INTEGER NOT NULL,
                meta_key TEXT NOT NULL,
                meta_value TEXT
            )
        SQL);
        // A plugin table without a primary key (exercises rowid fallback).
        $db->exec(<<<SQL
            CREATE TABLE plugin_noprimary (
                a TEXT NOT NULL,
                b TEXT NOT NULL
            )
        SQL);
        $db->exec("INSERT INTO wp_options(option_name, option_value) VALUES('siteurl', '" . $siteUrl . "')");
        $db->exec("INSERT INTO wp_options(option_name, option_value) VALUES('home', '" . $siteUrl . "')");
        return $db;
    }

    private static int $nextGuid = 100;
    public static function insertPost(\SQLite3 $db, string $title, string $content, string $guidHost = 'https://site-a.test', ?int $guidN = null): int
    {
        $date = '2024-01-01 12:00:00';
        $stmt = $db->prepare(<<<SQL
            INSERT INTO wp_posts (post_date, post_date_gmt, post_content, post_title, post_modified, post_modified_gmt, guid)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        SQL);
        $stmt->bindValue(1, $date); $stmt->bindValue(2, $date);
        $stmt->bindValue(3, $content); $stmt->bindValue(4, $title);
        $stmt->bindValue(5, $date); $stmt->bindValue(6, $date);
        $stmt->bindValue(7, "{$guidHost}/?p=" . ($guidN ?? self::$nextGuid++));
        $stmt->execute();
        return (int)$db->lastInsertRowID();
    }
}
