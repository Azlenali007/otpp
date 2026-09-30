<?php
/**
 * NumVault - Custom Provider API Engine
 * Standardized implementation supporting official 5SIM API (https://5sim.net/docs#user)
 * and Custom REST endpoints with full token authentication and strict URL handling.
 */

declare(strict_types=1);

namespace App\Providers;

use App\Core\Logger;

class CustomApiProvider extends BaseProvider implements SmsProviderInterface {

    /**
     * Sanitizes and constructs an absolute request URL without invalid domain mutations
     */
    protected function buildUrl(string $endpoint): string {
        $base = trim($this->apiUrl);

        // Remove accidental $ characters or invalid prefixes
        $base = str_replace('$', '', $base);
        // Correct any accidental api1.5sim.net or api1.$5sim.net to https://5sim.net
        $base = preg_replace('#^https?://api1\.5sim\.net#i', 'https://5sim.net', $base);

        if (!preg_match('#^https?://#i', $base)) {
            $base = 'https://' . ltrim($base, '/');
        }
        $base = rtrim($base, '/');

        $path = '/' . ltrim(trim($endpoint), '/');

        // Prevent duplicating /v1 when base URL already specifies it
        if (str_ends_with($base, '/v1') && str_starts_with($path, '/v1/')) {
            $path = substr($path, 3);
        }

        return $base . $path;
    }

