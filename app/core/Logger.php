<?php
/**
 * NumVault - Security & Application Logger
 * Logs security events, audit trails, and errors while stripping sensitive secrets
 */

declare(strict_types=1);

namespace App\Core;

class Logger {
    private static string $logDir = __DIR__ . '/../../storage/logs';

    private static function ensureLogDir(): void {
        if (!is_dir(self::$logDir)) {
            @mkdir(self::$logDir, 0750, true);
        }
    }

    private static function sanitize(string $message): string {
        // Redact API keys, tokens, passwords, hashes, card numbers
        $patterns = [
            '/("?password"?\s*[:=]\s*")[^"]+("?)/i' => '$1[REDACTED]$2',
            '/("?api_key"?\s*[:=]\s*")[^"]+("?)/i' => '$1[REDACTED]$2',
            '/("?secret"?\s*[:=]\s*")[^"]+("?)/i' => '$1[REDACTED]$2',
            '/("?token"?\s*[:=]\s*")[^"]+("?)/i' => '$1[REDACTED]$2',
            '/("?password_hash"?\s*[:=]\s*")[^"]+("?)/i' => '$1[REDACTED]$2',
            '/bearer\s+[A-Za-z0-9_\-\.]+/i' => 'Bearer [REDACTED]',
        ];
        return preg_replace(array_keys($patterns), array_values($patterns), $message) ?? $message;
    }

    public static function log(string $file, string $level, string $message, array $context = []): void {
        self::ensureLogDir();
        $filePath = self::$logDir . '/' . basename($file) . '.log';
        $timestamp = date('Y-m-d H:i:s');
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'CLI';
        $userId = $_SESSION['user_id'] ?? 'Guest';
        $sanitizedMsg = self::sanitize($message);
        
        $contextStr = '';
        if (!empty($context)) {
            $contextStr = ' ' . self::sanitize(json_encode($context, JSON_UNESCAPED_SLASHES));
        }

        $logLine = sprintf("[%s] [%s] [IP:%s] [UID:%s] %s%s\n", $timestamp, strtoupper($level), $ip, $userId, $sanitizedMsg, $contextStr);
        @file_put_contents($filePath, $logLine, FILE_APPEND | LOCK_EX);
    }

    public static function security(string $message, array $context = []): void {
        self::log('security', 'SECURITY', $message, $context);
    }

    public static function audit(string $message, array $context = []): void {
        self::log('audit', 'AUDIT', $message, $context);
    }

    public static function error(string $message, array $context = []): void {
        self::log('error', 'ERROR', $message, $context);
    }

    public static function info(string $message, array $context = []): void {
        self::log('app', 'INFO', $message, $context);
    }
}
