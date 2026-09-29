<?php
/**
 * NumVault - Administrator Country Routes Management
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

$admin = require_admin();
$pageTitle = "Country Management";

$pdo = get_db();
$error = '';

// Handle Add / Edit / Toggle
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'save_country') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $code = strtoupper(trim($_POST['code'] ?? ''));
        $prefix = trim($_POST['prefix'] ?? '');
        $sortOrder = (int)($_POST['sort_order'] ?? 1);
        $isEnabled = !empty($_POST['is_enabled']) ? 1 : 0;

        if (empty($name) || empty($code) || empty($prefix)) {
            $error = 'Country name, 2-letter ISO code, and dial prefix are required.';
        } else {
            if ($id > 0) {
                $upd = $pdo->prepare("UPDATE countries SET name = ?, code = ?, prefix = ?, sort_order = ?, is_enabled = ? WHERE id = ?");
                $upd->execute([$name, $code, $prefix, $sortOrder, $isEnabled, $id]);
                log_audit($admin['id'], 'admin_country_updated', "Updated country {$name} ({$code})");
                set_flash('success', "Country {$name} updated.");
            } else {
                $ins = $pdo->prepare("INSERT INTO countries (name, code, prefix, sort_order, is_enabled) VALUES (?, ?, ?, ?, ?)");
                $ins->execute([$name, $code, $prefix, $sortOrder, $isEnabled]);
                log_audit($admin['id'], 'admin_country_created', "Created country {$name} ({$code})");
                set_flash('success', "Country {$name} added.");
            }
            header('Location: /admin/countries.php');
            exit;
        }
    } elseif ($action === 'toggle_status') {
        $id = (int)($_POST['country_id'] ?? 0);
        $pdo->exec("UPDATE countries SET is_enabled = IF(is_enabled=1, 0, 1) WHERE id = {$id}");
        set_flash('success', "Country availability updated.");
        header('Location: /admin/countries.php');
        exit;
    }
}

$countries = $pdo->query("
    SELECT c.*, COUNT(s.id) AS active_servers_count 
    FROM countries c
    LEFT JOIN servers s ON c.id = s.country_id AND s.is_enabled = 1
    GROUP BY c.id
    ORDER BY c.sort_order ASC, c.name ASC
")->fetchAll();

$editCountry = null;
if (isset($_GET['edit'])) {
    $eId = (int)$_GET['edit'];
    $stmt = $pdo->prepare("SELECT * FROM countries WHERE id = ?");
    $stmt->execute([$eId]);
    $editCountry = $stmt->fetch();
}

require_once __DIR__ . '/../app/layouts/admin_header.php';
?>

<div class="space-y-6">

    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-extrabold text-slate-900 tracking-tight">Country Routes</h1>
            <p class="text-xs text-slate-500 mt-0.5">Manage supported geographic carriers and international prefixes</p>
        </div>
        <a href="/admin/countries.php?action=new" class="px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold shadow-xs transition-colors">
            + Add Country Route
        </a>
    </div>

    <?php if ($error): ?>
        <div class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-medium">
            <?= e($error) ?>
        </div>
    <?php endif; ?>

    <!-- Add / Edit Country Form -->
    <?php if ($editCountry !== null || isset($_GET['action']) && $_GET['action'] === 'new'): ?>
        <div class="bg-white rounded-2xl border border-blue-200 shadow-md p-6 space-y-4">
            <div class="flex items-center justify-between pb-3 border-b border-slate-100">
                <h2 class="text-sm font-bold text-slate-900">
                    <?= $editCountry ? 'Edit Country: ' . e($editCountry['name']) : 'Add New Country' ?>
                </h2>
                <a href="/admin/countries.php" class="text-xs text-slate-400 hover:text-slate-600 font-bold">&times; Cancel</a>
            </div>

            <form method="POST" action="/admin/countries.php" class="space-y-4">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="save_country">
                <input type="hidden" name="id" value="<?= (int)($editCountry['id'] ?? 0) ?>">

                <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                    <div class="sm:col-span-2">
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Country Name</label>
                        <input type="text" name="name" required value="<?= e($editCountry['name'] ?? '') ?>" placeholder="e.g. United Kingdom" class="w-full text-xs px-3.5 py-2 rounded-xl border border-slate-300">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">ISO Code (2-Letter)</label>
                        <input type="text" name="code" required maxlength="5" value="<?= e($editCountry['code'] ?? '') ?>" placeholder="GB" class="w-full text-xs px-3.5 py-2 rounded-xl border border-slate-300 uppercase font-mono">
                    </div>
                    <div>
                        <label class="block text-xs font-semibold text-slate-700 mb-1">Dial Prefix</label>
                        <input type="text" name="prefix" required value="<?= e($editCountry['prefix'] ?? '') ?>" placeholder="+44" class="w-full text-xs px-3.5 py-2 rounded-xl border border-slate-300 font-mono">
                    </div>
                </div>

                <div class="flex items-center gap-6 pt-2">
                    <label class="flex items-center gap-2 text-xs font-semibold text-slate-700 cursor-pointer">
                        <input type="checkbox" name="is_enabled" value="1" <?= empty($editCountry) || !empty($editCountry['is_enabled']) ? 'checked' : '' ?> class="w-4 h-4 text-blue-600 rounded">
                        <span>Country is Active and Shown to Users</span>
                    </label>

                    <div class="flex items-center gap-2 text-xs">
                        <span class="font-semibold text-slate-700">Sort Priority:</span>
                        <input type="number" name="sort_order" value="<?= (int)($editCountry['sort_order'] ?? 1) ?>" class="w-20 text-xs px-2 py-1 border rounded font-mono">
                    </div>
                </div>

                <div class="pt-3 border-t border-slate-100 flex justify-end gap-2">
                    <a href="/admin/countries.php" class="px-4 py-2 bg-slate-100 text-slate-700 text-xs font-bold rounded-xl">Cancel</a>
                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-xl shadow-xs">
                        Save Country Route
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
                        <th class="px-5 py-3">Country</th>
                        <th class="px-5 py-3">ISO Code</th>
                        <th class="px-5 py-3">Prefix</th>
                        <th class="px-5 py-3">Active Servers</th>
                        <th class="px-5 py-3">Status</th>
                        <th class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php foreach ($countries as $c): ?>
                        <tr class="hover:bg-slate-50/50 transition-colors">
                            <td class="px-5 py-3.5 font-mono text-slate-400 font-bold"><?= (int)$c['sort_order'] ?></td>
                            <td class="px-5 py-3.5 font-bold text-slate-900"><?= e($c['name']) ?></td>
                            <td class="px-5 py-3.5 font-mono font-bold text-blue-600"><?= e($c['code']) ?></td>
                            <td class="px-5 py-3.5 font-mono text-slate-600"><?= e($c['prefix']) ?></td>
                            <td class="px-5 py-3.5 font-mono text-slate-500"><?= (int)$c['active_servers_count'] ?> line(s)</td>
                            <td class="px-5 py-3.5">
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold <?= $c['is_enabled'] ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-slate-100 text-slate-500' ?>">
                                    <?= $c['is_enabled'] ? 'ENABLED' : 'DISABLED' ?>
                                </span>
                            </td>
                            <td class="px-5 py-3.5 text-right space-x-1.5">
                                <a href="/admin/countries.php?edit=<?= $c['id'] ?>" class="px-2.5 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-700 font-bold rounded-lg transition-colors">
                                    Edit
                                </a>
                                <form method="POST" action="/admin/countries.php" class="inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="toggle_status">
                                    <input type="hidden" name="country_id" value="<?= $c['id'] ?>">
                                    <button type="submit" class="px-2.5 py-1.5 <?= $c['is_enabled'] ? 'bg-rose-50 text-rose-700 hover:bg-rose-100' : 'bg-emerald-50 text-emerald-700 hover:bg-emerald-100' ?> font-bold rounded-lg transition-colors">
                                        <?= $c['is_enabled'] ? 'Disable' : 'Enable' ?>
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
