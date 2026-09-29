<?php
/**
 * NumVault - Cron Sub-task: Automatic Timeout Refunds Runner
 * Scans active orders that have exceeded the timeout window without receiving an OTP
 * and atomically refunds the user's wallet with ledger audit records.
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Refunds\RefundService;
use App\Core\Logger;

// Security verification: Allow CLI or authorized CRON_KEY secret
$isCli = (php_sapi_name() === 'cli');
$cronKey = $_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '';
$expectedKey = getenv('CRON_SECRET') ?: 'nv_cron_secret_secure_2026';

if (!$isCli && !hash_equals($expectedKey, (string)$cronKey)) {
    Logger::security("Unauthorized attempt to invoke cron/refunds.php via HTTP");
    http_response_code(403);
    die(json_encode(['error' => 'Access Denied: Invalid cron secret key.']));
}

$lockFile = STORAGE_PATH . '/cache/cron_refunds.lock';
$fp = fopen($lockFile, 'c+');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    die(json_encode(['status' => 'skipped', 'message' => 'Previous refunds cron job is still executing.']));
}

$pdo = get_db();
$timeoutMinutes = (int)get_setting('order_timeout_minutes', '5');

// Query active orders past expiry date/time without OTP
$stmt = $pdo->prepare("
    SELECT id, user_id, amount, server_id, provider_order_id, created_at, expires_at
    FROM orders
    WHERE status = 'active'
      AND (expires_at <= NOW() OR created_at <= DATE_SUB(NOW(), INTERVAL ? MINUTE))
      AND (otp_code IS NULL OR otp_code = '')
    ORDER BY id ASC
");
$stmt->execute([$timeoutMinutes]);
$expiredOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalRefunded = 0;
$refundCount = 0;

foreach ($expiredOrders as $order) {
    $orderId = (int)$order['id'];
    $result = RefundService::processOrderRefund($orderId, 'Order timeout / no OTP delivered within window');
    if ($result['success']) {
        $totalRefunded += (float)($result['amount'] ?? 0);
        $refundCount++;
    }
}

flock($fp, LOCK_UN);
fclose($fp);

$output = [
    'status'         => 'success',
    'timestamp'      => date('c'),
    'scanned_orders' => count($expiredOrders),
    'refunds_issued' => $refundCount,
    'amount_credited'=> round($totalRefunded, 2)
];

if ($isCli) {
    echo "[CRON REFUNDS " . date('Y-m-d H:i:s') . "] Processed: {$refundCount} refunds. Total: \${$totalRefunded}\n";
} else {
    header('Content-Type: application/json');
    echo json_encode($output, JSON_PRETTY_PRINT);
}
