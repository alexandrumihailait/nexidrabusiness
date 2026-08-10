<?php
/**
 * @var PDO $pdo
 * @var array $company
 * @var bool $isCompanyAdmin
 */

if (!$isCompanyAdmin) {
    cashflow_forbidden('Doar administratorii firmei pot gestiona permisiunile.');
}

$companyId = (int)$company['id'];

$roles = $pdo->query("SELECT * FROM cf_roles ORDER BY id ASC")->fetchAll();
$rolesByCode = [];
foreach ($roles as $r) { $rolesByCode[$r['code']] = $r; }

$centersStmt = $pdo->prepare("SELECT * FROM cf_profit_centers WHERE company_id = ? AND status = 'active' ORDER BY type = 'corporate' ASC, name ASC");
$centersStmt->execute([$companyId]);
$allCenters = $centersStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cashflow_csrf_check();
    $action = $_POST['do'] ?? '';

    if ($action === 'invite') {
        $email = trim(strtolower($_POST['email'] ?? ''));
        $name = trim($_POST['name'] ?? '');
        $roleCode = $_POST['role_code'] ?? 'operator';

        $subscription = cashflow_get_company_subscription($pdo, $companyId);
        $userLimit = (int)$subscription['max_users'];
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM cf_company_users WHERE company_id = ? AND status = 'active'");
        $countStmt->execute([$companyId]);
        $currentUserCount = (int)$countStmt->fetchColumn();

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || empty($rolesByCode[$roleCode])) {
            cashflow_flash_set('danger', 'Email invalid sau rol necunoscut.');
        } elseif ($userLimit > 0 && $currentUserCount >= $userLimit) {
            cashflow_flash_set('danger', "Planul curent permite maximum $userLimit utilizatori activi. Contactează administratorul platformei pentru upgrade.");
        } else {
            $userStmt = $pdo->prepare("SELECT id FROM cf_users WHERE email = ? LIMIT 1");
            $userStmt->execute([$email]);
            $targetUserId = $userStmt->fetchColumn();

            if (!$targetUserId) {
                $tempPassword = bin2hex(random_bytes(8));
                $ins = $pdo->prepare("INSERT INTO cf_users (name, email, password_hash) VALUES (?, ?, ?)");
                $ins->execute([$name ?: $email, $email, password_hash($tempPassword, PASSWORD_DEFAULT)]);
                $targetUserId = (int)$pdo->lastInsertId();
                cashflow_flash_set('success', "Cont nou creat pentru $email. Parolă temporară: $tempPassword (comunic-o userului în siguranță).");
            }

            $roleId = $rolesByCode[$roleCode]['id'];
            $upsert = $pdo->prepare(
                "INSERT INTO cf_company_users (company_id, user_id, role_id, status) VALUES (?, ?, ?, 'active')
                 ON DUPLICATE KEY UPDATE role_id = VALUES(role_id), status = 'active'"
            );
            $upsert->execute([$companyId, $targetUserId, $roleId]);
            cashflow_audit($pdo, $userId, $companyId, null, 'grant_company_access', 'user', (int)$targetUserId, ['role' => $roleCode]);
            cashflow_flash_set('success', "Acces acordat pentru $email ca {$rolesByCode[$roleCode]['name']}.");
        }
    }

    if ($action === 'change_role') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $roleCode = $_POST['role_code'] ?? '';
        if (!empty($rolesByCode[$roleCode]) && $targetUserId !== $userId) {
            $upd = $pdo->prepare("UPDATE cf_company_users SET role_id = ? WHERE company_id = ? AND user_id = ?");
            $upd->execute([$rolesByCode[$roleCode]['id'], $companyId, $targetUserId]);
            cashflow_audit($pdo, $userId, $companyId, null, 'change_role', 'user', $targetUserId, ['role' => $roleCode]);
            cashflow_flash_set('success', 'Rolul a fost actualizat.');
        }
    }

    if ($action === 'toggle_status') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        if ($targetUserId !== $userId) {
            $stmt = $pdo->prepare("SELECT status FROM cf_company_users WHERE company_id = ? AND user_id = ?");
            $stmt->execute([$companyId, $targetUserId]);
            $cur = $stmt->fetchColumn();
            if ($cur) {
                $newStatus = $cur === 'active' ? 'inactive' : 'active';
                $upd = $pdo->prepare("UPDATE cf_company_users SET status = ? WHERE company_id = ? AND user_id = ?");
                $upd->execute([$newStatus, $companyId, $targetUserId]);
                cashflow_audit($pdo, $userId, $companyId, null, 'status_change', 'user', $targetUserId, ['status' => $newStatus]);
            }
        }
    }

    if ($action === 'set_center_access') {
        $targetUserId = (int)($_POST['user_id'] ?? 0);
        $levels = $_POST['access_level'] ?? [];
        foreach ($allCenters as $pc) {
            $level = $levels[$pc['id']] ?? 'none';
            if (!array_key_exists($level, CASHFLOW_ACCESS_RANK)) {
                continue;
            }
            $stmt = $pdo->prepare(
                "INSERT INTO cf_profit_center_access (user_id, company_id, profit_center_id, access_level) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE access_level = VALUES(access_level)"
            );
            $stmt->execute([$targetUserId, $companyId, $pc['id'], $level]);
        }
        cashflow_audit($pdo, $userId, $companyId, null, 'update_center_access', 'user', $targetUserId, ['levels' => $levels]);
        cashflow_flash_set('success', 'Accesul pe centre de profit a fost actualizat.');
    }

    header('Location: ' . cashflow_url('permissions'));
    exit;
}

