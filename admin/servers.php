<?php
/**
 * NumVault - Administrator Multi-Server Routing & Pricing Engine
 * Maps Services + Countries to specific Provider Gateways with custom profit margins
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$admin = require_admin();
$pageTitle = "Servers & Pricing Engine";

$pdo = get_db();
$error = '';

// Handle Server Save / Toggle / Delete
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'save_server') {
        $id = (int)($_POST['id'] ?? 0);
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $countryId = (int)($_POST['country_id'] ?? 0);
        $providerId = (int)($_POST['provider_id'] ?? 0);
        $serverName = trim($_POST['server_name'] ?? '');
        $providerServiceCode = trim($_POST['provider_service_code'] ?? '');
        $providerCountryCode = trim($_POST['provider_country_code'] ?? '');
        $costPrice = (float)($_POST['cost_price'] ?? 0);
        $sellingPrice = (float)($_POST['selling_price'] ?? 0);
        $isEnabled = !empty($_POST['is_enabled']) ? 1 : 0;

        if ($serviceId <= 0 || $countryId <= 0 || $providerId <= 0 || empty($serverName) || $sellingPrice <= 0) {
            $error = 'Service, country, provider, server name, and valid selling price are required.';
        } else {
            if ($id > 0) {
                $upd = $pdo->prepare("
                    UPDATE servers 
                    SET service_id = ?, country_id = ?, provider_id = ?, server_name = ?, 
                        provider_service_code = ?, provider_country_code = ?, cost_price = ?, selling_price = ?, is_enabled = ?
                    WHERE id = ?
                ");
                $upd->execute([$serviceId, $countryId, $providerId, $serverName, $providerServiceCode, $providerCountryCode, $costPrice, $sellingPrice, $isEnabled, $id]);
                log_audit($admin['id'], 'admin_server_updated', "Updated server route #{$id}: {$serverName}");
                set_flash('success', "Server route {$serverName} updated.");
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO servers (service_id, country_id, provider_id, server_name, provider_service_code, provider_country_code, cost_price, selling_price, is_enabled)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $ins->execute([$serviceId, $countryId, $providerId, $serverName, $providerServiceCode, $providerCountryCode, $costPrice, $sellingPrice, $isEnabled]);
                log_audit($admin['id'], 'admin_server_created', "Created server route: {$serverName}");
                set_flash('success', "New server route {$serverName} established.");
            }
            header('Location: /admin/servers.php');
            exit;
        }
    } elseif ($action === 'toggle_status') {
        $id = (int)($_POST['server_id'] ?? 0);
        $pdo->exec("UPDATE servers SET is_enabled = IF(is_enabled=1, 0, 1) WHERE id = {$id}");
        set_flash('success', "Server availability toggled.");
        header('Location: /admin/servers.php');
        exit;
    }
}

// Fetch all servers with join data
$servers = $pdo->query("
    SELECT s.*, 
           sv.name AS service_name, sv.code AS service_code,
           c.name AS country_name, c.code AS country_code, c.prefix AS country_prefix,
           p.name AS provider_name
    FROM servers s
    JOIN services sv ON s.service_id = sv.id
    JOIN countries c ON s.country_id = c.id
    JOIN providers p ON s.provider_id = p.id
    ORDER BY s.service_id ASC, s.country_id ASC, s.selling_price ASC
")->fetchAll();

// Fetch lists for form selects
$allServices = $pdo->query("SELECT id, name FROM services WHERE is_enabled = 1 ORDER BY name ASC")->fetchAll();
$allCountries = $pdo->query("SELECT id, name, prefix FROM countries WHERE is_enabled = 1 ORDER BY name ASC")->fetchAll();
$allProviders = $pdo->query("SELECT id, name FROM providers WHERE is_enabled = 1 ORDER BY name ASC")->fetchAll();

$editServer = null;
if (isset($_GET['edit'])) {
    $eId = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM servers WHERE id = ?");
    $stmt->execute([$eId]);
    $editServer = $stmt->fetch();
}

require_once __DIR__ . '/../app/layouts/admin_header.php';
?>

<div class="space-y-6">

    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-extrabold text-slate-900 tracking-tight">Multi-Server Routing & Margins</h1>
            <p class="text-xs text-slate-500 mt-0.5">Route specific services to optimal wholesale providers and set retail selling prices</p>
        </div>
        <a href="/admin/servers.php?action=new" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold shadow-xs transition-colors">
            + New Server Route
        </a>
    </div>

    <?php if ($error): ?>
        <div class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-medium">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <!-- Add / Edit Server Route Modal/Card -->
    <?php if ($editServer !== null || isset($_GET['action']) && $_GET['action'] === 'new'): ?>
        <div class="bg-white rounded-2xl border border-blue-200 shadow-md p-6 space-y-4">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <h2 class="text-sm font-bold text-slate-900">
                    <?= $editServer ? 'Edit Route: ' . e($editServer['server_name']) : 'Create Multi-Server Line' ?>
                </h2>
                <a href="/admin/servers.php" class="text-xs text-slate-400 hover:text-slate-600 font-bold">&times; Cancel</a>
            </div>

            <form method="POST" action="/admin/servers.php" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_server">
                <input type="hidden" name="id" value="<?= (int)($editServer['id'] ?? 0) ?>">

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Target Service</label>
                        <select name="service_id" required class="w-full text-xs px-3.5 py-2 rounded-xl border border-slate-300">
                            <?php foreach ($allServices as $sv): ?>
                                <option value="<?= $sv['id'] ?>" <?= ($editServer['service_id'] ?? 0) == $sv['id'] ? 'selected' : '' ?>><?= e($sv['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Target Country</label>
                        <select name="country_id" required class="w-full text-xs px-3.5 py-2 rounded-xl border border-slate-300">
                            <?php foreach ($allCountries as $c): ?>
                                <option value="<?= $c['id'] ?>" <?= ($editServer['country_id'] ?? 0) == $c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?> (<?= e($c['prefix']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Upstream SMS Gateway</label>
                        <select name="provider_id" required class="w-full text-xs px-3.5 py-2 rounded-xl border border-slate-300">
                            <?php foreach ($allProviders as $p): ?>
                                <option value="<?= $p['id'] ?>" <?= ($editServer['provider_id'] ?? 0) == $p['id'] ? 'selected' : '' ?>><?= e($p['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Server Display Label</label>
                        <input type="text" name="server_name" required value="<?= e($editServer['server_name'] ?? 'Server 1 (Direct Fast)') ?>" placeholder="Server 1 (High Delivery)" class="w-full text-xs px-3.5 py-2 rounded-xl border border-slate-300">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Provider Service Code</label>
                        <input type="text" name="provider_service_code" value="<?= e($editServer['provider_service_code'] ?? 'wa') ?>" placeholder="e.g. wa, tg, dr" class="w-full text-xs px-3.5 py-2 rounded-xl border border-slate-300 font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Provider Country Code</label>
                        <input type="text" name="provider_country_code" value="<?= e($editServer['provider_country_code'] ?? '187') ?>" placeholder="e.g. 187, 22, us" class="w-full text-xs px-3.5 py-2 rounded-xl border border-slate-300 font-mono">
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 bg-slate-50 p-4 rounded-xl border border-slate-200">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Estimated Cost Price ($)</label>
                        <input type="number" step="0.01" min="0.00" name="cost_price" value="<?= number_format((float)($editServer['cost_price'] ?? 0.25), 2, '.', '') ?>" class="w-full text-xs font-mono px-3.5 py-2 rounded-xl border border-slate-300">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Customer Selling Price ($)</label>
                        <input type="number" step="0.01" min="0.01" name="selling_price" value="<?= number_format((float)($editServer['selling_price'] ?? 0.50), 2, '.', '') ?>" class="w-full text-xs font-mono font-bold text-blue-600 px-3.5 py-2 rounded-xl border border-slate-300">
                    </div>
                </div>

                <div class="flex items-center gap-2 pt-1">
                    <input type="checkbox" name="is_enabled" value="1" <?= empty($editServer) || !empty($editServer['is_enabled']) ? 'checked' : '' ?> class="w-4 h-4 text-blue-600 rounded">
                    <label class="text-xs font-semibold text-slate-700 cursor-pointer">Route is Active & Allocating Numbers</label>
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <a href="/admin/servers.php" class="px-4 py-2 bg-slate-100 text-slate-700 text-xs font-bold rounded-xl">Cancel</a>
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-xl shadow-xs">
                        Save Server Route
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <!-- Server Routes Table -->
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-slate-50 text-slate-500 font-semibold border-b border-slate-100">
                    <tr>
                        <th class="px-5 py-3">Service</th>
                        <th class="px-5 py-3">Country</th>
                        <th class="px-5 py-3">Server Label</th>
                        <th class="px-5 py-3">Gateway Adapter</th>
                        <th class="px-5 py-3">Provider Codes</th>
                        <th class="px-5 py-3">Cost Price</th>
                        <th class="px-5 py-3">Selling Price</th>
                        <th class="px-5 py-3">Margin</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php foreach ($servers as $s): ?>
                        <?php $margin = (float)$s['selling_price'] - (float)$s['cost_price']; ?>
                        <tr class="hover:bg-slate-50/50 transition-colors">
                            <td class="px-5 py-3.5 font-bold text-slate-900"><?= e($s['service_name']) ?></td>
                            <td class="px-5 py-3.5 text-slate-700"><?= e($s['country_name']) ?> (<?= e($s['country_code']) ?>)</td>
                            <td class="px-5 py-3.5 font-bold text-blue-700"><?= e($s['server_name']) ?></td>
                            <td class="px-5 py-3.5 text-slate-500"><?= e($s['provider_name']) ?></td>
                            <td class="px-5 py-3.5 font-mono text-[11px] text-slate-400">
                                <?= e($s['provider_service_code']) ?> / <?= e($s['provider_country_code']) ?>
                            </td>
                            <td class="px-5 py-3.5 font-mono text-slate-500"><?= format_price($s['cost_price']) ?></td>
                            <td class="px-5 py-3.5 font-mono font-bold text-slate-900"><?= format_price($s['selling_price']) ?></td>
                            <td class="px-5 py-3.5 font-mono font-bold <?= $margin >= 0 ? 'text-emerald-600' : 'text-rose-600' ?>">
                                +<?= format_price($margin) ?>
                            </td>
                            <td class="px-5 py-3.5">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= $s['is_enabled'] ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-500' ?>">
                                    <?= $s['is_enabled'] ? 'ACTIVE' : 'DISABLED' ?>
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-right space-x-1.5">
                                <a href="/admin/servers.php?edit=<?= $s['id'] ?>" class="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-lg transition-colors">
                                    Edit
                                </a>
                                <form method="POST" action="/admin/servers.php" class="inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="server_id" value="<?= $s['id'] ?>">
                                    <button type="submit" class="px-2.5 py-1.5 <?= $s['is_enabled'] ? 'bg-rose-50 text-rose-700 hover:bg-rose-100' : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100' ?> font-bold rounded-lg transition-colors">
                                        <?= $s['is_enabled'] ? 'Disable' : 'Enable' ?>
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
