<?php
/**
 * @var PDO $pdo
 * @var array $currentUser
 * @var string $page
 */
?><!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin platformă · Cashflow</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><rect width='24' height='24' rx='6' fill='%234f46e5'/><path d='M5 16l4-4 3 3 6-7' stroke='white' stroke-width='2' fill='none' stroke-linecap='round' stroke-linejoin='round'/></svg>">
<link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/style.css" rel="stylesheet">
</head>
<body class="cf-admin-body">

<nav class="navbar navbar-expand-xl cf-admin-topbar sticky-top">
  <div class="container-fluid px-3">
    <a class="navbar-brand fw-bold d-flex align-items-center gap-2" href="admin.php">
      <i class="bi bi-shield-lock"></i> Admin platformă
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#cfAdminNav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="cfAdminNav">
      <ul class="navbar-nav ms-auto align-items-xl-center gap-1 my-2 my-xl-0">
        <li class="nav-item"><a class="nav-link <?= $page === 'dashboard' ? 'active' : '' ?>" href="admin.php?p=dashboard"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
        <li class="nav-item"><a class="nav-link <?= $page === 'companies' ? 'active' : '' ?>" href="admin.php?p=companies"><i class="bi bi-building"></i> Firme</a></li>
        <li class="nav-item"><a class="nav-link <?= $page === 'users' ? 'active' : '' ?>" href="admin.php?p=users"><i class="bi bi-people"></i> Utilizatori</a></li>
        <li class="nav-item"><a class="nav-link <?= $page === 'plans' ? 'active' : '' ?>" href="admin.php?p=plans"><i class="bi bi-credit-card"></i> Abonamente</a></li>
        <li class="nav-item"><a class="nav-link <?= $page === 'rbac' ? 'active' : '' ?>" href="admin.php?p=rbac"><i class="bi bi-key"></i> RBAC</a></li>
        <li class="nav-item"><a class="nav-link <?= $page === 'audit' ? 'active' : '' ?>" href="admin.php?p=audit"><i class="bi bi-journal-text"></i> Audit</a></li>
        <li class="nav-item"><a class="nav-link" href="index.php?p=select_company"><i class="bi bi-box-arrow-left"></i> Ieși din admin</a></li>
      </ul>
    </div>
  </div>
</nav>

<main class="cf-admin-main container-fluid px-3 px-lg-4 py-4 cf-fade-in">
  <?php foreach (cashflow_flash_pull() as $flash): ?>
    <div class="alert alert-<?= cashflow_e($flash['type']) ?> alert-dismissible fade show" role="alert">
      <?= cashflow_e($flash['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endforeach; ?>
