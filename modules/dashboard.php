<?php
/**
 * @var PDO $pdo
 * @var array $company
 * @var array|null $activeProfitCenter
 * @var array $accessibleProfitCenters
 * @var bool $isCompanyAdmin
 */

require_once __DIR__ . '/../lib/finance.php';

$companyId = (int)$company['id'];
$accessibleIds = array_map('intval', array_column($accessibleProfitCenters, 'id'));

[$periodFrom, $periodTo, $periodLabel] = cashflow_resolve_period($_GET['period'] ?? 'month');

if ($activeProfitCenter) {
    // Single profit-center dashboard.
    $pcId = (int)$activeProfitCenter['id'];
    $totals = cashflow_totals($pdo, $companyId, [$pcId], $periodFrom, $periodTo);
    $allTime = cashflow_totals($pdo, $companyId, [$pcId], null, null);
    $categoryBreakdown = cashflow_category_breakdown($pdo, $companyId, [$pcId], $periodFrom, $periodTo, 'expense');
    $forecast = cashflow_forecast($pdo, $companyId, [$pcId]);
    $receivablesPayables = cashflow_receivables_payables($pdo, $companyId, [$pcId]);

    $activityKpis = null;
    if ($activeProfitCenter['type'] === 'transport') {
        $activityKpis = ['type' => 'transport', 'data' => cashflow_transport_kpis($pdo, $companyId, $pcId, $periodFrom, $periodTo)];
    } elseif ($activeProfitCenter['type'] === 'service') {
        $activityKpis = ['type' => 'service', 'data' => cashflow_service_kpis($pdo, $companyId, $pcId, $periodFrom, $periodTo)];
    }
} else {
    // Consolidated dashboard across every profit center the user can see.
    $totals = cashflow_totals($pdo, $companyId, $accessibleIds, $periodFrom, $periodTo);
    $allTime = cashflow_totals($pdo, $companyId, $accessibleIds, null, null);
    $forecast = cashflow_forecast($pdo, $companyId, $accessibleIds);
    $receivablesPayables = cashflow_receivables_payables($pdo, $companyId, $accessibleIds);

    $perCenter = [];
    foreach ($accessibleProfitCenters as $pc) {
        $perCenter[] = [
            'pc' => $pc,
            'totals' => cashflow_totals($pdo, $companyId, [(int)$pc['id']], $periodFrom, $periodTo),
        ];
    }
}

$cashReal = $isCompanyAdmin ? cashflow_real_cash($pdo, $companyId) : null;
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <div>
    <h4 class="fw-bold mb-0">
      <?php if ($activeProfitCenter): ?>
        <i class="bi <?= cashflow_e($activeProfitCenter['icon']) ?>" style="color: <?= cashflow_e($activeProfitCenter['color']) ?>"></i>
        <?= cashflow_e($activeProfitCenter['name']) ?>
      <?php else: ?>
        <i class="bi bi-collection"></i> Dashboard consolidat
      <?php endif; ?>
    </h4>
    <small class="text-muted"><?= cashflow_e($company['name']) ?> · CUI <?= cashflow_e($company['cui'] ?: '-') ?></small>
  </div>
  <div class="btn-group btn-group-sm" role="group">
    <?php foreach (['month' => 'Luna curentă', '30d' => '30 zile', 'year' => 'Anul curent', 'all' => 'Tot'] as $key => $label): ?>
      <a class="btn btn-outline-secondary <?= ($_GET['period'] ?? 'month') === $key ? 'active' : '' ?>"
         href="<?= cashflow_url($activeProfitCenter ? 'dashboard' : 'dashboard', ['period' => $key]) ?>"><?= $label ?></a>
    <?php endforeach; ?>
  </div>
</div>

