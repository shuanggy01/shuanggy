<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/admin.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

admin_verify_csrf();
admin_remember_revoke_current($pdo);

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();

    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'],
        $params['domain'],
        $params['secure'],
        $params['httponly']
    );
}

session_destroy();
admin_redirect('/admin/login.php');
