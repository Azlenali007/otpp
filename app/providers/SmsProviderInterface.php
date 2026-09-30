<?php
/**
 * NumVault - SMS Provider Interface
 */

declare(strict_types=1);

namespace App\Providers;

interface SmsProviderInterface {
    public function getBalance(): float;
    public function testConnection(): array;
    public function requestNumber(string $serviceCode, string $countryCode, ?string $operator = null): array;
    public function checkOtp(string $providerOrderId): array;
    public function setActivationStatus(string $providerOrderId, int $status): bool;
    public function cancelNumber(string $providerOrderId): bool;
    public function finishNumber(string $providerOrderId): bool;
    public function getCountries(): array;
    public function getServices(?string $providerCountryCode = null): array;
    public function getOperators(?string $providerCountryCode = null): array;
}
