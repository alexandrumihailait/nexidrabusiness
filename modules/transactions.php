<?php
/**
 * @var PDO $pdo
 * @var array $company
 * @var array|null $activeProfitCenter
 * @var array $accessibleProfitCenters
 * @var bool $isCompanyAdmin
 */

$companyId = (int)$company['id'];
$companyRoleArr = ['role_code' => $company['role_code'], 'role_name' => $company['role_name']];

$writableCenters = array_values(array_filter(
    $accessibleProfitCenters,
    fn ($pc) => in_array($pc['access_level'], ['read_write', 'full'], true)
));

// ---- Handle POST: create transaction / cancel transaction ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cashflow_csrf_check();

    if (($_POST['do'] ?? '') === 'cancel') {
        $txId = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM cf_transactions WHERE id = ? AND company_id = ? AND deleted_at IS NULL LIMIT 1");
        $stmt->execute([$txId, $companyId]);
        $tx = $stmt->fetch();

        if ($tx) {
            cashflow_require_profit_center_access($pdo, $userId, $companyId, (int)$tx['profit_center_id'], 'read_write', $companyRoleArr);
            $upd = $pdo->prepare("UPDATE cf_transactions SET status = 'cancelled', updated_by = ? WHERE id = ?");
            $upd->execute([$userId, $txId]);
            cashflow_audit($pdo, $userId, $companyId, (int)$tx['profit_center_id'], 'cancel', 'transaction', $txId);
            cashflow_flash_set('success', 'Tranzacția a fost anulată.');
        }
        header('Location: ' . cashflow_url('transactions'));
        exit;
    }

    if (($_POST['do'] ?? '') === 'create') {
        $pcId = (int)($_POST['profit_center_id'] ?? 0);
        $accessLevel = cashflow_require_profit_center_access($pdo, $userId, $companyId, $pcId, 'read_write', $companyRoleArr);

        $type = ($_POST['type'] ?? '') === 'income' ? 'income' : 'expense';
        $amount = (float)str_replace(',', '.', $_POST['amount'] ?? '0');
        $currency = strtoupper(substr(trim($_POST['currency'] ?? $company['currency']), 0, 3)) ?: $company['currency'];
        $exchangeRate = $currency === $company['currency'] ? 1.0 : (float)str_replace(',', '.', $_POST['exchange_rate'] ?? '1');
        $date = $_POST['transaction_date'] ?? date('Y-m-d');
        $accountId = (int)($_POST['account_id'] ?? 0);
        $description = trim($_POST['description'] ?? '');
        $invoiceNumber = trim($_POST['invoice_number'] ?? '') ?: null;
        $categoryName = trim($_POST['category'] ?? '');
        $partnerName = trim($_POST['partner'] ?? '');

        $errors = [];
        if ($amount <= 0) { $errors[] = 'Suma trebuie să fie pozitivă.'; }
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { $errors[] = 'Data este invalidă.'; }
        if ($exchangeRate <= 0) { $errors[] = 'Cursul de schimb este invalid.'; }

        $acctStmt = $pdo->prepare("SELECT id FROM cf_accounts WHERE id = ? AND company_id = ? AND status = 'active' LIMIT 1");
        $acctStmt->execute([$accountId, $companyId]);
        if (!$acctStmt->fetchColumn()) { $errors[] = 'Contul selectat nu este valid.'; }

        if (empty($errors)) {
            $categoryId = cashflow_resolve_category($pdo, $companyId, $type, $categoryName);
            $partnerId = cashflow_resolve_partner($pdo, $companyId, $partnerName);

            $newId = cashflow_create_transaction($pdo, [
                'company_id' => $companyId,
                'profit_center_id' => $pcId,
                'account_id' => $accountId,
                'user_id' => $userId,
                'type' => $type,
                'category_id' => $categoryId,
                'partner_id' => $partnerId,
                'amount' => $amount,
                'currency' => $currency,
                'exchange_rate' => $exchangeRate,
                'transaction_date' => $date,
                'description' => $description,
                'invoice_number' => $invoiceNumber,
            ]);

            cashflow_audit($pdo, $userId, $companyId, $pcId, 'create', 'transaction', $newId, [
                'type' => $type, 'amount' => $amount, 'currency' => $currency, 'amount_ron' => $amountRon,
            ]);

            cashflow_flash_set('success', 'Tranzacția a fost salvată.');
            header('Location: ' . cashflow_url('transactions', ['pc' => $pcId]));
            exit;
        }
    }
}

// ---- Build the list ----
$filterPcIds = $activeProfitCenter
    ? [(int)$activeProfitCenter['id']]
    : array_map('intval', array_column($accessibleProfitCenters, 'id'));

$typeFilter = in_array($_GET['type'] ?? '', ['income', 'expense'], true) ? $_GET['type'] : null;
$search = trim($_GET['q'] ?? '');