<div class="row g-3 mb-4">
  <?php if ($cashReal !== null): ?>
    <div class="col-6 col-lg-3">
      <div class="cf-kpi cf-kpi-cash">
        <div class="cf-kpi-label">Cash real (conturi firmă)</div>
        <div class="cf-kpi-value"><?= cashflow_money($cashReal) ?></div>
      </div>
    </div>
  <?php endif; ?>
  <div class="col-6 col-lg-3">
    <div class="cf-kpi cf-kpi-in">
      <div class="cf-kpi-label">Încasări (<?= cashflow_e($periodLabel) ?>)</div>
      <div class="cf-kpi-value"><?= cashflow_money($totals['income']) ?></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="cf-kpi cf-kpi-out">
      <div class="cf-kpi-label">Plăți (<?= cashflow_e($periodLabel) ?>)</div>
      <div class="cf-kpi-value"><?= cashflow_money($totals['expense']) ?></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="cf-kpi cf-kpi-net">
      <div class="cf-kpi-label">Cashflow net (<?= cashflow_e($periodLabel) ?>)</div>
      <div class="cf-kpi-value"><?= ($totals['net'] >= 0 ? '+' : '') . cashflow_money($totals['net']) ?></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-8">
    <div class="cf-card p-3 h-100">
      <div class="d-flex justify-content-between align-items-center mb-2">
        <h6 class="fw-bold mb-0">Cash atribuit (analitic) de la înființare</h6>
        <span class="badge bg-light text-dark border">Suma tuturor tranzacțiilor <?= $activeProfitCenter ? 'centrului' : 'centrelor vizibile' ?></span>
      </div>
      <p class="fs-4 fw-800 mb-1 <?= $allTime['net'] >= 0 ? 'text-success' : 'text-danger' ?>">
        <?= ($allTime['net'] >= 0 ? '+' : '') . cashflow_money($allTime['net']) ?>
      </p>
      <p class="text-muted small mb-0">
        Cash atribuit reprezintă cashflow-ul calculat analitic pe baza tranzacțiilor atribuite acestui context, nu neapărat sold bancar disponibil fizic
        (banii pot fi într-un cont comun al firmei — vezi „Cash real” pentru soldul efectiv).
      </p>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="cf-card p-3 h-100">
      <h6 class="fw-bold mb-2">Forecast 90 zile</h6>
      <p class="fs-4 fw-800 mb-1 <?= $forecast >= 0 ? 'text-success' : 'text-danger' ?>"><?= ($forecast >= 0 ? '+' : '') . cashflow_money($forecast) ?></p>
      <p class="text-muted small mb-0">Estimare pe baza tendinței cashflow-ului din ultimele 90 de zile.</p>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="cf-card p-3 h-100">
      <div class="small text-muted">Creanțe nedecontate</div>
      <div class="fs-5 fw-800"><?= cashflow_money($receivablesPayables['receivable']) ?></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="cf-card p-3 h-100">
      <div class="small text-muted">Datorii nedecontate</div>
      <div class="fs-5 fw-800"><?= cashflow_money($receivablesPayables['payable']) ?></div>
    </div>
  </div>
  <div class="col-12 col-lg-6 d-flex align-items-center">
    <a href="<?= cashflow_url('invoices') ?>" class="btn btn-outline-secondary btn-sm"><i class="bi bi-receipt"></i> Vezi facturile (creanțe/datorii)</a>
  </div>
</div>

