<?php
/**
 * NumVault - Official uOTP API Adapter
 * Standardized protocol implementation for https://uotp.store/api/stubs/handler_api.php
 */

declare(strict_types=1);

namespace App\Providers;

use App\Core\Logger;

class UotpProvider extends BaseProvider implements SmsProviderInterface {

    public const DEFAULT_API_URL = 'https://uotp.store/api/stubs/handler_api.php';

    public function __construct(string $apiUrl = '', string $apiKey = '') {
        $cleanUrl = trim($apiUrl);
        if (empty($cleanUrl) || str_contains($cleanUrl, '5sim.net')) {
            $cleanUrl = self::DEFAULT_API_URL;
        }
        parent::__construct($cleanUrl, trim($apiKey));
    }

    /**
     * Executes server-side HTTP GET request with SSL verification, redirects and safe error handling
     */
    protected function callUotp(array $params): array {
        // Enforce required query parameter: api_key={API_KEY}
        $params['api_key'] = $this->apiKey;

        // Build cleanly encoded HTTPS URL
        $separator = str_contains($this->apiUrl, '?') ? '&' : '?';
        $url = $this->apiUrl . $separator . http_build_query($params);

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/122.0.0.0 Safari/537.36',
            CURLOPT_HTTPHEADER     => [
                'Accept: text/plain, text/html, application/json, */*',
                'Accept-Language: en-US,en;q=0.9'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        $curlErrno = curl_errno($ch);
        curl_close($ch);

        if ($curlError) {
            $host = parse_url($this->apiUrl, PHP_URL_HOST);
            $msg = "Connection failed: {$curlError}";
            if ($curlErrno === CURLE_COULDNT_RESOLVE_HOST) {
                $msg = "Could not resolve provider host '{$host}'. Please verify network and Base URL.";
            } elseif ($curlErrno === CURLE_OPERATION_TIMEDOUT) {
                $msg = "Connection to uOTP provider timed out. Gateway took too long to respond.";
            }
            Logger::error("uOTP cURL error ({$curlErrno}): {$msg}");
            return [
                'success' => false,
                'status'  => 0,
                'body'    => '',
                'error'   => $msg
            ];
        }

        $body = trim((string)$response);

        // Capture and sanitize 403 Forbidden with actual response body
        if ($httpCode === 403) {
            $sanitizedBody = strip_tags($body);
            $sanitizedBody = preg_replace('/\s+/', ' ', $sanitizedBody);
            if (!empty($this->apiKey)) {
                $sanitizedBody = str_replace($this->apiKey, '***', $sanitizedBody);
            }
            $sanitizedBody = trim(substr($sanitizedBody, 0, 150));
            $errorMsg = "HTTP 403 Forbidden" . ($sanitizedBody !== '' ? " | Provider response: {$sanitizedBody}" : ": Access denied by uOTP server.");
            Logger::error("uOTP HTTP 403: {$errorMsg}");
            return [
                'success' => false,
                'status'  => 403,
                'body'    => $body,
                'error'   => $errorMsg
            ];
        }

        if ($httpCode === 401 || $body === 'BAD_KEY') {
            return [
                'success' => false,
                'status'  => 401,
                'body'    => $body,
                'error'   => 'Provider rejected API key (BAD_KEY). Please check your uOTP API key in Provider Settings.'
            ];
        }

        if ($httpCode >= 500) {
            $sanitized = trim(substr(strip_tags($body), 0, 100));
            return [
                'success' => false,
                'status'  => $httpCode,
                'body'    => $body,
                'error'   => "HTTP {$httpCode} Provider Gateway Error" . ($sanitized !== '' ? ": {$sanitized}" : ".")
            ];
        }

        return [
            'success' => ($httpCode >= 200 && $httpCode < 300),
            'status'  => $httpCode,
            'body'    => $body,
            'error'   => null
        ];
    }

    /**
     * 1. Balance Check & Connection Test
     * GET: https://uotp.store/api/stubs/handler_api.php?action=getBalance&api_key={API_KEY}
     */
    public function testConnection(): array {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'status'  => 0,
                'error'   => 'API key is required. Please enter your uOTP API key in Provider Settings.'
            ];
        }

