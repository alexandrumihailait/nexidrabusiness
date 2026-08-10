<?php
/**
 * @var PDO $pdo
 * @var array $company
 * @var array $accessibleProfitCenters
 */

require_once __DIR__ . '/../lib/finance.php';

$companyId = (int)$company['id'];
[$periodFrom, $periodTo, $periodLabel] = cashflow_resolve_period($_GET['period'] ?? 'month');

$rows = [];
foreach ($accessibleProfitCenters as $pc) {
    $rows[] = ['pc' => $pc, 'totals' => cashflow_totals($pdo, $companyId, [(int)$pc['id']], $periodFrom, $periodTo)];
}

$consolidated = cashflow_totals(
    $pdo,
    $companyId,
    array_map('intval', array_column($accessibleProfitCenters, 'id')),
    $periodFrom,
    $periodTo
);

$totalIncomeAll = array_sum(array_column(array_column($rows, 'totals'), 'income')) ?: 1;

$allocatedCosts = cashflow_allocated_costs(
    $pdo,
    $companyId,
    array_map('intval', array_column($accessibleProfitCenters, 'id')),
    $periodFrom,
    $periodTo
);
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <h4 class="fw-bold mb-0"><i class="bi bi-bar-chart"></i> Rapoarte</h4>
  <div class="d-flex gap-2 flex-wrap">
    <div class="btn-group btn-group-sm" role="group">
      <?php foreach (['month' => 'Luna curentă', '30d' => '30 zile', 'year' => 'Anul curent', 'all' => 'Tot'] as $key => $label): ?>
        <a class="btn btn-outline-secondary <?= ($_GET['period'] ?? 'month') === $key ? 'active' : '' ?>" href="<?= cashflow_url('reports', ['period' => $key]) ?>"><?= $label ?></a>
      <?php endforeach; ?>
    </div>
    <a href="<?= cashflow_url('export', ['type' => 'report', 'period' => $_GET['period'] ?? 'month']) ?>" class="btn btn-outline-secondary btn-sm fw-bold"><i class="bi bi-file-earmark-excel"></i> Export Excel</a>
  </div>
</div>

<h6 class="fw-bold mb-2">Raport consolidat vs. segmentat pe centru de profit (<?= cashflow_e($periodLabel) ?>)</h6>
<div class="cf-card p-0 overflow-hidden mb-4">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light small text-uppercase text-muted">
        <tr>
          <th class="ps-3">Centru</th>
          <th class="text-end">Încasări</th>
          <th class="text-end">Plăți</th>
          <th class="text-end pe-3">Cashflow net</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $row): $pc = $row['pc']; $t = $row['totals']; ?>
          <tr>
            <td class="ps-3">
              <i class="bi <?= cashflow_e($pc['icon']) ?>" style="color: <?= cashflow_e($pc['color']) ?>"></i>
              <?= cashflow_e($pc['name']) ?>
            </td>
            <td class="text-end"><?= cashflow_money($t['income']) ?></td>
            <td class="text-end"><?= cashflow_money($t['expense']) ?></td>
            <td class="text-end pe-3 fw-bold <?= $t['net'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= ($t['net'] >= 0 ? '+' : '') . cashflow_money($t['net']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot class="table-light">
        <tr class="fw-bold">
          <td class="ps-3">Firma (consolidat, centre vizibile)</td>
          <td class="text-end"><?= cashflow_money($consolidated['income']) ?></td>
          <td class="text-end"><?= cashflow_money($consolidated['expense']) ?></td>
          <td class="text-end pe-3 <?= $consolidated['net'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= ($consolidated['net'] >= 0 ? '+' : '') . cashflow_money($consolidated['net']) ?></td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<h6 class="fw-bold mb-2">Compară centrele de profit</h6>
<div class="cf-card p-3">
  <?php if (empty($rows)): ?>
    <p class="text-muted small mb-0">Nu ai acces la niciun centru de profit.</p>
  <?php endif; ?>
  <?php foreach ($rows as $row): $pc = $row['pc']; $t = $row['totals']; $share = round($t['income'] / $totalIncomeAll * 100); ?>
    <div class="d-flex justify-content-between small mb-1">
      <span><i class="bi <?= cashflow_e($pc['icon']) ?>" style="color: <?= cashflow_e($pc['color']) ?>"></i> <?= cashflow_e($pc['name']) ?></span>
      <span class="fw-bold"><?= $share ?>% din venituri · marjă <?= $t['income'] > 0 ? round($t['net'] / $t['income'] * 100) : 0 ?>%</span>
    </div>
    <div class="cf-bar-track mb-3">
      <div class="cf-bar-fill" style="width: <?= $share ?>%; background: <?= cashflow_e($pc['color']) ?>"></div>
    </div>
  <?php endforeach; ?>
</div>

<h6 class="fw-bold mb-2 mt-4">Costuri alocate primite (din costuri comune/corporate distribuite)</h6>
<p class="text-muted small">
  Nu confunda cashflow-ul (mai sus) cu profitul: costurile alocate reduc profitul unui centru fără să apară ca o plată directă din contul acelui centru
  — banii au ieșit deja din contul comun/Corporate. Profit centru = cashflow direct − costuri alocate primite.
</p>
<div class="cf-card p-0 overflow-hidden">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light small text-uppercase text-muted">
        <tr><th class="ps-3">Centru</th><th class="text-end">Cashflow direct</th><th class="text-end">Costuri alocate primite</th><th class="text-end pe-3">Profit centru (estimat)</th></tr>
      </thead>
      <tbody>
        <?php if (empty($rows)): ?><tr><td colspan="4" class="text-center py-4 text-muted">Nu ai acces la niciun centru de profit.</td></tr><?php endif; ?>
        <?php foreach ($rows as $row): $pc = $row['pc']; $t = $row['totals']; $alloc = $allocatedCosts[(int)$pc['id']] ?? 0.0; ?>
          <tr>
            <td class="ps-3"><i class="bi <?= cashflow_e($pc['icon']) ?>" style="color: <?= cashflow_e($pc['color']) ?>"></i> <?= cashflow_e($pc['name']) ?></td>
            <td class="text-end"><?= ($t['net'] >= 0 ? '+' : '') . cashflow_money($t['net']) ?></td>
            <td class="text-end text-danger"><?= $alloc > 0 ? '-' . cashflow_money($alloc) : cashflow_money(0) ?></td>
            <td class="text-end pe-3 fw-bold <?= ($t['net'] - $alloc) >= 0 ? 'text-success' : 'text-danger' ?>"><?= (($t['net'] - $alloc) >= 0 ? '+' : '') . cashflow_money($t['net'] - $alloc) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
