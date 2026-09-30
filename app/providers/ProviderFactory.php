<?php
/**
 * NumVault - SMS Provider Factory
 * Resolves provider adapters for uOTP API
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

        $apiUrl = (string)($provider['api_url'] ?? '');
        $apiKey = (string)($provider['api_key'] ?? '');

        // Use official uOTP Provider adapter
        $instance = new UotpProvider($apiUrl, $apiKey);

        self::$instances[$providerId] = $instance;
        return $instance;
    }
}
