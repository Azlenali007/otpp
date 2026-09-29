<?php
/**
 * NumVault - Customer Login
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\RateLimiter;
use App\Core\Logger;
use App\Core\Session;

if (is_logged_in()) {
    header('Location: /user/dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    // 1. Rate Limiting Check
    $rate = RateLimiter::check('login', 5, 300);
    if (!$rate['allowed']) {
        $error = "Too many login attempts. Please wait {$rate['retry_after']} seconds before trying again.";
    } else {
        $identity = trim($_POST['identity'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($identity) || empty($password)) {
            $error = 'Please enter your username/email and password.';
        } else {
            $pdo = get_db();
            $stmt = $pdo->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) LIMIT 1");
            $stmt->execute([$identity, $identity]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password_hash'])) {
                if ($user['status'] === 'blocked') {
                    $error = 'Your account has been suspended. Please contact customer support.';
                } else {
                    RateLimiter::clear('login');
                    Session::regenerate();
                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['username'] = $user['username'];
                    $_SESSION['is_admin'] = ($user['role'] === 'admin');

                    log_audit((int)$user['id'], 'user_login', 'Customer authenticated successfully');

                    set_flash('success', "Welcome back, {$user['username']}!");

                    $target = ($user['role'] === 'admin') ? '/admin/dashboard.php' : '/user/dashboard.php';
                    header("Location: {$target}");
                    exit;
                }
            } else {
                RateLimiter::hit('login', 300);
                Logger::security("Failed login attempt for identity '{$identity}'");
                $error = 'Invalid username/email or password credentials.';
            }
        }
    }
}

$pageTitle = "Customer Sign In";
require_once __DIR__ . '/../app/layouts/landing_header.php';
?>

<div class="py-16 max-w-md mx-auto px-4 sm:px-6">
    <div class="bg-white p-8 rounded-2xl border border-slate-200/80 shadow-xs space-y-6">
        <div class="text-center space-y-1">
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Customer Sign In</h1>
            <p class="text-xs text-slate-500">Access your virtual number dashboard and wallet</p>
        </div>

        <?php if ($error): ?>
            <div class="p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-medium flex items-center gap-2">
                <?= icon('alert-circle', 'w-4 h-4 flex-shrink-0') ?>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="/auth/login.php" class="space-y-4">
            <?= csrf_field() ?>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Username or Email</label>
                <input type="text" name="identity" required autofocus value="<?= e($_POST['identity'] ?? '') ?>" class="w-full text-sm px-3.5 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-colors">
            </div>

            <div>
                <div class="flex items-center justify-between mb-1">
                    <label class="block text-xs font-semibold text-slate-700">Password</label>
                    <a href="/auth/forgot-password.php" class="text-xs text-blue-600 hover:text-blue-700 font-medium">Forgot?</a>
                </div>
                <input type="password" name="password" required class="w-full text-sm px-3.5 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-colors">
            </div>

            <button type="submit" class="w-full py-3 px-4 rounded-xl text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 shadow-md shadow-blue-600/20 transition-colors">
                Sign In to Account
            </button>
        </form>

        <div class="pt-4 border-t border-slate-100 text-center text-xs text-slate-500">
            Don't have an account yet? 
            <a href="/auth/register.php" class="font-bold text-blue-600 hover:text-blue-700 ml-1">Create free account</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../app/layouts/landing_footer.php'; ?>
