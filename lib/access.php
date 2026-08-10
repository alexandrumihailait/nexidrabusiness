<?php
require_once __DIR__ . '/auth.php';

/**
 * Central authorization layer. Every module that touches company or
 * profit-center scoped data MUST go through these functions -- never
 * trust a company_id/profit_center_id coming from the URL, a form field,
 * or the session on its own. The chain that is always re-verified against
 * the database on each request is:
 *
 *   authenticated user -> access to company? -> access to profit center? -> action allowed?
 *
 * Any failed link returns/dies with 403.
 */

const CASHFLOW_ADMIN_ROLES = ['owner', 'admin'];

const CASHFLOW_ACCESS_RANK = [
    'none' => 0,
    'read' => 1,
    'read_write' => 2,
    'full' => 3,
];

function cashflow_forbidden(string $message = 'Acces interzis.'): void
{
    http_response_code(403);
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode(['error' => $message]);
        exit;
    }
    die(htmlspecialchars($message));
}

/** All companies (id, name, cui, logo, role_code) the user has active access to. */
function cashflow_user_companies(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare(
        "SELECT c.id, c.name, c.cui, c.logo, c.currency, r.code AS role_code, r.name AS role_name
         FROM cf_company_users cu
         JOIN cf_companies c ON c.id = cu.company_id
         JOIN cf_roles r ON r.id = cu.role_id
         WHERE cu.user_id = ? AND cu.status = 'active' AND c.status = 'active'
         ORDER BY c.name ASC"
    );
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

/**
 * Verifies the user has active access to $companyId and returns their
 * role row ('role_code' etc). Dies with 403 otherwise -- callers do not
 * need to (and must not) special-case a missing return value into "allow".
 */
function cashflow_require_company_access(PDO $pdo, int $userId, int $companyId): array
{
    $stmt = $pdo->prepare(
        "SELECT cu.*, r.code AS role_code, r.name AS role_name
         FROM cf_company_users cu
         JOIN cf_roles r ON r.id = cu.role_id
         JOIN cf_companies c ON c.id = cu.company_id
         WHERE cu.user_id = ? AND cu.company_id = ? AND cu.status = 'active' AND c.status = 'active'
         LIMIT 1"
    );
    $stmt->execute([$userId, $companyId]);
    $row = $stmt->fetch();

    if ($row) {
        return $row;
    }

    // Platform admins can enter any active company for support purposes,
    // without needing a cf_company_users row -- synthesized as 'owner' (an
    // actual role, so permission lookups by role_id still work) and
    // audit-logged every time so impersonation is never silent.
    if (cashflow_is_platform_admin($pdo, $userId)) {
        $companyStmt = $pdo->prepare("SELECT id FROM cf_companies WHERE id = ? AND status = 'active' LIMIT 1");
        $companyStmt->execute([$companyId]);
        if ($companyStmt->fetchColumn()) {
            $roleStmt = $pdo->prepare("SELECT id, code, name FROM cf_roles WHERE code = 'owner' LIMIT 1");
            $roleStmt->execute();
            $ownerRole = $roleStmt->fetch();
            if ($ownerRole) {
                cashflow_audit($pdo, $userId, $companyId, null, 'platform_admin_impersonation', 'company', $companyId);
                return ['company_id' => $companyId, 'user_id' => $userId, 'role_id' => $ownerRole['id'], 'role_code' => $ownerRole['code'], 'role_name' => $ownerRole['name'] . ' (admin platformă)', 'status' => 'active'];
            }
        }
    }

    cashflow_forbidden('Nu ai acces la această firmă.');
}

function cashflow_is_admin_role(string $roleCode): bool
{
    return in_array($roleCode, CASHFLOW_ADMIN_ROLES, true);
}

/**
 * All profit centers of $companyId the user may see, each annotated with
 * the effective access_level ('full' for company admins/owners, otherwise
 * whatever cf_profit_center_access grants). Centers with no grant (and no
 * admin role) are excluded entirely -- they must never reach the frontend.
 */
function cashflow_user_profit_centers(PDO $pdo, int $userId, int $companyId, ?array $companyRole = null): array
{
    $companyRole = $companyRole ?? cashflow_require_company_access($pdo, $userId, $companyId);
    $isAdmin = cashflow_is_admin_role($companyRole['role_code']);

    if ($isAdmin) {
        $stmt = $pdo->prepare(
            "SELECT *, 'full' AS access_level FROM cf_profit_centers
             WHERE company_id = ? AND status = 'active' ORDER BY type = 'corporate' ASC, name ASC"
        );
        $stmt->execute([$companyId]);
        return $stmt->fetchAll();
    }

    $stmt = $pdo->prepare(
        "SELECT pc.*, pca.access_level
         FROM cf_profit_centers pc
         JOIN cf_profit_center_access pca ON pca.profit_center_id = pc.id
         WHERE pc.company_id = ? AND pc.status = 'active' AND pca.user_id = ? AND pca.access_level <> 'none'
         ORDER BY pc.type = 'corporate' ASC, pc.name ASC"
    );
    $stmt->execute([$companyId, $userId]);
    return $stmt->fetchAll();
}

/**
 * Verifies the user has at least $minLevel access to $profitCenterId
 * within $companyId. Dies with 403 otherwise. Returns the effective
 * access level string on success.
 */
function cashflow_require_profit_center_access(
    PDO $pdo,
    int $userId,
    int $companyId,
    int $profitCenterId,
    string $minLevel = 'read',
    ?array $companyRole = null
): string {
    $companyRole = $companyRole ?? cashflow_require_company_access($pdo, $userId, $companyId);

    $pcStmt = $pdo->prepare("SELECT id FROM cf_profit_centers WHERE id = ? AND company_id = ? LIMIT 1");
    $pcStmt->execute([$profitCenterId, $companyId]);
    if (!$pcStmt->fetchColumn()) {
        cashflow_forbidden('Centrul de profit nu aparține acestei firme.');
    }

    if (cashflow_is_admin_role($companyRole['role_code'])) {
        return 'full';
    }

    $stmt = $pdo->prepare(
        "SELECT access_level FROM cf_profit_center_access WHERE user_id = ? AND profit_center_id = ? LIMIT 1"
    );
    $stmt->execute([$userId, $profitCenterId]);
    $level = $stmt->fetchColumn();

    if (!$level || CASHFLOW_ACCESS_RANK[$level] < CASHFLOW_ACCESS_RANK[$minLevel]) {
        cashflow_forbidden('Nu ai acces suficient la acest centru de profit.');
    }

    return $level;
}

/** IDs only, convenience wrapper for building "WHERE profit_center_id IN (...)" filters. */
function cashflow_user_profit_center_ids(PDO $pdo, int $userId, int $companyId, ?array $companyRole = null): array
{
    return array_map('intval', array_column(cashflow_user_profit_centers($pdo, $userId, $companyId, $companyRole), 'id'));
}

/**
 * Resolves + validates the active company context for the request.
 * Priority: explicit ?cid= (re-verified against DB, never trusted blindly)
 * falling back to the persisted session context. Redirects to the company
 * selector if nothing valid is available.
 */
function cashflow_resolve_active_company(PDO $pdo, int $userId): array
{
    $requested = isset($_GET['cid']) ? (int)$_GET['cid'] : null;
    $sessionCompanyId = isset($_SESSION['cf_active_company_id']) ? (int)$_SESSION['cf_active_company_id'] : null;

    $candidateId = $requested ?: $sessionCompanyId;

    if (!$candidateId) {
        header('Location: index.php?p=select_company');
        exit;
    }

    $role = cashflow_require_company_access($pdo, $userId, $candidateId);

    $_SESSION['cf_active_company_id'] = $candidateId;
    if ($requested && $requested !== $sessionCompanyId) {
        // Company changed: the previously active profit center almost
        // certainly does not belong to the new company, so drop it.
        unset($_SESSION['cf_active_profit_center_id']);
    }

    $stmt = $pdo->prepare("SELECT * FROM cf_companies WHERE id = ? LIMIT 1");
    $stmt->execute([$candidateId]);
    $company = $stmt->fetch();
    $company['role_code'] = $role['role_code'];
    $company['role_name'] = $role['role_name'];

    return $company;
}

/**
 * Resolves the active profit center for the request within $companyId.
 * Returns null to mean "TOATE" (all centers, consolidated view). A
 * non-null, non-empty request is validated against the user's actual
 * access before being accepted/persisted.
 */
function cashflow_resolve_active_profit_center(PDO $pdo, int $userId, int $companyId, array $companyRole): ?array
{
    if (array_key_exists('pc', $_GET)) {
        $requested = $_GET['pc'];
        if ($requested === '' || $requested === 'all') {
            $_SESSION['cf_active_profit_center_id'] = null;
            return null;
        }
        $pcId = (int)$requested;
        cashflow_require_profit_center_access($pdo, $userId, $companyId, $pcId, 'read', $companyRole);
        $_SESSION['cf_active_profit_center_id'] = $pcId;
    }

    $activeId = $_SESSION['cf_active_profit_center_id'] ?? null;
    if (!$activeId) {
        return null;
    }

    // Re-verify on every request; access may have been revoked mid-session.
    cashflow_require_profit_center_access($pdo, $userId, $companyId, (int)$activeId, 'read', $companyRole);

    $stmt = $pdo->prepare("SELECT * FROM cf_profit_centers WHERE id = ? AND company_id = ? LIMIT 1");
    $stmt->execute([$activeId, $companyId]);
    $pc = $stmt->fetch();
    if (!$pc) {
        unset($_SESSION['cf_active_profit_center_id']);
        return null;
    }
    return $pc;
}

/**
 * Fine-grained RBAC check (section 25/44 formalized): does this user hold
 * $permissionCode in $companyId, via their company role's permission
 * grants? Company admins/owners are not special-cased here -- they get
 * every permission because the default matrix grants it to their role,
 * so an admin who edits the matrix can deliberately narrow their own role
 * too. Always re-checked against the DB, same as every other access() call.
 */
function cashflow_user_has_permission(PDO $pdo, int $userId, int $companyId, string $permissionCode, ?array $companyRole = null): bool
{
    $companyRole = $companyRole ?? cashflow_require_company_access($pdo, $userId, $companyId);

    $stmt = $pdo->prepare(
        "SELECT 1 FROM cf_role_permissions rp
         JOIN cf_permissions p ON p.id = rp.permission_id
         WHERE rp.role_id = ? AND p.code = ? LIMIT 1"
    );
    $stmt->execute([$companyRole['role_id'], $permissionCode]);
    return (bool)$stmt->fetchColumn();
}

function cashflow_require_permission(PDO $pdo, int $userId, int $companyId, string $permissionCode, ?array $companyRole = null): void
{
    if (!cashflow_user_has_permission($pdo, $userId, $companyId, $permissionCode, $companyRole)) {
        cashflow_forbidden('Rolul tău nu are permisiunea "' . $permissionCode . '".');
    }
}

/** True if the authenticated user is a platform-wide (super-admin) user, unscoped to any company. */
function cashflow_is_platform_admin(PDO $pdo, int $userId): bool
{
    $stmt = $pdo->prepare("SELECT is_platform_admin FROM cf_users WHERE id = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$userId]);
    return (bool)$stmt->fetchColumn();
}

function cashflow_require_platform_admin(PDO $pdo, int $userId): void
{
    if (!cashflow_is_platform_admin($pdo, $userId)) {
        cashflow_forbidden('Acces rezervat administratorilor platformei.');
    }
}

function cashflow_audit(
    PDO $pdo,
    ?int $userId,
    ?int $companyId,
    ?int $profitCenterId,
    string $action,
    string $entityType,
    ?int $entityId = null,
    array $details = []
): void {
    $stmt = $pdo->prepare(
        "INSERT INTO cf_audit_log (user_id, company_id, profit_center_id, action, entity_type, entity_id, details, ip_address)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $userId,
        $companyId,
        $profitCenterId,
        $action,
        $entityType,
        $entityId,
        $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}
