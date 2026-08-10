<?php
/**
 * Minimal cURL wrapper shared by every third-party integration client
 * (ANAF, SmartBill, Google Drive). Every integration call goes through
 * this so timeouts, error shape, and logging stay consistent -- no
 * integration client talks to cURL directly.
 */
function cashflow_http_request(string $method, string $url, array $headers = [], ?string $body = null, int $timeoutSec = 20): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => $timeoutSec,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }

    $responseBody = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        return ['ok' => false, 'status' => 0, 'body' => null, 'error' => "cURL error ($errno): $error"];
    }

    return [
        'ok' => $status >= 200 && $status < 300,
        'status' => $status,
        'body' => $responseBody,
        'error' => ($status >= 200 && $status < 300) ? null : "HTTP $status",
    ];
}

function cashflow_http_json(string $method, string $url, array $headers, ?array $jsonBody, int $timeoutSec = 20): array
{
    $headers[] = 'Content-Type: application/json';
    $body = $jsonBody !== null ? json_encode($jsonBody, JSON_UNESCAPED_UNICODE) : null;
    $result = cashflow_http_request($method, $url, $headers, $body, $timeoutSec);

    if ($result['body'] !== null) {
        $decoded = json_decode($result['body'], true);
        $result['json'] = json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    } else {
        $result['json'] = null;
    }

    return $result;
}
