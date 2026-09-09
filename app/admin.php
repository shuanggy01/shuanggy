<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/config/database.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('asupan_admin');

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/admin',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function admin_e(?string $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

function admin_redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function admin_csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function admin_csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' .
        admin_e(admin_csrf_token()) .
        '">';
}

function admin_verify_csrf(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    $stored = $_SESSION['csrf_token'] ?? '';

    if (
        $sent === '' ||
        $stored === '' ||
        !hash_equals($stored, $sent)
    ) {
        http_response_code(419);
        exit('CSRF token tidak valid.');
    }
}

function admin_logged_in(): bool
{
    return !empty($_SESSION['admin_id']);
}

function admin_remember_cookie_name(): string
{
    return 'asupan_admin_remember';
}

function admin_remember_ttl(): int
{
    return 30 * 24 * 60 * 60;
}

function admin_remember_cookie_options(int $expires): array
{
    return [
        'expires' => $expires,
        'path' => '/admin',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function admin_remember_ensure_table(PDO $pdo): bool
{
    static $result = null;

    if ($result !== null) {
        return $result;
    }

    try {
        $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS admin_remember_tokens (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id BIGINT UNSIGNED NOT NULL,
    selector CHAR(18) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    last_used_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_remember_selector (selector),
    KEY idx_admin_remember_admin (admin_id),
    KEY idx_admin_remember_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
        $result = true;
    } catch (Throwable $e) {
        $result = false;
    }

    return $result;
}

function admin_remember_parse_cookie(): ?array
{
    $raw = (string) ($_COOKIE[admin_remember_cookie_name()] ?? '');

    if (!preg_match('/^([a-f0-9]{18}):([a-f0-9]{64})$/', $raw, $m)) {
        return null;
    }

    return [
        'selector' => $m[1],
        'validator' => $m[2],
    ];
}

function admin_remember_clear_cookie(): void
{
    setcookie(
        admin_remember_cookie_name(),
        '',
        admin_remember_cookie_options(time() - 3600)
    );
    unset($_COOKIE[admin_remember_cookie_name()]);
}

function admin_remember_revoke_current(PDO $pdo): void
{
    $parts = admin_remember_parse_cookie();

    if ($parts !== null && admin_remember_ensure_table($pdo)) {
        try {
            $stmt = $pdo->prepare('DELETE FROM admin_remember_tokens WHERE selector = ?');
            $stmt->execute([$parts['selector']]);
        } catch (Throwable $e) {
            // Logout/login biasa tetap boleh berjalan walau cleanup DB gagal.
        }
    }

    admin_remember_clear_cookie();
}

function admin_remember_issue(PDO $pdo, int $adminId): bool
{
    if (!admin_remember_ensure_table($pdo)) {
        return false;
    }

    // Satu browser/perangkat memakai satu token aktif agar mudah dicabut saat logout.
    admin_remember_revoke_current($pdo);

    try {
        $selector = bin2hex(random_bytes(9));
        $validator = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $validator);

        $stmt = $pdo->prepare(<<<'SQL'
INSERT INTO admin_remember_tokens (admin_id, selector, token_hash, expires_at)
VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))
SQL);
        $stmt->execute([$adminId, $selector, $tokenHash]);

        $cookieValue = $selector . ':' . $validator;
        setcookie(
            admin_remember_cookie_name(),
            $cookieValue,
            admin_remember_cookie_options(time() + admin_remember_ttl())
        );
        $_COOKIE[admin_remember_cookie_name()] = $cookieValue;

        return true;
    } catch (Throwable $e) {
        admin_remember_clear_cookie();
        return false;
    }
}

function admin_attempt_remember_login(PDO $pdo): bool
{
    if (admin_logged_in()) {
        return true;
    }

    $parts = admin_remember_parse_cookie();
    if ($parts === null || !admin_remember_ensure_table($pdo)) {
        return false;
    }

    try {
        $stmt = $pdo->prepare(<<<'SQL'
SELECT t.id, t.admin_id, t.token_hash, t.expires_at, a.username
FROM admin_remember_tokens t
INNER JOIN admins a ON a.id = t.admin_id
WHERE t.selector = ? AND a.is_active = 1
LIMIT 1
SQL);
        $stmt->execute([$parts['selector']]);
        $token = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$token || strtotime((string) $token['expires_at']) <= time()) {
            if ($token) {
                $pdo->prepare('DELETE FROM admin_remember_tokens WHERE id = ?')->execute([(int) $token['id']]);
            }
            admin_remember_clear_cookie();
            return false;
        }

        $presentedHash = hash('sha256', $parts['validator']);
        if (!hash_equals((string) $token['token_hash'], $presentedHash)) {
            // Validator salah untuk selector yang valid: cabut token tersebut.
            $pdo->prepare('DELETE FROM admin_remember_tokens WHERE id = ?')->execute([(int) $token['id']]);
            admin_remember_clear_cookie();
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int) $token['admin_id'];
        $_SESSION['admin_username'] = (string) $token['username'];
        $_SESSION['remember_login'] = true;

        // Rotate validator setelah auto-login agar cookie lama tidak bisa dipakai ulang.
        $newValidator = bin2hex(random_bytes(32));
        $newHash = hash('sha256', $newValidator);
        $pdo->prepare(<<<'SQL'
UPDATE admin_remember_tokens
SET token_hash = ?, last_used_at = NOW(), expires_at = DATE_ADD(NOW(), INTERVAL 30 DAY)
WHERE id = ?
SQL)->execute([$newHash, (int) $token['id']]);

        $cookieValue = $parts['selector'] . ':' . $newValidator;
        setcookie(
            admin_remember_cookie_name(),
            $cookieValue,
            admin_remember_cookie_options(time() + admin_remember_ttl())
        );
        $_COOKIE[admin_remember_cookie_name()] = $cookieValue;

        $pdo->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = ?')
            ->execute([(int) $token['admin_id']]);

        return true;
    } catch (Throwable $e) {
        admin_remember_clear_cookie();
        return false;
    }
}

function admin_require_login(): void
{
    global $pdo;

    if (!admin_logged_in() && !admin_attempt_remember_login($pdo)) {
        admin_redirect('/admin/login.php');
    }
}

function admin_flash(string $message): void
{
    $_SESSION['flash'] = $message;
}

function admin_get_flash(): ?string
{
    $message = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);

    return $message;
}

function admin_valid_url(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }

    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

    return in_array($scheme, ['http', 'https'], true);
}
