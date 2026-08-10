<?php
/**
 * Excel-compatible CSV export (spec's "integrare cu Excel"). Gated by the
 * plan's `excel_export` feature. UTF-8 BOM + semicolon delimiter so
 * Excel's regional (RO) CSV import opens it correctly without a manual
 * "text to columns" step.
 *
 * @var PDO $pdo
 * @var array $company
 * @var array|null $activeProfitCenter
 * @var array $accessibleProfitCenters
 */

require_once __DIR__ . '/../lib/finance.php';

$companyId = (int)$company['id'];
$subscription = cashflow_get_company_subscription($pdo, $companyId);

if (!cashflow_plan_has_feature($subscription, 'excel_export')) {
    http_response_code(403);
    die('Exportul Excel/CSV nu este inclus în planul curent.');
}

$type = $_GET['type'] ?? 'transactions';
$filterPcIds = $activeProfitCenter
    ? [(int)$activeProfitCenter['id']]
    : array_map('intval', array_column($accessibleProfitCenters, 'id'));

function cashflow_export_open(string $filename): void
{
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: private, no-store');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel detects encoding correctly
}

function cashflow_export_row(array $row): void
{
    $out = fopen('php://output', 'w');
    fputcsv($out, $row, ';');
    fclose($out);
}

if ($type === 'report') {
    [$periodFrom, $periodTo, $periodLabel] = cashflow_resolve_period($_GET['period'] ?? 'month');

    cashflow_export_open('raport_' . $company['id'] . '_' . date('Y-m-d') . '.csv');
    cashflow_export_row(['Centru de profit', 'Încasări (RON)', 'Plăți (RON)', 'Cashflow net (RON)']);
    foreach ($accessibleProfitCenters as $pc) {
        $t = cashflow_totals($pdo, $companyId, [(int)$pc['id']], $periodFrom, $periodTo);
        cashflow_export_row([$pc['name'], $t['income'], $t['expense'], $t['net']]);
    }
    $consolidated = cashflow_totals($pdo, $companyId, $filterPcIds, $periodFrom, $periodTo);
    cashflow_export_row(['TOTAL FIRMĂ', $consolidated['income'], $consolidated['expense'], $consolidated['net']]);
    exit;
}

// Default: transaction list export.
$documents = [];
if (!empty($filterPcIds)) {
    $placeholders = implode(',', array_fill(0, count($filterPcIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT t.*, pc.name AS pc_name, a.name AS account_name, c.name AS category_name, p.name AS partner_name, u.name AS user_name
         FROM cf_transactions t
         JOIN cf_profit_centers pc ON pc.id = t.profit_center_id
         JOIN cf_accounts a ON a.id = t.account_id
         LEFT JOIN cf_categories c ON c.id = t.category_id
         LEFT JOIN cf_partners p ON p.id = t.partner_id
         JOIN cf_users u ON u.id = t.user_id
         WHERE t.company_id = ? AND t.profit_center_id IN ($placeholders) AND t.deleted_at IS NULL
         ORDER BY t.transaction_date DESC, t.id DESC LIMIT 5000"
    );
    $stmt->execute(array_merge([$companyId], $filterPcIds));
    $documents = $stmt->fetchAll();
}

cashflow_export_open('tranzactii_' . $company['id'] . '_' . date('Y-m-d') . '.csv');
cashflow_export_row(['Data', 'Centru de profit', 'Tip', 'Categorie', 'Partener', 'Cont', 'Sumă', 'Monedă', 'Sumă (RON)', 'Descriere', 'Utilizator', 'Status']);
foreach ($documents as $tx) {
    cashflow_export_row([
        $tx['transaction_date'], $tx['pc_name'], $tx['type'] === 'income' ? 'Încasare' : 'Plată',
        $tx['category_name'] ?: '', $tx['partner_name'] ?: '', $tx['account_name'],
        $tx['amount'], $tx['currency'], $tx['amount_ron'], $tx['description'] ?: '', $tx['user_name'], $tx['status'],
    ]);
}
exit;
