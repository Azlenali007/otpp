<?php
/**
 * NumVault - Step 1: Installer Welcome & System Status
 */

declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';

if (file_exists(INSTALL_LOCK_FILE)) {
    die("<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><title>Installer Locked</title><script src='https://cdn.tailwindcss.com'></script></head><body class='min-h-screen bg-slate-100 flex items-center justify-center p-4'><div class='max-w-md w-full bg-white p-8 rounded-2xl border border-slate-200 text-center space-y-4'><div class='w-12 h-12 rounded-xl bg-emerald-50 text-emerald-600 flex items-center justify-center mx-auto text-xl font-bold'>✓</div><h1 class='text-xl font-bold text-slate-900'>System Already Installed & Locked</h1><p class='text-xs text-slate-500'>For security reasons, the installer is permanently locked. To sign in to the application, please proceed to the login portals.</p><div class='pt-2 flex justify-center gap-3'><a href='/auth/login.php' class='px-4 py-2 bg-blue-600 text-white rounded-xl text-xs font-bold'>Customer Sign In</a><a href='/admin/login.php' class='px-4 py-2 bg-slate-800 text-white rounded-xl text-xs font-bold'>Admin Console</a></div></div></body></html>");
}

header('Location: /install/requirements.php');
exit;
