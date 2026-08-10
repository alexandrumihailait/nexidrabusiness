<?php
/**
 * @var PDO $pdo
 * @var array $company
 * @var bool $isCompanyAdmin
 */

if (!$isCompanyAdmin) {
    cashflow_forbidden('Doar administratorii firmei pot gestiona centrele de profit.');
}

$companyId = (int)$company['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cashflow_csrf_check();
    $action = $_POST['do'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $code = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', trim($_POST['code'] ?? '')));
        $description = trim($_POST['description'] ?? '') ?: null;
        $color = trim($_POST['color'] ?? '#6366f1');
        $icon = trim($_POST['icon'] ?? 'bi-briefcase');
        $budgetAmountRaw = trim($_POST['budget_amount'] ?? '');
        $budgetAmount = $budgetAmountRaw !== '' ? (float)str_replace(',', '.', $budgetAmountRaw) : null;
        $budgetPeriod = in_array($_POST['budget_period'] ?? '', ['monthly', 'yearly'], true) ? $_POST['budget_period'] : null;

        if ($name === '' || $code === '') {
            cashflow_flash_set('danger', 'Numele și codul sunt obligatorii.');
        } else {
            if ($id > 0) {
                $stmt = $pdo->prepare("SELECT type FROM cf_profit_centers WHERE id = ? AND company_id = ? LIMIT 1");
                $stmt->execute([$id, $companyId]);
                $existing = $stmt->fetch();
                if ($existing && $existing['type'] === 'corporate') {
                    cashflow_flash_set('danger', 'Centrul Corporate nu poate fi redenumit ca tip.');
                }
                $upd = $pdo->prepare(
                    "UPDATE cf_profit_centers SET name=?, code=?, description=?, color=?, icon=?, budget_amount=?, budget_period=? WHERE id=? AND company_id=?"
                );
                $upd->execute([$name, $code, $description, $color, $icon, $budgetAmount, $budgetPeriod, $id, $companyId]);
                cashflow_audit($pdo, $userId, $companyId, $id, 'update', 'profit_center', $id);
                cashflow_flash_set('success', 'Centrul de profit a fost actualizat.');
            } else {
                $subscription = cashflow_get_company_subscription($pdo, $companyId);
                $centerLimit = (int)$subscription['max_profit_centers'];
                $countStmt = $pdo->prepare("SELECT COUNT(*) FROM cf_profit_centers WHERE company_id = ? AND status = 'active'");
                $countStmt->execute([$companyId]);
                $currentCount = (int)$countStmt->fetchColumn();

                if ($centerLimit > 0 && $currentCount >= $centerLimit) {
                    cashflow_flash_set('danger', "Planul curent permite maximum $centerLimit centre de profit active. Contactează administratorul platformei pentru upgrade.");
                } else {
                    $type = in_array($_POST['type'] ?? '', ['transport', 'service', 'custom'], true) ? $_POST['type'] : 'custom';
                    $ins = $pdo->prepare(
                        "INSERT INTO cf_profit_centers (company_id, name, code, description, color, icon, type, budget_amount, budget_period, status)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')"
                    );
                    $ins->execute([$companyId, $name, $code, $description, $color, $icon, $type, $budgetAmount, $budgetPeriod]);
                    $newId = (int)$pdo->lastInsertId();
                    cashflow_audit($pdo, $userId, $companyId, $newId, 'create', 'profit_center', $newId);
                    cashflow_flash_set('success', 'Centrul de profit a fost creat.');
                }
            }
        }
    }

    if ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT status, type FROM cf_profit_centers WHERE id = ? AND company_id = ? LIMIT 1");
        $stmt->execute([$id, $companyId]);
        $pc = $stmt->fetch();
        if ($pc && $pc['type'] !== 'corporate') {
            $newStatus = $pc['status'] === 'active' ? 'inactive' : 'active';
            $upd = $pdo->prepare("UPDATE cf_profit_centers SET status = ? WHERE id = ? AND company_id = ?");
            $upd->execute([$newStatus, $id, $companyId]);
            cashflow_audit($pdo, $userId, $companyId, $id, 'status_change', 'profit_center', $id, ['status' => $newStatus]);
            cashflow_flash_set('success', 'Statusul a fost actualizat.');
        } elseif ($pc) {
            cashflow_flash_set('danger', 'Centrul Corporate nu poate fi dezactivat.');
        }
    }

    header('Location: ' . cashflow_url('profit_centers'));
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM cf_profit_centers WHERE company_id = ? ORDER BY type = 'corporate' ASC, status = 'active' DESC, name ASC");
$stmt->execute([$companyId]);
$centers = $stmt->fetchAll();

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
$editCenter = null;
if ($editId) {
    foreach ($centers as $c) {
        if ((int)$c['id'] === $editId) { $editCenter = $c; break; }
    }
}
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="fw-bold mb-0"><i class="bi bi-diagram-3"></i> Centre de profit</h4>
</div>

