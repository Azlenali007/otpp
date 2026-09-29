<?php
/**
 * NumVault - Database Configuration
 * Seamlessly integrates installer-persisted database configuration and environment overrides
 */

declare(strict_types=1);

$installedConfig = [];
$configFile = dirname(__DIR__, 2) . '/storage/db_config.json';
if (file_exists($configFile)) {
    $raw = @file_get_contents($configFile);
    if ($raw !== false) {
        $parsed = json_decode($raw, true);
        if (is_array($parsed)) {
            $installedConfig = $parsed;
        }
    }
}

return [
    'host'      => getenv('DB_HOST') ?: ($installedConfig['host'] ?? '127.0.0.1'),
    'port'      => (int)(getenv('DB_PORT') ?: ($installedConfig['port'] ?? 3306)),
    'database'  => getenv('DB_NAME') ?: ($installedConfig['database'] ?? 'otp_marketplace'),
    'username'  => getenv('DB_USER') ?: ($installedConfig['username'] ?? 'otp_user'),
    'password'  => getenv('DB_PASS') ?: ($installedConfig['password'] ?? 'otp_pass_2026'),
    'charset'   => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'options'   => [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]
];
