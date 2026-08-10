<?php
/**
 * At-rest encryption for third-party credentials (integration client
 * secrets, OAuth tokens) stored in cf_company_integrations. AES-256-GCM
 * with a key derived from CASHFLOW_ENCRYPTION_KEY -- set that env var in
 * production (see DEPLOY.md). Without it, a fixed fallback key is used so
 * local/dev setups keep working, but every request logs a warning since
 * that offers no real protection.
 */

function cashflow_encryption_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }

    $configured = getenv('CASHFLOW_ENCRYPTION_KEY');
    if (!$configured) {
        error_log('[cashflow] CASHFLOW_ENCRYPTION_KEY is not set -- integration secrets are encrypted with an insecure fallback key. Set this env var in production.');
        $configured = 'cashflow-insecure-default-key-set-CASHFLOW_ENCRYPTION_KEY';
    }

    $key = hash('sha256', $configured, true);
    return $key;
}

function cashflow_encrypt(string $plaintext): string
{
    $iv = random_bytes(12);
    $tag = '';
    $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', cashflow_encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return base64_encode($iv . $tag . $ciphertext);
}

function cashflow_decrypt(?string $encoded): ?string
{
    if ($encoded === null || $encoded === '') {
        return null;
    }
    $raw = base64_decode($encoded, true);
    if ($raw === false || strlen($raw) < 28) {
        return null;
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $ciphertext = substr($raw, 28);
    $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', cashflow_encryption_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $plaintext === false ? null : $plaintext;
}
