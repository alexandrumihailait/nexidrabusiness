<?php
/** @var PDO $pdo */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cashflow_csrf_check();
    $action = $_POST['do'] ?? '';

    if ($action === 'create') {
        $name = trim($_POST['name'] ?? '');
        $email = trim(strtolower($_POST['email'] ?? ''));
        $isPlatformAdmin = !empty($_POST['is_platform_admin']) ? 1 : 0;

        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            cashflow_flash_set('danger', 'Nume și email valide sunt obligatorii.');
        } else {
            $stmt = $pdo->prepare("SELECT id FROM cf_users WHERE email = ?");
            $stmt->execute([$email]);
            if ($stmt->fetchColumn()) {
                cashflow_flash_set('danger', 'Există deja un cont cu acest email.');
            } else {
                $tempPassword = bin2hex(random_bytes(8));
                $ins = $pdo->prepare("INSERT INTO cf_users (name, email, password_hash, is_platform_admin) VALUES (?, ?, ?, ?)");
                $ins->execute([$name, $email, password_hash($tempPassword, PASSWORD_DEFAULT), $isPlatformAdmin]);
                $newId = (int)$pdo->lastInsertId();
                cashflow_audit($pdo, $userId, null, null, 'create', 'user', $newId, ['platform_admin' => (bool)$isPlatformAdmin]);
                cashflow_flash_set('success', "Cont creat pentru $email — parolă temporară: $tempPassword (comunic-o în siguranță).");
            }
        }
    }

    if ($action === 'toggle_platform_admin') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id !== $userId) {
            $stmt = $pdo->prepare("SELECT is_platform_admin FROM cf_users WHERE id = ?");
            $stmt->execute([$id]);
            $cur = (int)$stmt->fetchColumn();
            $pdo->prepare("UPDATE cf_users SET is_platform_admin = ? WHERE id = ?")->execute([$cur ? 0 : 1, $id]);
            cashflow_audit($pdo, $userId, null, null, 'toggle_platform_admin', 'user', $id, ['value' => !$cur]);
            cashflow_flash_set('success', 'Statusul de administrator platformă a fost actualizat.');
        } else {
            cashflow_flash_set('danger', 'Nu îți poți revoca propriul acces de administrator platformă.');
        }
    }

    if ($action === 'toggle_status') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id !== $userId) {
            $stmt = $pdo->prepare("SELECT status FROM cf_users WHERE id = ?");
            $stmt->execute([$id]);
            if ($cur = $stmt->fetchColumn()) {
                $newStatus = $cur === 'active' ? 'inactive' : 'active';
                $pdo->prepare("UPDATE cf_users SET status = ? WHERE id = ?")->execute([$newStatus, $id]);
                cashflow_audit($pdo, $userId, null, null, 'status_change', 'user', $id, ['status' => $newStatus]);
                cashflow_flash_set('success', 'Statusul contului a fost actualizat.');
            }
        }
    }

    if ($action === 'reset_password') {
        $id = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT email FROM cf_users WHERE id = ?");
        $stmt->execute([$id]);
        if ($email = $stmt->fetchColumn()) {
            $tempPassword = bin2hex(random_bytes(8));
            $pdo->prepare("UPDATE cf_users SET password_hash = ? WHERE id = ?")->execute([password_hash($tempPassword, PASSWORD_DEFAULT), $id]);
            cashflow_audit($pdo, $userId, null, null, 'reset_password', 'user', $id);
            cashflow_flash_set('success', "Parolă resetată pentru $email — parolă temporară: $tempPassword (comunic-o în siguranță).");
        }
    }

    header('Location: admin.php?p=users');
    exit;
}

$search = trim($_GET['q'] ?? '');
$sql = "SELECT * FROM cf_users";
$params = [];
if ($search !== '') {
    $sql .= " WHERE name LIKE ? OR email LIKE ?";
    $like = '%' . $search . '%';
    $params = [$like, $like];
}
$sql .= " ORDER BY created_at DESC LIMIT 200";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$users = $stmt->fetchAll();

