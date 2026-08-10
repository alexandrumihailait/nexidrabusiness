<?php
/** @var PDO $pdo */

$companies = cashflow_user_companies($pdo, $userId);

if (count($companies) === 1 && empty($_GET['show'])) {
    header('Location: index.php?p=dashboard&cid=' . (int)$companies[0]['id']);
    exit;
}
?><!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Selectează firma · Cashflow</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><rect width='24' height='24' rx='6' fill='%234f46e5'/><path d='M5 16l4-4 3 3 6-7' stroke='white' stroke-width='2' fill='none' stroke-linecap='round' stroke-linejoin='round'/></svg>">
<link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/style.css" rel="stylesheet">
</head>
<body class="cf-login-wrap">
  <div style="width:100%; max-width: 720px;" class="cf-fade-in">
    <div class="text-center mb-4">
      <span class="cf-brand-icon mb-3"><i class="bi bi-building"></i></span>
      <h4 class="fw-bold mt-3 mb-0">Selectează firma</h4>
      <p class="text-muted small">Ai acces la <?= count($companies) ?> firm<?= count($companies) === 1 ? 'ă' : 'e' ?></p>
    </div>

    <?php if (empty($companies)): ?>
      <div class="alert alert-warning">Nu ai acces activ la nicio firmă. Contactează un administrator.</div>
    <?php endif; ?>

    <div class="row g-3 cf-stagger">
      <?php foreach ($companies as $c): ?>
        <div class="col-12 col-sm-6">
          <a class="cf-company-pick d-flex align-items-center gap-3" href="index.php?p=dashboard&cid=<?= (int)$c['id'] ?>">
            <?php if (!empty($c['logo'])): ?>
              <img src="<?= cashflow_e($c['logo']) ?>" alt="" style="width:44px;height:44px;object-fit:contain;border-radius:8px;">
            <?php else: ?>
              <span class="cf-pc-dot" style="background:#4f46e5;"><i class="bi bi-building"></i></span>
            <?php endif; ?>
            <span>
              <strong class="d-block"><?= cashflow_e($c['name']) ?></strong>
              <small class="text-muted">CUI: <?= cashflow_e($c['cui'] ?: '-') ?> · <?= cashflow_e($c['role_name']) ?></small>
            </span>
          </a>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="text-center mt-4">
      <a href="index.php?p=logout" class="small text-muted">Deconectare</a>
    </div>
  </div>
</body>
</html>
