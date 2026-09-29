<?php
/**
 * NumVault - Step 5: Installation Complete & Security Lock Active
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$lockData = [];
if (file_exists(INSTALL_LOCK_FILE)) {
    $lockData = json_decode((string)file_get_contents(INSTALL_LOCK_FILE), true) ?: [];
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-100 antialiased">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installer - Installation Complete</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-full flex items-center justify-center p-4">
    <div class="max-w-xl w-full bg-white rounded-2xl border border-slate-200 shadow-sm p-8 text-center space-y-6">
        <div class="w-16 h-16 rounded-2xl bg-emerald-50 text-emerald-600 border border-emerald-200 flex items-center justify-center mx-auto text-2xl font-bold shadow-xs">
            ✓
        </div>

        <div class="space-y-2">
            <h1 class="text-2xl font-black text-slate-900 tracking-tight">Installation Completed Successfully!</h1>
            <p class="text-xs text-slate-500">
                Your database tables have been provisioned, security headers applied, and the installer has been permanently locked.
            </p>
        </div>

        <div class="p-4 bg-slate-50 border border-slate-200 rounded-xl text-left text-xs space-y-1.5 font-mono">
            <div class="text-slate-500"><span class="font-bold text-slate-700">Security Lock:</span> storage/installed.lock created</div>
            <div class="text-slate-500"><span class="font-bold text-slate-700">Environment:</span> Production Mode Active</div>
            <div class="text-slate-500"><span class="font-bold text-slate-700">Admin User:</span> <?= e($lockData['admin_user'] ?? 'admin') ?></div>
            <div class="text-slate-500"><span class="font-bold text-slate-700">Installed At:</span> <?= e($lockData['installed_at'] ?? date('c')) ?></div>
        </div>

        <div class="grid grid-cols-2 gap-3 pt-2">
            <a href="/auth/login.php" class="px-5 py-3 rounded-xl border border-slate-300 hover:bg-slate-50 text-slate-700 font-bold text-xs transition-colors">
                Customer Sign In
            </a>
            <a href="/admin/login.php" class="px-5 py-3 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs shadow-md shadow-blue-600/20 transition-colors">
                Master Admin Console &rarr;
            </a>
        </div>
    </div>
</body>
</html>
