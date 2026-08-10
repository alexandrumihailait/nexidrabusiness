<?php
/**
 * Company-facing subscription + usage view (read-only -- upgrading a plan
 * is a platform-admin action from admin.php, since there is no payment
 * gateway wired up yet; see README for what that would take to add).
 *
 * @var PDO $pdo
 * @var array $company
 */

$companyId = (int)$company['id'];
$summary = cashflow_subscription_summary($pdo, $companyId);
$sub = $summary['subscription'];

$allFeatureLabels = [
    'excel_export' => 'Export Excel/CSV',
    'anaf_lookup' => 'Interogare firme ANAF (CUI)',
    'anaf_efactura' => 'ANAF e-Factura',
    'smartbill' => 'Integrare SmartBill',
    'google_drive' => 'Integrare Google Drive',
];
$planFeatures = array_filter(array_map('trim', explode(',', $sub['features'] ?? '')));

function cashflow_usage_bar(int $used, int $limit): array
{
    if ($limit <= 0) {
        return ['pct' => 0, 'label' => "$used / nelimitat"];
    }
    $pct = min(100, (int)round($used / $limit * 100));
    return ['pct' => $pct, 'label' => "$used / $limit"];
}

$docBar = cashflow_usage_bar($summary['documents_used'], (int)$sub['max_documents_month']);
$lookupBar = cashflow_usage_bar($summary['anaf_lookups_used'], (int)$sub['max_anaf_lookups_month']);
?>

<h4 class="fw-bold mb-3"><i class="bi bi-credit-card"></i> Abonament</h4>

<div class="cf-card p-4 mb-4">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
    <div>
      <h5 class="fw-800 mb-0"><?= cashflow_e($sub['plan_name']) ?></h5>
      <p class="text-muted mb-0"><?= cashflow_money((float)($sub['price_month_ron'] ?? 0)) ?> / lună</p>
    </div>
    <span class="badge bg-success-subtle text-success border border-success px-3 py-2">Activ</span>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-md-6">
    <div class="cf-card p-3 h-100">
      <div class="d-flex justify-content-between small mb-1"><span class="fw-bold">Documente încărcate luna aceasta</span><span><?= cashflow_e($docBar['label']) ?></span></div>
      <div class="cf-bar-track"><div class="cf-bar-fill" style="width: <?= $docBar['pct'] ?>%; background: <?= $docBar['pct'] >= 90 ? '#dc2626' : '#4f46e5' ?>"></div></div>
    </div>
  </div>
  <div class="col-md-6">
    <div class="cf-card p-3 h-100">
      <div class="d-flex justify-content-between small mb-1"><span class="fw-bold">Interogări ANAF luna aceasta</span><span><?= cashflow_e($lookupBar['label']) ?></span></div>
      <div class="cf-bar-track"><div class="cf-bar-fill" style="width: <?= $lookupBar['pct'] ?>%; background: <?= $lookupBar['pct'] >= 90 ? '#dc2626' : '#4f46e5' ?>"></div></div>
    </div>
  </div>
</div>

<h6 class="fw-bold mb-2">Funcționalități incluse în planul curent</h6>
<div class="cf-card p-3">
  <?php foreach ($allFeatureLabels as $code => $label): ?>
    <span class="badge <?= in_array($code, $planFeatures, true) ? 'bg-success-subtle text-success border-success' : 'bg-light text-muted border' ?> border me-2 mb-2">
      <i class="bi bi-<?= in_array($code, $planFeatures, true) ? 'check-circle' : 'lock' ?>"></i> <?= cashflow_e($label) ?>
    </span>
  <?php endforeach; ?>
  <p class="text-muted small mt-3 mb-0">Pentru a schimba planul, contactează administratorul platformei.</p>
</div>
