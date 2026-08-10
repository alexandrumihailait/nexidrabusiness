<?php
/**
 * Aggregation helpers shared by the dashboard and reports modules. Every
 * function here takes an explicit, already-authorized list of profit
 * center IDs ($pcIds) -- callers must resolve access via
 * cashflow_user_profit_center_ids()/cashflow_require_profit_center_access()
 * before calling into this file. Passing an empty array intentionally
 * yields all-zero totals rather than "all centers".
 */

function cashflow_resolve_period(string $period): array
{
    $today = new DateTimeImmutable('today');

    switch ($period) {
        case '30d':
            return [$today->modify('-30 days')->format('Y-m-d'), $today->format('Y-m-d'), 'ultimele 30 zile'];
        case 'year':
            return [$today->format('Y-01-01'), $today->format('Y-m-d'), 'anul curent'];
        case 'all':
            return [null, null, 'tot istoricul'];
        case 'month':
        default:
            return [$today->format('Y-m-01'), $today->format('Y-m-d'), 'luna curentă'];
    }
}

function cashflow_totals(PDO $pdo, int $companyId, array $pcIds, ?string $from, ?string $to): array
{
    if (empty($pcIds)) {
        return ['income' => 0.0, 'expense' => 0.0, 'net' => 0.0];
    }

    $placeholders = implode(',', array_fill(0, count($pcIds), '?'));
    $sql = "SELECT type, COALESCE(SUM(amount_ron), 0) AS total
            FROM cf_transactions
            WHERE company_id = ? AND profit_center_id IN ($placeholders)
              AND status = 'confirmed' AND deleted_at IS NULL";
    $params = array_merge([$companyId], $pcIds);

    if ($from) {
        $sql .= " AND transaction_date >= ?";
        $params[] = $from;
    }
    if ($to) {
        $sql .= " AND transaction_date <= ?";
        $params[] = $to;
    }
    $sql .= " GROUP BY type";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $income = 0.0;
    $expense = 0.0;
    foreach ($stmt->fetchAll() as $row) {
        if ($row['type'] === 'income') {
            $income = (float)$row['total'];
        } else {
            $expense = (float)$row['total'];
        }
    }

    return ['income' => $income, 'expense' => $expense, 'net' => $income - $expense];
}

function cashflow_category_breakdown(PDO $pdo, int $companyId, array $pcIds, ?string $from, ?string $to, string $type): array
{
    if (empty($pcIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($pcIds), '?'));
    $sql = "SELECT c.name, COALESCE(SUM(t.amount_ron), 0) AS total
            FROM cf_transactions t
            LEFT JOIN cf_categories c ON c.id = t.category_id
            WHERE t.company_id = ? AND t.profit_center_id IN ($placeholders)
              AND t.status = 'confirmed' AND t.deleted_at IS NULL AND t.type = ?";
    $params = array_merge([$companyId], $pcIds, [$type]);

    if ($from) {
        $sql .= " AND t.transaction_date >= ?";
        $params[] = $from;
    }
    if ($to) {
        $sql .= " AND t.transaction_date <= ?";
        $params[] = $to;
    }
    $sql .= " GROUP BY c.id, c.name HAVING total > 0 ORDER BY total DESC LIMIT 8";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** Trend-based 90-day forecast: extrapolates the last 90 days' net cashflow. */
function cashflow_forecast(PDO $pdo, int $companyId, array $pcIds): float
{
    $from = (new DateTimeImmutable('today'))->modify('-90 days')->format('Y-m-d');
    $to = (new DateTimeImmutable('today'))->format('Y-m-d');
    $totals = cashflow_totals($pdo, $companyId, $pcIds, $from, $to);
    return $totals['net'];
}

/**
 * The real, physical cash position of the company: opening balances plus
 * every confirmed transaction across ALL accounts/profit centers. This is
 * intentionally not filtered by the caller's accessible profit centers --
 * only company admins/owners should ever request it (enforced by callers).
 */
function cashflow_real_cash(PDO $pdo, int $companyId): float
{
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(opening_balance), 0) FROM cf_accounts WHERE company_id = ? AND status = 'active'");
    $stmt->execute([$companyId]);
    $opening = (float)$stmt->fetchColumn();

    $stmt = $pdo->prepare(
        "SELECT COALESCE(SUM(CASE WHEN type = 'income' THEN amount_ron ELSE -amount_ron END), 0)
         FROM cf_transactions WHERE company_id = ? AND status = 'confirmed' AND deleted_at IS NULL"
    );
    $stmt->execute([$companyId]);
    $net = (float)$stmt->fetchColumn();

    return $opening + $net;
}

