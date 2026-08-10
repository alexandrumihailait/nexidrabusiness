<?php
/**
 * SmartBill API client. Authenticates with HTTP Basic Auth using the
 * account's SmartBill login email + the API token generated from
 * "My account -> Integrations -> API" in the SmartBill dashboard --
 * there is no OAuth flow. Base URL: https://ws.smartbill.ro/SBORO/api.
 *
 * Field names below follow SmartBill's documented invoice-creation
 * payload; verify against the live "api.smartbill.ro" reference for the
 * connected account before relying on this in production, since SmartBill
 * can add/rename optional fields between API revisions.
 */

const CASHFLOW_SMARTBILL_BASE_URL = 'https://ws.smartbill.ro/SBORO/api';

function cashflow_smartbill_auth_header(string $username, string $token): string
{
    return 'Authorization: Basic ' . base64_encode($username . ':' . $token);
}

/** Cheapest possible call to confirm the credentials work: fetch the account's VAT rate list. */
function cashflow_smartbill_test_connection(string $username, string $token): array
{
    $result = cashflow_http_request('GET', CASHFLOW_SMARTBILL_BASE_URL . '/tax', [
        cashflow_smartbill_auth_header($username, $token),
    ]);

    if (!$result['ok']) {
        throw new RuntimeException('Conexiune SmartBill eșuată: ' . ($result['error'] ?? 'necunoscut'));
    }
    return json_decode($result['body'], true) ?? [];
}

/**
 * Creates an invoice. $invoice must contain at least: companyVatCode
 * (issuer CIF), seriesName, client (name, vatCode/cui, address, etc.),
 * products (array of line items: name, quantity, price, measuringUnitName,
 * taxName). Returns SmartBill's response (series/number of the created
 * invoice, or an errorText on failure).
 */
function cashflow_smartbill_create_invoice(string $username, string $token, array $invoice): array
{
    $result = cashflow_http_json('POST', CASHFLOW_SMARTBILL_BASE_URL . '/invoice', [
        cashflow_smartbill_auth_header($username, $token),
    ], $invoice, 30);

    if (!$result['ok']) {
        $message = $result['json']['errorText'] ?? ($result['error'] ?? 'necunoscut');
        throw new RuntimeException('Creare factură SmartBill eșuată: ' . $message);
    }
    return $result['json'] ?? [];
}

function cashflow_smartbill_get_invoice_pdf(string $username, string $token, string $cif, string $seriesName, string $number): string
{
    $url = CASHFLOW_SMARTBILL_BASE_URL . '/invoice/pdf?' . http_build_query([
        'cif' => $cif, 'seriesname' => $seriesName, 'number' => $number,
    ]);
    $result = cashflow_http_request('GET', $url, [cashflow_smartbill_auth_header($username, $token)], null, 30);
    if (!$result['ok']) {
        throw new RuntimeException('Descărcare PDF SmartBill eșuată: ' . ($result['error'] ?? 'necunoscut'));
    }
    return $result['body'];
}

function cashflow_smartbill_payment_status(string $username, string $token, string $cif, string $seriesName, string $number): array
{
    $url = CASHFLOW_SMARTBILL_BASE_URL . '/invoice/paymentstatus?' . http_build_query([
        'cif' => $cif, 'seriesname' => $seriesName, 'number' => $number,
    ]);
    $result = cashflow_http_json('GET', $url, [cashflow_smartbill_auth_header($username, $token)], null);
    if (!$result['ok']) {
        throw new RuntimeException('Interogare status plată SmartBill eșuată: ' . ($result['error'] ?? 'necunoscut'));
    }
    return $result['json'] ?? [];
}

function cashflow_smartbill_cancel_invoice(string $username, string $token, string $cif, string $seriesName, string $number): array
{
    $result = cashflow_http_json('POST', CASHFLOW_SMARTBILL_BASE_URL . '/invoice/cancel', [
        cashflow_smartbill_auth_header($username, $token),
    ], ['companyVatCode' => $cif, 'seriesName' => $seriesName, 'number' => $number]);

    if (!$result['ok']) {
        throw new RuntimeException('Anulare factură SmartBill eșuată: ' . ($result['error'] ?? 'necunoscut'));
    }
    return $result['json'] ?? [];
}
