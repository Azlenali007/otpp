<?php
/**
 * NumVault - SMS Provider Interface
 */

declare(strict_types=1);

namespace App\Providers;

interface SmsProviderInterface {
    public function getBalance(): float;
    public function requestNumber(string $serviceCode, string $countryCode): array;
    public function checkOtp(string $providerOrderId): array;
    public function cancelNumber(string $providerOrderId): bool;
}
