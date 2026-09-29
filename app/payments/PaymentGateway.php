<?php
/**
 * NumVault - Unified Payment Gateway Manager
 * Enforces server-side payment verification, idempotent webhooks, and atomic wallet credits
 */

declare(strict_types=1);

namespace App\Payments;

use App\Core\Database;
use App\Core\Logger;
use PDO;
use Exception;

class PaymentGateway {
    /**
     * Create payment invoice and generate gateway checkout URL or manual instructions
     */
    public static function createPayment(int $userId, string $gateway, float $amount): array {
        if ($amount <= 0) {
            return ['success' => false, 'error' => 'Invalid deposit amount.'];
        }

        $minDeposit = (float)get_setting('min_deposit', '1.00');
        if ($amount < $minDeposit) {
            return ['success' => false, 'error' => 'Minimum deposit amount is ' . format_price($minDeposit)];
        }

        $pdo = Database::getConnection();
        $ref = 'PAY-' . strtoupper(substr($gateway, 0, 3)) . '-' . time() . '-' . mt_rand(1000, 9999);

        $stmt = $pdo->prepare("INSERT INTO payments (user_id, gateway, amount, status, transaction_ref) VALUES (?, ?, ?, 'pending', ?)");
        $stmt->execute([$userId, $gateway, $amount, $ref]);
        $paymentId = (int)$pdo->lastInsertId();

        // 1. UPI / Manual Transfer Checkout
        if ($gateway === 'upi_manual') {
            return [
                'success'      => true,
                'payment_id'   => $paymentId,
                'ref'          => $ref,
                'redirect_url' => "/user/payment.php?id={$paymentId}"
            ];
        }

        // 2. Cryptomus Crypto Gateway
        if ($gateway === 'cryptomus') {
            $apiKey = get_setting('cryptomus_api_key', '');
            $merchantId = get_setting('cryptomus_merchant_id', '');
            if (empty($apiKey) || empty($merchantId)) {
                return [
                    'success' => false,
                    'error'   => 'Cryptomus payment gateway credentials are not configured in Admin Settings.'
                ];
            }

            $appUrl = (getenv('APP_URL') ?: 'http://localhost:3000');
            $data = [
                'amount'       => number_format($amount, 2, '.', ''),
                'currency'     => 'USD',
                'order_id'     => $ref,
                'url_return'   => $appUrl . "/user/wallet.php?payment_success={$ref}",
                'url_callback' => $appUrl . "/api.php?action=cryptomus_webhook"
            ];
            $sign = md5(base64_encode(json_encode($data)) . $apiKey);

            $ch = curl_init('https://api.cryptomus.com/v1/payment');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'merchant: ' . $merchantId,
                'sign: ' . $sign,
                'Content-Type: application/json'
            ]);
            $res = curl_exec($ch);
            curl_close($ch);
            $resp = json_decode((string)$res, true);

            if (!empty($resp['result']['url'])) {
                return [
                    'success'      => true,
                    'payment_id'   => $paymentId,
                    'redirect_url' => $resp['result']['url']
                ];
            }

            return ['success' => false, 'error' => $resp['message'] ?? 'Unable to connect to Cryptomus payment service.'];
        }

        // 3. Stripe Checkout Gateway
        if ($gateway === 'stripe') {
            $secretKey = get_setting('stripe_secret_key', '');
            if (empty($secretKey)) {
                return [
                    'success' => false,
                    'error'   => 'Stripe gateway credentials are not configured in Admin Settings.'
                ];
            }

            $appUrl = (getenv('APP_URL') ?: 'http://localhost:3000');
            $ch = curl_init('https://api.stripe.com/v1/checkout/sessions');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_USERPWD, $secretKey . ':');
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'payment_method_types' => ['card'],
                'line_items' => [[
                    'price_data' => [
                        'currency'     => 'usd',
                        'product_data' => ['name' => 'NumVault Account Balance Top Up'],
                        'unit_amount'  => (int)round($amount * 100),
                    ],
                    'quantity' => 1,
                ]],
                'mode'        => 'payment',
                'client_reference_id' => $ref,
                'success_url' => $appUrl . "/user/wallet.php?payment_success={$ref}",
                'cancel_url'  => $appUrl . "/user/wallet.php?payment_cancel={$ref}",
            ]));
            $res = curl_exec($ch);
            curl_close($ch);
            $resp = json_decode((string)$res, true);

            if (!empty($resp['url'])) {
                return [
                    'success'      => true,
                    'payment_id'   => $paymentId,
                    'redirect_url' => $resp['url']
                ];
            }

            return ['success' => false, 'error' => $resp['error']['message'] ?? 'Stripe checkout initialization failed.'];
        }

        return ['success' => false, 'error' => 'Unsupported payment gateway requested.'];
    }

    /**
     * Atomically approve and credit user wallet in a database transaction
     */
    public static function approvePayment(int $adminId, int $paymentId): array {
        $pdo = Database::getConnection();

        try {
            $pdo->beginTransaction();

            $pStmt = $pdo->prepare("SELECT * FROM payments WHERE id = ? FOR UPDATE");
            $pStmt->execute([$paymentId]);
            $payment = $pStmt->fetch();

            if (!$payment) {
                $pdo->rollBack();
                return ['success' => false, 'error' => "Payment #{$paymentId} not found."];
            }
            if ($payment['status'] === 'completed') {
                $pdo->rollBack();
                return ['success' => false, 'error' => "Payment #{$paymentId} was already approved and credited."];
            }

            $amount = (float)$payment['amount'];
            $userId = (int)$payment['user_id'];

            // Lock and fetch user
            $uStmt = $pdo->prepare("SELECT balance, username FROM users WHERE id = ? FOR UPDATE");
            $uStmt->execute([$userId]);
            $user = $uStmt->fetch();

            if (!$user) {
                $pdo->rollBack();
                return ['success' => false, 'error' => "User account #{$userId} not found."];
            }

            $balBefore = (float)$user['balance'];
            $balAfter  = $balBefore + $amount;

            // 1. Credit User Balance
            $updUser = $pdo->prepare("UPDATE users SET balance = ? WHERE id = ?");
            $updUser->execute([$balAfter, $userId]);

            // 2. Mark payment completed
            $updPay = $pdo->prepare("UPDATE payments SET status = 'completed', updated_at = NOW() WHERE id = ?");
            $updPay->execute([$paymentId]);

            // 3. Record immutable ledger transaction
            record_wallet_tx(
                $pdo,
                $userId,
                'credit',
                $amount,
                $balBefore,
                $balAfter,
                "Deposit approved ({$payment['gateway']})",
                $payment['transaction_ref'],
                null,
                $paymentId
            );

            // 4. Log audit & send customer alert
            log_audit($adminId, 'admin_payment_approved', "Approved payment #{$paymentId} of {$amount} for user #{$userId}");
            create_notification(
                $userId,
                "Deposit of " . format_price($amount) . " Verified!",
                "Your payment #{$payment['transaction_ref']} has been verified and added to your wallet balance.",
                'wallet'
            );

            $pdo->commit();

            return [
                'success' => true,
                'message' => "Payment #{$paymentId} approved and " . format_price($amount) . " credited to {$user['username']}."
            ];

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            Logger::error("Approve payment error #{$paymentId}: " . $e->getMessage());
            return ['success' => false, 'error' => 'Error approving payment: ' . $e->getMessage()];
        }
    }
}
