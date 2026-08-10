<?php
/**
 * Thin redirect endpoint for switching company/profit-center context from
 * simple <select> or JS callers. It does no work itself -- cid/pc are
 * re-validated by cashflow_resolve_active_company()/
 * cashflow_resolve_active_profit_center() the moment the redirect target
 * is loaded, same as any other link in the header selectors.
 */

$target = $_GET['redirect'] ?? 'dashboard';
$allowed = ['dashboard', 'transactions', 'reports', 'profit_centers', 'accounts', 'permissions', 'audit', 'transport', 'service_orders', 'invoices', 'allocations', 'documents', 'billing', 'integrations'];
if (!in_array($target, $allowed, true)) {
    $target = 'dashboard';
}

$params = ['p' => $target];
if (isset($_GET['cid'])) {
    $params['cid'] = (int)$_GET['cid'];
}
if (isset($_GET['pc'])) {
    $params['pc'] = $_GET['pc'] === 'all' ? 'all' : (int)$_GET['pc'];
}

header('Location: index.php?' . http_build_query($params));
exit;
