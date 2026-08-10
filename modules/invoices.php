<?php
/**
 * Creanțe (receivables, invoices issued to clients) and datorii
 * (payables, invoices received from suppliers). Paying/settling one
 * posts the matching transaction into the ledger.
 *
 * @var PDO $pdo
 * @var array $company
 * @var array|null $activeProfitCenter
 * @var array $accessibleProfitCenters
 */

$companyId = (int)$company['id'];
$companyRoleArr = ['role_code' => $company['role_code'], 'role_name' => $company['role_name']];

$writableCenters = array_values(array_filter(
    $accessibleProfitCenters,
    fn ($pc) => in_array($pc['access_level'], ['read_write', 'full'], true)
));
$filterPcIds = $activeProfitCenter
    ? [(int)$activeProfitCenter['id']]
    : array_map('intval', array_column($accessibleProfitCenters, 'id'));

$accounts = [];
$acctStmt = $pdo->prepare("SELECT * FROM cf_accounts WHERE company_id = ? AND status = 'active' ORDER BY name ASC");
$acctStmt->execute([$companyId]);
$accounts = $acctStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cashflow_csrf_check();
    $action = $_POST['do'] ?? '';

    if ($action === 'create') {
        $pcId = (int)($_POST['profit_center_id'] ?? 0);
        cashflow_require_profit_center_access($pdo, $userId, $companyId, $pcId, 'read_write', $companyRoleArr);

        $direction = ($_POST['direction'] ?? '') === 'payable' ? 'payable' : 'receivable';
        $amount = (float)str_replace(',', '.', $_POST['amount'] ?? '0');
        $currency = strtoupper(substr(trim($_POST['currency'] ?? $company['currency']), 0, 3)) ?: $company['currency'];
        $exchangeRate = $currency === $company['currency'] ? 1.0 : (float)str_replace(',', '.', $_POST['exchange_rate'] ?? '1');
        $issueDate = $_POST['issue_date'] ?? date('Y-m-d');
        $dueDate = $_POST['due_date'] ?: null;

        if ($amount <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueDate) || $exchangeRate <= 0) {
            cashflow_flash_set('danger', 'Date invalide pentru factură.');
        } else {
            $partnerId = cashflow_resolve_partner($pdo, $companyId, trim($_POST['partner'] ?? ''));
            $amountRon = round($amount * $exchangeRate, 2);

            $ins = $pdo->prepare(
                "INSERT INTO cf_invoices (company_id, profit_center_id, partner_id, direction, invoice_number, issue_date, due_date, amount, currency, exchange_rate, amount_ron, description, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'unpaid', ?)"
            );
            $ins->execute([
                $companyId, $pcId, $partnerId, $direction,
                trim($_POST['invoice_number'] ?? '') ?: null, $issueDate, $dueDate,
                $amount, $currency, $exchangeRate, $amountRon,
                trim($_POST['description'] ?? '') ?: null, $userId,
            ]);
            $invId = (int)$pdo->lastInsertId();
            cashflow_audit($pdo, $userId, $companyId, $pcId, 'create', 'invoice', $invId, ['direction' => $direction]);
            cashflow_flash_set('success', $direction === 'receivable' ? 'Creanța a fost înregistrată.' : 'Datoria a fost înregistrată.');
        }
        header('Location: ' . cashflow_url('invoices', ['dir' => $direction]));
        exit;
    }

    if ($action === 'pay') {
        $invId = (int)($_POST['id'] ?? 0);
        $accountId = (int)($_POST['account_id'] ?? 0);
        $payAmount = (float)str_replace(',', '.', $_POST['pay_amount'] ?? '0');

        $stmt = $pdo->prepare("SELECT * FROM cf_invoices WHERE id = ? AND company_id = ? LIMIT 1");
        $stmt->execute([$invId, $companyId]);
        $invoice = $stmt->fetch();

        if ($invoice && $invoice['status'] !== 'paid' && $invoice['status'] !== 'cancelled' && $payAmount > 0) {
            cashflow_require_profit_center_access($pdo, $userId, $companyId, (int)$invoice['profit_center_id'], 'read_write', $companyRoleArr);
            $acctCheck = $pdo->prepare("SELECT id FROM cf_accounts WHERE id = ? AND company_id = ? AND status = 'active'");
            $acctCheck->execute([$accountId, $companyId]);

            $remainingRon = (float)$invoice['amount_ron'] - (float)$invoice['paid_amount_ron'];
            $payAmountRon = min($payAmount * (float)$invoice['exchange_rate'], $remainingRon);

            if ($acctCheck->fetchColumn() && $payAmountRon > 0) {
                $type = $invoice['direction'] === 'receivable' ? 'income' : 'expense';
                $catName = $invoice['direction'] === 'receivable' ? 'Încasare factură' : 'Plată factură';
                $catId = cashflow_resolve_category($pdo, $companyId, $type, $catName);

                $txId = cashflow_create_transaction($pdo, [
                    'company_id' => $companyId, 'profit_center_id' => $invoice['profit_center_id'], 'account_id' => $accountId,
                    'user_id' => $userId, 'type' => $type, 'category_id' => $catId, 'partner_id' => $invoice['partner_id'],
                    'amount' => min($payAmount, $remainingRon / (float)$invoice['exchange_rate']), 'currency' => $invoice['currency'], 'exchange_rate' => $invoice['exchange_rate'],
                    'transaction_date' => date('Y-m-d'), 'invoice_number' => $invoice['invoice_number'],
                    'description' => ($invoice['direction'] === 'receivable' ? 'Încasare' : 'Plată') . ' factură ' . ($invoice['invoice_number'] ?: '#' . $invId),
                ]);

                $newPaidRon = round((float)$invoice['paid_amount_ron'] + $payAmountRon, 2);
                $newStatus = $newPaidRon >= (float)$invoice['amount_ron'] - 0.01 ? 'paid' : 'partial';

                $upd = $pdo->prepare("UPDATE cf_invoices SET paid_amount_ron = ?, status = ?, payment_transaction_id = ? WHERE id = ?");
                $upd->execute([$newPaidRon, $newStatus, $txId, $invId]);
                cashflow_audit($pdo, $userId, $companyId, (int)$invoice['profit_center_id'], 'pay', 'invoice', $invId, ['amount_ron' => $payAmountRon]);
                cashflow_flash_set('success', 'Plata a fost înregistrată în cashflow.');
            }
        }
        header('Location: ' . cashflow_url('invoices', ['dir' => $invoice ? $invoice['direction'] : 'receivable']));
        exit;
    }
}

