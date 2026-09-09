<?php

declare(strict_types=1);

$page = trim((string) ($_GET['page'] ?? ''));

$pages = [
    'privacy' => 'Privacy Policy',
    'terms' => 'Terms of Service',
    'copyright' => 'Copyright & Takedown',
    'disclaimer' => 'Disclaimer',
    'contact' => 'Contact & Support',
];

if (!isset($pages[$page])) {
    http_response_code(404);
    exit('Halaman tidak ditemukan.');
}

require dirname(__DIR__) . '/app/site_footer.php';

function lg_e(?string $value): string
{
    return htmlspecialchars(
        (string) $value,
        ENT_QUOTES | ENT_SUBSTITUTE,
        'UTF-8'
    );
}

$title = $pages[$page];

?>
<!doctype html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta
    name="viewport"
    content="width=device-width, initial-scale=1, viewport-fit=cover"
>
<meta name="theme-color" content="#0F1115">
<meta name="robots" content="noindex,follow">

<title><?= lg_e($title) ?> - AsupanLendir</title>

<link rel="stylesheet" href="/assets/legal-pages-v1.css?v=1">
<link rel="icon" href="/favicon.ico">
</head>

<body>

<header class="legal-header">
    <a class="legal-brand" href="/">
        <img src="/assets/brand-mark.png" alt="">
        <strong>AsupanLendir</strong>
    </a>

    <a class="legal-home" href="/">
        Homepage
    </a>
</header>


<main class="legal-main">

<section class="legal-card">

<div class="legal-eyebrow">INFORMATION</div>

<h1><?= lg_e($title) ?></h1>


<?php if ($page === 'privacy'): ?>

<p>
    AsupanLendir menghargai privasi pengunjung. Kami berusaha
    mengumpulkan data seminimal mungkin untuk menjalankan website,
    menjaga keamanan, menghitung statistik, dan meningkatkan layanan.
</p>

<h2>Data teknis</h2>

<p>
    Website dapat memproses informasi teknis seperti jenis browser,
    perangkat, waktu akses, cookie sesi, dan data keamanan yang
    diperlukan agar website berfungsi dengan baik.
</p>

<h2>Statistik kunjungan</h2>

<p>
    Sistem dapat menggunakan identitas browser acak atau cookie untuk
    menghitung kunjungan unik dan mencegah penghitungan berulang.
    Data ini digunakan untuk statistik internal.
</p>

<h2>Layanan pihak ketiga</h2>

<p>
    Website dapat memuat layanan pihak ketiga seperti jaringan iklan,
    CDN, penyedia keamanan, atau layanan analitik. Penyedia tersebut
    dapat menerapkan cookie atau teknologi mereka sendiri sesuai
    kebijakan masing-masing.
</p>

<h2>Keamanan</h2>

<p>
    Kami menggunakan langkah teknis yang wajar untuk mengurangi
    penyalahgunaan, akses tidak sah, dan aktivitas berbahaya.
</p>

<h2>Kontak privasi</h2>

<p>
    Pertanyaan terkait privasi dapat dikirim ke
    <a href="mailto:ebus@asupanlendir.sbs">
        ebus@asupanlendir.sbs
    </a>.
</p>


<?php elseif ($page === 'terms'): ?>

<p>
    Dengan menggunakan AsupanLendir, pengunjung dianggap telah membaca
    dan menyetujui ketentuan penggunaan berikut.
</p>

<h2>Usia pengguna</h2>

<p>
    Layanan ini hanya ditujukan untuk pengguna yang telah berusia
    minimal 18 tahun atau telah mencapai usia dewasa yang berlaku
    di wilayah hukumnya.
</p>

<h2>Penggunaan layanan</h2>

<p>
    Pengguna dilarang menyalahgunakan website, mencoba mengakses area
    administratif tanpa izin, mengganggu layanan, melakukan scraping
    agresif, atau menggunakan website untuk kegiatan yang melanggar
    hukum.
</p>

<h2>Ketersediaan layanan</h2>

