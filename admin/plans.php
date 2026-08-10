<?php
/** @var PDO $pdo */

$availableFeatures = [
    'excel_export' => 'Export Excel/CSV',
    'anaf_lookup' => 'Interogare firme ANAF (CUI)',
    'anaf_efactura' => 'ANAF e-Factura (upload documente)',
    'smartbill' => 'Integrare SmartBill',
    'google_drive' => 'Integrare Google Drive',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cashflow_csrf_check();
    $action = $_POST['do'] ?? '';

    if ($action === 'save') {
        $id = (int)($_POST['id'] ?? 0);
        $code = strtolower(preg_replace('/[^a-z0-9_]+/i', '_', trim($_POST['code'] ?? '')));
        $name = trim($_POST['name'] ?? '');
        $price = (float)str_replace(',', '.', $_POST['price_month_ron'] ?? '0');
        $maxDocs = (int)($_POST['max_documents_month'] ?? 0);
        $maxLookups = (int)($_POST['max_anaf_lookups_month'] ?? 0);
        $maxUsers = (int)($_POST['max_users'] ?? 0);
        $maxCenters = (int)($_POST['max_profit_centers'] ?? 0);
        $features = implode(',', array_keys(array_intersect_key($availableFeatures, array_flip($_POST['features'] ?? []))));

        if ($name === '' || $code === '') {
            cashflow_flash_set('danger', 'Numele și codul planului sunt obligatorii.');
        } elseif ($id > 0) {
            $pdo->prepare(
                "UPDATE cf_subscription_plans SET name=?, price_month_ron=?, max_documents_month=?, max_anaf_lookups_month=?, max_users=?, max_profit_centers=?, features=? WHERE id=?"
            )->execute([$name, $price, $maxDocs, $maxLookups, $maxUsers, $maxCenters, $features, $id]);
            cashflow_audit($pdo, $userId, null, null, 'update', 'subscription_plan', $id);
            cashflow_flash_set('success', 'Planul a fost actualizat.');
        } else {
            $pdo->prepare(
                "INSERT INTO cf_subscription_plans (code, name, price_month_ron, max_documents_month, max_anaf_lookups_month, max_users, max_profit_centers, features, status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'active')"
            )->execute([$code, $name, $price, $maxDocs, $maxLookups, $maxUsers, $maxCenters, $features]);
            $newId = (int)$pdo->lastInsertId();
            cashflow_audit($pdo, $userId, null, null, 'create', 'subscription_plan', $newId);
            cashflow_flash_set('success', 'Planul a fost creat.');
        }
    }

    if ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT status FROM cf_subscription_plans WHERE id = ?");
        $stmt->execute([$id]);
        if ($cur = $stmt->fetchColumn()) {
            $pdo->prepare("UPDATE cf_subscription_plans SET status = ? WHERE id = ?")
                ->execute([$cur === 'active' ? 'inactive' : 'active', $id]);
        }
    }

    header('Location: admin.php?p=plans');
    exit;
}

$plans = $pdo->query(
    "SELECT p.*, (SELECT COUNT(*) FROM cf_company_subscriptions cs WHERE cs.plan_id = p.id AND cs.status = 'active') AS company_count
     FROM cf_subscription_plans p ORDER BY p.price_month_ron ASC"
)->fetchAll();

$editId = isset($_GET['edit']) ? (int)$_GET['edit'] : null;
$editPlan = null;
foreach ($plans as $p) {
    if ((int)$p['id'] === $editId) { $editPlan = $p; break; }
}
$editFeatures = $editPlan ? array_filter(array_map('trim', explode(',', $editPlan['features']))) : [];
?>

<h4 class="fw-bold mb-3"><i class="bi bi-credit-card"></i> Abonamente</h4>

