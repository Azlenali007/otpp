<?php
/**
 * NumVault - Administrator Support Ticket Response Desk
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$admin = require_admin();
$pageTitle = "Support Help Desk Management";

$pdo = get_db();
$error = '';

// Handle Reply or Status Change
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = $_POST['action'] ?? '';
    $ticketId = (int)($_POST['ticket_id'] ?? 0);

    if ($ticketId > 0) {
        if ($action === 'reply') {
            $replyMsg = trim($_POST['message'] ?? '');
            $newStatus = $_POST['status'] ?? 'answered';

            if (!empty($replyMsg)) {
                $ins = $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, is_admin, message) VALUES (?, ?, 1, ?)");
                $ins->execute([$ticketId, $admin['id'], $replyMsg]);

                $upd = $pdo->prepare("UPDATE tickets SET status = ?, updated_at = NOW() WHERE id = ?");
                $upd->execute([$newStatus, $ticketId]);

                // Notify customer
                $tRow = $pdo->query("SELECT user_id, subject FROM tickets WHERE id = {$ticketId}")->fetch();
                if ($tRow) {
                    create_notification(
                        (int)$tRow['user_id'],
                        "Support Staff Replied to Ticket #{$ticketId}",
                        "Our team replied to '{$tRow['subject']}'. Click to review the answer.",
                        'system'
                    );
                }

                set_flash('success', "Reply sent and ticket updated to {$newStatus}.");
            }
        } elseif ($action === 'change_status') {
            $status = $_POST['status'] ?? 'open';
            $upd = $pdo->prepare("UPDATE tickets SET status = ?, updated_at = NOW() WHERE id = ?");
            $upd->execute([$status, $ticketId]);
            set_flash('success', "Ticket status changed to {$status}.");
        }
        header("Location: /admin/tickets.php?view={$ticketId}");
        exit;
    }
}

// Fetch tickets
$tickets = $pdo->query("
    SELECT t.*, u.username, u.email, COUNT(r.id) AS reply_count 
    FROM tickets t
    JOIN users u ON t.user_id = u.id
    LEFT JOIN ticket_replies r ON t.id = r.ticket_id
    GROUP BY t.id
    ORDER BY CASE WHEN t.status = 'open' THEN 1 WHEN t.status = 'answered' THEN 2 ELSE 3 END, t.updated_at DESC
")->fetchAll();

// View specific ticket thread
$activeTicket = null;
$ticketReplies = [];
if (isset($_GET['view'])) {
    $vId = (int)$_GET['view'];
    $stmt = $pdo->prepare("
        SELECT t.*, u.username, u.email 
        FROM tickets t 
        JOIN users u ON t.user_id = u.id 
        WHERE t.id = ? 
        LIMIT 1
    ");
    $stmt->execute([$vId]);
    $activeTicket = $stmt->fetch();

    if ($activeTicket) {
        $rStmt = $pdo->prepare("
            SELECT r.*, u.username 
            FROM ticket_replies r 
            JOIN users u ON r.user_id = u.id 
            WHERE r.ticket_id = ? 
            ORDER BY r.id ASC
        ");
        $rStmt->execute([$vId]);
        $ticketReplies = $rStmt->fetchAll();
    }
}

require_once __DIR__ . '/../app/layouts/admin_header.php';
?>

<div class="space-y-6">

    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-extrabold text-slate-900 tracking-tight">Support Ticket Desk</h1>
            <p class="text-xs text-slate-500 mt-0.5">Respond to customer technical questions and route inquiries</p>
        </div>
    </div>

    <!-- Active Ticket Modal / Discussion Viewer -->
    <?php if ($activeTicket): ?>
        <div class="bg-white rounded-2xl border border-blue-300 shadow-md p-6 space-y-6">
            <div class="flex items-center justify-between pb-4 border-b border-slate-100">
                <div>
                    <div class="flex items-center gap-2">
                        <span class="font-mono font-bold text-xs text-slate-400">#<?= $activeTicket['id'] ?></span>
                        <h2 class="text-lg font-bold text-slate-900"><?= e($activeTicket['subject']) ?></h2>
                    </div>
                    <div class="text-xs text-slate-500 mt-0.5">
                        Client: <strong class="text-slate-800"><?= e($activeTicket['username']) ?></strong> (<?= e($activeTicket['email']) ?>) &bull; Priority: <?= ucfirst(e($activeTicket['priority'])) ?>
                    </div>
                </div>
                <div class="flex items-center gap-2">
                    <form method="POST" action="/admin/tickets.php" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="change_status">
                        <input type="hidden" name="ticket_id" value="<?= $activeTicket['id'] ?>">
                        <select name="status" onchange="this.form.submit()" class="text-xs px-2.5 py-1.5 border rounded-lg font-bold bg-slate-50">
                            <option value="open" <?= $activeTicket['status'] === 'open' ? 'selected' : '' ?>>Open</option>
                            <option value="answered" <?= $activeTicket['status'] === 'answered' ? 'selected' : '' ?>>Answered</option>
                            <option value="resolved" <?= $activeTicket['status'] === 'resolved' ? 'selected' : '' ?>>Resolved</option>
                            <option value="closed" <?= $activeTicket['status'] === 'closed' ? 'selected' : '' ?>>Closed</option>
                        </select>
                    </form>
                    <a href="/admin/tickets.php" class="text-xs text-slate-400 hover:text-slate-600 font-bold">&times; Close</a>
                </div>
            </div>

            <!-- Thread Conversation -->
            <div class="space-y-4 max-h-96 overflow-y-auto pr-1">
                <?php foreach ($ticketReplies as $r): ?>
                    <div class="p-4 rounded-xl border <?= $r['is_admin'] ? 'bg-blue-50/50 border-blue-200' : 'bg-slate-50 border-slate-200' ?>">
                        <div class="flex items-center justify-between pb-2 mb-2 border-b border-slate-200/60 text-xs">
                            <span class="font-bold text-slate-900">
                                <?= e($r['username']) ?> <?= $r['is_admin'] ? '<span class="text-blue-600">(Admin Staff)</span>' : '' ?>
                            </span>
                            <span class="font-mono text-[11px] text-slate-400"><?= date('M d, H:i', strtotime($r['created_at'])) ?></span>
                        </div>
                        <div class="text-xs text-slate-700 leading-relaxed whitespace-pre-wrap"><?= e($r['message']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Reply Form -->
            <form method="POST" action="/admin/tickets.php" class="space-y-3 pt-3 border-t border-slate-100">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="reply">
                <input type="hidden" name="ticket_id" value="<?= $activeTicket['id'] ?>">

                <textarea name="message" rows="3" required placeholder="Type staff answer to client..." class="w-full text-xs px-3.5 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-500 outline-none"></textarea>

                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-2 text-xs">
                        <span class="text-slate-600 font-semibold">Set Status:</span>
                        <select name="status" class="px-2 py-1 border rounded-lg text-xs font-bold">
                            <option value="answered" selected>Answered (Waiting on user)</option>
                            <option value="resolved">Resolved</option>
                            <option value="closed">Closed</option>
                        </select>
                    </div>
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-xl shadow-xs">
                        Send Official Reply &rarr;
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <!-- Tickets List Table -->
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-slate-50 text-slate-500 font-semibold border-b border-slate-100">
                    <tr>
                        <th class="px-5 py-3">ID</th>
                        <th class="px-5 py-3">Subject</th>
                        <th class="px-5 py-3">Customer</th>
                        <th class="px-5 py-3">Priority</th>
                        <th class="px-5 py-3">Replies</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3">Last Active</th>
                        <th class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php foreach ($tickets as $t): ?>
                        <tr class="hover:bg-slate-50/50 transition-colors">
                            <td class="px-5 py-3.5 font-mono text-slate-400 font-bold">#<?= $t['id'] ?></td>
                            <td class="px-5 py-3.5 font-bold text-slate-900"><?= e($t['subject']) ?></td>
                            <td class="px-5 py-3.5 text-blue-600 font-bold"><?= e($t['username']) ?></td>
                            <td class="px-5 py-3.5">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= $t['priority'] === 'high' ? 'bg-rose-50 text-rose-700' : 'bg-slate-100 text-slate-700' ?>">
                                    <?= strtoupper(e($t['priority'])) ?>
                                </span>
                            </td>
                            <td class="px-5 py-3.5 font-mono text-slate-500"><?= (int)$t['reply_count'] ?></td>
                            <td class="px-5 py-3.5">
                                <?php if ($t['status'] === 'open'): ?>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">Awaiting Reply</span>
                                <?php elseif ($t['status'] === 'answered'): ?>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200">Staff Answered</span>
                                <?php elseif ($t['status'] === 'resolved'): ?>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">Resolved</span>
                                <?php else: ?>
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-slate-100 text-slate-600">Closed</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-3.5 font-mono text-slate-400 text-[11px]">
                                <?= date('M d, H:i', strtotime($t['updated_at'])) ?>
                            </td>
                            <td class="px-5 py-3.5 text-right">
                                <a href="/admin/tickets.php?view=<?= $t['id'] ?>" class="px-3 py-1.5 bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold rounded-lg transition-colors">
                                    Open & Reply
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../app/layouts/admin_footer.php'; ?>
