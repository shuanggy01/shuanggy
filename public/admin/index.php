<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/admin.php';

admin_require_login();
admin_redirect('/admin/mobile.php');
