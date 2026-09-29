<?php
/**
 * NumVault - Master Background Task Runner
 * Idempotent execution of order OTP polling, timeout expiry, and auto-refunds
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Orders\OrderEngine;
use App\Core\Logger;

// Security verification: Allow CLI or authorized CRON_KEY secret
$isCli = (php_sapi_name() === 'cli');
$cronKey = $_GET['key'] ?? $_SERVER['HTTP_X_CRON_KEY'] ?? '';
$expectedKey = getenv('CRON_SECRET') ?: 'nv_cron_secret_secure_2026';

if (!$isCli && !hash_equals($expectedKey, (string)$cronKey)) {
    Logger::security("Unauthorized attempt to invoke cron via HTTP");
    http_response_code(403);
    die(json_encode(['error' => 'Access Denied: Invalid cron secret key.']));
}

$lockFile = STORAGE_PATH . '/cache/cron.lock';
$fp = fopen($lockFile, 'c+');
if (!$fp || !flock($fp, LOCK_EX | LOCK_NB)) {
    die(json_encode(['status' => 'skipped', 'message' => 'Previous cron job is still executing.']));
}

$pdo = get_db();
$stmt = $pdo->query("SELECT id FROM orders WHERE status = 'active' ORDER BY id ASC");
$activeOrderIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

$processed = 0;
$otpsReceived = 0;
$expiredRefunded = 0;

foreach ($activeOrderIds as $orderId) {
    $res = OrderEngine::checkAndUpdateOrder((int)$orderId);
    $processed++;
    if (!empty($res['otp'])) {
        $otpsReceived++;
    } elseif (!empty($res['is_refunded'])) {
        $expiredRefunded++;
    }
}

flock($fp, LOCK_UN);
fclose($fp);

$output = [
    'status'           => 'success',
    'timestamp'        => date('c'),
    'active_scanned'   => $processed,
    'otps_delivered'   => $otpsReceived,
    'auto_refunded'    => $expiredRefunded
];

if ($isCli) {
    echo "[" . date('Y-m-d H:i:s') . "] Processed: {$processed}, Delivered: {$otpsReceived}, Auto-Refunded: {$expiredRefunded}\n";
} else {
    header('Content-Type: application/json');
    echo json_encode($output, JSON_PRETTY_PRINT);
}
