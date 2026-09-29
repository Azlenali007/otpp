<?php
/**
 * NumVault - Application-Level Rate Limiter & Throttler
 * Prevents credential stuffing, API flooding, and brute-force abuse
 */

declare(strict_types=1);

namespace App\Core;

class RateLimiter {
    private static string $cacheDir = __DIR__ . '/../../storage/cache/rate_limits';

    private static function ensureDir(): void {
        if (!is_dir(self::$cacheDir)) {
            @mkdir(self::$cacheDir, 0750, true);
        }
    }

    /**
     * Check if request exceeds rate limit
     * @param string $action Action key (e.g. 'login', 'register', 'order_create')
     * @param int $maxAttempts Maximum allowed attempts in window
     * @param int $decaySeconds Window duration in seconds
     * @param string|null $identifier Custom identifier, defaults to client IP
     * @return array ['allowed' => bool, 'remaining' => int, 'retry_after' => int]
     */
    public static function check(string $action, int $maxAttempts = 5, int $decaySeconds = 60, ?string $identifier = null): array {
        self::ensureDir();
        $ip = $identifier ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $key = md5($action . '_' . $ip);
        $file = self::$cacheDir . '/' . $key . '.json';
        $now = time();

        $data = ['attempts' => 0, 'reset_at' => $now + $decaySeconds];
        if (file_exists($file)) {
            $content = @file_get_contents($file);
            $parsed = json_decode((string)$content, true);
            if (is_array($parsed) && isset($parsed['reset_at']) && $parsed['reset_at'] > $now) {
                $data = $parsed;
            }
        }

        if ($data['attempts'] >= $maxAttempts) {
            $retryAfter = max(1, $data['reset_at'] - $now);
            Logger::security("Rate limit exceeded for action '{$action}' from IP {$ip}", [
                'attempts' => $data['attempts'],
                'max' => $maxAttempts,
                'retry_after' => $retryAfter
            ]);
            return [
                'allowed' => false,
                'remaining' => 0,
                'retry_after' => $retryAfter
            ];
        }

        return [
            'allowed' => true,
            'remaining' => max(0, $maxAttempts - $data['attempts']),
            'retry_after' => 0
        ];
    }

    /**
     * Hit / increment rate limit attempt
     */
    public static function hit(string $action, int $decaySeconds = 60, ?string $identifier = null): int {
        self::ensureDir();
        $ip = $identifier ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $key = md5($action . '_' . $ip);
        $file = self::$cacheDir . '/' . $key . '.json';
        $now = time();

        $data = ['attempts' => 0, 'reset_at' => $now + $decaySeconds];
        if (file_exists($file)) {
            $content = @file_get_contents($file);
            $parsed = json_decode((string)$content, true);
            if (is_array($parsed) && isset($parsed['reset_at']) && $parsed['reset_at'] > $now) {
                $data = $parsed;
            }
        }

        $data['attempts']++;
        @file_put_contents($file, json_encode($data), LOCK_EX);
        return $data['attempts'];
    }

    /**
     * Clear / reset rate limit for key upon successful action
     */
    public static function clear(string $action, ?string $identifier = null): void {
        self::ensureDir();
        $ip = $identifier ?? ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1');
        $key = md5($action . '_' . $ip);
        $file = self::$cacheDir . '/' . $key . '.json';
        if (file_exists($file)) {
            @unlink($file);
        }
    }
}