<div class="cf-card p-3 mb-4">
  <h6 class="fw-bold mb-3"><?= $editPlan ? 'Editează planul' : 'Plan nou' ?></h6>
  <form method="post" action="admin.php?p=plans" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
    <input type="hidden" name="do" value="save">
    <input type="hidden" name="id" value="<?= $editPlan ? (int)$editPlan['id'] : 0 ?>">

    <div class="col-md-3"><label class="form-label small fw-bold">Nume</label><input type="text" name="name" class="form-control" required value="<?= cashflow_e($editPlan['name'] ?? '') ?>"></div>
    <div class="col-md-2"><label class="form-label small fw-bold">Cod (unic)</label><input type="text" name="code" class="form-control" required value="<?= cashflow_e($editPlan['code'] ?? '') ?>" <?= $editPlan ? 'readonly' : '' ?>></div>
    <div class="col-md-2"><label class="form-label small fw-bold">Preț/lună (RON)</label><input type="text" name="price_month_ron" class="form-control" value="<?= $editPlan['price_month_ron'] ?? '0' ?>"></div>
    <div class="col-md-2"><label class="form-label small fw-bold">Max useri</label><input type="number" name="max_users" class="form-control" value="<?= $editPlan['max_users'] ?? '5' ?>"></div>
    <div class="col-md-3"><label class="form-label small fw-bold">Max centre de profit</label><input type="number" name="max_profit_centers" class="form-control" value="<?= $editPlan['max_profit_centers'] ?? '5' ?>"></div>

    <div class="col-md-3"><label class="form-label small fw-bold">Max documente/lună</label><input type="number" name="max_documents_month" class="form-control" value="<?= $editPlan['max_documents_month'] ?? '20' ?>"></div>
    <div class="col-md-3"><label class="form-label small fw-bold">Max interogări ANAF/lună</label><input type="number" name="max_anaf_lookups_month" class="form-control" value="<?= $editPlan['max_anaf_lookups_month'] ?? '50' ?>"></div>

    <div class="col-12">
      <label class="form-label small fw-bold d-block">Funcționalități incluse</label>
      <?php foreach ($availableFeatures as $code => $label): ?>
        <div class="form-check form-check-inline">
          <input type="checkbox" name="features[]" value="<?= cashflow_e($code) ?>" class="form-check-input" id="feat_<?= cashflow_e($code) ?>" <?= in_array($code, $editFeatures, true) ? 'checked' : '' ?>>
          <label class="form-check-label small" for="feat_<?= cashflow_e($code) ?>"><?= cashflow_e($label) ?></label>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="col-12">
      <button type="submit" class="btn btn-primary fw-bold"><?= $editPlan ? 'Salvează modificările' : 'Creează planul' ?></button>
      <?php if ($editPlan): ?><a href="admin.php?p=plans" class="btn btn-outline-secondary">Renunță</a><?php endif; ?>
    </div>
  </form>
</div>

<div class="cf-card p-0 overflow-hidden">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 small">
      <thead class="table-light text-uppercase text-muted">
        <tr><th class="ps-3">Plan</th><th>Preț</th><th>Limite</th><th>Funcționalități</th><th>Firme</th><th>Status</th><th class="pe-3 text-end">Acțiuni</th></tr>
      </thead>
      <tbody>
        <?php foreach ($plans as $p): $feats = array_filter(array_map('trim', explode(',', $p['features']))); ?>
          <tr>
            <td class="ps-3 fw-bold"><?= cashflow_e($p['name']) ?><br><small class="text-muted fw-normal"><?= cashflow_e($p['code']) ?></small></td>
            <td><?= cashflow_money((float)$p['price_month_ron']) ?>/lună</td>
            <td class="small"><?= (int)$p['max_documents_month'] ?> doc · <?= (int)$p['max_anaf_lookups_month'] ?> ANAF · <?= (int)$p['max_users'] ?> useri</td>
            <td>
              <?php foreach ($feats as $f): ?><span class="badge bg-light text-dark border me-1"><?= cashflow_e($availableFeatures[$f] ?? $f) ?></span><?php endforeach; ?>
            </td>
            <td><?= (int)$p['company_count'] ?></td>
            <td><?= $p['status'] === 'active' ? '<span class="badge bg-success-subtle text-success border border-success">Activ</span>' : '<span class="badge bg-secondary-subtle text-secondary border">Inactiv</span>' ?></td>
            <td class="pe-3 text-end">
              <a href="admin.php?p=plans&edit=<?= (int)$p['id'] ?>" class="btn btn-sm btn-outline-secondary"><i class="bi bi-pencil"></i></a>
              <form method="post" action="admin.php?p=plans" class="d-inline">
                <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
                <input type="hidden" name="do" value="toggle_status">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-<?= $p['status'] === 'active' ? 'danger' : 'success' ?>"><i class="bi bi-<?= $p['status'] === 'active' ? 'pause' : 'play' ?>"></i></button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
