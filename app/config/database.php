<?php
/**
 * NumVault - Database Configuration
 */

declare(strict_types=1);

return [
    'host'     => getenv('DB_HOST') ?: '127.0.0.1',
    'port'     => (int)(getenv('DB_PORT') ?: 3306),
    'database' => getenv('DB_NAME') ?: 'otp_marketplace',
    'username' => getenv('DB_USER') ?: 'otp_user',
    'password' => getenv('DB_PASS') ?: 'otp_pass_2026',
    'charset'  => 'utf8mb4',
    'collation'=> 'utf8mb4_unicode_ci',
    'options'  => [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
    ]
];
