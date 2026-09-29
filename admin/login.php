<?php
/**
 * NumVault - Administrator Login
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\RateLimiter;
use App\Core\Logger;
use App\Core\Session;

if (is_admin()) {
    header('Location: /admin/dashboard.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $rate = RateLimiter::check('admin_login', 5, 600);
    if (!$rate['allowed']) {
        $error = "Too many administrative authentication failures. System locked for {$rate['retry_after']} seconds.";
    } else {
        $login = trim($_POST['login'] ?? '');
        $password = $_POST['password'] ?? '';

        if (empty($login) || empty($password)) {
            $error = "Please enter administrator credentials.";
        } else {
            $pdo = get_db();
            $stmt = $pdo->prepare("SELECT * FROM users WHERE (username = ? OR email = ?) AND role = 'admin' LIMIT 1");
            $stmt->execute([$login, $login]);
            $admin = $stmt->fetch();

            if ($admin && password_verify($password, $admin['password_hash'])) {
                RateLimiter::clear('admin_login');
                Session::regenerate();
                $_SESSION['user_id'] = (int)$admin['id'];
                $_SESSION['username'] = $admin['username'];
                $_SESSION['is_admin'] = true;

                log_audit((int)$admin['id'], 'admin_login', 'Administrator authenticated into Master Hub');
                Logger::security("Admin '{$admin['username']}' logged in successfully");

                set_flash('success', "Welcome to NumVault Admin Console.");
                header('Location: /admin/dashboard.php');
                exit;
            } else {
                RateLimiter::hit('admin_login', 600);
                Logger::security("Failed admin login attempt for user '{$login}'");
                $error = "Invalid administrator credentials or unauthorized role.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-900">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Portal - <?= e(get_setting('site_name', 'NumVault')) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="flex flex-col min-h-full font-sans antialiased text-slate-100 bg-slate-950 items-center justify-center p-4">

<div class="max-w-md w-full bg-slate-900 border border-slate-800 rounded-2xl p-8 shadow-2xl">
    <div class="text-center mb-6">
        <div class="inline-flex items-center justify-center w-12 h-12 rounded-xl bg-blue-600 text-white mb-3 shadow-lg shadow-blue-500/20 font-black">
            NV
        </div>
        <h1 class="text-xl font-bold text-white tracking-tight">System Control Console</h1>
        <p class="text-xs text-slate-400 mt-1">Authorized personnel only &bull; Strict audit recording active</p>
    </div>

    <?php if ($error): ?>
        <div class="mb-4 p-3 rounded-lg bg-rose-950/60 border border-rose-800 text-rose-300 text-xs">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="/admin/login.php" class="space-y-4">
        <?= csrf_field() ?>

        <div>
            <label class="block text-xs font-semibold text-slate-300 mb-1">Admin Username or Email</label>
            <input type="text" name="login" required autofocus value="<?= e($_POST['login'] ?? '') ?>" class="w-full px-3.5 py-2.5 bg-slate-800 border border-slate-700 rounded-xl text-xs text-white focus:ring-2 focus:ring-blue-500 outline-none">
        </div>

        <div>
            <label class="block text-xs font-semibold text-slate-300 mb-1">Secret Key / Password</label>
            <input type="password" name="password" required class="w-full px-3.5 py-2.5 bg-slate-800 border border-slate-700 rounded-xl text-xs text-white focus:ring-2 focus:ring-blue-500 outline-none">
        </div>

        <button type="submit" class="w-full py-3 px-4 bg-blue-600 text-white rounded-xl text-xs font-bold hover:bg-blue-500 transition-colors shadow-md shadow-blue-600/30">
            Authenticate &rarr;
        </button>
    </form>

    <div class="mt-6 pt-6 border-t border-slate-800 text-center">
        <a href="/" class="text-xs text-slate-500 hover:text-slate-300">
            &larr; Return to Public Marketplace
        </a>
    </div>
</div>

</body>
</html>