/** Finds a category by name, creating it if missing. Returns null for an empty name. */
function cashflow_resolve_category(PDO $pdo, int $companyId, string $type, string $name): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id FROM cf_categories WHERE company_id = ? AND type = ? AND name = ? LIMIT 1");
    $stmt->execute([$companyId, $type, $name]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int)$id;
    }
    $ins = $pdo->prepare("INSERT INTO cf_categories (company_id, name, type) VALUES (?, ?, ?)");
    $ins->execute([$companyId, $name, $type]);
    return (int)$pdo->lastInsertId();
}

/** Finds a partner by name, creating it if missing. Returns null for an empty name. */
function cashflow_resolve_partner(PDO $pdo, int $companyId, string $name): ?int
{
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    $stmt = $pdo->prepare("SELECT id FROM cf_partners WHERE company_id = ? AND name = ? LIMIT 1");
    $stmt->execute([$companyId, $name]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int)$id;
    }
    $ins = $pdo->prepare("INSERT INTO cf_partners (company_id, name, type) VALUES (?, ?, 'both')");
    $ins->execute([$companyId, $name]);
    return (int)$pdo->lastInsertId();
}

/**
 * Single entry point for posting a ledger transaction, used both by the
 * manual "Tranzacție nouă" form and by operational modules (trips, work
 * orders, invoice payments) settling into the cashflow automatically --
 * so every code path that touches money produces the same audited,
 * amount_ron-converted row (spec section 60: operational data must
 * always land in the cashflow, never bypass it).
 */
function cashflow_create_transaction(PDO $pdo, array $data): int
{
    $amountRon = round($data['amount'] * $data['exchange_rate'], 2);

    $stmt = $pdo->prepare(
        "INSERT INTO cf_transactions
         (company_id, profit_center_id, account_id, user_id, type, category_id, partner_id, amount, currency, exchange_rate, amount_ron, transaction_date, description, invoice_number, status, created_by)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', ?)"
    );
    $stmt->execute([
        $data['company_id'],
        $data['profit_center_id'],
        $data['account_id'],
        $data['user_id'],
        $data['type'],
        $data['category_id'] ?? null,
        $data['partner_id'] ?? null,
        $data['amount'],
        $data['currency'],
        $data['exchange_rate'],
        $amountRon,
        $data['transaction_date'],
        $data['description'] ?? null,
        $data['invoice_number'] ?? null,
        $data['user_id'],
    ]);

    return (int)$pdo->lastInsertId();
}

