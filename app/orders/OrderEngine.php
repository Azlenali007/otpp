<?php
/**
 * NumVault - Core Order Processing & Lifecycle Engine
 * Handles atomic number purchases, OTP verification polling, expiry & automated refunds
 */

declare(strict_types=1);

namespace App\Orders;

use App\Core\Database;
use App\Core\Logger;
use App\Providers\ProviderFactory;
use PDO;
use Exception;

class OrderEngine {
    /**
     * Atomically purchase a virtual number for a service server
     */
    public static function purchaseNumber(int $userId, int $serverId): array {
        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            // 1. Lock and verify user
            $uStmt = $pdo->prepare("SELECT id, balance, status FROM users WHERE id = ? FOR UPDATE");
            $uStmt->execute([$userId]);
            $user = $uStmt->fetch();

            if (!$user) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'User account not found.'];
            }
            if ($user['status'] === 'blocked') {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'Account is suspended. Please contact support.'];
            }

            // 2. Lock and verify server configuration
            $sStmt = $pdo->prepare("
                SELECT s.*, 
                       sv.name AS service_name, sv.code AS service_code,
                       c.name AS country_name, c.code AS country_code, c.prefix AS country_prefix,
                       p.id AS provider_id, p.name AS provider_name, p.slug AS provider_slug, p.is_enabled AS provider_enabled
                FROM servers s
                JOIN services sv ON s.service_id = sv.id
                JOIN countries c ON s.country_id = c.id
                JOIN providers p ON s.provider_id = p.id
                WHERE s.id = ? AND s.is_enabled = 1 AND sv.is_enabled = 1 AND c.is_enabled = 1
                LIMIT 1
            ");
            $sStmt->execute([$serverId]);
            $server = $sStmt->fetch();

            if (!$server) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'The selected server or service is currently unavailable.'];
            }
            if (empty($server['provider_enabled'])) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'The SMS provider associated with this route is temporarily offline.'];
            }

            $price = (float)$server['selling_price'];
            $costPrice = (float)$server['cost_price'];
            $userBalance = (float)$user['balance'];

            // 3. Verify sufficient wallet balance
            if ($userBalance < $price) {
                $pdo->rollBack();
                return [
                    'success' => false,
                    'error' => 'Insufficient wallet balance. Price is ' . format_price($price) . ', but your balance is ' . format_price($userBalance) . '. Please top up your wallet.'
                ];
            }

            // 4. Contact Provider Adapter for Real Virtual Number
            $provider = ProviderFactory::get((int)$server['provider_id']);
            if (!$provider) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'Failed to initialize connection to SMS provider gateway.'];
            }

            $operator = !empty($server['provider_operator_code']) ? (string)$server['provider_operator_code'] : 'any';
            $provRes = $provider->requestNumber($server['provider_service_code'], $server['provider_country_code'], $operator);
            if (empty($provRes['success']) || empty($provRes['phone'])) {
                $pdo->rollBack();
                return [
                    'success' => false,
                    'error' => $provRes['error'] ?? 'Provider has no available phone numbers in stock right now for this service. Please try another server route.'
                ];
            }

            $phoneNumber = $provRes['phone'];
            $providerOrderId = $provRes['provider_order_id'];

            // 5. Deduct Wallet Balance Atomically
            $balanceBefore = $userBalance;
            $balanceAfter  = $balanceBefore - $price;

            $updUser = $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?");
            $updUser->execute([$balanceAfter, $userId]);

            // 6. Insert Order Record
            $timeoutMinutes = (int)get_setting('order_timeout_minutes', '5');
            $timeoutSeconds = max(60, $timeoutMinutes * 60);

            $insOrder = $pdo->prepare("
                INSERT INTO orders 
                (user_id, service_id, country_id, server_id, provider_id, phone_number, provider_order_id, cost_price, amount, status, expires_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active', DATE_ADD(NOW(), INTERVAL ? SECOND))
            ");
            $insOrder->execute([
                $userId,
                $server['service_id'],
                $server['country_id'],
                $serverId,
                $server['provider_id'],
                $phoneNumber,
                $providerOrderId,
                $costPrice,
                $price,
                $timeoutSeconds
            ]);
            $orderId = (int)$pdo->lastInsertId();

            // 7. Record Immutable Wallet Transaction Ledger
            record_wallet_tx(
                $pdo,
                $userId,
                'debit',
                $price,
                $balanceBefore,
                $balanceAfter,
                "Ordered {$server['service_name']} ({$server['country_name']}) - {$phoneNumber}",
                "ORD-{$orderId}",
                $orderId,
                null
            );

            // 8. Create Customer Notification
            create_notification(
                $userId,
                "Virtual Number Assigned: {$phoneNumber}",
                "Your {$server['service_name']} virtual number is active. Waiting for incoming SMS OTP verification code.",
                'order'
            );

            // 9. Audit Log
            log_audit($userId, 'order_purchased', "Order #{$orderId} for {$server['service_name']} ({$server['country_name']}) at " . format_price($price));

            $pdo->commit();

            return [
                'success'           => true,
                'order_id'          => $orderId,
                'phone_number'      => $phoneNumber,
                'provider_order_id' => $providerOrderId,
                'expires_in'        => $timeoutSeconds,
                'balance_remaining' => $balanceAfter
            ];

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::error("Purchase error: " . $e->getMessage());
            return ['success' => false, 'error' => 'System error processing purchase. Please try again.'];
        }
    }

    /**
     * Check order status with provider gateway, handle arrival of OTP or expiry
     */
    public static function checkAndUpdateOrder(int $orderId): array {
        $pdo = Database::getConnection();

        $stmt = $pdo->prepare("
            SELECT o.*, 
                   sv.name AS service_name, sv.code AS service_code,
                   c.name AS country_name, c.code AS country_code,
                   TIMESTAMPDIFF(SECOND, NOW(), o.expires_at) AS seconds_left
            FROM orders o
            JOIN services sv ON o.service_id = sv.id
            JOIN countries c ON o.country_id = c.id
            WHERE o.id = ?
            LIMIT 1
        ");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();

        if (!$order) {
            return ['error' => 'Order not found'];
        }

        // If order is already completed, expired, or cancelled, return immediate state
        if ($order['status'] !== 'active') {
            return [
                'status'       => $order['status'],
                'otp'          => $order['otp_code'],
                'sms_text'     => $order['sms_text'],
                'is_refunded'  => (bool)$order['is_refunded'],
                'seconds_left' => max(0, (int)$order['seconds_left'])
            ];
        }

        // Check if order has expired
        if ((int)$order['seconds_left'] <= 0) {
            return self::expireAndRefundOrder($orderId);
        }

        // Check provider for OTP
        if (empty($order['provider_id']) || empty($order['provider_order_id'])) {
            return [
                'status'       => 'active',
                'otp'          => null,
                'sms_text'     => null,
                'is_refunded'  => false,
                'seconds_left' => max(0, (int)$order['seconds_left'])
            ];
        }

        $provider = ProviderFactory::get((int)$order['provider_id']);
        if (!$provider) {
            return [
                'status'       => 'active',
                'otp'          => null,
                'sms_text'     => null,
                'is_refunded'  => false,
                'seconds_left' => max(0, (int)$order['seconds_left'])
            ];
        }

        $otpRes = $provider->checkOtp($order['provider_order_id']);

        if (!empty($otpRes['otp']) && $otpRes['status'] === 'completed') {
            $pdo->beginTransaction();
            try {
                $upd = $pdo->prepare("
                    UPDATE orders 
                    SET status = 'completed', otp_code = ?, sms_text = ?, updated_at = NOW() 
                    WHERE id = ? AND status = 'active'
                ");
                $upd->execute([$otpRes['otp'], $otpRes['text'] ?? '', $orderId]);

                create_notification(
                    (int)$order['user_id'],
                    "OTP Received: {$otpRes['otp']}",
                    "Your SMS verification code for {$order['service_name']} ({$order['phone_number']}) is {$otpRes['otp']}",
                    'otp'
                );

                log_audit((int)$order['user_id'], 'otp_received', "Order #{$orderId} received code {$otpRes['otp']}");

                $pdo->commit();

                return [
                    'status'       => 'completed',
                    'otp'          => $otpRes['otp'],
                    'sms_text'     => $otpRes['text'] ?? '',
                    'is_refunded'  => false,
                    'seconds_left' => max(0, (int)$order['seconds_left'])
                ];
            } catch (Exception $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                Logger::error("Order #{$orderId} OTP update error: " . $e->getMessage());
            }
        } elseif ($otpRes['status'] === 'cancelled') {
            return self::cancelAndRefundOrder($orderId, 'Provider cancelled order');
        }

        return [
            'status'       => 'active',
            'otp'          => null,
            'sms_text'     => null,
            'is_refunded'  => false,
            'seconds_left' => max(0, (int)$order['seconds_left'])
        ];
    }

    /**
     * Cancel an active order and execute 100% wallet refund
     */
    public static function cancelAndRefundOrder(int $orderId, string $reason = 'User requested cancellation'): array {
        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? FOR UPDATE");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();

            if (!$order || $order['status'] !== 'active') {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'Order is not in an active cancelable state.'];
            }

            // Cancel with provider if possible
            if (!empty($order['provider_id']) && !empty($order['provider_order_id'])) {
                try {
                    $provider = ProviderFactory::get((int)$order['provider_id']);
                    if ($provider) {
                        $provider->cancelNumber($order['provider_order_id']);
                    }
                } catch (Exception $e) {}
            }

            // Execute 100% Refund
            $refundAmount = (float)$order['amount'];
            $userId = (int)$order['user_id'];

            $uStmt = $pdo->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
            $uStmt->execute([$userId]);
            $user = $uStmt->fetch();

            $balanceBefore = (float)$user['balance'];
            $balanceAfter  = $balanceBefore + $refundAmount;

            // Update user balance
            $updUser = $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?");
            $updUser->execute([$balanceAfter, $userId]);

            // Update order status
            $updOrder = $pdo->prepare("
                UPDATE orders 
                SET status = 'cancelled', is_refunded = 1, refund_amount = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $updOrder->execute([$refundAmount, $orderId]);

            // Record transaction ledger
            record_wallet_tx(
                $pdo,
                $userId,
                'refund',
                $refundAmount,
                $balanceBefore,
                $balanceAfter,
                "Refund for cancelled order #{$orderId} ({$order['phone_number']})",
                "REF-ORD-{$orderId}",
                $orderId,
                null
            );

            create_notification(
                $userId,
                "Order #{$orderId} Cancelled & Refunded",
                "Amount of " . format_price($refundAmount) . " has been returned to your wallet.",
                'wallet'
            );

            log_audit($userId, 'order_cancelled', "Order #{$orderId} cancelled. Reason: {$reason}. Refunded {$refundAmount}");

            $pdo->commit();

            return [
                'success'      => true,
                'status'       => 'cancelled',
                'is_refunded'  => true,
                'refund_amount'=> $refundAmount,
                'new_balance'  => $balanceAfter
            ];

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::error("Cancel & refund error for order #{$orderId}: " . $e->getMessage());
            return ['success' => false, 'error' => 'Failed to cancel order: ' . $e->getMessage()];
        }
    }

    /**
     * Expire overdue order and auto-refund
     */
    public static function expireAndRefundOrder(int $orderId): array {
        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? FOR UPDATE");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();

            if (!$order || $order['status'] !== 'active') {
                $pdo->rollBack();
                return [
                    'status'      => $order['status'] ?? 'expired',
                    'otp'         => $order['otp_code'] ?? null,
                    'is_refunded' => (bool)($order['is_refunded'] ?? false)
                ];
            }

            $refundAmount = (float)$order['amount'];
            $userId = (int)$order['user_id'];

            // Cancel with provider if needed
            if (!empty($order['provider_id']) && !empty($order['provider_order_id'])) {
                try {
                    $provider = ProviderFactory::get((int)$order['provider_id']);
                    if ($provider) {
                        $provider->cancelNumber($order['provider_order_id']);
                    }
                } catch (Exception $e) {}
            }

            $uStmt = $pdo->prepare("SELECT balance FROM users WHERE id = ? FOR UPDATE");
            $uStmt->execute([$userId]);
            $user = $uStmt->fetch();

            $balanceBefore = (float)$user['balance'];
            $balanceAfter  = $balanceBefore + $refundAmount;

            $updUser = $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?");
            $updUser->execute([$balanceAfter, $userId]);

            $updOrder = $pdo->prepare("
                UPDATE orders 
                SET status = 'expired', is_refunded = 1, refund_amount = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $updOrder->execute([$refundAmount, $orderId]);

            record_wallet_tx(
                $pdo,
                $userId,
                'refund',
                $refundAmount,
                $balanceBefore,
                $balanceAfter,
                "Auto-refund: Order #{$orderId} expired without SMS",
                "AUTO-REF-{$orderId}",
                $orderId,
                null
            );

            create_notification(
                $userId,
                "Order #{$orderId} Expired (Auto-Refunded)",
                "No SMS was received for {$order['phone_number']}. Full amount " . format_price($refundAmount) . " has been returned to your wallet.",
                'wallet'
            );

            log_audit($userId, 'order_expired', "Order #{$orderId} expired without SMS. Auto-refunded {$refundAmount}");

            $pdo->commit();

            return [
                'status'       => 'expired',
                'otp'          => null,
                'sms_text'     => null,
                'is_refunded'  => true,
                'refund_amount'=> $refundAmount,
                'seconds_left' => 0
            ];

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::error("Expire order error #{$orderId}: " . $e->getMessage());
            return ['error' => 'Failed to process order expiration.'];
        }
    }
}
