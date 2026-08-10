<?php
/**
 * @var PDO $pdo
 * @var array $company
 * @var bool $isCompanyAdmin
 */

if (!$isCompanyAdmin) {
    cashflow_forbidden('Doar administratorii firmei pot configura integrările.');
}

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/integrations/common.php';
require_once __DIR__ . '/../lib/integrations/anaf.php';
require_once __DIR__ . '/../lib/integrations/smartbill.php';

$companyId = (int)$company['id'];
$subscription = cashflow_get_company_subscription($pdo, $companyId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    cashflow_csrf_check();
    $action = $_POST['do'] ?? '';

    if ($action === 'save_anaf') {
        cashflow_integration_save_config($pdo, $companyId, 'anaf_efactura', [
            'client_id' => trim($_POST['client_id'] ?? ''),
            'client_secret' => trim($_POST['client_secret'] ?? ''),
            'cif' => trim($_POST['cif'] ?? ''),
        ], ($_POST['environment'] ?? 'test') === 'prod' ? 'prod' : 'test');
        cashflow_audit($pdo, $userId, $companyId, null, 'update', 'integration_config', null, ['provider' => 'anaf_efactura']);
        cashflow_flash_set('success', 'Configurația ANAF e-Factura a fost salvată. Apasă "Conectează" pentru a autoriza aplicația.');
    }

    if ($action === 'save_smartbill') {
        $smartbillUsername = trim($_POST['username'] ?? '');
        $smartbillToken = trim($_POST['token'] ?? '');
        cashflow_integration_save_config($pdo, $companyId, 'smartbill', ['username' => $smartbillUsername]);
        if ($smartbillToken !== '') {
            cashflow_integration_save_tokens($pdo, $companyId, 'smartbill', $smartbillToken, null, null);
        }
        cashflow_audit($pdo, $userId, $companyId, null, 'update', 'integration_config', null, ['provider' => 'smartbill']);
        cashflow_flash_set('success', 'Configurația SmartBill a fost salvată.');
    }

    if ($action === 'test_smartbill') {
        $integration = cashflow_integration_get($pdo, $companyId, 'smartbill');
        try {
            if (!$integration || empty($integration['config']['username']) || empty($integration['access_token'])) {
                throw new RuntimeException('Completează username și token înainte de a testa conexiunea.');
            }
            cashflow_smartbill_test_connection($integration['config']['username'], $integration['access_token']);
            cashflow_integration_set_status($pdo, $companyId, 'smartbill', 'connected');
            cashflow_flash_set('success', 'Conexiune SmartBill validă.');
        } catch (Throwable $e) {
            cashflow_integration_set_status($pdo, $companyId, 'smartbill', 'error', $e->getMessage());
            cashflow_flash_set('danger', $e->getMessage());
        }
    }

    if ($action === 'save_google') {
        cashflow_integration_save_config($pdo, $companyId, 'google_drive', [
            'client_id' => trim($_POST['client_id'] ?? ''),
            'client_secret' => trim($_POST['client_secret'] ?? ''),
            'folder_id' => trim($_POST['folder_id'] ?? ''),
        ]);
        cashflow_audit($pdo, $userId, $companyId, null, 'update', 'integration_config', null, ['provider' => 'google_drive']);
        cashflow_flash_set('success', 'Configurația Google Drive a fost salvată. Apasă "Conectează" pentru a autoriza accesul.');
    }

    if ($action === 'disconnect') {
        $provider = $_POST['provider'] ?? '';
        if (in_array($provider, ['anaf_efactura', 'smartbill', 'google_drive'], true)) {
            cashflow_integration_disconnect($pdo, $companyId, $provider);
            cashflow_audit($pdo, $userId, $companyId, null, 'disconnect', 'integration', null, ['provider' => $provider]);
            cashflow_flash_set('success', 'Integrarea a fost deconectată.');
        }
    }

    if ($action === 'lookup_cui') {
        $cui = trim($_POST['cui'] ?? '');
        if (!cashflow_plan_has_feature($subscription, 'anaf_lookup')) {
            cashflow_flash_set('danger', 'Planul curent nu include interogarea firmelor la ANAF.');
        } elseif (!cashflow_check_and_increment_usage($pdo, $companyId, 'anaf_lookups', 'max_anaf_lookups_month')) {
            cashflow_flash_set('danger', 'Ai atins limita lunară de interogări ANAF a planului curent.');
        } else {
            try {
                $lookupResult = cashflow_anaf_lookup_cui($cui);
                cashflow_audit($pdo, $userId, $companyId, null, 'anaf_lookup', 'cui', null, ['cui' => $cui, 'found' => (bool)$lookupResult]);
                $_SESSION['cf_last_lookup'] = $lookupResult ?: ['_not_found' => true, '_cui' => $cui];
            } catch (Throwable $e) {
                cashflow_flash_set('danger', $e->getMessage());
            }
        }
        header('Location: ' . cashflow_url('integrations'));
        exit;
    }

    header('Location: ' . cashflow_url('integrations'));
    exit;
}

