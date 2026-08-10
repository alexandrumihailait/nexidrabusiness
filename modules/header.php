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
<title>Cashflow · <?= cashflow_e($company['name']) ?></title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><rect width='24' height='24' rx='6' fill='%234f46e5'/><path d='M5 16l4-4 3 3 6-7' stroke='white' stroke-width='2' fill='none' stroke-linecap='round' stroke-linejoin='round'/></svg>">
<link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/style.css" rel="stylesheet">
</head>
<body>

<nav class="navbar navbar-expand-xl cf-topbar sticky-top">
  <div class="container-fluid px-3">
    <a class="navbar-brand fw-bold d-flex align-items-center gap-2 flex-shrink-0" href="<?= cashflow_url('dashboard') ?>">
      <i class="bi bi-graph-up-arrow"></i> <span class="d-none d-sm-inline">Cashflow</span>
    </a>

    <button class="navbar-toggler flex-shrink-0" type="button" data-bs-toggle="collapse" data-bs-target="#cfNav">
      <span class="navbar-toggler-icon"></span>
    </button>

    <div class="collapse navbar-collapse" id="cfNav">
      <div class="d-flex flex-column flex-xl-row align-items-stretch align-items-xl-center gap-2 flex-wrap cf-context-selectors my-3 my-xl-0 me-xl-3">
        <div class="dropdown">
          <button class="btn btn-light btn-sm cf-selector-btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
            <?php if (!empty($company['logo'])): ?>
              <img src="<?= cashflow_e($company['logo']) ?>" alt="" class="cf-company-logo flex-shrink-0">
            <?php else: ?>
              <i class="bi bi-building flex-shrink-0"></i>
            <?php endif; ?>
            <span class="cf-selector-label">
              <strong><?= cashflow_e($company['name']) ?></strong>
              <small class="text-muted d-block">CUI: <?= cashflow_e($company['cui'] ?: '-') ?></small>
            </span>
          </button>
          <ul class="dropdown-menu">
            <?php foreach (cashflow_user_companies($pdo, $userId) as $c): ?>
              <li>
                <a class="dropdown-item d-flex align-items-center gap-2 <?= (int)$c['id'] === (int)$company['id'] ? 'active' : '' ?>"
                   href="index.php?p=<?= cashflow_e($page) ?>&cid=<?= (int)$c['id'] ?>">
                  <i class="bi bi-building"></i>
                  <span><?= cashflow_e($c['name']) ?><br><small class="text-muted">CUI: <?= cashflow_e($c['cui'] ?: '-') ?></small></span>
                </a>
              </li>
            <?php endforeach; ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="index.php?p=select_company"><i class="bi bi-list-check me-1"></i> Toate firmele</a></li>
          </ul>
        </div>

        <div class="dropdown">
          <button class="btn btn-light btn-sm cf-selector-btn dropdown-toggle" type="button" data-bs-toggle="dropdown">
            <?php if ($activeProfitCenter): ?>
              <i class="bi <?= cashflow_e($activeProfitCenter['icon']) ?> flex-shrink-0" style="color: <?= cashflow_e($activeProfitCenter['color']) ?>"></i>
              <span class="cf-selector-label"><strong><?= cashflow_e($activeProfitCenter['name']) ?></strong></span>
            <?php else: ?>
              <i class="bi bi-collection flex-shrink-0"></i>
              <span class="cf-selector-label"><strong>TOATE centrele</strong></span>
            <?php endif; ?>
          </button>
          <ul class="dropdown-menu">
            <li>
              <a class="dropdown-item d-flex align-items-center gap-2 <?= !$activeProfitCenter ? 'active' : '' ?>"
                 href="index.php?p=<?= cashflow_e($page) ?>&cid=<?= (int)$company['id'] ?>&pc=all">
                <i class="bi bi-collection"></i> TOATE (consolidat)
              </a>
            </li>
            <li><hr class="dropdown-divider"></li>
            <?php foreach ($accessibleProfitCenters as $pc): ?>
              <li>
                <a class="dropdown-item d-flex align-items-center gap-2 <?= $activeProfitCenter && (int)$activeProfitCenter['id'] === (int)$pc['id'] ? 'active' : '' ?>"
                   href="index.php?p=<?= cashflow_e($page) ?>&cid=<?= (int)$company['id'] ?>&pc=<?= (int)$pc['id'] ?>">
                  <i class="bi <?= cashflow_e($pc['icon']) ?>" style="color: <?= cashflow_e($pc['color']) ?>"></i>
                  <?= cashflow_e($pc['name']) ?>
                  <?php if ($pc['access_level'] === 'read'): ?><span class="badge bg-secondary ms-auto">read</span><?php endif; ?>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
        </div>
      </div>

      <ul class="navbar-nav me-auto align-items-xl-center gap-1">
        <li class="nav-item"><a class="nav-link <?= $page === 'dashboard' ? 'active' : '' ?>" href="<?= cashflow_url('dashboard') ?>"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
        <li class="nav-item"><a class="nav-link <?= $page === 'transactions' ? 'active' : '' ?>" href="<?= cashflow_url('transactions') ?>"><i class="bi bi-arrow-left-right"></i> Tranzacții</a></li>
        <li class="nav-item"><a class="nav-link <?= $page === 'reports' ? 'active' : '' ?>" href="<?= cashflow_url('reports') ?>"><i class="bi bi-bar-chart"></i> Rapoarte</a></li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= in_array($page, ['transport', 'service_orders', 'invoices', 'allocations', 'documents'], true) ? 'active' : '' ?>" href="#" data-bs-toggle="dropdown"><i class="bi bi-grid"></i> Module</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item <?= $page === 'transport' ? 'active' : '' ?>" href="<?= cashflow_url('transport') ?>"><i class="bi bi-truck"></i> Transport (curse, vehicule, șoferi)</a></li>
            <li><a class="dropdown-item <?= $page === 'service_orders' ? 'active' : '' ?>" href="<?= cashflow_url('service_orders') ?>"><i class="bi bi-wrench-adjustable-circle"></i> Lucrări (Service/Detailing/Colantări)</a></li>
            <li><a class="dropdown-item <?= $page === 'invoices' ? 'active' : '' ?>" href="<?= cashflow_url('invoices') ?>"><i class="bi bi-receipt"></i> Facturi (Creanțe/Datorii)</a></li>
            <li><a class="dropdown-item <?= $page === 'allocations' ? 'active' : '' ?>" href="<?= cashflow_url('allocations') ?>"><i class="bi bi-diagram-2"></i> Alocare costuri</a></li>
            <li><a class="dropdown-item <?= $page === 'documents' ? 'active' : '' ?>" href="<?= cashflow_url('documents') ?>"><i class="bi bi-file-earmark-arrow-up"></i> Documente</a></li>
          </ul>
        </li>

        <?php if ($isCompanyAdmin): ?>
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle <?= in_array($page, ['profit_centers', 'accounts', 'permissions', 'audit', 'integrations'], true) ? 'active' : '' ?>" href="#" data-bs-toggle="dropdown"><i class="bi bi-gear"></i> Administrare</a>
          <ul class="dropdown-menu">
            <li><a class="dropdown-item <?= $page === 'profit_centers' ? 'active' : '' ?>" href="<?= cashflow_url('profit_centers') ?>"><i class="bi bi-diagram-3"></i> Centre de profit</a></li>
            <li><a class="dropdown-item <?= $page === 'accounts' ? 'active' : '' ?>" href="<?= cashflow_url('accounts') ?>"><i class="bi bi-bank"></i> Conturi</a></li>
            <li><a class="dropdown-item <?= $page === 'permissions' ? 'active' : '' ?>" href="<?= cashflow_url('permissions') ?>"><i class="bi bi-people"></i> Permisiuni</a></li>
            <li><a class="dropdown-item <?= $page === 'integrations' ? 'active' : '' ?>" href="<?= cashflow_url('integrations') ?>"><i class="bi bi-plug"></i> Integrări</a></li>
            <li><a class="dropdown-item <?= $page === 'audit' ? 'active' : '' ?>" href="<?= cashflow_url('audit') ?>"><i class="bi bi-journal-text"></i> Audit</a></li>
          </ul>
        </li>
        <?php endif; ?>
      </ul>

      <ul class="navbar-nav align-items-xl-center">
        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown"><i class="bi bi-person-circle"></i> <?= cashflow_e($currentUser['name']) ?></a>
          <ul class="dropdown-menu dropdown-menu-end">
            <li><span class="dropdown-item-text small text-muted"><?= cashflow_e($company['role_name']) ?></span></li>
            <li><a class="dropdown-item" href="<?= cashflow_url('billing') ?>"><i class="bi bi-credit-card"></i> Abonament</a></li>
            <?php if (cashflow_is_platform_admin($pdo, $userId)): ?>
              <li><a class="dropdown-item" href="admin.php"><i class="bi bi-shield-lock"></i> Admin platformă</a></li>
            <?php endif; ?>
            <li><hr class="dropdown-divider"></li>
            <li><a class="dropdown-item" href="index.php?p=logout"><i class="bi bi-box-arrow-right"></i> Deconectare</a></li>
          </ul>
        </li>
      </ul>
    </div>
  </div>
</nav>

<main class="container-fluid px-3 px-lg-4 py-4 cf-fade-in">
  <?php foreach (cashflow_flash_pull() as $flash): ?>
    <div class="alert alert-<?= cashflow_e($flash['type']) ?> alert-dismissible fade show" role="alert">
      <?= cashflow_e($flash['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endforeach; ?>
