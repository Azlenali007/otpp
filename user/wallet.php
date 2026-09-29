<?php
/**
 * NumVault - Customer Wallet & Transaction Ledger
 * Real-time balance, deposit gateways, and immutable transaction ledger
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Payments\PaymentGateway;

$user = require_login();
$pageTitle = "My Wallet & Deposits";

$pdo = get_db();
$error = '';

// Handle Deposit Form POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $amount  = (float)($_POST['amount'] ?? 0);
    $gateway = trim($_POST['gateway'] ?? 'upi_manual');

    $res = PaymentGateway::createPayment($user['id'], $gateway, $amount);
    if ($res['success']) {
        header("Location: {$res['redirect_url']}");
        exit;
    } else {
        $error = $res['error'] ?? 'Failed to initialize payment.';
    }
}

// Fetch user wallet transaction ledger (Credits, Debits, Auto Refunds)
$txStmt = $pdo->prepare("
    SELECT * FROM wallet_transactions 
    WHERE user_id = ? 
    ORDER BY id DESC 
    LIMIT 20
");
$txStmt->execute([$user['id']]);
$transactions = $txStmt->fetchAll();

// Refresh current balance from DB
$uStmt = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
$uStmt->execute([$user['id']]);
$currentBalance = (float)$uStmt->fetchColumn();

require_once __DIR__ . '/../app/layouts/user_header.php';
?>

<div class="space-y-8">

    <!-- Wallet Header Banner -->
    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 sm:p-8 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-6">
        <div>
            <span class="text-xs font-bold uppercase tracking-wider text-blue-600">Secure Account Balance</span>
            <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight mt-0.5">Wallet & Deposits</h1>
            <p class="text-xs sm:text-sm text-slate-500 mt-1">
                Real-time MySQL wallet balance. All orders and refunds are executed with ACID database transactions.
            </p>
        </div>

        <div class="flex items-center gap-4 bg-slate-50 p-4 rounded-2xl border border-slate-200">
            <div class="w-12 h-12 rounded-xl bg-blue-600 text-white flex items-center justify-center shadow-md shadow-blue-600/20">
                <?= icon('wallet', 'w-6 h-6') ?>
            </div>
            <div>
                <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Available Funds</div>
                <div class="text-2xl sm:text-3xl font-black text-slate-900 font-mono">
                    <?= format_price($currentBalance) ?>
                </div>
            </div>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-medium flex items-center gap-2.5">
            <?= icon('alert-circle', 'w-5 h-5 flex-shrink-0') ?>
            <span><?= e($error) ?></span>
        </div>
    <?php endif; ?>

    <!-- Deposit Options Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">

        <!-- Left: Deposit Gateway Form -->
        <div class="lg:col-span-6 space-y-6">
            <div class="bg-white rounded-2xl border border-slate-200/80 p-6 sm:p-8 shadow-xs space-y-6">
                <div>
                    <h2 class="text-lg font-bold text-slate-900">Add Funds to Wallet</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Choose your preferred deposit channel and enter deposit amount</p>
                </div>

                <form method="POST" action="/user/wallet.php" class="space-y-5">
                    <?= csrf_field() ?>

                    <!-- Amount Selection -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">
                            Deposit Amount (<?= e(get_setting('currency_symbol', '$')) ?>)
                        </label>
                        <div class="relative">
                            <input type="number" step="0.01" min="1.00" name="amount" id="depositAmountInput" value="10.00" required class="w-full text-base font-bold font-mono px-4 py-3 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-500 outline-none">
                        </div>
                        <div class="flex items-center gap-2 mt-2">
                            <button type="button" onclick="document.getElementById('depositAmountInput').value = '5.00'" class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-lg transition-colors">$5.00</button>
                            <button type="button" onclick="document.getElementById('depositAmountInput').value = '10.00'" class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-lg transition-colors">$10.00</button>
                            <button type="button" onclick="document.getElementById('depositAmountInput').value = '25.00'" class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-lg transition-colors">$25.00</button>
                            <button type="button" onclick="document.getElementById('depositAmountInput').value = '50.00'" class="px-2.5 py-1 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-lg transition-colors">$50.00</button>
                        </div>
                    </div>

                    <!-- Payment Gateway Selector -->
                    <div>
                        <label class="block text-xs font-bold text-slate-700 uppercase tracking-wider mb-2">
                            Payment Method
                        </label>
                        <div class="space-y-2.5">
                            <label class="flex items-center justify-between p-3.5 rounded-xl border border-slate-200 hover:border-blue-400 cursor-pointer transition-colors has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50/40">
                                <div class="flex items-center gap-3">
                                    <input type="radio" name="gateway" value="upi_manual" checked class="w-4 h-4 text-blue-600 focus:ring-blue-500">
                                    <div>
                                        <div class="text-xs font-bold text-slate-900">UPI QR / Bank Transfer (Instant Verification)</div>
                                        <div class="text-[11px] text-slate-400">Zero fee &bull; Pay via GPay, PhonePe, Paytm, or Wire</div>
                                    </div>
                                </div>
                            </label>

                            <label class="flex items-center justify-between p-3.5 rounded-xl border border-slate-200 hover:border-blue-400 cursor-pointer transition-colors has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50/40">
                                <div class="flex items-center gap-3">
                                    <input type="radio" name="gateway" value="cryptomus" class="w-4 h-4 text-blue-600 focus:ring-blue-500">
                                    <div>
                                        <div class="text-xs font-bold text-slate-900">Cryptomus (Cryptocurrency Gateway)</div>
                                        <div class="text-[11px] text-slate-400">USDT, BTC, ETH, LTC &bull; Automated webhook credit</div>
                                    </div>
                                </div>
                            </label>

                            <label class="flex items-center justify-between p-3.5 rounded-xl border border-slate-200 hover:border-blue-400 cursor-pointer transition-colors has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50/40">
                                <div class="flex items-center gap-3">
                                    <input type="radio" name="gateway" value="stripe" class="w-4 h-4 text-blue-600 focus:ring-blue-500">
                                    <div>
                                        <div class="text-xs font-bold text-slate-900">Credit / Debit Card (Stripe Checkout)</div>
                                        <div class="text-[11px] text-slate-400">Visa, Mastercard, American Express</div>
                                    </div>
                                </div>
                            </label>
                        </div>
                    </div>

                    <button type="submit" class="w-full py-3.5 px-4 bg-blue-600 hover:bg-blue-700 text-white font-bold text-sm rounded-xl shadow-md shadow-blue-600/20 transition-colors flex items-center justify-center gap-2">
                        <?= icon('plus', 'w-4 h-4') ?>
                        <span>Proceed to Deposit &rarr;</span>
                    </button>
                </form>
            </div>
        </div>

        <!-- Right: Recent Transactions Preview -->
        <div class="lg:col-span-6 space-y-6">
            <div class="bg-white rounded-2xl border border-slate-200/80 p-6 sm:p-8 shadow-xs space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-bold text-slate-900">Recent Transactions</h2>
                        <p class="text-xs text-slate-500 mt-0.5">Immutable audit record of credits, debits & auto-refunds</p>
                    </div>
                    <a href="/user/transactions.php" class="text-xs font-bold text-blue-600 hover:text-blue-700">Full Ledger &rarr;</a>
                </div>

                <?php if (!empty($transactions)): ?>
                    <div class="divide-y divide-slate-100">
                        <?php foreach ($transactions as $tx): ?>
                            <div class="py-3 flex items-center justify-between text-xs">
                                <div>
                                    <div class="font-bold text-slate-800"><?= e($tx['description']) ?></div>
                                    <div class="text-[11px] text-slate-400 font-mono">
                                        <?= date('M d, H:i', strtotime($tx['created_at'])) ?> &bull; Ref: <?= e($tx['reference_id'] ?? '-') ?>
                                    </div>
                                </div>
                                <div class="text-right">
                                    <div class="font-mono font-bold <?= $tx['type'] === 'credit' ? 'text-emerald-600' : ($tx['type'] === 'refund' ? 'text-blue-600' : 'text-slate-900') ?>">
                                        <?= $tx['type'] === 'debit' ? '-' : '+' ?><?= format_price($tx['amount']) ?>
                                    </div>
                                    <div class="text-[10px] text-slate-400 font-mono">
                                        Bal: <?= format_price($tx['balance_after']) ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="py-12 text-center text-slate-400 text-xs">
                        No transactions recorded yet in your account.
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

</div>

<?php require_once __DIR__ . '/../app/layouts/user_footer.php'; ?>
