<?php
/**
 * Demo data seed for local/staging environments -- creates the exact
 * scenario walked through in the product spec (section 38-40): one user
 * with access to two companies, each with a few profit centers, plus a
 * handful of sample transactions. Safe to run repeatedly (idempotent on
 * email/company name/code uniqueness). Run via CLI: php seed.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    die('seed.php only runs from the command line (php seed.php) -- it prints temporary passwords and must never be web-accessible.');
}

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/subscriptions.php';

$pdo = cashflow_db();
cashflow_migrate($pdo);

function seed_user(PDO $pdo, string $name, string $email, string $password): int
{
    $stmt = $pdo->prepare("SELECT id FROM cf_users WHERE email = ?");
    $stmt->execute([$email]);
    if ($id = $stmt->fetchColumn()) {
        return (int)$id;
    }
    $ins = $pdo->prepare("INSERT INTO cf_users (name, email, password_hash) VALUES (?, ?, ?)");
    $ins->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
    return (int)$pdo->lastInsertId();
}

function seed_company(PDO $pdo, string $name, string $cui): int
{
    $stmt = $pdo->prepare("SELECT id FROM cf_companies WHERE name = ?");
    $stmt->execute([$name]);
    if ($id = $stmt->fetchColumn()) {
        return (int)$id;
    }
    $ins = $pdo->prepare("INSERT INTO cf_companies (name, cui, currency, timezone, status) VALUES (?, ?, 'RON', 'Europe/Bucharest', 'active')");
    $ins->execute([$name, $cui]);
    return (int)$pdo->lastInsertId();
}

function seed_role_id(PDO $pdo, string $code): int
{
    $stmt = $pdo->prepare("SELECT id FROM cf_roles WHERE code = ?");
    $stmt->execute([$code]);
    return (int)$stmt->fetchColumn();
}

function seed_company_user(PDO $pdo, int $companyId, int $userId, string $roleCode): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO cf_company_users (company_id, user_id, role_id, status) VALUES (?, ?, ?, 'active')
         ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), status = 'active'"
    );
    $stmt->execute([$companyId, $userId, seed_role_id($pdo, $roleCode)]);
}

function seed_profit_center(PDO $pdo, int $companyId, string $name, string $code, string $icon, string $color, string $type = 'custom'): int
{
    $stmt = $pdo->prepare("SELECT id FROM cf_profit_centers WHERE company_id = ? AND code = ?");
    $stmt->execute([$companyId, $code]);
    if ($id = $stmt->fetchColumn()) {
        return (int)$id;
    }
    $ins = $pdo->prepare(
        "INSERT INTO cf_profit_centers (company_id, name, code, icon, color, type, status) VALUES (?, ?, ?, ?, ?, ?, 'active')"
    );
    $ins->execute([$companyId, $name, $code, $icon, $color, $type]);
    return (int)$pdo->lastInsertId();
}

function seed_vehicle(PDO $pdo, int $companyId, string $plate, string $type, string $make, string $model): int
{
    $stmt = $pdo->prepare("SELECT id FROM cf_vehicles WHERE company_id = ? AND plate_number = ?");
    $stmt->execute([$companyId, $plate]);
    if ($id = $stmt->fetchColumn()) {
        return (int)$id;
    }
    $ins = $pdo->prepare(
        "INSERT INTO cf_vehicles (company_id, type, plate_number, make, model, mileage_km, status) VALUES (?, ?, ?, ?, ?, 120000, 'active')"
    );
    $ins->execute([$companyId, $type, $plate, $make, $model]);
    return (int)$pdo->lastInsertId();
}

function seed_driver(PDO $pdo, int $companyId, string $name): int
{
    $stmt = $pdo->prepare("SELECT id FROM cf_drivers WHERE company_id = ? AND name = ?");
    $stmt->execute([$companyId, $name]);
    if ($id = $stmt->fetchColumn()) {
        return (int)$id;
    }
    $ins = $pdo->prepare("INSERT INTO cf_drivers (company_id, name, base_salary, status) VALUES (?, ?, 4500, 'active')");
    $ins->execute([$companyId, $name]);
    return (int)$pdo->lastInsertId();
}

function seed_account(PDO $pdo, int $companyId, string $name, string $currency, float $opening): int
{
    $stmt = $pdo->prepare("SELECT id FROM cf_accounts WHERE company_id = ? AND name = ?");
    $stmt->execute([$companyId, $name]);
    if ($id = $stmt->fetchColumn()) {
        return (int)$id;
    }
    $ins = $pdo->prepare(
        "INSERT INTO cf_accounts (company_id, name, bank, currency, type, opening_balance, status) VALUES (?, ?, 'ING Bank', ?, 'bank', ?, 'active')"
    );
    $ins->execute([$companyId, $name, $currency, $opening]);
    return (int)$pdo->lastInsertId();
}

function seed_transaction(PDO $pdo, int $companyId, int $pcId, int $accountId, int $userId, string $type, float $amount, string $category, string $partner, string $date, string $description): void
{
    $catStmt = $pdo->prepare("SELECT id FROM cf_categories WHERE company_id = ? AND name = ? AND type = ?");
    $catStmt->execute([$companyId, $category, $type]);
    $catId = $catStmt->fetchColumn();
    if (!$catId) {
        $pdo->prepare("INSERT INTO cf_categories (company_id, name, type) VALUES (?, ?, ?)")->execute([$companyId, $category, $type]);
        $catId = $pdo->lastInsertId();
    }

    $pStmt = $pdo->prepare("SELECT id FROM cf_partners WHERE company_id = ? AND name = ?");
    $pStmt->execute([$companyId, $partner]);
    $partnerId = $pStmt->fetchColumn();
    if (!$partnerId) {
        $pdo->prepare("INSERT INTO cf_partners (company_id, name, type) VALUES (?, ?, 'both')")->execute([$companyId, $partner]);
        $partnerId = $pdo->lastInsertId();
    }

    $ins = $pdo->prepare(
        "INSERT INTO cf_transactions (company_id, profit_center_id, account_id, user_id, type, category_id, partner_id, amount, currency, exchange_rate, amount_ron, transaction_date, description, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'RON', 1, ?, ?, ?, 'confirmed', ?)"
    );
    $ins->execute([$companyId, $pcId, $accountId, $userId, $type, $catId, $partnerId, $amount, $amount, $date, $description, $userId]);
}

// --- Ion Popescu, with access to two companies (spec section 38) ---
// Also the platform admin for this demo (real deployments should create
// this flag manually on one real account -- see DEPLOY.md).
$ionId = seed_user($pdo, 'Ion Popescu', 'ion@exemplu.ro', 'parola123');
$pdo->prepare("UPDATE cf_users SET is_platform_admin = 1 WHERE id = ?")->execute([$ionId]);
$mariaId = seed_user($pdo, 'Maria Ionescu', 'maria@exemplu.ro', 'parola123');

$transportCo = seed_company($pdo, 'SC TRANSPORT SRL', 'RO11111111');
$serviceCo = seed_company($pdo, 'SC SERVICE SRL', 'RO12345678');

$businessPlanId = $pdo->query("SELECT id FROM cf_subscription_plans WHERE code = 'business'")->fetchColumn();
if ($businessPlanId) {
    cashflow_assign_subscription($pdo, $serviceCo, (int)$businessPlanId);
}

seed_company_user($pdo, $transportCo, $ionId, 'owner');
seed_company_user($pdo, $serviceCo, $ionId, 'owner');
seed_company_user($pdo, $serviceCo, $mariaId, 'operator');

$corpTransportCo = cashflow_ensure_corporate_center($pdo, $transportCo);
$corpServiceCo = cashflow_ensure_corporate_center($pdo, $serviceCo);

$transportPc = seed_profit_center($pdo, $transportCo, 'Transport', 'transport', 'bi-truck', '#2563eb', 'transport');
$servicePc = seed_profit_center($pdo, $transportCo, 'Service', 'service', 'bi-wrench', '#0891b2', 'service');

$detailingPc = seed_profit_center($pdo, $serviceCo, 'Detailing', 'detailing', 'bi-stars', '#7c3aed', 'service');
$colantariPc = seed_profit_center($pdo, $serviceCo, 'Colantări', 'colantari', 'bi-palette', '#db2777', 'service');
$transportPc2 = seed_profit_center($pdo, $serviceCo, 'Transport', 'transport', 'bi-truck', '#2563eb', 'transport');

// Maria (spec section 25/44): only Detailing + Colantări, no Transport/Corporate.
$grant = $pdo->prepare(
    "INSERT INTO cf_profit_center_access (user_id, company_id, profit_center_id, access_level) VALUES (?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE access_level = VALUES(access_level)"
);
$grant->execute([$mariaId, $serviceCo, $detailingPc, 'read_write']);
$grant->execute([$mariaId, $serviceCo, $colantariPc, 'read_write']);

$ingRon = seed_account($pdo, $serviceCo, 'ING RON', 'RON', 50000);
$ingEur = seed_account($pdo, $serviceCo, 'ING EUR', 'EUR', 5000);
$transportAcc = seed_account($pdo, $transportCo, 'BT RON', 'RON', 20000);

seed_transaction($pdo, $serviceCo, $detailingPc, $ingRon, $ionId, 'expense', 2500, 'Materiale', 'ABC Chemicals', date('Y-m-d'), 'Materiale detailing');
seed_transaction($pdo, $serviceCo, $transportPc2, $ingRon, $ionId, 'income', 25000, 'Curse', 'XYZ Logistics', date('Y-m-d', strtotime('-3 days')), 'Factura 1458');
seed_transaction($pdo, $serviceCo, $corpServiceCo, $ingRon, $ionId, 'expense', 4000, 'Contabilitate', 'Cabinet Contabil X', date('Y-m-d', strtotime('-5 days')), 'Onorariu lunar');
seed_transaction($pdo, $serviceCo, $colantariPc, $ingRon, $mariaId, 'income', 3200, 'Lucrări', 'Client Local SRL', date('Y-m-d', strtotime('-1 day')), 'Colantare integrală');
seed_transaction($pdo, $transportCo, $transportPc, $transportAcc, $ionId, 'income', 18000, 'Curse', 'Cargo Partners', date('Y-m-d', strtotime('-2 days')), 'Cursă Cluj-Hamburg');
seed_transaction($pdo, $transportCo, $servicePc, $transportAcc, $ionId, 'expense', 1200, 'Piese', 'AutoParts SRL', date('Y-m-d', strtotime('-4 days')), 'Reparație frâne');

// --- Transport: fleet + a settled trip (spec section 19) ---
$truck = seed_vehicle($pdo, $transportCo, 'CJ-01-ABC', 'truck', 'MAN', 'TGX');
$trailer = seed_vehicle($pdo, $transportCo, 'CJ-02-XYZ', 'trailer', 'Schmitz', 'S.KO');
$driver = seed_driver($pdo, $transportCo, 'Vasile Ionescu');

$tripStmt = $pdo->prepare("SELECT id FROM cf_trips WHERE company_id = ? AND trip_number = ?");
$tripStmt->execute([$transportCo, 'CJ-1001']);
if (!$tripStmt->fetchColumn()) {
    $partnerId = null;
    $pStmt = $pdo->prepare("SELECT id FROM cf_partners WHERE company_id = ? AND name = ?");
    $pStmt->execute([$transportCo, 'Cargo Partners']);
    $partnerId = $pStmt->fetchColumn() ?: null;
    if (!$partnerId) {
        $pdo->prepare("INSERT INTO cf_partners (company_id, name, type) VALUES (?, 'Cargo Partners', 'both')")->execute([$transportCo]);
        $partnerId = $pdo->lastInsertId();
    }
    $pdo->prepare(
        "INSERT INTO cf_trips (company_id, profit_center_id, trip_number, partner_id, vehicle_id, trailer_id, driver_id, origin, destination, km, tariff, currency, exchange_rate, fuel_cost, road_taxes, other_costs, trip_date, status, created_by)
         VALUES (?, ?, 'CJ-1001', ?, ?, ?, ?, 'Cluj-Napoca', 'Hamburg', 1450, 18000, 'RON', 1, 4200, 850, 300, ?, 'completed', ?)"
    )->execute([$transportCo, $transportPc, $partnerId, $truck, $trailer, $driver, date('Y-m-d', strtotime('-2 days')), $ionId]);
}

// --- Detailing: a settled work order matching the spec's own example (section 22) ---
$woStmt = $pdo->prepare("SELECT id FROM cf_work_orders WHERE company_id = ? AND order_number = ?");
$woStmt->execute([$serviceCo, '#1045']);
if (!$woStmt->fetchColumn()) {
    $pStmt = $pdo->prepare("SELECT id FROM cf_partners WHERE company_id = ? AND name = ?");
    $pStmt->execute([$serviceCo, 'Client Local SRL']);
    $partnerId = $pStmt->fetchColumn();
    if (!$partnerId) {
        $pdo->prepare("INSERT INTO cf_partners (company_id, name, type) VALUES (?, 'Client Local SRL', 'both')")->execute([$serviceCo]);
        $partnerId = $pdo->lastInsertId();
    }

    $pdo->prepare(
        "INSERT INTO cf_work_orders (company_id, profit_center_id, order_number, partner_id, vehicle_plate, vehicle_make, vehicle_model, service_category, date_in, date_done, materials_cost, labor_cost, subcontractor_cost, other_cost, client_price, currency, status, created_by)
         VALUES (?, ?, '#1045', ?, 'CJ-99-DET', 'BMW', 'X5', 'Detailing complet', ?, ?, 1200, 900, 0, 300, 4500, 'RON', 'in_progress', ?)"
    )->execute([$serviceCo, $detailingPc, $partnerId, date('Y-m-d', strtotime('-1 day')), date('Y-m-d'), $mariaId]);
}

// --- Invoices: an outstanding receivable and a payable (creanțe/datorii) ---
$invStmt = $pdo->prepare("SELECT id FROM cf_invoices WHERE company_id = ? AND invoice_number = ?");
$invStmt->execute([$serviceCo, 'FE-2026-0087']);
if (!$invStmt->fetchColumn()) {
    $pStmt = $pdo->prepare("SELECT id FROM cf_partners WHERE company_id = ? AND name = ?");
    $pStmt->execute([$serviceCo, 'XYZ Logistics']);
    $partnerId = $pStmt->fetchColumn();
    if (!$partnerId) {
        $pdo->prepare("INSERT INTO cf_partners (company_id, name, type) VALUES (?, 'XYZ Logistics', 'both')")->execute([$serviceCo]);
        $partnerId = $pdo->lastInsertId();
    }
    $pdo->prepare(
        "INSERT INTO cf_invoices (company_id, profit_center_id, partner_id, direction, invoice_number, issue_date, due_date, amount, currency, exchange_rate, amount_ron, status, created_by)
         VALUES (?, ?, ?, 'receivable', 'FE-2026-0087', ?, ?, 12000, 'RON', 1, 12000, 'unpaid', ?)"
    )->execute([$serviceCo, $transportPc2, $partnerId, date('Y-m-d', strtotime('-10 days')), date('Y-m-d', strtotime('+20 days')), $ionId]);
}

// --- A shared-cost allocation rule (section 32/33) ---
$ruleStmt = $pdo->prepare("SELECT id FROM cf_allocation_rules WHERE company_id = ? AND name = ?");
$ruleStmt->execute([$serviceCo, 'Abonament software contabilitate']);
if (!$ruleStmt->fetchColumn()) {
    $pdo->prepare("INSERT INTO cf_allocation_rules (company_id, name, method, status) VALUES (?, 'Abonament software contabilitate', 'percent', 'active')")->execute([$serviceCo]);
    $ruleId = $pdo->lastInsertId();
    $pdo->prepare("INSERT INTO cf_allocation_rule_lines (rule_id, profit_center_id, value) VALUES (?, ?, 50)")->execute([$ruleId, $transportPc2]);
    $pdo->prepare("INSERT INTO cf_allocation_rule_lines (rule_id, profit_center_id, value) VALUES (?, ?, 30)")->execute([$ruleId, $detailingPc]);
    $pdo->prepare("INSERT INTO cf_allocation_rule_lines (rule_id, profit_center_id, value) VALUES (?, ?, 20)")->execute([$ruleId, $colantariPc]);
}

echo "Seed complete.\n";
echo "Login: ion@exemplu.ro / parola123 (owner of both companies, and platform admin -- see 'Admin platformă' in the header dropdown or go to admin.php)\n";
echo "Login: maria@exemplu.ro / parola123 (operator, Detailing + Colantari only, SC SERVICE SRL)\n";
echo "SC SERVICE SRL is on the 'business' plan; SC TRANSPORT SRL is on the default 'starter' plan.\n";
