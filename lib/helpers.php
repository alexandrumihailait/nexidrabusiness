<?php

function cashflow_money(float $amount, string $currency = 'RON'): string
{
    return number_format($amount, 2, ',', '.') . ' ' . $currency;
}

function cashflow_flash_set(string $type, string $message): void
{
    $_SESSION['cf_flash'][] = ['type' => $type, 'message' => $message];
}

function cashflow_flash_pull(): array
{
    $flash = $_SESSION['cf_flash'] ?? [];
    unset($_SESSION['cf_flash']);
    return $flash;
}

function cashflow_e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/** Builds a query string preserving the current cid/pc context, overriding given keys. */
function cashflow_url(string $page, array $params = []): string
{
    $base = [
        'p' => $page,
        'cid' => $_SESSION['cf_active_company_id'] ?? null,
    ];
    if (array_key_exists('pc', $params)) {
        // explicit override, including intentional clear to 'all'
    } elseif (!empty($_SESSION['cf_active_profit_center_id'])) {
        $base['pc'] = $_SESSION['cf_active_profit_center_id'];
    }
    $query = array_filter(array_merge($base, $params), fn ($v) => $v !== null && $v !== '');
    return 'index.php?' . http_build_query($query);
}

/** Absolute base URL of this app (scheme + host + path to the cashflow/ directory), used for OAuth redirect URIs. */
function cashflow_base_url(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') == 443 ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $dir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php')), '/');
    return "$scheme://$host$dir";
}

function cashflow_oauth_redirect_uri(string $provider): string
{
    return cashflow_base_url() . '/index.php?p=integrations_callback&provider=' . urlencode($provider);
}
