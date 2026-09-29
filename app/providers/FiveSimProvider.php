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
}