$anafIntegration = cashflow_integration_get($pdo, $companyId, 'anaf_efactura');
$smartbillIntegration = cashflow_integration_get($pdo, $companyId, 'smartbill');
$googleIntegration = cashflow_integration_get($pdo, $companyId, 'google_drive');
$lookupResult = $_SESSION['cf_last_lookup'] ?? null;
unset($_SESSION['cf_last_lookup']);

$hasFeature = fn (string $code) => cashflow_plan_has_feature($subscription, $code);
?>

<h4 class="fw-bold mb-3"><i class="bi bi-plug"></i> Integrări</h4>

<!-- ANAF CUI lookup (interogare firme) -->
<div class="cf-card p-3 mb-4">
  <h6 class="fw-bold mb-1">Interogare firmă (CUI) — ANAF</h6>
  <p class="text-muted small">Serviciu public ANAF, fără credențiale necesare. Contorizat în limita planului: interogări ANAF/lună.</p>
  <?php if (!$hasFeature('anaf_lookup')): ?>
    <div class="alert alert-warning small mb-0">Planul curent nu include această funcționalitate.</div>
  <?php else: ?>
    <form method="post" action="<?= cashflow_url('integrations') ?>" class="d-flex gap-2 mb-3" style="max-width: 420px;">
      <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
      <input type="hidden" name="do" value="lookup_cui">
      <input type="text" name="cui" class="form-control" placeholder="ex: 12345678" required>
      <button type="submit" class="btn btn-primary fw-bold"><i class="bi bi-search"></i> Verifică</button>
    </form>
    <?php if ($lookupResult && empty($lookupResult['_not_found'])): ?>
      <div class="alert alert-light border small mb-0">
        <strong><?= cashflow_e($lookupResult['date_generale']['denumire'] ?? '-') ?></strong><br>
        CUI: <?= cashflow_e($lookupResult['date_generale']['cui'] ?? '-') ?> ·
        Adresă: <?= cashflow_e($lookupResult['date_generale']['adresa'] ?? '-') ?><br>
        Plătitor TVA: <?= !empty($lookupResult['inregistrare_scop_Tva']['scpTVA']) ? 'Da' : 'Nu' ?>
      </div>
    <?php elseif ($lookupResult): ?>
      <div class="alert alert-warning small mb-0">CUI <?= cashflow_e($lookupResult['_cui']) ?> nu a fost găsit.</div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<!-- ANAF e-Factura -->
