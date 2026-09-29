<?php
/**
 * NumVault - Customer Order History
 * Filter by active, completed, expired, refunded with full order lifecycle information
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$user = require_login();
$pageTitle = "My Verification Orders";

$pdo = get_db();

// Filter parameters
$status = trim($_GET['status'] ?? '');
$search = trim($_GET['search'] ?? '');

$query = "
    SELECT o.*, 
           sv.name AS service_name, sv.icon AS service_icon,
           c.name AS country_name, c.code AS country_code, c.prefix AS country_prefix,
           s.server_name
    FROM orders o
    JOIN services sv ON o.service_id = sv.id
    JOIN countries c ON o.country_id = c.id
    JOIN servers s ON o.server_id = s.id
    WHERE o.user_id = ?
";
$params = [$user['id']];

if (!empty($status)) {
    if ($status === 'refunded') {
        $query .= " AND o.is_refunded = 1";
    } else {
        $query .= " AND o.status = ?";
        $params[] = $status;
    }
}

if (!empty($search)) {
    $query .= " AND (o.phone_number LIKE ? OR sv.name LIKE ? OR c.name LIKE ?)";
    $like = "%{$search}%";
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$query .= " ORDER BY o.id DESC LIMIT 100";

$stmt = $pdo->prepare($query);
$stmt->execute($params);
$orders = $stmt->fetchAll();

require_once __DIR__ . '/../app/layouts/user_header.php';
?>

<div class="space-y-6">

    <!-- Top Filter Header -->
    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-extrabold text-slate-900 tracking-tight">Order History</h1>
            <p class="text-xs text-slate-500 mt-0.5">Comprehensive audit trail of virtual numbers and delivered SMS codes</p>
        </div>

        <!-- Filter Status Buttons -->
        <div class="flex items-center gap-1.5 overflow-x-auto text-xs font-semibold">
            <a href="/user/orders.php" class="px-3 py-1.5 rounded-lg transition-colors <?= empty($status) ? 'bg-blue-600 text-white shadow-xs' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
                All Orders
            </a>
            <a href="/user/orders.php?status=active" class="px-3 py-1.5 rounded-lg transition-colors <?= $status === 'active' ? 'bg-blue-600 text-white shadow-xs' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
                Active Waiting
            </a>
            <a href="/user/orders.php?status=completed" class="px-3 py-1.5 rounded-lg transition-colors <?= $status === 'completed' ? 'bg-blue-600 text-white shadow-xs' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
                Delivered
            </a>
            <a href="/user/orders.php?status=refunded" class="px-3 py-1.5 rounded-lg transition-colors <?= $status === 'refunded' ? 'bg-blue-600 text-white shadow-xs' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
                Refunded
            </a>
        </div>
    </div>

    <!-- Search Form -->
    <form method="GET" action="/user/orders.php" class="flex gap-2">
        <?php if (!empty($status)): ?>
            <input type="hidden" name="status" value="<?= e($status) ?>">
        <?php endif; ?>
        <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search by phone number, service name, or country..." class="flex-1 text-xs px-3.5 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-500 outline-none">
        <button type="submit" class="px-4 py-2.5 bg-slate-800 hover:bg-slate-900 text-white text-xs font-bold rounded-xl transition-colors">
            Search
        </button>
    </form>

    <!-- Orders Table Card -->
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <?php if (!empty($orders)): ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-50/80 text-slate-500 font-semibold border-b border-slate-100">
                        <tr>
                            <th class="px-5 py-3.5">Order ID</th>
                            <th class="px-5 py-3.5">Service & Server</th>
                            <th class="px-5 py-3.5">Virtual Number</th>
                            <th class="px-5 py-3.5">Amount</th>
                            <th class="px-5 py-3.5">Status</th>
                            <th class="px-5 py-3.5">OTP Code</th>
                            <th class="px-5 py-3.5">Order Time</th>
                            <th class="px-5 py-3.5 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium">
                        <?php foreach ($orders as $o): ?>
                            <tr class="hover:bg-slate-50/60 transition-colors">
                                <td class="px-5 py-3.5 font-mono text-slate-900 font-bold">#<?= $o['id'] ?></td>
                                <td class="px-5 py-3.5">
                                    <div class="font-bold text-slate-900"><?= e($o['service_name']) ?></div>
                                    <div class="text-[11px] text-slate-400"><?= e($o['country_name']) ?> &bull; <?= e($o['server_name']) ?></div>
                                </td>
                                <td class="px-5 py-3.5 font-mono font-bold text-slate-900"><?= e($o['phone_number']) ?></td>
                                <td class="px-5 py-3.5 font-bold text-slate-900"><?= format_price($o['amount']) ?></td>
                                <td class="px-5 py-3.5">
                                    <?php if ($o['status'] === 'completed'): ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">Delivered</span>
                                    <?php elseif ($o['status'] === 'active'): ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200">Waiting for SMS</span>
                                    <?php elseif ($o['status'] === 'expired'): ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600 border border-slate-200">
                                            Expired <?= $o['is_refunded'] ? '(Refunded)' : '' ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200"><?= ucfirst($o['status']) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-3.5">
                                    <?php if (!empty($o['otp_code'])): ?>
                                        <span class="font-mono font-black text-blue-600 bg-blue-50 px-2 py-0.5 rounded border border-blue-200 text-xs tracking-wider">
                                            <?= e($o['otp_code']) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-slate-400 italic">None</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-3.5 text-slate-500 font-mono text-[11px]">
                                    <?= date('M d, H:i', strtotime($o['created_at'])) ?>
                                </td>
                                <td class="px-5 py-3.5 text-right">
                                    <a href="/user/order.php?id=<?= $o['id'] ?>" class="inline-flex items-center gap-1 px-3 py-1.5 rounded-lg bg-blue-50 text-blue-700 font-bold hover:bg-blue-100 transition-colors">
                                        <span>Terminal</span>
                                        <?= icon('arrow-right', 'w-3 h-3') ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="p-12 text-center text-slate-400 space-y-2">
                <div class="text-sm font-semibold text-slate-700">No matching orders found</div>
                <p class="text-xs">Try selecting a different filter or purchase a new virtual number.</p>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/../app/layouts/user_footer.php'; ?>
