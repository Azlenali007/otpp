<?php
/**
 * NumVault - Customer Notifications Center
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$user = require_login();
$pageTitle = "Notifications";

$pdo = get_db();

// Handle mark all as read
if (isset($_POST['mark_all_read'])) {
    require_csrf();
    $upd = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
    $upd->execute([$user['id']]);
    set_flash('success', 'All notifications marked as read.');
    header('Location: /user/notifications.php');
    exit;
}

// Fetch notifications
$stmt = $pdo->prepare("SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 50");
$stmt->execute([$user['id']]);
$notifications = $stmt->fetchAll();

require_once __DIR__ . '/../app/layouts/user_header.php';
?>

<div class="max-w-3xl mx-auto space-y-6">

    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs flex items-center justify-between">
        <div>
            <h1 class="text-2xl font-extrabold text-slate-900 tracking-tight">Notification Center</h1>
            <p class="text-xs text-slate-500 mt-0.5">Real-time alerts for numbers, OTP deliveries, wallet credits, and refunds</p>
        </div>
        <?php if (!empty($notifications)): ?>
            <form method="POST" action="/user/notifications.php">
                <?= csrf_field() ?>
                <button type="submit" name="mark_all_read" value="1" class="text-xs font-bold text-blue-600 hover:text-blue-700 bg-blue-50 px-3 py-1.5 rounded-lg border border-blue-200">
                    Mark All as Read
                </button>
            </form>
        <?php endif; ?>
    </div>

    <div class="space-y-3">
        <?php if (!empty($notifications)): ?>
            <?php foreach ($notifications as $n): ?>
                <div class="p-5 rounded-2xl border bg-white border-slate-200/80 shadow-xs flex items-start justify-between gap-4">
                    <div class="space-y-1">
                        <div class="flex items-center gap-2">
                            <?php if (!$n['is_read']): ?>
                                <span class="w-2 h-2 rounded-full bg-blue-600"></span>
                            <?php endif; ?>
                            <h3 class="text-sm font-bold text-slate-900"><?= e($n['title']) ?></h3>
                        </div>
                        <p class="text-xs text-slate-600 leading-relaxed"><?= e($n['message']) ?></p>
                        <div class="text-[10px] font-mono text-slate-400">
                            <?= date('M d, Y H:i:s', strtotime($n['created_at'])) ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <div class="p-12 text-center text-slate-400 bg-white rounded-2xl border border-slate-200/80 text-xs">
                No notifications received yet.
            </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/../app/layouts/user_footer.php'; ?>
