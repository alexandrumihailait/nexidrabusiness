<?php
/**
 * Google Drive integration (OAuth2 + Drive API v3). Requires a Google
 * Cloud project with the Drive API enabled and an OAuth 2.0 Client ID
 * (Web application type) whose authorized redirect URI matches this
 * app's integrations_callback endpoint -- created in Google Cloud Console
 * by the company, not something this code can provision.
 *
 * Scope used is drive.file (least privilege): the app can only see/manage
 * files it created itself, never the user's whole Drive.
 */

const CASHFLOW_GOOGLE_OAUTH_AUTHORIZE_URL = 'https://accounts.google.com/o/oauth2/v2/auth';
const CASHFLOW_GOOGLE_OAUTH_TOKEN_URL = 'https://oauth2.googleapis.com/token';
const CASHFLOW_GOOGLE_DRIVE_SCOPE = 'https://www.googleapis.com/auth/drive.file';

function cashflow_google_authorize_url(array $config, string $redirectUri, string $state): string
{
    return CASHFLOW_GOOGLE_OAUTH_AUTHORIZE_URL . '?' . http_build_query([
        'client_id' => $config['client_id'] ?? '',
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => CASHFLOW_GOOGLE_DRIVE_SCOPE,
        'access_type' => 'offline',
        'prompt' => 'consent',
        'state' => $state,
    ]);
}

function cashflow_google_exchange_code(array $config, string $redirectUri, string $code): array
{
    $body = http_build_query([
        'code' => $code,
        'client_id' => $config['client_id'] ?? '',
        'client_secret' => $config['client_secret'] ?? '',
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code',
    ]);

    $result = cashflow_http_request('POST', CASHFLOW_GOOGLE_OAUTH_TOKEN_URL, ['Content-Type: application/x-www-form-urlencoded'], $body);
    if (!$result['ok']) {
        throw new RuntimeException('Autorizarea Google a eșuat: ' . ($result['error'] ?? 'necunoscut'));
    }
    $json = json_decode($result['body'], true);
    if (!$json || empty($json['access_token'])) {
        throw new RuntimeException('Răspuns invalid de la Google la schimbul de token.');
    }
    return $json;
}

function cashflow_google_refresh(array $config, string $refreshToken): array
{
    $body = http_build_query([
        'client_id' => $config['client_id'] ?? '',
        'client_secret' => $config['client_secret'] ?? '',
        'refresh_token' => $refreshToken,
        'grant_type' => 'refresh_token',
    ]);

    $result = cashflow_http_request('POST', CASHFLOW_GOOGLE_OAUTH_TOKEN_URL, ['Content-Type: application/x-www-form-urlencoded'], $body);
    if (!$result['ok']) {
        throw new RuntimeException('Reîmprospătarea token-ului Google a eșuat: ' . ($result['error'] ?? 'necunoscut'));
    }
    $json = json_decode($result['body'], true);
    if (!$json || empty($json['access_token'])) {
        throw new RuntimeException('Răspuns invalid de la Google la refresh.');
    }
    return $json;
}

/** Uploads raw file bytes as a new Drive file via multipart upload. Returns the created file's {id, name, webViewLink}. */
function cashflow_google_drive_upload(string $accessToken, string $filename, string $mimeType, string $content, ?string $folderId = null): array
{
    $boundary = 'cashflow-' . bin2hex(random_bytes(8));
    $metadata = ['name' => $filename];
    if ($folderId) {
        $metadata['parents'] = [$folderId];
    }

    $body = "--$boundary\r\n"
        . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
        . json_encode($metadata) . "\r\n"
        . "--$boundary\r\n"
        . "Content-Type: $mimeType\r\n\r\n"
        . $content . "\r\n"
        . "--$boundary--";

    $result = cashflow_http_request(
        'POST',
        'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,webViewLink',
        [
            'Authorization: Bearer ' . $accessToken,
            'Content-Type: multipart/related; boundary=' . $boundary,
        ],
        $body,
        30
    );

    if (!$result['ok']) {
        throw new RuntimeException('Upload Google Drive eșuat: ' . ($result['error'] ?? 'necunoscut'));
    }

    return json_decode($result['body'], true) ?? [];
}

function cashflow_google_drive_list(string $accessToken, ?string $folderId = null, int $pageSize = 20): array
{
    $params = ['pageSize' => $pageSize, 'fields' => 'files(id,name,webViewLink,createdTime)'];
    if ($folderId) {
        $params['q'] = "'" . $folderId . "' in parents and trashed = false";
    }

    $result = cashflow_http_json('GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query($params), [
        'Authorization: Bearer ' . $accessToken,
    ], null);

    if (!$result['ok']) {
        throw new RuntimeException('Listare Google Drive eșuată: ' . ($result['error'] ?? 'necunoscut'));
    }
    return $result['json']['files'] ?? [];
}
