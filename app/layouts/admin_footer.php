<?php
/**
 * NumVault - Master Administration Console Layout Footer
 */

declare(strict_types=1);

$siteName = get_setting('site_name', 'NumVault');
?>
    </main>

    <!-- Admin Footer (Document flow, distinct dark slate) -->
    <footer class="w-full bg-slate-900 border-t border-slate-800 py-6 mt-auto">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-4 text-xs text-slate-400">
            <div>
                &copy; <?= date('Y') ?> <?= e($siteName) ?> Master Administration Control Hub. Strict RBAC Enforcement.
            </div>
            <div class="flex items-center gap-6">
                <a href="/admin/logs.php" class="hover:text-blue-400 transition-colors">Audit & Security Logs</a>
                <a href="/admin/settings.php" class="hover:text-blue-400 transition-colors">Config</a>
                <a href="/auth/logout.php" class="hover:text-rose-400 transition-colors">Sign Out</a>
            </div>
        </div>
    </footer>

</body>
</html>
