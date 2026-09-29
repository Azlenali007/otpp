<?php
/**
 * NumVault - Payment Checkout & UTR Verification
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$user = require_login();
$paymentId = (int)($_GET['id'] ?? 0);

if ($paymentId <= 0) {
    header('Location: /user/wallet.php');
    exit;
}

$pdo = get_db();
$stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ? AND user_id = ? LIMIT 1");
$stmt->execute([$paymentId, $user['id']]);
$payment = $stmt->fetch();

if (!$payment) {
    set_flash('error', 'Payment invoice not found.');
    header('Location: /user/wallet.php');
    exit;
}

$error = '';

// Handle UTR submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $utr = trim($_POST['utr_reference'] ?? '');
    if (empty($utr) || strlen($utr) < 6) {
        $error = 'Please enter a valid 12-digit UPI UTR or bank transaction reference number.';
    } else {
        $respData = [
            'utr' => $utr,
            'submitted_at' => date('c'),
            'ip' => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'
        ];
        $upd = $pdo->prepare("UPDATE payments SET gateway_response = ? WHERE id = ?");
        $upd->execute([json_encode($respData), $paymentId]);

        log_audit($user['id'], 'payment_utr_submitted', "Submitted UTR {$utr} for invoice #{$paymentId}");
        set_flash('success', "Transaction reference {$utr} submitted! Your balance will be credited upon verification.");
        header('Location: /user/wallet.php');
        exit;
    }
}

$pageTitle = "Deposit Invoice #" . e($payment['transaction_ref']);
require_once __DIR__ . '/../app/layouts/user_header.php';
?>

<div class="max-w-xl mx-auto space-y-6">

    <div class="bg-white rounded-2xl border border-slate-200/80 p-8 shadow-xs space-y-6">
        <div class="text-center space-y-1">
            <span class="text-xs font-bold uppercase tracking-wider text-blue-600">Manual Payment Checkout</span>
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Complete Your Deposit</h1>
            <p class="text-xs text-slate-500">Invoice: <span class="font-mono font-bold text-slate-700"><?= e($payment['transaction_ref']) ?></span></p>
        </div>

        <div class="bg-slate-50 p-5 rounded-2xl border border-slate-200 text-center space-y-1">
            <div class="text-xs text-slate-500">Payable Amount</div>
            <div class="text-3xl font-black text-slate-900 font-mono">
                <?= format_price($payment['amount']) ?>
            </div>
            <div class="text-[11px] text-slate-400">Zero transfer fee &bull; 100% credited to wallet</div>
        </div>

        <!-- Bank & UPI Payment Details -->
        <div class="p-5 rounded-xl border border-blue-200 bg-blue-50/40 space-y-3 text-xs">
            <div class="font-bold text-blue-900 uppercase tracking-wider text-[11px]">Payment Destination:</div>
            
            <div class="space-y-1.5 font-medium text-slate-700">
                <div><strong class="text-slate-900">UPI ID / VPA:</strong> <span class="font-mono font-bold text-blue-700 select-all"><?= e(get_setting('upi_id', 'support@okhdfcbank')) ?></span></div>
                <div><strong class="text-slate-900">Bank Transfer:</strong> <span class="font-mono"><?= nl2br(e(get_setting('manual_bank_details', "Standard Chartered Bank\nAccount: 9001234567\nIFSC: SCBL0001"))) ?></span></div>
            </div>
        </div>

        <?php if ($error): ?>
            <div class="p-3.5 rounded-xl bg-rose-50 border border-rose-200 text-rose-700 text-xs font-medium flex items-center gap-2">
                <?= icon('alert-circle', 'w-4 h-4 flex-shrink-0') ?>
                <span><?= e($error) ?></span>
            </div>
        <?php endif; ?>

        <!-- UTR Form -->
        <form method="POST" action="/user/payment.php?id=<?= $paymentId ?>" class="space-y-4">
            <?= csrf_field() ?>

            <div>
                <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-1">
                    Enter Transaction Reference / UTR Number
                </label>
                <input type="text" name="utr_reference" required placeholder="e.g. 423871928371 or Wire Ref" class="w-full text-sm font-mono px-3.5 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-500 outline-none">
                <span class="text-[11px] text-slate-400 mt-1 block">Copy the 12-digit UTR from your bank or payment app receipt.</span>
            </div>

            <button type="submit" class="w-full py-3.5 px-4 bg-blue-600 hover:bg-blue-700 text-white font-bold text-sm rounded-xl shadow-md shadow-blue-600/20 transition-colors">
                Confirm & Submit Reference Number &rarr;
            </button>
        </form>

        <div class="text-center pt-2">
            <a href="/user/wallet.php" class="text-xs font-semibold text-slate-500 hover:text-slate-800">
                &larr; Return to Wallet
            </a>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../app/layouts/user_footer.php'; ?>
