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
}
