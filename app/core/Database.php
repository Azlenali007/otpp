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
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        $config = require __DIR__ . '/../config/database.php';
        $host     = (string)$config['host'];
        $port     = (int)$config['port'];
        $database = (string)$config['database'];
        $username = (string)$config['username'];
        $password = (string)$config['password'];
        $charset  = (string)$config['charset'];
        $options  = (array)$config['options'];

        // Known local MariaDB/MySQL UNIX socket paths
        $knownSockets = [
            '/run/mysqld/mysqld.sock',
            '/var/run/mysqld/mysqld.sock',
            '/tmp/mysql.sock',
            '/var/lib/mysql/mysql.sock',
        ];

        // Build list of connection attempts in priority order
        $candidates = [];

        // 1. Primary configured DSN
        $candidates[] = [
            'dsn'  => "mysql:host={$host};port={$port};dbname={$database};charset={$charset}",
            'user' => $username,
            'pass' => $password,
            'desc' => "TCP Host ({$host}:{$port})"
        ];

        // 2. Localhost socket fallback with configured credentials
        if ($host !== 'localhost') {
            $candidates[] = [
                'dsn'  => "mysql:host=localhost;dbname={$database};charset={$charset}",
                'user' => $username,
                'pass' => $password,
                'desc' => "Localhost Socket with configured user"
            ];
        }

        // 3. 127.0.0.1 TCP fallback if primary host was localhost
        if ($host !== '127.0.0.1') {
            $candidates[] = [
                'dsn'  => "mysql:host=127.0.0.1;port=3306;dbname={$database};charset={$charset}",
                'user' => $username,
                'pass' => $password,
                'desc' => "127.0.0.1:3306 TCP with configured user"
            ];
        }

        // 4. Direct UNIX socket attempts with configured credentials
        foreach ($knownSockets as $socket) {
            if (file_exists($socket)) {
                $candidates[] = [
                    'dsn'  => "mysql:unix_socket={$socket};dbname={$database};charset={$charset}",
                    'user' => $username,
                    'pass' => $password,
                    'desc' => "Unix socket ({$socket}) with configured user"
                ];
                // 5. Unix socket attempt with local root auth (socket authentication)
                $candidates[] = [
                    'dsn'  => "mysql:unix_socket={$socket};dbname={$database};charset={$charset}",
                    'user' => 'root',
                    'pass' => '',
                    'desc' => "Unix socket ({$socket}) with root socket auth"
                ];
            }
        }

        $errors = [];
        foreach ($candidates as $cand) {
            try {
                $pdo = new PDO($cand['dsn'], $cand['user'], $cand['pass'], $options);
                // Verify connection works with a simple ping
                $pdo->query("SELECT 1");
                self::$pdo = $pdo;
                return self::$pdo;
            } catch (PDOException $e) {
                $errors[] = "[{$cand['desc']}] " . $e->getMessage();
            }
        }

        // All connection strategies failed: Log technically for debugging
        $errorLogMessage = "All database connection attempts failed for database '{$database}': " . implode(" | ", $errors);
        Logger::error($errorLogMessage);

        // Check if application is not installed yet
        $lockFile = dirname(__DIR__, 2) . '/storage/installed.lock';
        $rootLock = dirname(__DIR__, 2) . '/installed.lock';
        $isInstalled = file_exists($lockFile) || file_exists($rootLock);
        $isInstallPage = str_contains($_SERVER['REQUEST_URI'] ?? '', '/install/');

        if (!$isInstalled && !$isInstallPage) {
            header('Location: /install/index.php');
            exit;
        }

        // Keep production response secure without exposing credentials
        http_response_code(500);
        die("Database connection is currently unavailable. Please contact the administrator or verify your database setup.");
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
            throw $e;
        }
    }
}
