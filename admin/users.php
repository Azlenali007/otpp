<?php
/**
 * NumVault - Administrator User Management & Wallet Adjustments
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$admin = require_admin();
$pageTitle = "Client Management & Wallets";

$pdo = get_db();
$error = '';

// Handle Balance Adjustment or Status Toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = $_POST['action'] ?? '';
    $userId = (int)($_POST['user_id'] ?? 0);

    if ($userId > 0) {
        if ($action === 'toggle_status') {
            $uRow = $pdo->query("SELECT status, username FROM users WHERE id = {$userId}")->fetch();
            if ($uRow) {
                $newStatus = ($uRow['status'] === 'active') ? 'blocked' : 'active';
                $upd = $pdo->prepare("UPDATE users SET status = ? WHERE id = ?");
                $upd->execute([$newStatus, $userId]);

                log_audit($admin['id'], 'admin_user_status_changed', "Changed {$uRow['username']} status to {$newStatus}");
                set_flash('success', "User {$uRow['username']} status set to {$newStatus}.");
            }
        } elseif ($action === 'adjust_balance') {
            $type = $_POST['adj_type'] ?? 'credit'; // credit or debit
            $amount = (float)($_POST['amount'] ?? 0);
            $reason = trim($_POST['reason'] ?? 'Administrative Adjustment');

            if ($amount <= 0) {
                $error = 'Please enter a positive adjustment amount.';
            } else {
                $pdo->beginTransaction();
                try {
                    $uStmt = $pdo->prepare("SELECT balance, username FROM users WHERE id = ? FOR UPDATE");
                    $uStmt->execute([$userId]);
                    $targetUser = $uStmt->fetch();

                    if (!$targetUser) {
                        throw new Exception("User not found.");
                    }

                    $balBefore = (float)$targetUser['balance'];
                    if ($type === 'debit' && $balBefore < $amount) {
                        throw new Exception("Cannot debit {$amount}. Current user balance is only {$balBefore}.");
                    }

                    $balAfter = ($type === 'credit') ? ($balBefore + $amount) : ($balBefore - $amount);

                    $upd = $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?");
                    $upd->execute([$balAfter, $userId]);

                    record_wallet_tx(
                        $pdo,
                        $userId,
                        $type === 'credit' ? 'credit' : 'debit',
                        $amount,
                        $balBefore,
                        $balAfter,
                        "Admin Adjustment: {$reason}",
                        "ADM-ADJ-" . time(),
                        null,
                        null
                    );

                    create_notification(
                        $userId,
                        "Wallet Balance Adjusted",
                        "Your wallet was {$type}ed by " . format_price($amount) . ". Note: {$reason}",
                        'wallet'
                    );

                    log_audit($admin['id'], 'admin_balance_adjustment', "{$type}ed {$amount} for user #{$userId} ({$targetUser['username']}). Reason: {$reason}");

                    $pdo->commit();
                    set_flash('success', "Balance for {$targetUser['username']} updated successfully.");
                } catch (Exception $e) {
                    $pdo->rollBack();
                    $error = 'Error adjusting balance: ' . $e->getMessage();
                }
            }
        }
        header('Location: /admin/users.php');
        exit;
    }
}

// Fetch users with search
$search = trim($_GET['search'] ?? '');
$query = "
    SELECT u.*, 
           COUNT(o.id) AS orders_count,
           SUM(CASE WHEN o.status = 'completed' THEN 1 ELSE 0 END) AS completed_count
    FROM users u
    LEFT JOIN orders o ON u.id = o.user_id
";
$params = [];

if (!empty($search)) {
    $query .= " WHERE (u.username LIKE ? OR u.email LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$query .= " GROUP BY u.id ORDER BY u.id DESC LIMIT 100";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$users = $stmt->fetchAll();

require_once __DIR__ . '/../app/layouts/admin_header.php';
?>

<div class="space-y-6">

    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-extrabold text-slate-900 tracking-tight">Registered Customers & Wallets</h1>
            <p class="text-xs text-slate-500 mt-0.5">Manage customer accounts, inspect ledger transactions, and adjust funds</p>
        </div>

        <form method="GET" action="/admin/users.php" class="flex gap-2">
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Search username or email..." class="text-xs px-3.5 py-2 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-500 outline-none w-64">
            <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-xs transition-colors">
                Search
            </button>
        </form>
    </div>

    <?php if ($error): ?>
        <div class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-medium">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-slate-50 text-slate-500 font-semibold border-b border-slate-100">
                    <tr>
                        <th class="px-5 py-3">ID</th>
                        <th class="px-5 py-3">User & Email</th>
                        <th class="px-5 py-3">Role</th>
                        <th class="px-5 py-3">Balance</th>
                        <th class="px-5 py-3">Orders</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Registered</th>
                        <th class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php foreach ($users as $u): ?>
                        <tr class="hover:bg-slate-50/50 transition-colors">
                            <td class="px-5 py-3.5 font-mono text-slate-400">#<?= $u['id'] ?></td>
                            <td class="px-5 py-3.5">
                                <div class="font-bold text-slate-900"><?= e($u['username']) ?></div>
                                <div class="text-[11px] text-slate-400 font-mono"><?= e($u['email']) ?></div>
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= $u['role'] === 'admin' ? 'bg-purple-50 text-purple-700 border border-purple-200' : 'bg-slate-100 text-slate-700' ?>">
                                    <?= strtoupper(e($u['role'])) ?>
                                </span>
                            </td>
                            <td class="px-5 py-3.5 font-mono font-bold text-slate-900 text-sm">
                                <?= format_price($u['balance']) ?>
                            </td>
                            <td class="px-5 py-3.5 font-mono text-slate-600">
                                <?= $u['orders_count'] ?> (<?= $u['completed_count'] ?> delivered)
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold <?= $u['status'] === 'active' ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-rose-50 text-rose-700 border border-rose-200' ?>">
                                    <?= strtoupper(e($u['status'])) ?>
                                </span>
                            </td>
                            <td class="px-5 py-3.5 font-mono text-slate-400 text-[11px]">
                                <?= date('M d, Y', strtotime($u['created_at'])) ?>
                            </td>
                            <td class="px-5 py-3.5 text-right space-x-2">
                                <!-- Balance Modal Trigger -->
                                <button type="button" onclick="openBalanceModal(<?= $u['id'] ?>, '<?= e($u['username']) ?>', '<?= format_price($u['balance']) ?>')" class="px-2.5 py-1.5 bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold rounded-lg transition-colors">
                                    Adjust $
                                </button>

                                <?php if ($u['role'] !== 'admin'): ?>
                                    <form method="POST" action="/admin/users.php" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
                                        <button type="submit" class="px-2.5 py-1.5 <?= $u['status'] === 'active' ? 'bg-rose-50 text-rose-700 hover:bg-rose-100' : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100' ?> font-bold rounded-lg transition-colors">
                                            <?= $u['status'] === 'active' ? 'Block' : 'Unblock' ?>
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Adjustment Modal (Plain JS) -->
<div id="balanceModal" class="hidden fixed inset-0 bg-slate-900/60 flex items-center justify-center p-4 z-50">
    <div class="bg-white rounded-2xl max-w-md w-full p-6 space-y-4 border border-slate-200 shadow-2xl">
        <div class="flex items-center justify-between pb-3 border-b border-slate-100">
            <h3 class="text-sm font-bold text-slate-900">Adjust Wallet: <span id="modalUsername"></span></h3>
            <button type="button" onclick="closeBalanceModal()" class="text-slate-400 hover:text-slate-600 font-bold">&times;</button>
        </div>

        <form method="POST" action="/admin/users.php" class="space-y-4">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="adjust_balance">
            <input type="hidden" name="user_id" id="modalUserId">

            <div class="text-xs text-slate-500">Current Balance: <span id="modalCurrentBal" class="font-mono font-bold text-slate-900"></span></div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Adjustment Type</label>
                <select name="adj_type" class="w-full text-xs px-3 py-2 rounded-lg border border-slate-300">
                    <option value="credit">Credit (Add funds to account)</option>
                    <option value="debit">Debit (Deduct funds from account)</option>
                </select>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Amount ($)</label>
                <input type="number" step="0.01" min="0.01" name="amount" required placeholder="0.00" class="w-full text-xs font-mono font-bold px-3 py-2 rounded-lg border border-slate-300">
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Audit Reason / Note</label>
                <input type="text" name="reason" required placeholder="e.g. Manual gateway wire clearance" class="w-full text-xs px-3 py-2 rounded-lg border border-slate-300">
            </div>

            <div class="pt-2 flex justify-end gap-2">
                <button type="button" onclick="closeBalanceModal()" class="px-4 py-2 bg-slate-100 text-slate-700 text-xs font-bold rounded-xl">Cancel</button>
                <button type="submit" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-xl shadow-xs">Execute & Record</button>
            </div>
        </form>
    </div>
</div>

<script>
    function openBalanceModal(id, username, bal) {
        document.getElementById('modalUserId').value = id;
        document.getElementById('modalUsername').innerText = username;
        document.getElementById('modalCurrentBal').innerText = bal;
        document.getElementById('balanceModal').classList.remove('hidden');
    }
    function closeBalanceModal() {
        document.getElementById('balanceModal').classList.add('hidden');
    }
</script>

<?php require_once __DIR__ . '/../app/layouts/admin_footer.php'; ?>
