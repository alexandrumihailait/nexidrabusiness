<?php
/**
 * Transport activity module: vehicles + drivers (company-scoped fleet) and
 * trips/curse (profit-center scoped, settles into the ledger).
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

$tab = in_array($_GET['tab'] ?? '', ['vehicles', 'drivers'], true) ? $_GET['tab'] : 'trips';

$accounts = [];
$acctStmt = $pdo->prepare("SELECT * FROM cf_accounts WHERE company_id = ? AND status = 'active' ORDER BY name ASC");
$acctStmt->execute([$companyId]);
$accounts = $acctStmt->fetchAll();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cashflow_csrf_check();
    $action = $_POST['do'] ?? '';

    if ($action === 'save_vehicle' && $isCompanyAdmin) {
        $ins = $pdo->prepare(
            "INSERT INTO cf_vehicles (company_id, type, plate_number, make, model, year, mileage_km, fuel_consumption_per_100km, rca_expiry, casco_expiry, itp_expiry, leasing_monthly, status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')"
        );
        $ins->execute([
            $companyId,
            in_array($_POST['type'] ?? '', ['truck', 'trailer', 'car', 'other'], true) ? $_POST['type'] : 'truck',
            trim($_POST['plate_number'] ?? ''),
            trim($_POST['make'] ?? '') ?: null,
            trim($_POST['model'] ?? '') ?: null,
            $_POST['year'] !== '' ? (int)($_POST['year'] ?? 0) : null,
            (int)($_POST['mileage_km'] ?? 0),
            $_POST['fuel_consumption_per_100km'] !== '' ? (float)str_replace(',', '.', $_POST['fuel_consumption_per_100km']) : null,
            $_POST['rca_expiry'] ?: null,
            $_POST['casco_expiry'] ?: null,
            $_POST['itp_expiry'] ?: null,
            $_POST['leasing_monthly'] !== '' ? (float)str_replace(',', '.', $_POST['leasing_monthly']) : null,
        ]);
        cashflow_audit($pdo, $userId, $companyId, null, 'create', 'vehicle', (int)$pdo->lastInsertId());
        cashflow_flash_set('success', 'Vehiculul a fost adăugat.');
        header('Location: ' . cashflow_url('transport', ['tab' => 'vehicles']));
        exit;
    }

    if ($action === 'toggle_vehicle' && $isCompanyAdmin) {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT status FROM cf_vehicles WHERE id = ? AND company_id = ?");
        $stmt->execute([$id, $companyId]);
        if ($cur = $stmt->fetchColumn()) {
            $pdo->prepare("UPDATE cf_vehicles SET status = ? WHERE id = ? AND company_id = ?")
                ->execute([$cur === 'active' ? 'inactive' : 'active', $id, $companyId]);
        }
        header('Location: ' . cashflow_url('transport', ['tab' => 'vehicles']));
        exit;
    }

    if ($action === 'save_driver' && $isCompanyAdmin) {
        $name = trim($_POST['name'] ?? '');
        if ($name !== '') {
            $ins = $pdo->prepare(
                "INSERT INTO cf_drivers (company_id, name, phone, license_number, base_salary, status) VALUES (?, ?, ?, ?, ?, 'active')"
            );
            $ins->execute([
                $companyId, $name,
                trim($_POST['phone'] ?? '') ?: null,
                trim($_POST['license_number'] ?? '') ?: null,
                $_POST['base_salary'] !== '' ? (float)str_replace(',', '.', $_POST['base_salary']) : null,
            ]);
            cashflow_audit($pdo, $userId, $companyId, null, 'create', 'driver', (int)$pdo->lastInsertId());
            cashflow_flash_set('success', 'Șoferul a fost adăugat.');
        }
        header('Location: ' . cashflow_url('transport', ['tab' => 'drivers']));
        exit;
    }

    if ($action === 'toggle_driver' && $isCompanyAdmin) {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT status FROM cf_drivers WHERE id = ? AND company_id = ?");
        $stmt->execute([$id, $companyId]);
        if ($cur = $stmt->fetchColumn()) {
            $pdo->prepare("UPDATE cf_drivers SET status = ? WHERE id = ? AND company_id = ?")
                ->execute([$cur === 'active' ? 'inactive' : 'active', $id, $companyId]);
        }
        header('Location: ' . cashflow_url('transport', ['tab' => 'drivers']));
        exit;
    }

    if ($action === 'save_trip') {
        $pcId = (int)($_POST['profit_center_id'] ?? 0);
        cashflow_require_profit_center_access($pdo, $userId, $companyId, $pcId, 'read_write', $companyRoleArr);

        $tariff = (float)str_replace(',', '.', $_POST['tariff'] ?? '0');
        $currency = strtoupper(substr(trim($_POST['currency'] ?? $company['currency']), 0, 3)) ?: $company['currency'];
        $exchangeRate = $currency === $company['currency'] ? 1.0 : (float)str_replace(',', '.', $_POST['exchange_rate'] ?? '1');
        $tripDate = $_POST['trip_date'] ?? date('Y-m-d');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $tripDate) || $exchangeRate <= 0) {
            cashflow_flash_set('danger', 'Date invalide pentru cursă.');
        } else {
            $partnerId = cashflow_resolve_partner($pdo, $companyId, trim($_POST['partner'] ?? ''));

            $ins = $pdo->prepare(
                "INSERT INTO cf_trips (company_id, profit_center_id, trip_number, partner_id, vehicle_id, trailer_id, driver_id, origin, destination, km, tariff, currency, exchange_rate, fuel_cost, road_taxes, other_costs, trip_date, status, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'completed', ?)"
            );
            $ins->execute([
                $companyId, $pcId, trim($_POST['trip_number'] ?? '') ?: null, $partnerId,
                (int)($_POST['vehicle_id'] ?? 0) ?: null, (int)($_POST['trailer_id'] ?? 0) ?: null, (int)($_POST['driver_id'] ?? 0) ?: null,
                trim($_POST['origin'] ?? '') ?: null, trim($_POST['destination'] ?? '') ?: null,
                $_POST['km'] !== '' ? (int)($_POST['km'] ?? 0) : null,
                $tariff, $currency, $exchangeRate,
                (float)str_replace(',', '.', $_POST['fuel_cost'] ?? '0'),
                (float)str_replace(',', '.', $_POST['road_taxes'] ?? '0'),
                (float)str_replace(',', '.', $_POST['other_costs'] ?? '0'),
                $tripDate, $userId,
            ]);
            $tripId = (int)$pdo->lastInsertId();
            cashflow_audit($pdo, $userId, $companyId, $pcId, 'create', 'trip', $tripId);
            cashflow_flash_set('success', 'Cursa a fost înregistrată.');
        }
        header('Location: ' . cashflow_url('transport'));
        exit;
    }

    if ($action === 'settle_trip') {
        $tripId = (int)($_POST['id'] ?? 0);
        $accountId = (int)($_POST['account_id'] ?? 0);

        $stmt = $pdo->prepare("SELECT * FROM cf_trips WHERE id = ? AND company_id = ? LIMIT 1");
        $stmt->execute([$tripId, $companyId]);
        $trip = $stmt->fetch();

        if ($trip && !$trip['income_transaction_id'] && !$trip['expense_transaction_id']) {
            cashflow_require_profit_center_access($pdo, $userId, $companyId, (int)$trip['profit_center_id'], 'read_write', $companyRoleArr);
            $acctCheck = $pdo->prepare("SELECT id FROM cf_accounts WHERE id = ? AND company_id = ? AND status = 'active'");
            $acctCheck->execute([$accountId, $companyId]);

            if ($acctCheck->fetchColumn()) {
                $incomeId = null;
                $expenseId = null;
                $categoryId = cashflow_resolve_category($pdo, $companyId, 'income', 'Curse');

                if ((float)$trip['tariff'] > 0) {
                    $incomeId = cashflow_create_transaction($pdo, [
                        'company_id' => $companyId, 'profit_center_id' => $trip['profit_center_id'], 'account_id' => $accountId,
                        'user_id' => $userId, 'type' => 'income', 'category_id' => $categoryId, 'partner_id' => $trip['partner_id'],
                        'amount' => $trip['tariff'], 'currency' => $trip['currency'], 'exchange_rate' => $trip['exchange_rate'],
                        'transaction_date' => $trip['trip_date'], 'description' => 'Cursă ' . ($trip['trip_number'] ?: '#' . $tripId),
                    ]);
                }

                $totalCost = (float)$trip['fuel_cost'] + (float)$trip['road_taxes'] + (float)$trip['other_costs'];
                if ($totalCost > 0) {
                    $expCategoryId = cashflow_resolve_category($pdo, $companyId, 'expense', 'Costuri cursă');
                    $expenseId = cashflow_create_transaction($pdo, [
                        'company_id' => $companyId, 'profit_center_id' => $trip['profit_center_id'], 'account_id' => $accountId,
                        'user_id' => $userId, 'type' => 'expense', 'category_id' => $expCategoryId,
                        'amount' => $totalCost, 'currency' => $trip['currency'], 'exchange_rate' => $trip['exchange_rate'],
                        'transaction_date' => $trip['trip_date'], 'description' => 'Combustibil/taxe/alte costuri cursă ' . ($trip['trip_number'] ?: '#' . $tripId),
                    ]);
                }

                $upd = $pdo->prepare("UPDATE cf_trips SET status = 'settled', income_transaction_id = ?, expense_transaction_id = ? WHERE id = ?");
                $upd->execute([$incomeId, $expenseId, $tripId]);
                cashflow_audit($pdo, $userId, $companyId, (int)$trip['profit_center_id'], 'settle', 'trip', $tripId);
                cashflow_flash_set('success', 'Cursa a fost înregistrată în cashflow.');
            }
        }
        header('Location: ' . cashflow_url('transport'));
        exit;
    }
}

$vehicles = [];
$drivers = [];
$vStmt = $pdo->prepare("SELECT * FROM cf_vehicles WHERE company_id = ? ORDER BY status = 'active' DESC, plate_number ASC");
$vStmt->execute([$companyId]);
$vehicles = $vStmt->fetchAll();

$dStmt = $pdo->prepare("SELECT * FROM cf_drivers WHERE company_id = ? ORDER BY status = 'active' DESC, name ASC");
$dStmt->execute([$companyId]);
$drivers = $dStmt->fetchAll();

$activeVehicles = array_values(array_filter($vehicles, fn ($v) => $v['status'] === 'active'));
$activeDrivers = array_values(array_filter($drivers, fn ($d) => $d['status'] === 'active'));

$trips = [];
if ($tab === 'trips' && !empty($filterPcIds)) {
    $placeholders = implode(',', array_fill(0, count($filterPcIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT t.*, pc.name AS pc_name, pc.color AS pc_color, pc.icon AS pc_icon,
                p.name AS partner_name, v.plate_number AS vehicle_plate, d.name AS driver_name
         FROM cf_trips t
         JOIN cf_profit_centers pc ON pc.id = t.profit_center_id
         LEFT JOIN cf_partners p ON p.id = t.partner_id
         LEFT JOIN cf_vehicles v ON v.id = t.vehicle_id
         LEFT JOIN cf_drivers d ON d.id = t.driver_id
         WHERE t.company_id = ? AND t.profit_center_id IN ($placeholders)
         ORDER BY t.trip_date DESC, t.id DESC LIMIT 100"
    );
    $stmt->execute(array_merge([$companyId], $filterPcIds));
    $trips = $stmt->fetchAll();
}

$defaultPcId = $activeProfitCenter && in_array($activeProfitCenter['access_level'], ['read_write', 'full'], true)
    ? (int)$activeProfitCenter['id']
    : ($writableCenters[0]['id'] ?? null);
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <h4 class="fw-bold mb-0"><i class="bi bi-truck"></i> Transport</h4>
  <ul class="nav nav-pills small">
    <li class="nav-item"><a class="nav-link <?= $tab === 'trips' ? 'active' : '' ?>" href="<?= cashflow_url('transport', ['tab' => 'trips']) ?>">Curse</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'vehicles' ? 'active' : '' ?>" href="<?= cashflow_url('transport', ['tab' => 'vehicles']) ?>">Vehicule</a></li>
    <li class="nav-item"><a class="nav-link <?= $tab === 'drivers' ? 'active' : '' ?>" href="<?= cashflow_url('transport', ['tab' => 'drivers']) ?>">Șoferi</a></li>
  </ul>
</div>

<?php if ($tab === 'trips'): ?>

  <?php if (!empty($writableCenters)): ?>
  <div class="cf-card p-3 mb-4">
    <h6 class="fw-bold mb-3">Cursă nouă</h6>
    <form method="post" action="<?= cashflow_url('transport') ?>" class="row g-3">
      <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
      <input type="hidden" name="do" value="save_trip">

      <div class="col-md-3">
        <label class="form-label small fw-bold">Centru de profit</label>
        <select name="profit_center_id" class="form-select" required>
          <?php foreach ($writableCenters as $pc): ?>
            <option value="<?= (int)$pc['id'] ?>" <?= $defaultPcId === (int)$pc['id'] ? 'selected' : '' ?>><?= cashflow_e($pc['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small fw-bold">Nr. cursă</label>
        <input type="text" name="trip_number" class="form-control">
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-bold">Client</label>
        <input type="text" name="partner" class="form-control" placeholder="Nume client">
      </div>
      <div class="col-md-2">
        <label class="form-label small fw-bold">Origine</label>
        <input type="text" name="origin" class="form-control">
      </div>
      <div class="col-md-2">
        <label class="form-label small fw-bold">Destinație</label>
        <input type="text" name="destination" class="form-control">
      </div>

      <div class="col-md-2">
        <label class="form-label small fw-bold">Camion</label>
        <select name="vehicle_id" class="form-select">
          <option value="">-</option>
          <?php foreach ($activeVehicles as $v): if ($v['type'] === 'trailer') continue; ?>
            <option value="<?= (int)$v['id'] ?>"><?= cashflow_e($v['plate_number']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small fw-bold">Remorcă</label>
        <select name="trailer_id" class="form-select">
          <option value="">-</option>
          <?php foreach ($activeVehicles as $v): if ($v['type'] !== 'trailer') continue; ?>
            <option value="<?= (int)$v['id'] ?>"><?= cashflow_e($v['plate_number']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small fw-bold">Șofer</label>
        <select name="driver_id" class="form-select">
          <option value="">-</option>
          <?php foreach ($activeDrivers as $d): ?>
            <option value="<?= (int)$d['id'] ?>"><?= cashflow_e($d['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-1">
        <label class="form-label small fw-bold">Km</label>
        <input type="number" name="km" class="form-control">
      </div>
      <div class="col-md-2">
        <label class="form-label small fw-bold">Data</label>
        <input type="date" name="trip_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-bold">Tarif (venit)</label>
        <input type="text" name="tariff" class="form-control" placeholder="0.00">
      </div>
      <div class="col-md-2">
        <label class="form-label small fw-bold">Monedă</label>
        <input type="text" name="currency" class="form-control" value="<?= cashflow_e($company['currency']) ?>" maxlength="3">
      </div>
      <div class="col-md-2">
        <label class="form-label small fw-bold">Curs</label>
        <input type="text" name="exchange_rate" class="form-control" value="1">
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-bold">Combustibil</label>
        <input type="text" name="fuel_cost" class="form-control" value="0">
      </div>
      <div class="col-md-2">
        <label class="form-label small fw-bold">Taxe drum</label>
        <input type="text" name="road_taxes" class="form-control" value="0">
      </div>
      <div class="col-md-3">
        <label class="form-label small fw-bold">Alte costuri</label>
        <input type="text" name="other_costs" class="form-control" value="0">
      </div>

      <div class="col-12">
        <button type="submit" class="btn btn-primary fw-bold">Salvează cursa</button>
      </div>
    </form>
  </div>
  <?php endif; ?>

  <div class="cf-card p-0 overflow-hidden">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 small">
        <thead class="table-light text-uppercase text-muted">
          <tr>
            <th class="ps-3">Data</th>
            <th>Centru</th>
            <th>Traseu</th>
            <th>Client</th>
            <th>Vehicul / Șofer</th>
            <th class="text-end">Venit</th>
            <th class="text-end">Costuri</th>
            <th class="text-end">Profit</th>
            <th>Status</th>
            <th class="text-end pe-3">Acțiuni</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($trips)): ?>
            <tr><td colspan="10" class="text-center py-5 text-muted">Nicio cursă înregistrată.</td></tr>
          <?php endif; ?>
          <?php foreach ($trips as $t): $cost = (float)$t['fuel_cost'] + (float)$t['road_taxes'] + (float)$t['other_costs']; $profit = (float)$t['tariff'] - $cost; ?>
            <tr>
              <td class="ps-3"><?= date('d.m.Y', strtotime($t['trip_date'])) ?></td>
              <td><i class="bi <?= cashflow_e($t['pc_icon']) ?>" style="color: <?= cashflow_e($t['pc_color']) ?>"></i> <?= cashflow_e($t['pc_name']) ?></td>
              <td><?= cashflow_e($t['origin']) ?> → <?= cashflow_e($t['destination']) ?><?= $t['km'] ? ' (' . (int)$t['km'] . ' km)' : '' ?></td>
              <td><?= cashflow_e($t['partner_name'] ?: '-') ?></td>
              <td><?= cashflow_e($t['vehicle_plate'] ?: '-') ?><?= $t['driver_name'] ? ' · ' . cashflow_e($t['driver_name']) : '' ?></td>
              <td class="text-end text-success"><?= cashflow_money((float)$t['tariff'], $t['currency']) ?></td>
              <td class="text-end text-danger"><?= cashflow_money($cost, $t['currency']) ?></td>
              <td class="text-end fw-bold <?= $profit >= 0 ? 'text-success' : 'text-danger' ?>"><?= cashflow_money($profit, $t['currency']) ?></td>
              <td>
                <?php if ($t['status'] === 'settled'): ?>
                  <span class="badge bg-success-subtle text-success border border-success">În cashflow</span>
                <?php else: ?>
                  <span class="badge bg-secondary-subtle text-secondary border">Neînregistrată</span>
                <?php endif; ?>
              </td>
              <td class="text-end pe-3">
                <?php if ($t['status'] !== 'settled'): ?>
                  <form method="post" action="<?= cashflow_url('transport') ?>" class="d-flex gap-1 justify-content-end">
                    <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
                    <input type="hidden" name="do" value="settle_trip">
                    <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
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

<?php elseif ($tab === 'vehicles'): ?>

  <?php if ($isCompanyAdmin): ?>
  <div class="cf-card p-3 mb-4">
    <h6 class="fw-bold mb-3">Adaugă vehicul</h6>
    <form method="post" action="<?= cashflow_url('transport', ['tab' => 'vehicles']) ?>" class="row g-3">
      <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
      <input type="hidden" name="do" value="save_vehicle">
      <div class="col-md-2">
        <label class="form-label small fw-bold">Tip</label>
        <select name="type" class="form-select">
          <option value="truck">Camion</option>
          <option value="trailer">Remorcă</option>
          <option value="car">Autoturism</option>
          <option value="other">Altul</option>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label small fw-bold">Nr. înmatriculare</label>
        <input type="text" name="plate_number" class="form-control" required>
      </div>
      <div class="col-md-2"><label class="form-label small fw-bold">Marcă</label><input type="text" name="make" class="form-control"></div>
      <div class="col-md-2"><label class="form-label small fw-bold">Model</label><input type="text" name="model" class="form-control"></div>
      <div class="col-md-1"><label class="form-label small fw-bold">An</label><input type="number" name="year" class="form-control"></div>
      <div class="col-md-2"><label class="form-label small fw-bold">Km</label><input type="number" name="mileage_km" class="form-control" value="0"></div>
      <div class="col-md-2"><label class="form-label small fw-bold">Consum/100km</label><input type="text" name="fuel_consumption_per_100km" class="form-control"></div>
      <div class="col-md-2"><label class="form-label small fw-bold">Expirare RCA</label><input type="date" name="rca_expiry" class="form-control"></div>
      <div class="col-md-2"><label class="form-label small fw-bold">Expirare CASCO</label><input type="date" name="casco_expiry" class="form-control"></div>
      <div class="col-md-2"><label class="form-label small fw-bold">Expirare ITP</label><input type="date" name="itp_expiry" class="form-control"></div>
      <div class="col-md-2"><label class="form-label small fw-bold">Leasing/lună</label><input type="text" name="leasing_monthly" class="form-control"></div>
      <div class="col-12"><button type="submit" class="btn btn-primary fw-bold">Adaugă vehiculul</button></div>
    </form>
  </div>
  <?php endif; ?>

  <div class="cf-card p-0 overflow-hidden">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 small">
        <thead class="table-light text-uppercase text-muted">
          <tr><th class="ps-3">Vehicul</th><th>Tip</th><th>Km</th><th>RCA</th><th>CASCO</th><th>ITP</th><th>Leasing</th><th>Status</th><?php if ($isCompanyAdmin): ?><th class="pe-3 text-end">Acțiuni</th><?php endif; ?></tr>
        </thead>
        <tbody>
          <?php foreach ($vehicles as $v): ?>
            <tr>
              <td class="ps-3 fw-bold"><?= cashflow_e($v['plate_number']) ?><br><small class="text-muted fw-normal"><?= cashflow_e($v['make'] . ' ' . $v['model']) ?></small></td>
              <td class="text-capitalize"><?= cashflow_e($v['type']) ?></td>
              <td><?= number_format((float)$v['mileage_km'], 0, ',', '.') ?> km</td>
              <td><?= $v['rca_expiry'] ? date('d.m.Y', strtotime($v['rca_expiry'])) : '-' ?></td>
              <td><?= $v['casco_expiry'] ? date('d.m.Y', strtotime($v['casco_expiry'])) : '-' ?></td>
              <td><?= $v['itp_expiry'] ? date('d.m.Y', strtotime($v['itp_expiry'])) : '-' ?></td>
              <td><?= $v['leasing_monthly'] ? cashflow_money((float)$v['leasing_monthly']) : '-' ?></td>
              <td><?= $v['status'] === 'active' ? '<span class="badge bg-success-subtle text-success border border-success">Activ</span>' : '<span class="badge bg-secondary-subtle text-secondary border">Inactiv</span>' ?></td>
              <?php if ($isCompanyAdmin): ?>
              <td class="pe-3 text-end">
                <form method="post" action="<?= cashflow_url('transport', ['tab' => 'vehicles']) ?>">
                  <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
                  <input type="hidden" name="do" value="toggle_vehicle">
                  <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-<?= $v['status'] === 'active' ? 'danger' : 'success' ?>"><i class="bi bi-<?= $v['status'] === 'active' ? 'pause' : 'play' ?>"></i></button>
                </form>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php else: ?>

  <?php if ($isCompanyAdmin): ?>
  <div class="cf-card p-3 mb-4">
    <h6 class="fw-bold mb-3">Adaugă șofer</h6>
    <form method="post" action="<?= cashflow_url('transport', ['tab' => 'drivers']) ?>" class="row g-3">
      <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
      <input type="hidden" name="do" value="save_driver">
      <div class="col-md-3"><label class="form-label small fw-bold">Nume</label><input type="text" name="name" class="form-control" required></div>
      <div class="col-md-3"><label class="form-label small fw-bold">Telefon</label><input type="text" name="phone" class="form-control"></div>
      <div class="col-md-3"><label class="form-label small fw-bold">Permis</label><input type="text" name="license_number" class="form-control"></div>
      <div class="col-md-3"><label class="form-label small fw-bold">Salariu de bază</label><input type="text" name="base_salary" class="form-control"></div>
      <div class="col-12"><button type="submit" class="btn btn-primary fw-bold">Adaugă șoferul</button></div>
    </form>
  </div>
  <?php endif; ?>

  <div class="cf-card p-0 overflow-hidden">
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0 small">
        <thead class="table-light text-uppercase text-muted">
          <tr><th class="ps-3">Nume</th><th>Telefon</th><th>Permis</th><th>Salariu bază</th><th>Status</th><?php if ($isCompanyAdmin): ?><th class="pe-3 text-end">Acțiuni</th><?php endif; ?></tr>
        </thead>
        <tbody>
          <?php foreach ($drivers as $d): ?>
            <tr>
              <td class="ps-3 fw-bold"><?= cashflow_e($d['name']) ?></td>
              <td><?= cashflow_e($d['phone']) ?></td>
              <td><?= cashflow_e($d['license_number']) ?></td>
              <td><?= $d['base_salary'] ? cashflow_money((float)$d['base_salary']) : '-' ?></td>
              <td><?= $d['status'] === 'active' ? '<span class="badge bg-success-subtle text-success border border-success">Activ</span>' : '<span class="badge bg-secondary-subtle text-secondary border">Inactiv</span>' ?></td>
              <?php if ($isCompanyAdmin): ?>
              <td class="pe-3 text-end">
                <form method="post" action="<?= cashflow_url('transport', ['tab' => 'drivers']) ?>">
                  <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
                  <input type="hidden" name="do" value="toggle_driver">
                  <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-<?= $d['status'] === 'active' ? 'danger' : 'success' ?>"><i class="bi bi-<?= $d['status'] === 'active' ? 'pause' : 'play' ?>"></i></button>
                </form>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

<?php endif; ?>
