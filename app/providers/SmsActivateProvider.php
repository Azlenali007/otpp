<?php
/**
 * NumVault - SMS-Activate Provider Adapter
 */

declare(strict_types=1);

namespace App\Providers;

class SmsActivateProvider extends BaseProvider implements SmsProviderInterface {
    public function getBalance(): float {
        if (empty($this->apiKey)) {
            return 0.00;
        }
        $res = $this->executeRequest($this->apiUrl, [
            'api_key' => $this->apiKey,
            'action'  => 'getBalance'
        ]);
        if (str_starts_with($res['body'], 'ACCESS_BALANCE:')) {
            return (float)str_replace('ACCESS_BALANCE:', '', $res['body']);
        }
        return 0.00;
    }

    public function requestNumber(string $serviceCode, string $countryCode): array {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'Provider API key is not configured in Admin Settings.'];
        }
        $res = $this->executeRequest($this->apiUrl, [
            'api_key' => $this->apiKey,
            'action'  => 'getNumber',
            'service' => $serviceCode,
            'country' => $countryCode
        ]);

        // Protocol: ACCESS_NUMBER:orderId:phoneNumber
        if (str_starts_with($res['body'], 'ACCESS_NUMBER:')) {
            $parts = explode(':', $res['body']);
            if (count($parts) >= 3) {
                return [
                    'success'           => true,
                    'provider_order_id' => $parts[1],
                    'phone'             => '+' . ltrim($parts[2], '+')
                ];
            }
        }

        return ['success' => false, 'error' => 'Provider returned: ' . ($res['body'] ?: $res['error'] ?: 'Out of stock or unavailable.')];
    }

    public function checkOtp(string $providerOrderId): array {
        if (empty($this->apiKey)) {
            return ['status' => 'waiting', 'otp' => null, 'text' => null];
        }
        $res = $this->executeRequest($this->apiUrl, [
            'api_key' => $this->apiKey,
            'action'  => 'getStatus',
            'id'      => $providerOrderId
        ]);

        // Protocol: STATUS_OK:code
        if (str_starts_with($res['body'], 'STATUS_OK:')) {
            $code = str_replace('STATUS_OK:', '', $res['body']);
            return [
                'status' => 'completed',
                'otp'    => trim($code),
                'text'   => "Your verification code is " . trim($code)
            ];
        }

        if ($res['body'] === 'STATUS_CANCEL') {
            return ['status' => 'cancelled', 'otp' => null, 'text' => null];
        }

        return ['status' => 'waiting', 'otp' => null, 'text' => null];
    }

    public function cancelNumber(string $providerOrderId): bool {
        if (empty($this->apiKey)) {
            return false;
        }
        $res = $this->executeRequest($this->apiUrl, [
            'api_key' => $this->apiKey,
            'action'  => 'setStatus',
            'status'  => '8', // 8 = cancel activation
            'id'      => $providerOrderId
        ]);
        return str_starts_with($res['body'], 'ACCESS_CANCEL');
    }
}
