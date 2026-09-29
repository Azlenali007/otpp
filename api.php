<?php
/**
 * NumVault - JSON API Endpoints
 * Supports secure AJAX polling, order cancellation, and wallet operations
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/app/bootstrap.php';

use App\Orders\OrderEngine;
use App\Providers\ProviderFactory;
use App\Payments\PaymentGateway;
use App\Core\Logger;

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'ping':
        echo json_encode([
            'status' => 'ok',
            'app'    => 'NumVault',
            'time'   => date('c')
        ]);
        exit;

    case 'check_order':
        $user = require_login();
        $orderId = (int)($_GET['id'] ?? 0);
        if ($orderId <= 0) {
            echo json_encode(['error' => 'Invalid order ID']);
            exit;
        }

        // Verify order ownership
        $pdo = get_db();
        $stmt = $pdo->prepare("SELECT id, user_id, status FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order || ($order['user_id'] != $user['id'] && !is_admin())) {
            echo json_encode(['error' => 'Order not found or permission denied']);
            exit;
        }

        // Run provider check & update
        $res = OrderEngine::checkAndUpdateOrder($orderId);
        echo json_encode($res);
        exit;

    case 'cancel_order':
        $user = require_login();
        require_csrf();

        $orderId = (int)($_POST['order_id'] ?? 0);
        if ($orderId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid order ID']);
            exit;
        }

        // Verify order ownership
        $pdo = get_db();
        $stmt = $pdo->prepare("SELECT id, user_id, status FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order || ($order['user_id'] != $user['id'] && !is_admin())) {
            echo json_encode(['success' => false, 'error' => 'Order not found or access denied']);
            exit;
        }

        $res = OrderEngine::cancelAndRefundOrder($orderId, 'User requested cancellation before expiry');
        echo json_encode($res);
        exit;

    case 'buy_number':
        $user = require_login();
        require_csrf();

        $serverId = (int)($_POST['server_id'] ?? 0);
        if ($serverId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid server ID']);
            exit;
        }

        $res = OrderEngine::purchaseNumber($user['id'], $serverId);
        echo json_encode($res);
        exit;

    case 'get_services_by_country':
        $countryId = (int)($_GET['country_id'] ?? 0);
        $pdo = get_db();
        $stmt = $pdo->prepare("
            SELECT sv.id, sv.name, sv.code, sv.icon, MIN(s.selling_price) AS min_price, COUNT(s.id) AS server_count
            FROM services sv
            JOIN servers s ON sv.id = s.service_id AND s.country_id = ? AND s.is_enabled = 1
            JOIN providers p ON s.provider_id = p.id AND p.is_enabled = 1
            WHERE sv.is_enabled = 1
            GROUP BY sv.id, sv.name, sv.code, sv.icon
            ORDER BY sv.sort_order ASC
        ");
        $stmt->execute([$countryId]);
        echo json_encode(['success' => true, 'services' => $stmt->fetchAll()]);
        exit;

    case 'mark_notification_read':
        $user = require_login();
        require_csrf();
        $notifId = (int)($_POST['notification_id'] ?? 0);
        $pdo = get_db();
        if ($notifId > 0) {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
            $stmt->execute([$notifId, $user['id']]);
        } else {
            $stmt = $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?");
            $stmt->execute([$user['id']]);
        }
        echo json_encode(['success' => true]);
        exit;

    case 'admin_sync_balance':
        $admin = require_admin();
        require_csrf();
        $providerId = (int)($_POST['provider_id'] ?? 0);
        if ($providerId <= 0) {
            echo json_encode(['success' => false, 'error' => 'Invalid provider ID']);
            exit;
        }

        $provider = ProviderFactory::get($providerId);
        if (!$provider) {
            echo json_encode(['success' => false, 'error' => 'Provider not configured']);
            exit;
        }

        $balance = $provider->getBalance();
        $pdo = get_db();
        $upd = $pdo->prepare("UPDATE providers SET balance = ?, last_check = NOW() WHERE id = ?");
        $upd->execute([$balance, $providerId]);

        echo json_encode(['success' => true, 'balance' => $balance]);
        exit;

    case 'cryptomus_webhook':
        // Cryptomus webhook verification
        $raw = file_get_contents('php://input');
        $data = json_decode((string)$raw, true);
        $sign = $_SERVER['HTTP_SIGN'] ?? ($data['sign'] ?? '');
        $apiKey = get_setting('cryptomus_api_key', '');

        if (empty($apiKey) || empty($data['order_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid webhook configuration']);
            exit;
        }

        // Verify signature
        $expectedSign = md5(base64_encode((string)$raw) . $apiKey);
        if (!hash_equals($expectedSign, (string)$sign)) {
            Logger::security("Cryptomus webhook signature mismatch");
            http_response_code(403);
            echo json_encode(['error' => 'Signature mismatch']);
            exit;
        }

        // Idempotent payment processing
        if (in_array($data['status'] ?? '', ['paid', 'paid_over'])) {
            $ref = $data['order_id'];
            $pdo = get_db();
            $pStmt = $pdo->prepare("SELECT id, user_id, amount, status FROM payments WHERE transaction_ref = ? FOR UPDATE");
            $pdo->beginTransaction();
            $pStmt->execute([$ref]);
            $payment = $pStmt->fetch();

            if ($payment && $payment['status'] === 'pending') {
                PaymentGateway::approvePayment(1, (int)$payment['id']);
            }
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
        }
        echo json_encode(['status' => 'processed']);
        exit;

    default:
        http_response_code(400);
        echo json_encode(['error' => 'Unknown API action requested']);
        exit;
}
