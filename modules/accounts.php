<?php
/**
 * @var PDO $pdo
 * @var array $company
 * @var bool $isCompanyAdmin
 */

if (!$isCompanyAdmin) {
    cashflow_forbidden('Doar administratorii firmei pot gestiona conturile.');
}

$companyId = (int)$company['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cashflow_csrf_check();
    $action = $_POST['do'] ?? '';

    if ($action === 'save') {
        $name = trim($_POST['name'] ?? '');
        $bank = trim($_POST['bank'] ?? '') ?: null;
        $iban = trim($_POST['iban'] ?? '') ?: null;
        $currency = strtoupper(substr(trim($_POST['currency'] ?? 'RON'), 0, 3)) ?: 'RON';
        $type = in_array($_POST['type'] ?? '', ['bank', 'cash', 'card'], true) ? $_POST['type'] : 'bank';
        $opening = (float)str_replace(',', '.', $_POST['opening_balance'] ?? '0');

        if ($name === '') {
            cashflow_flash_set('danger', 'Numele contului este obligatoriu.');
        } else {
            $ins = $pdo->prepare(
                "INSERT INTO cf_accounts (company_id, name, bank, iban, currency, type, opening_balance, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 'active')"
            );
            $ins->execute([$companyId, $name, $bank, $iban, $currency, $type, $opening]);
            $newId = (int)$pdo->lastInsertId();
            cashflow_audit($pdo, $userId, $companyId, null, 'create', 'account', $newId);
            cashflow_flash_set('success', 'Contul a fost creat.');
        }
    }

    if ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT status FROM cf_accounts WHERE id = ? AND company_id = ? LIMIT 1");
        $stmt->execute([$id, $companyId]);
        $acc = $stmt->fetch();
        if ($acc) {
            $newStatus = $acc['status'] === 'active' ? 'inactive' : 'active';
            $upd = $pdo->prepare("UPDATE cf_accounts SET status = ? WHERE id = ? AND company_id = ?");
            $upd->execute([$newStatus, $id, $companyId]);
            cashflow_audit($pdo, $userId, $companyId, null, 'status_change', 'account', $id, ['status' => $newStatus]);
        }
    }

    header('Location: ' . cashflow_url('accounts'));
    exit;
}

$stmt = $pdo->prepare("SELECT * FROM cf_accounts WHERE company_id = ? ORDER BY status = 'active' DESC, name ASC");
$stmt->execute([$companyId]);
$accounts = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="fw-bold mb-0"><i class="bi bi-bank"></i> Conturi</h4>
</div>

<div class="cf-card p-3 mb-4">
  <h6 class="fw-bold mb-3">Adaugă cont</h6>
  <form method="post" action="<?= cashflow_url('accounts') ?>" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
    <input type="hidden" name="do" value="save">
    <div class="col-md-3">
      <label class="form-label small fw-bold">Nume</label>
      <input type="text" name="name" class="form-control" required placeholder="ex: ING RON">
    </div>
    <div class="col-md-3">
      <label class="form-label small fw-bold">Bancă</label>
      <input type="text" name="bank" class="form-control">
    </div>
    <div class="col-md-3">
      <label class="form-label small fw-bold">IBAN</label>
      <input type="text" name="iban" class="form-control">
    </div>
    <div class="col-md-1">
      <label class="form-label small fw-bold">Monedă</label>
      <input type="text" name="currency" class="form-control" value="<?= cashflow_e($company['currency']) ?>" maxlength="3">
    </div>
    <div class="col-md-2">
      <label class="form-label small fw-bold">Tip</label>
      <select name="type" class="form-select">
        <option value="bank">Bancă</option>
        <option value="cash">Numerar</option>
        <option value="card">Card</option>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label small fw-bold">Sold inițial</label>
      <input type="text" name="opening_balance" class="form-control" value="0">
    </div>
    <div class="col-12">
      <button type="submit" class="btn btn-primary fw-bold">Creează contul</button>
    </div>
  </form>
</div>

<div class="cf-card p-0 overflow-hidden">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light small text-uppercase text-muted">
        <tr>
          <th class="ps-3">Cont</th>
          <th>Bancă / IBAN</th>
          <th>Tip</th>
          <th>Monedă</th>
          <th>Sold inițial</th>
          <th>Status</th>
          <th class="text-end pe-3">Acțiuni</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($accounts as $acc): ?>
          <tr>
            <td class="ps-3 fw-bold"><?= cashflow_e($acc['name']) ?></td>
            <td class="small text-muted"><?= cashflow_e($acc['bank']) ?><?= $acc['iban'] ? '<br>' . cashflow_e($acc['iban']) : '' ?></td>
            <td class="small text-capitalize"><?= cashflow_e($acc['type']) ?></td>
            <td class="small"><?= cashflow_e($acc['currency']) ?></td>
            <td class="small"><?= cashflow_money((float)$acc['opening_balance'], $acc['currency']) ?></td>
            <td>
              <?php if ($acc['status'] === 'active'): ?>
                <span class="badge bg-success-subtle text-success border border-success">Activ</span>
              <?php else: ?>
                <span class="badge bg-secondary-subtle text-secondary border">Inactiv</span>
              <?php endif; ?>
            </td>
            <td class="text-end pe-3">
              <form method="post" action="<?= cashflow_url('accounts') ?>" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
                <input type="hidden" name="do" value="toggle_status">
                <input type="hidden" name="id" value="<?= (int)$acc['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-<?= $acc['status'] === 'active' ? 'danger' : 'success' ?>">
                  <i class="bi bi-<?= $acc['status'] === 'active' ? 'pause' : 'play' ?>"></i>
                </button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
