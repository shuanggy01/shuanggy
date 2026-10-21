<?php
return [
    // Salin file ini menjadi config.php. Ganti token sebelum membuka /admin.php.
    'setup_token' => "GANTI_DENGAN_TOKEN_ACAK_MINIMAL_24_KARAKTER",
    // Folder ini HARUS di luar document root (document root = folder public).
    'data_dir' => __DIR__ . '/var',
];
