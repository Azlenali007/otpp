<?php
/**
 * NumVault - Core Helpers & Global Utilities
 */

declare(strict_types=1);

use App\Core\Database;
use App\Core\Security;
use App\Core\Logger;
use App\Core\Session;

/**
 * Escape HTML special characters for XSS defense
 */
function e(?string $str): string {
    return Security::escape($str);
}

/**
 * Mask secret credentials for UI display
 */
function mask_secret(?string $secret, int $visible = 4): string {
    return Security::maskSecret($secret, $visible);
}

/**
 * Generate CSRF hidden input field
 */
function csrf_field(): string {
    $token = Security::csrfToken();
    return '<input type="hidden" name="csrf_token" value="' . e($token) . '">';
}

/**
 * Verify CSRF token
 */
function verify_csrf_token(): bool {
    return Security::verifyCsrf();
}

/**
 * Enforce CSRF token on POST
 */
function require_csrf(): void {
    Security::enforceCsrf();
}

/**
 * Database connection helper
 */
function get_db(): PDO {
    return Database::getConnection();
}

/**
 * Get setting from database settings table
 */
function get_setting(string $key, string $default = ''): string {
    static $settings = null;
    if ($settings === null) {
        $settings = [];
        try {
            $pdo = get_db();
            $stmt = $pdo->query("SELECT key_name, value_text FROM settings");
            while ($row = $stmt->fetch()) {
                $settings[$row['key_name']] = $row['value_text'];
            }
        } catch (Exception $e) {
            // Table may not exist during installation
        }
    }
    return $settings[$key] ?? $default;
}

/**
 * Set setting in database settings table
 */
function set_setting(string $key, string $value): bool {
    try {
        $pdo = get_db();
        $stmt = $pdo->prepare("INSERT INTO settings (key_name, value_text) VALUES (?, ?) ON DUPLICATE KEY UPDATE value_text = ?");
        return $stmt->execute([$key, $value, $value]);
    } catch (Exception $e) {
        Logger::error("Failed to update setting {$key}: " . $e->getMessage());
        return false;
    }
}

/**
 * Authentication Helpers
 */
function is_logged_in(): bool {
    return !empty($_SESSION['user_id']);
}

