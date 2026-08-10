<?php
/**
 * Local file storage for uploaded documents. Files live under
 * storage/uploads/{company_id}/, outside any URL that Apache/Nginx would
 * serve directly (storage/.htaccess denies all access as a second layer);
 * the only legitimate path to the bytes is modules/document_download.php,
 * which re-checks the requester's access before streaming anything.
 */

const CASHFLOW_DOCUMENT_ALLOWED_MIME = [
    'application/pdf' => 'pdf',
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'text/xml' => 'xml',
    'application/xml' => 'xml',
];

const CASHFLOW_DOCUMENT_MAX_BYTES = 10 * 1024 * 1024; // 10 MB

function cashflow_storage_dir(int $companyId): string
{
    $dir = __DIR__ . '/../storage/uploads/' . $companyId;
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
    return $dir;
}

/**
 * Validates and moves an uploaded file ($_FILES[...] entry) into company
 * storage. Returns [stored_filename, mime_type, size_bytes] on success;
 * throws on any validation failure (bad extension/mime, oversized, upload
 * error) so the caller never records a cf_documents row for a bad file.
 */
function cashflow_store_uploaded_file(int $companyId, array $fileEntry): array
{
    if (($fileEntry['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Încărcarea fișierului a eșuat.');
    }
    if (($fileEntry['size'] ?? 0) <= 0 || $fileEntry['size'] > CASHFLOW_DOCUMENT_MAX_BYTES) {
        throw new RuntimeException('Fișierul este gol sau depășește 10 MB.');
    }
    if (!is_uploaded_file($fileEntry['tmp_name'])) {
        throw new RuntimeException('Fișier invalid.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $fileEntry['tmp_name']);
    finfo_close($finfo);

    if (!isset(CASHFLOW_DOCUMENT_ALLOWED_MIME[$mime])) {
        throw new RuntimeException('Tip de fișier neacceptat (permise: PDF, JPG, PNG, XML).');
    }

    $extension = CASHFLOW_DOCUMENT_ALLOWED_MIME[$mime];
    $storedFilename = bin2hex(random_bytes(16)) . '.' . $extension;
    $destination = cashflow_storage_dir($companyId) . '/' . $storedFilename;

    if (!move_uploaded_file($fileEntry['tmp_name'], $destination)) {
        throw new RuntimeException('Nu am putut salva fișierul pe server.');
    }

    return [$storedFilename, $mime, (int)$fileEntry['size']];
}

function cashflow_document_path(int $companyId, string $storedFilename): string
{
    return cashflow_storage_dir($companyId) . '/' . basename($storedFilename);
}
