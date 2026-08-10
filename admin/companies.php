<?php
/** @var PDO $pdo */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cashflow_csrf_check();
    $action = $_POST['do'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $cui = trim($_POST['cui'] ?? '') ?: null;
        $ownerEmail = trim(strtolower($_POST['owner_email'] ?? ''));
        $ownerName = trim($_POST['owner_name'] ?? '');
        $planId = (int)($_POST['plan_id'] ?? 0);

        if ($name === '' || !filter_var($ownerEmail, FILTER_VALIDATE_EMAIL)) {
            cashflow_flash_set('danger', 'Numele firmei și emailul administratorului sunt obligatorii.');
        } else {
            $pdo->beginTransaction();
            try {
                $ins = $pdo->prepare("INSERT INTO cf_companies (name, cui, currency, timezone, status) VALUES (?, ?, 'RON', 'Europe/Bucharest', 'active')");
                $ins->execute([$name, $cui]);
                $companyId = (int)$pdo->lastInsertId();

                cashflow_ensure_corporate_center($pdo, $companyId);

                $userStmt = $pdo->prepare("SELECT id FROM cf_users WHERE email = ?");
                $userStmt->execute([$ownerEmail]);
                $ownerUserId = $userStmt->fetchColumn();
                $tempPassword = null;
                if (!$ownerUserId) {
                    $tempPassword = bin2hex(random_bytes(8));
                    $uIns = $pdo->prepare("INSERT INTO cf_users (name, email, password_hash) VALUES (?, ?, ?)");
                    $uIns->execute([$ownerName ?: $ownerEmail, $ownerEmail, password_hash($tempPassword, PASSWORD_DEFAULT)]);
                    $ownerUserId = (int)$pdo->lastInsertId();
                }

                $roleStmt = $pdo->prepare("SELECT id FROM cf_roles WHERE code = 'owner'");
                $roleStmt->execute();
                $ownerRoleId = $roleStmt->fetchColumn();
                $pdo->prepare("INSERT INTO cf_company_users (company_id, user_id, role_id, status) VALUES (?, ?, ?, 'active')")
                    ->execute([$companyId, $ownerUserId, $ownerRoleId]);

                if ($planId > 0) {
                    cashflow_assign_subscription($pdo, $companyId, $planId);
                } else {
                    cashflow_get_company_subscription($pdo, $companyId);
                }

                $pdo->commit();
                cashflow_audit($pdo, $userId, $companyId, null, 'create', 'company', $companyId, ['owner_email' => $ownerEmail]);

                $msg = 'Firma "' . $name . '" a fost creată.';
                if ($tempPassword) {
                    $msg .= " Cont nou creat pentru $ownerEmail — parolă temporară: $tempPassword (comunic-o în siguranță).";
                }
                cashflow_flash_set('success', $msg);
            } catch (Throwable $e) {
                $pdo->rollBack();
                cashflow_flash_set('danger', 'Nu am putut crea firma: ' . $e->getMessage());
            }
        }
    }

    if ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT status FROM cf_companies WHERE id = ?");
        $stmt->execute([$id]);
        if ($cur = $stmt->fetchColumn()) {
            $newStatus = $cur === 'active' ? 'inactive' : 'active';
            $pdo->prepare("UPDATE cf_companies SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
            cashflow_audit($pdo, $userId, $id, null, 'status_change', 'company', $id, ['status' => $newStatus]);
            cashflow_flash_set('success', 'Statusul firmei a fost actualizat.');
        }
    }

    if ($action === 'change_plan') {
        $companyId = (int)($_POST['company_id'] ?? 0);
        $planId = (int)($_POST['plan_id'] ?? 0);
        if ($companyId > 0 && $planId > 0) {
            cashflow_assign_subscription($pdo, $companyId, $planId);
            cashflow_audit($pdo, $userId, $companyId, null, 'change_plan', 'company', $companyId, ['plan_id' => $planId]);
            cashflow_flash_set('success', 'Abonamentul firmei a fost actualizat.');
        }
    }

    header('Location: admin.php?p=companies');
    exit;
}

