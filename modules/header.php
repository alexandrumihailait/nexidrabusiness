<?php
/**
 * @var PDO $pdo
 * @var array $company
 * @var array|null $activeProfitCenter
 * @var array $accessibleProfitCenters
 * @var bool $isCompanyAdmin
 * @var string $page
 * @var array $currentUser
 */
?><!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Finance Suite · <?= cashflow_e($company['name'] ?? 'App') ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><rect width='24' height='24' rx='6' fill='%230ea5e9'/><path d='M5 16l4-4 3 3 6-7' stroke='white' stroke-width='2' fill='none' stroke-linecap='round' stroke-linejoin='round'/></svg>">
<link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<!-- ApexCharts for modern reporting -->
<script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
<style>
    :root {
        --cf-bg: #0b1120;
        --cf-card-bg: #111827;
        --cf-border: #1f2937;
        --cf-text-main: #f8fafc;
        --cf-text-muted: #94a3b8;
        --cf-accent: #0ea5e9;
        --cf-accent-hover: #38bdf8;
    }
    body { background-color: var(--cf-bg); color: var(--cf-text-main); font-family: 'Segoe UI', system-ui, sans-serif; }
    .cf-topbar { background-color: var(--cf-card-bg); border-bottom: 1px solid var(--cf-border); }
    .navbar-brand, .nav-link { color: var(--cf-text-muted) !important; font-weight: 500; }
    .navbar-brand { color: var(--cf-text-main) !important; font-size: 1.25rem; font-weight: 700; }
    .nav-link:hover, .nav-link.active { color: var(--cf-accent) !important; }
    
    .cf-card { background-color: var(--cf-card-bg); border: 1px solid var(--cf-border); border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,0.2); }
    
    .table { color: var(--cf-text-main); --bs-table-bg: transparent; --bs-table-color: var(--cf-text-main); }
    .table-light { background-color: var(--cf-border) !important; color: var(--cf-text-muted) !important; border-bottom: 2px solid var(--cf-bg); }
    .table-hover tbody tr:hover { background-color: rgba(255, 255, 255, 0.03) !important; color: var(--cf-text-main); }
    td, th { border-color: var(--cf-border) !important; }
    
    .form-control, .form-select, .input-group-text { background-color: var(--cf-bg); border: 1px solid var(--cf-border); color: var(--cf-text-main); }
    .form-control:focus, .form-select:focus { background-color: var(--cf-bg); color: var(--cf-text-main); border-color: var(--cf-accent); box-shadow: 0 0 0 0.25rem rgba(14, 165, 233, 0.25); }
    .form-control::placeholder { color: #475569; }
    
    .btn-primary { background-color: var(--cf-accent); border-color: var(--cf-accent); color: #fff; }
    .btn-primary:hover { background-color: var(--cf-accent-hover); border-color: var(--cf-accent-hover); }
    .btn-light { background-color: var(--cf-bg); border-color: var(--cf-border); color: var(--cf-text-main); }
    .btn-light:hover { background-color: var(--cf-border); color: #fff; }
    
    .dropdown-menu { background-color: var(--cf-card-bg); border: 1px solid var(--cf-border); box-shadow: 0 10px 15px -3px rgba(0,0,0,0.5); }
    .dropdown-item { color: var(--cf-text-main); }
    .dropdown-item:hover, .dropdown-item.active { background-color: var(--cf-bg); color: var(--cf-accent); }
    .dropdown-divider { border-color: var(--cf-border); }
    
    .text-muted { color: var(--cf-text-muted) !important; }
    .cf-fade-in { animation: fadeIn 0.4s ease-in; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
</style>
</head>
<body>

<nav class="navbar navbar-expand-xl cf-topbar sticky-top py-3">
  <div class="container-fluid px-3">
    <a class="navbar-brand d-flex align-items-center gap-2 flex-shrink-0" href="<?= cashflow_url('dashboard') ?>">
      <i class="bi bi-graph-up-arrow" style="color: var(--cf-accent);"></i> <span class="d-none d-sm-inline">Finance Suite</span>
    </a>

    <button class="navbar-toggler flex-shrink-0 border-secondary" type="button" data-bs-toggle="collapse" data-bs-target="#cfNav">
      <i class="bi bi-list text-light fs-2"></i>
    </button>

    <div class="collapse navbar-collapse" id="cfNav">
      <div class="d-flex flex-column flex-xl-row align-items-stretch align-items-xl-center gap-3 cf-context-selectors my-3 my-xl-0 me-xl-4">
        
        <div class="dropdown">
          <button class="btn btn-light btn-sm cf-selector-btn dropdown-toggle d-flex align-items-center gap-2 py-2 px-3 rounded-pill" type="button" data-bs-toggle="dropdown">
            <?php if (!empty($company['logo'])): ?>
              <img src="<?= cashflow_e($company['logo']) ?>" alt="" class="cf-company-logo flex-shrink-0" width="20">
            <?php else: ?>
              <i class="bi bi-building flex-shrink-0 text-info"></i>
            <?php endif; ?>
            <span class="cf-selector-label text-start lh-1">
              <strong class="d-block"><?= cashflow_e($company['name'] ?? 'Firma Mea') ?></strong>
            </span>
          </button>
          <ul class="dropdown-menu">
            <?php if(isset($userId)): foreach (cashflow_user_companies($pdo, $userId) as $c): ?>
              <li>
                <a class="dropdown-item d-flex align-items-center gap-2 <?= (isset($company['id']) && (int)$c['id'] === (int)$company['id']) ? 'active' : '' ?>"
                   href="index.php?p=<?= cashflow_e($page) ?>&cid=<?= (int)$c['id'] ?>">
                  <i class="bi bi-building"></i>
                  <span><?= cashflow_e($c['name']) ?><br><small class="text-muted">CUI: <?= cashflow_e($c['cui'] ?: '-') ?></small></span>
                </a>
              </li>
            <?php endforeach; endif; ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="index.php?p=select_company"><i class="bi bi-list-check me-1"></i> Toate firmele</a></li>
          </ul>
        </div>

        <div class="dropdown">
          <button class="btn btn-light btn-sm cf-selector-btn dropdown-toggle d-flex align-items-center gap-2 py-2 px-3 rounded-pill" type="button" data-bs-toggle="dropdown">
            <?php if (!empty($activeProfitCenter)): ?>
              <i class="bi <?= cashflow_e($activeProfitCenter['icon']) ?> flex-shrink-0" style="color: <?= cashflow_e($activeProfitCenter['color']) ?>"></i>
              <span class="cf-selector-label text-start lh-1"><strong class="d-block"><?= cashflow_e($activeProfitCenter['name']) ?></strong></span>
            <?php else: ?>
              <i class="bi bi-collection flex-shrink-0 text-success"></i>
              <span class="cf-selector-label text-start lh-1"><strong class="d-block">TOATE centrele</strong></span>
            <?php endif; ?>
          </button>
          <ul class="dropdown-menu">
            <li>
              <a class="dropdown-item d-flex align-items-center gap-2 <?= empty($activeProfitCenter) ? 'active' : '' ?>"
                 href="index.php?p=<?= cashflow_e($page) ?>&cid=<?= (int)($company['id'] ?? 0) ?>&pc=all">
                <i class="bi bi-collection"></i> TOATE (consolidat)
              </a>
            </li>
            <li><hr class="dropdown-divider"></li>
            <?php if(isset($accessibleProfitCenters)): foreach ($accessibleProfitCenters as $pc): ?>
              <li>
                <a class="dropdown-item d-flex align-items-center gap-2 <?= (!empty($activeProfitCenter) && (int)$activeProfitCenter['id'] === (int)$pc['id']) ? 'active' : '' ?>"
                   href="index.php?p=<?= cashflow_e($page) ?>&cid=<?= (int)($company['id'] ?? 0) ?>&pc=<?= (int)$pc['id'] ?>">
                  <i class="bi <?= cashflow_e($pc['icon']) ?>" style="color: <?= cashflow_e($pc['color']) ?>"></i>
                  <?= cashflow_e($pc['name']) ?>
                  <?php if ($pc['access_level'] === 'read'): ?><span class="badge bg-secondary ms-auto">read</span><?php endif; ?>
                </a>
              </li>
            <?php endforeach; endif; ?>
          </ul>
        </div>
      </div>

      <ul class="navbar-nav me-auto align-items-xl-center gap-2 fw-semibold">
        <li class="nav-item"><a class="nav-link px-3 rounded <?= $page === 'dashboard' ? 'active bg-dark' : '' ?>" href="<?= cashflow_url('dashboard') ?>">Dashboard</a></li>
        <li class="nav-item"><a class="nav-link px-3 rounded <?= $page === 'transactions' ? 'active bg-dark' : '' ?>" href="<?= cashflow_url('transactions') ?>">Tranzacții</a></li>
        <li class="nav-item"><a class="nav-link px-3 rounded <?= $page === 'reports' ? 'active bg-dark' : '' ?>" href="<?= cashflow_url('reports') ?>">Rapoarte</a></li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle px-3 rounded <?= in_array($page, ['transport', 'service_orders', 'invoices', 'allocations', 'documents'], true) ? 'active bg-dark' : '' ?>" href="#" data-bs-toggle="dropdown">Module</a>
          <ul class="dropdown-menu border-0 shadow-lg mt-2">
            <li><a class="dropdown-item py-2 <?= $page === 'transport' ? 'active' : '' ?>" href="<?= cashflow_url('transport') ?>"><i class="bi bi-truck me-2"></i> Transport</a></li>
            <li><a class="dropdown-item py-2 <?= $page === 'service_orders' ? 'active' : '' ?>" href="<?= cashflow_url('service_orders') ?>"><i class="bi bi-wrench me-2"></i> Lucrări (Service)</a></li>
            <li><a class="dropdown-item py-2 <?= $page === 'invoices' ? 'active' : '' ?>" href="<?= cashflow_url('invoices') ?>"><i class="bi bi-receipt me-2"></i> Facturi</a></li>
            <li><a class="dropdown-item py-2 <?= $page === 'allocations' ? 'active' : '' ?>" href="<?= cashflow_url('allocations') ?>"><i class="bi bi-diagram-2 me-2"></i> Alocare costuri</a></li>
            <li><a class="dropdown-item py-2 <?= $page === 'documents' ? 'active' : '' ?>" href="<?= cashflow_url('documents') ?>"><i class="bi bi-folder me-2"></i> Documente</a></li>
          </ul>
        </li>

        <?php if (!empty($isCompanyAdmin)): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle px-3 rounded <?= in_array($page, ['profit_centers', 'accounts', 'permissions', 'audit', 'integrations'], true) ? 'active bg-dark' : '' ?>" href="#" data-bs-toggle="dropdown">Administrare</a>
          <ul class="dropdown-menu border-0 shadow-lg mt-2">
            <li><a class="dropdown-item py-2 <?= $page === 'profit_centers' ? 'active' : '' ?>" href="<?= cashflow_url('profit_centers') ?>"><i class="bi bi-diagram-3 me-2"></i> Centre de profit</a></li>
            <li><a class="dropdown-item py-2 <?= $page === 'accounts' ? 'active' : '' ?>" href="<?= cashflow_url('accounts') ?>"><i class="bi bi-bank me-2"></i> Conturi</a></li>
            <li><a class="dropdown-item py-2 <?= $page === 'permissions' ? 'active' : '' ?>" href="<?= cashflow_url('permissions') ?>"><i class="bi bi-people me-2"></i> Permisiuni</a></li>
            <li><a class="dropdown-item py-2 <?= $page === 'integrations' ? 'active' : '' ?>" href="<?= cashflow_url('integrations') ?>"><i class="bi bi-plug me-2"></i> Integrări</a></li>
            <li><a class="dropdown-item py-2 <?= $page === 'audit' ? 'active' : '' ?>" href="<?= cashflow_url('audit') ?>"><i class="bi bi-shield-check me-2"></i> Audit</a></li>
          </ul>
        </li>
        <?php endif; ?>
      </ul>

      <ul class="navbar-nav align-items-xl-center">
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" data-bs-toggle="dropdown">
            <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" style="width: 32px; height: 32px;">
                <i class="bi bi-person"></i>
            </div>
            <?= cashflow_e($currentUser['name'] ?? 'Utilizator') ?>
          </a>
          <ul class="dropdown-menu dropdown-menu-end border-0 shadow-lg mt-2">
            <li><span class="dropdown-item-text small text-muted"><?= cashflow_e($company['role_name'] ?? 'Rol') ?></span></li>
            <li><a class="dropdown-item py-2" href="<?= cashflow_url('billing') ?>"><i class="bi bi-credit-card me-2"></i> Abonament</a></li>
            <?php if (isset($userId) && cashflow_is_platform_admin($pdo, $userId)): ?>
              <li><a class="dropdown-item py-2" href="admin.php"><i class="bi bi-shield-lock me-2 text-warning"></i> Admin platformă</a></li>
            <?php endif; ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item py-2 text-danger" href="index.php?p=logout"><i class="bi bi-box-arrow-right me-2"></i> Deconectare</a></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>

<main class="container-fluid px-3 px-lg-4 py-4 cf-fade-in">
  <?php foreach (cashflow_flash_pull() as $flash): ?>
    <div class="alert alert-<?= cashflow_e($flash['type'] === 'danger' ? 'danger bg-danger text-white border-0' : 'success bg-success text-white border-0') ?> alert-dismissible fade show" role="alert">
      <?= cashflow_e($flash['message']) ?>
      <button type="button" class="btn-close btn-close-white" data-bs-dismiss="alert"></button>
    </div>
  <?php endforeach; ?>