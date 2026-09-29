<?php
/**
 * NumVault - Step 4: Administrator Account & Site Customization
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (file_exists(INSTALL_LOCK_FILE)) {
    header('Location: /install/index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $siteName = trim($_POST['site_name'] ?? 'NumVault');
    $currency = trim($_POST['currency'] ?? '$');
    $adminUser = trim($_POST['admin_user'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPass = $_POST['admin_pass'] ?? '';

    if (empty($adminUser) || empty($adminEmail) || strlen($adminPass) < 8) {
        $error = 'Admin password must be at least 8 characters long and all fields are required.';
    } else {
        try {
            // 0. Persist verified database configuration from Step 3
            if (!empty($_SESSION['install_db']) && is_array($_SESSION['install_db'])) {
                $dbConfigFile = STORAGE_PATH . '/db_config.json';
                @file_put_contents($dbConfigFile, json_encode($_SESSION['install_db'], JSON_PRETTY_PRINT), LOCK_EX);
                @chmod($dbConfigFile, 0600);
            }

            $pdo = get_db();

            // 1. Save settings
            $stmt = $pdo->prepare("INSERT INTO settings (key_name, value_text) VALUES (?, ?) ON DUPLICATE KEY UPDATE value_text = ?");
            $stmt->execute(['site_name', $siteName, $siteName]);
            $stmt->execute(['currency_symbol', $currency, $currency]);
            $stmt->execute(['order_timeout_minutes', '5', '5']);
            $stmt->execute(['min_deposit', '1.00', '1.00']);

            // 2. Create or update master admin user
            $hash = password_hash($adminPass, PASSWORD_BCRYPT);
            $uStmt = $pdo->prepare("
                INSERT INTO users (username, email, password_hash, role, balance, status)
                VALUES (?, ?, ?, 'admin', 100.00, 'active')
                ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash), role = 'admin'
            ");
            $uStmt->execute([$adminUser, $adminEmail, $hash]);

            // 3. Write installation lock file
            $lockData = [
                'installed_at' => date('c'),
                'version'      => '2.0.0-hardened',
                'admin_user'   => $adminUser,
                'admin_email'  => $adminEmail,
            ];
            @file_put_contents(INSTALL_LOCK_FILE, json_encode($lockData, JSON_PRETTY_PRINT), LOCK_EX);

            header('Location: /install/complete.php');
            exit;
        } catch (Exception $e) {
            $error = 'Configuration Error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-100 antialiased">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installer - Site Setup</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-full flex items-center justify-center p-4">
    <div class="max-w-xl w-full bg-white rounded-2xl border border-slate-200 shadow-sm p-8 space-y-6">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-blue-600 text-white flex items-center justify-center font-bold">3</div>
            <div>
                <h1 class="text-xl font-bold text-slate-900">Admin Account & Settings</h1>
                <p class="text-xs text-slate-500">Configure your brand name and create super admin credentials</p>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-medium">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="/install/setup.php" class="space-y-4">
            <?= csrf_field() ?>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Platform Name</label>
                    <input type="text" name="site_name" value="NumVault" required class="w-full text-xs px-3 py-2 rounded-lg border border-slate-300">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Currency Symbol</label>
                    <input type="text" name="currency" value="$" required class="w-full text-xs px-3 py-2 rounded-lg border border-slate-300">
                </div>
            </div>

            <div class="pt-2 border-t border-slate-100">
                <h2 class="text-xs font-bold text-slate-900 uppercase tracking-wider mb-3">Super Admin Credentials</h2>
                <div class="space-y-3">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Admin Username</label>
                        <input type="text" name="admin_user" value="admin" required class="w-full text-xs px-3 py-2 rounded-lg border border-slate-300">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Admin Email</label>
                        <input type="email" name="admin_email" value="admin@numvault.io" required class="w-full text-xs px-3 py-2 rounded-lg border border-slate-300">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Master Password (min 8 chars)</label>
                        <input type="password" name="admin_pass" required class="w-full text-xs px-3 py-2 rounded-lg border border-slate-300">
                    </div>
                </div>
            </div>

            <div class="pt-3 flex items-center justify-between">
                <a href="/install/database.php" class="text-xs font-semibold text-slate-500 hover:text-slate-800">&larr; Back</a>
                <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-xl text-xs shadow-md shadow-blue-600/20 transition-colors">
                    Complete Installation & Lock &rarr;
                </button>
            </div>
        </form>
    </div>
</body>
</html>
