<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit("CLI only.\n");
}

require dirname(__DIR__) . '/config/database.php';

function ask(string $message): string
{
    echo $message;
    return trim((string) fgets(STDIN));
}

function hiddenPassword(string $message): string
{
    echo $message;

    if (PHP_OS_FAMILY !== 'Windows') {
        system('stty -echo');
    }

    $password = trim((string) fgets(STDIN));

    if (PHP_OS_FAMILY !== 'Windows') {
        system('stty echo');
    }

    echo PHP_EOL;

    return $password;
}

$username = ask('Username admin: ');

if (!preg_match('/^[A-Za-z0-9_.-]{3,40}$/', $username)) {
    exit("Username tidak valid.\n");
}

$password = hiddenPassword('Password admin: ');

if (strlen($password) < 10) {
    exit("Password minimal 10 karakter.\n");
}

$confirm = hiddenPassword('Ulangi password: ');

if ($password !== $confirm) {
    exit("Password tidak sama.\n");
}

$hash = password_hash($password, PASSWORD_DEFAULT);

$stmt = $pdo->prepare("
    INSERT INTO admins
        (username, password_hash, is_active)
    VALUES
        (?, ?, 1)
    ON DUPLICATE KEY UPDATE
        password_hash = VALUES(password_hash),
        is_active = 1
");

$stmt->execute([$username, $hash]);

echo "ADMIN BERHASIL DIBUAT ✅\n";
