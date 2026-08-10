<?php
/**
 * Shared/corporate cost allocation (spec section 13/32/33): splitting one
 * transaction's amount across several profit centers, optionally from a
 * reusable rule (e.g. "Software X -> Transport 50% / Detailing 30% /
 * Colantări 20%"). Allocations are recorded separately from the realized
 * cashflow (cf_transactions is never modified) -- they feed the "cost
 * alocat" view in reports, distinct from direct cashflow (section 34/35).
 *
 * @var PDO $pdo
 * @var array $company
 * @var array $accessibleProfitCenters
 * @var bool $isCompanyAdmin
 */

$companyId = (int)$company['id'];
$companyRoleArr = ['role_code' => $company['role_code'], 'role_name' => $company['role_name']];

$allCentersStmt = $pdo->prepare("SELECT * FROM cf_profit_centers WHERE company_id = ? AND status = 'active' ORDER BY type = 'corporate' ASC, name ASC");
$allCentersStmt->execute([$companyId]);
$allCenters = $allCentersStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cashflow_csrf_check();
    $action = $_POST['do'] ?? '';

    if ($action === 'save_rule' && $isCompanyAdmin) {
        $name = trim($_POST['name'] ?? '');
        $method = ($_POST['method'] ?? '') === 'fixed' ? 'fixed' : 'percent';
        $lines = $_POST['line'] ?? [];

        if ($name === '') {
            cashflow_flash_set('danger', 'Numele regulii este obligatoriu.');
        } else {
            $ins = $pdo->prepare("INSERT INTO cf_allocation_rules (company_id, name, method, status) VALUES (?, ?, ?, 'active')");
            $ins->execute([$companyId, $name, $method]);
            $ruleId = (int)$pdo->lastInsertId();

            foreach ($lines as $pcId => $value) {
                $value = (float)str_replace(',', '.', $value);
                if ($value <= 0) {
                    continue;
                }
                $pcCheck = $pdo->prepare("SELECT id FROM cf_profit_centers WHERE id = ? AND company_id = ?");
                $pcCheck->execute([(int)$pcId, $companyId]);
                if ($pcCheck->fetchColumn()) {
                    $pdo->prepare("INSERT INTO cf_allocation_rule_lines (rule_id, profit_center_id, value) VALUES (?, ?, ?)")
                        ->execute([$ruleId, (int)$pcId, $value]);
                }
            }
            cashflow_audit($pdo, $userId, $companyId, null, 'create', 'allocation_rule', $ruleId);
            cashflow_flash_set('success', 'Regula de alocare a fost creată.');
        }
        header('Location: ' . cashflow_url('allocations'));
        exit;
    }

    if ($action === 'toggle_rule' && $isCompanyAdmin) {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT status FROM cf_allocation_rules WHERE id = ? AND company_id = ?");
        $stmt->execute([$id, $companyId]);
        if ($cur = $stmt->fetchColumn()) {
            $pdo->prepare("UPDATE cf_allocation_rules SET status = ? WHERE id = ? AND company_id = ?")
                ->execute([$cur === 'active' ? 'inactive' : 'active', $id, $companyId]);
        }
        header('Location: ' . cashflow_url('allocations'));
        exit;
    }

    if ($action === 'apply_split') {
        if (!$isCompanyAdmin) {
            cashflow_forbidden('Doar administratorii firmei pot aloca o tranzacție pe alte centre de profit.');
        }
        $txId = (int)($_POST['transaction_id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM cf_transactions WHERE id = ? AND company_id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$txId, $companyId]);
        $tx = $stmt->fetch();

        if (!$tx) {
            cashflow_flash_set('danger', 'Tranzacția nu a fost găsită.');
            header('Location: ' . cashflow_url('allocations'));
            exit;
        }

        cashflow_require_profit_center_access($pdo, $userId, $companyId, (int)$tx['profit_center_id'], 'read_write', $companyRoleArr);

        $lines = $_POST['amount'] ?? [];
        $rows = [];
        $sum = 0.0;
        foreach ($lines as $pcId => $amount) {
            $amount = (float)str_replace(',', '.', $amount);
            if ($amount <= 0) {
                continue;
            }
            $pcCheck = $pdo->prepare("SELECT id FROM cf_profit_centers WHERE id = ? AND company_id = ?");
            $pcCheck->execute([(int)$pcId, $companyId]);
            if (!$pcCheck->fetchColumn()) {
                continue;
            }
            $rows[] = ['pc' => (int)$pcId, 'amount' => $amount];
            $sum += $amount;
        }

        if (empty($rows) || abs($sum - (float)$tx['amount']) > 0.01) {
            cashflow_flash_set('danger', sprintf(
                'Suma alocărilor (%s) trebuie să fie egală cu suma tranzacției (%s).',
                cashflow_money($sum, $tx['currency']),
                cashflow_money((float)$tx['amount'], $tx['currency'])
            ));
        } else {
            $pdo->prepare("DELETE FROM cf_transaction_allocations WHERE transaction_id = ?")->execute([$txId]);
            $ins = $pdo->prepare("INSERT INTO cf_transaction_allocations (transaction_id, profit_center_id, amount, percent) VALUES (?, ?, ?, ?)");
            foreach ($rows as $row) {
                $percent = round($row['amount'] / $sum * 100, 3);
                $ins->execute([$txId, $row['pc'], $row['amount'], $percent]);
            }
            cashflow_audit($pdo, $userId, $companyId, (int)$tx['profit_center_id'], 'allocate', 'transaction', $txId, ['lines' => $rows]);
            cashflow_flash_set('success', 'Alocarea a fost salvată.');
        }
        header('Location: ' . cashflow_url('allocations', ['tx' => $txId]));
        exit;
    }
}

$rulesStmt = $pdo->prepare("SELECT * FROM cf_allocation_rules WHERE company_id = ? ORDER BY status = 'active' DESC, name ASC");
$rulesStmt->execute([$companyId]);
$rules = $rulesStmt->fetchAll();

$ruleLines = [];
if (!empty($rules)) {
    $ruleIds = array_column($rules, 'id');
    $placeholders = implode(',', array_fill(0, count($ruleIds), '?'));
    $stmt = $pdo->prepare("SELECT rl.*, pc.name AS pc_name FROM cf_allocation_rule_lines rl JOIN cf_profit_centers pc ON pc.id = rl.profit_center_id WHERE rl.rule_id IN ($placeholders)");
    $stmt->execute($ruleIds);
    foreach ($stmt->fetchAll() as $line) {
        $ruleLines[$line['rule_id']][] = $line;
    }
}

$txId = isset($_GET['tx']) ? (int)$_GET['tx'] : null;
$transaction = null;
$existingAllocations = [];
if ($txId) {
    $stmt = $pdo->prepare(
        "SELECT t.*, pc.name AS pc_name FROM cf_transactions t JOIN cf_profit_centers pc ON pc.id = t.profit_center_id
         WHERE t.id = ? AND t.company_id = ? AND t.deleted_at IS NULL LIMIT 1"
    );
    $stmt->execute([$txId, $companyId]);
    $transaction = $stmt->fetch();
    if ($transaction) {
        cashflow_require_profit_center_access($pdo, $userId, $companyId, (int)$transaction['profit_center_id'], 'read', $companyRoleArr);
        $allocStmt = $pdo->prepare("SELECT * FROM cf_transaction_allocations WHERE transaction_id = ?");
        $allocStmt->execute([$txId]);
        foreach ($allocStmt->fetchAll() as $a) {
            $existingAllocations[$a['profit_center_id']] = $a;
        }
    }
}

// Recent unallocated-friendly candidates: expense transactions on the
// corporate/general center(s) the user can see, most likely to need splitting.
$candidatesStmt = $pdo->prepare(
    "SELECT t.*, pc.name AS pc_name FROM cf_transactions t
     JOIN cf_profit_centers pc ON pc.id = t.profit_center_id
     WHERE t.company_id = ? AND pc.type = 'corporate' AND t.type = 'expense' AND t.deleted_at IS NULL
     ORDER BY t.transaction_date DESC LIMIT 20"
);
$candidatesStmt->execute([$companyId]);
$candidates = $candidatesStmt->fetchAll();
$accessiblePcIds = array_map('intval', array_column($accessibleProfitCenters, 'id'));
$candidates = array_values(array_filter($candidates, fn ($c) => in_array((int)$c['profit_center_id'], $accessiblePcIds, true)));
?>

<h4 class="fw-bold mb-3"><i class="bi bi-diagram-2"></i> Alocare costuri</h4>

<?php if ($transaction): ?>
  <div class="cf-card p-3 mb-4">
    <h6 class="fw-bold mb-1">Alocă tranzacția</h6>
    <p class="text-muted small mb-3">
      <?= cashflow_e($transaction['description'] ?: ('Tranzacție #' . $transaction['id'])) ?> ·
      <?= cashflow_e($transaction['pc_name']) ?> ·
      <strong><?= cashflow_money((float)$transaction['amount'], $transaction['currency']) ?></strong> ·
      <?= date('d.m.Y', strtotime($transaction['transaction_date'])) ?>
    </p>
    <?php if ($isCompanyAdmin): ?>
    <form method="post" action="<?= cashflow_url('allocations', ['tx' => $transaction['id']]) ?>">
      <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
      <input type="hidden" name="do" value="apply_split">
      <input type="hidden" name="transaction_id" value="<?= (int)$transaction['id'] ?>">
      <div class="row g-2">
        <?php foreach ($allCenters as $pc): $existing = $existingAllocations[$pc['id']] ?? null; ?>
          <div class="col-6 col-md-3">
            <label class="small fw-bold d-flex align-items-center gap-1">
              <i class="bi <?= cashflow_e($pc['icon']) ?>" style="color: <?= cashflow_e($pc['color']) ?>"></i> <?= cashflow_e($pc['name']) ?>
            </label>
            <input type="text" name="amount[<?= (int)$pc['id'] ?>]" class="form-control form-control-sm" value="<?= $existing ? $existing['amount'] : '' ?>" placeholder="0.00">
          </div>
        <?php endforeach; ?>
      </div>
      <p class="text-muted small mt-2 mb-2">Suma alocărilor trebuie să fie egală cu suma tranzacției (<?= cashflow_money((float)$transaction['amount'], $transaction['currency']) ?>).</p>
      <button type="submit" class="btn btn-primary fw-bold btn-sm">Salvează alocarea</button>
      <a href="<?= cashflow_url('allocations') ?>" class="btn btn-outline-secondary btn-sm">Renunță</a>
    </form>
    <?php elseif (empty($existingAllocations)): ?>
      <p class="text-muted small mb-0">Doar administratorii firmei pot aloca această tranzacție pe alte centre de profit.</p>
    <?php else: ?>
      <p class="text-muted small mb-2">Distribuție curentă:</p>
      <?php foreach ($allCenters as $pc): if (empty($existingAllocations[$pc['id']])) continue; ?>
        <span class="badge bg-light text-dark border me-1"><?= cashflow_e($pc['name']) ?>: <?= cashflow_money((float)$existingAllocations[$pc['id']]['amount'], $transaction['currency']) ?></span>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if ($isCompanyAdmin): ?>
<div class="cf-card p-3 mb-4">
  <h6 class="fw-bold mb-3">Regulă de alocare nouă</h6>
  <form method="post" action="<?= cashflow_url('allocations') ?>" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
    <input type="hidden" name="do" value="save_rule">
    <div class="col-md-5"><label class="form-label small fw-bold">Nume regulă</label><input type="text" name="name" class="form-control" placeholder="ex: Abonament software" required></div>
    <div class="col-md-3">
      <label class="form-label small fw-bold">Metodă</label>
      <select name="method" class="form-select">
        <option value="percent">Procent</option>
        <option value="fixed">Sumă fixă</option>
      </select>
    </div>
    <div class="col-12 row g-2">
      <?php foreach ($allCenters as $pc): ?>
        <div class="col-6 col-md-3">
          <label class="small fw-bold"><?= cashflow_e($pc['name']) ?></label>
          <input type="text" name="line[<?= (int)$pc['id'] ?>]" class="form-control form-control-sm" placeholder="ex: 50">
        </div>
      <?php endforeach; ?>
    </div>
    <div class="col-12"><button type="submit" class="btn btn-primary fw-bold btn-sm">Creează regula</button></div>
  </form>
</div>

<div class="cf-card p-0 overflow-hidden mb-4">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 small">
      <thead class="table-light text-uppercase text-muted">
        <tr><th class="ps-3">Regulă</th><th>Metodă</th><th>Distribuție</th><th>Status</th><th class="pe-3 text-end">Acțiuni</th></tr>
      </thead>
      <tbody>
        <?php if (empty($rules)): ?><tr><td colspan="5" class="text-center py-4 text-muted">Nicio regulă definită.</td></tr><?php endif; ?>
        <?php foreach ($rules as $r): ?>
          <tr>
            <td class="ps-3 fw-bold"><?= cashflow_e($r['name']) ?></td>
            <td><?= $r['method'] === 'percent' ? 'Procent' : 'Sumă fixă' ?></td>
            <td>
              <?php foreach ($ruleLines[$r['id']] ?? [] as $line): ?>
                <span class="badge bg-light text-dark border me-1"><?= cashflow_e($line['pc_name']) ?>: <?= $line['value'] ?><?= $r['method'] === 'percent' ? '%' : '' ?></span>
              <?php endforeach; ?>
            </td>
            <td><?= $r['status'] === 'active' ? '<span class="badge bg-success-subtle text-success border border-success">Activă</span>' : '<span class="badge bg-secondary-subtle text-secondary border">Inactivă</span>' ?></td>
            <td class="pe-3 text-end">
              <form method="post" action="<?= cashflow_url('allocations') ?>">
                <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
                <input type="hidden" name="do" value="toggle_rule">
                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-<?= $r['status'] === 'active' ? 'danger' : 'success' ?>"><i class="bi bi-<?= $r['status'] === 'active' ? 'pause' : 'play' ?>"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<h6 class="fw-bold mb-2">Tranzacții Corporate/General recente (candidați pentru alocare)</h6>
<div class="cf-card p-0 overflow-hidden">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 small">
      <thead class="table-light text-uppercase text-muted">
        <tr><th class="ps-3">Data</th><th>Descriere</th><th class="text-end">Sumă</th><th class="pe-3 text-end">Acțiuni</th></tr>
      </thead>
      <tbody>
        <?php if (empty($candidates)): ?><tr><td colspan="4" class="text-center py-4 text-muted">Nicio tranzacție corporate recentă.</td></tr><?php endif; ?>
        <?php foreach ($candidates as $c): ?>
          <tr>
            <td class="ps-3"><?= date('d.m.Y', strtotime($c['transaction_date'])) ?></td>
            <td><?= cashflow_e($c['description'] ?: '-') ?></td>
            <td class="text-end"><?= cashflow_money((float)$c['amount'], $c['currency']) ?></td>
            <td class="pe-3 text-end"><a href="<?= cashflow_url('allocations', ['tx' => $c['id']]) ?>" class="btn btn-sm btn-outline-primary">Alocă</a></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