$companiesByUser = [];
if (!empty($users)) {
    $userIds = array_column($users, 'id');
    $placeholders = implode(',', array_fill(0, count($userIds), '?'));
    $cStmt = $pdo->prepare(
        "SELECT cu.user_id, c.name FROM cf_company_users cu JOIN cf_companies c ON c.id = cu.company_id
         WHERE cu.user_id IN ($placeholders) AND cu.status = 'active'"
    );
    $cStmt->execute($userIds);
    foreach ($cStmt->fetchAll() as $row) {
        $companiesByUser[$row['user_id']][] = $row['name'];
    }
}
?>

<h4 class="fw-bold mb-3"><i class="bi bi-people"></i> Utilizatori</h4>

<div class="cf-card p-3 mb-4">
  <h6 class="fw-bold mb-3">Cont nou</h6>
  <form method="post" action="admin.php?p=users" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
    <input type="hidden" name="do" value="create">
    <div class="col-md-3"><label class="form-label small fw-bold">Nume</label><input type="text" name="name" class="form-control" required></div>
    <div class="col-md-3"><label class="form-label small fw-bold">Email</label><input type="email" name="email" class="form-control" required></div>
    <div class="col-md-3 d-flex align-items-end">
      <div class="form-check">
        <input type="checkbox" name="is_platform_admin" value="1" class="form-check-input" id="isPlatformAdmin">
        <label class="form-check-label small fw-bold" for="isPlatformAdmin">Administrator platformă</label>
      </div>
    </div>
    <div class="col-md-3 d-flex align-items-end"><button type="submit" class="btn btn-primary fw-bold w-100">Creează contul</button></div>
  </form>
</div>

<form method="get" action="admin.php" class="mb-3">
  <input type="hidden" name="p" value="users">
  <div class="input-group" style="max-width: 360px;">
    <input type="text" name="q" class="form-control" placeholder="Caută nume/email..." value="<?= cashflow_e($search) ?>">
    <button class="btn btn-dark" type="submit"><i class="bi bi-search"></i></button>
  </div>
</form>

<div class="cf-card p-0 overflow-hidden">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 small">
      <thead class="table-light text-uppercase text-muted">
        <tr><th class="ps-3">Utilizator</th><th>Firme</th><th>Admin platformă</th><th>Status</th><th class="pe-3 text-end">Acțiuni</th></tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u): ?>
          <tr>
            <td class="ps-3 fw-bold"><?= cashflow_e($u['name']) ?><br><small class="text-muted fw-normal"><?= cashflow_e($u['email']) ?></small></td>
            <td><?= cashflow_e(implode(', ', $companiesByUser[$u['id']] ?? []) ?: '-') ?></td>
            <td><?= $u['is_platform_admin'] ? '<span class="badge bg-primary">DA</span>' : '<span class="text-muted">nu</span>' ?></td>
            <td><?= $u['status'] === 'active' ? '<span class="badge bg-success-subtle text-success border border-success">Activ</span>' : '<span class="badge bg-secondary-subtle text-secondary border">Inactiv</span>' ?></td>
            <td class="pe-3 text-end text-nowrap">
              <form method="post" action="admin.php?p=users" class="d-inline" onsubmit="return confirm('Resetezi parola acestui cont?')">
                <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
                <input type="hidden" name="do" value="reset_password">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Resetează parola"><i class="bi bi-key"></i></button>
              </form>
              <?php if ((int)$u['id'] !== $userId): ?>
                <form method="post" action="admin.php?p=users" class="d-inline">
                  <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
                  <input type="hidden" name="do" value="toggle_platform_admin">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-primary" title="Comută admin platformă"><i class="bi bi-shield"></i></button>
                </form>
                <form method="post" action="admin.php?p=users" class="d-inline">
                  <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
                  <input type="hidden" name="do" value="toggle_status">
                  <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-<?= $u['status'] === 'active' ? 'danger' : 'success' ?>"><i class="bi bi-<?= $u['status'] === 'active' ? 'pause' : 'play' ?>"></i></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
