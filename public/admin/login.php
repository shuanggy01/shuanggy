<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/app/admin.php';

if (admin_logged_in() || admin_attempt_remember_login($pdo)) {
    admin_redirect('/admin/mobile.php');
}

$error = null;
$username = '';
$remember = true;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    admin_verify_csrf();

    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');
    $remember = isset($_POST['remember']);

    $stmt = $pdo->prepare(<<<'SQL'
SELECT id, username, password_hash
FROM admins
WHERE username = ?
  AND is_active = 1
LIMIT 1
SQL);
    $stmt->execute([$username]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($admin && password_verify($password, (string) $admin['password_hash'])) {
        session_regenerate_id(true);

        $_SESSION['admin_id'] = (int) $admin['id'];
        $_SESSION['admin_username'] = (string) $admin['username'];
        unset($_SESSION['remember_login']);

        $pdo->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = ?')
            ->execute([(int) $admin['id']]);

        if ($remember) {
            admin_remember_issue($pdo, (int) $admin['id']);
        } else {
            admin_remember_revoke_current($pdo);
        }

        admin_redirect('/admin/mobile.php');
    }

    usleep(350000);
    $error = 'Username atau password salah.';
}
?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="theme-color" content="#0B0D10">
<meta name="color-scheme" content="dark">
<title>Login Admin - AsupanLendir</title>
<style>
:root{--bg:#0B0D10;--panel:#13171D;--panel2:#171C22;--border:#29313A;--gold:#EAB84D;--gold2:#F5C75B;--text:#F7F8FA;--muted:#98A2B3;--muted2:#667085;--danger:#FF7A7A;--danger-bg:rgba(239,68,68,.09)}
*{box-sizing:border-box}html{min-height:100%;background:var(--bg)}body{margin:0;min-height:100dvh;background:radial-gradient(circle at 50% -10%,rgba(234,184,77,.13),transparent 34%),linear-gradient(180deg,#0B0D10 0%,#090B0D 100%);color:var(--text);font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;-webkit-font-smoothing:antialiased}.shell{width:min(100%,460px);min-height:100dvh;margin:0 auto;padding:max(34px,env(safe-area-inset-top)) 18px max(26px,env(safe-area-inset-bottom));display:flex;flex-direction:column;justify-content:center}.brand{text-align:center;margin-bottom:27px}.mark-wrap{width:70px;height:70px;margin:0 auto 15px;padding:5px;border-radius:23px;background:linear-gradient(145deg,rgba(234,184,77,.2),rgba(234,184,77,.03));border:1px solid rgba(234,184,77,.24);box-shadow:0 18px 50px rgba(0,0,0,.32)}.mark{display:block;width:100%;height:100%;object-fit:cover;border-radius:18px}.brand .eyebrow{color:var(--gold);font-size:10px;font-weight:850;letter-spacing:2px;text-transform:uppercase}.brand h1{margin:8px 0 0;font-size:29px;line-height:1.05;letter-spacing:-.8px}.brand p{margin:9px auto 0;max-width:300px;color:var(--muted);font-size:12px;line-height:1.6}.card{padding:18px;background:linear-gradient(160deg,rgba(255,255,255,.025),rgba(255,255,255,0)),var(--panel);border:1px solid var(--border);border-radius:22px;box-shadow:0 26px 80px rgba(0,0,0,.3)}.error{margin-bottom:14px;padding:12px 13px;border:1px solid rgba(255,122,122,.18);border-radius:13px;background:var(--danger-bg);color:#FFAAAA;font-size:12px;line-height:1.45}.field{margin-top:14px}.field:first-of-type{margin-top:0}.field label{display:block;margin:0 0 8px;color:#C7CDD5;font-size:11px;font-weight:750}.control{position:relative}.control input{width:100%;height:52px;padding:0 14px;border:1px solid #303842;border-radius:14px;outline:0;background:#0D1014;color:var(--text);font:inherit;font-size:16px;transition:border-color .18s,box-shadow .18s,background .18s}.control input::placeholder{color:#515A65}.control input:focus{border-color:rgba(234,184,77,.72);background:#0F1216;box-shadow:0 0 0 4px rgba(234,184,77,.08)}.control.has-action input{padding-right:52px}.eye{position:absolute;right:6px;top:6px;width:40px;height:40px;display:grid;place-items:center;border:0;border-radius:11px;background:transparent;color:var(--muted);cursor:pointer}.eye:active{background:rgba(255,255,255,.05)}.eye svg{width:20px;height:20px}.remember{margin-top:15px;display:flex;align-items:flex-start;gap:10px;padding:1px 2px}.remember input{appearance:none;width:20px;height:20px;flex:0 0 20px;margin:1px 0 0;border:1px solid #3A424C;border-radius:6px;background:#0D1014;display:grid;place-items:center}.remember input:checked{border-color:var(--gold);background:var(--gold)}.remember input:checked:after{content:"";width:9px;height:5px;border-left:2px solid #17130A;border-bottom:2px solid #17130A;transform:translateY(-1px) rotate(-45deg)}.remember strong{display:block;font-size:12px;line-height:1.35}.remember span{display:block;margin-top:3px;color:var(--muted);font-size:10px;line-height:1.45}.submit{width:100%;height:52px;margin-top:18px;border:0;border-radius:14px;background:linear-gradient(180deg,var(--gold2),var(--gold));color:#17130A;font:inherit;font-size:12px;font-weight:900;letter-spacing:.7px;text-transform:uppercase;box-shadow:0 12px 30px rgba(234,184,77,.13);cursor:pointer}.submit:active{transform:translateY(1px)}.trust{margin-top:15px;display:flex;align-items:center;justify-content:center;gap:7px;color:var(--muted2);font-size:9px;line-height:1.3}.trust svg{width:13px;height:13px;color:var(--gold)}.foot{margin-top:24px;text-align:center;color:#515A65;font-size:9px;line-height:1.6}.desktop-link{display:inline-block;margin-top:8px;color:var(--muted);text-decoration:none}.desktop-link:hover{color:var(--gold)}@media(min-width:700px){.shell{padding-left:24px;padding-right:24px}.card{padding:22px}}
</style>
</head>
<body>
<main class="shell">
    <header class="brand">
        <div class="mark-wrap"><img class="mark" src="/assets/brand-mark.png" alt=""></div>
        <div class="eyebrow">Admin Control</div>
        <h1>AsupanLendir</h1>
        <p>Masuk ke dashboard mobile untuk mengelola video, iklan, dan pengaturan website.</p>
    </header>

    <section class="card" aria-label="Login admin">
        <?php if ($error !== null): ?>
            <div class="error" role="alert"><?= admin_e($error) ?></div>
        <?php endif; ?>

        <form method="post" action="/admin/login.php" autocomplete="on">
            <?= admin_csrf_input() ?>

            <div class="field">
                <label for="username">Username</label>
                <div class="control">
                    <input id="username" name="username" type="text" value="<?= admin_e($username) ?>" autocomplete="username" autocapitalize="none" spellcheck="false" placeholder="Masukkan username" required autofocus>
                </div>
            </div>

            <div class="field">
                <label for="password">Password</label>
                <div class="control has-action">
                    <input id="password" name="password" type="password" autocomplete="current-password" placeholder="Masukkan password" required>
                    <button class="eye" type="button" id="togglePassword" aria-label="Tampilkan password" aria-pressed="false">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M2.5 12s3.5-6 9.5-6 9.5 6 9.5 6-3.5 6-9.5 6S2.5 12 2.5 12Z"/><circle cx="12" cy="12" r="2.5"/></svg>
                    </button>
                </div>
            </div>

            <label class="remember" for="remember">
                <input id="remember" name="remember" type="checkbox" value="1" <?= $remember ? 'checked' : '' ?>>
                <span><strong>Ingat saya 30 hari</strong><span>Password tidak disimpan oleh website. Perangkat ini memakai token login aman.</span></span>
            </label>

            <button class="submit" type="submit">Masuk Admin</button>
        </form>

        <div class="trust">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="5" y="10" width="14" height="10" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
            <span>Gunakan Remember Me hanya di perangkat pribadi</span>
        </div>
    </section>

    <footer class="foot">Dashboard utama setelah login: Mobile Admin<br><a class="desktop-link" href="/admin/desktop.php">Dashboard desktop tetap tersedia</a></footer>
</main>
<script>
(()=>{
    const input=document.getElementById('password');
    const button=document.getElementById('togglePassword');
    if(!input||!button)return;
    button.addEventListener('click',()=>{
        const show=input.type==='password';
        input.type=show?'text':'password';
        button.setAttribute('aria-pressed',show?'true':'false');
        button.setAttribute('aria-label',show?'Sembunyikan password':'Tampilkan password');
        input.focus({preventScroll:true});
    });
})();
</script>
</body>
</html>
