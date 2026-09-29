<?php
/**
 * NumVault - Administrator Payment & Deposit Approvals
 * Verifies gateway payments and atomically credits customer wallets in database transactions
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Payments\PaymentGateway;

$admin = require_admin();
$pageTitle = "Deposits & Payment Gateway Verification";

$pdo = get_db();
$error = '';

// Handle Approve or Reject
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = $_POST['action'] ?? '';
    $paymentId = (int)($_POST['payment_id'] ?? 0);

    if ($paymentId > 0) {
        if ($action === 'approve') {
            $res = PaymentGateway::approvePayment((int)$admin['id'], $paymentId);
            if ($res['success']) {
                set_flash('success', $res['message']);
            } else {
                set_flash('error', $res['error']);
            }
            header('Location: /admin/payments.php');
            exit;
        } elseif ($action === 'reject') {
            $reason = trim($_POST['reject_reason'] ?? 'Verification failed / Invalid reference');
            $updPay = $pdo->prepare("UPDATE payments SET status = 'failed', gateway_response = ?, updated_at = NOW() WHERE id = ? AND status = 'pending'");
            $updPay->execute([json_encode(['rejected_by' => $admin['username'], 'reason' => $reason]), $paymentId]);

            log_audit($admin['id'], 'admin_payment_rejected', "Rejected payment #{$paymentId}. Reason: {$reason}");
            set_flash('success', "Payment #{$paymentId} rejected.");
            header('Location: /admin/payments.php');
            exit;
        }
    }
}

// Fetch payments
$payments = $pdo->query("
    SELECT p.*, u.username, u.email 
    FROM payments p
    JOIN users u ON p.user_id = u.id
    ORDER BY p.id DESC
    LIMIT 100
")->fetchAll();

require_once __DIR__ . '/../app/layouts/admin_header.php';
?>

<div class="space-y-6">

    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-extrabold text-slate-900 tracking-tight">Deposit Invoices & Verifications</h1>
            <p class="text-xs text-slate-500 mt-0.5">Inspect manual bank transfers / UTR numbers and approve wallet credits</p>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <?php if (!empty($payments)): ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-50 text-slate-500 font-semibold border-b border-slate-100">
                        <tr>
                            <th class="px-5 py-3">Invoice ID</th>
                            <th class="px-5 py-3">Customer</th>
                            <th class="px-5 py-3">Gateway</th>
                            <th class="px-5 py-3">Amount</th>
                            <th class="px-5 py-3">Reference / UTR</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3">Timestamp</th>
                            <th class="px-5 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium">
                        <?php foreach ($payments as $p): ?>
                            <?php 
                                $resp = json_decode((string)($p['gateway_response'] ?? ''), true);
                                $utr = $resp['utr'] ?? ($resp['reason'] ?? null);
                            ?>
                            <tr class="hover:bg-slate-50/50 transition-colors">
                                <td class="px-5 py-3.5 font-mono text-slate-900 font-bold">#<?= $p['id'] ?></td>
                                <td class="px-5 py-3.5">
                                    <div class="font-bold text-slate-900"><?= e($p['username']) ?></div>
                                    <div class="text-[11px] text-slate-400 font-mono"><?= e($p['email']) ?></div>
                                </td>
                                <td class="px-5 py-3.5">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-700 font-mono">
                                        <?= strtoupper(e($p['gateway'])) ?>
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 font-mono font-bold text-slate-900 text-sm">
                                    <?= format_price($p['amount']) ?>
                                </td>
                                <td class="px-5 py-3.5">
                                    <div class="font-mono text-[11px] font-bold text-slate-800"><?= e($p['transaction_ref']) ?></div>
                                    <?php if ($utr): ?>
                                        <div class="text-[11px] text-blue-700 font-mono font-bold mt-0.5">UTR: <?= e((string)$utr) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-3.5">
                                    <?php if ($p['status'] === 'completed'): ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">APPROVED</span>
                                    <?php elseif ($p['status'] === 'pending'): ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">PENDING REVIEW</span>
                                    <?php else: ?>
                                        <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-rose-50 text-rose-700 border border-rose-200"><?= strtoupper(e($p['status'])) ?></span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-3.5 font-mono text-slate-400 text-[11px]">
                                    <?= date('M d, H:i', strtotime($p['created_at'])) ?>
                                </td>
                                <td class="px-5 py-3.5 text-right space-x-1.5">
                                    <?php if ($p['status'] === 'pending'): ?>
                                        <form method="POST" action="/admin/payments.php" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="approve">
                                            <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                                            <button type="submit" class="px-2.5 py-1.5 bg-emerald-600 hover:bg-emerald-700 text-white font-bold rounded-lg text-xs transition-colors shadow-xs">
                                                Approve & Credit
                                            </button>
                                        </form>

                                        <form method="POST" action="/admin/payments.php" class="inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="action" value="reject">
                                            <input type="hidden" name="payment_id" value="<?= $p['id'] ?>">
                                            <button type="submit" class="px-2.5 py-1.5 bg-rose-50 text-rose-700 hover:bg-rose-100 font-bold rounded-lg text-xs transition-colors">
                                                Reject
                                            </button>
                                        </form>
                                    <?php else: ?>
                                        <span class="text-slate-400 text-[11px]">Archived</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="p-12 text-center text-slate-400 text-xs">
                No deposit records found.
            </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/../app/layouts/admin_footer.php'; ?>
