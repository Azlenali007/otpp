<?php
/**
 * NumVault - Administrator Pricing & Profit Margins Console
 * Global markup multipliers, provider margin adjustments, and route profit analytics
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Services\ServiceManager;
use App\Core\Logger;

$admin = require_admin();
$pageTitle = "Pricing & Margins";

$pdo = get_db();
$error = '';

// Handle Pricing Updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = $_POST['action'] ?? '';

    if ($action === 'bulk_margin') {
        $percent = (float)($_POST['margin_percent'] ?? 0);
        $providerId = !empty($_POST['provider_id']) ? (int)$_POST['provider_id'] : null;

        if ($percent <= -100) {
            $error = 'Markup percentage must be greater than -100%.';
        } else {
            $updated = ServiceManager::applyBulkMargin($percent, $providerId);
            $target = $providerId ? "for Provider #{$providerId}" : "across all providers";
            Logger::audit((int)$admin['id'], 'admin_bulk_margin_applied', "Updated {$updated} routes with {$percent}% markup {$target}");
            set_flash('success', "Successfully recalculated pricing for {$updated} server routes.");
            header('Location: /admin/pricing.php');
            exit;
        }
    } elseif ($action === 'update_single_price') {
        $serverId = (int)($_POST['server_id'] ?? 0);
        $sellingPrice = (float)($_POST['selling_price'] ?? 0);

        if ($serverId <= 0 || $sellingPrice <= 0) {
            $error = 'Valid server ID and positive selling price required.';
        } else {
            $stmt = $pdo->prepare("UPDATE servers SET selling_price = ?, updated_at = NOW() WHERE id = ?");
            $stmt->execute([$sellingPrice, $serverId]);
            Logger::audit((int)$admin['id'], 'admin_server_price_updated', "Updated server #{$serverId} selling price to \${$sellingPrice}");
            set_flash('success', "Price updated for route #{$serverId}.");
            header('Location: /admin/pricing.php');
            exit;
        }
    }
}

// Analytics Metrics
$metricsStmt = $pdo->query("
    SELECT 
        COUNT(id) AS total_routes,
        AVG(cost_price) AS avg_cost,
        AVG(selling_price) AS avg_selling,
        AVG(CASE WHEN cost_price > 0 THEN ((selling_price - cost_price) / cost_price) * 100 ELSE 0 END) AS avg_margin_pct,
        SUM(selling_price - cost_price) AS total_margin_spread
    FROM servers
    WHERE is_enabled = 1
");
$metrics = $metricsStmt->fetch(PDO::FETCH_ASSOC);

// Providers list for bulk filter
$providers = $pdo->query("SELECT id, name FROM providers ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);

// Server routes list
$filterProvider = !empty($_GET['provider_id']) ? (int)$_GET['provider_id'] : 0;
$search = trim($_GET['search'] ?? '');

$sql = "
    SELECT srv.*, s.name AS service_name, c.name AS country_name, c.code AS country_code, p.name AS provider_name
    FROM servers srv
    JOIN services s ON s.id = srv.service_id
    JOIN countries c ON c.id = srv.country_id
    JOIN providers p ON p.id = srv.provider_id
    WHERE 1=1
";
$params = [];

if ($filterProvider > 0) {
    $sql .= " AND srv.provider_id = ?";
    $params[] = $filterProvider;
}

if (!empty($search)) {
    $sql .= " AND (srv.server_name LIKE ? OR s.name LIKE ? OR c.name LIKE ?)";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

$sql .= " ORDER BY s.name ASC, c.name ASC LIMIT 100";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$routes = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once APP_ROOT . '/app/layouts/admin_header.php';
?>

<div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8 space-y-6">

    <!-- Page Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-black text-slate-900 tracking-tight">Pricing & Margins Console</h1>
            <p class="text-xs text-slate-500 mt-1">Manage global cost multipliers, bulk price markups, and per-route profitability.</p>
        </div>
        <div class="flex items-center gap-2">
            <a href="/admin/servers.php" class="px-4 py-2.5 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-xs font-bold shadow-sm transition-colors flex items-center gap-2">
                <?= icon('server', 'w-4 h-4') ?>
                Manage Route Servers &rarr;
            </a>
        </div>
    </div>

    <?php if (!empty($error)): ?>
        <div class="p-4 bg-rose-50 border border-rose-200 text-rose-800 rounded-xl text-xs font-medium flex items-center gap-2">
            <?= icon('alert-circle', 'w-4 h-4 flex-shrink-0') ?>
            <span><?= e($error) ?></span>
        </div>
    <?php endif; ?>

    <!-- Overview Analytics -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs">
            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Active Routes</div>
            <div class="text-2xl font-black text-slate-900 mt-1"><?= number_format((int)($metrics['total_routes'] ?? 0)) ?></div>
            <div class="text-[11px] text-slate-500 mt-1">Enabled service-country bindings</div>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs">
            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Average Cost Price</div>
            <div class="text-2xl font-black text-slate-700 mt-1">$<?= number_format((float)($metrics['avg_cost'] ?? 0), 2) ?></div>
            <div class="text-[11px] text-slate-500 mt-1">Base provider wholesale cost</div>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs">
            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Average Selling Price</div>
            <div class="text-2xl font-black text-blue-600 mt-1">$<?= number_format((float)($metrics['avg_selling'] ?? 0), 2) ?></div>
            <div class="text-[11px] text-slate-500 mt-1">Retail price displayed to clients</div>
        </div>
        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-xs">
            <div class="text-[11px] font-bold text-slate-400 uppercase tracking-wider">Average Profit Margin</div>
            <div class="text-2xl font-black text-emerald-600 mt-1"><?= number_format((float)($metrics['avg_margin_pct'] ?? 0), 1) ?>%</div>
            <div class="text-[11px] text-slate-500 mt-1">Average net markup spread</div>
        </div>
    </div>

    <!-- Bulk Markup Adjustment Card -->
    <div class="bg-white p-6 rounded-2xl border border-slate-200 shadow-xs space-y-4">
        <div class="flex items-center gap-3">
            <div class="w-10 h-10 rounded-xl bg-blue-50 text-blue-600 flex items-center justify-center font-bold">
                <?= icon('trending-up', 'w-5 h-5') ?>
            </div>
            <div>
                <h3 class="text-sm font-bold text-slate-900">Bulk Price Multiplier & Margin Recalculator</h3>
                <p class="text-xs text-slate-500">Recalculate customer selling prices automatically based on provider cost + percentage margin.</p>
            </div>
        </div>

        <form method="POST" action="/admin/pricing.php" class="grid grid-cols-1 sm:grid-cols-12 gap-3 pt-2">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="bulk_margin">

            <div class="sm:col-span-4">
                <label class="block text-[11px] font-bold text-slate-700 uppercase mb-1">Target Provider</label>
                <select name="provider_id" class="w-full px-3.5 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-blue-500">
                    <option value="">All Active Providers</option>
                    <?php foreach ($providers as $pr): ?>
                        <option value="<?= $pr['id'] ?>"><?= e($pr['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="sm:col-span-5">
                <label class="block text-[11px] font-bold text-slate-700 uppercase mb-1">Markup Percentage over Cost (%)</label>
                <div class="relative">
                    <input type="number" step="0.5" name="margin_percent" value="35" required class="w-full pl-3.5 pr-10 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-blue-500 font-bold">
                    <span class="absolute right-3.5 top-2.5 text-xs text-slate-400 font-bold">%</span>
                </div>
            </div>

            <div class="sm:col-span-3 flex items-end">
                <button type="submit" onclick="return confirm('Are you sure you want to recalculate selling prices for selected routes?')" class="w-full px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold transition-colors shadow-xs h-[38px] flex items-center justify-center gap-1.5">
                    <?= icon('refresh-cw', 'w-4 h-4') ?>
                    Apply Multiplier
                </button>
            </div>
        </form>
    </div>

    <!-- Search & Filters -->
    <div class="bg-white p-4 rounded-2xl border border-slate-200 shadow-xs flex flex-col sm:flex-row items-center justify-between gap-4">
        <form method="GET" action="/admin/pricing.php" class="flex-1 w-full flex flex-wrap items-center gap-2">
            <input type="text" name="search" value="<?= e($search) ?>" placeholder="Filter by service, country, or route..." class="flex-1 min-w-[200px] px-3.5 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:bg-white focus:ring-2 focus:ring-blue-500">
            <select name="provider_id" onchange="this.form.submit()" class="px-3.5 py-2 text-xs bg-slate-50 border border-slate-200 rounded-xl focus:bg-white">
                <option value="">All Providers</option>
                <?php foreach ($providers as $pr): ?>
                    <option value="<?= $pr['id'] ?>" <?= $filterProvider === (int)$pr['id'] ? 'selected' : '' ?>><?= e($pr['name']) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="px-4 py-2 bg-slate-800 hover:bg-slate-900 text-white rounded-xl text-xs font-bold transition-colors">Filter</button>
            <?php if (!empty($search) || $filterProvider > 0): ?>
                <a href="/admin/pricing.php" class="px-3 py-2 text-xs text-slate-500 hover:text-slate-800 font-medium">Reset</a>
            <?php endif; ?>
        </form>
    </div>

    <!-- Pricing Table -->
    <div class="bg-white rounded-2xl border border-slate-200 shadow-xs overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-left border-collapse text-xs">
                <thead>
                    <tr class="bg-slate-50 border-b border-slate-200 text-slate-500 font-bold uppercase tracking-wider text-[10px]">
                        <th class="py-3.5 px-4">Route Name</th>
                        <th class="py-3.5 px-4">Service</th>
                        <th class="py-3.5 px-4">Country</th>
                        <th class="py-3.5 px-4">Provider</th>
                        <th class="py-3.5 px-4">Cost (Wholesale)</th>
                        <th class="py-3.5 px-4">Selling (Retail)</th>
                        <th class="py-3.5 px-4">Net Margin</th>
                        <th class="py-3.5 px-4 text-right">Quick Price Update</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100 font-medium">
                    <?php if (empty($routes)): ?>
                        <tr>
                            <td colspan="8" class="py-12 text-center text-slate-400">
                                No server routes found matching criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($routes as $rt): 
                            $cost = (float)$rt['cost_price'];
                            $selling = (float)$rt['selling_price'];
                            $profit = $selling - $cost;
                            $marginPct = $cost > 0 ? ($profit / $cost) * 100 : 0;
                        ?>
                            <tr class="hover:bg-slate-50/70 transition-colors">
                                <td class="py-3.5 px-4 font-bold text-slate-900">
                                    <?= e($rt['server_name']) ?>
                                    <?php if (!$rt['is_enabled']): ?>
                                        <span class="ml-1 text-[9px] px-1.5 py-0.5 rounded-full bg-slate-100 text-slate-500">Disabled</span>
                                    <?php endif; ?>
                                </td>
                                <td class="py-3.5 px-4 font-semibold text-slate-800">
                                    <?= e($rt['service_name']) ?>
                                </td>
                                <td class="py-3.5 px-4">
                                    <span class="inline-flex items-center gap-1.5 text-slate-700">
                                        <span class="font-mono text-[10px] text-slate-400"><?= e($rt['country_code']) ?></span>
                                        <?= e($rt['country_name']) ?>
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 text-slate-600">
                                    <?= e($rt['provider_name']) ?>
                                </td>
                                <td class="py-3.5 px-4 font-mono text-slate-600">
                                    $<?= number_format($cost, 2) ?>
                                </td>
                                <td class="py-3.5 px-4 font-mono font-bold text-blue-600">
                                    $<?= number_format($selling, 2) ?>
                                </td>
                                <td class="py-3.5 px-4 font-mono">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-bold <?= $profit >= 0 ? 'bg-emerald-50 text-emerald-700 border border-emerald-200' : 'bg-rose-50 text-rose-700 border border-rose-200' ?>">
                                        +$<?= number_format($profit, 2) ?> (<?= number_format($marginPct, 1) ?>%)
                                    </span>
                                </td>
                                <td class="py-3.5 px-4 text-right">
                                    <form method="POST" action="/admin/pricing.php" class="inline-flex items-center gap-1.5 justify-end">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="update_single_price">
                                        <input type="hidden" name="server_id" value="<?= $rt['id'] ?>">
                                        <div class="relative w-24">
                                            <span class="absolute left-2.5 top-1.5 text-slate-400 font-mono text-xs">$</span>
                                            <input type="number" step="0.01" min="0.01" name="selling_price" value="<?= number_format($selling, 2, '.', '') ?>" class="w-full pl-6 pr-2 py-1 text-xs bg-slate-50 border border-slate-200 rounded-lg font-mono focus:bg-white focus:ring-1 focus:ring-blue-500">
                                        </div>
                                        <button type="submit" class="px-2.5 py-1 bg-slate-800 hover:bg-slate-900 text-white rounded-lg text-[11px] font-bold transition-colors">
                                            Save
                                        </button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php require_once APP_ROOT . '/app/layouts/admin_footer.php'; ?>
