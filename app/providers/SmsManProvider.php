<?php
/**
 * NumVault - SMS-Man Provider Adapter
 */

declare(strict_types=1);

namespace App\Providers;

class SmsManProvider extends BaseProvider implements SmsProviderInterface {
    public function getBalance(): float {
        if (empty($this->apiKey)) return 0.00;
        $res = $this->executeRequest($this->apiUrl . '/get-balance', ['token' => $this->apiKey]);
        $data = json_decode((string)$res['body'], true);
        return (float)($data['balance'] ?? 0.00);
    }

    public function requestNumber(string $serviceCode, string $countryCode): array {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'SMS-Man API key is not configured in Admin Settings.'];
        }
        $res = $this->executeRequest($this->apiUrl . '/get-number', [
            'token'       => $this->apiKey,
            'country_id'  => $countryCode,
            'application_id' => $serviceCode
        ]);
        $data = json_decode((string)$res['body'], true);

        if (!empty($data['number']) && !empty($data['request_id'])) {
            return [
                'success'           => true,
                'provider_order_id' => (string)$data['request_id'],
                'phone'             => '+' . ltrim((string)$data['number'], '+')
            ];
        }

        return [
            'success' => false,
            'error'   => $data['error_msg'] ?? 'No available numbers on SMS-Man.'
        ];
    }

    public function checkOtp(string $providerOrderId): array {
        if (empty($this->apiKey)) return ['status' => 'waiting', 'otp' => null, 'text' => null];
        $res = $this->executeRequest($this->apiUrl . '/get-sms', [
            'token'      => $this->apiKey,
            'request_id' => $providerOrderId
        ]);
        $data = json_decode((string)$res['body'], true);

        if (!empty($data['sms_code'])) {
            return [
                'status' => 'completed',
                'otp'    => (string)$data['sms_code'],
                'text'   => "Your code is " . $data['sms_code']
            ];
        }

        return ['status' => 'waiting', 'otp' => null, 'text' => null];
    }

    public function cancelNumber(string $providerOrderId): bool {
        if (empty($this->apiKey)) return false;
        $res = $this->executeRequest($this->apiUrl . '/set-status', [
            'token'      => $this->apiKey,
            'request_id' => $providerOrderId,
            'status'     => 'reject'
        ]);
        $data = json_decode((string)$res['body'], true);
        return !empty($data['success']);
    }
}
