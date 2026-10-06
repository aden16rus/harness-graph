<?php
declare(strict_types=1);

namespace Harness\Infrastructure\Database;

use PDO;

final class Connection
{
    private static ?PDO $pdo = null;

    public static function get(string $dbPath = '/data/harness.db'): PDO
    {
        if (self::$pdo === null) {
            $dir = dirname($dbPath);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }

            self::$pdo = new PDO('sqlite:' . $dbPath, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);

            // Enable WAL mode and foreign keys for high performance SQLite
            self::$pdo->exec('PRAGMA journal_mode = WAL;');
            self::$pdo->exec('PRAGMA foreign_keys = ON;');
        }

        return self::$pdo;
    }

    public static function set(PDO $pdo): void
    {
        self::$pdo = $pdo;
    }

    public static function createInMemory(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON;');
        return $pdo;
    }
}
