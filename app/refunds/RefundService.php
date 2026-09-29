<?php
/**
 * NumVault - Refund Service
 * Handles automatic and manual order refunds with atomic ledger consistency and duplicate prevention
 */

declare(strict_types=1);

namespace App\Refunds;

use PDO;
use Exception;
use App\Core\Logger;
use App\Orders\OrderEngine;

class RefundService {
    /**
     * Process an atomic refund for an order back to the user's wallet
     * 
     * @return array [success => bool, amount => float, error => ?string]
     */
    public static function processOrderRefund(
        int $orderId,
        string $reason = 'Order cancelled / no OTP delivered',
        ?int $adminId = null
    ): array {
        $pdo = get_db();

        try {
            $pdo->beginTransaction();

            // 1. Lock the order row FOR UPDATE
            $stmt = $pdo->prepare("SELECT * FROM orders WHERE id = ? FOR UPDATE");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$order) {
                $pdo->rollBack();
                return ['success' => false, 'error' => "Order #{$orderId} not found."];
            }

            // Guard against duplicate refunds
            if (!empty($order['is_refunded']) || $order['status'] === 'cancelled') {
                $pdo->rollBack();
                return ['success' => false, 'error' => "Order #{$orderId} has already been refunded or cancelled."];
            }

            // Completed orders with received OTP code cannot be refunded
            if ($order['status'] === 'completed' && !empty($order['otp_code'])) {
                $pdo->rollBack();
                return ['success' => false, 'error' => "Order #{$orderId} was completed with OTP code and cannot be refunded."];
            }

            $refundAmount = (float)$order['amount'];
            $userId = (int)$order['user_id'];

            // 2. Lock user row FOR UPDATE
            $uStmt = $pdo->prepare("SELECT id, balance FROM users WHERE id = ? FOR UPDATE");
            $uStmt->execute([$userId]);
            $user = $uStmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'Associated customer account not found.'];
            }

            $balanceBefore = (float)$user['balance'];
            $balanceAfter = round($balanceBefore + $refundAmount, 2);

            // 3. Credit user balance
            $updUser = $pdo->prepare("UPDATE users SET balance = ?, updated_at = NOW() WHERE id = ?");
            $updUser->execute([$balanceAfter, $userId]);

            // 4. Mark order as cancelled and refunded
            $updOrder = $pdo->prepare("
                UPDATE orders 
                SET status = 'cancelled', is_refunded = 1, refund_amount = ?, refund_reason = ?, updated_at = NOW() 
                WHERE id = ?
            ");
            $updOrder->execute([$refundAmount, $reason, $orderId]);

            // 5. Create transaction ledger entry in wallet_transactions
            $tStmt = $pdo->prepare("
                INSERT INTO wallet_transactions (user_id, order_id, payment_id, type, amount, balance_before, balance_after, description, reference_id, created_at)
                VALUES (?, ?, NULL, 'refund', ?, ?, ?, ?, ?, NOW())
            ");
            $desc = "Refund for order #{$orderId} ({$reason})";
            $ref = "REF-ORD-{$orderId}-" . time();
            $tStmt->execute([$userId, $orderId, $refundAmount, $balanceBefore, $balanceAfter, $desc, $ref]);

            $pdo->commit();

            // 6. Security & Audit Logging
            $actor = $adminId ? "Admin #{$adminId}" : "Automated System";
            Logger::audit(
                $userId,
                'order_refunded',
                "{$actor} issued refund of \${$refundAmount} for Order #{$orderId}. New Balance: \${$balanceAfter}. Reason: {$reason}"
            );

            return [
                'success' => true,
                'amount' => $refundAmount,
                'new_balance' => $balanceAfter,
                'order_id' => $orderId
            ];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::error("Order refund failed for Order #{$orderId}: " . $e->getMessage());
            return ['success' => false, 'error' => 'Database error processing refund: ' . $e->getMessage()];
        }
    }

    /**
     * Retrieve refund statistics for admin analytics
     */
    public static function getStats(): array {
        $pdo = get_db();
        $totalRefunds = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE is_refunded = 1 OR status = 'cancelled'")->fetchColumn();
        $totalAmount = (float)$pdo->query("SELECT COALESCE(SUM(refund_amount), 0) FROM orders WHERE is_refunded = 1")->fetchColumn();
        $todayRefunds = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE is_refunded = 1 AND DATE(updated_at) = CURDATE()")->fetchColumn();
        $todayAmount = (float)$pdo->query("SELECT COALESCE(SUM(refund_amount), 0) FROM orders WHERE is_refunded = 1 AND DATE(updated_at) = CURDATE()")->fetchColumn();

        return [
            'total_count' => $totalRefunds,
            'total_amount' => $totalAmount,
            'today_count' => $todayRefunds,
            'today_amount' => $todayAmount,
        ];
    }
}
