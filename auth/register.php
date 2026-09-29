<?php
/**
 * NumVault - Customer Registration
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

    $rate = RateLimiter::check('register', 5, 3600);
    if (!$rate['allowed']) {
        $error = "Registration limit reached. Please wait {$rate['retry_after']} seconds before attempting again.";
    } else {
        $username = trim($_POST['username'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';

        if (empty($username) || empty($email) || empty($password)) {
            $error = 'All fields are required.';
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,30}$/', $username)) {
            $error = 'Username must be 3-30 alphanumeric characters or underscores.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } elseif (strlen($password) < 8) {
            $error = 'Password must be at least 8 characters long.';
        } elseif ($password !== $confirmPassword) {
            $error = 'Password confirmation does not match.';
        } else {
            $pdo = get_db();

            // Check if username or email already exists
            $checkStmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ? LIMIT 1");
            $checkStmt->execute([$username, $email]);
            if ($checkStmt->fetch()) {
                $error = 'An account with that username or email address already exists.';
            } else {
                RateLimiter::clear('register');
                $hash = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $pdo->prepare("
                    INSERT INTO users (username, email, password_hash, role, balance, status)
                    VALUES (?, ?, ?, 'user', 0.00, 'active')
                ");
                $stmt->execute([$username, $email, $hash]);
                $userId = (int)$pdo->lastInsertId();

                Session::regenerate();
                $_SESSION['user_id'] = $userId;
                $_SESSION['username'] = $username;
                $_SESSION['is_admin'] = false;

                create_notification(
                    $userId,
                    "Welcome to NumVault!",
                    "Your account has been created. Add funds to your wallet to start purchasing instant virtual numbers.",
                    'system'
                );

                log_audit($userId, 'user_registered', "New customer registered: {$username}");

                set_flash('success', "Welcome to NumVault, {$username}! Your account has been activated.");
                header('Location: /user/dashboard.php');
                exit;
            }
        }
    }
}

$pageTitle = "Create Customer Account";
require_once __DIR__ . '/../app/layouts/landing_header.php';
?>

<div class="py-16 max-w-md mx-auto px-4 sm:px-6">
    <div class="bg-white p-8 rounded-2xl border border-slate-200/80 shadow-xs space-y-6">
        <div class="text-center space-y-1">
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Create Account</h1>
            <p class="text-xs text-slate-500">Instant access to carrier-grade virtual numbers</p>
        </div>

        <?php if ($error): ?>
            <div class="p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-medium flex items-center gap-2">
                <?= icon('alert-circle', 'w-4 h-4 flex-shrink-0') ?>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="/auth/register.php" class="space-y-4">
            <?= csrf_field() ?>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Username</label>
                <input type="text" name="username" required value="<?= e($_POST['username'] ?? '') ?>" class="w-full text-sm px-3.5 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-colors">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Email Address</label>
                <input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>" class="w-full text-sm px-3.5 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-colors">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Password (min 8 chars)</label>
                <input type="password" name="password" required class="w-full text-sm px-3.5 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-colors">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Confirm Password</label>
                <input type="password" name="confirm_password" required class="w-full text-sm px-3.5 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-colors">
            </div>

            <button type="submit" class="w-full py-3 px-4 rounded-xl text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 shadow-md shadow-blue-600/20 transition-colors">
                Create Free Account
            </button>
        </form>

        <div class="pt-4 border-t border-slate-100 text-center text-xs text-slate-500">
            Already have an account? 
            <a href="/auth/login.php" class="font-bold text-blue-600 hover:text-blue-700 ml-1">Sign in</a>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../app/layouts/landing_footer.php'; ?>
