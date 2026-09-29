<?php
/**
 * NumVault - Session Security Manager
 * Hardens session lifecycle, cookie flags, and session fixation defense
 */

declare(strict_types=1);

namespace App\Core;

class Session {
    public static function start(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                   (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');

        if ($isHttps) {
            ini_set('session.cookie_secure', '1');
        }

        session_name('NV_SESSID');
        session_start();

        // Check for session timeout (e.g. 2 hours inactivity)
        $timeout = 7200;
        if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity'] > $timeout)) {
            self::destroy();
            session_start();
            $_SESSION['flash_info'] = 'Your session has expired due to inactivity. Please sign in again.';
        }
        $_SESSION['last_activity'] = time();
    }

    public static function regenerate(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public static function destroy(): void {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $params = session_get_cookie_params();
                setcookie(
                    session_name(),
                    '',
                    time() - 42000,
                    $params['path'],
                    $params['domain'],
                    $params['secure'],
                    $params['httponly']
                );
            }
            session_destroy();
        }
    }
}
