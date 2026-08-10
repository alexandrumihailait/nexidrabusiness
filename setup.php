<?php
/**
 * First-run bootstrap: creates the very first platform admin account.
 * There is no other way to reach admin access from a fresh deploy without
 * either CLI access (php seed.php, often unavailable on shared hosting)
 * or raw SQL (DEPLOY.md's manual fallback) -- this page exists so a
 * normal web-only deploy still has a path to the admin panel.
 *
 * Self-disabling: the moment ANY user has is_platform_admin = 1, this
 * page refuses to do anything but show a "already configured" message,
 * checked fresh against the database on every request. Visit it once,
 * right after deploying, then it's permanently inert.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/access.php';
require_once __DIR__ . '/lib/helpers.php';

$pdo = cashflow_db();
cashflow_migrate($pdo);

$adminExists = (int)$pdo->query("SELECT COUNT(*) FROM cf_users WHERE is_platform_admin = 1")->fetchColumn() > 0;
$error = null;

if (!$adminExists && $_SERVER['REQUEST_METHOD'] === 'POST') {
    cashflow_csrf_check();
    $name = trim($_POST['name'] ?? '');
    $email = trim(strtolower($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 8) {
        $error = 'Completează numele, un email valid și o parolă de minim 8 caractere.';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM cf_users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetchColumn()) {
            $error = 'Există deja un cont cu acest email. Dacă e contul tău, cere unui administrator existent să-ți acorde acces, sau vezi DEPLOY.md.';
        } else {
            // Narrow (not eliminate) the race window: re-check right before insert.
            $adminExists = (int)$pdo->query("SELECT COUNT(*) FROM cf_users WHERE is_platform_admin = 1")->fetchColumn() > 0;
            if ($adminExists) {
                $error = 'Un administrator de platformă a fost deja creat între timp.';
            } else {
                $ins = $pdo->prepare("INSERT INTO cf_users (name, email, password_hash, is_platform_admin) VALUES (?, ?, ?, 1)");
                $ins->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT)]);
                $newId = (int)$pdo->lastInsertId();
                cashflow_audit($pdo, $newId, null, null, 'setup_create_platform_admin', 'user', $newId);

                session_regenerate_id(true);
                $_SESSION['cf_user_id'] = $newId;
                header('Location: admin.php');
                exit;
            }
        }
    }
}
?><!doctype html>
<html lang="ro">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Configurare inițială · Cashflow</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24'><rect width='24' height='24' rx='6' fill='%234f46e5'/><path d='M5 16l4-4 3 3 6-7' stroke='white' stroke-width='2' fill='none' stroke-linecap='round' stroke-linejoin='round'/></svg>">
<link href="assets/vendor/bootstrap/css/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<link href="assets/style.css" rel="stylesheet">
</head>
<body class="cf-login-wrap">
  <div class="cf-login-card cf-fade-in" style="max-width: 440px;">
    <div class="text-center mb-4">
      <span class="cf-brand-icon mb-3"><i class="bi bi-shield-lock"></i></span>
      <h4 class="fw-bold mt-3 mb-0">Configurare inițială</h4>
      <p class="text-muted small">Creează primul cont de administrator al platformei</p>
    </div>
    <div class="cf-card p-4">
      <?php if ($adminExists): ?>
        <div class="alert alert-success py-2 small mb-3"><i class="bi bi-check-circle"></i> Platforma este deja configurată — există cel puțin un administrator.</div>
        <a href="index.php?p=login" class="btn btn-primary w-100 fw-bold">Mergi la autentificare</a>
      <?php else: ?>
        <?php if ($error): ?>
          <div class="alert alert-danger py-2 small"><?= cashflow_e($error) ?></div>
        <?php endif; ?>
        <form method="post" action="setup.php">
          <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
          <div class="mb-3">
            <label class="form-label small fw-bold">Nume</label>
            <input type="text" name="name" class="form-control" required autofocus>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-bold">Email</label>
            <input type="email" name="email" class="form-control" required>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-bold">Parolă (minim 8 caractere)</label>
            <input type="password" name="password" class="form-control" minlength="8" required>
          </div>
          <button type="submit" class="btn btn-primary w-100 fw-bold">Creează contul de administrator</button>
        </form>
        <p class="text-muted small mt-3 mb-0">Această pagină se dezactivează automat imediat ce contul este creat.</p>
      <?php endif; ?>
    </div>
  </div>
</body>
</html>
