<?php
/**
 * NumVault - Security Input Validator
 * Validates and sanitizes inputs before database queries and business logic execution
 */

declare(strict_types=1);

namespace App\Security;

class Validator {
    /**
     * Verify that an input is a valid positive integer ID
     */
    public static function isValidId(mixed $id): bool {
        return filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false;
    }

    /**
     * Verify that an input is a valid monetary amount (> 0)
     */
    public static function isPositiveAmount(mixed $amount): bool {
        if (!is_numeric($amount)) {
            return false;
        }
        $val = (float)$amount;
        return $val > 0.0 && !is_nan($val) && !is_infinite($val);
    }

    /**
     * Validate email format
     */
    public static function isValidEmail(string $email): bool {
        return filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false;
    }

    /**
     * Validate standard username characters (letters, numbers, underscore)
     */
    public static function isValidUsername(string $username): bool {
        return (bool)preg_match('/^[a-zA-Z0-9_]{3,30}$/', trim($username));
    }

    /**
     * Sanitize string for plain text storage
     */
    public static function cleanString(?string $input, int $maxLength = 255): string {
        if ($input === null) {
            return '';
        }
        $cleaned = trim(strip_tags($input));
        return mb_substr($cleaned, 0, $maxLength);
    }
}
