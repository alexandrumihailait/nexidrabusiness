<?php
require_once __DIR__ . '/../crypto.php';

/**
 * Shared storage helpers for per-company third-party integration state
 * (cf_company_integrations). One row per (company, provider); `config`
 * (client_id/client_secret/etc) and both OAuth tokens are encrypted at
 * rest with cashflow_encrypt()/cashflow_decrypt() -- a DB dump alone is
 * not enough to reuse these credentials. Never re-render a decrypted
 * secret back into a settings form; only show a masked placeholder.
 */

function cashflow_integration_get(PDO $pdo, int $companyId, string $provider): ?array
{
    $stmt = $pdo->prepare("SELECT * FROM cf_company_integrations WHERE company_id = ? AND provider = ? LIMIT 1");
    $stmt->execute([$companyId, $provider]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $configJson = cashflow_decrypt($row['config']);
    $row['config'] = $configJson ? json_decode($configJson, true) : [];
    $row['access_token'] = cashflow_decrypt($row['access_token']);
    $row['refresh_token'] = cashflow_decrypt($row['refresh_token']);
    return $row;
}

function cashflow_integration_save_config(PDO $pdo, int $companyId, string $provider, array $config, string $environment = 'test'): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO cf_company_integrations (company_id, provider, environment, config, status)
         VALUES (?, ?, ?, ?, 'disconnected')
         ON DUPLICATE KEY UPDATE environment = VALUES(environment), config = VALUES(config)"
    );
    $stmt->execute([$companyId, $provider, $environment, cashflow_encrypt(json_encode($config, JSON_UNESCAPED_UNICODE))]);
}

function cashflow_integration_save_tokens(PDO $pdo, int $companyId, string $provider, string $accessToken, ?string $refreshToken, ?string $expiresAt): void
{
    $stmt = $pdo->prepare(
        "UPDATE cf_company_integrations SET access_token = ?, refresh_token = COALESCE(?, refresh_token),
             token_expires_at = ?, status = 'connected', last_error = NULL
         WHERE company_id = ? AND provider = ?"
    );
    $stmt->execute([
        cashflow_encrypt($accessToken),
        $refreshToken !== null ? cashflow_encrypt($refreshToken) : null,
        $expiresAt,
        $companyId,
        $provider,
    ]);
}

function cashflow_integration_set_status(PDO $pdo, int $companyId, string $provider, string $status, ?string $error = null): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO cf_company_integrations (company_id, provider, status, last_error)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE status = VALUES(status), last_error = VALUES(last_error)"
    );
    $stmt->execute([$companyId, $provider, $status, $error]);
}

function cashflow_integration_disconnect(PDO $pdo, int $companyId, string $provider): void
{
    $stmt = $pdo->prepare(
        "UPDATE cf_company_integrations SET access_token = NULL, refresh_token = NULL, token_expires_at = NULL, status = 'disconnected'
         WHERE company_id = ? AND provider = ?"
    );
    $stmt->execute([$companyId, $provider]);
}
