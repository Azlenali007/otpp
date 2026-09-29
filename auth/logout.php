<?php
/**
 * NumVault - Logout Handler
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\Session;

if (is_logged_in()) {
    log_audit($_SESSION['user_id'] ?? null, 'user_logout', 'User signed out of session');
}

Session::destroy();
header('Location: /auth/login.php');
exit;
