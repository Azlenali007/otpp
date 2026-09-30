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

    public function getCountries(): array {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'Custom API provider key not configured.'];
        }
        $res = $this->executeRequest($this->apiUrl . '/countries', ['key' => $this->apiKey]);
        $data = json_decode((string)$res['body'], true);
        if (!is_array($data)) {
            return ['success' => false, 'error' => 'Invalid response from Custom API /countries: ' . ($res['body'] ?: $res['error'])];
        }

        $countries = [];
        foreach ($data as $key => $item) {
            if (!is_array($item)) continue;
            $provId = (string)($item['id'] ?? ($item['code'] ?? $key));
            $name = (string)($item['name'] ?? "Country {$provId}");
            $code = (string)($item['code'] ?? '');
            $prefix = (string)($item['prefix'] ?? '');
            $norm = BaseProvider::getIsoAndPrefix($name, $code, $prefix);

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
            return ['success' => false, 'error' => 'Custom API provider key not configured.'];
        }
        $params = ['key' => $this->apiKey];
        if (!empty($providerCountryCode)) {
            $params['country'] = $providerCountryCode;
        }
        $res = $this->executeRequest($this->apiUrl . '/services', $params);
        $data = json_decode((string)$res['body'], true);
        if (!is_array($data)) {
            return ['success' => false, 'error' => 'Invalid response from Custom API /services: ' . ($res['body'] ?: $res['error'])];
        }

        $services = [];
        foreach ($data as $key => $item) {
            if (!is_array($item)) continue;
            $provId = (string)($item['id'] ?? ($item['code'] ?? $key));
            $name = (string)($item['name'] ?? "Service {$provId}");
            $code = strtolower((string)($item['code'] ?? preg_replace('/[^a-zA-Z0-9]/', '', $name)));
            $cost = isset($item['cost']) ? (float)$item['cost'] : (isset($item['price']) ? (float)$item['price'] : null);
            $count = isset($item['count']) ? (int)$item['count'] : (isset($item['stock']) ? (int)$item['stock'] : null);

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
