<?php
/**
 * @var PDO $pdo
 * @var array $company
 * @var array $accessibleProfitCenters
 * @var bool $isCompanyAdmin
 */

require_once __DIR__ . '/../lib/storage.php';
require_once __DIR__ . '/../lib/integrations/common.php';
require_once __DIR__ . '/../lib/integrations/google_drive.php';

$companyId = (int)$company['id'];
$companyRoleArr = ['role_code' => $company['role_code'], 'role_name' => $company['role_name']];
$subscription = cashflow_get_company_subscription($pdo, $companyId);

cashflow_require_permission($pdo, $userId, $companyId, 'documents.upload', $companyRoleArr);

$writableCenters = array_values(array_filter(
    $accessibleProfitCenters,
    fn ($pc) => in_array($pc['access_level'], ['read_write', 'full'], true)
));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cashflow_csrf_check();

    if (($_POST['do'] ?? '') === 'upload') {
        $pcId = (int)($_POST['profit_center_id'] ?? 0);
        if ($pcId > 0) {
            cashflow_require_profit_center_access($pdo, $userId, $companyId, $pcId, 'read_write', $companyRoleArr);
        }
        $type = in_array($_POST['type'] ?? '', ['invoice_in', 'invoice_out', 'other'], true) ? $_POST['type'] : 'other';

        if (!cashflow_check_and_increment_usage($pdo, $companyId, 'documents', 'max_documents_month')) {
            cashflow_flash_set('danger', 'Ai atins limita lunară de documente a planului curent. Contactează administratorul platformei pentru upgrade.');
        } else {
            try {
                [$storedFilename, $mime, $size] = cashflow_store_uploaded_file($companyId, $_FILES['file'] ?? []);
                $ins = $pdo->prepare(
                    "INSERT INTO cf_documents (company_id, profit_center_id, uploaded_by, type, original_filename, stored_filename, mime_type, size_bytes, source)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'manual')"
                );
                $ins->execute([
                    $companyId, $pcId ?: null, $userId, $type,
                    substr(basename($_FILES['file']['name'] ?? 'document'), 0, 255),
                    $storedFilename, $mime, $size,
                ]);
                $docId = (int)$pdo->lastInsertId();
                cashflow_audit($pdo, $userId, $companyId, $pcId ?: null, 'upload', 'document', $docId);
                cashflow_flash_set('success', 'Documentul a fost încărcat.');
            } catch (Throwable $e) {
                cashflow_flash_set('danger', $e->getMessage());
            }
        }
    }

    if (($_POST['do'] ?? '') === 'send_to_drive') {
        $docId = (int)($_POST['id'] ?? 0);
        $stmt = $pdo->prepare("SELECT * FROM cf_documents WHERE id = ? AND company_id = ?");
        $stmt->execute([$docId, $companyId]);
        $doc = $stmt->fetch();

        $drive = cashflow_integration_get($pdo, $companyId, 'google_drive');
        if (!$doc) {
            cashflow_flash_set('danger', 'Document inexistent.');
        } elseif (!cashflow_plan_has_feature($subscription, 'google_drive')) {
            cashflow_flash_set('danger', 'Planul curent nu include Google Drive.');
        } elseif (!$drive || $drive['status'] !== 'connected' || empty($drive['access_token'])) {
            cashflow_flash_set('danger', 'Google Drive nu este conectat. Configurează integrarea din Administrare → Integrări.');
        } else {
            try {
                if ($doc['profit_center_id']) {
                    cashflow_require_profit_center_access($pdo, $userId, $companyId, (int)$doc['profit_center_id'], 'read', $companyRoleArr);
                }
                $accessToken = $drive['access_token'];
                if (!empty($drive['token_expires_at']) && strtotime($drive['token_expires_at']) < time() && !empty($drive['refresh_token'])) {
                    $refreshed = cashflow_google_refresh($drive['config'], $drive['refresh_token']);
                    $accessToken = $refreshed['access_token'];
                    cashflow_integration_save_tokens($pdo, $companyId, 'google_drive', $accessToken, null, isset($refreshed['expires_in']) ? date('Y-m-d H:i:s', time() + (int)$refreshed['expires_in']) : null);
                }

                $content = file_get_contents(cashflow_document_path($companyId, $doc['stored_filename']));
                $uploaded = cashflow_google_drive_upload($accessToken, $doc['original_filename'], $doc['mime_type'], $content, $drive['config']['folder_id'] ?? null);

                cashflow_audit($pdo, $userId, $companyId, $doc['profit_center_id'], 'send_to_drive', 'document', $docId, ['drive_file_id' => $uploaded['id'] ?? null]);
                cashflow_flash_set('success', 'Documentul a fost trimis pe Google Drive.');
            } catch (Throwable $e) {
                cashflow_integration_set_status($pdo, $companyId, 'google_drive', 'error', $e->getMessage());
                cashflow_flash_set('danger', $e->getMessage());
            }
        }
    }

    header('Location: ' . cashflow_url('documents'));
    exit;
}

