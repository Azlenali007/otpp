<?php
/**
 * NumVault - Step 2: System Requirements & Extension Checks
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (file_exists(INSTALL_LOCK_FILE)) {
    header('Location: /install/index.php');
    exit;
}

$checks = [
    'PHP Version (>= 8.1.0)' => [
        'status' => version_compare(PHP_VERSION, '8.1.0', '>='),
        'current' => PHP_VERSION,
        'required' => '>= 8.1.0'
    ],
    'PDO MySQL Extension' => [
        'status' => extension_loaded('pdo_mysql'),
        'current' => extension_loaded('pdo_mysql') ? 'Enabled' : 'Missing',
        'required' => 'Required'
    ],
    'cURL Extension' => [
        'status' => extension_loaded('curl'),
        'current' => extension_loaded('curl') ? 'Enabled' : 'Missing',
        'required' => 'Required'
    ],
    'OpenSSL Extension' => [
        'status' => extension_loaded('openssl'),
        'current' => extension_loaded('openssl') ? 'Enabled' : 'Missing',
        'required' => 'Required'
    ],
    'JSON Extension' => [
        'status' => extension_loaded('json'),
        'current' => extension_loaded('json') ? 'Enabled' : 'Missing',
        'required' => 'Required'
    ],
    'Mbstring Extension' => [
        'status' => extension_loaded('mbstring'),
        'current' => extension_loaded('mbstring') ? 'Enabled' : 'Missing',
        'required' => 'Required'
    ],
    'Session Support' => [
        'status' => extension_loaded('session'),
        'current' => extension_loaded('session') ? 'Enabled' : 'Missing',
        'required' => 'Required'
    ],
    'Storage Directory Writable' => [
        'status' => is_writable(STORAGE_PATH),
        'current' => is_writable(STORAGE_PATH) ? 'Writable' : 'Not Writable',
        'required' => 'Writable (0755)'
    ]
];

$allPassed = true;
foreach ($checks as $c) {
    if (!$c['status']) {
        $allPassed = false;
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-100 antialiased">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installer - Requirements Check</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-full flex items-center justify-center p-4">
    <div class="max-w-xl w-full bg-white rounded-2xl border border-slate-200 shadow-sm p-8 space-y-6">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-blue-600 text-white flex items-center justify-center font-bold">1</div>
            <div>
                <h1 class="text-xl font-bold text-slate-900">System Prerequisites Check</h1>
                <p class="text-xs text-slate-500">Checking environment, PHP extensions and write permissions</p>
            </div>
        </div>

        <div class="divide-y divide-slate-100 border border-slate-200 rounded-xl overflow-hidden text-xs">
            <?php foreach ($checks as $name => $chk): ?>
                <div class="p-3.5 flex items-center justify-between <?= $chk['status'] ? 'bg-slate-50/50' : 'bg-rose-50/50' ?>">
                    <div>
                        <span class="font-bold text-slate-800"><?= e($name) ?></span>
                        <div class="text-[11px] text-slate-500">Detected: <?= e($chk['current']) ?> (<?= e($chk['required']) ?>)</div>
                    </div>
                    <div>
                        <?php if ($chk['status']): ?>
                            <span class="px-2.5 py-1 bg-emerald-50 text-emerald-700 font-bold rounded-lg border border-emerald-200">PASS</span>
                        <?php else: ?>
                            <span class="px-2.5 py-1 bg-rose-50 text-rose-700 font-bold rounded-lg border border-rose-200">FAIL</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="flex items-center justify-between pt-2">
            <a href="/install/index.php" class="text-xs font-semibold text-slate-500 hover:text-slate-800">&larr; Refresh</a>
            <?php if ($allPassed): ?>
                <a href="/install/database.php" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-xl text-xs shadow-md shadow-blue-600/20 transition-colors">
                    Continue to Database Setup &rarr;
                </a>
            <?php else: ?>
                <button disabled class="px-5 py-2.5 bg-slate-300 text-slate-500 font-bold rounded-xl text-xs cursor-not-allowed">
                    Fix Missing Requirements to Proceed
                </button>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
