<?php
/**
 * NumVault - Customer Transaction Ledger
 * Full immutable ledger of deposits, number purchases, and auto-refunds
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$user = require_login();
$pageTitle = "Transaction Ledger";

$pdo = get_db();

$type = trim($_GET['type'] ?? '');
$query = "SELECT * FROM wallet_transactions WHERE user_id = ?";
$params = [$user['id']];

if (!empty($type)) {
    $query .= " AND type = ?";
    $params[] = $type;
}

$query .= " ORDER BY id DESC LIMIT 100";
$stmt = $pdo->prepare($query);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

require_once __DIR__ . '/../app/layouts/user_header.php';
?>

<div class="space-y-6">

    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-extrabold text-slate-900 tracking-tight">Wallet Ledger</h1>
            <p class="text-xs text-slate-500 mt-0.5">Immutable record of every balance change, purchase, and refund</p>
        </div>

        <div class="flex items-center gap-1.5 text-xs font-semibold">
            <a href="/user/transactions.php" class="px-3 py-1.5 rounded-lg transition-colors <?= empty($type) ? 'bg-blue-600 text-white shadow-xs' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
                All Ledger
            </a>
            <a href="/user/transactions.php?type=credit" class="px-3 py-1.5 rounded-lg transition-colors <?= $type === 'credit' ? 'bg-blue-600 text-white shadow-xs' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
                Credits
            </a>
            <a href="/user/transactions.php?type=debit" class="px-3 py-1.5 rounded-lg transition-colors <?= $type === 'debit' ? 'bg-blue-600 text-white shadow-xs' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
                Debits
            </a>
            <a href="/user/transactions.php?type=refund" class="px-3 py-1.5 rounded-lg transition-colors <?= $type === 'refund' ? 'bg-blue-600 text-white shadow-xs' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
                Refunds
            </a>
        </div>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <?php if (!empty($transactions)): ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-50/80 text-slate-500 font-semibold border-b border-slate-100">
                        <tr>
                            <th class="px-5 py-3.5">ID</th>
                            <th class="px-5 py-3.5">Type</th>
                            <th class="px-5 py-3.5">Description</th>
                            <th class="px-5 py-3.5">Reference</th>
                            <th class="px-5 py-3.5">Amount</th>
                            <th class="px-5 py-3.5">Balance Before</th>
                            <th class="px-5 py-3.5">Balance After</th>
                            <th class="px-5 py-3.5 text-right">Timestamp</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium">
                        <?php foreach ($transactions as $tx): ?>
                            <tr class="hover:bg-slate-50/50 transition-colors">
                                <td class="px-5 py-3.5 font-mono text-slate-400">#<?= $tx['id'] ?></td>
                                <td class="px-5 py-3.5">
                                    <?php if ($tx['type'] === 'credit'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">Credit</span>
                                    <?php elseif ($tx['type'] === 'refund'): ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200">Auto Refund</span>
                                    <?php else: ?>
                                        <span class="inline-flex items-center px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-700 border border-slate-200">Debit</span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-3.5 font-bold text-slate-900"><?= e($tx['description']) ?></td>
                                <td class="px-5 py-3.5 font-mono text-[11px] text-slate-500"><?= e($tx['reference_id'] ?? '-') ?></td>
                                <td class="px-5 py-3.5 font-mono font-bold <?= $tx['type'] === 'credit' ? 'text-emerald-600' : ($tx['type'] === 'refund' ? 'text-blue-600' : 'text-slate-900') ?>">
                                    <?= $tx['type'] === 'debit' ? '-' : '+' ?><?= format_price($tx['amount']) ?>
                                </td>
                                <td class="px-5 py-3.5 font-mono text-slate-400"><?= format_price($tx['balance_before']) ?></td>
                                <td class="px-5 py-3.5 font-mono font-bold text-slate-900"><?= format_price($tx['balance_after']) ?></td>
                                <td class="px-5 py-3.5 font-mono text-[11px] text-slate-400 text-right">
                                    <?= date('M d, H:i:s', strtotime($tx['created_at'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="p-12 text-center text-slate-400 text-xs">
                No ledger transactions found matching the filter criteria.
            </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/../app/layouts/user_footer.php'; ?>