/** Outstanding (unpaid/partial) receivables and payables, in RON, for the given profit centers. */
function cashflow_receivables_payables(PDO $pdo, int $companyId, array $pcIds): array
{
    if (empty($pcIds)) {
        return ['receivable' => 0.0, 'payable' => 0.0];
    }

    $placeholders = implode(',', array_fill(0, count($pcIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT direction, COALESCE(SUM(amount_ron - paid_amount_ron), 0) AS total
         FROM cf_invoices
         WHERE company_id = ? AND profit_center_id IN ($placeholders)
           AND status IN ('unpaid', 'partial')
         GROUP BY direction"
    );
    $stmt->execute(array_merge([$companyId], $pcIds));

    $result = ['receivable' => 0.0, 'payable' => 0.0];
    foreach ($stmt->fetchAll() as $row) {
        $result[$row['direction']] = (float)$row['total'];
    }
    return $result;
}

/**
 * Costs allocated INTO each profit center via cf_transaction_allocations
 * (shared/corporate costs split across activity lines -- section 33/34).
 * Distinct from direct cashflow: this never touches cf_transactions
 * totals, only the "profit centru" view that adds allocated cost on top
 * of directly-attributed cost.
 */
function cashflow_allocated_costs(PDO $pdo, int $companyId, array $pcIds, ?string $from, ?string $to): array
{
    if (empty($pcIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($pcIds), '?'));
    $sql = "SELECT a.profit_center_id, COALESCE(SUM(a.amount), 0) AS total
            FROM cf_transaction_allocations a
            JOIN cf_transactions t ON t.id = a.transaction_id
            WHERE t.company_id = ? AND a.profit_center_id IN ($placeholders)
              AND t.status = 'confirmed' AND t.deleted_at IS NULL";
    $params = array_merge([$companyId], $pcIds);

    if ($from) {
        $sql .= " AND t.transaction_date >= ?";
        $params[] = $from;
    }
    if ($to) {
        $sql .= " AND t.transaction_date <= ?";
        $params[] = $to;
    }
    $sql .= " GROUP BY a.profit_center_id";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    $result = [];
    foreach ($stmt->fetchAll() as $row) {
        $result[(int)$row['profit_center_id']] = (float)$row['total'];
    }
    return $result;
}

/**
 * Activity-specific KPIs for a Transport-type profit center (section 18:
 * cost/km, venit/km, profit/cursă). Driven by cf_profit_centers.type
 * rather than a hardcoded center name/id, so any number of Transport
 * centers work the same way.
 */
function cashflow_transport_kpis(PDO $pdo, int $companyId, int $pcId, ?string $from, ?string $to): array
{
    $sql = "SELECT COUNT(*) AS trips, COALESCE(SUM(km), 0) AS km,
                   COALESCE(SUM(tariff * exchange_rate), 0) AS revenue_ron,
                   COALESCE(SUM((fuel_cost + road_taxes + other_costs) * exchange_rate), 0) AS cost_ron
            FROM cf_trips WHERE company_id = ? AND profit_center_id = ?";
    $params = [$companyId, $pcId];
    if ($from) { $sql .= " AND trip_date >= ?"; $params[] = $from; }
    if ($to) { $sql .= " AND trip_date <= ?"; $params[] = $to; }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    $km = (float)$row['km'];
    $trips = (int)$row['trips'];
    $revenue = (float)$row['revenue_ron'];
    $cost = (float)$row['cost_ron'];

    return [
        'trips' => $trips,
        'km' => $km,
        'revenue' => $revenue,
        'cost' => $cost,
        'cost_per_km' => $km > 0 ? $cost / $km : 0.0,
        'revenue_per_km' => $km > 0 ? $revenue / $km : 0.0,
        'profit_per_trip' => $trips > 0 ? ($revenue - $cost) / $trips : 0.0,
    ];
}

/**
 * Activity-specific KPIs for a Service/Detailing/Colantări-type profit
 * center (section 18: materiale, manoperă, mașini procesate, profit/lucrare).
 */
function cashflow_service_kpis(PDO $pdo, int $companyId, int $pcId, ?string $from, ?string $to): array
{
    $sql = "SELECT COUNT(*) AS orders,
                   COALESCE(SUM(materials_cost * exchange_rate), 0) AS materials_ron,
                   COALESCE(SUM(labor_cost * exchange_rate), 0) AS labor_ron,
                   COALESCE(SUM(client_price * exchange_rate), 0) AS revenue_ron,
                   COALESCE(SUM((materials_cost + labor_cost + subcontractor_cost + other_cost) * exchange_rate), 0) AS cost_ron
            FROM cf_work_orders WHERE company_id = ? AND profit_center_id = ?";
    $params = [$companyId, $pcId];
    if ($from) { $sql .= " AND date_in >= ?"; $params[] = $from; }
    if ($to) { $sql .= " AND date_in <= ?"; $params[] = $to; }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    $orders = (int)$row['orders'];
    $revenue = (float)$row['revenue_ron'];
    $cost = (float)$row['cost_ron'];

    return [
        'orders' => $orders,
        'materials' => (float)$row['materials_ron'],
        'labor' => (float)$row['labor_ron'],
        'revenue' => $revenue,
        'cost' => $cost,
        'revenue_per_order' => $orders > 0 ? $revenue / $orders : 0.0,
        'cost_per_order' => $orders > 0 ? $cost / $orders : 0.0,
        'profit_per_order' => $orders > 0 ? ($revenue - $cost) / $orders : 0.0,
    ];
}