<div class="cf-card p-3 mb-4">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
    <h6 class="fw-bold mb-0">ANAF e-Factura</h6>
    <?php $st = $anafIntegration['status'] ?? 'disconnected'; ?>
    <span class="badge bg-<?= $st === 'connected' ? 'success' : ($st === 'error' ? 'danger' : 'secondary') ?>-subtle text-<?= $st === 'connected' ? 'success' : ($st === 'error' ? 'danger' : 'secondary') ?> border"><?= cashflow_e($st) ?></span>
  </div>
  <?php if (!$hasFeature('anaf_efactura')): ?>
    <div class="alert alert-warning small mb-0">Planul curent nu include ANAF e-Factura.</div>
  <?php else: ?>
    <p class="text-muted small">
      Necesită o aplicație OAuth înregistrată în <a href="https://logincert.anaf.ro" target="_blank" rel="noopener">portalul ANAF</a> și un certificat digital calificat pentru autorizarea inițială.
      URL de redirecționare de înregistrat: <code><?= cashflow_e(cashflow_oauth_redirect_uri('anaf_efactura')) ?></code>
    </p>
    <form method="post" action="<?= cashflow_url('integrations') ?>" class="row g-3 mb-3">
      <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
      <input type="hidden" name="do" value="save_anaf">
      <div class="col-md-3"><label class="form-label small fw-bold">CIF firmă</label><input type="text" name="cif" class="form-control" value="<?= cashflow_e($anafIntegration['config']['cif'] ?? $company['cui'] ?? '') ?>"></div>
      <div class="col-md-3"><label class="form-label small fw-bold">Client ID</label><input type="text" name="client_id" class="form-control" value="<?= cashflow_e($anafIntegration['config']['client_id'] ?? '') ?>"></div>
      <div class="col-md-3"><label class="form-label small fw-bold">Client Secret</label><input type="password" name="client_secret" class="form-control" placeholder="<?= $anafIntegration && !empty($anafIntegration['config']['client_secret']) ? '••••••••' : '' ?>"></div>
      <div class="col-md-2">
        <label class="form-label small fw-bold">Mediu</label>
        <select name="environment" class="form-select">
          <option value="test" <?= ($anafIntegration['environment'] ?? 'test') === 'test' ? 'selected' : '' ?>>Test</option>
          <option value="prod" <?= ($anafIntegration['environment'] ?? '') === 'prod' ? 'selected' : '' ?>>Producție</option>
        </select>
      </div>
      <div class="col-md-1 d-flex align-items-end"><button type="submit" class="btn btn-primary w-100">Salvează</button></div>
    </form>
    <?php if (!empty($anafIntegration['config']['client_id'])): ?>
      <a href="<?= cashflow_url('integrations_callback', ['provider' => 'anaf_efactura', 'action' => 'start']) ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-link-45deg"></i> Conectează ANAF</a>
    <?php endif; ?>
    <?php if ($st === 'connected'): ?>
      <form method="post" action="<?= cashflow_url('integrations') ?>" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
        <input type="hidden" name="do" value="disconnect"><input type="hidden" name="provider" value="anaf_efactura">
        <button type="submit" class="btn btn-outline-danger btn-sm">Deconectează</button>
      </form>
    <?php endif; ?>
    <?php if (!empty($anafIntegration['last_error'])): ?><p class="text-danger small mt-2 mb-0"><?= cashflow_e($anafIntegration['last_error']) ?></p><?php endif; ?>
  <?php endif; ?>
</div>

<!-- SmartBill -->
<div class="cf-card p-3 mb-4">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
    <h6 class="fw-bold mb-0">SmartBill</h6>
    <?php $st = $smartbillIntegration['status'] ?? 'disconnected'; ?>
    <span class="badge bg-<?= $st === 'connected' ? 'success' : ($st === 'error' ? 'danger' : 'secondary') ?>-subtle text-<?= $st === 'connected' ? 'success' : ($st === 'error' ? 'danger' : 'secondary') ?> border"><?= cashflow_e($st) ?></span>
  </div>
  <?php if (!$hasFeature('smartbill')): ?>
    <div class="alert alert-warning small mb-0">Planul curent nu include SmartBill.</div>
  <?php else: ?>
    <p class="text-muted small">Token API generat din contul SmartBill: My account → Integrations → API.</p>
    <form method="post" action="<?= cashflow_url('integrations') ?>" class="row g-3 mb-2">
      <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
      <input type="hidden" name="do" value="save_smartbill">
      <div class="col-md-4"><label class="form-label small fw-bold">Username (email cont SmartBill)</label><input type="email" name="username" class="form-control" value="<?= cashflow_e($smartbillIntegration['config']['username'] ?? '') ?>"></div>
      <div class="col-md-4"><label class="form-label small fw-bold">API Token</label><input type="password" name="token" class="form-control" placeholder="<?= !empty($smartbillIntegration['access_token']) ? '••••••••' : '' ?>"></div>
      <div class="col-md-4 d-flex align-items-end gap-2">
        <button type="submit" class="btn btn-primary">Salvează</button>
        <button type="submit" name="do" value="test_smartbill" class="btn btn-outline-secondary">Testează</button>
      </div>
    </form>
    <?php if ($st === 'connected'): ?>
      <form method="post" action="<?= cashflow_url('integrations') ?>" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
        <input type="hidden" name="do" value="disconnect"><input type="hidden" name="provider" value="smartbill">
        <button type="submit" class="btn btn-outline-danger btn-sm">Deconectează</button>
      </form>
    <?php endif; ?>
    <?php if (!empty($smartbillIntegration['last_error'])): ?><p class="text-danger small mt-2 mb-0"><?= cashflow_e($smartbillIntegration['last_error']) ?></p><?php endif; ?>
  <?php endif; ?>
