<?php
/**
 * NumVault - Customer Support Help Desk
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Core\RateLimiter;

$user = require_login();
$pageTitle = "Support Help Desk";

$pdo = get_db();
$error = '';

// Handle Ticket Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $rate = RateLimiter::check('create_ticket', 5, 600);
    if (!$rate['allowed']) {
        $error = "Ticket submission limit reached. Please wait {$rate['retry_after']} seconds before submitting another ticket.";
    } else {
        $subject  = trim($_POST['subject'] ?? '');
        $priority = trim($_POST['priority'] ?? 'medium');
        $orderId  = !empty($_POST['order_id']) ? (int)$_POST['order_id'] : null;
        $message  = trim($_POST['message'] ?? '');

        if (empty($subject) || empty($message)) {
            $error = 'Please provide both a ticket subject and your detailed inquiry.';
        } else {
            // Verify order ownership if order_id is supplied
            if ($orderId !== null) {
                $chkOrder = $pdo->prepare("SELECT id FROM orders WHERE id = ? AND user_id = ?");
                $chkOrder->execute([$orderId, $user['id']]);
                if (!$chkOrder->fetch()) {
                    $orderId = null; // Discard invalid order association
                }
            }

            $stmt = $pdo->prepare("
                INSERT INTO tickets (user_id, order_id, subject, priority, status)
                VALUES (?, ?, ?, ?, 'open')
            ");
            $stmt->execute([$user['id'], $orderId, $subject, $priority]);
            $ticketId = (int)$pdo->lastInsertId();

            // Insert initial message into replies
            $rStmt = $pdo->prepare("
                INSERT INTO ticket_replies (ticket_id, user_id, is_admin, message)
                VALUES (?, ?, 0, ?)
            ");
            $rStmt->execute([$ticketId, $user['id'], $message]);

            log_audit($user['id'], 'ticket_created', "Opened ticket #{$ticketId}: {$subject}");
            set_flash('success', "Support ticket #{$ticketId} submitted! Our engineering team will review it shortly.");
            header("Location: /user/ticket.php?id={$ticketId}");
            exit;
        }
    }
}

// Fetch user tickets
$stmt = $pdo->prepare("
    SELECT t.*, COUNT(r.id) AS reply_count 
    FROM tickets t
    LEFT JOIN ticket_replies r ON t.id = r.ticket_id
    WHERE t.user_id = ?
    GROUP BY t.id
    ORDER BY t.id DESC
");
$stmt->execute([$user['id']]);
$tickets = $stmt->fetchAll();

// Fetch recent orders for dropdown
$oStmt = $pdo->prepare("SELECT id, phone_number FROM orders WHERE user_id = ? ORDER BY id DESC LIMIT 10");
$oStmt->execute([$user['id']]);
$userOrders = $oStmt->fetchAll();

require_once __DIR__ . '/../app/layouts/user_header.php';
?>

<div class="space-y-8">

    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 sm:p-8 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-6">
        <div>
            <span class="text-xs font-bold uppercase tracking-wider text-blue-600">Technical Support</span>
            <h1 class="text-3xl font-extrabold text-slate-900 tracking-tight mt-0.5">Customer Help Desk</h1>
            <p class="text-xs sm:text-sm text-slate-500 mt-1">
                Direct assistance with virtual carrier routes, gateway inquiries, and account questions.
            </p>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-medium flex items-center gap-2.5">
            <?= icon('alert-circle', 'w-5 h-5 flex-shrink-0') ?>
            <span><?= e($error) ?></span>
        </div>
    <?php endif; ?>

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-8">

        <!-- Left: Open New Ticket Form -->
        <div class="lg:col-span-5 space-y-6">
            <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs space-y-5">
                <div>
                    <h2 class="text-base font-bold text-slate-900">Open a New Support Ticket</h2>
                    <p class="text-xs text-slate-500 mt-0.5">Please provide specific details for faster resolution</p>
                </div>

                <form method="POST" action="/user/support.php" class="space-y-4">
                    <?= csrf_field() ?>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Subject</label>
                        <input type="text" name="subject" required placeholder="e.g. Assistance with WhatsApp route" value="<?= e($_POST['subject'] ?? '') ?>" class="w-full text-xs px-3.5 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-500 outline-none">
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Priority</label>
                            <select name="priority" class="w-full text-xs px-3 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-500 outline-none">
                                <option value="low">Low Priority</option>
                                <option value="medium" selected>Medium Priority</option>
                                <option value="high">High Priority</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-semibold text-slate-700 mb-1">Related Order (Optional)</label>
                            <select name="order_id" class="w-full text-xs px-3 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-500 outline-none">
                                <option value="">None / General</option>
                                <?php foreach ($userOrders as $uo): ?>
                                    <option value="<?= $uo['id'] ?>">#<?= $uo['id'] ?> (<?= e($uo['phone_number']) ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Message Description</label>
                        <textarea name="message" rows="4" required placeholder="Describe your question or issue in detail..." class="w-full text-xs px-3.5 py-2.5 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-500 outline-none"><?= e($_POST['message'] ?? '') ?></textarea>
                    </div>

                    <button type="submit" class="w-full py-3 px-4 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-md shadow-blue-600/20 transition-colors">
                        Submit Support Ticket &rarr;
                    </button>
                </form>
            </div>
        </div>

        <!-- Right: Tickets List -->
        <div class="lg:col-span-7 space-y-6">
            <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-base font-bold text-slate-900">My Support Tickets</h2>
                        <p class="text-xs text-slate-500 mt-0.5">Track conversations with our support personnel</p>
                    </div>
                </div>

                <?php if (!empty($tickets)): ?>
                    <div class="divide-y divide-slate-100">
                        <?php foreach ($tickets as $t): ?>
                            <a href="/user/ticket.php?id=<?= $t['id'] ?>" class="py-4 flex items-center justify-between hover:bg-slate-50/60 p-2 rounded-xl transition-colors group">
                                <div class="space-y-1">
                                    <div class="flex items-center gap-2">
                                        <span class="font-mono text-xs font-bold text-slate-400">#<?= $t['id'] ?></span>
                                        <h3 class="text-xs font-bold text-slate-900 group-hover:text-blue-600 transition-colors">
                                            <?= e($t['subject']) ?>
                                        </h3>
                                        <?php if ($t['order_id']): ?>
                                            <span class="text-[10px] font-mono text-slate-400 bg-slate-100 px-1.5 py-0.5 rounded">Order #<?= $t['order_id'] ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="text-[11px] text-slate-400 font-mono">
                                        <?= date('M d, Y H:i', strtotime($t['created_at'])) ?> &bull; <?= $t['reply_count'] ?> messages
                                    </div>
                                </div>
                                <div class="flex items-center gap-3">
                                    <?php if ($t['status'] === 'answered'): ?>
                                        <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-blue-50 text-blue-700 border border-blue-200">Staff Answered</span>
                                    <?php elseif ($t['status'] === 'open'): ?>
                                        <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">Awaiting Reply</span>
                                    <?php elseif ($t['status'] === 'resolved'): ?>
                                        <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">Resolved</span>
                                    <?php else: ?>
                                        <span class="px-2.5 py-1 rounded-full text-[10px] font-bold bg-slate-100 text-slate-600 border border-slate-200">Closed</span>
                                    <?php endif; ?>
                                    <?= icon('arrow-right', 'w-4 h-4 text-slate-400 group-hover:text-blue-600 transition-colors') ?>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="py-12 text-center text-slate-400 text-xs">
                        No support tickets opened yet.
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

</div>

<?php require_once __DIR__ . '/../app/layouts/user_footer.php'; ?>
