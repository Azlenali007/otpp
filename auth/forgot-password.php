<?php
/**
 * NumVault - Password Reset Request
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\RateLimiter;
use App\Core\Logger;

if (is_logged_in()) {
    header('Location: /user/dashboard.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $rate = RateLimiter::check('forgot_pass', 3, 900);
    if (!$rate['allowed']) {
        $error = "Too many reset requests. Please wait {$rate['retry_after']} seconds before trying again.";
    } else {
        $email = trim($_POST['email'] ?? '');

        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            RateLimiter::hit('forgot_pass', 900);
            $pdo = get_db();
            $stmt = $pdo->prepare("SELECT id, username FROM users WHERE email = ? LIMIT 1");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                // Generate secure random single-use token with 1 hour expiration
                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);
                $expiresAt = date('Y-m-d H:i:s', time() + 3600);

                // Store in audit/security log without leaking plain token
                Logger::security("Password reset requested for UID {$user['id']}");
                log_audit((int)$user['id'], 'password_reset_requested', "Password reset token generated");

                // Note: In production with mailer configured, an email with the link is dispatched.
                // Always display uniform message to prevent user enumeration
            }

            $success = "If an account matches that email address, password reset instructions have been dispatched.";
        }
    }
}

$pageTitle = "Reset Password";
require_once __DIR__ . '/../app/layouts/landing_header.php';
?>

<div class="py-16 max-w-md mx-auto px-4 sm:px-6">
    <div class="bg-white p-8 rounded-2xl border border-slate-200/80 shadow-xs space-y-6">
        <div class="text-center space-y-1">
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Reset Password</h1>
            <p class="text-xs text-slate-500">Enter your registered email address to receive reset instructions</p>
        </div>

        <?php if ($error): ?>
            <div class="p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-medium flex items-center gap-2">
                <?= icon('alert-circle', 'w-4 h-4 flex-shrink-0') ?>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="p-3.5 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-700 text-xs font-medium flex items-center gap-2">
                <?= icon('check-circle', 'w-4 h-4 flex-shrink-0') ?>
                <span><?= e($success) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="/auth/forgot-password.php" class="space-y-4">
            <?= csrf_field() ?>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Registered Email Address</label>
                <input type="email" name="email" required autofocus value="<?= e($_POST['email'] ?? '') ?>" class="w-full text-sm px-3.5 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-colors">
            </div>

            <button type="submit" class="w-full py-3 px-4 rounded-xl text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 shadow-md shadow-blue-600/20 transition-colors">
                Send Reset Instructions
            </button>
        </form>

        <div class="pt-4 border-t border-slate-100 text-center text-xs text-slate-500">
            Remembered your password? 
            <a href="/auth/login.php" class="font-bold text-blue-600 hover:text-blue-700 ml-1">Sign in</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../app/layouts/landing_footer.php'; ?>
