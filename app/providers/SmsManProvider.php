<?php
/**
 * NumVault - SMS-Man Provider Adapter
 */

declare(strict_types=1);

namespace App\Providers;

class SmsManProvider extends BaseProvider implements SmsProviderInterface {
    public function testConnection(): array {
        if (empty($this->apiKey)) return ['success' => false, 'error' => 'API token is not configured.'];
        $res = $this->executeRequest($this->apiUrl . '/get-balance', ['token' => $this->apiKey]);
        $data = json_decode((string)$res['body'], true);
        if (isset($data['balance'])) {
            return ['success' => true, 'status' => 200, 'balance' => (float)$data['balance']];
        }
        return ['success' => false, 'status' => 400, 'error' => 'SMS-Man error: ' . ($data['error_msg'] ?? ($res['body'] ?: $res['error']))];
    }

    public function getBalance(): float {
        $conn = $this->testConnection();
        return $conn['success'] ? (float)$conn['balance'] : 0.00;
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

    public function getCountries(): array {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'SMS-Man API key is not configured.'];
        }
        $res = $this->executeRequest($this->apiUrl . '/countries', ['token' => $this->apiKey]);
        $data = json_decode((string)$res['body'], true);
        if (!is_array($data)) {
            return ['success' => false, 'error' => 'Failed to parse countries from SMS-Man: ' . ($res['body'] ?: $res['error'])];
        }

        $countries = [];
        foreach ($data as $key => $item) {
            if (!is_array($item)) continue;
            $provId = (string)($item['id'] ?? $key);
            $name = (string)($item['title'] ?? "Country {$provId}");
            $norm = BaseProvider::getIsoAndPrefix($name);
            $countries[] = [
                'provider_country_id' => $provId,
                'name'                => $name,
                'code'                => $norm['code'],
                'prefix'              => $norm['prefix']
            ];
        }
        return ['success' => true, 'countries' => $countries];
    }

    public function getServices(?string $providerCountryCode = null): array {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'SMS-Man API key is not configured.'];
        }
        $res = $this->executeRequest($this->apiUrl . '/applications', ['token' => $this->apiKey]);
        $data = json_decode((string)$res['body'], true);
        if (!is_array($data)) {
            return ['success' => false, 'error' => 'Failed to parse services from SMS-Man: ' . ($res['body'] ?: $res['error'])];
        }

        $prices = [];
        if (!empty($providerCountryCode)) {
            $limRes = $this->executeRequest($this->apiUrl . '/limits', [
                'token'      => $this->apiKey,
                'country_id' => $providerCountryCode
            ]);
            $limData = json_decode((string)$limRes['body'], true);
            if (is_array($limData)) {
                $prices = $limData;
            }
        }

        $services = [];
        foreach ($data as $key => $item) {
            if (!is_array($item)) continue;
            $provId = (string)($item['id'] ?? $key);
            $name = (string)($item['title'] ?? "Service {$provId}");
            $code = strtolower((string)preg_replace('/[^a-zA-Z0-9]/', '', $name));
            if (empty($code)) $code = "app_" . $provId;

            $cost = isset($prices[$provId]['cost']) ? (float)$prices[$provId]['cost'] : null;
            $count = isset($prices[$provId]['count']) ? (int)$prices[$provId]['count'] : null;

            $services[] = [
                'provider_service_id' => $provId,
                'name'                => $name,
                'code'                => $code,
                'cost'                => $cost,
                'count'               => $count
            ];
        }
        return ['success' => true, 'services' => $services];
    }
}
