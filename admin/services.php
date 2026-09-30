<?php
/**
 * NumVault - Administrator Services Management
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$admin = require_admin();
$pageTitle = "Service Catalog Management";

$pdo = get_db();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'save_service') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $code = strtolower(trim($_POST['code'] ?? ''));
        $icon = trim($_POST['icon'] ?? 'shield');
        $sortOrder = (int)($_POST['sort_order'] ?? 1);
        $isEnabled = !empty($_POST['is_enabled']) ? 1 : 0;

        if (empty($name) || empty($code)) {
            $error = 'Service name and unique application code are required.';
        } else {
            if ($id > 0) {
                $upd = $pdo->prepare("UPDATE services SET name = ?, code = ?, icon = ?, sort_order = ?, is_enabled = ? WHERE id = ?");
                $upd->execute([$name, $code, $icon, $sortOrder, $isEnabled, $id]);
                log_audit($admin['id'], 'admin_service_updated', "Updated service {$name}");
                set_flash('success', "Service {$name} updated.");
            } else {
                $ins = $pdo->prepare("INSERT INTO services (name, code, icon, sort_order, is_enabled) VALUES (?, ?, ?, ?, ?)");
                $ins->execute([$name, $code, $icon, $sortOrder, $isEnabled]);
                log_audit($admin['id'], 'admin_service_created', "Created service {$name}");
                set_flash('success', "Service {$name} created.");
            }
            header('Location: /admin/services.php');
            exit;
        }
    } elseif ($action === 'toggle_status') {
        $id = (int)($_POST['service_id'] ?? 0);
        $pdo->exec("UPDATE services SET is_enabled = IF(is_enabled=1, 0, 1) WHERE id = {$id}");
        set_flash('success', "Service status toggled.");
        header('Location: /admin/services.php');
        exit;
    }
}

$services = $pdo->query("
    SELECT sv.*, COUNT(s.id) AS servers_count 
    FROM services sv
    LEFT JOIN servers s ON sv.id = s.service_id AND s.is_enabled = 1
    GROUP BY sv.id
    ORDER BY sv.sort_order ASC, sv.name ASC
")->fetchAll();

$editService = null;
if (isset($_GET['edit'])) {
    $eId = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM services WHERE id = ?");
    $stmt->execute([$eId]);
    $editService = $stmt->fetch();
}

require_once __DIR__ . '/../app/layouts/admin_header.php';
?>

<div class="space-y-6">

    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-extrabold text-slate-900 tracking-tight">Service Catalog</h1>
            <p class="text-xs text-slate-500 mt-0.5">Manage target apps (WhatsApp, Telegram, OpenAI, Google, etc.)</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="/admin/provider_import.php?tab=services" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white rounded-xl text-xs font-bold shadow-xs transition-colors flex items-center gap-1.5">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"></path></svg>
                Import from Provider
            </a>
            <a href="/admin/services.php?action=new" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold shadow-xs transition-colors">
                + Add New Service
            </a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-medium">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <!-- Add / Edit Service Form -->
    <?php if ($editService !== null || isset($_GET['action']) && $_GET['action'] === 'new'): ?>
        <div class="bg-white rounded-2xl border border-blue-200 shadow-md p-6 space-y-4">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <h2 class="text-sm font-bold text-slate-900">
                    <?= $editService ? 'Edit Service: ' . e($editService['name']) : 'Add New Service to Catalog' ?>
                </h2>
                <a href="/admin/services.php" class="text-xs text-slate-400 hover:text-slate-600 font-bold">&times; Cancel</a>
            </div>

            <form method="POST" action="/admin/services.php" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_service">
                <input type="hidden" name="id" value="<?= (int)($editService['id'] ?? 0) ?>">

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Service Display Name</label>
                        <input type="text" name="name" required value="<?= e($editService['name'] ?? '') ?>" placeholder="e.g. WhatsApp" class="w-full text-xs px-3.5 py-2 rounded-xl border border-slate-300">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Internal Code</label>
                        <input type="text" name="code" required value="<?= e($editService['code'] ?? '') ?>" placeholder="wa" class="w-full text-xs px-3.5 py-2 rounded-xl border border-slate-300 font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">UI Icon</label>
                        <select name="icon" class="w-full text-xs px-3.5 py-2 rounded-xl border border-slate-300">
                            <option value="message-square" <?= ($editService['icon'] ?? '') === 'message-square' ? 'selected' : '' ?>>Message Square</option>
                            <option value="shield" <?= ($editService['icon'] ?? '') === 'shield' ? 'selected' : '' ?>>Shield</option>
                            <option value="layers" <?= ($editService['icon'] ?? '') === 'layers' ? 'selected' : '' ?>>Layers</option>
                            <option value="globe" <?= ($editService['icon'] ?? '') === 'globe' ? 'selected' : '' ?>>Globe</option>
                            <option value="phone" <?= ($editService['icon'] ?? '') === 'phone' ? 'selected' : '' ?>>Phone</option>
                            <option value="user" <?= ($editService['icon'] ?? '') === 'user' ? 'selected' : '' ?>>User</option>
                            <option value="bell" <?= ($editService['icon'] ?? '') === 'bell' ? 'selected' : '' ?>>Bell</option>
                        </select>
                    </div>
                </div>

                <div class="flex items-center gap-6 pt-2">
                    <label class="flex items-center gap-2 text-xs font-semibold text-slate-700 cursor-pointer">
                        <input type="checkbox" name="is_enabled" value="1" <?= empty($editService) || !empty($editService['is_enabled']) ? 'checked' : '' ?> class="w-4 h-4 text-blue-600 rounded">
                        <span>Service is Active in Catalog</span>
                    </label>

                    <div class="flex items-center gap-2 text-xs">
                        <span class="font-semibold text-slate-700">Sort Priority:</span>
                        <input type="number" name="sort_order" value="<?= (int)($editService['sort_order'] ?? 1) ?>" class="w-20 text-xs px-2 py-1 border rounded font-mono">
                    </div>
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <a href="/admin/services.php" class="px-4 py-2 bg-slate-100 text-slate-700 text-xs font-bold rounded-xl">Cancel</a>
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-xl shadow-xs">
                        Save Service
                    </button>
                </div>
            </form>
        </div>
    <?php endif; ?>

    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="bg-slate-50 text-slate-500 font-semibold border-b border-slate-100">
                    <tr>
                        <th class="px-5 py-3">Order</th>
                        <th class="px-5 py-3">Service</th>
                        <th class="px-5 py-3">Code</th>
                        <th class="px-5 py-3">Icon</th>
                        <th class="px-5 py-3">Configured Routes</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php foreach ($services as $sv): ?>
                        <tr class="hover:bg-slate-50/50 transition-colors">
                            <td class="px-5 py-3.5 font-mono text-slate-400 font-bold"><?= (int)$sv['sort_order'] ?></td>
                            <td class="px-5 py-3.5 font-bold text-slate-900"><?= e($sv['name']) ?></td>
                            <td class="px-5 py-3.5 font-mono text-blue-600 font-bold"><?= e($sv['code']) ?></td>
                            <td class="px-5 py-3.5 text-slate-500"><?= icon($sv['icon'] ?: 'shield', 'w-4 h-4') ?></td>
                            <td class="px-5 py-3.5 font-mono text-slate-600"><?= (int)$sv['servers_count'] ?> server(s)</td>
                            <td class="px-5 py-3.5">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= $sv['is_enabled'] ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-500' ?>">
                                    <?= $sv['is_enabled'] ? 'ACTIVE' : 'DISABLED' ?>
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-right space-x-1.5">
                                <a href="/admin/services.php?edit=<?= $sv['id'] ?>" class="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-lg transition-colors">
                                    Edit
                                </a>
                                <form method="POST" action="/admin/services.php" class="inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="service_id" value="<?= $sv['id'] ?>">
                                    <button type="submit" class="px-2.5 py-1.5 <?= $sv['is_enabled'] ? 'bg-rose-50 text-rose-700 hover:bg-rose-100' : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100' ?> font-bold rounded-lg transition-colors">
                                        <?= $sv['is_enabled'] ? 'Disable' : 'Enable' ?>
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
