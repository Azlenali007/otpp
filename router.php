<?php
/**
 * NumVault - Built-in Server Router with Maximum Security Hardening
 * Prevents direct access to sensitive directories, enforces clean routes & security rules
 */

declare(strict_types=1);

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$uri = urldecode($uri);

// 1. Direct Security Blocks: Protect /app/, /storage/, hidden files, credentials, logs, SQL
if (
    str_starts_with($uri, '/app/') ||
    str_starts_with($uri, '/storage/') ||
    str_contains($uri, '/.env') ||
    str_contains($uri, '/.git') ||
    preg_match('/\.(sql|log|lock|ini|sh|bak|dist|json|md|yaml|yml)$/i', $uri)
) {
    http_response_code(403);
    die("Access Denied: Direct access to internal system resources is prohibited.");
}

// 2. Physical static file serving (CSS, JS, images, fonts)
$staticPath = __DIR__ . $uri;
if ($uri !== '/' && file_exists($staticPath) && !is_dir($staticPath) && !str_ends_with($staticPath, '.php')) {
    return false;
}

// 3. Root Landing Route
if ($uri === '/' || $uri === '/index.html' || $uri === '/index.php') {
    require __DIR__ . '/index.php';
    exit;
}

// 4. Legacy Route Aliases (Backwards compatibility)
$legacyMap = [
    '/login.php'             => '/auth/login.php',
    '/register.php'          => '/auth/register.php',
    '/logout.php'            => '/auth/logout.php',
    '/admin_login.php'       => '/admin/login.php',
    '/admin.php'             => '/admin/dashboard.php',
    '/admin_dashboard.php'   => '/admin/dashboard.php',
    '/admin_users.php'       => '/admin/users.php',
    '/admin_providers.php'   => '/admin/providers.php',
    '/admin_countries.php'   => '/admin/countries.php',
    '/admin_services.php'    => '/admin/services.php',
    '/admin_servers.php'     => '/admin/servers.php',
    '/admin_orders.php'      => '/admin/orders.php',
    '/admin_payments.php'    => '/admin/payments.php',
    '/admin_tickets.php'     => '/admin/tickets.php',
    '/admin_settings.php'    => '/admin/settings.php',
    '/user_dashboard.php'    => '/user/dashboard.php',
    '/user_buy.php'          => '/user/services.php',
    '/services.php'          => '/user/services.php',
    '/user_order.php'        => '/user/order.php',
    '/order.php'             => '/user/order.php',
    '/user_orders.php'       => '/user/orders.php',
    '/orders.php'            => '/user/orders.php',
    '/user_wallet.php'       => '/user/wallet.php',
    '/wallet.php'            => '/user/wallet.php',
    '/user_payment.php'      => '/user/payment.php',
    '/user_support.php'      => '/user/support.php',
    '/tickets.php'           => '/user/support.php',
    '/user_ticket.php'       => '/user/ticket.php',
    '/user_notifications.php'=> '/user/notifications.php',
    '/notifications.php'     => '/user/notifications.php',
    '/install.php'           => '/install/index.php',
    '/cron.php'              => '/cron/index.php',
];

if (isset($legacyMap[$uri])) {
    $target = $legacyMap[$uri];
    header("Location: {$target}", true, 301);
    exit;
}

// 5. Direct PHP Script Execution
if (file_exists($staticPath) && str_ends_with($staticPath, '.php')) {
    require $staticPath;
    exit;
}

// 6. Directory Index Resolution (e.g. /user/ -> /user/index.php)
if (is_dir($staticPath)) {
    $dirIndex = rtrim($staticPath, '/') . '/index.php';
    if (file_exists($dirIndex)) {
        require $dirIndex;
        exit;
    }
}

// 7. Clean URLs without .php extension (e.g. /user/dashboard -> /user/dashboard.php)
$cleanPath = __DIR__ . $uri . '.php';
if (file_exists($cleanPath)) {
    require $cleanPath;
    exit;
}

// 8. 404 Not Found Page
http_response_code(404);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>404 - Resource Not Found</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="h-screen bg-slate-50 flex items-center justify-center font-sans text-slate-800 p-4">
    <div class="text-center space-y-4 max-w-md w-full p-8 bg-white rounded-2xl border border-slate-200 shadow-sm">
        <h1 class="text-4xl font-black text-slate-900">404</h1>
        <p class="text-xs text-slate-600">The requested resource could not be found or has moved.</p>
        <div class="pt-2">
            <a href="/" class="px-5 py-2.5 rounded-xl bg-blue-600 text-white font-bold text-xs hover:bg-blue-700 transition-colors">
                Return to NumVault
            </a>
        </div>
    </div>
</body>
</html>
