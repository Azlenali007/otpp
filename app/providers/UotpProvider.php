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
     * Executes server-side HTTP GET request with SSL verification and error handling
     */
    protected function callUotp(array $params): array {
        $params['api_key'] = $this->apiKey;

        $url = $this->apiUrl . (str_contains($this->apiUrl, '?') ? '&' : '?') . http_build_query($params);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'NumVault-uOTP-Client/2.0');

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

        if ($httpCode === 401 || $body === 'BAD_KEY') {
            return [
                'success' => false,
                'status'  => 401,
                'body'    => $body,
                'error'   => 'Provider rejected API key (BAD_KEY). Please check your uOTP API key in Provider Settings.'
            ];
        }

        if ($httpCode === 403) {
            return [
                'success' => false,
                'status'  => 403,
                'body'    => $body,
                'error'   => 'HTTP 403 Forbidden: Access denied by uOTP server.'
            ];
        }

        if ($httpCode >= 500) {
            return [
                'success' => false,
                'status'  => $httpCode,
                'body'    => $body,
                'error'   => "HTTP {$httpCode} Provider Gateway Error: uOTP server is temporarily unavailable."
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
            'error'   => "Unexpected response from uOTP: " . substr($body, 0, 100)
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
            'country'  => trim($countryCode),
            'operator' => !empty($operator) && $operator !== 'any' ? trim($operator) : 'any'
        ];

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
            'WRONG_OPERATOR' => 'The requested telecom operator is not available for this country.',
            'BANNED'         => 'The uOTP account is temporarily restricted (BANNED).',
            'ERROR_SQL'      => 'uOTP gateway encountered an internal database error.'
        ];

        $friendlyError = $errorMap[$body] ?? ("uOTP returned error: " . ($body ?: 'Empty response received.'));

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
        if (in_array($body, ['STATUS_CANCEL', 'NO_ACTIVATION'])) {
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

    /**
     * Cancels an activation on uOTP (status=8)
     */
    public function cancelNumber(string $providerOrderId): bool {
        return $this->setActivationStatus($providerOrderId, 8);
    }

    /**
     * Finishes/confirms successful activation on uOTP (status=6)
     */
    public function finishNumber(string $providerOrderId): bool {
        return $this->setActivationStatus($providerOrderId, 6);
    }

    /**
     * 5. Country Import
     * GET: https://uotp.store/api/stubs/handler_api.php?api_key={API_KEY}&action=getCountries
     */
    public function getCountries(): array {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'API key is missing or not configured for uOTP.'];
        }

        $res = $this->callUotp(['action' => 'getCountries']);

        if (!$res['success']) {
            return ['success' => false, 'error' => $res['error'] ?: 'Failed to retrieve countries from uOTP.'];
        }

        $data = json_decode($res['body'], true);

        // Fallback: If getCountries returned non-JSON, extract from getPrices
        if (!is_array($data)) {
            $pricesRes = $this->callUotp(['action' => 'getPrices']);
            $pricesData = json_decode($pricesRes['body'], true);
            if (is_array($pricesData)) {
                $countries = [];
                foreach ($pricesData as $countryId => $services) {
                    $cIdStr = (string)$countryId;
                    $norm = BaseProvider::getIsoAndPrefix("Country {$cIdStr}");
                    $countries[] = [
                        'provider_country_id' => $cIdStr,
                        'name'                => "Country {$cIdStr}",
                        'code'                => $norm['code'],
                        'prefix'              => $norm['prefix']
                    ];
                }
                return ['success' => true, 'countries' => $countries];
            }

            return [
                'success' => false,
                'error'   => 'Invalid response format from uOTP getCountries: ' . substr($res['body'], 0, 150)
            ];
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

    /**
     * 6. Service, Pricing and Stock Import
     * GET: https://uotp.store/api/stubs/handler_api.php?api_key={API_KEY}&action=getPrices&country={COUNTRY}
     */
    public function getServices(?string $providerCountryCode = null): array {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'API key is missing or not configured for uOTP.'];
        }

        $params = ['action' => 'getPrices'];
        if ($providerCountryCode !== null && $providerCountryCode !== '') {
            $params['country'] = $providerCountryCode;
        }

        $res = $this->callUotp($params);

        if (!$res['success']) {
            return ['success' => false, 'error' => $res['error'] ?: 'Failed to retrieve prices from uOTP.'];
        }

        $data = json_decode($res['body'], true);
        if (!is_array($data)) {
            return [
                'success' => false,
                'error'   => 'Invalid response format from uOTP getPrices: ' . substr($res['body'], 0, 150)
            ];
        }

        $rawServices = $data;
        if ($providerCountryCode !== null && isset($data[$providerCountryCode]) && is_array($data[$providerCountryCode])) {
            $rawServices = $data[$providerCountryCode];
        } elseif (isset($data['0']) && is_array($data['0'])) {
            $rawServices = $data['0'];
        }

        $services = [];
        foreach ($rawServices as $svcCode => $svcInfo) {
            if (!is_array($svcInfo)) continue;
            $codeStr = (string)$svcCode;
            $cost = isset($svcInfo['cost']) ? (float)$svcInfo['cost'] : (isset($svcInfo['price']) ? (float)$svcInfo['price'] : null);
            $count = isset($svcInfo['count']) ? (int)$svcInfo['count'] : (isset($svcInfo['qty']) ? (int)$svcInfo['qty'] : null);
            $name = CustomApiProvider::formatServiceName($codeStr);

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

    /**
     * 7. Operator Import
     * GET: https://uotp.store/api/stubs/handler_api.php?api_key={API_KEY}&action=getOperators&country={COUNTRY}
     */
    public function getOperators(?string $providerCountryCode = null): array {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'API key is missing or not configured for uOTP.'];
        }

        $params = ['action' => 'getOperators'];
        if ($providerCountryCode !== null && $providerCountryCode !== '') {
            $params['country'] = $providerCountryCode;
        }

        $res = $this->callUotp($params);
        if (!$res['success']) {
            // Default operator fallback
            return ['success' => true, 'operators' => ['any']];
        }

        $data = json_decode($res['body'], true);
        if (is_array($data)) {
            $operators = [];
            foreach ($data as $op) {
                if (is_string($op)) {
                    $operators[] = $op;
                } elseif (is_array($op) && !empty($op['name'])) {
                    $operators[] = (string)$op['name'];
                }
            }
            if (!in_array('any', $operators)) {
                array_unshift($operators, 'any');
            }
            return ['success' => true, 'operators' => $operators];
        }

        return ['success' => true, 'operators' => ['any']];
    }
}