$companies = $pdo->query(
    "SELECT c.*, p.name AS plan_name, p.id AS plan_id,
            (SELECT COUNT(*) FROM cf_company_users cu WHERE cu.company_id = c.id AND cu.status = 'active') AS user_count,
            (SELECT COUNT(*) FROM cf_profit_centers pc WHERE pc.company_id = c.id AND pc.status = 'active') AS pc_count
     FROM cf_companies c
     LEFT JOIN cf_company_subscriptions cs ON cs.company_id = c.id
     LEFT JOIN cf_subscription_plans p ON p.id = cs.plan_id
     ORDER BY c.created_at DESC"
)->fetchAll();

$plans = $pdo->query("SELECT * FROM cf_subscription_plans WHERE status = 'active' ORDER BY price_month_ron ASC")->fetchAll();
?>

<h4 class="fw-bold mb-3"><i class="bi bi-building"></i> Firme</h4>

<div class="cf-card p-3 mb-4">
  <h6 class="fw-bold mb-3">Firmă nouă</h6>
  <form method="post" action="admin.php?p=companies" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
    <input type="hidden" name="do" value="create">
    <div class="col-md-3"><label class="form-label small fw-bold">Nume firmă</label><input type="text" name="name" class="form-control" required></div>
    <div class="col-md-2"><label class="form-label small fw-bold">CUI</label><input type="text" name="cui" class="form-control"></div>
    <div class="col-md-3"><label class="form-label small fw-bold">Email administrator</label><input type="email" name="owner_email" class="form-control" required></div>
    <div class="col-md-2"><label class="form-label small fw-bold">Nume administrator (dacă e cont nou)</label><input type="text" name="owner_name" class="form-control"></div>
    <div class="col-md-2">
      <label class="form-label small fw-bold">Abonament</label>
      <select name="plan_id" class="form-select">
        <?php foreach ($plans as $p): ?><option value="<?= (int)$p['id'] ?>" <?= $p['code'] === 'starter' ? 'selected' : '' ?>><?= cashflow_e($p['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-12"><button type="submit" class="btn btn-primary fw-bold">Creează firma</button></div>
  </form>
</div>

<div class="cf-card p-0 overflow-hidden">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 small">
      <thead class="table-light text-uppercase text-muted">
        <tr><th class="ps-3">Firmă</th><th>Useri</th><th>Centre</th><th>Abonament</th><th>Status</th><th class="pe-3 text-end">Acțiuni</th></tr>
      </thead>
      <tbody>
        <?php foreach ($companies as $c): ?>
          <tr>
            <td class="ps-3 fw-bold"><?= cashflow_e($c['name']) ?><br><small class="text-muted fw-normal">CUI <?= cashflow_e($c['cui'] ?: '-') ?></small></td>
            <td><?= (int)$c['user_count'] ?></td>
            <td><?= (int)$c['pc_count'] ?></td>
            <td>
              <form method="post" action="admin.php?p=companies" class="d-flex gap-1">
                <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
                <input type="hidden" name="do" value="change_plan">
                <input type="hidden" name="company_id" value="<?= (int)$c['id'] ?>">
                <select name="plan_id" class="form-select form-select-sm" onchange="this.form.submit()">
                  <?php foreach ($plans as $p): ?><option value="<?= (int)$p['id'] ?>" <?= (int)$p['id'] === (int)$c['plan_id'] ? 'selected' : '' ?>><?= cashflow_e($p['name']) ?></option><?php endforeach; ?>
                </select>
              </form>
            </td>
            <td><?= $c['status'] === 'active' ? '<span class="badge bg-success-subtle text-success border border-success">Activă</span>' : '<span class="badge bg-secondary-subtle text-secondary border">Inactivă</span>' ?></td>
            <td class="pe-3 text-end">
              <a href="index.php?p=dashboard&cid=<?= (int)$c['id'] ?>" class="btn btn-sm btn-outline-secondary" title="Intră în firmă"><i class="bi bi-box-arrow-in-right"></i></a>
              <form method="post" action="admin.php?p=companies" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
                <input type="hidden" name="do" value="toggle_status">
                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-<?= $c['status'] === 'active' ? 'danger' : 'success' ?>"><i class="bi bi-<?= $c['status'] === 'active' ? 'pause' : 'play' ?>"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
