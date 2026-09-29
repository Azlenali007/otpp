<?php
/**
 * NumVault - SMS Provider Factory
 * Resolves provider adapters with memory caching and direct mode support
 */

declare(strict_types=1);

namespace App\Providers;

use App\Core\Database;

class ProviderFactory {
    private static array $instances = [];

    public static function get(int $providerId): ?SmsProviderInterface {
        if ($providerId <= 0) {
            return null;
        }

        if (isset(self::$instances[$providerId])) {
            return self::$instances[$providerId];
        }

        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM providers WHERE id = ? LIMIT 1");
        $stmt->execute([$providerId]);
        $provider = $stmt->fetch();

        if (!$provider) {
            return null;
        }

        $apiUrl = $provider['api_url'] ?? '';
        $apiKey = $provider['api_key'] ?? '';
        $slug   = strtolower((string)($provider['slug'] ?? ''));

        // Support exact and prefixed slugs (e.g. 5sim, 5sim_2, direct, direct_2, custom, etc.)
        $instance = null;
        if (str_starts_with($slug, '5sim')) {
            $instance = new FiveSimProvider($apiUrl, $apiKey);
        } elseif (str_starts_with($slug, 'direct') || str_starts_with($slug, 'custom')) {
            $instance = new CustomApiProvider($apiUrl, $apiKey);
        } elseif (str_starts_with($slug, 'daisysms')) {
            $instance = new DaisySmsProvider($apiUrl, $apiKey);
        } elseif (str_starts_with($slug, 'sms_man')) {
            $instance = new SmsManProvider($apiUrl, $apiKey);
        } elseif (str_starts_with($slug, 'sms_activate')) {
            $instance = new SmsActivateProvider($apiUrl, $apiKey);
        } else {
            $instance = new CustomApiProvider($apiUrl, $apiKey);
        }

        self::$instances[$providerId] = $instance;
        return $instance;
    }
}
