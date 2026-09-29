<?php
/**
 * NumVault - Administrator System Orders Ledger
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Orders\OrderEngine;

$admin = require_admin();
$pageTitle = "Orders Management";

$pdo = get_db();

// Handle Force Expiry / Manual Action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';
    $orderId = (int)($_POST['order_id'] ?? 0);

    if ($orderId > 0) {
        if ($action === 'force_refund') {
            OrderEngine::cancelAndRefundOrder($orderId, 'Admin forced manual cancellation and refund');
            set_flash('success', "Order #{$orderId} cancelled and refunded.");
        } elseif ($action === 'sync_order') {
            $res = OrderEngine::checkAndUpdateOrder($orderId);
            set_flash('success', "Order #{$orderId} checked: status is {$res['status']}");
        }
        header('Location: /admin/orders.php');
        exit;
    }
}

$status = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');

$query = "
    SELECT o.*, 
           u.username, u.email,
           sv.name AS service_name,
           c.name AS country_name,
           p.name AS provider_name
    FROM orders o
    JOIN users u ON o.user_id = u.id
    JOIN services sv ON o.service_id = sv.id
    JOIN countries c ON o.country_id = c.id
    JOIN providers p ON o.provider_id = p.id
";
$params = [];

if (!empty($status)) {
    $query .= " WHERE o.status = ?";
    $params[] = $status;
}

if (!empty($search)) {
    $prefix = empty($status) ? " WHERE" : " AND";
    $query .= "{$prefix} (o.phone_number LIKE ? OR u.username LIKE ? OR o.provider_order_id LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$query .= " ORDER BY o.id DESC LIMIT 100";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll();

require_once __DIR__ . '/../app/layouts/admin_header.php';
?>

<div class="space-y-6">

    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-extrabold text-slate-900 tracking-tight">System Order Ledger</h1>
            <p class="text-xs text-slate-500 mt-0.5">Global audit of all number allocations, carrier OTPs, and automated refunds</p>
        </div>

        <div class="flex items-center gap-1.5 text-xs font-semibold">
            <a href="/admin/orders.php" class="px-3 py-1.5 rounded-lg transition-colors <?= empty($status) ? 'bg-blue-600 text-white shadow-xs' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
                All
            </a>
            <a href="/admin/orders.php?status=active" class="px-3 py-1.5 rounded-lg transition-colors <?= $status === 'active' ? 'bg-blue-600 text-white shadow-xs' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
                Active Waiting
            </a>
            <a href="/admin/orders.php?status=completed" class="px-3 py-1.5 rounded-lg transition-colors <?= $status === 'completed' ? 'bg-blue-600 text-white shadow-xs' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
                Delivered
            </a>
            <a href="/admin/orders.php?status=expired" class="px-3 py-1.5 rounded-lg transition-colors <?= $status === 'expired' ? 'bg-blue-600 text-white shadow-xs' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
                Expired
            </a>
        </div>
    </div>

    <!-- Search Form -->
    <form method="GET" action="/admin/orders.php" class="flex gap-2">
        <?php if (!empty($status)): ?>
            <input type="hidden" name="status" value="<?= e($status) ?>">
        <?php endif; ?>
        <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search phone number, client username, or provider order ID..." class="flex-1 text-xs px-3.5 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-500 outline-none">
        <button type="submit" class="px-4 py-2.5 bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold rounded-xl transition-colors">
            Search
        </button>
    </form>

    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <?php if (!empty($orders)): ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-50 text-slate-500 font-semibold border-b border-slate-100">
                        <tr>
                            <th class="px-5 py-3">ID</th>
                            <th class="px-5 py-3">Client</th>
                            <th class="px-5 py-3">Service</th>
                            <th class="px-5 py-3">Phone Number</th>
                            <th class="px-5 py-3">Provider ID</th>
                            <th class="px-5 py-3">Price</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3">OTP Code</th>
                            <th class="px-5 py-3">Time</th>
                            <th class="px-5 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium">
                        <?php foreach ($orders as $o): ?>
                            <tr class="hover:bg-slate-50/50 transition-colors">
                                <td class="px-5 py-3.5 font-mono text-slate-400 font-bold">#<?= $o['id'] ?></td>
                                <td class="px-5 py-3.5">
                                    <div class="font-bold text-slate-900"><?= e($o['username']) ?></div>
                                    <div class="text-[11px] text-slate-400 font-mono"><?= e($o['email']) ?></div>
                                </td>
                                <td class="px-5 py-3.5 font-bold text-slate-900"><?= e($o['service_name']) ?> (<?= e($o['country_name']) ?>)</td>
                                <td class="px-5 py-3.5 font-mono font-bold text-slate-900"><?= e($o['phone_number']) ?></td>
                                <td class="px-5 py-3.5 font-mono text-slate-500 text-[11px]"><?= e($o['provider_name']) ?>: <?= e($o['provider_order_id']) ?></td>
                                <td class="px-5 py-3.5 font-mono font-bold text-slate-900"><?= format_price($o['amount']) ?></td>
                                <td class="px-5 py-3.5">
                                    <?php if ($o['status'] === 'completed'): ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">Delivered</span>
                                    <?php elseif ($o['status'] === 'active'): ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200">Active</span>
                                    <?php elseif ($o['status'] === 'expired'): ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-600">Expired</span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-700"><?= ucfirst($o['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-3.5 font-mono font-bold text-blue-600">
                                    <?= e($o['otp_code'] ?? '-') ?>
                                </td>
                                <td class="px-5 py-3.5 font-mono text-slate-400 text-[11px]">
                                    <?= date('M d, H:i', strtotime($o['created_at'])) ?>
                                </td>
                                <td class="px-5 py-3.5 text-right space-x-1.5">
                                    <?php if ($o['status'] === 'active'): ?>
                                        <form method="POST" action="/admin/orders.php" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="sync_order">
                                            <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                                            <button type="submit" class="px-2 py-1 bg-blue-50 text-blue-700 font-bold rounded text-[11px]">Sync</button>
                                        </form>

                                        <form method="POST" action="/admin/orders.php" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="force_refund">
                                            <input type="hidden" name="order_id" value="<?= $o['id'] ?>">
                                            <button type="submit" class="px-2 py-1 bg-rose-50 text-rose-700 font-bold rounded text-[11px]">Refund</button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="p-12 text-center text-slate-400 text-xs">
                No orders found.
            </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/../app/layouts/admin_footer.php'; ?>
