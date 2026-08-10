<?php
/**
 * Front controller for the platform (super-admin) panel -- operates
 * across every company, outside the per-company role model in index.php.
 * Every page here starts from cashflow_require_platform_admin(), which is
 * re-checked against the database on every request like every other
 * access() guard in this app.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/access.php';
require_once __DIR__ . '/lib/helpers.php';
require_once __DIR__ . '/lib/finance.php';
require_once __DIR__ . '/lib/subscriptions.php';

$pdo = cashflow_db();
cashflow_migrate($pdo);

$userId = cashflow_require_login();
$currentUser = cashflow_current_user($pdo);
if (!$currentUser) {
    cashflow_logout();
    header('Location: index.php?p=login');
    exit;
}

cashflow_require_platform_admin($pdo, $userId);

$page = $_GET['p'] ?? 'dashboard';

$moduleMap = [
    'dashboard' => 'dashboard.php',
    'companies' => 'companies.php',
    'users' => 'users.php',
    'plans' => 'plans.php',
    'rbac' => 'rbac.php',
    'audit' => 'audit.php',
];

$moduleFile = $moduleMap[$page] ?? null;
if (!$moduleFile || !file_exists(__DIR__ . '/admin/' . $moduleFile)) {
    http_response_code(404);
    $page = 'dashboard';
    $moduleFile = 'dashboard.php';
}

require __DIR__ . '/admin/header.php';
require __DIR__ . '/admin/' . $moduleFile;
require __DIR__ . '/admin/footer.php';
