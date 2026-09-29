<?php
/**
 * NumVault - Master Administration Console Dashboard
 * Real-time SQL statistics and carrier infrastructure status
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$admin = require_admin();
$pageTitle = "Admin Dashboard";

$pdo = get_db();

// 1. Core SQL Statistics
$usersCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'user'")->fetchColumn();
$totalCustomerBalance = (float)$pdo->query("SELECT SUM(balance) FROM users WHERE role = 'user'")->fetchColumn();

// Order statistics
$orderStats = $pdo->query("
    SELECT 
        COUNT(*) AS total_orders,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_orders,
        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active_orders,
        SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) AS expired_orders,
        SUM(CASE WHEN is_refunded = 1 THEN 1 ELSE 0 END) AS refunded_orders,
        SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) AS total_revenue,
        SUM(amount) AS gross_volume
    FROM orders
")->fetch();

// Payment Statistics
$paymentStats = $pdo->query("
    SELECT 
        COUNT(*) AS total_payments,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_payments,
        SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) AS completed_deposits
    FROM payments
")->fetch();

// 2. Active SMS Providers
$providers = $pdo->query("SELECT * FROM providers ORDER BY priority ASC")->fetchAll();

// 3. Recent 8 System Orders
$recentOrders = $pdo->query("
    SELECT o.*, 
           u.username,
           sv.name AS service_name,
           c.name AS country_name,
           p.name AS provider_name
    FROM orders o
    JOIN users u ON o.user_id = u.id
    JOIN services sv ON o.service_id = sv.id
    JOIN countries c ON o.country_id = c.id
    JOIN providers p ON o.provider_id = p.id
    ORDER BY o.id DESC
    LIMIT 8
")->fetchAll();

require_once __DIR__ . '/../app/layouts/admin_header.php';
?>

<div class="space-y-8">

    <!-- Top Dashboard Header -->
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight">System Infrastructure Overview</h1>
            <p class="text-xs text-slate-500 mt-1">Real-time carrier metrics, wallet liability, and live dispatch logs</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="/admin/servers.php" class="px-3.5 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold shadow-xs transition-colors">
                Configure Pricing & Servers
            </a>
            <a href="/admin/payments.php" class="px-3.5 py-2 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-xs font-bold transition-colors">
                Pending Deposits (<?= (int)$paymentStats['pending_payments'] ?>)
            </a>
        </div>
    </div>

    <!-- Metric Cards Grid -->
    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6">
        <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs">
            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Total Registered Clients</div>
            <div class="text-2xl font-black text-slate-900 mt-1 font-mono">
                <?= number_format($usersCount) ?>
            </div>
            <div class="text-[11px] text-slate-500 mt-1">Wallet liability: <strong class="text-slate-800"><?= format_price($totalCustomerBalance) ?></strong></div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs">
            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Total Orders Processed</div>
            <div class="text-2xl font-black text-slate-900 mt-1 font-mono">
                <?= number_format((int)$orderStats['total_orders']) ?>
            </div>
            <div class="text-[11px] text-slate-500 mt-1">Active waiting: <strong class="text-blue-600"><?= (int)$orderStats['active_orders'] ?></strong></div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs">
            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Completed SMS Deliveries</div>
            <div class="text-2xl font-black text-emerald-600 mt-1 font-mono">
                <?= number_format((int)$orderStats['completed_orders']) ?>
            </div>
            <div class="text-[11px] text-slate-500 mt-1">Revenue: <strong class="text-slate-800"><?= format_price($orderStats['total_revenue'] ?? 0) ?></strong></div>
        </div>

        <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs">
            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Verified Deposits</div>
            <div class="text-2xl font-black text-slate-900 mt-1 font-mono">
                <?= format_price($paymentStats['completed_deposits'] ?? 0) ?>
            </div>
            <div class="text-[11px] text-slate-500 mt-1">Pending review: <strong class="text-amber-600"><?= (int)$paymentStats['pending_payments'] ?></strong></div>
        </div>
    </div>

    <!-- SMS Gateway Providers Live Status -->
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-4">
        <div class="flex items-center justify-between">
            <div>
                <h2 class="text-base font-bold text-slate-900">Configured SMS Gateways</h2>
                <p class="text-xs text-slate-500 mt-0.5">Real API integrations and carrier dispatch endpoints</p>
            </div>
            <a href="/admin/providers.php" class="text-xs font-bold text-blue-600 hover:text-blue-700">Manage Gateways &rarr;</a>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <?php foreach ($providers as $prov): ?>
                <div class="p-4 rounded-xl border border-slate-200 bg-slate-50/50 flex flex-col justify-between space-y-3">
                    <div class="flex items-center justify-between">
                        <div class="flex items-center gap-2">
                            <span class="w-2.5 h-2.5 rounded-full <?= $prov['is_enabled'] ? 'bg-emerald-500' : 'bg-slate-300' ?>"></span>
                            <span class="text-xs font-bold text-slate-900"><?= e($prov['name']) ?></span>
                        </div>
                        <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= $prov['is_enabled'] ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-500' ?>">
                            <?= $prov['is_enabled'] ? 'ACTIVE' : 'DISABLED' ?>
                        </span>
                    </div>

                    <div class="space-y-1 font-mono text-xs">
                        <div class="text-[11px] text-slate-400">Gateway Balance</div>
                        <div class="text-lg font-black text-slate-900"><?= number_format((float)$prov['balance'], 2) ?> <?= e($prov['currency']) ?></div>
                    </div>

                    <div class="pt-2 border-t border-slate-200/80 flex items-center justify-between text-[11px] text-slate-400 font-mono">
                        <span>Priority: <?= (int)$prov['priority'] ?></span>
                        <span>Checked: <?= $prov['last_check'] ? date('H:i', strtotime($prov['last_check'])) : 'Never' ?></span>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Recent System Orders Table -->
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div class="p-6 border-b border-slate-100 flex items-center justify-between">
            <div>
                <h2 class="text-base font-bold text-slate-900">Recent Customer Verification Orders</h2>
                <p class="text-xs text-slate-500 mt-0.5">Live order dispatch across all registered clients</p>
            </div>
            <a href="/admin/orders.php" class="text-xs font-bold text-blue-600 hover:text-blue-700">All Orders &rarr;</a>
        </div>

        <?php if (!empty($recentOrders)): ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-50 text-slate-500 font-semibold border-b border-slate-100">
                        <tr>
                            <th class="px-5 py-3">Order ID</th>
                            <th class="px-5 py-3">User</th>
                            <th class="px-5 py-3">Service</th>
                            <th class="px-5 py-3">Phone Number</th>
                            <th class="px-5 py-3">Provider</th>
                            <th class="px-5 py-3">Price</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3">OTP</th>
                            <th class="px-5 py-3 text-right">Time</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium">
                        <?php foreach ($recentOrders as $ro): ?>
                            <tr class="hover:bg-slate-50/50 transition-colors">
                                <td class="px-5 py-3.5 font-mono text-slate-900 font-bold">#<?= $ro['id'] ?></td>
                                <td class="px-5 py-3.5 text-blue-600 font-bold"><?= e($ro['username']) ?></td>
                                <td class="px-5 py-3.5 font-bold text-slate-900"><?= e($ro['service_name']) ?> (<?= e($ro['country_name']) ?>)</td>
                                <td class="px-5 py-3.5 font-mono font-bold text-slate-900"><?= e($ro['phone_number']) ?></td>
                                <td class="px-5 py-3.5 text-slate-500"><?= e($ro['provider_name']) ?></td>
                                <td class="px-5 py-3.5 font-bold text-slate-900"><?= format_price($ro['amount']) ?></td>
                                <td class="px-5 py-3.5">
                                    <?php if ($ro['status'] === 'completed'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">Delivered</span>
                                    <?php elseif ($ro['status'] === 'active'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200">Waiting</span>
                                    <?php elseif ($ro['status'] === 'expired'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-600">Expired</span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-700"><?= ucfirst($ro['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-3.5 font-mono font-bold text-blue-700">
                                    <?= e($ro['otp_code'] ?? '-') ?>
                                </td>
                                <td class="px-5 py-3.5 font-mono text-slate-400 text-right">
                                    <?= date('M d, H:i', strtotime($ro['created_at'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="p-12 text-center text-slate-400 text-xs">
                No orders recorded in the system yet.
            </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/../app/layouts/admin_footer.php'; ?>
