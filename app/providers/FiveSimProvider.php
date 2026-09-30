<?php
/**
 * NumVault - 5SIM Provider Adapter
 */

declare(strict_types=1);

namespace App\Providers;

class FiveSimProvider extends BaseProvider implements SmsProviderInterface {
    protected function execute5Sim(string $endpoint, array $params = [], string $method = 'GET'): array {
        $ch = curl_init();
        $url = $this->apiUrl . '/' . ltrim($endpoint, '/');
        if ($method === 'GET' && !empty($params)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->apiKey,
            'Accept: application/json'
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => $httpCode,
            'body'   => $response ?: '',
            'data'   => json_decode((string)$response, true)
        ];
    }

    public function getBalance(): float {
        if (empty($this->apiKey)) return 0.00;
        $res = $this->execute5Sim('profile');
        if (!empty($res['data']['balance'])) {
            return (float)$res['data']['balance'];
        }
        return 0.00;
    }

    public function requestNumber(string $serviceCode, string $countryCode): array {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => '5SIM API key is not configured in Admin Settings.'];
        }
        $country = strtolower($countryCode);
        $service = strtolower($serviceCode);
        $res = $this->execute5Sim("buy/activation/{$country}/any/{$service}");

        if (!empty($res['data']['phone']) && !empty($res['data']['id'])) {
            return [
                'success'           => true,
                'provider_order_id' => (string)$res['data']['id'],
                'phone'             => '+' . ltrim((string)$res['data']['phone'], '+')
            ];
        }

        return [
            'success' => false,
            'error'   => $res['data']['message'] ?? ($res['body'] ?: 'No numbers available on 5SIM.')
        ];
    }

    public function checkOtp(string $providerOrderId): array {
        if (empty($this->apiKey)) return ['status' => 'waiting', 'otp' => null, 'text' => null];
        $res = $this->execute5Sim("check/{$providerOrderId}");

        if (!empty($res['data']['sms'])) {
            $smsList = $res['data']['sms'];
            if (is_array($smsList) && count($smsList) > 0) {
                $lastSms = end($smsList);
                return [
                    'status' => 'completed',
                    'otp'    => $lastSms['code'] ?? null,
                    'text'   => $lastSms['text'] ?? null
                ];
            }
        }

        if (isset($res['data']['status']) && in_array($res['data']['status'], ['CANCELED', 'TIMEOUT', 'BANNED'])) {
            return ['status' => 'cancelled', 'otp' => null, 'text' => null];
        }

        return ['status' => 'waiting', 'otp' => null, 'text' => null];
    }

    public function cancelNumber(string $providerOrderId): bool {
        if (empty($this->apiKey)) return false;
        $res = $this->execute5Sim("cancel/{$providerOrderId}", [], 'GET');
        return isset($res['data']['status']) && $res['data']['status'] === 'CANCELED';
    }

    public function getCountries(): array {
        $res = $this->execute5Sim('countries');
        if (empty($res['data']) || !is_array($res['data'])) {
            $ch = curl_init('https://5sim.net/v1/guest/countries');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $guestRes = curl_exec($ch);
            curl_close($ch);
            $res['data'] = json_decode((string)$guestRes, true);
        }

        if (empty($res['data']) || !is_array($res['data'])) {
            return ['success' => false, 'error' => 'Failed to retrieve country data from 5SIM: ' . ($res['body'] ?: 'Invalid JSON response')];
        }

        $countries = [];
        foreach ($res['data'] as $slugKey => $cData) {
            if (!is_array($cData)) continue;
            $slug = (string)$slugKey;
            $name = (string)($cData['text_en'] ?? ($cData['text'] ?? ucwords(str_replace('_', ' ', $slug))));

            $prefix = '';
            if (!empty($cData['prefix'])) {
                if (is_array($cData['prefix'])) {
                    $prefix = (string)key($cData['prefix']);
                } else {
                    $prefix = (string)$cData['prefix'];
                }
            }

            $iso = '';
            if (!empty($cData['iso'])) {
                if (is_array($cData['iso'])) {
                    $iso = strtoupper((string)key($cData['iso']));
                } else {
                    $iso = strtoupper((string)$cData['iso']);
                }
            }

            if (empty($iso) || empty($prefix)) {
                $norm = BaseProvider::getIsoAndPrefix($name, $iso, $prefix);
                if (empty($iso)) $iso = $norm['code'];
                if (empty($prefix)) $prefix = $norm['prefix'];
            }

            $countries[] = [
                'provider_country_id' => $slug,
                'name'                => $name,
                'code'                => $iso,
                'prefix'              => (str_starts_with($prefix, '+') ? '' : '+') . $prefix
            ];
        }

        return ['success' => true, 'countries' => $countries];
    }

    public function getServices(?string $providerCountryCode = null): array {
        $country = $providerCountryCode ? strtolower($providerCountryCode) : 'usa';
        $res = $this->execute5Sim("products/{$country}/any");
        if (empty($res['data']) || !is_array($res['data'])) {
            $ch = curl_init("https://5sim.net/v1/guest/products/{$country}/any");
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            $guestRes = curl_exec($ch);
            curl_close($ch);
            $res['data'] = json_decode((string)$guestRes, true);
        }

        if (empty($res['data']) || !is_array($res['data'])) {
            return ['success' => false, 'error' => "Failed to retrieve services for country '{$country}' from 5SIM: " . ($res['body'] ?: 'Invalid response')];
        }

        $services = [];
        foreach ($res['data'] as $svcCode => $item) {
            if (!is_array($item)) continue;
            $codeStr = (string)$svcCode;
            $cost = isset($item['Price']) ? (float)$item['Price'] : (isset($item['cost']) ? (float)$item['cost'] : null);
            $count = isset($item['Qty']) ? (int)$item['Qty'] : (isset($item['count']) ? (int)$item['count'] : null);
            $name = ucwords(str_replace(['_', '-'], ' ', $codeStr));

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
