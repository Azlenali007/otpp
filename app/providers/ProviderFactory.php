<?php
/**
 * NumVault - SMS Provider Factory
 */

declare(strict_types=1);

namespace App\Providers;

use App\Core\Database;

class ProviderFactory {
    public static function get(int $providerId): ?SmsProviderInterface {
        $pdo = Database::getConnection();
        $stmt = $pdo->prepare("SELECT * FROM providers WHERE id = ? LIMIT 1");
        $stmt->execute([$providerId]);
        $provider = $stmt->fetch();

        if (!$provider) {
            return null;
        }

        $apiUrl = $provider['api_url'] ?? '';
        $apiKey = $provider['api_key'] ?? '';
        $slug   = $provider['slug'] ?? '';

        return match ($slug) {
            'sms_activate' => new SmsActivateProvider($apiUrl, $apiKey),
            '5sim'         => new FiveSimProvider($apiUrl, $apiKey),
            'daisysms'     => new DaisySmsProvider($apiUrl, $apiKey),
            'sms_man'      => new SmsManProvider($apiUrl, $apiKey),
            'custom'       => new CustomApiProvider($apiUrl, $apiKey),
            default        => new SmsActivateProvider($apiUrl, $apiKey),
        };
    }
}
