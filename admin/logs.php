<?php
/**
 * NumVault - Administrator Security & Audit Logs Console
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$admin = require_admin();
$pageTitle = "Security & Audit Logs";

$pdo = get_db();

$tab = $_GET['tab'] ?? 'audit';

// Fetch audit records from DB
$auditLogs = [];
if ($tab === 'audit') {
    $stmt = $pdo->query("
        SELECT a.*, u.username 
        FROM audit_logs a 
        LEFT JOIN users u ON a.user_id = u.id 
        ORDER BY a.id DESC 
        LIMIT 100
    ");
    $auditLogs = $stmt->fetchAll();
}

// Fetch file security logs
$securityLogContent = '';
if ($tab === 'security') {
    $secLogFile = STORAGE_PATH . '/logs/security.log';
    if (file_exists($secLogFile)) {
        $lines = file($secLogFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $recentLines = array_slice($lines ?: [], -100);
        $securityLogContent = implode("\n", array_reverse($recentLines));
    }
}

require_once __DIR__ . '/../app/layouts/admin_header.php';
?>

<div class="space-y-6">

    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-extrabold text-slate-900 tracking-tight">Security & Audit Logs</h1>
            <p class="text-xs text-slate-500 mt-0.5">Immutable audit trails of staff events, customer logins, and security alerts</p>
        </div>

        <div class="flex items-center gap-1.5 text-xs font-semibold">
            <a href="/admin/logs.php?tab=audit" class="px-3.5 py-1.5 rounded-lg transition-colors <?= $tab === 'audit' ? 'bg-blue-600 text-white shadow-xs' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
                Database Audit Trail
            </a>
            <a href="/admin/logs.php?tab=security" class="px-3.5 py-1.5 rounded-lg transition-colors <?= $tab === 'security' ? 'bg-blue-600 text-white shadow-xs' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
                Security Threat Log
            </a>
        </div>
    </div>

    <?php if ($tab === 'audit'): ?>
        <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-50 text-slate-500 font-semibold border-b border-slate-100">
                        <tr>
                            <th class="px-5 py-3">ID</th>
                            <th class="px-5 py-3">User</th>
                            <th class="px-5 py-3">Action</th>
                            <th class="px-5 py-3">Details</th>
                            <th class="px-5 py-3">IP Address</th>
                            <th class="px-5 py-3 text-right">Timestamp</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium">
                        <?php foreach ($auditLogs as $l): ?>
                            <tr class="hover:bg-slate-50/50 transition-colors">
                                <td class="px-5 py-3 font-mono text-slate-400">#<?= $l['id'] ?></td>
                                <td class="px-5 py-3 font-bold <?= $l['username'] ? 'text-blue-600' : 'text-slate-400' ?>">
                                    <?= e($l['username'] ?? 'System') ?>
                                </td>
                                <td class="px-5 py-3 font-mono font-bold text-slate-800">
                                    <?= e($l['action']) ?>
                                </td>
                                <td class="px-5 py-3 text-slate-600 max-w-md truncate">
                                    <?= e($l['details']) ?>
                                </td>
                                <td class="px-5 py-3 font-mono text-slate-500 text-[11px]"><?= e($l['ip_address'] ?? '127.0.0.1') ?></td>
                                <td class="px-5 py-3 font-mono text-slate-400 text-[11px] text-right">
                                    <?= date('M d, Y H:i:s', strtotime($l['created_at'])) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php else: ?>
        <div class="bg-slate-900 rounded-2xl p-6 shadow-xl border border-slate-800">
            <div class="flex items-center justify-between pb-3 border-b border-slate-800 mb-3 text-xs">
                <span class="font-mono text-slate-400">storage/logs/security.log (Last 100 Entries)</span>
                <span class="text-emerald-400 font-mono text-[11px]">Real-time Event Stream</span>
            </div>
            <pre class="font-mono text-xs text-slate-300 whitespace-pre-wrap leading-relaxed max-h-[500px] overflow-y-auto"><?= e($securityLogContent ?: 'No security violations or rate limit events recorded.') ?></pre>
        </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../app/layouts/admin_footer.php'; ?>
