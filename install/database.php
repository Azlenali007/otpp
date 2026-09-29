<?php
/**
 * NumVault - Step 3: Database Connection & Schema Deployment
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (file_exists(INSTALL_LOCK_FILE)) {
    header('Location: /install/index.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $host = trim($_POST['db_host'] ?? '127.0.0.1');
    $port = (int)($_POST['db_port'] ?? 3306);
    $database = trim($_POST['db_name'] ?? 'otp_marketplace');
    $username = trim($_POST['db_user'] ?? 'otp_user');
    $password = $_POST['db_pass'] ?? '';

    try {
        $dsn = "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4";
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);

        // Load and execute schema.sql
        $schemaPath = APP_ROOT . '/schema.sql';
        if (file_exists($schemaPath)) {
            $sql = file_get_contents($schemaPath);
            $pdo->exec($sql);
        }

        // Store configuration in temporary session for Step 4
        $_SESSION['install_db'] = [
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'password' => $password
        ];

        header('Location: /install/setup.php');
        exit;
    } catch (PDOException $e) {
        $error = "Database Error: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-100 antialiased">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Installer - Database Configuration</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="min-h-full flex items-center justify-center p-4">
    <div class="max-w-xl w-full bg-white rounded-2xl border border-slate-200 shadow-sm p-8 space-y-6">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-blue-600 text-white flex items-center justify-center font-bold">2</div>
            <div>
                <h1 class="text-xl font-bold text-slate-900">Database Connection & Tables</h1>
                <p class="text-xs text-slate-500">Provide MySQL / MariaDB database credentials</p>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-medium">
                <?= e($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="/install/database.php" class="space-y-4">
            <?= csrf_field() ?>

            <div class="grid grid-cols-3 gap-3">
                <div class="col-span-2">
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Database Host</label>
                    <input type="text" name="db_host" value="127.0.0.1" required class="w-full text-xs px-3 py-2 rounded-lg border border-slate-300">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Port</label>
                    <input type="number" name="db_port" value="3306" required class="w-full text-xs px-3 py-2 rounded-lg border border-slate-300">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Database Name</label>
                <input type="text" name="db_name" value="otp_marketplace" required class="w-full text-xs px-3 py-2 rounded-lg border border-slate-300">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Database User</label>
                    <input type="text" name="db_user" value="otp_user" required class="w-full text-xs px-3 py-2 rounded-lg border border-slate-300">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Database Password</label>
                    <input type="password" name="db_pass" value="otp_pass_2026" class="w-full text-xs px-3 py-2 rounded-lg border border-slate-300">
                </div>
            </div>

            <div class="pt-2 flex items-center justify-between">
                <a href="/install/requirements.php" class="text-xs font-semibold text-slate-500 hover:text-slate-800">&larr; Back</a>
                <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold rounded-xl text-xs shadow-md shadow-blue-600/20 transition-colors">
                    Test Connection & Deploy Schema &rarr;
                </button>
            </div>
        </form>
    </div>
</body>
</html>