<?php if ($activeProfitCenter && $activityKpis): ?>
  <h6 class="fw-bold mb-3"><?= $activityKpis['type'] === 'transport' ? 'KPI Transport' : 'KPI Service / Detailing' ?> (<?= cashflow_e($periodLabel) ?>)</h6>
  <div class="row g-3 mb-4">
    <?php if ($activityKpis['type'] === 'transport'): $d = $activityKpis['data']; ?>
      <div class="col-6 col-lg-2"><div class="cf-card p-3 text-center"><div class="small text-muted">Curse</div><div class="fw-800"><?= $d['trips'] ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="cf-card p-3 text-center"><div class="small text-muted">Km parcurși</div><div class="fw-800"><?= number_format($d['km'], 0, ',', '.') ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="cf-card p-3 text-center"><div class="small text-muted">Cost/km</div><div class="fw-800"><?= cashflow_money($d['cost_per_km']) ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="cf-card p-3 text-center"><div class="small text-muted">Venit/km</div><div class="fw-800"><?= cashflow_money($d['revenue_per_km']) ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="cf-card p-3 text-center"><div class="small text-muted">Combustibil+taxe</div><div class="fw-800"><?= cashflow_money($d['cost']) ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="cf-card p-3 text-center"><div class="small text-muted">Profit/cursă</div><div class="fw-800 <?= $d['profit_per_trip'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= cashflow_money($d['profit_per_trip']) ?></div></div></div>
    <?php else: $d = $activityKpis['data']; ?>
      <div class="col-6 col-lg-2"><div class="cf-card p-3 text-center"><div class="small text-muted">Mașini procesate</div><div class="fw-800"><?= $d['orders'] ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="cf-card p-3 text-center"><div class="small text-muted">Materiale</div><div class="fw-800"><?= cashflow_money($d['materials']) ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="cf-card p-3 text-center"><div class="small text-muted">Manoperă</div><div class="fw-800"><?= cashflow_money($d['labor']) ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="cf-card p-3 text-center"><div class="small text-muted">Venit/lucrare</div><div class="fw-800"><?= cashflow_money($d['revenue_per_order']) ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="cf-card p-3 text-center"><div class="small text-muted">Cost/lucrare</div><div class="fw-800"><?= cashflow_money($d['cost_per_order']) ?></div></div></div>
      <div class="col-6 col-lg-2"><div class="cf-card p-3 text-center"><div class="small text-muted">Profit/lucrare</div><div class="fw-800 <?= $d['profit_per_order'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= cashflow_money($d['profit_per_order']) ?></div></div></div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php if (!$activeProfitCenter): ?>
  <h6 class="fw-bold mb-3">Centre de profit</h6>
  <div class="row g-3 mb-4">
    <?php if (empty($perCenter)): ?>
      <div class="col-12"><div class="alert alert-light border">Nu ai acces la niciun centru de profit în această firmă.</div></div>
    <?php endif; ?>
    <?php foreach ($perCenter as $row): $pc = $row['pc']; $t = $row['totals']; ?>
      <div class="col-6 col-lg-3">
        <a class="text-decoration-none text-reset" href="index.php?p=dashboard&cid=<?= $companyId ?>&pc=<?= (int)$pc['id'] ?>">
          <div class="cf-pc-card">
            <div class="d-flex align-items-center gap-2 mb-2">
              <span class="cf-pc-dot" style="background: <?= cashflow_e($pc['color']) ?>"><i class="bi <?= cashflow_e($pc['icon']) ?>"></i></span>
              <strong><?= cashflow_e($pc['name']) ?></strong>
            </div>
            <div class="small text-muted">Cashflow</div>
            <div class="fw-800 <?= $t['net'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= ($t['net'] >= 0 ? '+' : '') . cashflow_money($t['net']) ?></div>
            <div class="d-flex justify-content-between small text-muted mt-2">
              <span>Venituri: <?= cashflow_money($t['income']) ?></span>
            </div>
            <div class="d-flex justify-content-between small text-muted">
              <span>Cheltuieli: <?= cashflow_money($t['expense']) ?></span>
            </div>
          </div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>
<?php else: ?>
  <h6 class="fw-bold mb-3">Top categorii cheltuieli (<?= cashflow_e($periodLabel) ?>)</h6>
  <div class="cf-card p-3">
    <?php if (empty($categoryBreakdown)): ?>
      <p class="text-muted small mb-0">Nu există cheltuieli înregistrate în această perioadă.</p>
    <?php else: ?>
      <?php $max = max(array_column($categoryBreakdown, 'total')) ?: 1; ?>
      <?php foreach ($categoryBreakdown as $row): ?>
        <div class="d-flex justify-content-between small mb-1">
          <span><?= cashflow_e($row['name'] ?: 'Fără categorie') ?></span>
          <span class="fw-bold"><?= cashflow_money($row['total']) ?></span>
        </div>
        <div class="cf-bar-track mb-3">
          <div class="cf-bar-fill" style="width: <?= round($row['total'] / $max * 100) ?>%; background: <?= cashflow_e($activeProfitCenter['color']) ?>"></div>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
<?php endif; ?>

<div class="mt-4 d-flex flex-wrap gap-2">
  <a href="<?= cashflow_url('transactions', ['action' => 'new']) ?>" class="btn btn-primary fw-bold">
    <i class="bi bi-plus-circle"></i> Tranzacție nouă
  </a>
  <?php if ($activeProfitCenter && $activeProfitCenter['type'] === 'transport'): ?>
    <a href="<?= cashflow_url('transport') ?>" class="btn btn-outline-primary fw-bold"><i class="bi bi-truck"></i> Cursă nouă</a>
  <?php elseif ($activeProfitCenter && $activeProfitCenter['type'] === 'service'): ?>
    <a href="<?= cashflow_url('service_orders') ?>" class="btn btn-outline-primary fw-bold"><i class="bi bi-wrench-adjustable-circle"></i> Lucrare nouă</a>
  <?php endif; ?>
</div>
