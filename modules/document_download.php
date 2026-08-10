<?php
/**
 * Authenticated document download gate -- the only legitimate way to
 * reach the bytes under storage/uploads/ (which is otherwise denied by
 * storage/.htaccess). Re-verifies company + profit-center access before
 * streaming anything, exactly like every other data access in this app.
 *
 * @var PDO $pdo
 * @var array $company
 */

require_once __DIR__ . '/../lib/storage.php';

$companyId = (int)$company['id'];
$companyRoleArr = ['role_code' => $company['role_code'], 'role_name' => $company['role_name']];

$docId = (int)($_GET['id'] ?? 0);
$stmt = $pdo->prepare("SELECT * FROM cf_documents WHERE id = ? AND company_id = ? LIMIT 1");
$stmt->execute([$docId, $companyId]);
$document = $stmt->fetch();

if (!$document) {
    http_response_code(404);
    die('Document inexistent.');
}

if ($document['profit_center_id']) {
    cashflow_require_profit_center_access($pdo, $userId, $companyId, (int)$document['profit_center_id'], 'read', $companyRoleArr);
}

$path = cashflow_document_path($companyId, $document['stored_filename']);
if (!is_file($path)) {
    http_response_code(404);
    die('Fișierul nu mai există pe server.');
}

header('Content-Type: ' . $document['mime_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $document['original_filename']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
readfile($path);
exit;
