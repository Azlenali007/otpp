<?php
/**
 * NumVault - User Panel Layout Footer
 */

declare(strict_types=1);

$siteName = get_setting('site_name', 'NumVault');
?>
    </main>

    <!-- User Footer (Document flow, clean professional) -->
    <footer class="w-full bg-white border-t border-slate-200 py-6 mt-auto">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-500">
            <div>
                &copy; <?= date('Y') ?> <?= e($siteName) ?> Customer Portal. Carrier-grade infrastructure.
            </div>
            <div class="flex items-center gap-6">
                <a href="/user/support.php" class="hover:text-blue-600 transition-colors">Help Desk</a>
                <a href="/user/orders.php" class="hover:text-blue-600 transition-colors">Order Status</a>
                <a href="/auth/logout.php" class="hover:text-rose-600 transition-colors">Sign Out</a>
            </div>
        </div>
    </footer>

</body>
</html>
