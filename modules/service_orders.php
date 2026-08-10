<?php
/**
 * Detailing / Service Auto / Colantări module: work orders (lucrări),
 * profit-center scoped, settle into the ledger like transport trips.
 *
 * @var PDO $pdo
 * @var array $company
 * @var array|null $activeProfitCenter
 * @var array $accessibleProfitCenters
 * @var bool $isCompanyAdmin
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

$serviceCategories = ['Mecanică', 'Diagnoză', 'Frâne', 'Suspensie', 'Motor', 'Electrică', 'AC', 'Distribuție', 'Polish', 'Ceramic coating', 'Interior', 'Exterior', 'Detailing complet', 'Colantare integrală', 'Colantare parțială', 'Branding', 'Folie geam', 'PPF'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cashflow_csrf_check();
    $action = $_POST['do'] ?? '';

    if ($action === 'save_order') {
        $pcId = (int)($_POST['profit_center_id'] ?? 0);
        cashflow_require_profit_center_access($pdo, $userId, $companyId, $pcId, 'read_write', $companyRoleArr);

        $dateIn = $_POST['date_in'] ?? date('Y-m-d');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateIn)) {
            cashflow_flash_set('danger', 'Data intrării este invalidă.');
        } else {
            $partnerId = cashflow_resolve_partner($pdo, $companyId, trim($_POST['partner'] ?? ''));

            $ins = $pdo->prepare(
                "INSERT INTO cf_work_orders (company_id, profit_center_id, order_number, partner_id, vehicle_plate, vehicle_make, vehicle_model, vehicle_vin, service_category, date_in, date_estimated, date_done, materials_cost, labor_cost, subcontractor_cost, other_cost, client_price, currency, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?)"
            );
            $ins->execute([
                $companyId, $pcId, trim($_POST['order_number'] ?? '') ?: null, $partnerId,
                trim($_POST['vehicle_plate'] ?? '') ?: null, trim($_POST['vehicle_make'] ?? '') ?: null,
                trim($_POST['vehicle_model'] ?? '') ?: null, trim($_POST['vehicle_vin'] ?? '') ?: null,
                trim($_POST['service_category'] ?? '') ?: null,
                $dateIn, $_POST['date_estimated'] ?: null, $_POST['date_done'] ?: null,
                (float)str_replace(',', '.', $_POST['materials_cost'] ?? '0'),
                (float)str_replace(',', '.', $_POST['labor_cost'] ?? '0'),
                (float)str_replace(',', '.', $_POST['subcontractor_cost'] ?? '0'),
                (float)str_replace(',', '.', $_POST['other_cost'] ?? '0'),
                (float)str_replace(',', '.', $_POST['client_price'] ?? '0'),
                strtoupper(substr(trim($_POST['currency'] ?? $company['currency']), 0, 3)) ?: $company['currency'],
                $userId,
            ]);
            $orderId = (int)$pdo->lastInsertId();
            cashflow_audit($pdo, $userId, $companyId, $pcId, 'create', 'work_order', $orderId);
            cashflow_flash_set('success', 'Lucrarea a fost înregistrată.');
        }
        header('Location: ' . cashflow_url('service_orders'));
        exit;
    }

    if ($action === 'settle_order') {
        $orderId = (int)($_POST['id'] ?? 0);
        $accountId = (int)($_POST['account_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT * FROM cf_work_orders WHERE id = ? AND company_id = ? LIMIT 1");
        $stmt->execute([$orderId, $companyId]);
        $order = $stmt->fetch();

        if ($order && !$order['income_transaction_id'] && !$order['expense_transaction_id']) {
            cashflow_require_profit_center_access($pdo, $userId, $companyId, (int)$order['profit_center_id'], 'read_write', $companyRoleArr);
            $acctCheck = $pdo->prepare("SELECT id FROM cf_accounts WHERE id = ? AND company_id = ? AND status = 'active'");
            $acctCheck->execute([$accountId, $companyId]);

            if ($acctCheck->fetchColumn()) {
                $incomeId = null;
                $expenseId = null;

                if ((float)$order['client_price'] > 0) {
                    $catId = cashflow_resolve_category($pdo, $companyId, 'income', 'Lucrări');
                    $incomeId = cashflow_create_transaction($pdo, [
                        'company_id' => $companyId, 'profit_center_id' => $order['profit_center_id'], 'account_id' => $accountId,
                        'user_id' => $userId, 'type' => 'income', 'category_id' => $catId, 'partner_id' => $order['partner_id'],
                        'amount' => $order['client_price'], 'currency' => $order['currency'], 'exchange_rate' => $order['exchange_rate'],
                        'transaction_date' => $order['date_done'] ?: $order['date_in'],
                        'description' => 'Lucrare ' . ($order['order_number'] ?: '#' . $orderId) . ($order['vehicle_plate'] ? ' - ' . $order['vehicle_plate'] : ''),
                    ]);
                }

                $totalCost = (float)$order['materials_cost'] + (float)$order['labor_cost'] + (float)$order['subcontractor_cost'] + (float)$order['other_cost'];
                if ($totalCost > 0) {
                    $expCatId = cashflow_resolve_category($pdo, $companyId, 'expense', 'Materiale/manoperă lucrare');
                    $expenseId = cashflow_create_transaction($pdo, [
                        'company_id' => $companyId, 'profit_center_id' => $order['profit_center_id'], 'account_id' => $accountId,
                        'user_id' => $userId, 'type' => 'expense', 'category_id' => $expCatId,
                        'amount' => $totalCost, 'currency' => $order['currency'], 'exchange_rate' => $order['exchange_rate'],
                        'transaction_date' => $order['date_done'] ?: $order['date_in'],
                        'description' => 'Materiale/manoperă/subcontractori lucrare ' . ($order['order_number'] ?: '#' . $orderId),
                    ]);
                }

                $upd = $pdo->prepare("UPDATE cf_work_orders SET status = 'settled', income_transaction_id = ?, expense_transaction_id = ? WHERE id = ?");
                $upd->execute([$incomeId, $expenseId, $orderId]);
                cashflow_audit($pdo, $userId, $companyId, (int)$order['profit_center_id'], 'settle', 'work_order', $orderId);
                cashflow_flash_set('success', 'Lucrarea a fost înregistrată în cashflow.');
            }
        }
        header('Location: ' . cashflow_url('service_orders'));
        exit;
    }
}

$orders = [];
if (!empty($filterPcIds)) {
    $placeholders = implode(',', array_fill(0, count($filterPcIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT o.*, pc.name AS pc_name, pc.color AS pc_color, pc.icon AS pc_icon, p.name AS partner_name
         FROM cf_work_orders o
         JOIN cf_profit_centers pc ON pc.id = o.profit_center_id
         LEFT JOIN cf_partners p ON p.id = o.partner_id
         WHERE o.company_id = ? AND o.profit_center_id IN ($placeholders)
         ORDER BY o.date_in DESC, o.id DESC LIMIT 100"
    );
    $stmt->execute(array_merge([$companyId], $filterPcIds));
    $orders = $stmt->fetchAll();
}

$defaultPcId = $activeProfitCenter && in_array($activeProfitCenter['access_level'], ['read_write', 'full'], true)
    ? (int)$activeProfitCenter['id']
    : ($writableCenters[0]['id'] ?? null);
?>

<div class="d-flex justify-content-between align-items-center mb-3">
  <h4 class="fw-bold mb-0"><i class="bi bi-wrench-adjustable-circle"></i> Lucrări (Service / Detailing / Colantări)</h4>
</div>

<?php if (!empty($writableCenters)): ?>
<div class="cf-card p-3 mb-4">
  <h6 class="fw-bold mb-3">Lucrare nouă</h6>
  <form method="post" action="<?= cashflow_url('service_orders') ?>" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
    <input type="hidden" name="do" value="save_order">

    <div class="col-md-3">
      <label class="form-label small fw-bold">Centru de profit</label>
      <select name="profit_center_id" class="form-select" required>
        <?php foreach ($writableCenters as $pc): ?>
          <option value="<?= (int)$pc['id'] ?>" <?= $defaultPcId === (int)$pc['id'] ? 'selected' : '' ?>><?= cashflow_e($pc['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-2"><label class="form-label small fw-bold">Nr. lucrare</label><input type="text" name="order_number" class="form-control"></div>
    <div class="col-md-3"><label class="form-label small fw-bold">Client</label><input type="text" name="partner" class="form-control"></div>
    <div class="col-md-2"><label class="form-label small fw-bold">Nr. înmatriculare</label><input type="text" name="vehicle_plate" class="form-control"></div>
    <div class="col-md-2">
      <label class="form-label small fw-bold">Categorie serviciu</label>
      <input type="text" name="service_category" class="form-control" list="serviceCatList">
      <datalist id="serviceCatList"><?php foreach ($serviceCategories as $sc): ?><option value="<?= cashflow_e($sc) ?>"><?php endforeach; ?></datalist>
    </div>

    <div class="col-md-2"><label class="form-label small fw-bold">Marcă</label><input type="text" name="vehicle_make" class="form-control"></div>
    <div class="col-md-2"><label class="form-label small fw-bold">Model</label><input type="text" name="vehicle_model" class="form-control"></div>
    <div class="col-md-2"><label class="form-label small fw-bold">VIN</label><input type="text" name="vehicle_vin" class="form-control"></div>
    <div class="col-md-2"><label class="form-label small fw-bold">Data intrării</label><input type="date" name="date_in" class="form-control" value="<?= date('Y-m-d') ?>" required></div>
    <div class="col-md-2"><label class="form-label small fw-bold">Data estimată</label><input type="date" name="date_estimated" class="form-control"></div>
    <div class="col-md-2"><label class="form-label small fw-bold">Data finalizării</label><input type="date" name="date_done" class="form-control" value="<?= date('Y-m-d') ?>"></div>

    <div class="col-md-2"><label class="form-label small fw-bold">Materiale</label><input type="text" name="materials_cost" class="form-control" value="0"></div>
    <div class="col-md-2"><label class="form-label small fw-bold">Manoperă</label><input type="text" name="labor_cost" class="form-control" value="0"></div>
    <div class="col-md-2"><label class="form-label small fw-bold">Subcontractori</label><input type="text" name="subcontractor_cost" class="form-control" value="0"></div>
    <div class="col-md-2"><label class="form-label small fw-bold">Alte costuri</label><input type="text" name="other_cost" class="form-control" value="0"></div>
    <div class="col-md-2"><label class="form-label small fw-bold">Preț client</label><input type="text" name="client_price" class="form-control" value="0"></div>
    <div class="col-md-2"><label class="form-label small fw-bold">Monedă</label><input type="text" name="currency" class="form-control" value="<?= cashflow_e($company['currency']) ?>" maxlength="3"></div>

    <div class="col-12"><button type="submit" class="btn btn-primary fw-bold">Salvează lucrarea</button></div>
  </form>
</div>
<?php endif; ?>

<div class="cf-card p-0 overflow-hidden">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 small">
      <thead class="table-light text-uppercase text-muted">
        <tr>
          <th class="ps-3">Data</th><th>Centru</th><th>Lucrare</th><th>Client / Vehicul</th>
          <th class="text-end">Preț client</th><th class="text-end">Costuri</th><th class="text-end">Profit</th><th>Status</th><th class="pe-3 text-end">Acțiuni</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($orders)): ?>
          <tr><td colspan="9" class="text-center py-5 text-muted">Nicio lucrare înregistrată.</td></tr>
        <?php endif; ?>
        <?php foreach ($orders as $o): $cost = (float)$o['materials_cost'] + (float)$o['labor_cost'] + (float)$o['subcontractor_cost'] + (float)$o['other_cost']; $profit = (float)$o['client_price'] - $cost; ?>
          <tr>
            <td class="ps-3"><?= date('d.m.Y', strtotime($o['date_in'])) ?></td>
            <td><i class="bi <?= cashflow_e($o['pc_icon']) ?>" style="color: <?= cashflow_e($o['pc_color']) ?>"></i> <?= cashflow_e($o['pc_name']) ?></td>
            <td><?= cashflow_e($o['order_number'] ?: '#' . $o['id']) ?><?= $o['service_category'] ? '<br><small class="text-muted">' . cashflow_e($o['service_category']) . '</small>' : '' ?></td>
            <td><?= cashflow_e($o['partner_name'] ?: '-') ?><?= $o['vehicle_plate'] ? '<br><small class="text-muted">' . cashflow_e($o['vehicle_plate']) . '</small>' : '' ?></td>
            <td class="text-end text-success"><?= cashflow_money((float)$o['client_price'], $o['currency']) ?></td>
            <td class="text-end text-danger"><?= cashflow_money($cost, $o['currency']) ?></td>
            <td class="text-end fw-bold <?= $profit >= 0 ? 'text-success' : 'text-danger' ?>"><?= cashflow_money($profit, $o['currency']) ?></td>
            <td>
              <?php if ($o['status'] === 'settled'): ?>
                <span class="badge bg-success-subtle text-success border border-success">În cashflow</span>
              <?php else: ?>
                <span class="badge bg-secondary-subtle text-secondary border">Neînregistrată</span>
              <?php endif; ?>
            </td>
            <td class="pe-3 text-end">
              <?php if ($o['status'] !== 'settled'): ?>
                <form method="post" action="<?= cashflow_url('service_orders') ?>" class="d-flex gap-1 justify-content-end">
                  <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
                  <input type="hidden" name="do" value="settle_order">
                  <input type="hidden" name="id" value="<?= (int)$o['id'] ?>">
                  <select name="account_id" class="form-select form-select-sm" style="width:auto;" required>
                    <?php foreach ($accounts as $acc): ?><option value="<?= (int)$acc['id'] ?>"><?= cashflow_e($acc['name']) ?></option><?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn btn-sm btn-outline-primary" title="Trece în cashflow"><i class="bi bi-arrow-down-circle"></i></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
