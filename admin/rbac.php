<?php
/** @var PDO $pdo */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cashflow_csrf_check();
    $roleId = (int)($_POST['role_id'] ?? 0);
    $grantedPermissionIds = array_map('intval', $_POST['permissions'] ?? []);

    $roleCheck = $pdo->prepare("SELECT id FROM cf_roles WHERE id = ?");
    $roleCheck->execute([$roleId]);
    if ($roleCheck->fetchColumn()) {
        $pdo->prepare("DELETE FROM cf_role_permissions WHERE role_id = ?")->execute([$roleId]);
        $ins = $pdo->prepare("INSERT INTO cf_role_permissions (role_id, permission_id) VALUES (?, ?)");
        foreach ($grantedPermissionIds as $permId) {
            $ins->execute([$roleId, $permId]);
        }
        cashflow_audit($pdo, $userId, null, null, 'update_rbac_matrix', 'role', $roleId, ['permissions' => $grantedPermissionIds]);
        cashflow_flash_set('success', 'Matricea de permisiuni a fost actualizată.');
    }
    header('Location: admin.php?p=rbac');
    exit;
}

$roles = $pdo->query("SELECT * FROM cf_roles ORDER BY id ASC")->fetchAll();
$permissions = $pdo->query("SELECT * FROM cf_permissions ORDER BY permission_group ASC, label ASC")->fetchAll();

$grantsByRole = [];
$grantStmt = $pdo->query("SELECT role_id, permission_id FROM cf_role_permissions");
foreach ($grantStmt->fetchAll() as $g) {
    $grantsByRole[$g['role_id']][$g['permission_id']] = true;
}

$permGroups = [];
foreach ($permissions as $p) {
    $permGroups[$p['permission_group']][] = $p;
}
?>

<h4 class="fw-bold mb-3"><i class="bi bi-key"></i> RBAC — matrice roluri × permisiuni</h4>
<p class="text-muted small mb-4">
  Rolurile (<?= cashflow_e(implode(', ', array_column($roles, 'name'))) ?>) sunt partajate de toate firmele de pe platformă.
  Editarea de aici se aplică imediat tuturor firmelor care folosesc rolul respectiv.
</p>

<?php foreach ($roles as $role): ?>
  <div class="cf-card p-3 mb-3">
    <form method="post" action="admin.php?p=rbac">
      <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
      <input type="hidden" name="role_id" value="<?= (int)$role['id'] ?>">
      <h6 class="fw-bold mb-3"><?= cashflow_e($role['name']) ?> <small class="text-muted">(<?= cashflow_e($role['code']) ?>)</small></h6>
      <div class="row g-3">
        <?php foreach ($permGroups as $group => $perms): ?>
          <div class="col-md-4">
            <div class="small fw-bold text-uppercase text-muted mb-1"><?= cashflow_e($group) ?></div>
            <?php foreach ($perms as $perm): ?>
              <div class="form-check">
                <input type="checkbox" name="permissions[]" value="<?= (int)$perm['id'] ?>" class="form-check-input" id="p<?= $role['id'] ?>_<?= $perm['id'] ?>"
                  <?= isset($grantsByRole[$role['id']][$perm['id']]) ? 'checked' : '' ?>>
                <label class="form-check-label small" for="p<?= $role['id'] ?>_<?= $perm['id'] ?>"><?= cashflow_e($perm['label']) ?></label>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <button type="submit" class="btn btn-sm btn-primary fw-bold mt-3">Salvează pentru <?= cashflow_e($role['name']) ?></button>
    </form>
  </div>
<?php endforeach; ?>
