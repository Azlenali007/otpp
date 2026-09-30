<?php
/**
 * NumVault - Base Provider Class
 */

declare(strict_types=1);

namespace App\Providers;

use App\Core\Logger;

class BaseProvider {
    protected string $apiKey;
    protected string $apiUrl;

    public function __construct(string $apiUrl, string $apiKey) {
        $this->apiUrl = rtrim($apiUrl, '/');
        $this->apiKey = $apiKey;
    }

    protected function executeRequest(string $url, array $params = [], string $method = 'GET'): array {
        $ch = curl_init();
        if ($method === 'GET' && !empty($params)) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($params);
        }

        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, 'NumVault-OTP-Platform/2.0');

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            Logger::error("Provider cURL failure: {$curlError} for URL: " . parse_url($url, PHP_URL_HOST));
        }

        return [
            'status' => $httpCode,
            'body'   => $response ?: '',
            'error'  => $curlError
        ];
    }

    /**
     * Standard ISO 3166-1 alpha-2 and calling prefix lookup dictionary
     */
    public static function getIsoAndPrefix(string $name, ?string $code = null, ?string $prefix = null): array {
        if (!empty($code) && !empty($prefix)) {
            $cleanCode = strtoupper(preg_replace('/[^A-Z]/', '', $code));
            $cleanPrefix = '+' . ltrim($prefix, '+');
            return ['code' => $cleanCode ?: 'XX', 'prefix' => $cleanPrefix];
        }

        $nameNormalized = strtolower(trim($name));
        $map = [
            'united states' => ['code' => 'US', 'prefix' => '+1'],
            'usa'           => ['code' => 'US', 'prefix' => '+1'],
            'united kingdom'=> ['code' => 'GB', 'prefix' => '+44'],
            'england'       => ['code' => 'GB', 'prefix' => '+44'],
            'russia'        => ['code' => 'RU', 'prefix' => '+7'],
            'ukraine'       => ['code' => 'UA', 'prefix' => '+380'],
            'kazakhstan'    => ['code' => 'KZ', 'prefix' => '+7'],
            'china'         => ['code' => 'CN', 'prefix' => '+86'],
            'philippines'   => ['code' => 'PH', 'prefix' => '+63'],
            'myanmar'       => ['code' => 'MM', 'prefix' => '+95'],
            'indonesia'     => ['code' => 'ID', 'prefix' => '+62'],
            'malaysia'      => ['code' => 'MY', 'prefix' => '+60'],
            'india'         => ['code' => 'IN', 'prefix' => '+91'],
            'canada'        => ['code' => 'CA', 'prefix' => '+1'],
            'germany'       => ['code' => 'DE', 'prefix' => '+49'],
            'france'        => ['code' => 'FR', 'prefix' => '+33'],
            'brazil'        => ['code' => 'BR', 'prefix' => '+55'],
            'poland'        => ['code' => 'PL', 'prefix' => '+48'],
            'spain'         => ['code' => 'ES', 'prefix' => '+34'],
            'italy'         => ['code' => 'IT', 'prefix' => '+39'],
            'netherlands'   => ['code' => 'NL', 'prefix' => '+31'],
            'vietnam'       => ['code' => 'VN', 'prefix' => '+84'],
            'nigeria'       => ['code' => 'NG', 'prefix' => '+234'],
            'turkey'        => ['code' => 'TR', 'prefix' => '+90'],
            'egypt'         => ['code' => 'EG', 'prefix' => '+20'],
            'mexico'        => ['code' => 'MX', 'prefix' => '+52'],
            'colombia'      => ['code' => 'CO', 'prefix' => '+57'],
            'argentina'     => ['code' => 'AR', 'prefix' => '+54'],
            'australia'     => ['code' => 'AU', 'prefix' => '+61'],
            'kenya'         => ['code' => 'KE', 'prefix' => '+254'],
            'south africa'  => ['code' => 'ZA', 'prefix' => '+27'],
            'singapore'     => ['code' => 'SG', 'prefix' => '+65'],
            'thailand'      => ['code' => 'TH', 'prefix' => '+66'],
            'pakistan'      => ['code' => 'PK', 'prefix' => '+92'],
            'bangladesh'    => ['code' => 'BD', 'prefix' => '+880'],
            'japan'         => ['code' => 'JP', 'prefix' => '+81'],
            'south korea'   => ['code' => 'KR', 'prefix' => '+82'],
            'sweden'        => ['code' => 'SE', 'prefix' => '+46'],
            'switzerland'   => ['code' => 'CH', 'prefix' => '+41'],
            'austria'       => ['code' => 'AT', 'prefix' => '+43'],
            'portugal'      => ['code' => 'PT', 'prefix' => '+351'],
            'romania'       => ['code' => 'RO', 'prefix' => '+40'],
            'czech republic'=> ['code' => 'CZ', 'prefix' => '+420'],
            'greece'        => ['code' => 'GR', 'prefix' => '+30'],
            'israel'        => ['code' => 'IL', 'prefix' => '+972'],
            'united arab emirates' => ['code' => 'AE', 'prefix' => '+971'],
            'saudi arabia'  => ['code' => 'SA', 'prefix' => '+966'],
        ];

        if (isset($map[$nameNormalized])) {
            return [
                'code'   => !empty($code) ? strtoupper($code) : $map[$nameNormalized]['code'],
                'prefix' => !empty($prefix) ? ('+' . ltrim($prefix, '+')) : $map[$nameNormalized]['prefix']
            ];
        }

        // Fallback: derive code from first 2 letters
        $derivedCode = strtoupper(substr(preg_replace('/[^a-zA-Z]/', '', $name), 0, 2));
        if (strlen($derivedCode) < 2) $derivedCode = 'UN';

        return [
            'code'   => !empty($code) ? strtoupper($code) : $derivedCode,
            'prefix' => !empty($prefix) ? ('+' . ltrim($prefix, '+')) : '+1'
        ];
    }
}
