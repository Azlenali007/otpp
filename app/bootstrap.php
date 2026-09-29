<?php
/**
 * NumVault - Master Application Bootstrap
 */

declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('STORAGE_PATH', APP_ROOT . '/storage');
define('INSTALL_LOCK_FILE', STORAGE_PATH . '/installed.lock');

// Prevent display of raw error outputs to clients
ini_set('display_errors', '0');
error_reporting(E_ALL);

// Custom Error & Exception Handler
set_error_handler(function(int $errno, string $errstr, string $errfile, int $errline) {
    if (!(error_reporting() & $errno)) {
        return false;
    }
    // Log to file
    $msg = "PHP Error [{$errno}]: {$errstr} in {$errfile}:{$errline}";
    @file_put_contents(STORAGE_PATH . '/logs/error.log', "[" . date('Y-m-d H:i:s') . "] {$msg}\n", FILE_APPEND | LOCK_EX);
    return true;
});

set_exception_handler(function(Throwable $e) {
    $msg = "Uncaught Exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine();
    @file_put_contents(STORAGE_PATH . '/logs/error.log', "[" . date('Y-m-d H:i:s') . "] {$msg}\n", FILE_APPEND | LOCK_EX);
    
    http_response_code(500);
    if (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'error' => 'An internal server error occurred. Our team has been notified.']);
    } else {
        echo "<!DOCTYPE html><html lang='en'><head><meta charset='UTF-8'><title>Service Unavailable</title><script src='https://cdn.tailwindcss.com'></script></head><body class='min-h-screen bg-slate-900 text-white flex items-center justify-center p-4'><div class='max-w-md w-full bg-slate-800 p-8 rounded-2xl border border-slate-700 text-center space-y-4'><div class='w-12 h-12 rounded-xl bg-rose-600/20 text-rose-500 flex items-center justify-center mx-auto text-xl font-bold'>!</div><h1 class='text-xl font-bold'>Temporary System Error</h1><p class='text-xs text-slate-400'>The request could not be processed at this time. Please retry in a moment.</p><div class='pt-2'><a href='/' class='px-4 py-2 bg-blue-600 hover:bg-blue-700 text-white rounded-xl text-xs font-bold transition-colors'>Return to Homepage</a></div></div></body></html>";
    }
    exit;
});

// SPL Autoloader for App\ namespace
spl_autoload_register(function (string $class) {
    $prefix = 'App\\';
    $baseDir = APP_ROOT . '/app/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $parts = explode('\\', $relativeClass);
    $className = array_pop($parts);
    $dir = strtolower(implode('/', $parts));
    $file = $baseDir . ($dir ? $dir . '/' : '') . $className . '.php';

    if (file_exists($file)) {
        require_once $file;
    } elseif (file_exists($baseDir . str_replace('\\', '/', $relativeClass) . '.php')) {
        require_once $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    }
});

// Load Core Helpers
require_once APP_ROOT . '/app/helpers/functions.php';

// Start Secure Session
\App\Core\Session::start();

// Apply HTTP Security Headers
\App\Core\Security::applyHeaders();
