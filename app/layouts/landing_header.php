<?php
/**
 * NumVault - Landing Page Layout Header
 * Public Document-flow Navigation, White + Premium Blue Theme
 */

declare(strict_types=1);

$siteName = get_setting('site_name', 'NumVault');
$user = current_user();
?>
<!DOCTYPE html>
<html lang="en" class="h-full bg-white text-slate-900 antialiased">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($pageTitle ?? 'Carrier-Grade Virtual Number & SMS OTP Marketplace') ?> - <?= e($siteName) ?></title>
    <meta name="description" content="Instant virtual phone numbers for SMS OTP verification across 100+ countries with automatic refunds.">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        primary: {
                            50: '#eff6ff',
                            100: '#dbeafe',
                            500: '#3b82f6',
                            600: '#2563eb',
                            700: '#1d4ed8',
                            800: '#1e40af',
                            900: '#1e3a8a',
                        }
                    }
                }
            }
        }
    </script>
</head>
<body class="min-h-full flex flex-col font-sans text-slate-800 bg-white">

    <!-- Landing Header (Document flow, no sticky/fixed) -->
    <header class="w-full bg-white border-b border-slate-200">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="flex items-center justify-between h-20">
                <!-- Brand Logo -->
                <a href="/" class="flex items-center gap-3 group">
                    <div class="w-11 h-11 rounded-xl bg-blue-600 flex items-center justify-center text-white shadow-md shadow-blue-500/20 group-hover:scale-[1.02] transition-transform">
                        <?= icon('shield', 'w-6 h-6') ?>
                    </div>
                    <div>
                        <span class="text-xl font-bold tracking-tight text-slate-900 flex items-center gap-1.5">
                            <?= e($siteName) ?>
                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-semibold bg-blue-50 text-blue-700 border border-blue-200">OTP</span>
                        </span>
                        <span class="block text-xs text-slate-500 font-medium">Virtual Number Marketplace</span>
                    </div>
                </a>

                <!-- Navigation Links -->
                <nav class="hidden md:flex items-center gap-8 text-sm font-semibold text-slate-600">
                    <a href="/#services" class="hover:text-blue-600 transition-colors">Services</a>
                    <a href="/#countries" class="hover:text-blue-600 transition-colors">Countries</a>
                    <a href="/#how-it-works" class="hover:text-blue-600 transition-colors">How It Works</a>
                    <a href="/#features" class="hover:text-blue-600 transition-colors">Pricing & Policy</a>
                </nav>

                <!-- Auth Action Buttons -->
                <div class="flex items-center gap-3">
                    <?php if ($user): ?>
                        <a href="/user/dashboard.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 shadow-sm transition-colors">
                            <?= icon('user', 'w-4 h-4') ?>
                            <span>Dashboard</span>
                            <span class="bg-blue-500/60 px-2 py-0.5 rounded text-xs"><?= format_price($user['balance']) ?></span>
                        </a>
                    <?php else: ?>
                        <a href="/auth/login.php" class="text-sm font-semibold text-slate-700 hover:text-blue-600 px-3 py-2 transition-colors">
                            Sign In
                        </a>
                        <a href="/auth/register.php" class="inline-flex items-center gap-2 px-4 py-2.5 rounded-lg text-sm font-semibold text-white bg-blue-600 hover:bg-blue-700 shadow-sm transition-colors">
                            <span>Get Started</span>
                            <?= icon('arrow-right', 'w-4 h-4') ?>
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </header>

    <!-- Main Landing Content Container -->
    <main class="flex-1 w-full">
