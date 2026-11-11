<?php

declare(strict_types=1);

namespace App\Core;

use PDO;

final class DatabaseConnection
{
    private static ?PDO $connection = null;

    public static function getConnection(): PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $configuration = require dirname(__DIR__, 2)
            . '/config/database.php';

        $database = $configuration['database'];

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $database['host'],
            $database['port'],
            $database['name'],
            $database['charset']
        );

        self::$connection = new PDO(
            $dsn,
            $database['username'],
            $database['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_PERSISTENT => false,
            ]
        );

        return self::$connection;
    }

    /**
     * Écarte la connexion uniquement si elle correspond
     * à celle détenue par le singleton.
     */
    public static function discardConnection(PDO $connection): void
    {
        if (self::$connection === $connection) {
            self::$connection = null;
        }
    }
}
