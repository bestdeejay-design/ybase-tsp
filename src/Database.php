<?php

declare(strict_types=1);

namespace YellParser;

use PDO;
use PDOException;

class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            $host = $_ENV['DB_HOST'] ?? 'localhost';
            $port = $_ENV['DB_PORT'] ?? '5432';
            $dbname = $_ENV['DB_NAME'] ?? 'yell_parser';
            $user = $_ENV['DB_USER'] ?? 'parser_user';
            $password = $_ENV['DB_PASSWORD'] ?? 'parser_pass';

            $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";

            try {
                self::$instance = new PDO($dsn, $user, $password, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                ]);
            } catch (PDOException $e) {
                throw new \RuntimeException('Database connection failed: ' . $e->getMessage());
            }
        }

        return self::$instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }
}
