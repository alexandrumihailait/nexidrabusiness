<?php
/**
 * Subscription plans + metered usage. A company always has exactly one
 * active subscription (auto-provisioned onto the 'starter' plan the first
 * time it's looked up); metered actions (document upload, ANAF lookups)
 * must call cashflow_check_and_increment_usage() BEFORE performing the
 * side effect, and only proceed if it returns true.
 */

function cashflow_usage_period(): string
{
    return date('Y-m');
}

/** The company's plan + subscription row, auto-provisioning 'starter' if none exists yet. */
function cashflow_get_company_subscription(PDO $pdo, int $companyId): array
{
    $stmt = $pdo->prepare(
        "SELECT cs.*, p.code AS plan_code, p.name AS plan_name, p.price_month_ron, p.max_documents_month,
                p.max_anaf_lookups_month, p.max_users, p.max_profit_centers, p.features
         FROM cf_company_subscriptions cs
         JOIN cf_subscription_plans p ON p.id = cs.plan_id
         WHERE cs.company_id = ? LIMIT 1"
    );
    $stmt->execute([$companyId]);
    $row = $stmt->fetch();

    if ($row) {
        return $row;
    }

    $planStmt = $pdo->prepare("SELECT id FROM cf_subscription_plans WHERE code = 'starter' LIMIT 1");
    $planStmt->execute();
    $planId = $planStmt->fetchColumn();
    if (!$planId) {
        // No plans provisioned at all (fresh install before seed) -- fail safe with an inert record.
        return ['plan_code' => 'none', 'plan_name' => 'Fără abonament', 'max_documents_month' => 0, 'max_anaf_lookups_month' => 0, 'max_users' => 0, 'max_profit_centers' => 0, 'features' => '', 'status' => 'active', 'current_period_start' => date('Y-m-01'), 'current_period_end' => date('Y-m-t')];
    }

    cashflow_assign_subscription($pdo, $companyId, (int)$planId);
    return cashflow_get_company_subscription($pdo, $companyId);
}

function cashflow_assign_subscription(PDO $pdo, int $companyId, int $planId): void
{
    $stmt = $pdo->prepare(
        "INSERT INTO cf_company_subscriptions (company_id, plan_id, status, current_period_start, current_period_end)
         VALUES (?, ?, 'active', ?, ?)
         ON DUPLICATE KEY UPDATE plan_id = VALUES(plan_id), status = 'active',
             current_period_start = VALUES(current_period_start), current_period_end = VALUES(current_period_end)"
    );
    $stmt->execute([$companyId, $planId, date('Y-m-01'), date('Y-m-t')]);
}

function cashflow_plan_has_feature(array $subscription, string $featureCode): bool
{
    $features = array_filter(array_map('trim', explode(',', $subscription['features'] ?? '')));
    return in_array($featureCode, $features, true);
}

function cashflow_get_usage(PDO $pdo, int $companyId, string $metric, ?string $periodYm = null): int
{
    $periodYm = $periodYm ?? cashflow_usage_period();
    $stmt = $pdo->prepare("SELECT counter FROM cf_usage_counters WHERE company_id = ? AND period_ym = ? AND metric = ?");
    $stmt->execute([$companyId, $periodYm, $metric]);
    return (int)($stmt->fetchColumn() ?: 0);
}

/**
 * Checks the current period's usage for $metric against $limitField on the
 * company's active plan and, if under the limit, atomically increments the
 * counter. A limit of 0 means unlimited. Returns false (without
 * incrementing) when the quota is exhausted -- callers must not perform
 * the metered action in that case.
 */
function cashflow_check_and_increment_usage(PDO $pdo, int $companyId, string $metric, string $limitField): bool
{
    $subscription = cashflow_get_company_subscription($pdo, $companyId);
    $limit = (int)($subscription[$limitField] ?? 0);
    $periodYm = cashflow_usage_period();

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT IGNORE INTO cf_usage_counters (company_id, period_ym, metric, counter) VALUES (?, ?, ?, 0)")
            ->execute([$companyId, $periodYm, $metric]);

        $stmt = $pdo->prepare("SELECT counter FROM cf_usage_counters WHERE company_id = ? AND period_ym = ? AND metric = ? FOR UPDATE");
        $stmt->execute([$companyId, $periodYm, $metric]);
        $current = (int)$stmt->fetchColumn();

        if ($limit > 0 && $current >= $limit) {
            $pdo->commit();
            return false;
        }

        $pdo->prepare("UPDATE cf_usage_counters SET counter = counter + 1 WHERE company_id = ? AND period_ym = ? AND metric = ?")
            ->execute([$companyId, $periodYm, $metric]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Usage summary for the "Abonament" page: plan limits + current period counters. */
function cashflow_subscription_summary(PDO $pdo, int $companyId): array
{
    $subscription = cashflow_get_company_subscription($pdo, $companyId);
    return [
        'subscription' => $subscription,
        'documents_used' => cashflow_get_usage($pdo, $companyId, 'documents'),
        'anaf_lookups_used' => cashflow_get_usage($pdo, $companyId, 'anaf_lookups'),
    ];
}
