<?php
/**
 * NumVault - Active Virtual Number & OTP Verification Terminal
 * Displays real-time countdown, copyable number, instant OTP SMS box, and auto-refund indicator
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Orders\OrderEngine;

$user = require_login();
$orderId = (int)($_GET['id'] ?? 0);

if ($orderId <= 0) {
    header('Location: /user/orders.php');
    exit;
}

$pdo = get_db();

// Strict user ownership authorization
$stmt = $pdo->prepare("
    SELECT o.*, 
           sv.name AS service_name, sv.icon AS service_icon,
           c.name AS country_name, c.code AS country_code, c.prefix AS country_prefix,
           s.server_name,
           TIMESTAMPDIFF(SECOND, NOW(), o.expires_at) AS seconds_left
    FROM orders o
    JOIN services sv ON o.service_id = sv.id
    JOIN countries c ON o.country_id = c.id
    JOIN servers s ON o.server_id = s.id
    WHERE o.id = ? AND o.user_id = ?
    LIMIT 1
");
$stmt->execute([$orderId, $user['id']]);
$order = $stmt->fetch();

if (!$order) {
    set_flash('error', 'Order not found or unauthorized access.');
    header('Location: /user/orders.php');
    exit;
}

// Check status: If expired by time, process refund immediately; active polling is handled asynchronously
if ($order['status'] === 'active' && (int)$order['seconds_left'] <= 0) {
    $res = OrderEngine::checkAndUpdateOrder($orderId);
    if (!empty($res['is_refunded'])) {
        $order['status'] = 'expired';
        $order['is_refunded'] = 1;
    }
}

$pageTitle = "OTP Terminal #{$orderId}";
require_once __DIR__ . '/../app/layouts/user_header.php';
?>

<div class="max-w-3xl mx-auto space-y-6">

    <!-- Terminal Header & Status -->
    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="w-12 h-12 rounded-xl bg-blue-50 border border-blue-200 flex items-center justify-center text-blue-600 shadow-xs">
                <?= icon($order['service_icon'] ?: 'shield', 'w-6 h-6') ?>
            </div>
            <div>
                <div class="flex items-center gap-2">
                    <h1 class="text-xl font-extrabold text-slate-900"><?= e($order['service_name']) ?></h1>
                    <span class="text-xs text-slate-400 font-mono">#<?= $order['id'] ?></span>
                </div>
                <p class="text-xs text-slate-500 mt-0.5">
                    <?= e($order['country_name']) ?> &bull; <?= e($order['server_name']) ?>
                </p>
            </div>
        </div>

        <div id="terminalStatusBadge">
            <?php if ($order['status'] === 'completed'): ?>
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                    <span class="w-2 h-2 rounded-full bg-emerald-600"></span>
                    <span>SMS RECEIVED</span>
                </span>
            <?php elseif ($order['status'] === 'active'): ?>
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-blue-50 text-blue-700 border border-blue-200 animate-pulse">
                    <span class="w-2 h-2 rounded-full bg-blue-600"></span>
                    <span>LISTENING FOR SMS</span>
                </span>
            <?php elseif ($order['status'] === 'expired'): ?>
                <span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200">
                    <span>EXPIRED (REFUNDED)</span>
                </span>
            <?php else: ?>
                <span class="inline-flex items-center px-3 py-1.5 rounded-full text-xs font-bold bg-rose-50 text-rose-700 border border-rose-200">
                    <?= strtoupper(e($order['status'])) ?>
                </span>
            <?php endif; ?>
        </div>
    </div>

    <!-- Number Display Card with Copy -->
    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 sm:p-8 shadow-xs space-y-6">
        <div>
            <div class="text-xs font-bold text-slate-400 uppercase tracking-wider">Assigned Virtual Phone Number</div>
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mt-2 bg-slate-50 p-4 rounded-xl border border-slate-200">
                <span class="font-mono text-2xl sm:text-3xl font-black text-slate-900 tracking-tight select-all" id="phoneNumberText">
                    <?= e($order['phone_number']) ?>
                </span>
                <button type="button" id="copyNumberBtn" class="inline-flex items-center justify-center gap-2 px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-xs transition-colors">
                    <?= icon('copy', 'w-4 h-4') ?>
                    <span id="copyNumberBtnText">Copy Number</span>
                </button>
            </div>
            <p class="text-xs text-slate-500 mt-2">
                Paste this phone number into the <?= e($order['service_name']) ?> application or website to request your verification SMS.
            </p>
        </div>

        <!-- Real-Time Countdown Timer (Only visible for active orders) -->
        <div id="countdownContainer" class="<?= $order['status'] === 'active' ? '' : 'hidden' ?> p-4 rounded-xl bg-blue-50/70 border border-blue-200 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
            <div class="flex items-center gap-3">
                <div class="w-8 h-8 rounded-lg bg-blue-600 text-white flex items-center justify-center">
                    <?= icon('clock', 'w-4 h-4') ?>
                </div>
                <div>
                    <div class="text-xs font-bold text-slate-800">Time Left to Receive SMS</div>
                    <div class="text-[11px] text-slate-500">Auto-refund triggers immediately if timeout expires</div>
                </div>
            </div>
            <div class="font-mono text-2xl font-black text-blue-700 tabular-nums" id="countdownTimer">
                --:--
            </div>
        </div>

        <!-- Verification OTP Code Arrival Container -->
        <div class="space-y-3">
            <div class="text-xs font-bold text-slate-400 uppercase tracking-wider">Incoming Verification Code (OTP)</div>

            <!-- OTP Box when code is present -->
            <div id="otpSuccessBox" class="<?= !empty($order['otp_code']) ? '' : 'hidden' ?> bg-emerald-50 border-2 border-emerald-500 p-6 rounded-2xl text-center space-y-4">
                <div class="inline-flex items-center gap-2 text-emerald-800 font-bold text-sm">
                    <?= icon('check-circle', 'w-5 h-5 text-emerald-600') ?>
                    <span>SMS Verification Code Received!</span>
                </div>
                <div class="font-mono text-4xl sm:text-5xl font-black tracking-widest text-emerald-900 select-all" id="otpCodeText">
                    <?= e($order['otp_code'] ?? '') ?>
                </div>
                <div class="pt-2 flex justify-center">
                    <button type="button" id="copyOtpBtn" class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-emerald-600 hover:bg-emerald-700 text-white font-bold text-xs shadow-xs transition-colors">
                        <?= icon('copy', 'w-4 h-4') ?>
                        <span id="copyOtpBtnText">Copy OTP Code</span>
                    </button>
                </div>
                <div class="text-xs text-emerald-700 font-mono" id="smsFullText">
                    <?= e($order['sms_text'] ?? '') ?>
                </div>
            </div>

            <!-- Waiting Indicator Box -->
            <div id="otpWaitingBox" class="<?= (empty($order['otp_code']) && $order['status'] === 'active') ? '' : 'hidden' ?> p-8 rounded-2xl border-2 border-dashed border-slate-200 text-center space-y-3">
                <div class="w-10 h-10 rounded-full bg-blue-50 text-blue-600 flex items-center justify-center mx-auto animate-spin">
                    <?= icon('refresh-cw', 'w-5 h-5') ?>
                </div>
                <div class="text-sm font-bold text-slate-700">Waiting for incoming SMS from carrier...</div>
                <p class="text-xs text-slate-400 max-w-sm mx-auto">
                    Polling provider gateway every 3 seconds. The code will display automatically as soon as it arrives.
                </p>
            </div>

            <!-- Expired / Refunded Box -->
            <div id="otpExpiredBox" class="<?= ($order['status'] === 'expired' || $order['status'] === 'cancelled') ? '' : 'hidden' ?> p-6 rounded-2xl bg-slate-50 border border-slate-200 text-center space-y-2">
                <div class="text-sm font-bold text-slate-800">
                    <?= $order['status'] === 'cancelled' ? 'Order Cancelled' : 'Order Expired' ?>
                </div>
                <p class="text-xs text-slate-500">
                    Full refund of <strong class="text-slate-900"><?= format_price($order['amount']) ?></strong> has been automatically credited back to your account wallet.
                </p>
            </div>
        </div>

        <!-- Action / Cancellation Controls -->
        <div class="pt-4 border-t border-slate-100 flex flex-col sm:flex-row items-center justify-between gap-3">
            <a href="/user/services.php" class="text-xs font-semibold text-slate-500 hover:text-slate-800">
                &larr; Get Another Number
            </a>

            <?php if ($order['status'] === 'active'): ?>
                <button type="button" id="cancelOrderBtn" class="text-xs font-bold text-rose-600 hover:text-rose-700 hover:bg-rose-50 px-3.5 py-2 rounded-lg border border-rose-200 transition-colors">
                    Cancel & Refund Now
                </button>
            <?php endif; ?>
        </div>
    </div>

</div>

<script>
    const orderId = <?= (int)$order['id'] ?>;
    let initialSecondsLeft = <?= max(0, (int)$order['seconds_left']) ?>;
    let orderStatus = "<?= e($order['status']) ?>";

    // Copy phone number
    document.getElementById('copyNumberBtn')?.addEventListener('click', function() {
        const text = document.getElementById('phoneNumberText')?.innerText.trim();
        if (text) {
            navigator.clipboard.writeText(text).then(() => {
                const btnText = document.getElementById('copyNumberBtnText');
                if (btnText) btnText.innerText = 'Copied!';
                setTimeout(() => { if (btnText) btnText.innerText = 'Copy Number'; }, 2000);
            });
        }
    });

    // Copy OTP code
    document.getElementById('copyOtpBtn')?.addEventListener('click', function() {
        const text = document.getElementById('otpCodeText')?.innerText.trim();
        if (text) {
            navigator.clipboard.writeText(text).then(() => {
                const btnText = document.getElementById('copyOtpBtnText');
                if (btnText) btnText.innerText = 'Copied!';
                setTimeout(() => { if (btnText) btnText.innerText = 'Copy OTP Code'; }, 2000);
            });
        }
    });

    // Countdown Timer logic
    function updateCountdownDisplay() {
        if (initialSecondsLeft <= 0) {
            const timerElem = document.getElementById('countdownTimer');
            if (timerElem) timerElem.innerText = "00:00";
            return;
        }
        const m = Math.floor(initialSecondsLeft / 60);
        const s = initialSecondsLeft % 60;
        const formatted = String(m).padStart(2, '0') + ':' + String(s).padStart(2, '0');
        const timerElem = document.getElementById('countdownTimer');
        if (timerElem) timerElem.innerText = formatted;
        initialSecondsLeft--;
    }

    if (orderStatus === 'active') {
        updateCountdownDisplay();
        setInterval(updateCountdownDisplay, 1000);

        // Poll order status every 3 seconds
        const pollInterval = setInterval(() => {
            fetch('/api.php?action=check_order&id=' + orderId)
                .then(res => res.json())
                .then(data => {
                    if (data.status === 'completed' && data.otp) {
                        clearInterval(pollInterval);
                        document.getElementById('countdownContainer')?.classList.add('hidden');
                        document.getElementById('otpWaitingBox')?.classList.add('hidden');
                        document.getElementById('cancelOrderBtn')?.remove();

                        const otpSuccess = document.getElementById('otpSuccessBox');
                        if (otpSuccess) otpSuccess.classList.remove('hidden');

                        const otpText = document.getElementById('otpCodeText');
                        if (otpText) otpText.innerText = data.otp;

                        const smsText = document.getElementById('smsFullText');
                        if (smsText && data.sms_text) smsText.innerText = data.sms_text;

                        const statusBadge = document.getElementById('terminalStatusBadge');
                        if (statusBadge) {
                            statusBadge.innerHTML = '<span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-emerald-50 text-emerald-700 border border-emerald-200"><span class="w-2 h-2 rounded-full bg-emerald-600"></span><span>SMS RECEIVED</span></span>';
                        }
                    } else if (data.status === 'expired' || data.status === 'cancelled' || data.is_refunded) {
                        clearInterval(pollInterval);
                        document.getElementById('countdownContainer')?.classList.add('hidden');
                        document.getElementById('otpWaitingBox')?.classList.add('hidden');
                        document.getElementById('cancelOrderBtn')?.remove();

                        const expiredBox = document.getElementById('otpExpiredBox');
                        if (expiredBox) expiredBox.classList.remove('hidden');

                        const statusBadge = document.getElementById('terminalStatusBadge');
                        if (statusBadge) {
                            statusBadge.innerHTML = '<span class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-full text-xs font-bold bg-slate-100 text-slate-700 border border-slate-200"><span>EXPIRED (REFUNDED)</span></span>';
                        }
                    }
                })
                .catch(err => console.error("Poll error:", err));
        }, 3000);

        // Cancel order button
        document.getElementById('cancelOrderBtn')?.addEventListener('click', function() {
            if (confirm("Are you sure you want to cancel this order? The full amount will be refunded immediately to your wallet.")) {
                const formData = new FormData();
                formData.append('order_id', orderId);
                formData.append('csrf_token', "<?= Security::csrfToken() ?>");

                fetch('/api.php?action=cancel_order', {
                    method: 'POST',
                    body: formData
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        location.reload();
                    } else {
                        alert(data.error || "Failed to cancel order.");
                    }
                });
            }
        });
    }
</script>

<?php require_once __DIR__ . '/../app/layouts/user_footer.php'; ?>
