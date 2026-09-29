<?php
/**
 * NumVault - Landing Page Layout Footer
 */

declare(strict_types=1);

$siteName = get_setting('site_name', 'NumVault');
?>
    </main>

    <!-- Landing Footer (Clean non-sticky, distinct from user & admin) -->
    <footer class="w-full bg-white border-t border-slate-200/80 py-14 mt-16">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-10">
                <!-- Col 1: Brand Info -->
                <div class="space-y-4 md:col-span-1">
                    <div class="flex items-center gap-2.5">
                        <div class="w-9 h-9 rounded-lg bg-blue-600 flex items-center justify-center text-white shadow-sm">
                            <?= icon('shield', 'w-5 h-5') ?>
                        </div>
                        <span class="text-lg font-bold text-slate-900"><?= e($siteName) ?></span>
                    </div>
                    <p class="text-sm text-slate-500 leading-relaxed">
                        High-availability SMS verification platform offering instant virtual numbers across 100+ countries with automatic refund protection.
                    </p>
                    <div class="text-xs text-slate-400">
                        &copy; <?= date('Y') ?> <?= e($siteName) ?>. All rights reserved.
                    </div>
                </div>

                <!-- Col 2: Fast Navigation -->
                <div class="space-y-3">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-900">Supported Services</h3>
                    <ul class="space-y-2 text-sm text-slate-600">
                        <li><a href="/#services" class="hover:text-blue-600 transition-colors">WhatsApp Numbers</a></li>
                        <li><a href="/#services" class="hover:text-blue-600 transition-colors">Telegram Verification</a></li>
                        <li><a href="/#services" class="hover:text-blue-600 transition-colors">OpenAI & ChatGPT</a></li>
                        <li><a href="/#services" class="hover:text-blue-600 transition-colors">Google & Gmail</a></li>
                        <li><a href="/#services" class="hover:text-blue-600 transition-colors">Instagram & Meta</a></li>
                    </ul>
                </div>

                <!-- Col 3: Policy & Transparency -->
                <div class="space-y-3">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-900">Guarantees & Policy</h3>
                    <ul class="space-y-2 text-sm text-slate-600">
                        <li class="flex items-center gap-2">
                            <?= icon('check', 'w-4 h-4 text-emerald-600') ?>
                            <span>100% Automatic Refund if no SMS</span>
                        </li>
                        <li class="flex items-center gap-2">
                            <?= icon('check', 'w-4 h-4 text-emerald-600') ?>
                            <span>Private & Non-Reused Numbers</span>
                        </li>
                        <li class="flex items-center gap-2">
                            <?= icon('check', 'w-4 h-4 text-emerald-600') ?>
                            <span>No Hidden Fees or Subscriptions</span>
                        </li>
                        <li class="flex items-center gap-2">
                            <?= icon('check', 'w-4 h-4 text-emerald-600') ?>
                            <span>Legitimate verification use only</span>
                        </li>
                    </ul>
                </div>

                <!-- Col 4: Quick Portals -->
                <div class="space-y-3">
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-900">Access Portals</h3>
                    <div class="flex flex-col gap-2.5 text-sm">
                        <a href="/auth/register.php" class="text-blue-600 hover:text-blue-700 font-medium flex items-center gap-1.5">
                            <span>Create Customer Account</span>
                            <?= icon('arrow-right', 'w-4 h-4') ?>
                        </a>
                        <a href="/auth/login.php" class="text-slate-600 hover:text-blue-600 font-medium">Customer Sign In</a>
                        <a href="/admin/login.php" class="text-slate-400 hover:text-slate-600 text-xs mt-2">Administrative Console</a>
                    </div>
                </div>
            </div>
        </div>
    </footer>
</body>
</html>
