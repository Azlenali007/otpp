<?php
/**
 * NumVault - Administrator System Settings
 * Branding, payment gateway credentials, and automated refund parameters
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$admin = require_admin();
$pageTitle = "System Configuration";

$pdo = get_db();
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $settingsKeys = [
        'site_name',
        'site_description',
        'currency_symbol',
        'order_timeout_minutes',
        'min_deposit',
        'telegram_support',
        'support_email',
        'maintenance_mode',
        'upi_id',
        'manual_bank_details'
    ];

    foreach ($settingsKeys as $key) {
        if (isset($_POST[$key])) {
            set_setting($key, trim($_POST[$key]));
        }
    }

    // Only update secret keys if non-empty input is provided (preserves existing secrets)
    if (!empty($_POST['cryptomus_api_key'])) {
        set_setting('cryptomus_api_key', trim($_POST['cryptomus_api_key']));
    }
    if (!empty($_POST['cryptomus_merchant_id'])) {
        set_setting('cryptomus_merchant_id', trim($_POST['cryptomus_merchant_id']));
    }
    if (!empty($_POST['stripe_secret_key'])) {
        set_setting('stripe_secret_key', trim($_POST['stripe_secret_key']));
    }
    if (!empty($_POST['stripe_publishable_key'])) {
        set_setting('stripe_publishable_key', trim($_POST['stripe_publishable_key']));
    }

    log_audit($admin['id'], 'admin_settings_updated', 'Updated system settings & payment gateway credentials');
    set_flash('success', 'System settings saved successfully.');
    header('Location: /admin/settings.php');
    exit;
}

require_once __DIR__ . '/../app/layouts/admin_header.php';
?>

<div class="max-w-4xl mx-auto space-y-6">

    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs flex items-center justify-between">
        <div>
            <h1 class="text-xl font-extrabold text-slate-900 tracking-tight">System Configuration Hub</h1>
            <p class="text-xs text-slate-500 mt-0.5">Manage global parameters, payment keys, and customer service endpoints</p>
        </div>
    </div>

    <form method="POST" action="/admin/settings.php" class="space-y-6">
        <?= csrf_field() ?>

        <!-- Brand & Platform -->
        <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs space-y-4">
            <h2 class="text-sm font-bold text-slate-900 border-b border-slate-100 pb-2">Branding & Marketplace Identity</h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Platform Name</label>
                    <input type="text" name="site_name" value="<?= e(get_setting('site_name', 'NumVault')) ?>" required class="w-full text-xs px-3.5 py-2.5 rounded-xl border border-slate-300">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Currency Symbol</label>
                    <input type="text" name="currency_symbol" value="<?= e(get_setting('currency_symbol', '$')) ?>" required class="w-full text-xs px-3.5 py-2.5 rounded-xl border border-slate-300 font-mono">
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-slate-700 mb-1">Tagline / Meta Description</label>
                <input type="text" name="site_description" value="<?= e(get_setting('site_description', 'Real-time Virtual Numbers & Automated SMS OTP Verification Platform')) ?>" class="w-full text-xs px-3.5 py-2.5 rounded-xl border border-slate-300">
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Order Timeout (Minutes until Auto-Refund)</label>
                    <input type="number" name="order_timeout_minutes" min="1" max="60" value="<?= e(get_setting('order_timeout_minutes', '5')) ?>" required class="w-full text-xs px-3.5 py-2.5 rounded-xl border border-slate-300 font-mono">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Minimum Deposit Amount ($)</label>
                    <input type="number" step="0.01" min="0.50" name="min_deposit" value="<?= e(get_setting('min_deposit', '1.00')) ?>" required class="w-full text-xs px-3.5 py-2.5 rounded-xl border border-slate-300 font-mono">
                </div>
            </div>
        </div>

        <!-- Payment Gateway Secrets -->
        <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs space-y-4">
            <h2 class="text-sm font-bold text-slate-900 border-b border-slate-100 pb-2">Payment Gateways & Secrets (Zero Frontend Exposure)</h2>

            <div class="space-y-4">
                <!-- Cryptomus -->
                <div class="p-4 rounded-xl border border-slate-200 bg-slate-50 space-y-3">
                    <div class="text-xs font-bold text-slate-800 uppercase tracking-wider">Cryptomus Crypto Gateway</div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Merchant UUID</label>
                            <input type="text" name="cryptomus_merchant_id" value="<?= e(get_setting('cryptomus_merchant_id', '')) ?>" placeholder="Enter merchant ID" class="w-full text-xs px-3 py-2 rounded-lg border font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">
                                Payment API Key <span class="text-slate-400 font-normal">(Masked: <?= mask_secret(get_setting('cryptomus_api_key', '')) ?: 'None' ?>)</span>
                            </label>
                            <input type="password" name="cryptomus_api_key" placeholder="Enter new API key to change" class="w-full text-xs px-3 py-2 rounded-lg border font-mono">
                        </div>
                    </div>
                </div>

                <!-- Stripe -->
                <div class="p-4 rounded-xl border border-slate-200 bg-slate-50 space-y-3">
                    <div class="text-xs font-bold text-slate-800 uppercase tracking-wider">Stripe Card Gateway</div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">Publishable Key</label>
                            <input type="text" name="stripe_publishable_key" value="<?= e(get_setting('stripe_publishable_key', '')) ?>" placeholder="pk_live_..." class="w-full text-xs px-3 py-2 rounded-lg border font-mono">
                        </div>
                        <div>
                            <label class="block text-[11px] font-semibold text-slate-600 mb-1">
                                Secret Key <span class="text-slate-400 font-normal">(Masked: <?= mask_secret(get_setting('stripe_secret_key', '')) ?: 'None' ?>)</span>
                            </label>
                            <input type="password" name="stripe_secret_key" placeholder="Enter new sk_live_... to change" class="w-full text-xs px-3 py-2 rounded-lg border font-mono">
                        </div>
                    </div>
                </div>

                <!-- UPI & Bank Transfer -->
                <div class="p-4 rounded-xl border border-slate-200 bg-slate-50 space-y-3">
                    <div class="text-xs font-bold text-slate-800 uppercase tracking-wider">Manual Bank / UPI Transfer</div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">UPI VPA Address</label>
                        <input type="text" name="upi_id" value="<?= e(get_setting('upi_id', 'support@okhdfcbank')) ?>" class="w-full text-xs px-3 py-2 rounded-lg border font-mono">
                    </div>
                    <div>
                        <label class="block text-[11px] font-semibold text-slate-600 mb-1">Wire / Bank Account Information</label>
                        <textarea name="manual_bank_details" rows="3" class="w-full text-xs px-3 py-2 rounded-lg border font-mono"><?= e(get_setting('manual_bank_details', "Standard Chartered Bank\nAccount: 9001234567\nIFSC: SCBL0001")) ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <!-- Support Channels -->
        <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs space-y-4">
            <h2 class="text-sm font-bold text-slate-900 border-b border-slate-100 pb-2">Customer Support Channels</h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Official Telegram Support Handle</label>
                    <input type="text" name="telegram_support" value="<?= e(get_setting('telegram_support', '@numvault_support')) ?>" class="w-full text-xs px-3.5 py-2.5 rounded-xl border border-slate-300 font-mono">
                </div>
                <div>
                    <label class="block text-xs font-semibold text-slate-700 mb-1">Support Email</label>
                    <input type="email" name="support_email" value="<?= e(get_setting('support_email', 'support@numvault.io')) ?>" class="w-full text-xs px-3.5 py-2.5 rounded-xl border border-slate-300">
                </div>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="px-6 py-3 bg-blue-600 hover:bg-blue-700 text-white font-bold text-xs rounded-xl shadow-md shadow-blue-600/20 transition-colors">
                Save All System Settings &rarr;
            </button>
        </div>
    </form>

</div>

<?php require_once __DIR__ . '/../app/layouts/admin_footer.php'; ?>