$membersStmt = $pdo->prepare(
    "SELECT cu.*, u.name, u.email, r.code AS role_code, r.name AS role_name
     FROM cf_company_users cu
     JOIN cf_users u ON u.id = cu.user_id
     JOIN cf_roles r ON r.id = cu.role_id
     WHERE cu.company_id = ?
     ORDER BY u.name ASC"
);
$membersStmt->execute([$companyId]);
$members = $membersStmt->fetchAll();

$accessStmt = $pdo->prepare("SELECT user_id, profit_center_id, access_level FROM cf_profit_center_access WHERE company_id = ?");
$accessStmt->execute([$companyId]);
$accessByUser = [];
foreach ($accessStmt->fetchAll() as $row) {
    $accessByUser[$row['user_id']][$row['profit_center_id']] = $row['access_level'];
}
?>

<h4 class="fw-bold mb-3"><i class="bi bi-people"></i> Permisiuni</h4>

<div class="cf-card p-3 mb-4">
  <h6 class="fw-bold mb-3">Adaugă / invită utilizator în firmă</h6>
  <form method="post" action="<?= cashflow_url('permissions') ?>" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
    <input type="hidden" name="do" value="invite">
    <div class="col-md-4">
      <label class="form-label small fw-bold">Email</label>
      <input type="email" name="email" class="form-control" required>
    </div>
    <div class="col-md-4">
      <label class="form-label small fw-bold">Nume (dacă e utilizator nou)</label>
      <input type="text" name="name" class="form-control">
    </div>
    <div class="col-md-3">
      <label class="form-label small fw-bold">Rol în firmă</label>
      <select name="role_code" class="form-select">
        <?php foreach ($roles as $r): ?>
          <option value="<?= cashflow_e($r['code']) ?>"><?= cashflow_e($r['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-1 d-flex align-items-end">
      <button type="submit" class="btn btn-primary w-100"><i class="bi bi-plus-lg"></i></button>
    </div>
  </form>
</div>

<?php foreach ($members as $m): $isAdminRow = cashflow_is_admin_role($m['role_code']); ?>
  <div class="cf-card p-3 mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
      <div>
        <strong><?= cashflow_e($m['name']) ?></strong>
        <span class="text-muted small">&lt;<?= cashflow_e($m['email']) ?>&gt;</span>
        <?php if ($m['status'] !== 'active'): ?><span class="badge bg-secondary ms-1">inactiv</span><?php endif; ?>
      </div>
      <div class="d-flex gap-2">
        <form method="post" action="<?= cashflow_url('permissions') ?>" class="d-flex gap-1">
          <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
          <input type="hidden" name="do" value="change_role">
          <input type="hidden" name="user_id" value="<?= (int)$m['user_id'] ?>">
          <select name="role_code" class="form-select form-select-sm" onchange="this.form.submit()" <?= (int)$m['user_id'] === $userId ? 'disabled' : '' ?>>
            <?php foreach ($roles as $r): ?>
              <option value="<?= cashflow_e($r['code']) ?>" <?= $r['code'] === $m['role_code'] ? 'selected' : '' ?>><?= cashflow_e($r['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </form>
        <?php if ((int)$m['user_id'] !== $userId): ?>
          <form method="post" action="<?= cashflow_url('permissions') ?>">
            <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
            <input type="hidden" name="do" value="toggle_status">
            <input type="hidden" name="user_id" value="<?= (int)$m['user_id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-<?= $m['status'] === 'active' ? 'danger' : 'success' ?>">
              <?= $m['status'] === 'active' ? 'Dezactivează' : 'Activează' ?>
            </button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($isAdminRow): ?>
      <p class="text-muted small mb-0"><i class="bi bi-info-circle"></i> Administratorii au acces FULL automat la toate centrele de profit ale firmei.</p>
    <?php else: ?>
      <form method="post" action="<?= cashflow_url('permissions') ?>">
        <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
        <input type="hidden" name="do" value="set_center_access">
        <input type="hidden" name="user_id" value="<?= (int)$m['user_id'] ?>">
        <div class="row g-2">
          <?php foreach ($allCenters as $pc): $level = $accessByUser[$m['user_id']][$pc['id']] ?? 'none'; ?>
            <div class="col-6 col-md-3">
              <label class="small fw-bold d-flex align-items-center gap-1">
                <i class="bi <?= cashflow_e($pc['icon']) ?>" style="color: <?= cashflow_e($pc['color']) ?>"></i> <?= cashflow_e($pc['name']) ?>
              </label>
              <select name="access_level[<?= (int)$pc['id'] ?>]" class="form-select form-select-sm">
                <option value="none" <?= $level === 'none' ? 'selected' : '' ?>>Fără acces</option>
                <option value="read" <?= $level === 'read' ? 'selected' : '' ?>>Read</option>
                <option value="read_write" <?= $level === 'read_write' ? 'selected' : '' ?>>Read + Write</option>
                <option value="full" <?= $level === 'full' ? 'selected' : '' ?>>Full</option>
              </select>
            </div>
          <?php endforeach; ?>
        </div>
        <button type="submit" class="btn btn-sm btn-outline-primary mt-2 fw-bold">Salvează accesul pe centre</button>
      </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>
