<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/access.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/finance.php';
require_once __DIR__ . '/lib/subscriptions.php';

$pdo = cashflow_db();
cashflow_migrate($pdo);

$page = $_GET['p'] ?? 'dashboard';

$publicPages = ['login'];

if (!in_array($page, $publicPages, true)) {
    $userId = cashflow_require_login();
    $currentUser = cashflow_current_user($pdo);
    if (!$currentUser) {
        cashflow_logout();
        header('Location: index.php?p=login');
        exit;
    }
}

if ($page === 'login') {
    require __DIR__ . '/modules/login.php';
    exit;
}

if ($page === 'logout') {
    cashflow_audit($pdo, $userId, $_SESSION['cf_active_company_id'] ?? null, null, 'logout', 'session');
    cashflow_logout();
    header('Location: index.php?p=login');
    exit;
}

if ($page === 'select_company') {
    require __DIR__ . '/modules/select_company.php';
    exit;
}

// Every remaining page operates inside an active company context, which is
// re-validated against the database on every single request.
$company = cashflow_resolve_active_company($pdo, $userId);
$companyRole = ['role_code' => $company['role_code'], 'role_name' => $company['role_name']];
$isCompanyAdmin = cashflow_is_admin_role($company['role_code']);
$activeProfitCenter = cashflow_resolve_active_profit_center($pdo, $userId, (int)$company['id'], $companyRole);
$accessibleProfitCenters = cashflow_user_profit_centers($pdo, $userId, (int)$company['id'], $companyRole);

$moduleMap = [
    'dashboard' => 'dashboard.php',
    'transactions' => 'transactions.php',
    'profit_centers' => 'profit_centers.php',
    'accounts' => 'accounts.php',
    'reports' => 'reports.php',
    'permissions' => 'permissions.php',
    'audit' => 'audit.php',
    'switch_context' => 'switch_context.php',
    'transport' => 'transport.php',
    'service_orders' => 'service_orders.php',
    'invoices' => 'invoices.php',
    'allocations' => 'allocations.php',
    'documents' => 'documents.php',
    'document_download' => 'document_download.php',
    'billing' => 'billing.php',
    'integrations' => 'integrations.php',
    'integrations_callback' => 'integrations_callback.php',
    'export' => 'export.php',
];

$moduleFile = $moduleMap[$page] ?? null;
if (!$moduleFile || !file_exists(__DIR__ . '/modules/' . $moduleFile)) {
    http_response_code(404);
    $page = 'dashboard';
    $moduleFile = 'dashboard.php';
}

// These pages either redirect (OAuth flows, context switching) or stream
// raw bytes (file download) -- they must run before header.php prints any
// HTML, or the later header()/binary output would fail.
$noLayoutPages = ['switch_context', 'integrations_callback', 'document_download', 'export'];
if (in_array($page, $noLayoutPages, true)) {
    require __DIR__ . '/modules/' . $moduleFile;
    exit;
}

require __DIR__ . '/modules/header.php';
require __DIR__ . '/modules/' . $moduleFile;
require __DIR__ . '/modules/footer.php';
