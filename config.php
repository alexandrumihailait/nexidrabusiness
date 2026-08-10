<?php
/**
 * Cashflow platform bootstrap: session, DB connection, error handling.
 * Configure via environment variables (falls back to local dev defaults).
 */

$cashflowIsHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443;

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure' => $cashflowIsHttps,
    ]);
    session_start();
}

define('CASHFLOW_ROOT', __DIR__);
define('CASHFLOW_DEBUG', getenv('CASHFLOW_DEBUG') === '1');

error_reporting(E_ALL);
ini_set('display_errors', CASHFLOW_DEBUG ? '1' : '0');
ini_set('log_errors', '1');

$cashflowLogDir = CASHFLOW_ROOT . '/storage/logs';
if (!is_dir($cashflowLogDir)) {
    @mkdir($cashflowLogDir, 0750, true);
}
ini_set('error_log', $cashflowLogDir . '/php-error.log');

// Baseline hardening headers on every response. Every asset (Bootstrap,
// icons, fonts) is self-hosted under assets/vendor/ -- CSP only needs
// 'self' plus 'unsafe-inline' for the small number of inline style=""
// attributes and onclick/onchange handlers used throughout the UI.
// Nothing in this app depends on a third-party CDN being reachable.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; font-src 'self';");
if ($cashflowIsHttps) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

/**
 * Uncaught exceptions must never leak a stack trace to the client in
 * production -- log it server-side and show a generic message instead.
 */
set_exception_handler(function (Throwable $e) {
    error_log('[cashflow] Uncaught: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    http_response_code(500);
    if (CASHFLOW_DEBUG) {
        echo '<pre>' . htmlspecialchars((string)$e) . '</pre>';
    } else {
        echo 'A apărut o eroare neașteptată. Am notat-o și ne ocupăm de ea.';
    }
});

function cashflow_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $host = getenv('CASHFLOW_DB_HOST') ?: '127.0.0.1';
    $port = getenv('CASHFLOW_DB_PORT') ?: '3306';
    $name = getenv('CASHFLOW_DB_NAME') ?: 'cashflow';
    $user = getenv('CASHFLOW_DB_USER') ?: 'root';
    $pass = getenv('CASHFLOW_DB_PASS') ?: '';

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    return $pdo;
}