        $res = $this->callUotp(['action' => 'getBalance']);

        if (!$res['success']) {
            return [
                'success' => false,
                'status'  => $res['status'],
                'error'   => $res['error'] ?: 'Authentication failed.'
            ];
        }

        $body = $res['body'];

        if (str_starts_with($body, 'ACCESS_BALANCE:')) {
            $balance = (float)str_replace('ACCESS_BALANCE:', '', $body);
            return [
                'success' => true,
                'status'  => 200,
                'balance' => $balance
            ];
        }

        if ($body === 'BAD_KEY') {
            return [
                'success' => false,
                'status'  => 401,
                'error'   => 'Invalid uOTP API Key (BAD_KEY). Please verify your API key.'
            ];
        }

        if ($body === 'ERROR_SQL') {
            return [
                'success' => false,
                'status'  => 500,
                'error'   => 'uOTP provider database error (ERROR_SQL).'
            ];
        }

        return [
            'success' => false,
            'status'  => $res['status'],
            'error'   => "Unexpected response from uOTP: " . substr(strip_tags($body), 0, 100)
        ];
    }

    public function getBalance(): float {
        $conn = $this->testConnection();
        return $conn['success'] ? (float)$conn['balance'] : 0.00;
    }

    /**
     * 2. Buy / Get Number
     * GET: https://uotp.store/api/stubs/handler_api.php?api_key={API_KEY}&action=getNumber&service={SERVICE}&country={COUNTRY}&operator={OPERATOR}
     */
    public function requestNumber(string $serviceCode, string $countryCode, ?string $operator = null): array {
        if (empty($this->apiKey)) {
            return [
                'success' => false,
                'error'   => 'uOTP API key is not configured in Admin Settings.'
            ];
        }

        $params = [
            'action'   => 'getNumber',
            'service'  => strtolower(trim($serviceCode)),
            'country'  => trim($countryCode)
        ];

        if (!empty($operator) && strtolower(trim($operator)) !== 'any') {
            $params['operator'] = trim($operator);
        }

        $res = $this->callUotp($params);

        if (!$res['success']) {
            return [
                'success' => false,
                'error'   => $res['error'] ?: 'Failed to connect to uOTP gateway.'
            ];
        }

        $body = $res['body'];

        // Format: ACCESS_NUMBER:{ID}:{PHONE} (optional :{COST})
        if (str_starts_with($body, 'ACCESS_NUMBER:')) {
            $parts = explode(':', $body);
            if (count($parts) >= 3) {
                $orderId = trim($parts[1]);
                $phone = trim($parts[2]);
                $cost = isset($parts[3]) && is_numeric($parts[3]) ? (float)$parts[3] : 0.00;

                return [
                    'success'           => true,
                    'provider_order_id' => $orderId,
                    'phone'             => '+' . ltrim($phone, '+'),
                    'cost_price'        => $cost,
                    'raw_response'      => $body
                ];
            }
        }

        // Handle standard uOTP error codes
        $errorMap = [
            'NO_NUMBERS'     => 'No phone numbers currently available for this service and country on uOTP.',
            'NO_BALANCE'     => 'Provider account balance is depleted (NO_BALANCE). Please top up uOTP balance.',
            'BAD_KEY'        => 'Invalid uOTP API Key. Please verify credentials in Provider Settings.',
            'BAD_ACTION'     => 'Invalid API action sent to uOTP.',
            'BAD_SERVICE'    => 'The requested service code is not supported by uOTP.',
            'BAD_COUNTRY'    => 'Invalid country code specified for uOTP.',
            'WRONG_OPERATOR' => 'The requested telecom operator is not available for this country.',
            'NO_CONNECTION'  => 'uOTP carrier gateway currently offline or unable to connect for this country.',
            'BANNED'         => 'The uOTP account is temporarily restricted (BANNED).',
            'ERROR_SQL'      => 'uOTP gateway encountered an internal database error.'
        ];

        $friendlyError = $errorMap[$body] ?? ("uOTP returned: " . ($body ?: 'Empty response received.'));

        return [
            'success' => false,
            'error'   => $friendlyError
        ];
    }

    /**
     * 3. SMS / OTP Status
     * GET: https://uotp.store/api/stubs/handler_api.php?api_key={API_KEY}&action=getStatus&id={ID}
     */
    public function checkOtp(string $providerOrderId): array {
        if (empty($this->apiKey)) {
            return ['status' => 'waiting', 'otp' => null, 'text' => null];
        }

        $orderId = trim($providerOrderId);
        $res = $this->callUotp([
            'action' => 'getStatus',
            'id'     => $orderId
        ]);

        if (!$res['success']) {
            return ['status' => 'waiting', 'otp' => null, 'text' => null, 'network_error' => $res['error']];
        }

        $body = $res['body'];

        // Format: STATUS_OK:{CODE}
        if (str_starts_with($body, 'STATUS_OK:')) {
            $code = trim(str_replace('STATUS_OK:', '', $body));
            return [
                'status' => 'completed',
                'otp'    => $code,
                'text'   => "Your verification code is: {$code}"
            ];
        }

        // Waiting states
        if ($body === 'STATUS_WAIT_CODE' || $body === 'STATUS_WAIT_RESEND') {
            return [
                'status' => 'waiting',
                'otp'    => null,
                'text'   => null
            ];
        }

        // Terminal cancelled / expired states
        if (in_array($body, ['STATUS_CANCEL', 'NO_ACTIVATION', 'ERROR_DATABASE'])) {
            return [
                'status' => 'cancelled',
                'otp'    => null,
                'text'   => null
            ];
        }

        return ['status' => 'waiting', 'otp' => null, 'text' => null];
    }

    /**
     * 4. Change Activation Status
     * GET: https://uotp.store/api/stubs/handler_api.php?api_key={API_KEY}&action=setStatus&status={STATUS}&id={ID}
     * status = 1 (ready), 3 (retry/resend), 6 (finish/completed), 8 (cancel/refund)
     */
    public function setActivationStatus(string $providerOrderId, int $status): bool {
        if (empty($this->apiKey)) return false;

        $orderId = trim($providerOrderId);
        $res = $this->callUotp([
            'action' => 'setStatus',
            'status' => (string)$status,
            'id'     => $orderId
        ]);

        if (!$res['success']) return false;

        $body = $res['body'];

        return in_array($body, [
            'ACCESS_READY',
            'ACCESS_RETRY_GET',
            'ACCESS_ACTIVATION',
            'ACCESS_CANCEL'
        ], true);
    }

    public function cancelNumber(string $providerOrderId): bool {
        return $this->setActivationStatus($providerOrderId, 8);
    }

    public function finishNumber(string $providerOrderId): bool {
        return $this->setActivationStatus($providerOrderId, 6);
    }

    /**
     * 5. Country Catalog
     * Returns standard handler_api country identifier catalog
     */
    public function getCountries(): array {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'API key is missing or not configured for uOTP.'];
        }

        // Test connection first to verify API key validity
        $test = $this->testConnection();
        if (!$test['success']) {
            return ['success' => false, 'error' => $test['error']];
        }

        // Standard handler_api country mapping list
        $catalog = [
            ['id' => '22', 'name' => 'India', 'code' => 'IN', 'prefix' => '+91'],
            ['id' => '0',  'name' => 'Russia', 'code' => 'RU', 'prefix' => '+7'],
            ['id' => '1',  'name' => 'Ukraine', 'code' => 'UA', 'prefix' => '+380'],
            ['id' => '2',  'name' => 'Kazakhstan', 'code' => 'KZ', 'prefix' => '+7'],
            ['id' => '3',  'name' => 'China', 'code' => 'CN', 'prefix' => '+86'],
            ['id' => '4',  'name' => 'Philippines', 'code' => 'PH', 'prefix' => '+63'],
            ['id' => '5',  'name' => 'Myanmar', 'code' => 'MM', 'prefix' => '+95'],
            ['id' => '6',  'name' => 'Indonesia', 'code' => 'ID', 'prefix' => '+62'],
            ['id' => '7',  'name' => 'Malaysia', 'code' => 'MY', 'prefix' => '+60'],
            ['id' => '8',  'name' => 'Kenya', 'code' => 'KE', 'prefix' => '+254'],
            ['id' => '9',  'name' => 'Vietnam', 'code' => 'VN', 'prefix' => '+84'],
            ['id' => '10', 'name' => 'Kyrgyzstan', 'code' => 'KG', 'prefix' => '+996'],
            ['id' => '11', 'name' => 'United States', 'code' => 'US', 'prefix' => '+1'],
            ['id' => '12', 'name' => 'Israel', 'code' => 'IL', 'prefix' => '+972'],
            ['id' => '13', 'name' => 'Hong Kong', 'code' => 'HK', 'prefix' => '+852'],
            ['id' => '14', 'name' => 'Poland', 'code' => 'PL', 'prefix' => '+48'],
            ['id' => '15', 'name' => 'United Kingdom', 'code' => 'GB', 'prefix' => '+44'],
            ['id' => '16', 'name' => 'Madagascar', 'code' => 'MG', 'prefix' => '+261'],
            ['id' => '18', 'name' => 'Nigeria', 'code' => 'NG', 'prefix' => '+234'],
            ['id' => '20', 'name' => 'Egypt', 'code' => 'EG', 'prefix' => '+20'],
            ['id' => '21', 'name' => 'Ireland', 'code' => 'IE', 'prefix' => '+353'],
            ['id' => '23', 'name' => 'Cambodia', 'code' => 'KH', 'prefix' => '+855'],
            ['id' => '24', 'name' => 'Laos', 'code' => 'LA', 'prefix' => '+856'],
            ['id' => '28', 'name' => 'Serbia', 'code' => 'RS', 'prefix' => '+381'],
            ['id' => '30', 'name' => 'South Africa', 'code' => 'ZA', 'prefix' => '+27'],
            ['id' => '31', 'name' => 'Romania', 'code' => 'RO', 'prefix' => '+40'],
            ['id' => '32', 'name' => 'Colombia', 'code' => 'CO', 'prefix' => '+57'],
            ['id' => '35', 'name' => 'Canada', 'code' => 'CA', 'prefix' => '+1'],
            ['id' => '36', 'name' => 'Morocco', 'code' => 'MA', 'prefix' => '+212'],
            ['id' => '38', 'name' => 'Argentina', 'code' => 'AR', 'prefix' => '+54'],
            ['id' => '42', 'name' => 'Germany', 'code' => 'DE', 'prefix' => '+49'],
            ['id' => '47', 'name' => 'Netherlands', 'code' => 'NL', 'prefix' => '+31'],
            ['id' => '51', 'name' => 'Thailand', 'code' => 'TH', 'prefix' => '+66'],
            ['id' => '52', 'name' => 'Saudi Arabia', 'code' => 'SA', 'prefix' => '+966'],
            ['id' => '55', 'name' => 'Spain', 'code' => 'ES', 'prefix' => '+34'],
            ['id' => '61', 'name' => 'Turkey', 'code' => 'TR', 'prefix' => '+90'],
            ['id' => '65', 'name' => 'Pakistan', 'code' => 'PK', 'prefix' => '+92'],
            ['id' => '72', 'name' => 'Brazil', 'code' => 'BR', 'prefix' => '+55'],
            ['id' => '77', 'name' => 'France', 'code' => 'FR', 'prefix' => '+33'],
            ['id' => '85', 'name' => 'Italy', 'code' => 'IT', 'prefix' => '+39'],
            ['id' => '94', 'name' => 'United Arab Emirates', 'code' => 'AE', 'prefix' => '+971'],
            ['id' => '120','name' => 'Singapore', 'code' => 'SG', 'prefix' => '+65'],
            ['id' => '124','name' => 'Australia', 'code' => 'AU', 'prefix' => '+61'],
            ['id' => '187','name' => 'United States (Route 2)', 'code' => 'US', 'prefix' => '+1']
        ];

        $countries = [];
        foreach ($catalog as $item) {
            $countries[] = [
                'provider_country_id' => $item['id'],
                'name'                => $item['name'],
                'code'                => $item['code'],
                'prefix'              => $item['prefix']
            ];
        }

        return ['success' => true, 'countries' => $countries];
    }

    /**
     * 6. Service, Pricing and Stock Import
     * Queries: action=getPrices&api_key={API_KEY}&country={COUNTRY}
     */
    public function getServices(?string $providerCountryCode = null): array {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'API key is missing or not configured for uOTP.'];
        }

        $country = $providerCountryCode !== null && $providerCountryCode !== '' ? trim($providerCountryCode) : '22';

        $res = $this->callUotp([
            'action'  => 'getPrices',
            'country' => $country
        ]);

        if (!$res['success']) {
            return ['success' => false, 'error' => $res['error'] ?: 'Failed to retrieve prices from uOTP.'];
        }

        $body = $res['body'];

        if ($body === 'NO_CONNECTION') {
            return [
                'success' => false,
                'error'   => "uOTP returned: NO_CONNECTION (Carrier route currently offline or connecting for country #{$country})"
            ];
        }

        if ($body === 'BAD_COUNTRY') {
            return [
                'success' => false,
                'error'   => "uOTP returned: BAD_COUNTRY (Invalid country ID '{$country}')"
            ];
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return [
                'success' => false,
                'error'   => 'Unexpected response from uOTP getPrices: ' . substr(strip_tags($body), 0, 150)
            ];
        }

        $services = [];
        // uOTP response format: [{"servicecode":"whatsapp","name":"WhatsApp","price":70}, ...]
        foreach ($data as $item) {
            if (!is_array($item)) continue;
            $codeStr = (string)($item['servicecode'] ?? '');
            if (empty($codeStr)) continue;

            $name = (string)($item['name'] ?? self::formatServiceName($codeStr));
            $cost = isset($item['price']) && is_numeric($item['price']) ? (float)$item['price'] : 0.00;

            $services[] = [
                'provider_service_id' => $codeStr,
                'name'                => $name,
                'code'                => strtolower($codeStr),
                'cost'                => $cost,
                'count'               => null
            ];
        }

        return ['success' => true, 'services' => $services];
    }

    /**
     * 7. Operators
     */
    public function getOperators(?string $providerCountryCode = null): array {
        return ['success' => true, 'operators' => ['any']];
    }

    public static function formatServiceName(string $code): string {
        $map = [
            'wa'       => 'WhatsApp',
            'whatsapp' => 'WhatsApp',
            'tg'       => 'Telegram',
            'telegram' => 'Telegram',
            'go'       => 'Google / Gmail',
            'google'   => 'Google / Gmail',
            'openai'   => 'OpenAI / ChatGPT',
            'ig'       => 'Instagram',
            'instagram'=> 'Instagram',
            'tw'       => 'Twitter / X',
            'twitter'  => 'Twitter / X',
            'ub'       => 'Uber',
            'uber'     => 'Uber',
            'fb'       => 'Facebook',
            'facebook' => 'Facebook',
            'lf'       => 'TikTok',
            'tiktok'   => 'TikTok'
        ];
        $lower = strtolower(trim($code));
        return $map[$lower] ?? ucwords(str_replace(['_', '-'], ' ', $code));
    }
}
