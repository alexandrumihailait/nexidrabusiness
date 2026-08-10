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

    // FIXED: Prevent white screen by using the smart redirect
    cashflow_redirect(cashflow_url('accounts'));
}

$stmt = $pdo->prepare("SELECT * FROM cf_accounts WHERE company_id = ? ORDER BY status = 'active' DESC, name ASC");
$stmt->execute([$companyId]);
$accounts = $stmt->fetchAll();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
  <div>
    <h3 class="fw-bold mb-1 text-white"><i class="bi bi-wallet2 text-info me-2"></i> Conturi / Gestiune</h3>
    <p class="text-muted small mb-0">Gestionează balanțele conturilor firmei</p>
  </div>
</div>

<div class="row">
    <div class="col-xl-4 mb-4">
        <div class="cf-card p-4 h-100">
            <h5 class="fw-bold mb-4 text-white d-flex align-items-center"><i class="bi bi-plus-circle-dotted me-2 text-primary"></i> Adaugă cont nou</h5>
            <form method="post" action="<?= cashflow_url('accounts') ?>" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
                <input type="hidden" name="do" value="save">
                
                <div class="col-12">
                  <label class="form-label small text-muted text-uppercase fw-semibold mb-1">Nume Cont</label>
                  <input type="text" name="name" class="form-control form-control-lg fs-6" required placeholder="ex: Caserie / ING RON">
                </div>
                
                <div class="col-6">
                  <label class="form-label small text-muted text-uppercase fw-semibold mb-1">Bancă</label>
                  <input type="text" name="bank" class="form-control" placeholder="Opțional">
                </div>
                
                <div class="col-6">
                  <label class="form-label small text-muted text-uppercase fw-semibold mb-1">Tip</label>
                  <select name="type" class="form-select">
                    <option value="bank">Cont Bancar</option>
                    <option value="cash">Numerar (Casă)</option>
                    <option value="card">Card Credit</option>
                  </select>
                </div>

                <div class="col-12">
                  <label class="form-label small text-muted text-uppercase fw-semibold mb-1">IBAN</label>
                  <input type="text" name="iban" class="form-control font-monospace" placeholder="ROXX INGB...">
                </div>

                <div class="col-6">
                  <label class="form-label small text-muted text-uppercase fw-semibold mb-1">Sold Inițial</label>
                  <input type="text" name="opening_balance" class="form-control fw-bold" value="0.00">
                </div>
                
                <div class="col-6">
                  <label class="form-label small text-muted text-uppercase fw-semibold mb-1">Monedă</label>
                  <input type="text" name="currency" class="form-control fw-bold" value="<?= cashflow_e($company['currency']) ?>" maxlength="3">
                </div>
                
                <div class="col-12 mt-4">
                  <button type="submit" class="btn btn-primary w-100 py-2 fw-bold shadow-sm">Creează Contul</button>
                </div>
            </form>
        </div>
    </div>

    <div class="col-xl-8 mb-4">
        <div class="cf-card p-0 overflow-hidden h-100 d-flex flex-column">
          <div class="p-3 border-bottom border-secondary" style="border-color: var(--cf-border) !important;">
             <h5 class="fw-bold mb-0 text-white">Lista Conturilor</h5>
          </div>
          <div class="table-responsive flex-grow-1">
            <table class="table table-hover align-middle mb-0">
              <thead class="table-light small text-uppercase fw-semibold" style="letter-spacing: 0.5px;">
                <tr>
                  <th class="ps-4 py-3">Cont</th>
                  <th class="py-3">Detalii Bancare</th>
                  <th class="py-3 text-end">Balanță inițială</th>
                  <th class="py-3 text-center">Status</th>
                  <th class="text-end pe-4 py-3">Acțiuni</th>
                </tr>
              </thead>
              <tbody class="border-top-0">
                <?php foreach ($accounts as $acc): ?>
                  <tr>
                    <td class="ps-4 py-3">
                      <div class="d-flex align-items-center gap-3">
                        <div class="bg-dark rounded p-2 text-info d-flex align-items-center justify-content-center" style="width:40px; height:40px; border: 1px solid var(--cf-border);">
                            <i class="bi bi-<?= $acc['type'] === 'cash' ? 'cash-stack' : ($acc['type'] === 'card' ? 'credit-card' : 'bank') ?> fs-5"></i>
                        </div>
                        <div>
                            <div class="fw-bold text-white"><?= cashflow_e($acc['name']) ?></div>
                            <div class="small text-muted text-uppercase" style="font-size: 0.75rem;"><?= cashflow_e($acc['type']) ?></div>
                        </div>
                      </div>
                    </td>
                    <td class="py-3">
                        <div class="text-light"><?= cashflow_e($acc['bank'] ?: '-') ?></div>
                        <div class="small text-muted font-monospace"><?= cashflow_e($acc['iban'] ?: '') ?></div>
                    </td>
                    <td class="py-3 text-end">
                        <div class="fw-bold text-white fs-6"><?= cashflow_money((float)$acc['opening_balance'], $acc['currency']) ?></div>
                    </td>
                    <td class="py-3 text-center">
                      <?php if ($acc['status'] === 'active'): ?>
                        <span class="badge rounded-pill bg-success text-white px-3 py-2" style="background-color: rgba(16, 185, 129, 0.2) !important; color: #34d399 !important;">Activ</span>
                      <?php else: ?>
                        <span class="badge rounded-pill bg-secondary text-white px-3 py-2" style="background-color: rgba(100, 116, 139, 0.2) !important; color: #94a3b8 !important;">Inactiv</span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end pe-4 py-3">
                      <form method="post" action="<?= cashflow_url('accounts') ?>" class="d-inline">
                        <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
                        <input type="hidden" name="do" value="toggle_status">
                        <input type="hidden" name="id" value="<?= (int)$acc['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-dark border border-secondary text-<?= $acc['status'] === 'active' ? 'danger' : 'success' ?> shadow-sm" title="Schimbă status">
                          <i class="bi bi-<?= $acc['status'] === 'active' ? 'power' : 'arrow-counterclockwise' ?>"></i>
                        </button>
                      </form>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if(empty($accounts)): ?>
                  <tr><td colspan="5" class="text-center py-5 text-muted">Nu există conturi definite.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>
    </div>
</div>