$transactions = [];
if (!empty($filterPcIds)) {
    $placeholders = implode(',', array_fill(0, count($filterPcIds), '?'));
    $sql = "SELECT t.*, pc.name AS pc_name, pc.color AS pc_color, pc.icon AS pc_icon,
                   a.name AS account_name, c.name AS category_name, p.name AS partner_name, u.name AS user_name
            FROM cf_transactions t
            JOIN cf_profit_centers pc ON pc.id = t.profit_center_id
            JOIN cf_accounts a ON a.id = t.account_id
            LEFT JOIN cf_categories c ON c.id = t.category_id
            LEFT JOIN cf_partners p ON p.id = t.partner_id
            JOIN cf_users u ON u.id = t.user_id
            WHERE t.company_id = ? AND t.profit_center_id IN ($placeholders) AND t.deleted_at IS NULL";
    $params = array_merge([$companyId], $filterPcIds);

    if ($typeFilter) {
        $sql .= " AND t.type = ?";
        $params[] = $typeFilter;
    }
    if ($search !== '') {
        $sql .= " AND (p.name LIKE ? OR t.description LIKE ? OR t.invoice_number LIKE ?)";
        $like = '%' . $search . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    $sql .= " ORDER BY t.transaction_date DESC, t.id DESC LIMIT 100";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();
}

$accounts = [];
$acctStmt = $pdo->prepare("SELECT * FROM cf_accounts WHERE company_id = ? AND status = 'active' ORDER BY name ASC");
$acctStmt->execute([$companyId]);
$accounts = $acctStmt->fetchAll();

$showForm = !empty($_GET['action']) && $_GET['action'] === 'new';
$defaultPcId = $activeProfitCenter && in_array($activeProfitCenter['access_level'], ['read_write', 'full'], true)
    ? (int)$activeProfitCenter['id']
    : ($writableCenters[0]['id'] ?? null);
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <h4 class="fw-bold mb-0"><i class="bi bi-arrow-left-right"></i> Tranzacții</h4>
  <div class="d-flex gap-2">
    <a href="<?= cashflow_url('export', ['type' => 'transactions']) ?>" class="btn btn-outline-secondary btn-sm fw-bold"><i class="bi bi-file-earmark-excel"></i> Export Excel</a>
    <?php if (!empty($writableCenters)): ?>
      <a href="<?= cashflow_url('transactions', ['action' => 'new']) ?>" class="btn btn-primary btn-sm fw-bold">
        <i class="bi bi-plus-circle"></i> Tranzacție nouă
      </a>
    <?php endif; ?>
  </div>
</div>

<?php if ($showForm && !empty($writableCenters)): ?>
<div class="cf-card p-3 mb-4">
  <h6 class="fw-bold mb-3">Tranzacție nouă</h6>
  <form method="post" action="<?= cashflow_url('transactions') ?>" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
    <input type="hidden" name="do" value="create">

    <div class="col-12">
      <label class="form-label small fw-bold">Firma</label>
      <input type="text" class="form-control" value="<?= cashflow_e($company['name']) ?>" disabled>
    </div>

    <div class="col-md-4">
      <label class="form-label small fw-bold">Centru de profit</label>
      <select name="profit_center_id" class="form-select" required>
        <?php foreach ($writableCenters as $pc): ?>
          <option value="<?= (int)$pc['id'] ?>" <?= $defaultPcId === (int)$pc['id'] ? 'selected' : '' ?>><?= cashflow_e($pc['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-4">
      <label class="form-label small fw-bold">Tip</label>
      <select name="type" class="form-select" required>
        <option value="expense">Plată / Cheltuială</option>
        <option value="income">Încasare / Venit</option>
      </select>
    </div>

    <div class="col-md-4">
      <label class="form-label small fw-bold">Cont</label>
      <select name="account_id" class="form-select" required>
        <?php foreach ($accounts as $acc): ?>
          <option value="<?= (int)$acc['id'] ?>"><?= cashflow_e($acc['name']) ?> (<?= cashflow_e($acc['currency']) ?>)</option>
        <?php endforeach; ?>
      </select>
    </div>

    <div class="col-md-3">
      <label class="form-label small fw-bold">Sumă</label>
      <input type="text" name="amount" class="form-control" required placeholder="0.00">
    </div>
    <div class="col-md-2">
      <label class="form-label small fw-bold">Monedă</label>
      <input type="text" name="currency" class="form-control" value="<?= cashflow_e($company['currency']) ?>" maxlength="3">
    </div>
    <div class="col-md-2">
      <label class="form-label small fw-bold">Curs (dacă ≠ <?= cashflow_e($company['currency']) ?>)</label>
      <input type="text" name="exchange_rate" class="form-control" value="1">
    </div>
    <div class="col-md-3">
      <label class="form-label small fw-bold">Categorie</label>
      <input type="text" name="category" class="form-control" placeholder="ex: Materiale" list="catList">
      <datalist id="catList">
        <?php
        $catStmt = $pdo->prepare("SELECT DISTINCT name FROM cf_categories WHERE company_id = ?");
        $catStmt->execute([$companyId]);
        foreach ($catStmt->fetchAll() as $cat): ?>
          <option value="<?= cashflow_e($cat['name']) ?>">
        <?php endforeach; ?>
      </datalist>
    </div>
    <div class="col-md-2">
      <label class="form-label small fw-bold">Data</label>
      <input type="date" name="transaction_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
    </div>

    <div class="col-md-6">
      <label class="form-label small fw-bold">Client / Furnizor</label>
      <input type="text" name="partner" class="form-control" placeholder="Nume partener" list="partnerList">
      <datalist id="partnerList">
        <?php
        $pStmt = $pdo->prepare("SELECT DISTINCT name FROM cf_partners WHERE company_id = ?");
        $pStmt->execute([$companyId]);
        foreach ($pStmt->fetchAll() as $p): ?>
          <option value="<?= cashflow_e($p['name']) ?>">
        <?php endforeach; ?>
      </datalist>
    </div>
    <div class="col-md-3">
      <label class="form-label small fw-bold">Nr. factură</label>
      <input type="text" name="invoice_number" class="form-control">
    </div>
    <div class="col-12">
      <label class="form-label small fw-bold">Descriere</label>
      <input type="text" name="description" class="form-control">
    </div>

    <div class="col-12">
      <button type="submit" class="btn btn-primary fw-bold"><i class="bi bi-check-circle"></i> Salvează</button>
      <a href="<?= cashflow_url('transactions') ?>" class="btn btn-outline-secondary">Renunță</a>
    </div>
  </form>
</div>
<?php elseif ($showForm): ?>
  <div class="alert alert-warning">Nu ai drept de scriere în niciun centru de profit din această firmă.</div>
<?php endif; ?>

<div class="cf-card p-3 mb-3">
  <form method="get" class="row g-2 align-items-end">
    <input type="hidden" name="p" value="transactions">
    <div class="col-auto">
      <label class="small fw-bold text-muted mb-1">Tip</label>
      <select name="type" class="form-select form-select-sm" onchange="this.form.submit()">
        <option value="">Toate</option>
        <option value="income" <?= $typeFilter === 'income' ? 'selected' : '' ?>>Încasări</option>
        <option value="expense" <?= $typeFilter === 'expense' ? 'selected' : '' ?>>Plăți</option>
      </select>
    </div>
    <div class="col-auto flex-grow-1">
      <label class="small fw-bold text-muted mb-1">Caută (partener, descriere, factură)</label>
      <input type="text" name="q" class="form-control form-control-sm" value="<?= cashflow_e($search) ?>">
    </div>
    <div class="col-auto">
      <button class="btn btn-sm btn-dark fw-bold" type="submit"><i class="bi bi-search"></i></button>
    </div>
  </form>
</div>

<div class="cf-card p-0 overflow-hidden">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
      <thead class="table-light small text-uppercase text-muted">
        <tr>
          <th class="ps-3">Data</th>
          <?php if (!$activeProfitCenter): ?><th>Centru</th><?php endif; ?>
          <th>Tip</th>
          <th>Categorie</th>
          <th>Partener</th>
          <th>Cont</th>
          <th class="text-end">Sumă</th>
          <th>User</th>
          <th class="text-end pe-3">Acțiuni</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($transactions)): ?>
          <tr><td colspan="9" class="text-center py-5 text-muted">Nicio tranzacție găsită.</td></tr>
        <?php endif; ?>
        <?php foreach ($transactions as $tx): ?>
          <tr class="<?= $tx['status'] === 'cancelled' ? 'text-muted text-decoration-line-through' : '' ?>">
            <td class="ps-3"><?= date('d.m.Y', strtotime($tx['transaction_date'])) ?></td>
            <?php if (!$activeProfitCenter): ?>
              <td><i class="bi <?= cashflow_e($tx['pc_icon']) ?>" style="color: <?= cashflow_e($tx['pc_color']) ?>"></i> <?= cashflow_e($tx['pc_name']) ?></td>
            <?php endif; ?>
            <td>
              <?php if ($tx['type'] === 'income'): ?>
                <span class="badge bg-success-subtle text-success border border-success">Încasare</span>
              <?php else: ?>
                <span class="badge bg-danger-subtle text-danger border border-danger">Plată</span>
              <?php endif; ?>
            </td>
            <td class="small"><?= cashflow_e($tx['category_name'] ?: '-') ?></td>
            <td class="small"><?= cashflow_e($tx['partner_name'] ?: '-') ?></td>
            <td class="small"><?= cashflow_e($tx['account_name']) ?></td>
            <td class="text-end fw-bold"><?= ($tx['type'] === 'income' ? '+' : '-') . cashflow_money((float)$tx['amount'], $tx['currency']) ?></td>
            <td class="small text-muted"><?= cashflow_e($tx['user_name']) ?></td>
            <td class="text-end pe-3">
              <?php if ($tx['status'] !== 'cancelled'): ?>
                <a href="<?= cashflow_url('allocations', ['tx' => $tx['id']]) ?>" class="btn btn-sm btn-outline-secondary" title="Alocă pe centre de profit"><i class="bi bi-diagram-2"></i></a>
                <form method="post" action="<?= cashflow_url('transactions') ?>" onsubmit="return confirm('Anulezi această tranzacție?')" class="d-inline">
                  <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
                  <input type="hidden" name="do" value="cancel">
                  <input type="hidden" name="id" value="<?= (int)$tx['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-danger"><i class="bi bi-x-lg"></i></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
