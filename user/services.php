<?php
/**
 * NumVault - Service & Number Purchase Terminal
 * Real-time Country, Service & Server Selection with atomic wallet deduction
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

use App\Orders\OrderEngine;

$user = require_login();
$pageTitle = "Get Virtual Number";

$pdo = get_db();

// 1. Fetch enabled countries
$countries = $pdo->query("SELECT id, name, code, prefix FROM countries WHERE is_enabled = 1 ORDER BY sort_order ASC, name ASC")->fetchAll();

// Determine selected country
$selectedCountryId = (int)($_GET['country_id'] ?? ($countries[0]['id'] ?? 1));

// 2. Fetch enabled services that have active servers in this country
$sStmt = $pdo->prepare("
    SELECT sv.id, sv.name, sv.code, sv.icon,
           MIN(s.selling_price) AS min_price,
           COUNT(s.id) AS server_count
    FROM services sv
    JOIN servers s ON sv.id = s.service_id AND s.country_id = ? AND s.is_enabled = 1
    JOIN providers p ON s.provider_id = p.id AND p.is_enabled = 1
    WHERE sv.is_enabled = 1
    GROUP BY sv.id, sv.name, sv.code, sv.icon
    ORDER BY sv.sort_order ASC, sv.name ASC
");
$sStmt->execute([$selectedCountryId]);
$availableServices = $sStmt->fetchAll();

// Determine selected service
$selectedServiceId = (int)($_GET['service_id'] ?? ($availableServices[0]['id'] ?? 0));

// 3. Fetch servers for selected country + service
$servers = [];
if ($selectedServiceId > 0) {
    $srvStmt = $pdo->prepare("
        SELECT s.*, p.name AS provider_name
        FROM servers s
        JOIN providers p ON s.provider_id = p.id
        WHERE s.country_id = ? AND s.service_id = ? AND s.is_enabled = 1 AND p.is_enabled = 1
        ORDER BY s.selling_price ASC
    ");
    $srvStmt->execute([$selectedCountryId, $selectedServiceId]);
    $servers = $srvStmt->fetchAll();
}

// 4. Handle Purchase POST action
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $serverId = (int)($_POST['server_id'] ?? 0);
    if ($serverId <= 0) {
        $error = 'Please select a valid server route.';
    } else {
        $result = OrderEngine::purchaseNumber($user['id'], $serverId);
        if ($result['success']) {
            set_flash('success', "Virtual number {$result['phone_number']} assigned! Waiting for SMS verification code.");
            header("Location: /user/order.php?id={$result['order_id']}");
            exit;
        } else {
            $error = $result['error'] ?? 'Failed to allocate virtual number.';
        }
    }
}

require_once __DIR__ . '/../app/layouts/user_header.php';
?>

<div class="space-y-6">

    <!-- Top Purchase Bar -->
    <div class="bg-white rounded-2xl border border-slate-200/80 p-6 shadow-xs flex flex-col md:flex-row md:items-center justify-between gap-4">
        <div>
            <h1 class="text-xl font-extrabold text-slate-900 tracking-tight">Select Service & Number</h1>
            <p class="text-xs text-slate-500 mt-0.5">Carrier routes with real-time stock allocation and live SMS delivery</p>
        </div>
        <div class="flex items-center gap-2">
            <span class="text-xs text-slate-500">Wallet:</span>
            <span class="text-base font-extrabold text-blue-600 font-mono"><?= format_price($user['balance']) ?></span>
            <a href="/user/wallet.php" class="ml-2 px-3 py-1.5 bg-slate-100 hover:bg-slate-200 text-slate-800 text-xs font-bold rounded-lg transition-colors">
                Top Up
            </a>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="p-4 rounded-xl bg-rose-50 border border-rose-200 text-rose-800 text-xs font-medium flex items-center gap-2.5">
            <?= icon('alert-circle', 'w-5 h-5 flex-shrink-0') ?>
            <span><?= e($error) ?></span>
        </div>
    <?php endif; ?>

    <!-- Two-Column Layout: Left (Country & Services), Right (Servers & Purchase) -->
    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">

        <!-- Left: Country & Service Selector -->
        <div class="lg:col-span-7 space-y-6">

            <!-- Country Selection Horizontal Ribbon -->
            <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs space-y-3">
                <label class="block text-xs font-bold text-slate-900 uppercase tracking-wider">1. Select Target Country</label>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                    <?php foreach ($countries as $c): ?>
                        <a href="/user/services.php?country_id=<?= $c['id'] ?>" class="flex items-center justify-between p-3 rounded-xl border text-xs font-semibold transition-colors <?= $c['id'] === $selectedCountryId ? 'bg-blue-50 border-blue-500 text-blue-700 shadow-xs' : 'border-slate-200 hover:bg-slate-50 text-slate-700' ?>">
                            <span class="truncate"><?= e($c['name']) ?></span>
                            <span class="font-mono text-[11px] text-slate-400 font-bold ml-1"><?= e($c['prefix']) ?></span>
                        </a>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Service List -->
            <div class="bg-white p-5 rounded-2xl border border-slate-200/80 shadow-xs space-y-4">
                <div class="flex items-center justify-between">
                    <label class="block text-xs font-bold text-slate-900 uppercase tracking-wider">2. Choose Service</label>
                    <span class="text-[11px] text-slate-400"><?= count($availableServices) ?> services available</span>
                </div>

                <!-- Instant Search Input -->
                <div class="relative">
                    <input type="text" id="serviceSearchInput" placeholder="Filter services (e.g. WhatsApp, Telegram)..." class="w-full text-xs px-3.5 py-2.5 pl-9 rounded-xl border border-slate-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 outline-none transition-colors">
                    <div class="absolute left-3 top-2.5 text-slate-400">
                        <?= icon('search', 'w-4 h-4') ?>
                    </div>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 max-h-96 overflow-y-auto pr-1" id="serviceListContainer">
                    <?php if (!empty($availableServices)): ?>
                        <?php foreach ($availableServices as $sv): ?>
                            <a href="/user/services.php?country_id=<?= $selectedCountryId ?>&service_id=<?= $sv['id'] ?>" class="service-item flex items-center justify-between p-3 rounded-xl border transition-colors <?= $sv['id'] === $selectedServiceId ? 'bg-blue-600 text-white border-blue-600 shadow-sm' : 'border-slate-200 hover:bg-slate-50 text-slate-800' ?>" data-name="<?= strtolower(e($sv['name'])) ?>">
                                <div class="flex items-center gap-2.5 truncate">
                                    <div class="w-8 h-8 rounded-lg flex items-center justify-center <?= $sv['id'] === $selectedServiceId ? 'bg-blue-500 text-white' : 'bg-slate-100 text-blue-600' ?>">
                                        <?= icon($sv['icon'] ?: 'shield', 'w-4 h-4') ?>
                                    </div>
                                    <div class="text-xs font-bold truncate"><?= e($sv['name']) ?></div>
                                </div>
                                <div class="text-right flex-shrink-0 ml-2">
                                    <div class="font-mono font-bold text-xs <?= $sv['id'] === $selectedServiceId ? 'text-white' : 'text-slate-900' ?>">
                                        from <?= format_price($sv['min_price']) ?>
                                    </div>
                                    <div class="text-[10px] <?= $sv['id'] === $selectedServiceId ? 'text-blue-100' : 'text-slate-400' ?>">
                                        <?= $sv['server_count'] ?> server(s)
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="col-span-2 text-center py-8 text-slate-400 text-xs">
                            No active services currently routed in this country. Please pick another country.
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        </div>

        <!-- Right: Server Route & Purchase Terminal -->
        <div class="lg:col-span-5 space-y-6">
            <div class="bg-white p-6 rounded-2xl border border-slate-200/80 shadow-xs space-y-5">
                <div class="border-b border-slate-100 pb-3">
                    <label class="block text-xs font-bold text-slate-900 uppercase tracking-wider">3. Select Route & Allocate</label>
                    <p class="text-xs text-slate-500 mt-0.5">Multi-server redundancy for high SMS delivery success</p>
                </div>

                <?php if (!empty($servers)): ?>
                    <form method="POST" action="/user/services.php?country_id=<?= $selectedCountryId ?>&service_id=<?= $selectedServiceId ?>" class="space-y-4">
                        <?= csrf_field() ?>

                        <div class="space-y-2.5">
                            <?php foreach ($servers as $idx => $srv): ?>
                                <label class="flex items-center justify-between p-3.5 rounded-xl border border-slate-200 hover:border-blue-400 cursor-pointer transition-colors has-[:checked]:border-blue-600 has-[:checked]:bg-blue-50/40">
                                    <div class="flex items-center gap-3">
                                        <input type="radio" name="server_id" value="<?= $srv['id'] ?>" <?= $idx === 0 ? 'checked' : '' ?> class="w-4 h-4 text-blue-600 focus:ring-blue-500">
                                        <div>
                                            <div class="text-xs font-bold text-slate-900"><?= e($srv['server_name']) ?></div>
                                            <div class="text-[11px] text-slate-400">Carrier Line &bull; High Availability</div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="font-mono font-extrabold text-sm text-blue-600">
                                            <?= format_price($srv['selling_price']) ?>
                                        </div>
                                    </div>
                                </label>
                            <?php endforeach; ?>
                        </div>

                        <!-- Refund Assurance Notice -->
                        <div class="p-3 rounded-xl bg-slate-50 border border-slate-200 text-slate-600 text-xs flex items-start gap-2">
                            <?= icon('check', 'w-4 h-4 text-emerald-600 flex-shrink-0 mt-0.5') ?>
                            <span>
                                If the OTP SMS is not received within the maximum countdown timer, your balance is 100% automatically refunded back to your wallet.
                            </span>
                        </div>

                        <!-- Buy Action Button -->
                        <button type="submit" class="w-full py-3.5 px-4 bg-blue-600 hover:bg-blue-700 text-white font-bold text-sm rounded-xl shadow-md shadow-blue-600/20 transition-colors flex items-center justify-center gap-2">
                            <?= icon('phone', 'w-4 h-4') ?>
                            <span>Buy Virtual Number Now</span>
                        </button>
                    </form>
                <?php else: ?>
                    <div class="py-12 text-center text-slate-400 text-xs space-y-2">
                        <div class="w-10 h-10 rounded-xl bg-slate-100 text-slate-400 flex items-center justify-center mx-auto">
                            <?= icon('phone', 'w-5 h-5') ?>
                        </div>
                        <p>Select a service from the left list to view available server lines and prices.</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

    </div>

</div>

<script>
    // Live filter search for service list
    const searchInput = document.getElementById('serviceSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            const items = document.querySelectorAll('.service-item');
            items.forEach(item => {
                const name = item.getAttribute('data-name') || '';
                if (name.includes(query)) {
                    item.style.display = 'flex';
                } else {
                    item.style.display = 'none';
                }
            });
        });
    }
</script>

<?php require_once __DIR__ . '/../app/layouts/user_footer.php'; ?>
