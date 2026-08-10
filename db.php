<?php
require_once __DIR__ . '/config.php';

/**
 * Idempotently provisions the cashflow schema. Safe to call on every
 * request (CREATE TABLE IF NOT EXISTS / INSERT IGNORE only); mirrors the
 * self-provisioning convention already used by hr/modules/corectii.php.
 */
function cashflow_migrate(PDO $pdo): void
{
    static $done = false;
    if ($done) {
        return;
    }

    $sql = file_get_contents(__DIR__ . '/schema.sql');
    // Strip full-line `--` comments before splitting on ';' -- some of
    // those comments contain a literal semicolon as plain punctuation,
    // which would otherwise desynchronize the naive split below and feed
    // MySQL a garbled fragment starting mid-comment.
    $sql = preg_replace('/^--.*$/m', '', $sql);
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
        if ($stmt === '') {
            continue;
        }
        $pdo->exec($stmt);
    }

    // Column added after the initial release: guarded ALTER (SHOW COLUMNS
    // check) rather than `ADD COLUMN IF NOT EXISTS`, whose support varies
    // across MySQL versions -- same pattern as hr/modules/corectii.php.
    $check = $pdo->query("SHOW COLUMNS FROM cf_users LIKE 'is_platform_admin'");
    if ($check && $check->rowCount() === 0) {
        $pdo->exec("ALTER TABLE cf_users ADD COLUMN is_platform_admin TINYINT(1) NOT NULL DEFAULT 0");
    }

    cashflow_seed_default_permissions($pdo);

    $done = true;
}

/**
 * Default role -> permission matrix. Runs on every migrate() call but is
 * a no-op once seeded (INSERT IGNORE on the unique (role_id, permission_id)
 * pair); platform admins can still edit the matrix afterwards from the
 * super-admin panel without this overwriting their changes.
 */
function cashflow_seed_default_permissions(PDO $pdo): void
{
    static $seeded = false;
    if ($seeded) {
        return;
    }
    $seeded = true;

    $matrix = [
        'owner' => ['transactions.write', 'invoices.write', 'operations.write', 'allocations.manage', 'reports.view', 'documents.upload', 'integrations.manage', 'company.manage', 'billing.view'],
        'admin' => ['transactions.write', 'invoices.write', 'operations.write', 'allocations.manage', 'reports.view', 'documents.upload', 'integrations.manage', 'company.manage', 'billing.view'],
        'manager' => ['transactions.write', 'invoices.write', 'operations.write', 'allocations.manage', 'reports.view', 'documents.upload', 'billing.view'],
        'operator' => ['transactions.write', 'invoices.write', 'operations.write', 'reports.view', 'documents.upload'],
        'read_only' => ['reports.view'],
    ];

    $roleStmt = $pdo->prepare("SELECT id FROM cf_roles WHERE code = ?");
    $permStmt = $pdo->prepare("SELECT id FROM cf_permissions WHERE code = ?");
    $grant = $pdo->prepare("INSERT IGNORE INTO cf_role_permissions (role_id, permission_id) VALUES (?, ?)");

    foreach ($matrix as $roleCode => $permissionCodes) {
        $roleStmt->execute([$roleCode]);
        $roleId = $roleStmt->fetchColumn();
        if (!$roleId) {
            continue;
        }
        foreach ($permissionCodes as $permCode) {
            $permStmt->execute([$permCode]);
            $permId = $permStmt->fetchColumn();
            if ($permId) {
                $grant->execute([$roleId, $permId]);
            }
        }
    }
}

/**
 * Every company must have exactly one 'corporate' profit center so that
 * general/shared costs always have a non-null profit_center_id to land in
 * (see cf_transactions.profit_center_id NOT NULL).
 */
function cashflow_ensure_corporate_center(PDO $pdo, int $companyId): int
{
    $stmt = $pdo->prepare("SELECT id FROM cf_profit_centers WHERE company_id = ? AND type = 'corporate' LIMIT 1");
    $stmt->execute([$companyId]);
    $existing = $stmt->fetchColumn();
    if ($existing) {
        return (int)$existing;
    }

    $ins = $pdo->prepare("INSERT INTO cf_profit_centers (company_id, name, code, description, color, icon, type, status)
        VALUES (?, 'General / Corporate', 'corporate', 'Cheltuieli generale ale firmei, nealocate direct unui centru de profit', '#64748b', 'bi-building', 'corporate', 'active')");
    $ins->execute([$companyId]);

    return (int)$pdo->lastInsertId();
}
