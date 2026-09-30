<?php
/**
 * NumVault - Administrator Provider Country & Service API Import Console
 * Direct API synchronization for wholesale routes, pricing, and availability
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Providers\ProviderFactory;

$admin = require_admin();
$pageTitle = "API Country & Service Importer";

$pdo = get_db();
$error = '';
$previewData = null;
$previewType = '';

// Load all active providers
$providers = $pdo->query("SELECT id, name, slug, currency FROM providers WHERE is_enabled = 1 ORDER BY priority ASC, name ASC")->fetchAll();

$selectedProviderId = (int)($_GET['provider_id'] ?? ($_POST['provider_id'] ?? ($providers[0]['id'] ?? 0)));
$selectedTab = trim($_GET['tab'] ?? ($_POST['tab'] ?? 'countries'));
if (!in_array($selectedTab, ['countries', 'services'])) {
    $selectedTab = 'countries';
}

$selectedProvider = null;
foreach ($providers as $p) {
    if ((int)$p['id'] === $selectedProviderId) {
        $selectedProvider = $p;
        break;
    }
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $action = $_POST['action'] ?? '';

    if (!$selectedProvider) {
        $error = 'Please select a valid, active SMS provider first.';
    } else {
        $adapter = ProviderFactory::get($selectedProviderId);
        if (!$adapter) {
            $error = 'Unable to initialize provider gateway adapter. Please verify provider settings.';
        } else {

            // 1. PREVIEW COUNTRIES
            if ($action === 'preview_countries') {
                $res = $adapter->getCountries();
                if (empty($res['success'])) {
                    $error = 'Provider API Error: ' . ($res['error'] ?? 'Failed to retrieve countries.');
                } else {
                    $rawCountries = $res['countries'] ?? [];
                    if (empty($rawCountries)) {
                        $error = 'Provider API returned 0 countries.';
                    } else {
                        // Check match status against existing DB
                        $existingStmt = $pdo->query("SELECT id, name, code, prefix FROM countries");
                        $existingCodes = [];
                        $existingNames = [];
                        while ($row = $existingStmt->fetch()) {
                            $existingCodes[strtoupper((string)$row['code'])] = $row;
                            $existingNames[strtolower(trim((string)$row['name']))] = $row;
                        }

                        $previewItems = [];
                        foreach ($rawCountries as $c) {
                            $codeUpper = strtoupper((string)($c['code'] ?? ''));
                            $nameLower = strtolower(trim((string)($c['name'] ?? '')));
                            $exists = isset($existingCodes[$codeUpper]) || isset($existingNames[$nameLower]);
                            $match = $existingCodes[$codeUpper] ?? ($existingNames[$nameLower] ?? null);

                            $previewItems[] = [
                                'provider_country_id' => (string)($c['provider_country_id'] ?? ''),
                                'name'                => (string)($c['name'] ?? ''),
                                'code'                => $codeUpper,
                                'prefix'              => (string)($c['prefix'] ?? ''),
                                'exists'              => $exists,
                                'existing_id'         => $match['id'] ?? null
                            ];
                        }

                        $previewData = $previewItems;
                        $previewType = 'countries';
                    }
                }
            }

            // 2. CONFIRM & IMPORT COUNTRIES
            elseif ($action === 'confirm_import_countries') {
                $itemsJson = $_POST['items_json'] ?? '';
                $items = json_decode($itemsJson, true);

                if (!is_array($items) || empty($items)) {
                    $error = 'No country preview data was found to import. Please run preview first.';
                } else {
                    try {
                        $pdo->beginTransaction();

                        $insCountry = $pdo->prepare("INSERT INTO countries (name, code, prefix, is_enabled, sort_order) VALUES (?, ?, ?, 1, 99)");
                        $updCountry = $pdo->prepare("UPDATE countries SET name = ?, prefix = ? WHERE id = ?");
                        $findCountry = $pdo->prepare("SELECT id, name, code FROM countries WHERE code = ? OR name = ? LIMIT 1");
                        $mapCountry = $pdo->prepare("
                            INSERT INTO provider_country_mappings (provider_id, country_id, provider_country_id, provider_country_name)
                            VALUES (?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE country_id = VALUES(country_id), provider_country_name = VALUES(provider_country_name)
                        ");

                        $added = 0;
                        $updated = 0;

                        foreach ($items as $item) {
                            $provCountryId = trim((string)($item['provider_country_id'] ?? ''));
                            $name = trim((string)($item['name'] ?? ''));
                            $code = strtoupper(trim((string)($item['code'] ?? '')));
                            $prefix = trim((string)($item['prefix'] ?? ''));

                            if (empty($name) || empty($code)) continue;

                            // Check existing
                            $findCountry->execute([$code, $name]);
                            $existing = $findCountry->fetch();

                            $countryId = 0;
                            if ($existing) {
                                $countryId = (int)$existing['id'];
                                if (!empty($prefix)) {
                                    $updCountry->execute([$name, $prefix, $countryId]);
                                }
                                $updated++;
                            } else {
                                $insCountry->execute([$name, $code, $prefix]);
                                $countryId = (int)$pdo->lastInsertId();
                                $added++;
                            }

                            if ($countryId > 0 && !empty($provCountryId)) {
                                $mapCountry->execute([$selectedProviderId, $countryId, $provCountryId, $name]);
                            }
                        }

                        $pdo->commit();
                        log_audit($admin['id'], 'admin_import_countries', "Imported {$added} new and synced {$updated} existing countries from provider #{$selectedProviderId} ({$selectedProvider['name']})");
                        set_flash('success', "Successfully imported {$added} new countries and synchronized {$updated} existing countries from {$selectedProvider['name']}.");
                        header("Location: /admin/provider_import.php?provider_id={$selectedProviderId}&tab=countries");
                        exit;
                    } catch (Exception $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        Logger::error("Country import error: " . $e->getMessage());
                        $error = 'Database transaction error during country import: ' . $e->getMessage();
                    }
                }
            }

            // 3. PREVIEW SERVICES
            elseif ($action === 'preview_services') {
                $targetCountryCode = trim($_POST['country_route'] ?? '');
                $res = $adapter->getServices($targetCountryCode);

                if (empty($res['success'])) {
                    $error = 'Provider API Error: ' . ($res['error'] ?? 'Failed to retrieve services.');
                } else {
                    $rawServices = $res['services'] ?? [];
                    if (empty($rawServices)) {
                        $error = 'Provider API returned 0 services for this country route.';
                    } else {
                        // Check match status against existing DB
                        $existingStmt = $pdo->query("SELECT id, name, code FROM services");
                        $existingCodes = [];
                        $existingNames = [];
                        while ($row = $existingStmt->fetch()) {
                            $existingCodes[strtolower((string)$row['code'])] = $row;
                            $existingNames[strtolower(trim((string)$row['name']))] = $row;
                        }

                        $previewItems = [];
                        foreach ($rawServices as $s) {
                            $codeLower = strtolower(trim((string)($s['code'] ?? '')));
                            $nameLower = strtolower(trim((string)($s['name'] ?? '')));
                            $exists = isset($existingCodes[$codeLower]) || isset($existingNames[$nameLower]);
                            $match = $existingCodes[$codeLower] ?? ($existingNames[$nameLower] ?? null);

                            $previewItems[] = [
                                'provider_service_id' => (string)($s['provider_service_id'] ?? ''),
                                'name'                => (string)($s['name'] ?? ''),
                                'code'                => $codeLower,
                                'cost'                => isset($s['cost']) ? (float)$s['cost'] : null,
                                'count'               => isset($s['count']) ? (int)$s['count'] : null,
                                'exists'              => $exists,
                                'existing_id'         => $match['id'] ?? null
                            ];
                        }

                        $previewData = $previewItems;
                        $previewType = 'services';
                    }
                }
            }

            // 4. CONFIRM & IMPORT SERVICES
            elseif ($action === 'confirm_import_services') {
                $itemsJson = $_POST['items_json'] ?? '';
                $targetCountryId = (int)($_POST['target_country_id'] ?? 0);
                $targetProviderCountryId = trim($_POST['target_provider_country_id'] ?? '');
                $items = json_decode($itemsJson, true);

                if (!is_array($items) || empty($items)) {
                    $error = 'No service preview data was found to import. Please run preview first.';
                } else {
                    try {
                        $pdo->beginTransaction();

                        $insService = $pdo->prepare("INSERT INTO services (name, code, icon, is_enabled, sort_order) VALUES (?, ?, 'shield', 1, 99)");
                        $findService = $pdo->prepare("SELECT id, name, code FROM services WHERE code = ? OR name = ? LIMIT 1");
                        $mapService = $pdo->prepare("
                            INSERT INTO provider_service_mappings (provider_id, service_id, provider_service_id, provider_service_name, cost_price, available_count)
                            VALUES (?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE service_id = VALUES(service_id), provider_service_name = VALUES(provider_service_name), cost_price = VALUES(cost_price), available_count = VALUES(available_count)
                        ");

                        $findServer = $pdo->prepare("SELECT id, cost_price, selling_price FROM servers WHERE service_id = ? AND country_id = ? AND provider_id = ? LIMIT 1");
                        $insServer = $pdo->prepare("
                            INSERT INTO servers (service_id, country_id, provider_id, server_name, provider_service_code, provider_country_code, provider_operator_code, cost_price, selling_price, is_enabled)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)
                        ");
                        $updServer = $pdo->prepare("
                            UPDATE servers 
                            SET provider_service_code = ?, provider_country_code = ?, provider_operator_code = ?, cost_price = ?
                            WHERE id = ?
                        ");

                        $added = 0;
                        $updated = 0;
                        $routesCreated = 0;

                        $targetOperator = trim((string)($_POST['target_operator'] ?? 'any'));
                        if (empty($targetOperator)) $targetOperator = 'any';

                        foreach ($items as $item) {
                            $provServiceId = trim((string)($item['provider_service_id'] ?? ''));
                            $name = trim((string)($item['name'] ?? ''));
                            $code = strtolower(trim((string)($item['code'] ?? '')));
                            $cost = isset($item['cost']) && is_numeric($item['cost']) ? (float)$item['cost'] : 0.00;
                            $count = isset($item['count']) && is_numeric($item['count']) ? (int)$item['count'] : null;

                            if (empty($name) || empty($code)) continue;

                            $findService->execute([$code, $name]);
                            $existing = $findService->fetch();

                            $serviceId = 0;
                            if ($existing) {
                                $serviceId = (int)$existing['id'];
                                $updated++;
                            } else {
                                $insService->execute([$name, $code]);
                                $serviceId = (int)$pdo->lastInsertId();
                                $added++;
                            }

                            if ($serviceId > 0 && !empty($provServiceId)) {
                                $mapService->execute([$selectedProviderId, $serviceId, $provServiceId, $name, $cost > 0 ? $cost : null, $count]);

                                // Connect real server route if target country is specified
                                if ($targetCountryId > 0) {
                                    $findServer->execute([$serviceId, $targetCountryId, $selectedProviderId]);
                                    $existingServer = $findServer->fetch();

                                    $provCountryCode = !empty($targetProviderCountryId) ? $targetProviderCountryId : (string)$targetCountryId;

                                    if ($existingServer) {
                                        // Update cost and provider codes while preserving admin configured retail price
                                        $updServer->execute([$provServiceId, $provCountryCode, $targetOperator, $cost, $existingServer['id']]);
                                    } else {
                                        // Create new server route with transparent margin
                                        $initialSellingPrice = $cost > 0 ? round($cost * 1.5, 2) : 0.50;
                                        $serverName = "Server 1 (" . $selectedProvider['name'] . ")";
                                        $insServer->execute([
                                            $serviceId,
                                            $targetCountryId,
                                            $selectedProviderId,
                                            $serverName,
                                            $provServiceId,
                                            $provCountryCode,
                                            $targetOperator,
                                            $cost,
                                            $initialSellingPrice
                                        ]);
                                        $routesCreated++;
                                    }
                                }
                            }
                        }

                        $pdo->commit();
                        log_audit($admin['id'], 'admin_import_services', "Imported {$added} new, synced {$updated} existing services, and configured {$routesCreated} server routes from provider #{$selectedProviderId} ({$selectedProvider['name']})");
                        set_flash('success', "Successfully imported {$added} new services, synchronized {$updated} existing services, and linked {$routesCreated} live routes from {$selectedProvider['name']}.");
                        header("Location: /admin/provider_import.php?provider_id={$selectedProviderId}&tab=services");
                        exit;
                    } catch (Exception $e) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        Logger::error("Service import error: " . $e->getMessage());
                        $error = 'Database transaction error during service import: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

// Fetch enabled countries for services country selector
$allCountries = $pdo->query("SELECT c.id, c.name, c.code, pcm.provider_country_id FROM countries c LEFT JOIN provider_country_mappings pcm ON c.id = pcm.country_id AND pcm.provider_id = {$selectedProviderId} WHERE c.is_enabled = 1 ORDER BY c.sort_order ASC, c.name ASC")->fetchAll();

require_once __DIR__ . '/../app/layouts/admin_header.php';
?>

<div class="space-y-6">

    <!-- Header Section -->
    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <span class="px-2.5 py-0.5 rounded-full text-[10px] font-extrabold uppercase tracking-wide bg-blue-50 text-blue-700 border border-blue-200">
                    Carrier Catalog Importer
                </span>
                <span class="text-xs text-slate-400 font-mono">Real-time API Sync</span>
            </div>
            <h1 class="text-xl font-extrabold text-slate-900 tracking-tight mt-1">API Country & Service Importer</h1>
            <p class="text-xs text-slate-500 mt-0.5">Import and synchronize real destination countries, application catalogs, and wholesale rates directly from configured provider APIs</p>
        </div>

        <div class="flex items-center gap-2">
            <a href="/admin/providers.php" class="px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 rounded-xl text-xs font-bold transition-colors">
                &larr; Provider Settings
            </a>
            <a href="/admin/servers.php" class="px-3 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold transition-colors shadow-xs">
                Manage Multi-Servers
            </a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-medium flex items-center justify-between">
            <div class="flex items-center gap-2">
                <svg class="w-4 h-4 flex-shrink-0 text-rose-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>
                <span><?= e($error) ?></span>
            </div>
        </div>
    <?php endif; ?>

    <?php if (empty($providers)): ?>
        <div class="bg-white rounded-2xl border border-slate-200/80 p-12 text-center space-y-4 shadow-xs">
            <div class="w-16 h-16 bg-blue-50 text-blue-600 rounded-2xl flex items-center justify-center mx-auto">
                <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path></svg>
            </div>
            <div>
                <h3 class="text-base font-extrabold text-slate-900">No Providers Configured</h3>
                <p class="text-xs text-slate-500 max-w-md mx-auto mt-1">Please add your 5SIM or Custom SMS provider first before importing countries and services.</p>
            </div>
            <div>
                <a href="/admin/providers.php?action=new" class="inline-flex items-center gap-2 px-5 py-2.5 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold shadow-xs transition-colors">
                    + Add Custom Provider
                </a>
            </div>
        </div>
    <?php else: ?>

    <!-- Provider Selection & Tabs Card -->
    <div class="bg-white rounded-2xl border border-slate-200/80 shadow-xs p-6 space-y-6">
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 pb-5 border-b border-slate-100">
            <!-- Provider Selector Form -->
            <form method="GET" action="/admin/provider_import.php" class="flex items-center gap-3">
                <input type="hidden" name="tab" value="<?= e($selectedTab) ?>">
                <label class="text-xs font-bold text-slate-700">Target Provider:</label>
                <select name="provider_id" onchange="this.form.submit()" class="text-xs px-3.5 py-2 rounded-xl border border-slate-300 font-bold bg-slate-50 focus:bg-white text-slate-900 outline-none">
                    <?php foreach ($providers as $p): ?>
                        <option value="<?= $p['id'] ?>" <?= (int)$p['id'] === $selectedProviderId ? 'selected' : '' ?>>
                            <?= e($p['name']) ?> (<?= e($p['slug']) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>

            <!-- Mode Tabs -->
            <div class="flex items-center gap-2">
                <a href="/admin/provider_import.php?provider_id=<?= $selectedProviderId ?>&tab=countries" class="px-4 py-2 rounded-xl text-xs font-bold transition-colors <?= $selectedTab === 'countries' ? 'bg-blue-600 text-white shadow-xs' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
                    1. Import Countries
                </a>
                <a href="/admin/provider_import.php?provider_id=<?= $selectedProviderId ?>&tab=services" class="px-4 py-2 rounded-xl text-xs font-bold transition-colors <?= $selectedTab === 'services' ? 'bg-blue-600 text-white shadow-xs' : 'bg-slate-100 text-slate-700 hover:bg-slate-200' ?>">
                    2. Import Services & Rates
                </a>
            </div>
        </div>

        <?php if ($selectedTab === 'countries'): ?>
            <!-- TAB 1: COUNTRIES IMPORT -->
            <div class="space-y-6">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <h2 class="text-sm font-bold text-slate-900">Synchronize Countries from <?= e($selectedProvider['name'] ?? 'Provider') ?></h2>
                        <p class="text-xs text-slate-500 mt-0.5">Fetches live supported destination country routes, ISO codes, and calling prefixes from the provider API.</p>
                    </div>

                    <form method="POST" action="/admin/provider_import.php?provider_id=<?= $selectedProviderId ?>&tab=countries">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="preview_countries">
                        <input type="hidden" name="provider_id" value="<?= $selectedProviderId ?>">
                        <button type="submit" class="px-4 py-2.5 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-xl shadow-xs transition-colors flex items-center gap-2">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                            Fetch & Preview Countries from API
                        </button>
                    </form>
                </div>

                <?php if ($previewType === 'countries' && is_array($previewData)): ?>
                    <!-- Real Preview Card -->
                    <div class="border border-blue-200 bg-blue-50/20 rounded-2xl p-5 space-y-4">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-bold text-slate-900">API Response Preview (<?= count($previewData) ?> Countries Found)</h3>
                                <p class="text-xs text-slate-500">Inspect real API records below before committing them to the MySQL database.</p>
                            </div>

                            <form method="POST" action="/admin/provider_import.php?provider_id=<?= $selectedProviderId ?>&tab=countries">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="confirm_import_countries">
                                <input type="hidden" name="provider_id" value="<?= $selectedProviderId ?>">
                                <input type="hidden" name="items_json" value="<?= e(json_encode($previewData)) ?>">
                                <button type="submit" class="px-5 py-2.5 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-xl shadow-xs transition-colors flex items-center gap-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                    Confirm & Save Imported Countries to MySQL
                                </button>
                            </form>
                        </div>

                        <div class="overflow-x-auto max-h-96 rounded-xl border border-slate-200 bg-white">
                            <table class="w-full text-left text-xs">
                                <thead class="bg-slate-50 text-slate-500 font-semibold border-b border-slate-200 sticky top-0">
                                    <tr>
                                        <th class="px-4 py-2.5">Provider ID</th>
                                        <th class="px-4 py-2.5">Country Name</th>
                                        <th class="px-4 py-2.5">ISO Code</th>
                                        <th class="px-4 py-2.5">Dial Prefix</th>
                                        <th class="px-4 py-2.5">Database Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 font-medium">
                                    <?php foreach ($previewData as $c): ?>
                                        <tr class="hover:bg-slate-50/50">
                                            <td class="px-4 py-2.5 font-mono font-bold text-blue-600"><?= e($c['provider_country_id']) ?></td>
                                            <td class="px-4 py-2.5 font-bold text-slate-900"><?= e($c['name']) ?></td>
                                            <td class="px-4 py-2.5 font-mono text-slate-600"><?= e($c['code']) ?></td>
                                            <td class="px-4 py-2.5 font-mono text-slate-600"><?= e($c['prefix'] ?: 'N/A') ?></td>
                                            <td class="px-4 py-2.5">
                                                <?php if ($c['exists']): ?>
                                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                                        Exists (Sync Details)
                                                    </span>
                                                <?php else: ?>
                                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                        + New Country
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>

        <?php elseif ($selectedTab === 'services'): ?>
            <!-- TAB 2: SERVICES IMPORT -->
            <div class="space-y-6">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                    <div>
                        <h2 class="text-sm font-bold text-slate-900">Synchronize Services & Rates from <?= e($selectedProvider['name'] ?? 'Provider') ?></h2>
                        <p class="text-xs text-slate-500 mt-0.5">Fetches live services, real carrier cost prices, and available stock from the provider gateway.</p>
                    </div>
                </div>

                <!-- Country Route Filter & Fetch Form -->
                <form method="POST" action="/admin/provider_import.php?provider_id=<?= $selectedProviderId ?>&tab=services" class="bg-slate-50 p-4 rounded-xl border border-slate-200 flex flex-col sm:flex-row sm:items-end gap-4">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="preview_services">
                    <input type="hidden" name="provider_id" value="<?= $selectedProviderId ?>">

                    <div class="flex-1">
                        <label class="block text-xs font-bold text-slate-700 mb-1">Target Country Route for Pricing & Availability:</label>
                        <select name="country_route" class="w-full text-xs px-3.5 py-2 rounded-xl border border-slate-300 bg-white font-medium text-slate-900">
                            <option value="">Default / Global Rate Table</option>
                            <?php foreach ($allCountries as $c): ?>
                                <option value="<?= e($c['provider_country_id'] ?: $c['code']) ?>" <?= (!empty($_POST['country_route']) && $_POST['country_route'] === ($c['provider_country_id'] ?: $c['code'])) ? 'selected' : '' ?>>
                                    <?= e($c['name']) ?> (<?= e($c['code']) ?>) <?= !empty($c['provider_country_id']) ? " - Provider ID: " . e($c['provider_country_id']) : '' ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <button type="submit" class="px-5 py-2 bg-blue-600 hover:bg-blue-700 text-white text-xs font-bold rounded-xl shadow-xs transition-colors flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path></svg>
                        Fetch & Preview Services from API
                    </button>
                </form>

                <?php if ($previewType === 'services' && is_array($previewData)): ?>
                    <!-- Real Services Preview Card -->
                    <div class="border border-blue-200 bg-blue-50/20 rounded-2xl p-5 space-y-4">
                        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                            <div>
                                <h3 class="text-sm font-bold text-slate-900">API Response Preview (<?= count($previewData) ?> Services Found)</h3>
                                <p class="text-xs text-slate-500">Real applications and costs returned by the provider. Existing selling prices are preserved.</p>
                            </div>

                            <form method="POST" action="/admin/provider_import.php?provider_id=<?= $selectedProviderId ?>&tab=services" class="flex items-center gap-2">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="confirm_import_services">
                                <input type="hidden" name="provider_id" value="<?= $selectedProviderId ?>">
                                <input type="hidden" name="items_json" value="<?= e(json_encode($previewData)) ?>">

                                <!-- Country mapping selector -->
                                <select name="target_country_id" class="text-xs px-2.5 py-2 rounded-xl border border-slate-300 bg-white font-bold">
                                    <option value="0">Catalog Only (No Route Link)</option>
                                    <?php foreach ($allCountries as $c): ?>
                                        <option value="<?= $c['id'] ?>">Link to Route: <?= e($c['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="text" name="target_operator" value="any" placeholder="Operator" title="Target telecom operator (default: any)" class="text-xs px-2.5 py-2 rounded-xl border border-slate-300 bg-white font-mono font-bold w-24">
                                <input type="hidden" name="target_provider_country_id" value="<?= e($_POST['country_route'] ?? '') ?>">

                                <button type="submit" class="px-4 py-2 bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-bold rounded-xl shadow-xs transition-colors flex items-center gap-2">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path></svg>
                                    Confirm & Save to MySQL
                                </button>
                            </form>
                        </div>

                        <div class="overflow-x-auto max-h-96 rounded-xl border border-slate-200 bg-white">
                            <table class="w-full text-left text-xs">
                                <thead class="bg-slate-50 text-slate-500 font-semibold border-b border-slate-200 sticky top-0">
                                    <tr>
                                        <th class="px-4 py-2.5">Provider Code</th>
                                        <th class="px-4 py-2.5">Application Name</th>
                                        <th class="px-4 py-2.5">Internal Code</th>
                                        <th class="px-4 py-2.5">Provider Cost</th>
                                        <th class="px-4 py-2.5">Available Stock</th>
                                        <th class="px-4 py-2.5">Database Status</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-slate-100 font-medium">
                                    <?php foreach ($previewData as $s): ?>
                                        <tr class="hover:bg-slate-50/50">
                                            <td class="px-4 py-2.5 font-mono font-bold text-blue-600"><?= e($s['provider_service_id']) ?></td>
                                            <td class="px-4 py-2.5 font-bold text-slate-900"><?= e($s['name']) ?></td>
                                            <td class="px-4 py-2.5 font-mono text-slate-600"><?= e($s['code']) ?></td>
                                            <td class="px-4 py-2.5 font-mono font-bold text-emerald-700">
                                                <?= $s['cost'] !== null ? number_format((float)$s['cost'], 2) . ' ' . e($selectedProvider['currency'] ?? 'USD') : '<span class="text-slate-400 font-normal">Not Provided</span>' ?>
                                            </td>
                                            <td class="px-4 py-2.5 font-mono text-slate-600">
                                                <?= $s['count'] !== null ? number_format((int)$s['count']) . ' pcs' : '<span class="text-slate-400 font-normal">Not Provided</span>' ?>
                                            </td>
                                            <td class="px-4 py-2.5">
                                                <?php if ($s['exists']): ?>
                                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-amber-50 text-amber-700 border border-amber-200">
                                                        Exists (Update Rate)
                                                    </span>
                                                <?php else: ?>
                                                    <span class="px-2 py-0.5 rounded text-[10px] font-bold bg-emerald-50 text-emerald-700 border border-emerald-200">
                                                        + New Service
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>

    </div>
    <?php endif; ?>

</div>

<?php require_once __DIR__ . '/../app/layouts/admin_footer.php'; ?>
