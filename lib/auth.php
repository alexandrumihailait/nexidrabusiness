<?php
require_once __DIR__ . '/../db.php';

function cashflow_current_user_id(): ?int
{
    return isset($_SESSION['cf_user_id']) ? (int)$_SESSION['cf_user_id'] : null;
}

function cashflow_require_login(): int
{
    $uid = cashflow_current_user_id();
    if (!$uid) {
        header('Location: index.php?p=login');
        exit;
    }
    return $uid;
}

const CASHFLOW_LOGIN_MAX_ATTEMPTS = 5;
const CASHFLOW_LOGIN_THROTTLE_MINUTES = 15;

/**
 * True if $email has racked up CASHFLOW_LOGIN_MAX_ATTEMPTS+ failed logins
 * in the last CASHFLOW_LOGIN_THROTTLE_MINUTES -- a lightweight brute-force
 * slow-down that doesn't require an external service. Checked (and
 * recorded) regardless of whether the email actually belongs to an
 * account, so the response never leaks account existence.
 */
function cashflow_is_login_throttled(PDO $pdo, string $email): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM cf_login_attempts
         WHERE email = ? AND success = 0 AND created_at >= (NOW() - INTERVAL " . CASHFLOW_LOGIN_THROTTLE_MINUTES . " MINUTE)"
    );
    $stmt->execute([$email]);
    return (int)$stmt->fetchColumn() >= CASHFLOW_LOGIN_MAX_ATTEMPTS;
}

function cashflow_record_login_attempt(PDO $pdo, string $email, bool $success): void
{
    $stmt = $pdo->prepare("INSERT INTO cf_login_attempts (email, ip_address, success) VALUES (?, ?, ?)");
    $stmt->execute([$email, $_SERVER['REMOTE_ADDR'] ?? null, $success ? 1 : 0]);
}

/**
 * Returns the authenticated user, or null on bad credentials -- or throws
 * CashflowLoginThrottledException if this email has been locked out by
 * repeated failures, so login.php can show a distinct "try again later"
 * message without a valid/invalid-credentials branch to leak from.
 */
function cashflow_attempt_login(PDO $pdo, string $email, string $password): ?array
{
    if (cashflow_is_login_throttled($pdo, $email)) {
        throw new CashflowLoginThrottledException();
    }

    $stmt = $pdo->prepare("SELECT * FROM cf_users WHERE email = ? AND status = 'active' LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        cashflow_record_login_attempt($pdo, $email, false);
        return null;
    }

    cashflow_record_login_attempt($pdo, $email, true);

    session_regenerate_id(true);
    $_SESSION['cf_user_id'] = (int)$user['id'];
    unset($_SESSION['cf_active_company_id'], $_SESSION['cf_active_profit_center_id']);

    return $user;
}

class CashflowLoginThrottledException extends RuntimeException
{
}

function cashflow_logout(): void
{
    unset($_SESSION['cf_user_id'], $_SESSION['cf_active_company_id'], $_SESSION['cf_active_profit_center_id']);
}

function cashflow_current_user(PDO $pdo): ?array
{
    $uid = cashflow_current_user_id();
    if (!$uid) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM cf_users WHERE id = ? LIMIT 1");
    $stmt->execute([$uid]);
    $user = $stmt->fetch();
    return $user ?: null;
}

function cashflow_csrf_token(): string
{
    if (empty($_SESSION['cf_csrf'])) {
        $_SESSION['cf_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['cf_csrf'];
}

function cashflow_csrf_check(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || $token === '' || !hash_equals($_SESSION['cf_csrf'] ?? '', $token)) {
        http_response_code(403);
        die('Invalid CSRF token.');
    }
}
