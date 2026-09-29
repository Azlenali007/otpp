<?php
/**
 * NumVault - Administrator Refund Management & Audit Console
 * Tracks all automated and manual order refunds, ledger credits, and dispute resolutions
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Refunds\RefundService;
use App\Core\Logger;

$admin = require_admin();
$pageTitle = "Refunds & Ledger Audit";

$pdo = get_db();
$error = '';

// Handle Manual Refund Action
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = $_POST['action'] ?? '';
    if ($action === 'issue_refund') {
        $orderId = (int)($_POST['order_id'] ?? 0);
        $reason = trim($_POST['refund_reason'] ?? 'Admin manual refund resolution');

        if ($orderId <= 0) {
            $error = 'Valid Order ID is required.';
        } else {
            $res = RefundService::processOrderRefund($orderId, $reason, (int)$admin['id']);
            if ($res['success']) {
                set_flash('success', "Order #{$orderId} has been successfully refunded (\${$res['amount']}) to customer wallet.");
                header('Location: /admin/refunds.php');
                exit;
            } else {
                $error = $res['error'] ?? 'Failed to process refund.';
            }
        }
    }
}

// Fetch Refund Statistics
$stats = RefundService::getStats();

// Search and Pagination
$search = trim($_GET['search'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$offset = ($page - 1) * $perPage;

$whereClause = "WHERE (o.is_refunded = 1 OR o.status = 'cancelled')";
$params = [];

if (!empty($search)) {
    $whereClause .= " AND (o.id = ? OR u.username LIKE ? OR o.phone_number LIKE ? OR s.name LIKE ?)";
    $params[] = is_numeric($search) ? (int)$search : 0;
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

// Count total
$countStmt = $pdo->prepare("
    SELECT COUNT(o.id)
    FROM orders o
    JOIN users u ON u.id = o.user_id
    JOIN services s ON s.id = o.service_id
    {$whereClause}
");
$countStmt->execute($params);
$totalRecords = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRecords / $perPage));

// Query refunded orders
$stmt = $pdo->prepare("
    SELECT o.*, u.username, u.email AS user_email, s.name AS service_name, srv.server_name,
           wt.reference_id AS transaction_ref, wt.description AS refund_desc
    FROM orders o
    JOIN users u ON u.id = o.user_id
    JOIN services s ON s.id = o.service_id
    LEFT JOIN servers srv ON srv.id = o.server_id
    LEFT JOIN wallet_transactions wt ON wt.order_id = o.id AND wt.type = 'refund'
    {$whereClause}
    ORDER BY o.updated_at DESC
    LIMIT ? OFFSET ?
");
$bindIdx = 1;
foreach ($params as $p) {
    $stmt->bindValue($bindIdx++, $p);
}
$stmt->bindValue($bindIdx++, $perPage, PDO::PARAM_INT);
$stmt->bindValue($bindIdx++, $offset, PDO::PARAM_INT);
$stmt->execute();
$refunds = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once APP_ROOT . '/app/layouts/admin_header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight">Refunds & Ledger Audit</h1>
            <p class="text-xs text-slate-500 mt-1">Real-time inspection of auto-refunded orders, ledger credits, and manual adjustments.</p>
        </div>
        <div class="flex items-center gap-2">
            <button onclick="document.getElementById('manualRefundModal').classList.remove('hidden')" class="px-4 py-2.5 bg-rose-600 hover:bg-rose-700 text-white rounded-xl text-xs font-bold shadow-sm transition-colors flex items-center gap-2">
                <?= icon('rotate-ccw', 'w-4 h-4') ?>
                Issue Manual Refund
            </button>
        </div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="p-4 bg-rose-50 border border-rose-200 text-rose-800 rounded-xl text-xs font-medium flex items-center gap-2">
            <?= icon('alert-circle', 'w-4 h-4 flex-shrink-0') ?>
            <span><?= e($error) ?></span>
        </div>
    <?php endif; ?>

    <!-- Summary Metrics -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs">
            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Total Refunded Orders</div>
            <div class="text-2xl font-black text-slate-900 mt-1"><?= number_format($stats['total_count']) ?></div>
            <div class="text-[11px] text-slate-500 mt-1">Lifetime automatic & manual</div>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs">
            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Total Volume Credited</div>
            <div class="text-2xl font-black text-rose-600 mt-1">$<?= number_format($stats['total_amount'], 2) ?></div>
            <div class="text-[11px] text-slate-500 mt-1">Re-credited back to wallets</div>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs">
            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Refunds Today</div>
            <div class="text-2xl font-black text-slate-900 mt-1"><?= number_format($stats['today_count']) ?></div>
            <div class="text-[11px] text-slate-500 mt-1">Processed in past 24 hours</div>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs">
            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Amount Refunded Today</div>
            <div class="text-2xl font-black text-emerald-600 mt-1">$<?= number_format($stats['today_amount'], 2) ?></div>
            <div class="text-[11px] text-slate-500 mt-1">Guaranteed customer balance protection</div>
        </div>
    </div>

    <!-- Search & Filter Bar -->
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs flex flex-col sm:flex-row items-center justify-between gap-4">
        <form method="GET" action="/admin/refunds.php" class="flex-1 w-full flex items-center gap-2">
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search by Order ID, username, phone number..." class="w-full px-3.5 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:outline-hidden focus:ring-2 focus:ring-blue-500">
            <button type="submit" class="px-4 py-2 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-xs font-bold transition-colors">
                Search
            </button>
            <?php if (!empty($search)): ?>
                <a href="/admin/refunds.php" class="px-3 py-2 text-xs text-slate-500 hover:text-slate-800 font-medium">Clear</a>
            <?php endif; ?>
        </form>
        <div class="text-xs text-slate-500 font-medium whitespace-nowrap">
            Showing <?= count($refunds) ?> of <?= $totalRecords ?> records
        </div>
    </div>

    <!-- Refund Records Table -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-slate-500 font-bold uppercase tracking-wider text-[10px]">
                        <th class="py-3.5 px-4">Order ID</th>
                        <th class="py-3.5 px-4">Customer</th>
                        <th class="py-3.5 px-4">Service & Server</th>
                        <th class="py-3.5 px-4">Phone Number</th>
                        <th class="py-3.5 px-4">Amount Credited</th>
                        <th class="py-3.5 px-4">Ledger Ref / Reason</th>
                        <th class="py-3.5 px-4">Refunded Date</th>
                        <th class="py-3.5 px-4 text-right">Order Link</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php if (empty($refunds)): ?>
                        <tr>
                            <td colspan="8" class="py-12 text-center text-slate-400">
                                <div class="w-10 h-10 rounded-full bg-slate-100 flex items-center justify-center mx-auto mb-2 text-slate-400">
                                    <?= icon('rotate-ccw', 'w-5 h-5') ?>
                                </div>
                                No refunded orders found.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($refunds as $ref): 
                            $refAmount = (float)($ref['refund_amount'] > 0 ? $ref['refund_amount'] : $ref['amount']);
                        ?>
                            <tr class="hover:bg-slate-50/70 transition-colors">
                                <td class="py-3.5 px-4 font-mono font-bold text-slate-800">
                                    #<?= e((string)$ref['id']) ?>
                                </td>
                                <td class="py-3.5 px-4">
                                    <div class="font-bold text-slate-900"><?= e($ref['username']) ?></div>
                                    <div class="text-[10px] text-slate-400 font-mono"><?= e($ref['user_email']) ?></div>
                                </td>
                                <td class="py-3.5 px-4">
                                    <div class="font-bold text-slate-800"><?= e($ref['service_name']) ?></div>
                                    <div class="text-[10px] text-slate-400"><?= e($ref['server_name'] ?? 'Route #' . $ref['server_id']) ?></div>
                                </td>
                                <td class="py-3.5 px-4 font-mono text-slate-700">
                                    <?= e($ref['phone_number'] ?: 'Not Assigned') ?>
                                </td>
                                <td class="py-3.5 px-4">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold bg-rose-50 text-rose-700 border border-rose-200">
                                        +$<?= number_format($refAmount, 2) ?>
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 max-w-xs truncate">
                                    <div class="font-mono text-[10px] text-slate-600 truncate"><?= e($ref['transaction_ref'] ?? 'System Auto-Refund') ?></div>
                                    <div class="text-[10px] text-slate-400 truncate"><?= e($ref['refund_reason'] ?? $ref['refund_desc'] ?? 'Order timeout / no OTP') ?></div>
                                </td>
                                <td class="py-3.5 px-4 text-slate-500 whitespace-nowrap">
                                    <?= date('M d, Y H:i', strtotime($ref['updated_at'])) ?>
                                </td>
                                <td class="py-3.5 px-4 text-right">
                                    <a href="/admin/orders.php?search=<?= e((string)$ref['id']) ?>" class="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-lg text-[11px] font-bold transition-colors">
                                        View Order
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($totalPages > 1): ?>
            <div class="p-4 bg-slate-50 border-t border-slate-200 flex items-center justify-between text-xs">
                <div class="text-slate-500">Page <?= $page ?> of <?= $totalPages ?></div>
                <div class="flex gap-1">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>" class="px-3 py-1.5 bg-white border border-slate-200 rounded-lg text-slate-700 font-bold hover:bg-slate-50">Previous</a>
                    <?php endif; ?>
                    <?php if ($page < $totalPages): ?>
                        <a href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>" class="px-3 py-1.5 bg-white border border-slate-200 rounded-lg text-slate-700 font-bold hover:bg-slate-50">Next</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Manual Refund Modal -->
<div id="manualRefundModal" class="hidden fixed inset-0 bg-slate-900/60 backdrop-blur-xs flex items-center justify-center p-4 z-50">
    <div class="max-w-md w-full bg-white rounded-2xl p-6 shadow-xl border border-slate-200 space-y-4">
        <div class="flex items-center justify-between">
            <h3 class="text-base font-bold text-slate-900">Issue Manual Order Refund</h3>
            <button onclick="document.getElementById('manualRefundModal').classList.add('hidden')" class="text-slate-400 hover:text-slate-600 text-lg font-bold">&times;</button>
        </div>
        <p class="text-xs text-slate-500">
            This will immediately credit the customer's wallet balance with the order's purchase amount and set the order status to <span class="font-bold text-rose-600">cancelled / refunded</span>.
        </p>

        <form method="POST" action="/admin/refunds.php" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="issue_refund">

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Target Order ID *</label>
                <input type="number" name="order_id" required min="1" placeholder="e.g. 1042" class="w-full px-3.5 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-rose-500">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-700 mb-1">Reason for Refund *</label>
                <input type="text" name="refund_reason" required value="Customer requested cancellation / carrier issue" class="w-full px-3.5 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-rose-500">
            </div>

            <div class="flex items-center justify-end gap-3 pt-2">
                <button type="button" onclick="document.getElementById('manualRefundModal').classList.add('hidden')" class="px-4 py-2 text-xs font-bold text-slate-600 hover:bg-slate-100 rounded-xl">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-rose-600 hover:bg-rose-700 text-white text-xs font-bold rounded-xl shadow-xs transition-colors">Confirm & Process Refund</button>
            </div>
        </form>
    </div>
</div>

<?php require_once APP_ROOT . '/app/layouts/admin_footer.php'; ?>
