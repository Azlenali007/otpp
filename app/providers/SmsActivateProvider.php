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

        if (in_array($res['body'], ['STATUS_CANCEL', 'STATUS_EXPIRED', 'NO_ACTIVATION'])) {
            return ['status' => 'expired', 'otp' => null, 'text' => null];
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
            'status'  => '8',
            'id'      => $providerOrderId
        ]);
        return str_starts_with($res['body'], 'ACCESS_CANCEL');
    }

    public function getCountries(): array {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'API key is missing or not configured for this provider.'];
        }
        $res = $this->executeRequest($this->apiUrl, [
            'api_key' => $this->apiKey,
            'action'  => 'getCountries'
        ]);

        if (empty($res['body'])) {
            return ['success' => false, 'error' => 'No response received from provider gateway: ' . ($res['error'] ?: 'Connection failed/timed out')];
        }

        if (str_starts_with($res['body'], 'BAD_KEY')) {
            return ['success' => false, 'error' => 'Provider rejected API key (BAD_KEY). Please verify provider credentials in Admin.'];
        }

        $data = json_decode((string)$res['body'], true);
        if (!is_array($data)) {
            return ['success' => false, 'error' => 'Invalid data format returned by provider: ' . substr($res['body'], 0, 150)];
        }

        $countries = [];
        foreach ($data as $key => $item) {
            if (!is_array($item)) continue;
            $provId = (string)($item['id'] ?? $key);
            $name = (string)($item['eng'] ?? ($item['rus'] ?? "Country {$provId}"));
            $isoPrefix = BaseProvider::getIsoAndPrefix($name);
            $countries[] = [
                'provider_country_id' => $provId,
                'name'                => $name,
                'code'                => $isoPrefix['code'],
                'prefix'              => $isoPrefix['prefix']
            ];
        }

        return ['success' => true, 'countries' => $countries];
    }

    public function getServices(?string $providerCountryCode = null): array {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'API key is missing or not configured for this provider.'];
        }

        $params = [
            'api_key' => $this->apiKey,
            'action'  => 'getPrices'
        ];
        if ($providerCountryCode !== null && $providerCountryCode !== '') {
            $params['country'] = $providerCountryCode;
        }

        $res = $this->executeRequest($this->apiUrl, $params);
        if (empty($res['body'])) {
            return ['success' => false, 'error' => 'No response received from provider gateway: ' . ($res['error'] ?: 'Connection failed/timed out')];
        }

        if (str_starts_with($res['body'], 'BAD_KEY')) {
            return ['success' => false, 'error' => 'Provider rejected API key (BAD_KEY).'];
        }

        $data = json_decode((string)$res['body'], true);
        if (!is_array($data)) {
            return ['success' => false, 'error' => 'Invalid data format returned by provider: ' . substr($res['body'], 0, 150)];
        }

        $rawServices = $data;
        if ($providerCountryCode !== null && isset($data[$providerCountryCode]) && is_array($data[$providerCountryCode])) {
            $rawServices = $data[$providerCountryCode];
        } elseif (isset($data['0']) && is_array($data['0'])) {
            $rawServices = $data['0'];
        }

        $nameMap = [
            'wa' => 'WhatsApp', 'tg' => 'Telegram', 'go' => 'Google / Gmail', 'ig' => 'Instagram',
            'tw' => 'Twitter / X', 'fb' => 'Facebook', 'vi' => 'Viber', 'lf' => 'TikTok',
            'ub' => 'Uber', 'oi' => 'Tinder', 'ds' => 'Discord', 'am' => 'Amazon',
            'ni' => 'Steam', 'me' => 'Line', 'mb' => 'Yahoo', 'wb' => 'WeChat',
            'ot' => 'Any Other Service', 'dr' => 'OpenAI / ChatGPT', 'openai' => 'OpenAI / ChatGPT',
            'full' => 'Full SMS Service'
        ];

        $services = [];
        foreach ($rawServices as $svcCode => $svcInfo) {
            if (!is_array($svcInfo)) continue;
            $codeStr = (string)$svcCode;
            $cost = isset($svcInfo['cost']) ? (float)$svcInfo['cost'] : (isset($svcInfo['price']) ? (float)$svcInfo['price'] : null);
            $count = isset($svcInfo['count']) ? (int)$svcInfo['count'] : (isset($svcInfo['qty']) ? (int)$svcInfo['qty'] : null);
            $name = $nameMap[strtolower($codeStr)] ?? (ucwords(str_replace('_', ' ', $codeStr)) . " ({$codeStr})");

            $services[] = [
                'provider_service_id' => $codeStr,
                'name'                => $name,
                'code'                => $codeStr,
                'cost'                => $cost,
                'count'               => $count
            ];
        }

        return ['success' => true, 'services' => $services];
    }
}
