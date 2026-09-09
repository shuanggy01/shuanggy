<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/admin.php';
admin_require_login();

// Ads Manager sekarang memakai satu editor responsif agar struktur Desktop/Mobile
// tidak bisa rusak karena dua halaman admin menyimpan format JSON yang berbeda.
header('Location: /admin/ads-mobile.php');
exit;
