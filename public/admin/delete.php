<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/admin.php';

admin_require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

admin_verify_csrf();

$id = (int) ($_POST['id'] ?? 0);

$stmt = $pdo->prepare("
    DELETE FROM videos
    WHERE id = ?
");

$stmt->execute([$id]);

admin_flash('Video berhasil dihapus.');

admin_redirect('/admin/');
