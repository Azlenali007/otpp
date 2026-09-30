<?php
/**
 * NumVault - Administrator Custom SMS Provider Management
 * Full integration with 5SIM (https://5sim.net/docs#user) and Custom REST Gateways
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Providers\ProviderFactory;
use App\Core\Logger;

$admin = require_admin();
$pageTitle = "Custom SMS Provider Gateways";

$pdo = get_db();
$error = '';

// Handle Provider Add/Edit/Test
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = $_POST['action'] ?? '';

    // 1. TEST CONNECTION & BALANCE SYNC
    if ($action === 'test_connection' || $action === 'check_balance') {
        $providerId = (int)($_POST['provider_id'] ?? 0);
        $provider = ProviderFactory::get($providerId);
        if ($provider) {
            $testRes = $provider->testConnection();
            if (!empty($testRes['success'])) {
                $balance = (float)($testRes['balance'] ?? 0.00);
                $upd = $pdo->prepare("UPDATE providers SET balance = ?, last_check = NOW() WHERE id = ?");
                $upd->execute([$balance, $providerId]);

                $details = "Balance: " . format_price($balance);
                if (!empty($testRes['email'])) {
                    $details .= " | Account: " . e((string)$testRes['email']);
                }
                if (isset($testRes['rating'])) {
                    $details .= " | Rating: " . e((string)$testRes['rating']);
                }
                set_flash('success', "✓ Provider Connected Successfully! {$details}");
            } else {
                $errMsg = $testRes['error'] ?? 'Authentication failed.';
                set_flash('error', "✗ Provider Connection Failed: {$errMsg}");
            }
        } else {
            set_flash('error', 'Provider adapter could not be initialized.');
        }
        header('Location: /admin/providers.php');
        exit;
    }

    // 2. TOGGLE ENABLE / DISABLE
    elseif ($action === 'toggle_status') {
        $providerId = (int)($_POST['provider_id'] ?? 0);
        $pdo->exec("UPDATE providers SET is_enabled = IF(is_enabled=1, 0, 1) WHERE id = {$providerId}");
        set_flash('success', "Provider status updated.");
        header('Location: /admin/providers.php');
        exit;
    }

    // 3. DELETE PROVIDER
    elseif ($action === 'delete_provider') {
        $providerId = (int)($_POST['provider_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT name FROM providers WHERE id = ?");
        $stmt->execute([$providerId]);
        $p = $stmt->fetch();

        if ($p) {
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM provider_country_mappings WHERE provider_id = ?")->execute([$providerId]);
            $pdo->prepare("DELETE FROM provider_service_mappings WHERE provider_id = ?")->execute([$providerId]);
            $pdo->prepare("DELETE FROM servers WHERE provider_id = ?")->execute([$providerId]);
            $pdo->prepare("DELETE FROM providers WHERE id = ?")->execute([$providerId]);
            $pdo->commit();

            log_audit($admin['id'], 'admin_provider_deleted', "Deleted provider {$p['name']}");
            set_flash('success', "Provider '{$p['name']}' removed.");
        }
        header('Location: /admin/providers.php');
        exit;
    }

    // 4. SAVE CUSTOM PROVIDER CONFIGURATION
    elseif ($action === 'save_provider') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $apiUrl = trim($_POST['api_url'] ?? '');
        $apiKey = trim($_POST['api_key'] ?? '');
        $priority = (int)($_POST['priority'] ?? 1);
        $currency = strtoupper(trim($_POST['currency'] ?? 'USD'));
        $isEnabled = !empty($_POST['is_enabled']) ? 1 : 0;

        // Clean and format base URL correctly without altering hostname or adding $
        $apiUrl = str_replace('$', '', $apiUrl);
        $apiUrl = preg_replace('#^https?://api1\.5sim\.net#i', 'https://5sim.net', $apiUrl);
        if (!preg_match('#^https?://#i', $apiUrl) && !empty($apiUrl)) {
            $apiUrl = 'https://' . ltrim($apiUrl, '/');
        }
        $apiUrl = rtrim($apiUrl, '/');

        if (empty($name) || empty($apiUrl)) {
            $error = 'Provider name and API Base URL (e.g. https://5sim.net) are required.';
        } else {
            try {
                // Ensure unique slug
                $originalSlug = 'custom_' . preg_replace('/[^a-z0-9]/', '', strtolower($name));
                if (strlen($originalSlug) < 8) $originalSlug = 'custom_provider';
                $slug = $originalSlug;
                $counter = 1;
                while (true) {
                    $check = $pdo->prepare("SELECT id FROM providers WHERE slug = ? AND id != ?");
                    $check->execute([$slug, $id]);
                    if (!$check->fetch()) break;
                    $counter++;
                    $slug = $originalSlug . '_' . $counter;
                }

                if ($id > 0) {
                    if (empty($apiKey)) {
                        $upd = $pdo->prepare("
                            UPDATE providers 
                            SET name = ?, slug = ?, api_url = ?, priority = ?, currency = ?, is_enabled = ?
                            WHERE id = ?
                        ");
                        $upd->execute([$name, $slug, $apiUrl, $priority, $currency, $isEnabled, $id]);
                    } else {
                        $upd = $pdo->prepare("
                            UPDATE providers 
                            SET name = ?, slug = ?, api_url = ?, api_key = ?, priority = ?, currency = ?, is_enabled = ?
                            WHERE id = ?
                        ");
                        $upd->execute([$name, $slug, $apiUrl, $apiKey, $priority, $currency, $isEnabled, $id]);
                    }
                    log_audit($admin['id'], 'admin_provider_updated', "Updated provider '{$name}'");
                    set_flash('success', "Custom Provider '{$name}' updated successfully.");
                } else {
                    $ins = $pdo->prepare("
                        INSERT INTO providers (name, slug, api_url, api_key, priority, currency, is_enabled)
                        VALUES (?, ?, ?, ?, ?, ?, ?)
                    ");
                    $ins->execute([$name, $slug, $apiUrl, $apiKey, $priority, $currency, $isEnabled]);
                    log_audit($admin['id'], 'admin_provider_created', "Configured new provider '{$name}'");
                    set_flash('success', "Custom Provider '{$name}' added successfully.");
                }
                header('Location: /admin/providers.php');
                exit;
            } catch (PDOException $e) {
                Logger::error("Failed saving custom provider '{$name}': " . $e->getMessage());
                $error = 'Database error saving provider: ' . $e->getMessage();
            }
        }
    }
}

// Fetch all providers
$providers = $pdo->query("SELECT * FROM providers ORDER BY priority ASC, id ASC")->fetchAll();

// Edit mode check
$editProvider = null;
if (isset($_GET['edit'])) {
    $eId = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM providers WHERE id = ?");
    $stmt->execute([$eId]);
    $editProvider = $stmt->fetch();
}

require_once __DIR__ . '/../app/layouts/admin_header.php';
?>

<div class="space-y-6">

    <!-- Top Action Bar -->
    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase tracking-wide bg-blue-50 text-blue-700 border border-blue-200">
                    Carrier API Management
                </span>
                <span class="text-xs text-slate-400 font-mono">5SIM &amp; Custom REST Protocol</span>
            </div>
            <h1 class="text-xl font-extrabold text-slate-900 tracking-tight mt-1">SMS Gateway Providers</h1>
            <p class="text-xs text-slate-500 mt-0.5">Configure 5SIM or Custom SMS supplier endpoints, Bearer API credentials, and live balances</p>
        </div>

        <div class="flex items-center gap-2">
            <?php if (!empty($providers)): ?>
                <a href="/admin/provider_import.php" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold shadow-xs transition-colors flex items-center gap-1.5">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                    Import Countries &amp; Services
                </a>
            <?php endif; ?>
            <a href="/admin/providers.php?action=new" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold shadow-xs transition-colors flex items-center gap-1">
                + Add Custom Provider
            </a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-medium flex items-center gap-2">
            <svg class="w-4 h-4 flex-shrink-0 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
            <span><?= e($error) ?></span>
        </div>
    <?php endif; ?>

    <!-- Add / Edit Provider Card -->
    <?php if ($editProvider !== null || (isset($_GET['action']) && $_GET['action'] === 'new')): ?>
        <div class="bg-white rounded-2xl border border-blue-200 shadow-md p-6 space-y-4">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <div>
                    <h2 class="text-sm font-bold text-slate-900">
                        <?= $editProvider ? 'Edit Custom Provider: ' . e($editProvider['name']) : 'Register Custom SMS Provider' ?>
                    </h2>
                    <p class="text-[11px] text-slate-500">Configure official 5SIM (https://5sim.net) or custom carrier API credentials</p>
                </div>
                <a href="/admin/providers.php" class="text-xs text-slate-400 hover:text-slate-600 font-bold">&times; Cancel</a>
            </div>

            <form method="POST" action="/admin/providers.php" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_provider">
                <input type="hidden" name="id" value="<?= (int)($editProvider['id'] ?? 0) ?>">

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Provider Display Name</label>
                        <input type="text" name="name" required value="<?= e($editProvider['name'] ?? '5SIM Provider') ?>" placeholder="e.g. 5SIM Provider" class="w-full text-xs px-3.5 py-2.5 rounded-xl border border-slate-300 font-bold text-slate-900">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Dispatch Priority (1 = Highest)</label>
                        <input type="number" name="priority" min="1" max="100" value="<?= (int)($editProvider['priority'] ?? 1) ?>" class="w-full text-xs px-3.5 py-2.5 rounded-xl border border-slate-300 font-mono text-slate-900">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">
                            API Base URL
                            <span class="text-slate-400 font-normal ml-1">(For 5SIM enter: https://5sim.net)</span>
                        </label>
                        <input type="text" name="api_url" required value="<?= e($editProvider['api_url'] ?? 'https://5sim.net') ?>" placeholder="https://5sim.net" class="w-full text-xs px-3.5 py-2.5 rounded-xl border border-slate-300 font-mono text-slate-900">
                        <p class="text-[10px] text-slate-400 mt-1">Official base URL without path mutations. Documented endpoints (/v1/user/profile, /v1/guest/prices) are joined automatically.</p>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">
                            API Secret / Bearer Token
                            <?= $editProvider ? '<span class="text-slate-400 font-normal">(Leave blank to preserve current)</span>' : '' ?>
                        </label>
                        <input type="password" name="api_key" placeholder="<?= $editProvider && !empty($editProvider['api_key']) ? mask_secret($editProvider['api_key']) : 'Enter provider API token' ?>" class="w-full text-xs px-3.5 py-2.5 rounded-xl border border-slate-300 font-mono text-slate-900">
                        <p class="text-[10px] text-slate-400 mt-1">Sent via header: <code class="bg-slate-100 px-1 py-0.5 rounded text-slate-600">Authorization: Bearer {TOKEN}</code> with <code class="bg-slate-100 px-1 py-0.5 rounded text-slate-600">Accept: application/json</code></p>
                    </div>
                </div>

                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pt-2 border-t border-slate-100">
                    <div class="flex items-center gap-6">
                        <label class="flex items-center gap-2 text-xs font-semibold text-slate-700 cursor-pointer">
                            <input type="checkbox" name="is_enabled" value="1" <?= empty($editProvider) || !empty($editProvider['is_enabled']) ? 'checked' : '' ?> class="w-4 h-4 text-blue-600 rounded">
                            <span>Provider is Active and Accepting Purchases</span>
                        </label>

                        <div class="flex items-center gap-2 text-xs">
                            <span class="font-semibold text-slate-700">Currency:</span>
                            <input type="text" name="currency" value="<?= e($editProvider['currency'] ?? 'USD') ?>" class="w-20 text-xs px-2.5 py-1.5 border border-slate-300 rounded-lg font-mono font-bold text-slate-800">
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <a href="/admin/providers.php" class="px-4 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-xs font-bold rounded-xl transition-colors">Cancel</a>
                        <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-xl shadow-xs transition-colors">
                            Save Provider Configuration
                        </button>
                    </div>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <!-- Providers List -->
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <?php if (!empty($providers)): ?>
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-slate-50 text-slate-500 font-semibold border-b border-slate-100">
                        <tr>
                            <th class="px-5 py-3">Priority</th>
                            <th class="px-5 py-3">Gateway Name</th>
                            <th class="px-5 py-3">API Base URL</th>
                            <th class="px-5 py-3">Authentication Status</th>
                            <th class="px-5 py-3">Reported Balance</th>
                            <th class="px-5 py-3">Status</th>
                            <th class="px-5 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 font-medium">
                        <?php foreach ($providers as $p): ?>
                            <tr class="hover:bg-slate-50/50 transition-colors">
                                <td class="px-5 py-3.5 font-mono text-slate-400 font-bold"><?= (int)$p['priority'] ?></td>
                                <td class="px-5 py-3.5 font-bold text-slate-900">
                                    <div class="flex items-center gap-2">
                                        <span><?= e($p['name']) ?></span>
                                        <span class="text-[10px] px-1.5 py-0.5 rounded bg-slate-100 font-mono text-slate-500"><?= e($p['currency'] ?? 'USD') ?></span>
                                    </div>
                                </td>
                                <td class="px-5 py-3.5 font-mono text-slate-600 text-xs">
                                    <?= e($p['api_url']) ?>
                                </td>
                                <td class="px-5 py-3.5 font-mono">
                                    <?php if (!empty($p['api_key'])): ?>
                                        <span class="text-emerald-700 bg-emerald-50 px-2.5 py-0.5 rounded border border-emerald-200 font-bold text-[10px] inline-flex items-center gap-1">
                                            <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                            Bearer <?= mask_secret($p['api_key'], 3) ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-rose-700 bg-rose-50 px-2 py-0.5 rounded border border-rose-200 font-bold text-[10px]">
                                            Missing API Token
                                        </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-3.5 font-mono">
                                    <span class="font-extrabold text-slate-900 text-sm">
                                        <?= number_format((float)($p['balance'] ?? 0), 2) ?> <?= e($p['currency'] ?? 'USD') ?>
                                    </span>
                                    <?php if (!empty($p['last_check'])): ?>
                                        <div class="text-[10px] text-slate-400 font-normal">
                                            Checked <?= time_ago($p['last_check']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-5 py-3.5">
                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= $p['is_enabled'] ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-500' ?>">
                                        <?= $p['is_enabled'] ? 'ACTIVE' : 'DISABLED' ?>
                                    </span>
                                </td>
                                <td class="px-5 py-3.5 text-right space-x-1">
                                    <!-- Test Connection Button -->
                                    <form method="POST" action="/admin/providers.php" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="test_connection">
                                        <input type="hidden" name="provider_id" value="<?= $p['id'] ?>">
                                        <button type="submit" class="px-2.5 py-1.5 bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold rounded-lg transition-colors" title="Test live connection to /v1/user/profile">
                                            Test Connection
                                        </button>
                                    </form>

                                    <!-- Import API Button -->
                                    <a href="/admin/provider_import.php?provider_id=<?= $p['id'] ?>" class="px-2.5 py-1.5 bg-emerald-50 hover:bg-emerald-100 text-emerald-700 font-bold rounded-lg transition-colors" title="Import countries and services directly from this provider API">
                                        Import API
                                    </a>

                                    <!-- Edit Button -->
                                    <a href="/admin/providers.php?edit=<?= $p['id'] ?>" class="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-lg transition-colors">
                                        Edit
                                    </a>

                                    <!-- Toggle Status -->
                                    <form method="POST" action="/admin/providers.php" class="inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="toggle_status">
                                        <input type="hidden" name="provider_id" value="<?= $p['id'] ?>">
                                        <button type="submit" class="px-2 py-1.5 <?= $p['is_enabled'] ? 'bg-slate-100 text-slate-600 hover:bg-slate-200' : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100' ?> font-bold rounded-lg transition-colors">
                                            <?= $p['is_enabled'] ? 'Disable' : 'Enable' ?>
                                        </button>
                                    </form>

                                    <!-- Delete Button -->
                                    <form method="POST" action="/admin/providers.php" class="inline" onsubmit="return confirm('Are you sure you want to delete this provider? Associated country mappings and server routes will also be removed.');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="delete_provider">
                                        <input type="hidden" name="provider_id" value="<?= $p['id'] ?>">
                                        <button type="submit" class="px-2 py-1.5 bg-rose-50 hover:bg-rose-100 text-rose-700 font-bold rounded-lg transition-colors">
                                            Delete
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <!-- Clean Empty State -->
            <div class="p-12 text-center space-y-4">
                <div class="w-16 h-16 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center mx-auto">
                    <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
                </div>
                <div>
                    <h3 class="text-base font-extrabold text-slate-900">No Providers Configured</h3>
                    <p class="text-xs text-slate-500 max-w-md mx-auto mt-1">Connect your 5SIM account or Custom SMS API gateway using your API Base URL and Bearer token.</p>
                </div>
                <div>
                    <a href="/admin/providers.php?action=new" class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold shadow-xs transition-colors">
                        + Add Custom Provider
                    </a>
                </div>
            </div>
        <?php endif; ?>
    </div>

</div>

<?php require_once __DIR__ . '/../app/layouts/admin_footer.php'; ?>
