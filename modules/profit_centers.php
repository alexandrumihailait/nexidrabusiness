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
        $color = trim($_POST['color'] ?? '#0ea5e9');
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

    // FIXED: Smart JS redirect to avoid "headers already sent"
    cashflow_redirect(cashflow_url('profit_centers'));
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

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h3 class="fw-bold mb-1 text-white"><i class="bi bi-diagram-3 text-info me-2"></i> Centre de Profit</h3>
    <p class="text-muted small mb-0">Structura diviziilor și setarea bugetelor</p>
  </div>
</div>

<div class="cf-card p-4 mb-4">
  <h5 class="fw-bold mb-4 text-white"><i class="bi <?= $editCenter ? 'bi-pencil-square text-warning' : 'bi-plus-circle-dotted text-primary' ?> me-2"></i> <?= $editCenter ? 'Editează Divizia' : 'Adaugă o nouă divizie' ?></h5>
  <form method="post" action="<?= cashflow_url('profit_centers') ?>" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
    <input type="hidden" name="do" value="save">
    <input type="hidden" name="id" value="<?= $editCenter ? (int)$editCenter['id'] : 0 ?>">

    <div class="col-md-4">
      <label class="form-label small text-muted text-uppercase fw-semibold mb-1">Nume Centru</label>
      <input type="text" name="name" class="form-control" required value="<?= cashflow_e($editCenter['name'] ?? '') ?>" placeholder="ex: Flota Cluj">
    </div>
    <div class="col-md-3">
      <label class="form-label small text-muted text-uppercase fw-semibold mb-1">Cod Unic</label>
      <input type="text" name="code" class="form-control font-monospace" required value="<?= cashflow_e($editCenter['code'] ?? '') ?>" <?= $editCenter ? 'readonly' : '' ?> placeholder="cluj_1">
    </div>
    
    <?php if (!$editCenter): ?>
    <div class="col-md-5">
      <label class="form-label small text-muted text-uppercase fw-semibold mb-1">Tip Activitate</label>
      <select name="type" class="form-select">
        <option value="custom">General</option>
        <option value="transport">Transport (Curse, Vehicule)</option>
        <option value="service">Service (Detailing, Reparații)</option>
      </select>
    </div>
    <?php else: ?>
        <div class="col-md-5"></div>
    <?php endif; ?>

    <div class="col-md-2">
      <label class="form-label small text-muted text-uppercase fw-semibold mb-1">Accent Culoare</label>
      <input type="color" name="color" class="form-control form-control-color w-100 px-2 bg-dark" value="<?= cashflow_e($editCenter['color'] ?? '#0ea5e9') ?>" style="height: 42px;">
    </div>
    <div class="col-md-3">
      <label class="form-label small text-muted text-uppercase fw-semibold mb-1">Icon (clasa Bootstrap)</label>
      <input type="text" name="icon" class="form-control" value="<?= cashflow_e($editCenter['icon'] ?? 'bi-briefcase') ?>">
    </div>

    <div class="col-md-7">
      <label class="form-label small text-muted text-uppercase fw-semibold mb-1">Descriere (Opțional)</label>
      <input type="text" name="description" class="form-control" value="<?= cashflow_e($editCenter['description'] ?? '') ?>" placeholder="Notițe despre divizie...">
    </div>
    
    <div class="col-md-3">
      <label class="form-label small text-muted text-uppercase fw-semibold mb-1">Țintă Buget</label>
      <input type="text" name="budget_amount" class="form-control" value="<?= $editCenter['budget_amount'] ?? '' ?>" placeholder="0.00">
    </div>
    <div class="col-md-3">
      <label class="form-label small text-muted text-uppercase fw-semibold mb-1">Ciclu Buget</label>
      <select name="budget_period" class="form-select">
        <option value="">- Nu se aplică -</option>
        <option value="monthly" <?= ($editCenter['budget_period'] ?? '') === 'monthly' ? 'selected' : '' ?>>Lunar</option>
        <option value="yearly" <?= ($editCenter['budget_period'] ?? '') === 'yearly' ? 'selected' : '' ?>>Anual</option>
      </select>
    </div>

    <div class="col-12 mt-4">
      <button type="submit" class="btn btn-primary fw-bold px-4"><?= $editCenter ? 'Salvează modificările' : 'Creează Divizia' ?></button>
      <?php if ($editCenter): ?><a href="<?= cashflow_url('profit_centers') ?>" class="btn btn-light px-4 ms-2">Anulează editarea</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="cf-card p-0 overflow-hidden">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light small text-uppercase fw-semibold" style="letter-spacing: 0.5px;">
        <tr>
          <th class="ps-4 py-3">Nume Centru</th>
          <th class="py-3">Cod Intern</th>
          <th class="py-3">Structură Buget</th>
          <th class="py-3 text-center">Status</th>
          <th class="text-end pe-4 py-3">Acțiuni</th>
        </tr>
      </thead>
      <tbody class="border-top-0">
        <?php foreach ($centers as $pc): ?>
          <tr>
            <td class="ps-4 py-3">
              <div class="d-flex align-items-center gap-3">
                <div class="rounded-circle d-flex align-items-center justify-content-center text-white shadow-sm" style="background: <?= cashflow_e($pc['color']) ?>; width: 38px; height: 38px;">
                  <i class="bi <?= cashflow_e($pc['icon']) ?>"></i>
                </div>
                <div>
                  <strong class="text-white d-block"><?= cashflow_e($pc['name']) ?></strong>
                  <?php $typeLabels = ['corporate' => 'General', 'transport' => 'Transport', 'service' => 'Service/Lucrări']; ?>
                  <?php if (isset($typeLabels[$pc['type']])): ?>
                    <span class="small text-info" style="font-size: 0.75rem;"><?= cashflow_e($typeLabels[$pc['type']]) ?></span>
                  <?php endif; ?>
                </div>
              </div>
            </td>
            <td class="py-3 font-monospace small text-muted"><?= cashflow_e($pc['code']) ?></td>
            <td class="py-3 text-light">
              <?php if($pc['budget_amount']): ?>
                <span class="fw-bold"><?= cashflow_money((float)$pc['budget_amount']) ?></span> 
                <span class="text-muted small">/ <?= $pc['budget_period'] === 'yearly' ? 'an' : 'lună' ?></span>
              <?php else: ?>
                <span class="text-muted">-</span>
              <?php endif; ?>
            </td>
            <td class="py-3 text-center">
              <?php if ($pc['status'] === 'active'): ?>
                 <span class="badge rounded-pill bg-success text-white px-3 py-1" style="background-color: rgba(16, 185, 129, 0.2) !important; color: #34d399 !important;">Activ</span>
              <?php else: ?>
                 <span class="badge rounded-pill bg-secondary text-white px-3 py-1" style="background-color: rgba(100, 116, 139, 0.2) !important; color: #94a3b8 !important;">Inactiv</span>
              <?php endif; ?>
            </td>
            <td class="text-end pe-4 py-3">
              <a href="<?= cashflow_url('profit_centers', ['edit' => $pc['id']]) ?>" class="btn btn-sm btn-dark border border-secondary text-light shadow-sm me-1"><i class="bi bi-pencil"></i></a>
              <?php if ($pc['type'] !== 'corporate'): ?>
                <form method="post" action="<?= cashflow_url('profit_centers') ?>" class="d-inline">
                  <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
                  <input type="hidden" name="do" value="toggle_status">
                  <input type="hidden" name="id" value="<?= (int)$pc['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-dark border border-secondary text-<?= $pc['status'] === 'active' ? 'danger' : 'success' ?> shadow-sm">
                    <i class="bi bi-<?= $pc['status'] === 'active' ? 'power' : 'arrow-counterclockwise' ?>"></i>
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