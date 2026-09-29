<?php
/**
 * NumVault - Customer Account Profile & Security Settings
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\RateLimiter;
use App\Core\Logger;

$user = require_login();
$pageTitle = "My Profile & Security";

$pdo = get_db();
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'change_password') {
        $rate = RateLimiter::check('pwd_change', 5, 900);
        if (!$rate['allowed']) {
            $error = "Too many password change attempts. Please wait {$rate['retry_after']} seconds.";
        } else {
            $currentPass = $_POST['current_password'] ?? '';
            $newPass     = $_POST['new_password'] ?? '';
            $confirmPass = $_POST['confirm_password'] ?? '';

            // Verify current password
            $stmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
            $stmt->execute([$user['id']]);
            $currentHash = $stmt->fetchColumn();

            if (!password_verify($currentPass, (string)$currentHash)) {
                RateLimiter::hit('pwd_change', 900);
                $error = 'The current password you provided is incorrect.';
            } elseif (strlen($newPass) < 8) {
                $error = 'The new password must be at least 8 characters in length.';
            } elseif ($newPass !== $confirmPass) {
                $error = 'The new password confirmation does not match.';
            } else {
                RateLimiter::clear('pwd_change');
                $newHash = password_hash($newPass, PASSWORD_BCRYPT);
                $upd = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
                $upd->execute([$newHash, $user['id']]);

                log_audit($user['id'], 'password_changed', 'Customer changed account password');
                $success = 'Your password has been securely updated.';
            }
        }
    }
}

require_once __DIR__ . '/../app/layouts/user_header.php';
?>

<div class="max-w-2xl mx-auto space-y-6">

    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs flex items-center gap-4">
        <div class="w-14 h-14 rounded-2xl bg-blue-50 text-blue-700 border border-blue-200 flex items-center justify-center font-black text-xl">
            <?= strtoupper(substr($user['username'], 0, 2)) ?>
        </div>
        <div>
            <h1 class="text-xl font-extrabold text-slate-900"><?= e($user['username']) ?></h1>
            <p class="text-xs text-slate-500 font-mono"><?= e($user['email']) ?> &bull; Member since <?= date('M Y', strtotime($user['created_at'])) ?></p>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-medium flex items-center gap-2.5">
            <?= icon('alert-circle', 'w-5 h-5 flex-shrink-0') ?>
            <span><?= e($error) ?></span>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="p-4 rounded-xl bg-emerald-50 border border-emerald-200 text-emerald-800 text-xs font-medium flex items-center gap-2.5">
            <?= icon('check-circle', 'w-5 h-5 flex-shrink-0') ?>
            <span><?= e($success) ?></span>
        </div>
    <?php endif; ?>

    <!-- Password Change Card -->
    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 sm:p-8 shadow-xs space-y-5">
        <div>
            <h2 class="text-base font-bold text-slate-900">Change Account Password</h2>
            <p class="text-xs text-slate-500 mt-0.5">Protect your account and wallet with a strong unique password</p>
        </div>

        <form method="POST" action="/user/profile.php" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="change_password">

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Current Password</label>
                <input type="password" name="current_password" required class="w-full text-xs px-3.5 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-500 outline-none">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">New Password (min 8 characters)</label>
                <input type="password" name="new_password" required class="w-full text-xs px-3.5 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-500 outline-none">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Confirm New Password</label>
                <input type="password" name="confirm_password" required class="w-full text-xs px-3.5 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-500 outline-none">
            </div>

            <div class="pt-2">
                <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-xs transition-colors">
                    Update Password
                </button>
            </div>
        </form>
    </div>

</div>

<?php require_once __DIR__ . '/../app/layouts/user_footer.php'; ?>