    /**
     * Executes an HTTP request with proper headers, Bearer authentication, and status validation
     */
    protected function executeRequest(string $endpoint, array $params = [], string $method = 'GET'): array {
        $url = $this->buildUrl($endpoint);
        $ch = curl_init();

        $headers = [
            'Accept: application/json',
            'User-Agent: NumVault-Carrier-Client/2.0'
        ];

        // Apply Bearer token authentication as required by 5SIM and REST APIs
        if (!empty($this->apiKey)) {
            $headers[] = 'Authorization: Bearer ' . trim($this->apiKey);
        }

        if ($method === 'GET' && !empty($params)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if (!empty($params)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
                $headers[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        // Safe network error reporting
        if ($curlError) {
            $host = parse_url($url, PHP_URL_HOST);
            $msg = "Connection failed: {$curlError}";
            if ($curlErrno === CURLE_COULDNT_RESOLVE_HOST) {
                $msg = "Could not resolve host '{$host}'. Please verify the API Base URL.";
            } elseif ($curlErrno === CURLE_OPERATION_TIMEDOUT) {
                $msg = "Connection to provider '{$host}' timed out. Provider API took too long to respond.";
            }
            Logger::error("Custom Provider cURL Error ({$curlErrno}): {$msg}");
            return [
                'success' => false,
                'status'  => 0,
                'body'    => '',
                'data'    => null,
                'error'   => $msg
            ];
        }

        // Check HTTP error codes safely
        if ($httpCode === 401) {
            return [
                'success' => false,
                'status'  => 401,
                'body'    => $response ?: '',
                'data'    => null,
                'error'   => 'HTTP 401 Unauthorized: Invalid or missing API token. Please verify your provider API key.'
            ];
        }
        if ($httpCode === 403) {
            return [
                'success' => false,
                'status'  => 403,
                'body'    => $response ?: '',
                'data'    => null,
                'error'   => 'HTTP 403 Forbidden: Access denied by provider gateway.'
            ];
        }
        if ($httpCode === 404) {
            return [
                'success' => false,
                'status'  => 404,
                'body'    => $response ?: '',
                'data'    => null,
                'error'   => "HTTP 404 Not Found: Endpoint '{$endpoint}' was not found on this provider."
            ];
        }
        if ($httpCode === 429) {
            return [
                'success' => false,
                'status'  => 429,
                'body'    => $response ?: '',
                'data'    => null,
                'error'   => 'HTTP 429 Too Many Requests: Provider API rate limit exceeded. Please wait a moment and try again.'
            ];
        }
        if ($httpCode >= 500) {
            return [
                'success' => false,
                'status'  => $httpCode,
                'body'    => $response ?: '',
                'data'    => null,
                'error'   => "HTTP {$httpCode} Provider Error: The provider server encountered an internal failure."
            ];
        }

        $decoded = json_decode((string)$response, true);
        if ($decoded === null && !empty($response)) {
            return [
                'success' => false,
                'status'  => $httpCode,
                'body'    => $response,
                'data'    => null,
                'error'   => 'Invalid JSON response from provider: ' . substr(strip_tags((string)$response), 0, 100)
            ];
        }

        return [
            'success' => ($httpCode >= 200 && $httpCode < 300),
            'status'  => $httpCode,
            'body'    => $response ?: '',
            'data'    => $decoded,
            'error'   => null
        ];
    }

    /**
     * Tests connectivity to provider using documented GET /v1/user/profile endpoint
     */
    public function testConnection(): array {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'status'  => 0,
                'error'   => 'API token is required for authentication. Please provide the Bearer API key in Provider Settings.'
            ];
        }

        $res = $this->executeRequest('/v1/user/profile');
        if (!$res['success']) {
            return [
                'success' => false,
                'status'  => $res['status'],
                'error'   => $res['error'] ?: 'Authentication test failed. Please verify provider credentials.'
            ];
        }

        $data = $res['data'] ?? [];
        $balance = isset($data['balance']) ? (float)$data['balance'] : 0.00;
        $email = $data['email'] ?? null;
        $rating = isset($data['rating']) ? (float)$data['rating'] : null;

        return [
            'success' => true,
            'status'  => 200,
            'balance' => $balance,
            'email'   => $email,
            'rating'  => $rating
        ];
    }

    /**
     * Fetches current account balance from provider
     */
    public function getBalance(): float {
        $conn = $this->testConnection();
        return $conn['success'] ? (float)$conn['balance'] : 0.00;
    }

    /**
     * Imports supported destination countries using 5SIM documented /v1/guest/countries and /v1/guest/prices
     */
    public function getCountries(): array {
        // 1. Try documented 5SIM /v1/guest/countries endpoint
        $res = $this->executeRequest('/v1/guest/countries');
        if ($res['success'] && is_array($res['data']) && !empty($res['data'])) {
            $countries = [];
            foreach ($res['data'] as $slugKey => $cData) {
                if (!is_array($cData)) continue;
                $slug = (string)$slugKey;
                $name = (string)($cData['text_en'] ?? ($cData['text'] ?? ucwords(str_replace('_', ' ', $slug))));

                $prefix = '';
                if (!empty($cData['prefix'])) {
                    $prefix = is_array($cData['prefix']) ? (string)key($cData['prefix']) : (string)$cData['prefix'];
                }

                $iso = '';
                if (!empty($cData['iso'])) {
                    $iso = is_array($cData['iso']) ? strtoupper((string)key($cData['iso'])) : strtoupper((string)$cData['iso']);
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

        // 2. Fallback to documented /v1/guest/prices endpoint where keys are all supported countries
        $resPrices = $this->executeRequest('/v1/guest/prices');
        if ($resPrices['success'] && is_array($resPrices['data']) && !empty($resPrices['data'])) {
            $countries = [];
            foreach ($resPrices['data'] as $slugKey => $products) {
                if (!is_array($products)) continue;
                $slug = (string)$slugKey;
                $norm = BaseProvider::getIsoAndPrefix($slug);
                $name = ucwords(str_replace('_', ' ', $slug));

                $countries[] = [
                    'provider_country_id' => $slug,
                    'name'                => $name,
                    'code'                => $norm['code'],
                    'prefix'              => $norm['prefix']
                ];
            }
            return ['success' => true, 'countries' => $countries];
        }

        return [
            'success' => false,
            'error'   => 'Failed to retrieve countries from provider: ' . ($res['error'] ?? ($resPrices['error'] ?? 'No country data returned.'))
        ];
    }

    /**
     * Imports services, live carrier costs, and available stock using documented 5SIM products and prices
     */
    public function getServices(?string $providerCountryCode = null): array {
        $country = !empty($providerCountryCode) ? strtolower(trim($providerCountryCode)) : 'usa';

        // 1. Try documented 5SIM /v1/guest/products/{country}/{operator} endpoint
        $resProducts = $this->executeRequest("/v1/guest/products/{$country}/any");
        if ($resProducts['success'] && is_array($resProducts['data']) && !empty($resProducts['data'])) {
            $services = [];
            foreach ($resProducts['data'] as $svcCode => $item) {
                if (!is_array($item)) continue;
                $codeStr = (string)$svcCode;
                $cost = isset($item['Price']) ? (float)$item['Price'] : (isset($item['cost']) ? (float)$item['cost'] : null);
                $count = isset($item['Qty']) ? (int)$item['Qty'] : (isset($item['count']) ? (int)$item['count'] : null);

                // Format friendly application names
                $name = self::formatServiceName($codeStr);

                $services[] = [
                    'provider_service_id' => $codeStr,
                    'name'                => $name,
                    'code'                => $codeStr,
                    'cost'                => $cost,
                    'count'               => $count,
                    'category'            => $item['Category'] ?? 'activation'
                ];
            }
            return ['success' => true, 'services' => $services];
        }

        // 2. Query documented /v1/guest/prices endpoint to extract product pricing and stock
        $resPrices = $this->executeRequest('/v1/guest/prices');
        if ($resPrices['success'] && is_array($resPrices['data']) && !empty($resPrices['data'])) {
            $allPrices = $resPrices['data'];
            $targetPrices = $allPrices[$country] ?? reset($allPrices);

            if (is_array($targetPrices)) {
                $services = [];
                foreach ($targetPrices as $svcCode => $operators) {
                    if (!is_array($operators)) continue;
                    $codeStr = (string)$svcCode;

                    // Extract best cost and total stock count across operators
                    $bestCost = null;
                    $totalCount = 0;
                    foreach ($operators as $opName => $opData) {
                        if (!is_array($opData)) continue;
                        $c = isset($opData['cost']) ? (float)$opData['cost'] : null;
                        $cnt = isset($opData['count']) ? (int)$opData['count'] : 0;
                        $totalCount += $cnt;
                        if ($c !== null && $c > 0 && ($bestCost === null || $c < $bestCost)) {
                            $bestCost = $c;
                        }
                    }

                    $name = self::formatServiceName($codeStr);

                    $services[] = [
                        'provider_service_id' => $codeStr,
                        'name'                => $name,
                        'code'                => $codeStr,
                        'cost'                => $bestCost,
                        'count'               => $totalCount
                    ];
                }
                return ['success' => true, 'services' => $services];
            }
        }

        return [
            'success' => false,
            'error'   => "Failed to retrieve services for country '{$country}' from provider: " . ($resProducts['error'] ?? ($resPrices['error'] ?? 'No service data returned.'))
        ];
    }

    /**
     * Orders a live virtual phone number using documented 5SIM /v1/user/buy/activation/{country}/{operator}/{product}
     */
    public function requestNumber(string $serviceCode, string $countryCode): array {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'API token is not configured in Provider Settings.'];
        }

        $country = strtolower(trim($countryCode)) ?: 'any';
        $operator = 'any';
        $product = strtolower(trim($serviceCode));

        $endpoint = "/v1/user/buy/activation/{$country}/{$operator}/{$product}";
        $res = $this->executeRequest($endpoint);

        if (!$res['success'] || empty($res['data'])) {
            $err = $res['data']['message'] ?? ($res['error'] ?: ($res['body'] ?: 'No phone numbers available on 5SIM for this route.'));
            return ['success' => false, 'error' => "5SIM Error: {$err}"];
        }

        $data = $res['data'];
        if (!empty($data['phone']) && !empty($data['id'])) {
            return [
                'success'           => true,
                'provider_order_id' => (string)$data['id'],
                'phone'             => '+' . ltrim((string)$data['phone'], '+'),
                'cost_price'        => (float)($data['price'] ?? 0),
                'operator'          => (string)($data['operator'] ?? '')
            ];
        }

        return [
            'success' => false,
            'error'   => $data['message'] ?? 'Could not allocate phone number from provider.'
        ];
    }

    /**
     * Checks SMS verification code using documented 5SIM /v1/user/check/{id}
     */
    public function checkOtp(string $providerOrderId): array {
        if (empty($this->apiKey)) {
            return ['status' => 'waiting', 'otp' => null, 'text' => null];
        }

        $orderId = trim($providerOrderId);
        $res = $this->executeRequest("/v1/user/check/{$orderId}");

        if (!$res['success'] || empty($res['data'])) {
            return ['status' => 'waiting', 'otp' => null, 'text' => null];
        }

        $data = $res['data'];

        // If SMS message received
        if (!empty($data['sms']) && is_array($data['sms']) && count($data['sms']) > 0) {
            $lastSms = end($data['sms']);
            $otp = (string)($lastSms['code'] ?? '');
            $text = (string)($lastSms['text'] ?? '');
            if (!empty($otp) || !empty($text)) {
                return [
                    'status' => 'completed',
                    'otp'    => $otp ?: null,
                    'text'   => $text ?: "Code: {$otp}"
                ];
            }
        }

        // Terminal statuses
        if (isset($data['status']) && in_array(strtoupper((string)$data['status']), ['CANCELED', 'TIMEOUT', 'BANNED'])) {
            return ['status' => 'cancelled', 'otp' => null, 'text' => null];
        }

        return ['status' => 'waiting', 'otp' => null, 'text' => null];
    }

    /**
     * Cancels an active number order using documented 5SIM /v1/user/cancel/{id}
     */
    public function cancelNumber(string $providerOrderId): bool {
        if (empty($this->apiKey)) return false;
        $orderId = trim($providerOrderId);
        $res = $this->executeRequest("/v1/user/cancel/{$orderId}");
        return $res['success'] && isset($res['data']['status']) && strtoupper((string)$res['data']['status']) === 'CANCELED';
    }

    /**
     * Finishes an active order using documented 5SIM /v1/user/finish/{id}
     */
    public function finishNumber(string $providerOrderId): bool {
        if (empty($this->apiKey)) return false;
        $orderId = trim($providerOrderId);
        $res = $this->executeRequest("/v1/user/finish/{$orderId}");
        return $res['success'] && isset($res['data']['status']) && strtoupper((string)$res['data']['status']) === 'FINISHED';
    }

    /**
     * Helper to format human-readable service application names
     */
    public static function formatServiceName(string $code): string {
        $map = [
            'wa'      => 'WhatsApp',
            'whatsapp'=> 'WhatsApp',
            'tg'      => 'Telegram',
            'telegram'=> 'Telegram',
            'go'      => 'Google / Gmail',
            'google'  => 'Google / Gmail',
            'openai'  => 'OpenAI / ChatGPT',
            'ig'      => 'Instagram',
            'instagram'=> 'Instagram',
            'tw'      => 'Twitter / X',
            'twitter' => 'Twitter / X',
            'fb'      => 'Facebook',
            'facebook'=> 'Facebook',
            'ub'      => 'Uber',
            'uber'    => 'Uber',
            'ds'      => 'Discord',
            'discord' => 'Discord',
            'am'      => 'Amazon',
            'amazon'  => 'Amazon',
            'vi'      => 'Viber',
            'viber'   => 'Viber',
            'lf'      => 'TikTok',
            'tiktok'  => 'TikTok',
            'oi'      => 'Tinder',
            'tinder'  => 'Tinder',
            'ni'      => 'Steam',
            'steam'   => 'Steam',
            '1688'    => '1688.com',
            '7eleven' => '7-Eleven',
        ];
        $lower = strtolower(trim($code));
        return $map[$lower] ?? ucwords(str_replace(['_', '-'], ' ', $code));
    }
}