<p>
    Fitur, konten, URL, dan layanan dapat berubah, dihentikan,
    dipindahkan, atau diperbarui sewaktu-waktu.
</p>

<h2>Layanan pihak ketiga</h2>

<p>
    Beberapa tautan, iklan, video, atau layanan dapat berasal dari pihak
    ketiga. AsupanLendir tidak mengendalikan seluruh isi dan kebijakan
    layanan eksternal tersebut.
</p>

<h2>Pelanggaran</h2>

<p>
    Akses dapat dibatasi apabila terdapat aktivitas yang dianggap
    berbahaya, otomatis, abusif, atau melanggar ketentuan ini.
</p>


<?php elseif ($page === 'copyright'): ?>

<p>
    AsupanLendir menghormati hak kekayaan intelektual dan menerima
    laporan yang jelas terkait dugaan pelanggaran hak cipta.
</p>

<h2>Permintaan penghapusan</h2>

<p>
    Pemegang hak atau perwakilan yang sah dapat mengirim permintaan
    pemeriksaan atau penghapusan konten ke:
</p>

<div class="legal-contact-box">
    <strong>Copyright / Takedown</strong>
    <a href="mailto:ebus@asupanlendir.sbs">
        ebus@asupanlendir.sbs
    </a>
</div>

<h2>Informasi yang perlu disertakan</h2>

<ul>
    <li>Identitas dan informasi kontak pelapor.</li>
    <li>Penjelasan karya atau materi yang diklaim.</li>
    <li>URL halaman AsupanLendir yang dimaksud.</li>
    <li>Penjelasan dasar kepemilikan atau kewenangan pelapor.</li>
    <li>
        Pernyataan bahwa informasi yang diberikan benar dan diajukan
        dengan itikad baik.
    </li>
</ul>

<p>
    Laporan yang tidak lengkap dapat membutuhkan informasi tambahan
    sebelum dapat diproses.
</p>


<?php elseif ($page === 'disclaimer'): ?>

<p>
    Informasi dan konten pada website disediakan sebagaimana adanya.
    AsupanLendir tidak menjamin bahwa seluruh konten akan selalu
    tersedia, bebas gangguan, atau cocok untuk setiap pengguna.
</p>

<h2>Konten pihak ketiga</h2>

<p>
    Sebagian konten, media, iklan, atau tautan dapat berasal dari
    penyedia pihak ketiga. Masing-masing pihak bertanggung jawab atas
    layanan dan kebijakannya sendiri.
</p>

<h2>Tautan eksternal</h2>

<p>
    Mengklik tautan eksternal dapat membawa pengguna keluar dari
    AsupanLendir. Pengguna disarankan memeriksa alamat tujuan dan
    kebijakan situs yang dikunjungi.
</p>

<h2>Tanggung jawab pengguna</h2>

<p>
    Pengguna bertanggung jawab mematuhi hukum, aturan usia, dan
    ketentuan yang berlaku di wilayah masing-masing.
</p>


<?php elseif ($page === 'contact'): ?>

<p>
    Untuk dukungan website, laporan teknis, pertanyaan umum,
    atau permintaan terkait konten, silakan hubungi:
</p>

<div class="legal-contact-box">
    <strong>Support</strong>
    <a href="mailto:ebus@asupanlendir.sbs">
        ebus@asupanlendir.sbs
    </a>
</div>

<h2>Saat menghubungi kami</h2>

<p>
    Sertakan URL halaman yang dimaksud dan penjelasan singkat masalah
    agar laporan lebih mudah diperiksa.
</p>

<h2>Copyright / Takedown</h2>

<p>
    Untuk laporan hak cipta, gunakan email yang sama dan sertakan
    informasi sebagaimana dijelaskan pada halaman
    <a href="/copyright">Copyright & Takedown</a>.
</p>

<?php endif; ?>


<div class="legal-updated">
    Terakhir diperbarui: 29 Agustus 2026
</div>

</section>

</main>


<?= site_footer_render() ?>

</body>
</html>
