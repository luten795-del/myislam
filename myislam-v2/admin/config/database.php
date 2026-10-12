<?php
declare(strict_types=1);
function db(): ?PDO {
    static $connection = null;
    if ($connection === null) {
        try {
            $host = 'db';
            $port = '3306';
            $dbname = 'myislam';
            $username = 'root';
            $password = 'admin';
            $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $connection = new PDO($dsn, $username, $password, $options);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            return null;
        }
    }
    return $connection;
}
?>