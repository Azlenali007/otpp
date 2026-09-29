<?php
/**
 * NumVault - Authentication Service
 * Manages user logins, registration, session security, password hashing, and role verification
 */

declare(strict_types=1);

namespace App\Auth;

use PDO;
use Exception;
use App\Core\Session;
use App\Core\Logger;
use App\Core\RateLimiter;

class AuthService {
    /**
     * Authenticate user credentials
     * 
     * @return array [success => bool, user => ?array, error => ?string]
     */
    public static function attempt(string $identity, string $password): array {
        $identity = trim($identity);
        if (empty($identity) || empty($password)) {
            return ['success' => false, 'error' => 'Please enter your username/email and password.'];
        }

        // Rate limit by IP + identity to block brute-force attacks
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $rateKey = "login_attempt_{$ip}_" . md5($identity);
        if (!RateLimiter::check($rateKey, 5, 900)) { // 5 attempts per 15 minutes
            Logger::security("Rate limit exceeded for login on identity: {$identity} from IP: {$ip}");
            return ['success' => false, 'error' => 'Too many failed login attempts. Please try again in 15 minutes.'];
        }

        $pdo = get_db();
        $stmt = $pdo->prepare("
            SELECT id, username, email, password_hash, role, balance, status
            FROM users
            WHERE (username = ? OR email = ?)
            LIMIT 1
        ");
        $stmt->execute([$identity, $identity]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user || !password_verify($password, $user['password_hash'])) {
            RateLimiter::hit($rateKey, 900);
            Logger::security("Failed login attempt for identity: {$identity} from IP: {$ip}");
            return ['success' => false, 'error' => 'Invalid credentials. Please verify your login details.'];
        }

        if ($user['status'] !== 'active') {
            return ['success' => false, 'error' => 'Your account is suspended. Please contact customer support.'];
        }

        // Clear rate limiter on successful authentication
        RateLimiter::clear($rateKey);

        // Regenerate session ID to prevent session fixation attacks
        Session::regenerate();

        // Establish secure session state
        $_SESSION['user_id'] = (int)$user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['authenticated_at'] = time();

        Logger::audit((int)$user['id'], 'user_login', "Successful login for {$user['username']} ({$user['role']})");

        return [
            'success' => true,
            'user' => $user
        ];
    }

    /**
     * Register a new customer account
     */
    public static function register(string $username, string $email, string $password): array {
        $username = trim($username);
        $email = trim(strtolower($email));

        // Strict input validation
        if (strlen($username) < 3 || strlen($username) > 30 || !preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            return ['success' => false, 'error' => 'Username must be 3-30 characters long and contain only letters, numbers, and underscores.'];
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'error' => 'Please provide a valid email address.'];
        }

        if (strlen($password) < 8) {
            return ['success' => false, 'error' => 'Password must be at least 8 characters in length.'];
        }

        $pdo = get_db();
        // Check uniqueness
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->execute([$username, $email]);
        if ($stmt->fetch()) {
            return ['success' => false, 'error' => 'An account with that username or email address already exists.'];
        }

        $hash = password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);

        try {
            $ins = $pdo->prepare("
                INSERT INTO users (username, email, password_hash, role, balance, status, created_at)
                VALUES (?, ?, ?, 'user', 0.00, 'active', NOW())
            ");
            $ins->execute([$username, $email, $hash]);
            $newId = (int)$pdo->lastInsertId();

            Logger::audit($newId, 'user_registered', "New customer account created: {$username} ({$email})");

            return ['success' => true, 'user_id' => $newId];
        } catch (Exception $e) {
            Logger::error("Registration error: " . $e->getMessage());
            return ['success' => false, 'error' => 'Unable to create account at this time. Please retry.'];
        }
    }

    /**
     * Terminate the current session securely
     */
    public static function logout(): void {
        if (!empty($_SESSION['user_id'])) {
            Logger::audit((int)$_SESSION['user_id'], 'user_logout', "User logged out");
        }
        Session::destroy();
    }
}
