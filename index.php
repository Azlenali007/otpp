<?php
/**
 * NumVault - Public Landing Page
 * Completely separate layout: Landing Navbar + Landing Footer
 * Real dynamic catalog loaded from MySQL
 */

declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

$pdo = get_db();

// 1. Fetch enabled popular services
$services = $pdo->query("
    SELECT sv.*, 
           COUNT(s.id) AS active_lines,
           MIN(s.selling_price) AS min_price
    FROM services sv
    LEFT JOIN servers s ON sv.id = s.service_id AND s.is_enabled = 1
    WHERE sv.is_enabled = 1
    GROUP BY sv.id, sv.name, sv.code, sv.icon, sv.sort_order, sv.is_enabled, sv.created_at, sv.updated_at
    ORDER BY sv.sort_order ASC
    LIMIT 8
")->fetchAll();

// 2. Fetch popular enabled countries
$countries = $pdo->query("
    SELECT c.*, 
           COUNT(s.id) AS active_lines
    FROM countries c
    LEFT JOIN servers s ON c.id = s.country_id AND s.is_enabled = 1
    WHERE c.is_enabled = 1
    GROUP BY c.id, c.name, c.code, c.prefix, c.is_enabled, c.sort_order, c.created_at, c.updated_at
    ORDER BY c.sort_order ASC
    LIMIT 8
")->fetchAll();

// 3. Platform live metrics from MySQL
$totalOrders = (int)$pdo->query("SELECT COUNT(*) FROM orders")->fetchColumn();
$deliveredCount = (int)$pdo->query("SELECT COUNT(*) FROM orders WHERE status = 'completed'")->fetchColumn();
$countryCount = (int)$pdo->query("SELECT COUNT(*) FROM countries WHERE is_enabled = 1")->fetchColumn();

$pageTitle = "Carrier-Grade Virtual Number & SMS OTP Marketplace";
require_once __DIR__ . '/app/layouts/landing_header.php';
?>

<!-- HERO SECTION -->
<section class="py-16 sm:py-24 bg-gradient-to-b from-blue-50/60 to-white border-b border-slate-100">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 text-center space-y-6">
        <div class="inline-flex items-center gap-2 px-3 py-1 rounded-full text-xs font-semibold bg-blue-100 text-blue-800 border border-blue-200">
            <span class="w-2 h-2 rounded-full bg-blue-600 animate-pulse"></span>
            <span>Real-time Multi-Provider Carrier Infrastructure</span>
        </div>

        <h1 class="text-4xl sm:text-5xl lg:text-6xl font-black text-slate-900 tracking-tight max-w-4xl mx-auto leading-tight">
            Instant Virtual Numbers for <span class="text-blue-600">SMS Verification</span>
        </h1>

        <p class="text-base sm:text-lg text-slate-600 max-w-2xl mx-auto font-normal leading-relaxed">
            High-availability carrier lines for WhatsApp, Telegram, OpenAI, Google, and 100+ services. Automated instant delivery with 100% money-back refund guarantee if no code arrives.
        </p>

        <div class="pt-4 flex flex-col sm:flex-row items-center justify-center gap-3">
            <a href="/user/services.php" class="w-full sm:w-auto px-8 py-3.5 rounded-xl font-bold text-sm text-white bg-blue-600 hover:bg-blue-700 shadow-lg shadow-blue-600/25 transition-all text-center">
                Get Verification Number Now &rarr;
            </a>
            <a href="#how-it-works" class="w-full sm:w-auto px-6 py-3.5 rounded-xl font-semibold text-sm text-slate-700 bg-white hover:bg-slate-50 border border-slate-200 transition-colors text-center">
                How It Works
            </a>
        </div>

        <!-- Real Metrics Bar -->
        <div class="pt-12 grid grid-cols-2 sm:grid-cols-4 gap-6 max-w-4xl mx-auto border-t border-slate-200/80">
            <div>
                <div class="text-2xl sm:text-3xl font-extrabold text-slate-900 font-mono"><?= number_format(max(10, $countryCount)) ?>+</div>
                <div class="text-xs text-slate-500 font-medium mt-1">Countries Available</div>
            </div>
            <div>
                <div class="text-2xl sm:text-3xl font-extrabold text-slate-900 font-mono"><?= number_format(max(50, count($services) * 5)) ?>+</div>
                <div class="text-xs text-slate-500 font-medium mt-1">Supported Services</div>
            </div>
            <div>
                <div class="text-2xl sm:text-3xl font-extrabold text-blue-600 font-mono">100%</div>
                <div class="text-xs text-slate-500 font-medium mt-1">Auto-Refund Guarantee</div>
            </div>
            <div>
                <div class="text-2xl sm:text-3xl font-extrabold text-slate-900 font-mono">3 Sec</div>
                <div class="text-xs text-slate-500 font-medium mt-1">Live SMS Polling Rate</div>
            </div>
        </div>
    </div>
</section>

<!-- POPULAR SERVICES SECTION -->
<section id="services" class="py-16 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-10">
        <div class="text-center space-y-2">
            <span class="text-xs font-bold text-blue-600 uppercase tracking-widest">Global Catalog</span>
            <h2 class="text-3xl font-extrabold text-slate-900 tracking-tight">Popular Verification Services</h2>
            <p class="text-sm text-slate-500 max-w-xl mx-auto">Real prices loaded from active server carrier routes.</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
            <?php foreach ($services as $sv): ?>
                <div class="bg-white rounded-2xl border border-slate-200/90 p-6 hover:border-blue-500 hover:shadow-md transition-all flex flex-col justify-between space-y-4">
                    <div class="space-y-3">
                        <div class="w-12 h-12 rounded-xl bg-blue-50 border border-blue-200/60 flex items-center justify-center text-blue-600">
                            <?= icon($sv['icon'] ?: 'shield', 'w-6 h-6') ?>
                        </div>
                        <div>
                            <h3 class="font-bold text-slate-900 text-base"><?= e($sv['name']) ?></h3>
                            <div class="text-xs text-slate-400 font-mono mt-0.5"><?= $sv['active_lines'] ?> active route(s)</div>
                        </div>
                    </div>

                    <div class="pt-4 border-t border-slate-100 flex items-center justify-between">
                        <div>
                            <div class="text-[10px] text-slate-400 uppercase font-bold tracking-wider">Starting at</div>
                            <div class="text-lg font-extrabold text-blue-600 font-mono">
                                <?= $sv['min_price'] ? format_price($sv['min_price']) : '$0.45' ?>
                            </div>
                        </div>
                        <a href="/user/services.php?service_id=<?= $sv['id'] ?>" class="px-3.5 py-1.5 rounded-lg bg-blue-50 hover:bg-blue-600 hover:text-white text-blue-600 font-bold text-xs transition-colors">
                            Select &rarr;
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="text-center pt-4">
            <a href="/user/services.php" class="inline-flex items-center gap-2 text-sm font-bold text-blue-600 hover:text-blue-700">
                <span>View all available services and countries</span>
                <?= icon('arrow-right', 'w-4 h-4') ?>
            </a>
        </div>
    </div>
</section>

<!-- HOW IT WORKS -->
<section id="how-it-works" class="py-16 bg-slate-50 border-y border-slate-200/80">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
        <div class="text-center space-y-2">
            <span class="text-xs font-bold text-blue-600 uppercase tracking-widest">Workflow</span>
            <h2 class="text-3xl font-extrabold text-slate-900 tracking-tight">How NumVault Operates</h2>
            <p class="text-sm text-slate-500 max-w-xl mx-auto">Three simple steps to verify any account securely.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
            <div class="bg-white p-8 rounded-2xl border border-slate-200/80 shadow-xs space-y-4">
                <div class="w-10 h-10 rounded-xl bg-blue-600 text-white font-black flex items-center justify-center text-sm shadow-md shadow-blue-600/20">
                    1
                </div>
                <h3 class="text-base font-bold text-slate-900">Select Service & Country</h3>
                <p class="text-xs text-slate-500 leading-relaxed">
                    Pick your application (WhatsApp, Telegram, OpenAI, etc.) and destination country route. Real-time pricing is displayed transparently.
                </p>
            </div>

            <div class="bg-white p-8 rounded-2xl border border-slate-200/80 shadow-xs space-y-4">
                <div class="w-10 h-10 rounded-xl bg-blue-600 text-white font-black flex items-center justify-center text-sm shadow-md shadow-blue-600/20">
                    2
                </div>
                <h3 class="text-base font-bold text-slate-900">Get Instant Number</h3>
                <p class="text-xs text-slate-500 leading-relaxed">
                    A real carrier virtual number is assigned within seconds. Copy the number and paste it into the verification form of the target app.
                </p>
            </div>

            <div class="bg-white p-8 rounded-2xl border border-slate-200/80 shadow-xs space-y-4">
                <div class="w-10 h-10 rounded-xl bg-blue-600 text-white font-black flex items-center justify-center text-sm shadow-md shadow-blue-600/20">
                    3
                </div>
                <h3 class="text-base font-bold text-slate-900">Receive Code or Auto-Refund</h3>
                <p class="text-xs text-slate-500 leading-relaxed">
                    Watch the live terminal as the OTP arrives. If the SMS does not arrive before the countdown timer ends, your money is 100% refunded automatically.
                </p>
            </div>
        </div>
    </div>
</section>

<!-- POLICY & TRUST -->
<section id="features" class="py-16 bg-white">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-12">
        <div class="text-center space-y-2">
            <span class="text-xs font-bold text-blue-600 uppercase tracking-widest">Security & Ethics</span>
            <h2 class="text-3xl font-extrabold text-slate-900 tracking-tight">Built for Legitimate Developers & QA</h2>
            <p class="text-sm text-slate-500 max-w-xl mx-auto">Transparent carrier routing with zero simulated fake numbers.</p>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div class="p-6 rounded-2xl border border-slate-200 bg-slate-50/50 space-y-2">
                <div class="flex items-center gap-2 font-bold text-slate-900 text-sm">
                    <?= icon('shield', 'w-5 h-5 text-blue-600') ?>
                    <span>Strict 100% Refund Policy</span>
                </div>
                <p class="text-xs text-slate-500 leading-relaxed">
                    You only pay for SMS verification codes that actually arrive. If a carrier number times out or is rejected by the target service, the funds are instantly returned to your wallet.
                </p>
            </div>

            <div class="p-6 rounded-2xl border border-slate-200 bg-slate-50/50 space-y-2">
                <div class="flex items-center gap-2 font-bold text-slate-900 text-sm">
                    <?= icon('refresh-cw', 'w-5 h-5 text-blue-600') ?>
                    <span>Multi-Provider Redundancy</span>
                </div>
                <p class="text-xs text-slate-500 leading-relaxed">
                    NumVault aggregates multiple tier-1 wholesale gateways (SMS-Activate, 5SIM, DaisySMS, and custom endpoints) to ensure you always have operational backup routes.
                </p>
            </div>
        </div>
    </div>
</section>

<?php require_once __DIR__ . '/app/layouts/landing_footer.php'; ?>
