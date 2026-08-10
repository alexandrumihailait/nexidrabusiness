<?php
/**
 * ANAF integrations. Two independent APIs, commonly confused:
 *
 * 1. Public VAT/company lookup (v9 TVA) -- free, no credentials, rate
 *    limited by ANAF to ~1 req/sec / 100 CUIs per batch. This is the
 *    "interoghează firma după CUI" feature, metered against the
 *    company's max_anaf_lookups_month plan limit.
 *
 * 2. e-Factura -- OAuth2 (authorization-code flow, requires a qualified
 *    digital certificate to grant the initial consent) + REST endpoints
 *    to upload/track/download invoices. This is the "urcă facturi la
 *    ANAF" feature. Requires the company to register an OAuth app in
 *    ANAF's developer portal (https://logincert.anaf.ro) and complete the
 *    consent step themselves -- nothing here can substitute that.
 */

const CASHFLOW_ANAF_LOOKUP_URL = 'https://webservicesp.anaf.ro/api/PlatitorTvaRest/v9/tva';

const CASHFLOW_ANAF_OAUTH_AUTHORIZE_URL = 'https://logincert.anaf.ro/anaf-oauth2/v1/authorize';
const CASHFLOW_ANAF_OAUTH_TOKEN_URL = 'https://logincert.anaf.ro/anaf-oauth2/v1/token';

function cashflow_anaf_efactura_base_url(string $environment): string
{
    return $environment === 'prod'
        ? 'https://webserviceapl.anaf.ro/prod/FCTEL/rest'
        : 'https://webserviceapl.anaf.ro/test/FCTEL/rest';
}

/**
 * Looks up one CUI against the public VAT registry. Returns the ANAF
 * response record (denumire, adresa, VAT status, etc.) or null if the CUI
 * was not found. Throws on transport/HTTP failure -- callers should catch
 * and surface a friendly error, this never silently returns stale data.
 */
function cashflow_anaf_lookup_cui(string $cui, ?string $date = null): ?array
{
    $cui = preg_replace('/\D/', '', $cui);
    if ($cui === '') {
        throw new InvalidArgumentException('CUI invalid.');
    }
    $date = $date ?? date('Y-m-d');

    $result = cashflow_http_json('POST', CASHFLOW_ANAF_LOOKUP_URL, [], [
        ['cui' => (int)$cui, 'data' => $date],
    ], 15);

    if (!$result['ok']) {
        throw new RuntimeException('ANAF nu a răspuns: ' . ($result['error'] ?? 'eroare necunoscută'));
    }

    $json = $result['json'];
    if (!$json || empty($json['found'][0])) {
        return null;
    }

    return $json['found'][0];
}

function cashflow_anaf_efactura_authorize_url(array $config, string $redirectUri, string $state): string
{
    return CASHFLOW_ANAF_OAUTH_AUTHORIZE_URL . '?' . http_build_query([
        'response_type' => 'code',
        'client_id' => $config['client_id'] ?? '',
        'redirect_uri' => $redirectUri,
        'state' => $state,
        'token_content_type' => 'jwt',
    ]);
}

function cashflow_anaf_efactura_exchange_code(array $config, string $redirectUri, string $code): array
{
    $body = http_build_query([
        'grant_type' => 'authorization_code',
        'code' => $code,
        'client_id' => $config['client_id'] ?? '',
        'client_secret' => $config['client_secret'] ?? '',
        'redirect_uri' => $redirectUri,
    ]);

    $result = cashflow_http_request('POST', CASHFLOW_ANAF_OAUTH_TOKEN_URL, ['Content-Type: application/x-www-form-urlencoded'], $body);
    if (!$result['ok']) {
        throw new RuntimeException('Autorizarea ANAF a eșuat: ' . ($result['error'] ?? 'necunoscut'));
    }

    $json = json_decode($result['body'], true);
    if (!$json || empty($json['access_token'])) {
        throw new RuntimeException('Răspuns invalid de la ANAF la schimbul de token.');
    }
    return $json;
}