<div class="cf-card p-3 mb-4">
  <h6 class="fw-bold mb-3"><?= $editCenter ? 'Editează centrul de profit' : 'Adaugă centru de profit' ?></h6>
  <form method="post" action="<?= cashflow_url('profit_centers') ?>" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
    <input type="hidden" name="do" value="save">
    <input type="hidden" name="id" value="<?= $editCenter ? (int)$editCenter['id'] : 0 ?>">

    <div class="col-md-4">
      <label class="form-label small fw-bold">Nume</label>
      <input type="text" name="name" class="form-control" required value="<?= cashflow_e($editCenter['name'] ?? '') ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label small fw-bold">Cod (unic)</label>
      <input type="text" name="code" class="form-control" required value="<?= cashflow_e($editCenter['code'] ?? '') ?>" <?= $editCenter ? 'readonly' : '' ?>>
    </div>
    <div class="col-md-2">
      <label class="form-label small fw-bold">Culoare</label>
      <input type="color" name="color" class="form-control form-control-color" value="<?= cashflow_e($editCenter['color'] ?? '#6366f1') ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label small fw-bold">Icon (bootstrap-icons)</label>
      <input type="text" name="icon" class="form-control" value="<?= cashflow_e($editCenter['icon'] ?? 'bi-briefcase') ?>" placeholder="bi-truck">
    </div>

    <?php if (!$editCenter): ?>
    <div class="col-md-4">
      <label class="form-label small fw-bold">Tip activitate</label>
      <select name="type" class="form-select">
        <option value="custom">General</option>
        <option value="transport">Transport (curse, vehicule, șoferi)</option>
        <option value="service">Service / Detailing / Colantări (lucrări)</option>
      </select>
      <div class="form-text">Determină panoul de KPI specific afișat pe dashboard-ul acestui centru.</div>
    </div>
    <?php endif; ?>

    <div class="col-md-6">
      <label class="form-label small fw-bold">Descriere</label>
      <input type="text" name="description" class="form-control" value="<?= cashflow_e($editCenter['description'] ?? '') ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label small fw-bold">Buget</label>
      <input type="text" name="budget_amount" class="form-control" value="<?= $editCenter['budget_amount'] ?? '' ?>" placeholder="ex: 100000">
    </div>
    <div class="col-md-3">
      <label class="form-label small fw-bold">Perioadă buget</label>
      <select name="budget_period" class="form-select">
        <option value="">-</option>
        <option value="monthly" <?= ($editCenter['budget_period'] ?? '') === 'monthly' ? 'selected' : '' ?>>Lunar</option>
        <option value="yearly" <?= ($editCenter['budget_period'] ?? '') === 'yearly' ? 'selected' : '' ?>>Anual</option>
      </select>
    </div>

    <div class="col-12">
      <button type="submit" class="btn btn-primary fw-bold"><?= $editCenter ? 'Salvează modificările' : 'Creează centrul' ?></button>
      <?php if ($editCenter): ?><a href="<?= cashflow_url('profit_centers') ?>" class="btn btn-outline-secondary">Renunță</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="cf-card p-0 overflow-hidden">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light small text-uppercase text-muted">
        <tr>
          <th class="ps-3">Centru</th>
          <th>Cod</th>
          <th>Buget</th>
          <th>Status</th>
          <th class="text-end pe-3">Acțiuni</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($centers as $pc): ?>
          <tr>
            <td class="ps-3">
              <span class="cf-pc-dot" style="background: <?= cashflow_e($pc['color']) ?>; width:28px; height:28px; font-size:.85rem;">
                <i class="bi <?= cashflow_e($pc['icon']) ?>"></i>
              </span>
              <strong><?= cashflow_e($pc['name']) ?></strong>
              <?php $typeLabels = ['corporate' => 'general', 'transport' => 'transport', 'service' => 'service/detailing']; ?>
              <?php if (isset($typeLabels[$pc['type']])): ?><span class="badge bg-secondary-subtle text-secondary border ms-1"><?= cashflow_e($typeLabels[$pc['type']]) ?></span><?php endif; ?>
            </td>
            <td class="small text-muted"><?= cashflow_e($pc['code']) ?></td>
            <td class="small"><?= $pc['budget_amount'] ? cashflow_money((float)$pc['budget_amount']) . ' / ' . ($pc['budget_period'] === 'yearly' ? 'an' : 'lună') : '-' ?></td>
            <td>
              <?php if ($pc['status'] === 'active'): ?>
                <span class="badge bg-success-subtle text-success border border-success">Activ</span>
              <?php else: ?>
                <span class="badge bg-secondary-subtle text-secondary border">Inactiv</span>
              <?php endif; ?>
            </td>
            <td class="text-end pe-3">
              <a href="<?= cashflow_url('profit_centers', ['edit' => $pc['id']]) ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
              <?php if ($pc['type'] !== 'corporate'): ?>
                <form method="post" action="<?= cashflow_url('profit_centers') ?>" class="d-inline">
                  <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
                  <input type="hidden" name="do" value="toggle_status">
                  <input type="hidden" name="id" value="<?= (int)$pc['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-<?= $pc['status'] === 'active' ? 'danger' : 'success' ?>">
                    <i class="bi bi-<?= $pc['status'] === 'active' ? 'pause' : 'play' ?>"></i>
                  </button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<p class="text-muted small mt-2">Centrele nu pot fi șterse fizic dacă au tranzacții asociate — folosește dezactivarea (Activ/Inactiv).</p>
