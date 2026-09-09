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

if ($id <= 0) {
    admin_redirect('/admin/albums.php');
}

/*
 * collection_videos otomatis terhapus
 * melalui FOREIGN KEY ON DELETE CASCADE.
 *
 * Record videos TIDAK ikut terhapus.
 */

$stmt = $pdo->prepare("
    DELETE FROM collections
    WHERE id = ?
");

$stmt->execute([$id]);

admin_flash(
    'Album berhasil dihapus. Video asli tetap aman ✅'
);

admin_redirect(
    '/admin/albums.php'
);
