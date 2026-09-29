<?php
/**
 * NumVault - Custom REST API Provider Adapter
 */

declare(strict_types=1);

namespace App\Providers;

class CustomApiProvider extends BaseProvider implements SmsProviderInterface {
    public function getBalance(): float {
        if (empty($this->apiKey)) return 0.00;
        $res = $this->executeRequest($this->apiUrl . '/balance', ['key' => $this->apiKey]);
        $data = json_decode((string)$res['body'], true);
        return (float)($data['balance'] ?? 0.00);
    }

    public function requestNumber(string $serviceCode, string $countryCode): array {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'Custom API provider key not configured.'];
        }
        $res = $this->executeRequest($this->apiUrl . '/request-number', [
            'key'     => $this->apiKey,
            'service' => $serviceCode,
            'country' => $countryCode
        ], 'POST');
        $data = json_decode((string)$res['body'], true);

        if (!empty($data['phone']) && !empty($data['order_id'])) {
            return [
                'success'           => true,
                'provider_order_id' => (string)$data['order_id'],
                'phone'             => '+' . ltrim((string)$data['phone'], '+')
            ];
        }

        return [
            'success' => false,
            'error'   => $data['error'] ?? 'Custom API route returned error.'
        ];
    }

    public function checkOtp(string $providerOrderId): array {
        if (empty($this->apiKey)) return ['status' => 'waiting', 'otp' => null, 'text' => null];
        $res = $this->executeRequest($this->apiUrl . '/check-otp', [
            'key'      => $this->apiKey,
            'order_id' => $providerOrderId
        ]);
        $data = json_decode((string)$res['body'], true);

        if (!empty($data['otp'])) {
            return [
                'status' => 'completed',
                'otp'    => (string)$data['otp'],
                'text'   => $data['message'] ?? ("OTP: " . $data['otp'])
            ];
        }

        if (isset($data['status']) && $data['status'] === 'expired') {
            return ['status' => 'expired', 'otp' => null, 'text' => null];
        }

        return ['status' => 'waiting', 'otp' => null, 'text' => null];
    }

    public function cancelNumber(string $providerOrderId): bool {
        if (empty($this->apiKey)) return false;
        $res = $this->executeRequest($this->apiUrl . '/cancel', [
            'key'      => $this->apiKey,
            'order_id' => $providerOrderId
        ], 'POST');
        $data = json_decode((string)$res['body'], true);
        return !empty($data['success']);
    }
}
