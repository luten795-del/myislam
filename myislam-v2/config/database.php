<?php
declare(strict_types=1);

// Database credentials – adjust as needed
define('DB_HOST', 'db');
define('DB_PORT', '3306');   // Verify this matches your MySQL port
define('DB_NAME', 'myislam');
define('DB_USER', 'root');
define('DB_PASS', 'admin');

/**
 * Get a PDO database connection.
 *
 * @return PDO
 * @throws PDOException if connection fails
 */
function db(): PDO {
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            DB_HOST,
            DB_PORT,
            DB_NAME
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

            // Force MySQL session timezone to EAT (+03:00, Tanzania, no DST).
            // This makes MySQL's NOW() and PHP's date() agree, so any
            // comparison between stored timestamps and PHP-computed
            // timestamps (e.g. competition windows) is exact.
            $pdo->exec("SET time_zone = '+03:00'");
        } catch (PDOException $e) {
            // Log the error and rethrow so the caller can decide how to handle it
            error_log('Database connection failed: ' . $e->getMessage());
            throw $e;
        }
    }

    return $pdo;
}

/**
 * Quick check if database is reachable (optional).
 */
function is_db_connected(): bool {
    try {
        db();
        return true;
    } catch (PDOException $e) {
        return false;
    }
}