$direction = ($_GET['dir'] ?? 'receivable') === 'payable' ? 'payable' : 'receivable';

$invoices = [];
if (!empty($filterPcIds)) {
    $placeholders = implode(',', array_fill(0, count($filterPcIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT i.*, pc.name AS pc_name, pc.color AS pc_color, pc.icon AS pc_icon, p.name AS partner_name
         FROM cf_invoices i
         JOIN cf_profit_centers pc ON pc.id = i.profit_center_id
         LEFT JOIN cf_partners p ON p.id = i.partner_id
         WHERE i.company_id = ? AND i.profit_center_id IN ($placeholders) AND i.direction = ?
         ORDER BY (i.status <> 'paid') DESC, i.due_date ASC, i.id DESC LIMIT 150"
    );
    $stmt->execute(array_merge([$companyId], $filterPcIds, [$direction]));
    $invoices = $stmt->fetchAll();
}

$totals = cashflow_receivables_payables($pdo, $companyId, $filterPcIds);
$defaultPcId = $activeProfitCenter && in_array($activeProfitCenter['access_level'], ['read_write', 'full'], true)
    ? (int)$activeProfitCenter['id']
    : ($writableCenters[0]['id'] ?? null);
$today = date('Y-m-d');
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <h4 class="fw-bold mb-0"><i class="bi bi-receipt"></i> Facturi</h4>
  <ul class="nav nav-pills small">
    <li class="nav-item"><a class="nav-link <?= $direction === 'receivable' ? 'active' : '' ?>" href="<?= cashflow_url('invoices', ['dir' => 'receivable']) ?>">Creanțe (de încasat)</a></li>
    <li class="nav-item"><a class="nav-link <?= $direction === 'payable' ? 'active' : '' ?>" href="<?= cashflow_url('invoices', ['dir' => 'payable']) ?>">Datorii (de plătit)</a></li>
  </ul>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="cf-kpi cf-kpi-in">
      <div class="cf-kpi-label">Total creanțe nedecontate</div>
      <div class="cf-kpi-value"><?= cashflow_money($totals['receivable']) ?></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="cf-kpi cf-kpi-out">
      <div class="cf-kpi-label">Total datorii nedecontate</div>
      <div class="cf-kpi-value"><?= cashflow_money($totals['payable']) ?></div>
    </div>
  </div>
</div>

<?php if (!empty($writableCenters)): ?>
<div class="cf-card p-3 mb-4">
  <h6 class="fw-bold mb-3">Factură nouă (<?= $direction === 'receivable' ? 'emisă către client' : 'primită de la furnizor' ?>)</h6>
  <form method="post" action="<?= cashflow_url('invoices') ?>" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
    <input type="hidden" name="do" value="create">
    <input type="hidden" name="direction" value="<?= cashflow_e($direction) ?>">

    <div class="col-md-3">
      <label class="form-label small fw-bold">Centru de profit</label>
      <select name="profit_center_id" class="form-select" required>
        <?php foreach ($writableCenters as $pc): ?>
          <option value="<?= (int)$pc['id'] ?>" <?= $defaultPcId === (int)$pc['id'] ? 'selected' : '' ?>><?= cashflow_e($pc['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3"><label class="form-label small fw-bold"><?= $direction === 'receivable' ? 'Client' : 'Furnizor' ?></label><input type="text" name="partner" class="form-control"></div>
    <div class="col-md-2"><label class="form-label small fw-bold">Nr. factură</label><input type="text" name="invoice_number" class="form-control"></div>
    <div class="col-md-2"><label class="form-label small fw-bold">Data emiterii</label><input type="date" name="issue_date" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
    <div class="col-md-2"><label class="form-label small fw-bold">Scadență</label><input type="date" name="due_date" class="form-control"></div>

    <div class="col-md-2"><label class="form-label small fw-bold">Sumă</label><input type="text" name="amount" class="form-control" required></div>
    <div class="col-md-2"><label class="form-label small fw-bold">Monedă</label><input type="text" name="currency" class="form-control" value="<?= cashflow_e($company['currency']) ?>" maxlength="3"></div>
    <div class="col-md-2"><label class="form-label small fw-bold">Curs</label><input type="text" name="exchange_rate" class="form-control" value="1"></div>
    <div class="col-md-6"><label class="form-label small fw-bold">Descriere</label><input type="text" name="description" class="form-control"></div>

    <div class="col-12"><button type="submit" class="btn btn-primary fw-bold">Salvează factura</button></div>
  </form>
</div>
<?php endif; ?>

<div class="cf-card p-0 overflow-hidden">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 small">
      <thead class="table-light text-uppercase text-muted">
        <tr>
          <th class="ps-3">Factură</th><th>Centru</th><th><?= $direction === 'receivable' ? 'Client' : 'Furnizor' ?></th>
          <th>Scadență</th><th class="text-end">Sumă</th><th class="text-end">Rest de plată</th><th>Status</th><th class="pe-3 text-end">Acțiuni</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($invoices)): ?>
          <tr><td colspan="8" class="text-center py-5 text-muted">Nicio factură.</td></tr>
        <?php endif; ?>
        <?php foreach ($invoices as $inv):
          $remaining = (float)$inv['amount_ron'] - (float)$inv['paid_amount_ron'];
          $remainingInInvoiceCurrency = $remaining / max((float)$inv['exchange_rate'], 0.0001);
          $overdue = $inv['due_date'] && $inv['due_date'] < $today && $inv['status'] !== 'paid';
        ?>
          <tr class="<?= $overdue ? 'table-danger' : '' ?>">
            <td class="ps-3 fw-bold"><?= cashflow_e($inv['invoice_number'] ?: '#' . $inv['id']) ?><br><small class="text-muted fw-normal"><?= date('d.m.Y', strtotime($inv['issue_date'])) ?></small></td>
            <td><i class="bi <?= cashflow_e($inv['pc_icon']) ?>" style="color: <?= cashflow_e($inv['pc_color']) ?>"></i> <?= cashflow_e($inv['pc_name']) ?></td>
            <td><?= cashflow_e($inv['partner_name'] ?: '-') ?></td>
            <td><?= $inv['due_date'] ? date('d.m.Y', strtotime($inv['due_date'])) : '-' ?><?= $overdue ? ' <span class="badge bg-danger">restantă</span>' : '' ?></td>
            <td class="text-end"><?= cashflow_money((float)$inv['amount'], $inv['currency']) ?></td>
            <td class="text-end fw-bold"><?= cashflow_money($remaining) ?></td>
            <td>
              <?php $badges = ['unpaid' => 'secondary', 'partial' => 'warning', 'paid' => 'success', 'cancelled' => 'secondary']; ?>
              <span class="badge bg-<?= $badges[$inv['status']] ?>-subtle text-<?= $badges[$inv['status']] ?> border border-<?= $badges[$inv['status']] ?>"><?= cashflow_e($inv['status']) ?></span>
            </td>
            <td class="pe-3 text-end">
              <?php if ($inv['status'] === 'unpaid' || $inv['status'] === 'partial'): ?>
                <form method="post" action="<?= cashflow_url('invoices') ?>" class="d-flex gap-1 justify-content-end">
                  <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
                  <input type="hidden" name="do" value="pay">
                  <input type="hidden" name="id" value="<?= (int)$inv['id'] ?>">
                  <input type="text" name="pay_amount" class="form-control form-control-sm" style="width:90px;" placeholder="sumă" value="<?= round($remainingInInvoiceCurrency, 2) ?>">
                  <select name="account_id" class="form-select form-select-sm" style="width:auto;" required>
                    <?php foreach ($accounts as $acc): ?><option value="<?= (int)$acc['id'] ?>"><?= cashflow_e($acc['name']) ?></option><?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn btn-sm btn-outline-primary" title="Marchează ca <?= $direction === 'receivable' ? 'încasată' : 'plătită' ?>"><i class="bi bi-check-circle"></i></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