$filterPcIds = array_map('intval', array_column($accessibleProfitCenters, 'id'));
$documents = [];
if (!empty($filterPcIds)) {
    $placeholders = implode(',', array_fill(0, count($filterPcIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT d.*, pc.name AS pc_name, u.name AS uploader_name
         FROM cf_documents d
         LEFT JOIN cf_profit_centers pc ON pc.id = d.profit_center_id
         JOIN cf_users u ON u.id = d.uploaded_by
         WHERE d.company_id = ? AND (d.profit_center_id IS NULL OR d.profit_center_id IN ($placeholders))
         ORDER BY d.created_at DESC LIMIT 200"
    );
    $stmt->execute(array_merge([$companyId], $filterPcIds));
    $documents = $stmt->fetchAll();
}

$usage = cashflow_get_usage($pdo, $companyId, 'documents');
$limit = (int)$subscription['max_documents_month'];
$typeLabels = ['invoice_in' => 'Factură primită', 'invoice_out' => 'Factură emisă', 'other' => 'Alt document'];

$driveIntegration = cashflow_integration_get($pdo, $companyId, 'google_drive');
$driveReady = cashflow_plan_has_feature($subscription, 'google_drive') && $driveIntegration && $driveIntegration['status'] === 'connected';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
  <h4 class="fw-bold mb-0"><i class="bi bi-file-earmark-arrow-up"></i> Documente</h4>
  <span class="badge bg-light text-dark border">Utilizare lună curentă: <?= $usage ?> / <?= $limit > 0 ? $limit : 'nelimitat' ?></span>
</div>

<div class="cf-card p-3 mb-4">
  <h6 class="fw-bold mb-3">Încarcă document</h6>
  <form method="post" action="<?= cashflow_url('documents') ?>" enctype="multipart/form-data" class="row g-3">
    <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
    <input type="hidden" name="do" value="upload">
    <div class="col-md-3">
      <label class="form-label small fw-bold">Centru de profit (opțional)</label>
      <select name="profit_center_id" class="form-select">
        <option value="0">Firmă (general)</option>
        <?php foreach ($writableCenters as $pc): ?><option value="<?= (int)$pc['id'] ?>"><?= cashflow_e($pc['name']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-3">
      <label class="form-label small fw-bold">Tip document</label>
      <select name="type" class="form-select">
        <?php foreach ($typeLabels as $val => $label): ?><option value="<?= cashflow_e($val) ?>"><?= cashflow_e($label) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="col-md-4">
      <label class="form-label small fw-bold">Fișier (PDF, JPG, PNG, XML — max 10MB)</label>
      <input type="file" name="file" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.xml" required>
    </div>
    <div class="col-md-2 d-flex align-items-end"><button type="submit" class="btn btn-primary fw-bold w-100">Încarcă</button></div>
  </form>
</div>

<div class="cf-card p-0 overflow-hidden">
  <div class="table-responsive">
    <table class="table table-hover align-middle mb-0 small">
      <thead class="table-light text-uppercase text-muted">
        <tr><th class="ps-3">Fișier</th><th>Tip</th><th>Centru</th><th>Sursă</th><th>Încărcat de</th><th>Data</th><th class="pe-3 text-end">Acțiuni</th></tr>
      </thead>
      <tbody>
        <?php if (empty($documents)): ?><tr><td colspan="7" class="text-center py-5 text-muted">Niciun document încărcat.</td></tr><?php endif; ?>
        <?php foreach ($documents as $d): ?>
          <tr>
            <td class="ps-3 fw-bold"><i class="bi bi-file-earmark"></i> <?= cashflow_e($d['original_filename']) ?></td>
            <td><?= cashflow_e($typeLabels[$d['type']] ?? $d['type']) ?></td>
            <td><?= cashflow_e($d['pc_name'] ?: 'Firmă') ?></td>
            <td><span class="badge bg-light text-dark border"><?= cashflow_e($d['source']) ?></span></td>
            <td><?= cashflow_e($d['uploader_name']) ?></td>
            <td><?= date('d.m.Y H:i', strtotime($d['created_at'])) ?></td>
            <td class="pe-3 text-end">
              <a href="<?= cashflow_url('document_download', ['id' => $d['id']]) ?>" class="btn btn-sm btn-outline-primary" title="Descarcă"><i class="bi bi-download"></i></a>
              <?php if ($driveReady): ?>
                <form method="post" action="<?= cashflow_url('documents') ?>" class="d-inline">
                  <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
                  <input type="hidden" name="do" value="send_to_drive">
                  <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
                  <button type="submit" class="btn btn-sm btn-outline-secondary" title="Trimite pe Google Drive"><i class="bi bi-google"></i></button>
                </form>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