function is_admin(): bool {
    return !empty($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
}

function current_user(): ?array {
    static $cachedUser = null;
    if ($cachedUser !== null) {
        return $cachedUser;
    }
    if (!is_logged_in()) {
        return null;
    }
    try {
        $pdo = get_db();
        $stmt = $pdo->prepare("SELECT id, username, email, role, balance, status, created_at FROM users WHERE id = ? LIMIT 1");
        $stmt->execute([$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if ($user) {
            $cachedUser = $user;
            return $cachedUser;
        }
    } catch (Exception $e) {}
    return null;
}

function require_login(): array {
    $user = current_user();
    if (!$user) {
        $_SESSION['flash_error'] = "Please sign in to access your dashboard.";
        header('Location: /auth/login.php');
        exit;
    }
    if ($user['status'] === 'blocked') {
        Session::destroy();
        header('Location: /auth/login.php?error=blocked');
        exit;
    }
    return $user;
}

function require_admin(): array {
    $user = current_user();
    if (!$user || $user['role'] !== 'admin' || !is_admin()) {
        Logger::security("Unauthorized attempt to access admin page", [
            'user_id' => $user['id'] ?? null,
            'role' => $user['role'] ?? 'guest',
            'uri' => $_SERVER['REQUEST_URI'] ?? ''
        ]);
        $_SESSION['flash_error'] = "Administrative authorization required.";
        header('Location: /admin/login.php');
        exit;
    }
    return $user;
}

/**
 * Flash messaging
 */
function set_flash(string $type, string $message): void {
    $_SESSION['flash_' . $type] = $message;
}

function get_flash(): array {
    $messages = [];
    foreach (['success', 'error', 'info', 'warning'] as $type) {
        if (isset($_SESSION['flash_' . $type])) {
            $messages[$type] = $_SESSION['flash_' . $type];
            unset($_SESSION['flash_' . $type]);
        }
    }
    return $messages;
}

/**
 * Format currency price
 */
function format_price(float|string $amount): string {
    $currency = get_setting('currency_symbol', '$');
    return $currency . number_format((float)$amount, 2);
}

/**
 * Record immutable wallet transaction ledger
 */
function record_wallet_tx(
    PDO $pdo,
    int $userId,
    string $type,
    float $amount,
    float $balanceBefore,
    float $balanceAfter,
    string $description,
    ?string $referenceId = null,
    ?int $orderId = null,
    ?int $paymentId = null
): int {
    $stmt = $pdo->prepare("
        INSERT INTO wallet_transactions 
        (user_id, order_id, payment_id, type, amount, balance_before, balance_after, description, reference_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $userId,
        $orderId,
        $paymentId,
        $type,
        $amount,
        $balanceBefore,
        $balanceAfter,
        $description,
        $referenceId
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Create user notification
 */
function create_notification(int $userId, string $title, string $message, string $type = 'system'): bool {
    try {
        $pdo = get_db();
        $stmt = $pdo->prepare("INSERT INTO notifications (user_id, title, message, type, is_read) VALUES (?, ?, ?, ?, 0)");
        return $stmt->execute([$userId, $title, $message, $type]);
    } catch (Exception $e) {
        Logger::error("Notification error: " . $e->getMessage());
        return false;
    }
}

/**
 * Security audit log
 */
function log_audit(?int $userId, string $action, ?string $details = null): void {
    try {
        $pdo = get_db();
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $stmt = $pdo->prepare("INSERT INTO audit_logs (user_id, action, details, ip_address) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userId, $action, $details, $ip]);
    } catch (Exception $e) {}
    Logger::audit($action . ($details ? ": {$details}" : ''), ['user_id' => $userId]);
}

/**
 * Render inline SVG icon
 */
function icon(string $name, string $class = 'w-5 h-5'): string {
    $icons = [
        'shield' => '<svg class="' . e($class) . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path></svg>',
        'phone' => '<svg class="' . e($class) . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg>',
        'wallet' => '<svg class="' . e($class) . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z"></path></svg>',
        'clock' => '<svg class="' . e($class) . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>',
        'check' => '<svg class="' . e($class) . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>',
        'check-circle' => '<svg class="' . e($class) . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>',
        'alert-circle' => '<svg class="' . e($class) . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>',
        'copy' => '<svg class="' . e($class) . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path></svg>',
        'refresh-cw' => '<svg class="' . e($class) . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>',
        'user' => '<svg class="' . e($class) . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path></svg>',
        'search' => '<svg class="' . e($class) . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>',
        'log-out' => '<svg class="' . e($class) . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path></svg>',
        'server' => '<svg class="' . e($class) . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"></path></svg>',
        'settings' => '<svg class="' . e($class) . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path></svg>',
        'life-buoy' => '<svg class="' . e($class) . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10" stroke-width="2"></circle><circle cx="12" cy="12" r="4" stroke-width="2"></circle><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4.93 4.93l4.24 4.24m5.66 5.66l4.24 4.24m-4.24-9.9l4.24-4.24m-14.14 14.14l4.24-4.24"></path></svg>',
        'arrow-right' => '<svg class="' . e($class) . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>',
        'bell' => '<svg class="' . e($class) . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"></path></svg>',
        'plus' => '<svg class="' . e($class) . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>',
        'x' => '<svg class="' . e($class) . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path></svg>',
        'globe' => '<svg class="' . e($class) . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"></path></svg>',
        'layers' => '<svg class="' . e($class) . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>',
        'message-square' => '<svg class="' . e($class) . '" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path></svg>'
    ];
    return $icons[$name] ?? $icons['shield'];
}