</div>

<!-- Google Drive -->
<div class="cf-card p-3">
  <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
    <h6 class="fw-bold mb-0">Google Drive</h6>
    <?php $st = $googleIntegration['status'] ?? 'disconnected'; ?>
    <span class="badge bg-<?= $st === 'connected' ? 'success' : ($st === 'error' ? 'danger' : 'secondary') ?>-subtle text-<?= $st === 'connected' ? 'success' : ($st === 'error' ? 'danger' : 'secondary') ?> border"><?= cashflow_e($st) ?></span>
  </div>
  <?php if (!$hasFeature('google_drive')): ?>
    <div class="alert alert-warning small mb-0">Planul curent nu include Google Drive.</div>
  <?php else: ?>
    <p class="text-muted small">
      Necesită un OAuth Client ID (tip Web application) dintr-un proiect Google Cloud cu Drive API activat.
      URL de redirecționare de înregistrat: <code><?= cashflow_e(cashflow_oauth_redirect_uri('google_drive')) ?></code>
    </p>
    <form method="post" action="<?= cashflow_url('integrations') ?>" class="row g-3 mb-2">
      <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
      <input type="hidden" name="do" value="save_google">
      <div class="col-md-4"><label class="form-label small fw-bold">Client ID</label><input type="text" name="client_id" class="form-control" value="<?= cashflow_e($googleIntegration['config']['client_id'] ?? '') ?>"></div>
      <div class="col-md-4"><label class="form-label small fw-bold">Client Secret</label><input type="password" name="client_secret" class="form-control" placeholder="<?= !empty($googleIntegration['config']['client_secret']) ? '••••••••' : '' ?>"></div>
      <div class="col-md-4"><label class="form-label small fw-bold">Folder ID (opțional)</label><input type="text" name="folder_id" class="form-control" value="<?= cashflow_e($googleIntegration['config']['folder_id'] ?? '') ?>"></div>
      <div class="col-12"><button type="submit" class="btn btn-primary">Salvează</button></div>
    </form>
    <?php if (!empty($googleIntegration['config']['client_id'])): ?>
      <a href="<?= cashflow_url('integrations_callback', ['provider' => 'google_drive', 'action' => 'start']) ?>" class="btn btn-outline-primary btn-sm"><i class="bi bi-link-45deg"></i> Conectează Google Drive</a>
    <?php endif; ?>
    <?php if ($st === 'connected'): ?>
      <form method="post" action="<?= cashflow_url('integrations') ?>" class="d-inline">
        <input type="hidden" name="csrf_token" value="<?= cashflow_e(cashflow_csrf_token()) ?>">
        <input type="hidden" name="do" value="disconnect"><input type="hidden" name="provider" value="google_drive">
        <button type="submit" class="btn btn-outline-danger btn-sm">Deconectează</button>
      </form>
    <?php endif; ?>
    <?php if (!empty($googleIntegration['last_error'])): ?><p class="text-danger small mt-2 mb-0"><?= cashflow_e($googleIntegration['last_error']) ?></p><?php endif; ?>
  <?php endif; ?>
</div>
