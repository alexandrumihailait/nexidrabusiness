<?php
/** @var PDO $pdo */

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cashflow_csrf_check();
    $email = trim($_POST['email'] ?? '');
    $password = (string)($_POST['password'] ?? '');

    try {
        $user = $email !== '' && $password !== '' ? cashflow_attempt_login($pdo, $email, $password) : null;

        if ($user) {
            cashflow_audit($pdo, (int)$user['id'], null, null, 'login', 'session');
            header('Location: index.php?p=select_company');
            exit;
        }

        $error = 'Email sau parolă incorectă.';
    } catch (CashflowLoginThrottledException $e) {
        $error = 'Prea multe încercări eșuate. Reîncearcă în câteva minute.';
    }
}
?><!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Autentificare · Cashflow</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><rect width='24' height='24' rx='6' fill='%234f46e5'/><path d='M5 16l4-4 3 3 6-7' stroke='white' stroke-width='2' fill='none' stroke-linecap='round' stroke-linejoin='round'/></svg>">
<link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/style.css" rel="stylesheet">
</head>
<body class="cf-login-wrap">
  <div class="cf-login-card cf-fade-in">
    <div class="text-center mb-4">
      <span class="cf-brand-icon mb-3"><i class="bi bi-graph-up-arrow"></i></span>
      <h4 class="fw-bold mt-3 mb-0">Cashflow</h4>
      <p class="text-muted small">Management financiar multi-firmă / multi-centru de profit</p>
    </div>
    <div class="cf-card p-4">
      <?php if ($error): ?>
        <div class="alert alert-danger py-2 small mb-3"><i class="bi bi-exclamation-triangle-fill me-1"></i> <?= cashflow_e($error) ?></div>
      <?php endif; ?>
      <form method="post" action="index.php?p=login">
        <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
        <div class="mb-3">
          <label class="form-label small fw-bold">Email</label>
          <input type="email" name="email" class="form-control" required autofocus>
        </div>
        <div class="mb-3">
          <label class="form-label small fw-bold">Parolă</label>
          <input type="password" name="password" class="form-control" required>
        </div>
        <button type="submit" class="btn btn-primary w-100 fw-bold py-2">Autentificare</button>
      </form>
    </div>
  </div>
</body>
</html>
