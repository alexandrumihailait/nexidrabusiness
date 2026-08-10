<?php
/** @var PDO $pdo */

$companyFilter = isset($_GET['cid']) ? (int)$_GET['cid'] : 0;

$sql = "SELECT al.*, u.name AS user_name, c.name AS company_name
        FROM cf_audit_log al
        LEFT JOIN cf_users u ON u.id = al.user_id
        LEFT JOIN cf_companies c ON c.id = al.company_id";
$params = [];
if ($companyFilter > 0) {
    $sql .= " WHERE al.company_id = ?";
    $params[] = $companyFilter;
}
$sql .= " ORDER BY al.id DESC LIMIT 300";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$entries = $stmt->fetchAll();

$companies = $pdo->query("SELECT id, name FROM cf_companies ORDER BY name ASC")->fetchAll();
?>

<h4 class="fw-bold mb-3"><i class="bi bi-journal-text"></i> Audit platformă</h4>

<form method="get" action="admin.php" class="mb-3">
  <input type="hidden" name="p" value="audit">
  <div class="input-group" style="max-width: 360px;">
    <select name="cid" class="form-select" onchange="this.form.submit()">
      <option value="0">Toate firmele</option>
      <?php foreach ($companies as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $companyFilter === (int)$c['id'] ? 'selected' : '' ?>><?= cashflow_e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
  </div>
</form>

<div class="cf-card p-0 overflow-hidden">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 small">
      <thead class="table-light text-uppercase text-muted">
        <tr><th class="ps-3">Data</th><th>Utilizator</th><th>Firmă</th><th>Acțiune</th><th>Entitate</th><th class="pe-3">Detalii</th></tr>
      </thead>
      <tbody>
        <?php if (empty($entries)): ?><tr><td colspan="6" class="text-center py-5 text-muted">Nicio înregistrare.</td></tr><?php endif; ?>
        <?php foreach ($entries as $e): ?>
          <tr>
            <td class="ps-3"><?= date('d.m.Y H:i', strtotime($e['created_at'])) ?></td>
            <td><?= cashflow_e($e['user_name'] ?: 'Sistem') ?></td>
            <td><?= cashflow_e($e['company_name'] ?: '-') ?></td>
            <td><?= cashflow_e($e['action']) ?></td>
            <td><?= cashflow_e($e['entity_type']) ?><?= $e['entity_id'] ? ' #' . (int)$e['entity_id'] : '' ?></td>
            <td class="pe-3 text-muted"><?= $e['details'] ? cashflow_e(substr($e['details'], 0, 140)) : '' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
