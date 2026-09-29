<?php
/**
 * NumVault - Ticket Discussion View & Reply Form
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$user = require_login();
$ticketId = (int)($_GET['id'] ?? 0);

if ($ticketId <= 0) {
    header('Location: /user/support.php');
    exit;
}

$pdo = get_db();

// Strict user ownership check
$stmt = $pdo->prepare("SELECT * FROM tickets WHERE id = ? AND user_id = ? LIMIT 1");
$stmt->execute([$ticketId, $user['id']]);
$ticket = $stmt->fetch();

if (!$ticket) {
    set_flash('error', 'Ticket not found or unauthorized access.');
    header('Location: /user/support.php');
    exit;
}

$error = '';

// Handle customer reply
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $reply = trim($_POST['message'] ?? '');
    if (empty($reply)) {
        $error = 'Please enter a response message.';
    } else {
        $ins = $pdo->prepare("INSERT INTO ticket_replies (ticket_id, user_id, is_admin, message) VALUES (?, ?, 0, ?)");
        $ins->execute([$ticketId, $user['id'], $reply]);

        // Set status to open (awaiting admin answer)
        $upd = $pdo->prepare("UPDATE tickets SET status = 'open', updated_at = NOW() WHERE id = ?");
        $upd->execute([$ticketId]);

        log_audit($user['id'], 'ticket_reply_user', "Customer replied to ticket #{$ticketId}");
        set_flash('success', 'Your reply has been posted.');
        header("Location: /user/ticket.php?id={$ticketId}");
        exit;
    }
}

// Fetch replies
$rStmt = $pdo->prepare("
    SELECT r.*, u.username 
    FROM ticket_replies r 
    JOIN users u ON r.user_id = u.id 
    WHERE r.ticket_id = ? 
    ORDER BY r.id ASC
");
$rStmt->execute([$ticketId]);
$replies = $rStmt->fetchAll();

$pageTitle = "Ticket #{$ticketId}: " . e($ticket['subject']);
require_once __DIR__ . '/../app/layouts/user_header.php';
?>

<div class="max-w-4xl mx-auto space-y-6">

    <!-- Ticket Summary Card -->
    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <span class="font-mono text-xs font-bold text-slate-400">#<?= $ticket['id'] ?></span>
                <h1 class="text-xl font-extrabold text-slate-900"><?= e($ticket['subject']) ?></h1>
            </div>
            <p class="text-xs text-slate-500 mt-1 font-mono">
                Created: <?= date('M d, Y H:i', strtotime($ticket['created_at'])) ?> &bull; Priority: <?= ucfirst(e($ticket['priority'])) ?>
                <?php if ($ticket['order_id']): ?>
                    &bull; Associated Order: <a href="/user/order.php?id=<?= $ticket['order_id'] ?>" class="text-blue-600 font-bold hover:underline">#<?= $ticket['order_id'] ?></a>
                <?php endif; ?>
            </p>
        </div>

        <div>
            <?php if ($ticket['status'] === 'answered'): ?>
                <span class="px-3 py-1.5 rounded-full text-xs font-bold bg-blue-50 text-blue-700 border border-blue-200">Staff Answered</span>
            <?php elseif ($ticket['status'] === 'open'): ?>
                <span class="px-3 py-1.5 rounded-full text-xs font-bold bg-amber-50 text-amber-700 border border-amber-200">Awaiting Response</span>
            <?php elseif ($ticket['status'] === 'resolved'): ?>
                <span class="px-3 py-1.5 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">Resolved</span>
            <?php else: ?>
                <span class="px-3 py-1.5 rounded-full text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200">Closed</span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Replies Conversation Feed -->
    <div class="space-y-4">
        <?php foreach ($replies as $r): ?>
            <div class="p-6 rounded-2xl border <?= $r['is_admin'] ? 'bg-blue-50/40 border-blue-200' : 'bg-white border-slate-200/80 shadow-xs' ?>">
                <div class="flex items-center justify-between pb-3 mb-3 border-b border-slate-100">
                    <div class="flex items-center gap-2">
                        <div class="w-7 h-7 rounded-lg flex items-center justify-center font-bold text-xs <?= $r['is_admin'] ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-700' ?>">
                            <?= strtoupper(substr($r['username'], 0, 2)) ?>
                        </div>
                        <span class="text-xs font-bold text-slate-900"><?= e($r['username']) ?></span>
                        <?php if ($r['is_admin']): ?>
                            <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-blue-600 text-white">Support Engineer</span>
                        <?php endif; ?>
                    </div>
                    <span class="text-[11px] font-mono text-slate-400"><?= date('M d, H:i', strtotime($r['created_at'])) ?></span>
                </div>
                <div class="text-xs text-slate-800 leading-relaxed whitespace-pre-wrap"><?= e($r['message']) ?></div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Customer Reply Box -->
    <?php if ($ticket['status'] !== 'closed'): ?>
        <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs space-y-4">
            <h2 class="text-xs font-bold uppercase tracking-wider text-slate-900">Post Reply</h2>

            <?php if ($error): ?>
                <div class="p-3 rounded-lg bg-rose-50 text-rose-700 text-xs font-medium">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="/user/ticket.php?id=<?= $ticketId ?>" class="space-y-3">
                <?= csrf_field() ?>
                <textarea name="message" rows="3" required placeholder="Type your follow-up reply here..." class="w-full text-xs px-3.5 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-500 outline-none"></textarea>
                <div class="flex items-center justify-between">
                    <a href="/user/support.php" class="text-xs text-slate-500 hover:text-slate-800">&larr; Back to Help Desk</a>
                    <button type="submit" class="px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-xl shadow-xs transition-colors">
                        Send Reply
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../app/layouts/user_footer.php'; ?>
