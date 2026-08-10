<?php
/** @var PDO $pdo */

$stats = [
    'companies' => (int)$pdo->query("SELECT COUNT(*) FROM cf_companies WHERE status = 'active'")->fetchColumn(),
    'users' => (int)$pdo->query("SELECT COUNT(*) FROM cf_users WHERE status = 'active'")->fetchColumn(),
    'transactions' => (int)$pdo->query("SELECT COUNT(*) FROM cf_transactions WHERE deleted_at IS NULL")->fetchColumn(),
    'documents' => (int)$pdo->query("SELECT COUNT(*) FROM cf_documents")->fetchColumn(),
];

$byPlan = $pdo->query(
    "SELECT p.name, COUNT(cs.id) AS companies
     FROM cf_subscription_plans p
     LEFT JOIN cf_company_subscriptions cs ON cs.plan_id = p.id AND cs.status = 'active'
     GROUP BY p.id, p.name ORDER BY p.price_month_ron ASC"
)->fetchAll();

$period = cashflow_usage_period();
$topUsage = $pdo->prepare(
    "SELECT c.name, uc.metric, uc.counter
     FROM cf_usage_counters uc
     JOIN cf_companies c ON c.id = uc.company_id
     WHERE uc.period_ym = ?
     ORDER BY uc.counter DESC LIMIT 10"
);
$topUsage->execute([$period]);
$topUsageRows = $topUsage->fetchAll();

$recentCompanies = $pdo->query("SELECT * FROM cf_companies ORDER BY created_at DESC LIMIT 6")->fetchAll();
?>

<h4 class="fw-bold mb-3"><i class="bi bi-speedometer2"></i> Dashboard platformă</h4>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3"><div class="cf-kpi cf-kpi-cash"><div class="cf-kpi-label">Firme active</div><div class="cf-kpi-value"><?= $stats['companies'] ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="cf-kpi cf-kpi-in"><div class="cf-kpi-label">Utilizatori activi</div><div class="cf-kpi-value"><?= $stats['users'] ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="cf-kpi cf-kpi-net"><div class="cf-kpi-label">Tranzacții totale</div><div class="cf-kpi-value"><?= number_format($stats['transactions'], 0, ',', '.') ?></div></div></div>
  <div class="col-6 col-lg-3"><div class="cf-kpi cf-kpi-out"><div class="cf-kpi-label">Documente încărcate</div><div class="cf-kpi-value"><?= $stats['documents'] ?></div></div></div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-5">
    <div class="cf-card p-3 h-100">
      <h6 class="fw-bold mb-3">Firme pe abonament</h6>
      <?php foreach ($byPlan as $row): ?>
        <div class="d-flex justify-content-between small mb-1"><span><?= cashflow_e($row['name']) ?></span><span class="fw-bold"><?= (int)$row['companies'] ?></span></div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="cf-card p-3 h-100">
      <h6 class="fw-bold mb-3">Utilizare lună curentă (<?= cashflow_e($period) ?>)</h6>
      <?php if (empty($topUsageRows)): ?>
        <p class="text-muted small mb-0">Nicio utilizare înregistrată luna aceasta.</p>
      <?php endif; ?>
      <div class="table-responsive">
        <table class="table table-sm mb-0">
          <?php foreach ($topUsageRows as $row): ?>
            <tr><td><?= cashflow_e($row['name']) ?></td><td class="text-muted small"><?= cashflow_e($row['metric']) ?></td><td class="text-end fw-bold"><?= (int)$row['counter'] ?></td></tr>
          <?php endforeach; ?>
        </table>
      </div>
    </div>
  </div>
</div>

<h6 class="fw-bold mb-2">Firme recente</h6>
<div class="cf-card p-0 overflow-hidden">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 small">
      <thead class="table-light text-uppercase text-muted"><tr><th class="ps-3">Firmă</th><th>CUI</th><th>Creată</th><th class="pe-3 text-end">Status</th></tr></thead>
      <tbody>
        <?php foreach ($recentCompanies as $c): ?>
          <tr>
            <td class="ps-3 fw-bold"><?= cashflow_e($c['name']) ?></td>
            <td><?= cashflow_e($c['cui'] ?: '-') ?></td>
            <td><?= date('d.m.Y', strtotime($c['created_at'])) ?></td>
            <td class="pe-3 text-end"><?= $c['status'] === 'active' ? '<span class="badge bg-success-subtle text-success border border-success">Activă</span>' : '<span class="badge bg-secondary-subtle text-secondary border">Inactivă</span>' ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
