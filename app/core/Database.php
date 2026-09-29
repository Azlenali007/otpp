<?php
/**
 * NumVault - Database Connection & Transaction Manager
 * Strict PDO prepared statement execution with zero raw query concatenation
 */

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;
use Exception;

class Database {
    private static ?PDO $pdo = null;

    public static function getConnection(): PDO {
        if (self::$pdo === null) {
            $config = require __DIR__ . '/../config/database.php';
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $config['host'],
                $config['port'],
                $config['database'],
                $config['charset']
            );

            try {
                self::$pdo = new PDO($dsn, $config['username'], $config['password'], $config['options']);
            } catch (PDOException $e) {
                // Try localhost fallback for local MariaDB sockets/root
                try {
                    $fallbackDsn = sprintf('mysql:host=127.0.0.1;port=3306;dbname=%s;charset=utf8mb4', $config['database']);
                    self::$pdo = new PDO($fallbackDsn, 'root', '', $config['options']);
                } catch (PDOException $e2) {
                    Logger::error("Database connection failure: " . $e2->getMessage());
                    
                    // If during installation or not installed, gracefully handle
                    $lockFile = __DIR__ . '/../../storage/installed.lock';
                    $isInstallPage = str_contains($_SERVER['REQUEST_URI'] ?? '', '/install/');
                    if (!file_exists($lockFile) && !$isInstallPage) {
                        header('Location: /install/index.php');
                        exit;
                    }

                    // Return generic message in production to prevent credential leakage
                    die("Database connection is currently unavailable. Please contact the administrator or verify your database setup.");
                }
            }
        }
        return self::$pdo;
    }

    /**
     * Execute a callable inside an ACID transaction
     */
    public static function transaction(callable $callback): mixed {
        $pdo = self::getConnection();
        $pdo->beginTransaction();
        try {
            $result = $callback($pdo);
            $pdo->commit();
            return $result;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::error("Transaction rollback: " . $e->getMessage());
            throw $e;
        }
    }
}
