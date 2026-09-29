<?php
/**
 * NumVault - Customer Dashboard
 * Displays real balance, active order widget, quick actions, recent orders
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$user = require_login();
$pageTitle = "Customer Dashboard";

$pdo = get_db();

// 1. Get active orders for this user
$activeStmt = $pdo->prepare("
    SELECT o.*, 
           sv.name AS service_name, sv.icon AS service_icon,
           c.name AS country_name, c.code AS country_code, c.prefix AS country_prefix,
           s.server_name
    FROM orders o
    JOIN services sv ON o.service_id = sv.id
    JOIN countries c ON o.country_id = c.id
    JOIN servers s ON o.server_id = s.id
    WHERE o.user_id = ? AND o.status = 'active'
    ORDER BY o.id DESC
");
$activeStmt->execute([$user['id']]);
$activeOrders = $activeStmt->fetchAll();

// 2. Get recent completed / expired orders (limit 6)
$recentStmt = $pdo->prepare("
    SELECT o.*, 
           sv.name AS service_name, sv.icon AS service_icon,
           c.name AS country_name, c.code AS country_code
    FROM orders o
    JOIN services sv ON o.service_id = sv.id
    JOIN countries c ON o.country_id = c.id
    WHERE o.user_id = ?
    ORDER BY o.id DESC
    LIMIT 6
");
$recentStmt->execute([$user['id']]);
$recentOrders = $recentStmt->fetchAll();

// 3. User SQL statistics
$statStmt = $pdo->prepare("
    SELECT 
        COUNT(*) AS total_orders,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_orders,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_orders,
        SUM(CASE WHEN is_refunded = 1 THEN refund_amount ELSE 0 END) AS total_refunded,
        SUM(amount) AS total_spent
    FROM orders 
    WHERE user_id = ?
");
$statStmt->execute([$user['id']]);
$stats = $statStmt->fetch();

require_once __DIR__ . '/../app/layouts/user_header.php';
?>

<div class="space-y-8">

    <!-- Top Account Banner -->
    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 sm:p-8 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-6">
        <div>
            <div class="inline-flex items-center gap-2 px-2.5 py-1 rounded-full text-xs font-semibold bg-blue-50 text-blue-700 border border-blue-200/60 mb-2">
                <span class="w-1.5 h-1.5 rounded-full bg-blue-600 animate-pulse"></span>
                <span>Active Account</span>
            </div>
            <h1 class="text-2xl sm:text-3xl font-extrabold text-slate-900 tracking-tight">
                Welcome back, <?= e($user['username']) ?>
            </h1>
            <p class="text-xs sm:text-sm text-slate-500 mt-1 max-w-xl">
                Carrier-grade virtual numbers with live SMS delivery and 100% automated refund guarantee if no code arrives.
            </p>
        </div>

        <div class="flex items-center gap-3">
            <a href="/user/services.php" class="inline-flex items-center gap-2 px-5 py-3 rounded-xl bg-blue-600 hover:bg-blue-700 text-white font-bold text-sm shadow-md shadow-blue-600/20 transition-colors">
                <?= icon('phone', 'w-4 h-4') ?>
                <span>Get New Number</span>
            </a>
            <a href="/user/wallet.php" class="inline-flex items-center gap-2 px-4 py-3 rounded-xl bg-slate-100 hover:bg-slate-200 text-slate-800 font-bold text-sm transition-colors border border-slate-200">
                <?= icon('wallet', 'w-4 h-4 text-emerald-600') ?>
                <span>Add Funds</span>
            </a>
        </div>
    </div>

    <!-- Active Orders Widget (High Priority Attention) -->
    <?php if (!empty($activeOrders)): ?>
        <div class="space-y-3">
            <div class="flex items-center justify-between">
                <h2 class="text-base font-bold text-slate-900 flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full bg-blue-600 animate-ping"></span>
                    <span>Active Waiting Numbers (<?= count($activeOrders) ?>)</span>
                </h2>
                <a href="/user/orders.php?status=active" class="text-xs font-semibold text-blue-600 hover:text-blue-700">View All Active &rarr;</a>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <?php foreach ($activeOrders as $ao): ?>
                    <div class="bg-blue-50/50 border-2 border-blue-500/40 rounded-2xl p-5 shadow-xs flex flex-col justify-between space-y-4">
                        <div class="flex items-start justify-between">
                            <div class="flex items-center gap-3">
                                <div class="w-10 h-10 rounded-xl bg-white border border-blue-200 flex items-center justify-center text-blue-600 shadow-xs">
                                    <?= icon($ao['service_icon'] ?: 'shield', 'w-5 h-5') ?>
                                </div>
                                <div>
                                    <h3 class="font-bold text-slate-900 text-sm"><?= e($ao['service_name']) ?></h3>
                                    <div class="text-[11px] text-slate-500"><?= e($ao['country_name']) ?> &bull; <?= e($ao['server_name']) ?></div>
                                </div>
                            </div>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-blue-600 text-white">
                                WAITING SMS
                            </span>
                        </div>

                        <div class="bg-white p-3 rounded-xl border border-blue-200/80">
                            <div class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">Assigned Number</div>
                            <div class="text-lg font-mono font-bold text-slate-900 tracking-tight select-all">
                                <?= e($ao['phone_number']) ?>
                            </div>
                        </div>

                        <div class="flex items-center justify-between pt-1">
                            <span class="text-xs text-slate-500 font-medium">Cost: <strong class="text-slate-900"><?= format_price($ao['amount']) ?></strong></span>
                            <a href="/user/order.php?id=<?= $ao['id'] ?>" class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg bg-blue-600 text-white font-bold text-xs hover:bg-blue-700 transition-colors shadow-xs">
                                <span>Open Terminal</span>
                                <?= icon('arrow-right', 'w-3.5 h-3.5') ?>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>

    <!-- User SQL Metric Cards -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
        <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs">
            <div class="text-slate-400 text-xs font-bold uppercase tracking-wider">Current Balance</div>
            <div class="text-2xl font-black text-slate-900 mt-1 font-mono">
                <?= format_price($user['balance']) ?>
            </div>
            <div class="text-[11px] text-emerald-600 font-medium mt-1">Ready for number purchases</div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs">
            <div class="text-slate-400 text-xs font-bold uppercase tracking-wider">Completed OTPs</div>
            <div class="text-2xl font-black text-slate-900 mt-1">
                <?= (int)($stats['completed_orders'] ?? 0) ?>
            </div>
            <div class="text-[11px] text-slate-500 font-medium mt-1">Total codes received</div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs">
            <div class="text-slate-400 text-xs font-bold uppercase tracking-wider">Auto-Refunded</div>
            <div class="text-2xl font-black text-slate-900 mt-1 font-mono">
                <?= format_price($stats['total_refunded'] ?? 0) ?>
            </div>
            <div class="text-[11px] text-slate-500 font-medium mt-1">100% money-back guarantee</div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs">
            <div class="text-slate-400 text-xs font-bold uppercase tracking-wider">Total Orders</div>
            <div class="text-2xl font-black text-slate-900 mt-1">
                <?= (int)($stats['total_orders'] ?? 0) ?>
            </div>
            <div class="text-[11px] text-slate-500 font-medium mt-1">Lifetime verifications</div>
        </div>
    </div>

    <!-- Recent Order History -->
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div class="p-6 border-b border-slate-100 flex items-center justify-between">
            <div>
                <h2 class="text-base font-bold text-slate-900">Recent Verification Orders</h2>
                <p class="text-xs text-slate-500">Live order audit and code history</p>
            </div>
            <a href="/user/orders.php" class="text-xs font-bold text-blue-600 hover:text-blue-700">
                View Full History &rarr;
            </a>
        </div>

        <?php if (!empty($recentOrders)): ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-50/80 text-slate-500 font-semibold border-b border-slate-100">
                        <tr>
                            <th class="px-5 py-3.5">Order ID</th>
                            <th class="px-5 py-3.5">Service</th>
                            <th class="px-5 py-3.5">Virtual Number</th>
                            <th class="px-5 py-3.5">Amount</th>
                            <th class="px-5 py-3.5">Status</th>
                            <th class="px-5 py-3.5">OTP Code</th>
                            <th class="px-5 py-3.5">Created At</th>
                            <th class="px-5 py-3.5 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium">
                        <?php foreach ($recentOrders as $ro): ?>
                            <tr class="hover:bg-slate-50/50 transition-colors">
                                <td class="px-5 py-3.5 font-mono text-slate-900 font-bold">#<?= $ro['id'] ?></td>
                                <td class="px-5 py-3.5">
                                    <div class="font-bold text-slate-900"><?= e($ro['service_name']) ?></div>
                                    <div class="text-[11px] text-slate-400"><?= e($ro['country_name']) ?></div>
                                </td>
                                <td class="px-5 py-3.5 font-mono font-bold text-slate-900 select-all"><?= e($ro['phone_number']) ?></td>
                                <td class="px-5 py-3.5 font-bold text-slate-900"><?= format_price($ro['amount']) ?></td>
                                <td class="px-5 py-3.5">
                                    <?php if ($ro['status'] === 'completed'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                            Delivered
                                        </span>
                                    <?php elseif ($ro['status'] === 'active'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200">
                                            Waiting
                                        </span>
                                    <?php elseif ($ro['status'] === 'expired'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-600 border border-slate-200">
                                            Expired <?= $ro['is_refunded'] ? '(Refunded)' : '' ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                            <?= ucfirst($ro['status']) ?>
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-3.5">
                                    <?php if (!empty($ro['otp_code'])): ?>
                                        <span class="font-mono font-black text-blue-700 bg-blue-50 px-2.5 py-1 rounded-md border border-blue-200 text-xs">
                                            <?= e($ro['otp_code']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-slate-400 italic">None</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-3.5 text-slate-500 font-mono text-[11px]">
                                    <?= date('M d, H:i', strtotime($ro['created_at'])) ?>
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <a href="/user/order.php?id=<?= $ro['id'] ?>" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-slate-100 hover:bg-slate-200 text-slate-700 font-semibold transition-colors">
                                        <span>View</span>
                                        <?= icon('arrow-right', 'w-3 h-3') ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="p-12 text-center text-slate-400 space-y-3">
                <div class="w-12 h-12 rounded-xl bg-slate-100 text-slate-400 flex items-center justify-center mx-auto">
                    <?= icon('clock', 'w-6 h-6') ?>
                </div>
                <div class="text-sm font-semibold text-slate-700">No orders yet</div>
                <p class="text-xs text-slate-500 max-w-sm mx-auto">Select a service and country to get your first instant virtual number.</p>
                <div class="pt-2">
                    <a href="/user/services.php" class="inline-flex items-center gap-1.5 px-4 py-2 bg-blue-600 text-white rounded-xl text-xs font-bold shadow-xs">
                        <?= icon('phone', 'w-3.5 h-3.5') ?>
                        <span>Get Number Now</span>
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/../app/layouts/user_footer.php'; ?>
