<?php
/**
 * NumVault - Wallet & Transaction Service
 * Atomic wallet debit/credit operations with strict database row locks and ledger accounting
 */

declare(strict_types=1);

namespace App\Wallet;

use PDO;
use Exception;
use App\Core\Logger;

class WalletService {
    /**
     * Fetch user's current wallet balance directly from the database
     */
    public static function getBalance(int $userId): float {
        $pdo = get_db();
        $stmt = $pdo->prepare("SELECT balance FROM users WHERE id = ?");
        $stmt->execute([$userId]);
        $val = $stmt->fetchColumn();
        return $val !== false ? (float)$val : 0.0;
    }

    /**
     * Credit funds to a user's wallet with atomic row locking
     * 
     * @return array [success => bool, new_balance => float, error => ?string]
     */
    public static function credit(
        int $userId,
        float $amount,
        string $description,
        ?string $reference = null,
        ?int $paymentId = null,
        string $type = 'credit'
    ): array {
        if ($amount <= 0) {
            return ['success' => false, 'error' => 'Credit amount must be greater than zero.'];
        }

        $pdo = get_db();
        try {
            $pdo->beginTransaction();

            // Lock user row FOR UPDATE
            $stmt = $pdo->prepare("SELECT id, balance, status FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'User account not found.'];
            }

            if ($user['status'] !== 'active') {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'Account is suspended or inactive.'];
            }

            $balanceBefore = (float)$user['balance'];
            $newBalance = round($balanceBefore + $amount, 2);

            // Update user balance
            $upd = $pdo->prepare("UPDATE users SET balance = ?, updated_at = NOW() WHERE id = ?");
            $upd->execute([$newBalance, $userId]);

            // Create ledger entry in wallet_transactions
            $ins = $pdo->prepare("
                INSERT INTO wallet_transactions (user_id, order_id, payment_id, type, amount, balance_before, balance_after, description, reference_id, created_at)
                VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $ins->execute([$userId, $paymentId, $type, $amount, $balanceBefore, $newBalance, $description, $reference]);

            $pdo->commit();

            Logger::audit($userId, 'wallet_credit', "Credited \${$amount} (New Balance: \${$newBalance}). Ref: {$reference}");

            return [
                'success' => true,
                'balance_before' => $balanceBefore,
                'new_balance' => $newBalance,
                'amount' => $amount
            ];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::error("Wallet credit failed for User #{$userId}: " . $e->getMessage());
            return ['success' => false, 'error' => 'Wallet transaction failed.'];
        }
    }

    /**
     * Debit funds from a user's wallet with atomic row locking and overdraft check
     * 
     * @return array [success => bool, new_balance => float, error => ?string]
     */
    public static function debit(
        int $userId,
        float $amount,
        string $description,
        ?string $reference = null,
        ?int $orderId = null,
        string $type = 'debit'
    ): array {
        if ($amount <= 0) {
            return ['success' => false, 'error' => 'Debit amount must be greater than zero.'];
        }

        $pdo = get_db();
        try {
            $pdo->beginTransaction();

            // Lock user row FOR UPDATE
            $stmt = $pdo->prepare("SELECT id, balance, status FROM users WHERE id = ? FOR UPDATE");
            $stmt->execute([$userId]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'User account not found.'];
            }

            if ($user['status'] !== 'active') {
                $pdo->rollBack();
                return ['success' => false, 'error' => 'Account is suspended or inactive.'];
            }

            $balanceBefore = (float)$user['balance'];
            if ($balanceBefore < $amount) {
                $pdo->rollBack();
                return [
                    'success' => false,
                    'error' => "Insufficient funds. Available: \${$balanceBefore}, Required: \${$amount}"
                ];
            }

            $newBalance = round($balanceBefore - $amount, 2);

            // Update user balance
            $upd = $pdo->prepare("UPDATE users SET balance = ?, updated_at = NOW() WHERE id = ?");
            $upd->execute([$newBalance, $userId]);

            // Create ledger entry in wallet_transactions
            $ins = $pdo->prepare("
                INSERT INTO wallet_transactions (user_id, order_id, payment_id, type, amount, balance_before, balance_after, description, reference_id, created_at)
                VALUES (?, ?, NULL, ?, ?, ?, ?, ?, ?, NOW())
            ");
            $ins->execute([$userId, $orderId, $type, $amount, $balanceBefore, $newBalance, $description, $reference]);

            $pdo->commit();

            Logger::audit($userId, 'wallet_debit', "Debited \${$amount} (New Balance: \${$newBalance}). Ref: {$reference}");

            return [
                'success' => true,
                'balance_before' => $balanceBefore,
                'new_balance' => $newBalance,
                'amount' => $amount
            ];
        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::error("Wallet debit failed for User #{$userId}: " . $e->getMessage());
            return ['success' => false, 'error' => 'Wallet transaction failed.'];
        }
    }

    /**
     * Get transaction ledger history for a user
     */
    public static function getTransactions(int $userId, int $limit = 50, int $offset = 0): array {
        $pdo = get_db();
        $stmt = $pdo->prepare("
            SELECT * FROM wallet_transactions
            WHERE user_id = ?
            ORDER BY id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
