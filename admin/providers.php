<?php
/**
 * NumVault - Administrator SMS Provider Management
 * Real-time balance polling, API credentials, and multi-provider adapter configuration
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Providers\ProviderFactory;

$admin = require_admin();
$pageTitle = "SMS Provider Gateways";

$pdo = get_db();
$error = '';

// Handle Provider Add/Edit/Balance Check
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = $_POST['action'] ?? '';

    // Check Balance
    if ($action === 'check_balance') {
        $providerId = (int)($_POST['provider_id'] ?? 0);
        $provider = ProviderFactory::get($providerId);
        if ($provider) {
            $balance = $provider->getBalance();
            $upd = $pdo->prepare("UPDATE providers SET balance = ?, last_check = NOW() WHERE id = ?");
            $upd->execute([$balance, $providerId]);
            set_flash('success', "Balance fetched from provider gateway: {$balance}");
        } else {
            set_flash('error', 'Provider adapter could not be initialized.');
        }
        header('Location: /admin/providers.php');
        exit;
    }

    // Toggle Enable / Disable
    elseif ($action === 'toggle_status') {
        $providerId = (int)($_POST['provider_id'] ?? 0);
        $pdo->exec("UPDATE providers SET is_enabled = IF(is_enabled=1, 0, 1) WHERE id = {$providerId}");
        set_flash('success', "Provider status toggled.");
        header('Location: /admin/providers.php');
        exit;
    }

    // Save Provider Settings
    elseif ($action === 'save_provider') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $slug = trim($_POST['slug'] ?? '');
        $apiUrl = trim($_POST['api_url'] ?? '');
        $apiKey = trim($_POST['api_key'] ?? '');
        $priority = (int)($_POST['priority'] ?? 1);
        $currency = trim($_POST['currency'] ?? 'USD');
        $isEnabled = !empty($_POST['is_enabled']) ? 1 : 0;

        if (empty($name) || empty($slug) || empty($apiUrl)) {
            $error = 'Provider name, slug, and API endpoint URL are required.';
        } else {
            if ($id > 0) {
                // If API key is not modified, keep current key
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
                set_flash('success', "Provider {$name} updated successfully.");
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO providers (name, slug, api_url, api_key, priority, currency, is_enabled)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([$name, $slug, $apiUrl, $apiKey, $priority, $currency, $isEnabled]);
                log_audit($admin['id'], 'admin_provider_created', "Added new provider '{$name}'");
                set_flash('success', "Provider {$name} created successfully.");
            }
            header('Location: /admin/providers.php');
            exit;
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

    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-extrabold text-slate-900 tracking-tight">SMS Gateway Providers</h1>
            <p class="text-xs text-slate-500 mt-0.5">Manage external virtual number supplier endpoints and credentials</p>
        </div>
        <a href="/admin/providers.php?action=new" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold shadow-xs transition-colors">
            + Add Provider
        </a>
    </div>

    <?php if ($error): ?>
        <div class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-medium">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <!-- Add / Edit Provider Card -->
    <?php if ($editProvider !== null || isset($_GET['action']) && $_GET['action'] === 'new'): ?>
        <div class="bg-white rounded-2xl border border-blue-200 shadow-md p-6 space-y-4">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <h2 class="text-sm font-bold text-slate-900">
                    <?= $editProvider ? 'Edit Provider: ' . e($editProvider['name']) : 'Register New SMS Provider' ?>
                </h2>
                <a href="/admin/providers.php" class="text-xs text-slate-400 hover:text-slate-600 font-bold">&times; Cancel</a>
            </div>

            <form method="POST" action="/admin/providers.php" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_provider">
                <input type="hidden" name="id" value="<?= (int)($editProvider['id'] ?? 0) ?>">

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Provider Name</label>
                        <input type="text" name="name" required value="<?= e($editProvider['name'] ?? '') ?>" placeholder="e.g. SMS-Activate Gateway" class="w-full text-xs px-3.5 py-2 rounded-xl border border-slate-300">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Adapter Slug</label>
                        <select name="slug" required class="w-full text-xs px-3.5 py-2 rounded-xl border border-slate-300">
                            <option value="sms_activate" <?= ($editProvider['slug'] ?? '') === 'sms_activate' ? 'selected' : '' ?>>SMS-Activate Adapter</option>
                            <option value="5sim" <?= ($editProvider['slug'] ?? '') === '5sim' ? 'selected' : '' ?>>5SIM Direct Adapter</option>
                            <option value="daisysms" <?= ($editProvider['slug'] ?? '') === 'daisysms' ? 'selected' : '' ?>>DaisySMS Adapter</option>
                            <option value="sms_man" <?= ($editProvider['slug'] ?? '') === 'sms_man' ? 'selected' : '' ?>>SMS-Man Adapter</option>
                            <option value="custom" <?= ($editProvider['slug'] ?? '') === 'custom' ? 'selected' : '' ?>>Custom REST API</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Dispatch Priority (1 = Highest)</label>
                        <input type="number" name="priority" min="1" max="100" value="<?= (int)($editProvider['priority'] ?? 1) ?>" class="w-full text-xs px-3.5 py-2 rounded-xl border border-slate-300">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">API Base URL</label>
                        <input type="text" name="api_url" required value="<?= e($editProvider['api_url'] ?? '') ?>" placeholder="https://api.sms-activate.org/stubs/handler_api.php" class="w-full text-xs px-3.5 py-2 rounded-xl border border-slate-300 font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">
                            API Secret Key <?= $editProvider ? '<span class="text-slate-400 font-normal">(Leave blank to keep current)</span>' : '' ?>
                        </label>
                        <input type="password" name="api_key" placeholder="<?= $editProvider && !empty($editProvider['api_key']) ? mask_secret($editProvider['api_key']) : 'Enter provider API key' ?>" class="w-full text-xs px-3.5 py-2 rounded-xl border border-slate-300 font-mono">
                    </div>
                </div>

                <div class="flex items-center gap-6 pt-2">
                    <label class="flex items-center gap-2 text-xs font-semibold text-slate-700 cursor-pointer">
                        <input type="checkbox" name="is_enabled" value="1" <?= empty($editProvider) || !empty($editProvider['is_enabled']) ? 'checked' : '' ?> class="w-4 h-4 text-blue-600 rounded">
                        <span>Provider is Active and Accepting Purchases</span>
                    </label>

                    <div class="flex items-center gap-2 text-xs">
                        <span class="font-semibold text-slate-700">Currency:</span>
                        <input type="text" name="currency" value="<?= e($editProvider['currency'] ?? 'USD') ?>" class="w-16 text-xs px-2 py-1 border rounded font-mono">
                    </div>
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <a href="/admin/providers.php" class="px-4 py-2 bg-slate-100 text-slate-700 text-xs font-bold rounded-xl">Cancel</a>
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-xl shadow-xs">
                        Save Provider Configuration
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <!-- Providers List Card -->
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-slate-50 text-slate-500 font-semibold border-b border-slate-100">
                    <tr>
                        <th class="px-5 py-3">Priority</th>
                        <th class="px-5 py-3">Gateway Name</th>
                        <th class="px-5 py-3">Adapter Slug</th>
                        <th class="px-5 py-3">API URL</th>
                        <th class="px-5 py-3">API Key Status</th>
                        <th class="px-5 py-3">Reported Balance</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php foreach ($providers as $p): ?>
                        <tr class="hover:bg-slate-50/50 transition-colors">
                            <td class="px-5 py-3.5 font-mono text-slate-400 font-bold"><?= (int)$p['priority'] ?></td>
                            <td class="px-5 py-3.5 font-bold text-slate-900"><?= e($p['name']) ?></td>
                            <td class="px-5 py-3.5 font-mono text-blue-600"><?= e($p['slug']) ?></td>
                            <td class="px-5 py-3.5 font-mono text-slate-500 text-[11px] truncate max-w-xs"><?= e($p['api_url']) ?></td>
                            <td class="px-5 py-3.5 font-mono">
                                <?php if (!empty($p['api_key'])): ?>
                                    <span class="text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded border border-emerald-200 font-bold text-[10px]">
                                        <?= mask_secret($p['api_key'], 3) ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-rose-600 bg-rose-50 px-2 py-0.5 rounded border border-rose-200 text-[10px] font-bold">NOT SET</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-5 py-3.5 font-mono font-bold text-slate-900">
                                <?= number_format((float)$p['balance'], 2) ?> <?= e($p['currency']) ?>
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= $p['is_enabled'] ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-500' ?>">
                                    <?= $p['is_enabled'] ? 'ENABLED' : 'DISABLED' ?>
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-right space-x-1.5">
                                <!-- Check Balance Button -->
                                <form method="POST" action="/admin/providers.php" class="inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="check_balance">
                                    <input type="hidden" name="provider_id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="px-2.5 py-1.5 bg-blue-50 hover:bg-blue-100 text-blue-700 font-bold rounded-lg transition-colors" title="Sync live balance from provider API">
                                        Sync Bal
                                    </button>
                                </form>

                                <!-- Edit Button -->
                                <a href="/admin/providers.php?edit=<?= $p['id'] ?>" class="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-lg transition-colors">
                                    Edit
                                </a>

                                <!-- Toggle Status -->
                                <form method="POST" action="/admin/providers.php" class="inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="provider_id" value="<?= $p['id'] ?>">
                                    <button type="submit" class="px-2.5 py-1.5 <?= $p['is_enabled'] ? 'bg-rose-50 text-rose-700 hover:bg-rose-100' : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100' ?> font-bold rounded-lg transition-colors">
                                        <?= $p['is_enabled'] ? 'Disable' : 'Enable' ?>
                                    </button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../app/layouts/admin_footer.php'; ?>
