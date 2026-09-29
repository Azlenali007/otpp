<?php
/**
 * NumVault - Security Core
 * Hardens HTTP headers, CSRF enforcement, input validation, and credential masking
 */

declare(strict_types=1);

namespace App\Core;

class Security {
    /**
     * Send production security headers
     */
    public static function applyHeaders(): void {
        if (headers_sent()) {
            return;
        }

        // Prevent MIME type sniffing
        header("X-Content-Type-Options: nosniff");

        // Clickjacking protection (ALLOW-FROM / sameorigin for iframes when required)
        header("X-Frame-Options: SAMEORIGIN");

        // Cross-site scripting filter
        header("X-XSS-Protection: 1; mode=block");

        // Referrer policy
        header("Referrer-Policy: strict-origin-when-cross-origin");

        // Restrict unnecessary browser features
        header("Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()");

        // Content Security Policy (allows Tailwind CDN and inline UI scripts required by the app)
        $csp = "default-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com https://fonts.googleapis.com https://fonts.gstatic.com data:; " .
               "img-src 'self' data: https:; " .
               "connect-src 'self'; " .
               "frame-ancestors 'self' *;";
        header("Content-Security-Policy: " . $csp);
    }

    /**
     * Generate or fetch CSRF token
     */
    public static function csrfToken(): string {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    /**
     * Verify CSRF token
     */
    public static function verifyCsrf(?string $token = null): bool {
        $submitted = $token ?? ($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (empty($submitted) || empty($_SESSION['csrf_token'])) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $submitted);
    }

    /**
     * Enforce CSRF token or terminate request
     */
    public static function enforceCsrf(): void {
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!self::verifyCsrf()) {
                Logger::security("CSRF token verification failed on URI " . ($_SERVER['REQUEST_URI'] ?? '/'));
                http_response_code(403);
                if (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) {
                    header('Content-Type: application/json');
                    echo json_encode(['success' => false, 'error' => 'Security verification failed (Invalid CSRF token).']);
                } else {
                    die("Security token expired or invalid. Please refresh the page and try again.");
                }
                exit;
            }
        }
    }

    /**
     * Mask sensitive credentials for admin presentation (e.g. API keys, secrets)
     */
    public static function maskSecret(?string $secret, int $visibleChars = 4): string {
        if (empty($secret)) {
            return '';
        }
        $len = strlen($secret);
        if ($len <= $visibleChars) {
            return str_repeat('•', $len);
        }
        return substr($secret, 0, $visibleChars) . str_repeat('•', max(4, $len - ($visibleChars * 2))) . substr($secret, -$visibleChars);
    }

    /**
     * Escape output for HTML context
     */
    public static function escape(?string $string): string {
        return htmlspecialchars((string)$string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
