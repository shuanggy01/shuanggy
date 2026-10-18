<?php
declare(strict_types=1);

const ROOT = __DIR__ . '/..';
ini_set('display_errors', '0');
ini_set('log_errors', '1');
$configFile = ROOT . '/config.php';
$config = is_file($configFile) ? require $configFile : [];
define('DATA_DIR', $config['data_dir'] ?? ROOT . '/var');
if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0700, true)) { http_response_code(500); exit('Folder data tidak dapat dibuat.'); }
if (!extension_loaded('pdo_sqlite') || !extension_loaded('curl') || !extension_loaded('openssl') || !extension_loaded('simplexml')) {
    http_response_code(503); exit('Aktifkan ekstensi PHP: pdo_sqlite, curl, openssl, dan SimpleXML. Lihat PANDUAN.md.');
}
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
header('Cache-Control: no-store');
// Per-site session files: independent of the shared PHP session.save_path.
$sessionRoot = realpath(DATA_DIR);
if ($sessionRoot === false) { http_response_code(503); exit('Folder data sesi tidak tersedia. Periksa folder var.'); }
$sessionDir = $sessionRoot . '/sessions';
if (!is_dir($sessionDir) && !@mkdir($sessionDir, 0700, true) && !is_dir($sessionDir)) {
    http_response_code(503); exit('Folder var/sessions belum bisa dibuat. Pastikan folder var dapat ditulis oleh pengguna www.');
}
if (!is_writable($sessionDir)) {
    http_response_code(503); exit('Folder var/sessions tidak dapat ditulis. Periksa pemilik folder di aaPanel.');
}
ini_set('session.save_handler', 'files');
session_save_path($sessionDir);
if (ini_get('session.save_handler') !== 'files' || session_save_path() !== $sessionDir) {
    http_response_code(503); exit('PHP belum mengizinkan penyimpanan sesi lokal. Periksa pengaturan PHP website.');
}
ini_set('session.gc_maxlifetime', '14400');
ini_set('session.gc_probability', '1');
ini_set('session.gc_divisor', '100');
$isHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('mobile_player');
session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>$isHttps,'httponly'=>true,'samesite'=>'Lax']);
if (!session_start()) { http_response_code(503); exit('Sesi belum dapat disimpan. Periksa izin folder var/sessions.'); }
if (!isset($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
function db(): PDO {
    static $db;
    if (!$db) {
        $db = new PDO('sqlite:' . DATA_DIR . '/player.sqlite', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
        $db->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000; PRAGMA foreign_keys=ON;');
        $db->exec('CREATE TABLE IF NOT EXISTS settings (key TEXT PRIMARY KEY,value TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS admins (id INTEGER PRIMARY KEY,username TEXT UNIQUE NOT NULL,password TEXT NOT NULL);
        CREATE TABLE IF NOT EXISTS videos (id TEXT PRIMARY KEY,title TEXT NOT NULL,video_key TEXT NOT NULL,thumb_key TEXT NOT NULL,video_url TEXT NOT NULL,thumb_url TEXT NOT NULL,views INTEGER NOT NULL DEFAULT 0,created_at INTEGER NOT NULL);
        CREATE INDEX IF NOT EXISTS videos_url_idx ON videos(video_url);
        CREATE TABLE IF NOT EXISTS uploads (id TEXT PRIMARY KEY,object_key TEXT NOT NULL,kind TEXT NOT NULL,mime TEXT NOT NULL,size INTEGER NOT NULL,multipart_id TEXT,status TEXT NOT NULL,created_at INTEGER NOT NULL);
        CREATE TABLE IF NOT EXISTS attempts (ip TEXT PRIMARY KEY,hits INTEGER NOT NULL,started INTEGER NOT NULL);');
        @chmod(DATA_DIR . '/player.sqlite', 0600);
    }
    return $db;
}
function setting(string $key, $fallback='') {
    $s=db()->prepare('SELECT value FROM settings WHERE key=?');$s->execute([$key]);$v=$s->fetchColumn();
    return $v===false ? $fallback : json_decode($v,true);
}
function putSetting(string $key,$value): void { $s=db()->prepare('INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value');$s->execute([$key,json_encode($value,JSON_UNESCAPED_SLASHES)]); }
function h($v): string {return htmlspecialchars((string)$v,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
function jsonOut($value,int $code=200): never {http_response_code($code);header('Content-Type: application/json; charset=utf-8');echo json_encode($value,JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);exit;}
function fail(string $message,int $status=400): never {jsonOut(['error'=>$message],$status);}
function csrf(): void {if (!hash_equals($_SESSION['csrf'],(string)($_SERVER['HTTP_X_CSRF_TOKEN']??$_POST['csrf']??''))) fail('Sesi formulir kedaluwarsa. Muat ulang halaman.',403);}
function requireAdmin(): void {if (empty($_SESSION['admin']) || time()-(int)($_SESSION['last_active']??0)>14400) {unset($_SESSION['admin']);fail('Silakan login kembali.',401);}$_SESSION['last_active']=time();}
function validUrl(string $url,bool $optional=true): string {
    $url=trim($url);if ($url==='' && $optional) return '';
    if (!filter_var($url,FILTER_VALIDATE_URL) || parse_url($url,PHP_URL_SCHEME)!=='https' || parse_url($url,PHP_URL_USER)!==null || parse_url($url,PHP_URL_PASS)!==null) throw new RuntimeException('URL harus HTTPS yang valid.');
    return $url;
}
require_once __DIR__.'/ads.php';
require_once __DIR__.'/play-links.php';
function defaults(): array {
    return ['site_name'=>'Layar','vip_url'=>'','download'=>true,'pretty_links'=>false,'media_proxy'=>false,'ads'=>adDefaults()];
}
function settings(): array {$site=array_replace(defaults(),setting('site',[]));$site['ads']=mergeAds($site['ads']??[]);return $site;}
function encKey(): string {
    $path=DATA_DIR.'/.key';
    if (!is_file($path)) { $f=@fopen($path,'x'); if ($f) {chmod($path,0600);fwrite($f,random_bytes(32));fclose($f);} }
    $key=file_get_contents($path);if (strlen($key)!==32) throw new RuntimeException('Kunci penyimpanan tidak valid.');return $key;
}
function encryptSecret(string $value): string { $iv=random_bytes(12);$tag='';$cipher=openssl_encrypt($value,'aes-256-gcm',encKey(),OPENSSL_RAW_DATA,$iv,$tag);if($cipher===false)throw new RuntimeException('Enkripsi gagal.');return base64_encode($iv.$tag.$cipher); }
function decryptSecret(string $value): string {if($value==='')return '';$data=base64_decode($value,true);if($data===false||strlen($data)<28)throw new RuntimeException('Kredensial rusak.');$plain=openssl_decrypt(substr($data,28),'aes-256-gcm',encKey(),OPENSSL_RAW_DATA,substr($data,0,12),substr($data,12,16));if($plain===false)throw new RuntimeException('Kredensial tidak bisa dibaca. Pulihkan file var/.key.');return $plain;}
function r2Config(): array {$r=setting('r2',[]);foreach(['access','secret'] as $k)$r[$k]=decryptSecret($r[$k]??'');return $r;}
function uploadRow(string $id): array {$q=db()->prepare('SELECT * FROM uploads WHERE id=?');$q->execute([$id]);$u=$q->fetch();if(!$u)throw new RuntimeException('Upload tidak ditemukan.');if($u['created_at']<time()-172800)throw new RuntimeException('Upload kedaluwarsa. Mulai upload baru.');return $u;}
function publicObject(string $key): string {return rtrim(r2Config()['public'],'/').'/'.implode('/',array_map('rawurlencode',explode('/',$key)));}
function findVideo(string $id): ?array {$q=db()->prepare('SELECT * FROM videos WHERE id=?');$q->execute([$id]);return $q->fetch()?:null;}
