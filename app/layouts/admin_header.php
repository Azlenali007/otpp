<?php
/**
 * NumVault - Master Administration Console Layout Header
 * Completely independent Admin layout (Slate + Blue accents, Non-sticky document flow)
 */

declare(strict_types=1);

$admin = require_admin();
$siteName = get_setting('site_name', 'NumVault');

// Real-time counter metrics
$activeOrdersCount = 0;
$pendingTickets = 0;
try {
    $pdo = get_db();
    $activeOrdersCount = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'active'")->fetchColumn();
    $pendingTickets = (int)$pdo->query("SELECT COUNT(*) FROM tickets WHERE status IN ('open', 'answered')")->fetchColumn();
} catch (Exception $e) {}

$currentScript = basename($_SERVER['PHP_SELF'] ?? '');
$flashes = get_flash();
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-100 text-slate-900 antialiased">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'Admin Console') ?> - <?= e($siteName) ?> Control Room</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            500: '#2563eb',
                            600: '#1d4ed8',
                            700: '#1e40af',
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="min-h-full flex flex-col bg-slate-100 font-sans text-slate-800">

    <!-- Admin Main Header (Document flow, distinct dark slate theme) -->
    <header class="w-full bg-slate-900 text-white border-b border-slate-800">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-18 py-3">
                <!-- Admin Brand -->
                <div class="flex items-center gap-3">
                    <a href="/admin/dashboard.php" class="flex items-center gap-2.5">
                        <div class="w-9 h-9 rounded-lg bg-blue-600 flex items-center justify-center text-white shadow-sm font-bold">
                            NV
                        </div>
                        <div>
                            <span class="text-base font-bold tracking-tight text-white"><?= e($siteName) ?></span>
                            <span class="block text-[10px] uppercase font-bold tracking-widest text-blue-400">Master Admin System</span>
                        </div>
                    </a>
                </div>

                <!-- Admin Status Counters & Profile -->
                <div class="flex items-center gap-4 text-xs">
                    <div class="hidden sm:flex items-center gap-3 bg-slate-800/80 px-3 py-1.5 rounded-lg border border-slate-700/60">
                        <span class="flex items-center gap-1.5 text-slate-300">
                            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
                            Active Orders: <strong class="text-white"><?= $activeOrdersCount ?></strong>
                        </span>
                        <span class="text-slate-600">|</span>
                        <span class="text-slate-300">
                            Open Tickets: <strong class="text-amber-400"><?= $pendingTickets ?></strong>
                        </span>
                    </div>

                    <div class="flex items-center gap-3 pl-3 border-l border-slate-700">
                        <div class="text-right hidden md:block">
                            <div class="font-semibold text-white leading-tight"><?= e($admin['username']) ?></div>
                            <div class="text-[10px] text-slate-400">Super Administrator</div>
                        </div>
                        <a href="/auth/logout.php" class="p-2 text-slate-400 hover:text-rose-400 hover:bg-slate-800 rounded-lg transition-colors" title="Log Out of Admin">
                            <?= icon('log-out', 'w-4 h-4') ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Admin Primary Navigation Bar -->
        <div class="w-full bg-slate-800 border-t border-slate-700/60 px-4 sm:px-6 lg:px-8">
            <div class="max-w-7xl mx-auto flex items-center overflow-x-auto py-2 gap-1 sm:gap-2">
                <a href="/admin/dashboard.php" class="px-3 py-1.5 rounded-md text-xs font-semibold whitespace-nowrap transition-colors <?= $currentScript === 'dashboard.php' ? 'bg-blue-600 text-white shadow-xs' : 'text-slate-300 hover:text-white hover:bg-slate-700/50' ?>">
                    Dashboard
                </a>
                <a href="/admin/orders.php" class="px-3 py-1.5 rounded-md text-xs font-semibold whitespace-nowrap transition-colors <?= $currentScript === 'orders.php' ? 'bg-blue-600 text-white shadow-xs' : 'text-slate-300 hover:text-white hover:bg-slate-700/50' ?>">
                    All Orders
                </a>
                <a href="/admin/users.php" class="px-3 py-1.5 rounded-md text-xs font-semibold whitespace-nowrap transition-colors <?= $currentScript === 'users.php' ? 'bg-blue-600 text-white shadow-xs' : 'text-slate-300 hover:text-white hover:bg-slate-700/50' ?>">
                    Users & Wallets
                </a>
                <a href="/admin/providers.php" class="px-3 py-1.5 rounded-md text-xs font-semibold whitespace-nowrap transition-colors <?= $currentScript === 'providers.php' ? 'bg-blue-600 text-white shadow-xs' : 'text-slate-300 hover:text-white hover:bg-slate-700/50' ?>">
                    SMS Providers API
                </a>
                <a href="/admin/services.php" class="px-3 py-1.5 rounded-md text-xs font-semibold whitespace-nowrap transition-colors <?= $currentScript === 'services.php' ? 'bg-blue-600 text-white shadow-xs' : 'text-slate-300 hover:text-white hover:bg-slate-700/50' ?>">
                    Services
                </a>
                <a href="/admin/countries.php" class="px-3 py-1.5 rounded-md text-xs font-semibold whitespace-nowrap transition-colors <?= $currentScript === 'countries.php' ? 'bg-blue-600 text-white shadow-xs' : 'text-slate-300 hover:text-white hover:bg-slate-700/50' ?>">
                    Countries
                </a>
                <a href="/admin/servers.php" class="px-3 py-1.5 rounded-md text-xs font-semibold whitespace-nowrap transition-colors <?= $currentScript === 'servers.php' ? 'bg-blue-600 text-white shadow-xs' : 'text-slate-300 hover:text-white hover:bg-slate-700/50' ?>">
                    Servers
                </a>
                <a href="/admin/pricing.php" class="px-3 py-1.5 rounded-md text-xs font-semibold whitespace-nowrap transition-colors <?= $currentScript === 'pricing.php' ? 'bg-blue-600 text-white shadow-xs' : 'text-slate-300 hover:text-white hover:bg-slate-700/50' ?>">
                    Pricing & Margins
                </a>
                <a href="/admin/payments.php" class="px-3 py-1.5 rounded-md text-xs font-semibold whitespace-nowrap transition-colors <?= $currentScript === 'payments.php' ? 'bg-blue-600 text-white shadow-xs' : 'text-slate-300 hover:text-white hover:bg-slate-700/50' ?>">
                    Deposits & Gateway
                </a>
                <a href="/admin/refunds.php" class="px-3 py-1.5 rounded-md text-xs font-semibold whitespace-nowrap transition-colors <?= $currentScript === 'refunds.php' ? 'bg-blue-600 text-white shadow-xs' : 'text-slate-300 hover:text-white hover:bg-slate-700/50' ?>">
                    Refunds
                </a>
                <a href="/admin/tickets.php" class="px-3 py-1.5 rounded-md text-xs font-semibold whitespace-nowrap transition-colors <?= $currentScript === 'tickets.php' ? 'bg-blue-600 text-white shadow-xs' : 'text-slate-300 hover:text-white hover:bg-slate-700/50' ?>">
                    Support (<?= $pendingTickets ?>)
                </a>
                <a href="/admin/settings.php" class="px-3 py-1.5 rounded-md text-xs font-semibold whitespace-nowrap transition-colors <?= $currentScript === 'settings.php' ? 'bg-blue-600 text-white shadow-xs' : 'text-slate-300 hover:text-white hover:bg-slate-700/50' ?>">
                    System Settings
                </a>
                <a href="/admin/logs.php" class="px-3 py-1.5 rounded-md text-xs font-semibold whitespace-nowrap transition-colors <?= $currentScript === 'logs.php' ? 'bg-blue-600 text-white shadow-xs' : 'text-slate-300 hover:text-white hover:bg-slate-700/50' ?>">
                    Security Logs
                </a>
            </div>
        </div>
    </header>

    <!-- Flash Messages -->
    <?php if (!empty($flashes)): ?>
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-4 w-full">
            <?php foreach ($flashes as $type => $msg): ?>
                <div class="p-4 mb-3 rounded-xl border flex items-center justify-between <?= $type === 'success' ? 'bg-emerald-50 text-emerald-800 border-emerald-200' : ($type === 'error' ? 'bg-rose-50 text-rose-800 border-rose-200' : 'bg-blue-50 text-blue-800 border-blue-200') ?>">
                    <div class="flex items-center gap-2.5 text-sm font-medium">
                        <?= icon($type === 'success' ? 'check-circle' : 'alert-circle', 'w-5 h-5 flex-shrink-0') ?>
                        <span><?= e($msg) ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <!-- Admin Main Body -->
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8">
