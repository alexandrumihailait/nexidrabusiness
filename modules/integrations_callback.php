<?php
/**
 * OAuth2 start + callback handler for ANAF e-Factura and Google Drive.
 * Shared by both providers since the flow shape is identical
 * (authorization-code grant): build an authorize URL with a random
 * `state`, stash it in the session, redirect; on return, verify `state`
 * matches before ever exchanging the code for a token.
 *
 * @var PDO $pdo
 * @var array $company
 * @var bool $isCompanyAdmin
 */

require_once __DIR__ . '/../lib/http.php';
require_once __DIR__ . '/../lib/integrations/common.php';
require_once __DIR__ . '/../lib/integrations/anaf.php';
require_once __DIR__ . '/../lib/integrations/google_drive.php';

if (!$isCompanyAdmin) {
    cashflow_forbidden('Doar administratorii firmei pot conecta integrări.');
}

$companyId = (int)$company['id'];
$provider = $_GET['provider'] ?? '';
if (!in_array($provider, ['anaf_efactura', 'google_drive'], true)) {
    cashflow_flash_set('danger', 'Furnizor necunoscut.');
    header('Location: ' . cashflow_url('integrations'));
    exit;
}

$integration = cashflow_integration_get($pdo, $companyId, $provider);
$config = $integration['config'] ?? [];
$redirectUri = cashflow_oauth_redirect_uri($provider);

if (($_GET['action'] ?? '') === 'start') {
    if (empty($config['client_id'])) {
        cashflow_flash_set('danger', 'Configurează mai întâi client_id/client_secret.');
        header('Location: ' . cashflow_url('integrations'));
        exit;
    }

    $state = bin2hex(random_bytes(16));
    $_SESSION['cf_oauth_state'][$provider] = $state;

    $authorizeUrl = $provider === 'anaf_efactura'
        ? cashflow_anaf_efactura_authorize_url($config, $redirectUri, $state)
        : cashflow_google_authorize_url($config, $redirectUri, $state);

    header('Location: ' . $authorizeUrl);
    exit;
}

// --- Callback: provider redirected back with ?code=...&state=... ---
$returnedState = $_GET['state'] ?? '';
$expectedState = $_SESSION['cf_oauth_state'][$provider] ?? null;
unset($_SESSION['cf_oauth_state'][$provider]);

if (!empty($_GET['error'])) {
    cashflow_integration_set_status($pdo, $companyId, $provider, 'error', $_GET['error']);
    cashflow_flash_set('danger', 'Autorizare respinsă: ' . $_GET['error']);
    header('Location: ' . cashflow_url('integrations'));
    exit;
}

if (!$expectedState || !hash_equals($expectedState, $returnedState)) {
    cashflow_flash_set('danger', 'Parametrul state este invalid sau expirat — reia conectarea.');
    header('Location: ' . cashflow_url('integrations'));
    exit;
}

$code = $_GET['code'] ?? '';
if ($code === '') {
    cashflow_flash_set('danger', 'Cod de autorizare lipsă.');
    header('Location: ' . cashflow_url('integrations'));
    exit;
}

try {
    $tokenResponse = $provider === 'anaf_efactura'
        ? cashflow_anaf_efactura_exchange_code($config, $redirectUri, $code)
        : cashflow_google_exchange_code($config, $redirectUri, $code);

    $expiresAt = isset($tokenResponse['expires_in'])
        ? date('Y-m-d H:i:s', time() + (int)$tokenResponse['expires_in'])
        : null;

    cashflow_integration_save_tokens(
        $pdo,
        $companyId,
        $provider,
        $tokenResponse['access_token'],
        $tokenResponse['refresh_token'] ?? null,
        $expiresAt
    );
    cashflow_audit($pdo, $userId, $companyId, null, 'connect', 'integration', null, ['provider' => $provider]);
    cashflow_flash_set('success', 'Conectat cu succes.');
} catch (Throwable $e) {
    cashflow_integration_set_status($pdo, $companyId, $provider, 'error', $e->getMessage());
    cashflow_flash_set('danger', $e->getMessage());
}

header('Location: ' . cashflow_url('integrations'));
exit;
