<?php
/**
 * @var PDO $pdo
 * @var array $company
 * @var bool $isCompanyAdmin
 */

if (!$isCompanyAdmin) {
    cashflow_forbidden('Doar administratorii firmei pot vedea jurnalul de audit.');
}

$companyId = (int)$company['id'];

$stmt = $pdo->prepare(
    "SELECT al.*, u.name AS user_name, pc.name AS pc_name
     FROM cf_audit_log al
     LEFT JOIN cf_users u ON u.id = al.user_id
     LEFT JOIN cf_profit_centers pc ON pc.id = al.profit_center_id
     WHERE al.company_id = ?
     ORDER BY al.id DESC
     LIMIT 200"
);
$stmt->execute([$companyId]);
$entries = $stmt->fetchAll();

$actionLabels = [
    'create' => 'creare', 'update' => 'modificare', 'cancel' => 'anulare',
    'status_change' => 'schimbare status', 'grant_company_access' => 'acordare acces firmă',
    'change_role' => 'schimbare rol', 'update_center_access' => 'actualizare acces centre',
    'login' => 'autentificare', 'logout' => 'deconectare',
];
?>

<h4 class="fw-bold mb-3"><i class="bi bi-journal-text"></i> Jurnal de audit</h4>

<div class="cf-card p-0 overflow-hidden">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 small">
      <thead class="table-light text-uppercase text-muted">
        <tr>
          <th class="ps-3">Data</th>
          <th>Utilizator</th>
          <th>Acțiune</th>
          <th>Entitate</th>
          <th>Centru</th>
          <th class="pe-3">Detalii</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($entries)): ?>
          <tr><td colspan="6" class="text-center py-5 text-muted">Nicio înregistrare.</td></tr>
        <?php endif; ?>
        <?php foreach ($entries as $e): ?>
          <tr>
            <td class="ps-3"><?= date('d.m.Y H:i', strtotime($e['created_at'])) ?></td>
            <td><?= cashflow_e($e['user_name'] ?: 'Sistem') ?></td>
            <td><?= cashflow_e($actionLabels[$e['action']] ?? $e['action']) ?></td>
            <td><?= cashflow_e($e['entity_type']) ?><?= $e['entity_id'] ? ' #' . (int)$e['entity_id'] : '' ?></td>
            <td><?= cashflow_e($e['pc_name'] ?: '-') ?></td>
            <td class="pe-3 text-muted"><?= $e['details'] ? cashflow_e(substr($e['details'], 0, 140)) : '' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