function cashflow_anaf_efactura_refresh(array $config, string $refreshToken): array
{
    $body = http_build_query([
        'grant_type' => 'refresh_token',
        'refresh_token' => $refreshToken,
        'client_id' => $config['client_id'] ?? '',
        'client_secret' => $config['client_secret'] ?? '',
    ]);

    $result = cashflow_http_request('POST', CASHFLOW_ANAF_OAUTH_TOKEN_URL, ['Content-Type: application/x-www-form-urlencoded'], $body);
    if (!$result['ok']) {
        throw new RuntimeException('Reîmprospătarea token-ului ANAF a eșuat: ' . ($result['error'] ?? 'necunoscut'));
    }
    $json = json_decode($result['body'], true);
    if (!$json || empty($json['access_token'])) {
        throw new RuntimeException('Răspuns invalid de la ANAF la refresh.');
    }
    return $json;
}

/** Uploads a UBL/CII invoice XML. Returns the parsed <header> response (index_incarcare, or errors). */
function cashflow_anaf_efactura_upload(string $accessToken, string $environment, string $cif, string $xmlContent, string $standard = 'UBL'): array
{
    $url = cashflow_anaf_efactura_base_url($environment) . '/upload?' . http_build_query(['standard' => $standard, 'cif' => $cif]);
    $result = cashflow_http_request('POST', $url, [
        'Authorization: Bearer ' . $accessToken,
        'Content-Type: text/plain',
    ], $xmlContent, 30);

    if (!$result['ok']) {
        throw new RuntimeException('Upload ANAF eșuat: ' . ($result['error'] ?? 'necunoscut'));
    }

    return cashflow_anaf_parse_xml_response($result['body']);
}

function cashflow_anaf_efactura_status(string $accessToken, string $environment, string $uploadId): array
{
    $url = cashflow_anaf_efactura_base_url($environment) . '/stareMesaj?' . http_build_query(['id_incarcare' => $uploadId]);
    $result = cashflow_http_request('GET', $url, ['Authorization: Bearer ' . $accessToken]);
    if (!$result['ok']) {
        throw new RuntimeException('Interogare stare ANAF eșuată: ' . ($result['error'] ?? 'necunoscut'));
    }
    return cashflow_anaf_parse_xml_response($result['body']);
}

function cashflow_anaf_efactura_list_messages(string $accessToken, string $environment, string $cif, int $days = 10): array
{
    $days = max(1, min(60, $days));
    $url = cashflow_anaf_efactura_base_url($environment) . '/listaMesajeFactura?' . http_build_query(['zile' => $days, 'cif' => $cif]);
    $result = cashflow_http_json('GET', $url, ['Authorization: Bearer ' . $accessToken], null);
    if (!$result['ok']) {
        throw new RuntimeException('Listare mesaje ANAF eșuată: ' . ($result['error'] ?? 'necunoscut'));
    }
    return $result['json'] ?? [];
}

/** Downloads the raw ZIP (contains the signed XML + signature) for a given message id. */
function cashflow_anaf_efactura_download(string $accessToken, string $environment, string $downloadId): string
{
    $url = cashflow_anaf_efactura_base_url($environment) . '/descarcare?' . http_build_query(['id' => $downloadId]);
    $result = cashflow_http_request('GET', $url, ['Authorization: Bearer ' . $accessToken], null, 30);
    if (!$result['ok']) {
        throw new RuntimeException('Descărcare ANAF eșuată: ' . ($result['error'] ?? 'necunoscut'));
    }
    return $result['body'];
}

/** ANAF's upload/stareMesaj responses are a small XML <header> element with attributes -- flatten to an array. */
function cashflow_anaf_parse_xml_response(string $xml): array
{
    $previous = libxml_use_internal_errors(true);
    $doc = simplexml_load_string($xml);
    libxml_use_internal_errors($previous);

    if ($doc === false) {
        return ['raw' => $xml];
    }

    $attributes = [];
    foreach ($doc->attributes() as $key => $value) {
        $attributes[$key] = (string)$value;
    }
    $attributes['raw'] = $xml;
    return $attributes;
}
