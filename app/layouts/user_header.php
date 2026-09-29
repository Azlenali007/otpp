<?php
/**
 * NumVault - User Panel Layout Header
 * Independent User Layout: Document flow, White + Premium Blue accents
 */

declare(strict_types=1);

$user = require_login();
$siteName = get_setting('site_name', 'NumVault');
$currency = get_setting('currency_symbol', '$');

// Fetch unread notifications count
if (!isset($unreadCount)) {
    try {
        $pdo = get_db();
        $nStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0");
        $nStmt->execute([$user['id']]);
        $unreadCount = (int)$nStmt->fetchColumn();
    } catch (Exception $e) {
        $unreadCount = 0;
    }
}

$currentScript = basename($_SERVER['PHP_SELF'] ?? '');
$flashes = get_flash();
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-slate-50 text-slate-900 antialiased">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'User Portal') ?> - <?= e($siteName) ?></title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                            800: '#1e40af',
                            900: '#1e3a8a',
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="min-h-full flex flex-col bg-slate-50 font-sans text-slate-800">

    <!-- User Top Navigation (Document flow, no sticky/fixed) -->
    <header class="w-full bg-white border-b border-slate-200">
        <!-- Top bar with user profile & wallet stats -->
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-20">
                <!-- User Brand Logo -->
                <div class="flex items-center gap-3">
                    <a href="/user/dashboard.php" class="flex items-center gap-2.5">
                        <div class="w-10 h-10 rounded-xl bg-blue-600 flex items-center justify-center text-white shadow-md shadow-blue-600/20">
                            <?= icon('shield', 'w-5 h-5') ?>
                        </div>
                        <div>
                            <span class="text-lg font-bold tracking-tight text-slate-900"><?= e($siteName) ?></span>
                            <span class="block text-xs font-semibold text-blue-600 uppercase tracking-wider">User Portal</span>
                        </div>
                    </a>
                </div>

                <!-- Right Action Bar: Real Wallet Balance + Notifications + User Menu -->
                <div class="flex items-center gap-3 sm:gap-4">
                    <!-- Real Wallet Balance Badge & Quick Deposit -->
                    <div class="flex items-center bg-slate-100 rounded-xl p-1 border border-slate-200">
                        <div class="px-3 py-1.5 flex items-center gap-2">
                            <?= icon('wallet', 'w-4 h-4 text-emerald-600') ?>
                            <div class="text-left">
                                <div class="text-[10px] font-bold text-slate-400 uppercase tracking-wider leading-none">Wallet</div>
                                <div class="text-sm font-extrabold text-slate-900 leading-tight" id="headerWalletBalance">
                                    <?= format_price($user['balance']) ?>
                                </div>
                            </div>
                        </div>
                        <a href="/user/wallet.php" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-blue-600 hover:bg-blue-700 text-white text-xs font-semibold shadow-xs transition-colors">
                            <?= icon('plus', 'w-3.5 h-3.5') ?>
                            <span class="hidden sm:inline">Add Funds</span>
                        </a>
                    </div>

                    <!-- Notifications Link -->
                    <a href="/user/notifications.php" class="relative p-2.5 text-slate-600 hover:text-blue-600 hover:bg-slate-100 rounded-xl border border-slate-200 transition-colors" title="Notifications">
                        <?= icon('bell', 'w-5 h-5') ?>
                        <?php if ($unreadCount > 0): ?>
                            <span class="absolute -top-1 -right-1 inline-flex items-center justify-center w-5 h-5 text-[11px] font-bold text-white bg-rose-600 rounded-full">
                                <?= $unreadCount > 9 ? '9+' : $unreadCount ?>
                            </span>
                        <?php endif; ?>
                    </a>

                    <!-- User Profile & Logout -->
                    <div class="flex items-center gap-2 pl-2 border-l border-slate-200">
                        <a href="/user/profile.php" class="w-9 h-9 rounded-xl bg-blue-50 text-blue-700 border border-blue-200 flex items-center justify-center font-bold text-sm hover:bg-blue-100 transition-colors" title="My Profile">
                            <?= strtoupper(substr($user['username'], 0, 2)) ?>
                        </a>
                        <div class="hidden lg:block text-left">
                            <div class="text-xs font-bold text-slate-900 leading-tight"><?= e($user['username']) ?></div>
                            <div class="text-[11px] text-slate-400 leading-none"><?= e($user['email']) ?></div>
                        </div>
                        <a href="/auth/logout.php" class="p-2 text-slate-400 hover:text-rose-600 hover:bg-rose-50 rounded-lg transition-colors" title="Sign Out">
                            <?= icon('log-out', 'w-4 h-4') ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <!-- Secondary User Navigation Bar -->
        <div class="w-full bg-slate-50 border-t border-slate-200 px-4 sm:px-6 lg:px-8">
            <div class="max-w-7xl mx-auto flex items-center overflow-x-auto py-2 gap-1 sm:gap-2">
                <a href="/user/dashboard.php" class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-sm font-semibold whitespace-nowrap transition-colors <?= $currentScript === 'dashboard.php' ? 'bg-white text-blue-700 shadow-xs border border-slate-200' : 'text-slate-600 hover:text-blue-600 hover:bg-white/60' ?>">
                    <?= icon('layers', 'w-4 h-4') ?>
                    <span>Dashboard</span>
                </a>
                <a href="/user/services.php" class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-sm font-semibold whitespace-nowrap transition-colors <?= in_array($currentScript, ['services.php', 'order.php']) ? 'bg-white text-blue-700 shadow-xs border border-slate-200' : 'text-slate-600 hover:text-blue-600 hover:bg-white/60' ?>">
                    <?= icon('phone', 'w-4 h-4') ?>
                    <span>Get Number</span>
                </a>
                <a href="/user/orders.php" class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-sm font-semibold whitespace-nowrap transition-colors <?= $currentScript === 'orders.php' ? 'bg-white text-blue-700 shadow-xs border border-slate-200' : 'text-slate-600 hover:text-blue-600 hover:bg-white/60' ?>">
                    <?= icon('clock', 'w-4 h-4') ?>
                    <span>My Orders</span>
                </a>
                <a href="/user/wallet.php" class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-sm font-semibold whitespace-nowrap transition-colors <?= in_array($currentScript, ['wallet.php', 'payment.php']) ? 'bg-white text-blue-700 shadow-xs border border-slate-200' : 'text-slate-600 hover:text-blue-600 hover:bg-white/60' ?>">
                    <?= icon('wallet', 'w-4 h-4') ?>
                    <span>Wallet & Deposits</span>
                </a>
                <a href="/user/transactions.php" class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-sm font-semibold whitespace-nowrap transition-colors <?= $currentScript === 'transactions.php' ? 'bg-white text-blue-700 shadow-xs border border-slate-200' : 'text-slate-600 hover:text-blue-600 hover:bg-white/60' ?>">
                    <?= icon('refresh-cw', 'w-4 h-4') ?>
                    <span>Ledger</span>
                </a>
                <a href="/user/support.php" class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-sm font-semibold whitespace-nowrap transition-colors <?= in_array($currentScript, ['support.php', 'ticket.php']) ? 'bg-white text-blue-700 shadow-xs border border-slate-200' : 'text-slate-600 hover:text-blue-600 hover:bg-white/60' ?>">
                    <?= icon('life-buoy', 'w-4 h-4') ?>
                    <span>Support Desk</span>
                </a>
                <a href="/user/profile.php" class="inline-flex items-center gap-2 px-3.5 py-2 rounded-lg text-sm font-semibold whitespace-nowrap transition-colors <?= $currentScript === 'profile.php' ? 'bg-white text-blue-700 shadow-xs border border-slate-200' : 'text-slate-600 hover:text-blue-600 hover:bg-white/60' ?>">
                    <?= icon('user', 'w-4 h-4') ?>
                    <span>Profile</span>
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

    <!-- User Content Area -->
    <main class="flex-1 max-w-7xl w-full mx-auto px-4 sm:px-6 lg:px-8 py-8">
