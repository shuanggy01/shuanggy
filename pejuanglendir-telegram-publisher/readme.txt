PejuangLendir Telegram Publisher V1.9.3.1

AUTO THUMBNAIL ROTATION
-----------------------
V1.9.1 tetap memakai meta native WP-Script/RetroTube yang kompatibel dengan UltimaTube.

UltimaTube membaca:
get_post_meta($post_id, 'thumbs', false)

Karena itu plugin menyimpan setiap cuplikan sebagai repeated post meta:

thumbs = https://play.pejuanglendir.cc/.../frame-01.webp
thumbs = https://play.pejuanglendir.cc/.../frame-02.webp
thumbs = https://play.pejuanglendir.cc/.../frame-03.webp
...

Tidak ada JavaScript preview custom.

ALUR
----
Telegram single / bulk
-> post WordPress dibuat
-> _pltp_rotation_pending = 1
-> OS rotation worker
-> FFprobe durasi
-> FFmpeg ambil 4 atau 5 frame
-> WebP 480x270 quality 72
-> upload langsung ke Rotation R2
-> repeated meta `thumbs`
-> UltimaTube data-thumbs
-> hover rotation native theme

DEFAULT 5 FRAME
---------------
10% / 30% / 50% / 70% / 90%

MODE 4 FRAME
------------
15% / 38% / 62% / 85%

ROTATION R2
-----------
Account ID, Access Key, Secret:
memakai credential R2 utama yang sudah tersimpan.

Rotation dapat memakai bucket berbeda.

Setting:
Settings -> Telegram Publisher -> Thumbnail Rotation

Rotation Public URL default:
https://play.pejuanglendir.cc

Jika Rotation Bucket dikosongkan:
pakai bucket utama.

OBJECT KEY
----------
rotation/YYYY/MM/post-ID/frame-01.webp

WORKER
------
wp-content/plugins/pejuanglendir-telegram-publisher/bin/rotation-worker.sh

Web PHP tetap tidak membutuhkan exec().
FFmpeg dan FFprobe hanya dijalankan OS worker.

CRON
----
Disarankan setiap menit:

* * * * * www /bin/bash /www/wwwroot/pejuanglendir.site/wp-content/plugins/pejuanglendir-telegram-publisher/bin/rotation-worker.sh >> /www/wwwroot/pejuanglendir.site/wp-content/uploads/pltp-worker/rotation.log 2>&1

Worker memproses maksimal 2 post per run untuk menjaga beban server.

BULK
----
Bulk tidak lagi dibatasi 10 video oleh plugin. Semua item yang muat dalam satu pesan Telegram diantrikan dan diproses satu per satu oleh worker.
Rotation dikerjakan background, jadi webhook Telegram tidak menunggu FFmpeg.

UPGRADE
-------
Upload V1.9.1 -> Replace current plugin.

Webhook, Bulk, Rank Math, R2 Featured Image, kategori dan duration worker
tetap menggunakan setting V1.8.2.


V1.9.1 - Mobile Admin Polish
- Tidak mengubah parser Telegram, R2 offload, Rank Math, worker, atau struktur option.
- Accordion mobile untuk Posting & Bot, Cloudflare R2, Thumbnail Rotation, Auto Category, Rank Math SEO, dan Webhook.
- Tombol Simpan Pengaturan sticky di layar kecil.
- Input/select/textarea dibuat touch-friendly di mobile.
- URL/code panjang dibungkus agar tidak merusak layout.
- Desktop tetap memakai layout WordPress normal.


V1.9.1.1 Auto Thumbnail Patch
- Baseline: V1.9.1 Mobile Admin Polish.
- 2-line Telegram format: title + direct MP4 => auto-thumbnail queue.
- 3-line format stays unchanged for manual thumbnails.
- Historical note: V1.9.1.1 used 3x3 portrait collage; superseded by V1.9.1.8 single-frame mode.
- Auto thumbnail becomes WordPress Featured Image and follows the existing main R2 safe offload.
- Uses a separate OS worker; existing duration-worker.sh and rotation-worker.sh are unchanged.
- Share Player /f/ is not included.


V1.9.1.2 Bulk Queue Patch
- Menghapus batas 10 video dari plugin.
- Bulk diproses background satu per satu, bukan di request webhook.
- Webhook cepat membalas sehingga risiko timeout/retry Telegram turun.
- Satu pesan antrian diterima + satu ringkasan setelah batch selesai; tidak spam balasan per video.
- Batas praktis tetap mengikuti batas panjang satu pesan Telegram.


V1.9.1.3 Bulk Progress Patch
- Baseline tetap V1.9.1 stable + Auto Thumbnail + Bulk Queue.
- Setiap video mengirim status segera setelah selesai diproses: [1/N], [2/N], dst.
- Status publish/draft menyertakan info thumbnail, R2, duration, rotation, category, Rank Math, judul dan link.
- Duplikat dan gagal juga dilaporkan langsung per item.
- Ringkasan akhir hanya total batch karena detail sudah dikirim per video.
- Worker duration, rotation, dan thumbnail tidak diubah.


V1.9.1.4 Performance Safe Queue
- Bulk Queue: maksimal 1 video per worker run (progress per video tetap aktif).
- Auto Thumbnail: maksimal 1 video per run.
- Rotation: maksimal 1 video per run dan menunggu auto-thumbnail post yang sama selesai.
- Thumbnail + Rotation memakai shared heavy-media lock agar FFmpeg tidak berjalan bersamaan.
- FFmpeg background dibatasi ke 1 thread dan dijalankan dengan CPU/I/O priority rendah.
- Duration worker: maksimal 2 video per run dengan priority rendah.
- Tidak mengubah parser, R2, Rank Math, webhook, format Telegram, download page, atau cron filename.


V1.9.1.5 Title + Actors + Tags
- Baseline: V1.9.1.4 Performance Safe, semua worker/cadence tetap.
- Judul otomatis membersihkan underscore (_), pemisah dash, dan spasi ganda.
- Baris opsional Telegram: Aktor: Nama Aktor
- Banyak aktor dapat dipisah koma, contoh: Aktor: Icha Hyper, Gek Diah
- Memakai taxonomy native RetroTube: actors (rewrite /actor/).
- Maksimal 3 tag navigasi dibuat dari aktor + kata penting judul.
- Archive actors dan post_tag dipaksa noindex,follow (WP robots + Rank Math frontend robots).
- Thumbnail manual/auto, bulk queue, progress, R2, duration, rotation, Rank Math, dan auto category tetap.


V1.9.1.6 Smart Title & Factual Description
- Baseline: V1.9.1.5 + Performance Safe workers.
- Penomoran seri di akhir judul dinormalisasi: (1), [1], Part-1, Bagian 1, Episode 1, Ep 1 -> " - Part 1".
- Pembersihan underscore/dash tetap aktif untuk post baru.
- Smart Description aktif default untuk post Telegram baru dan hanya memakai metadata faktual: judul, actors native RetroTube, kategori, tag, dan durasi.
- Duration worker me-refresh Smart Description + Rank Math description setelah FFprobe menyimpan durasi sebenarnya.
- Hanya post yang ditandai _pltp_smart_content_managed=1 yang boleh di-refresh otomatis; post manual/legacy tidak disentuh.
- Post Description Template tetap tersedia jika Smart Title & Description dimatikan dari pengaturan plugin.
- Actors dan tag archive tetap noindex,follow.
- Bulk progress, R2, auto-thumbnail, rotation, dan performance-safe queue tetap dipertahankan.
- Smart Description membantu struktur dan variasi faktual halaman, tetapi tidak menjamin ranking/indexing Google.


V1.9.1.7 Safe Full Sync / Backfill
- Menyeragamkan post video lama dari ID paling kecil sampai terbaru secara bertahap.
- Judul dibersihkan tetapi slug/permalink lama dipertahankan agar link yang sudah dibagikan tidak berubah.
- Aktor lama dipertahankan; post tanpa aktor hanya dicocokkan dengan taxonomy actors yang SUDAH ADA dan benar-benar muncul di judul. Tidak menebak nama baru.
- Tag lama dipertahankan; post tanpa tag dibuatkan maksimal 3 tag navigasi.
- Featured Image lama tidak ditimpa. Auto Thumbnail hanya diantrikan jika benar-benar belum ada thumbnail.
- Featured Image lokal diperiksa/offload ke R2 bila R2 aktif.
- Duration/rotation hanya diantrikan jika data belum ada.
- Rank Math + VideoObject disegarkan.
- Download tracking_url disinkronkan bila PejuangLendir Player & Download aktif.
- post_views_count tidak pernah di-reset; hanya diinisialisasi 0 jika meta belum ada.
- Smart Description hanya menimpa konten kosong/auto-managed, bukan deskripsi manual panjang.
- Worker metadata memproses 1 post per menit agar aman untuk server.


V1.9.1.8 Single Main Thumbnail + 5-Frame Rotation
- Post baru dengan auto-thumbnail sekarang selalu memakai 1 cuplikan/frame sebagai Featured Image.
- Collage/grid 3x3 untuk video portrait dihapus dari auto-thumbnail worker.
- Rasio frame utama dipertahankan dari sumber; RetroTube tetap bebas melakukan crop visual pada kartu/listing.
- Thumbnail rotation native RetroTube tetap berjalan terpisah melalui meta `thumbs` dan konfigurasi 5 cuplikan yang sudah ada.
- Post/thumbnail lama tidak dibackfill atau diubah oleh patch ini.
- Telegram parser, R2, Rank Math, Smart Description, actors/tags, bulk queue, duration worker, rotation worker, dan permalink tidak diubah.

V1.9.1.9 Rotation Worker Reliability Patch
- No change to main thumbnail behavior: still one single frame for new posts.
- RetroTube rotation remains fixed at 5 frames.
- Newest pending post is prioritized.
- A post waiting for auto-thumbnail no longer blocks the next rotation candidate.
- FFmpeg gets longer per-frame timeouts and up to 3 fast-seek retries before fallback.
- Manual runs now show per-frame progress instead of looking frozen.
- R2 commit errors are surfaced in the worker log for easier diagnosis.

V1.9.1.10 Portrait-safe Rotation Frame Patch
--------------------------------------------
- Main/Featured Image tetap 1 cuplikan.
- Rotation tetap 5 frame pada 10%, 30%, 50%, 70%, 90%.
- Frame rotation portrait tidak lagi dipaksa cover/crop ke 16:9.
- Worker sekarang memakai contain + black padding (scale decrease + pad 480x270).
- Mencegah poster/preview tampak zoom setelah metadata `thumbs` selesai dibuat.


V1.9.1.11 Auto Thumbnail + Resume Repair (19 Oktober 2026)
- Stable baseline 1.9.1.10 retained; only additive media orchestration.
- After successful Telegram publish/draft, immediately requests background media worker.
- Cron fallback every minute (requires WP-Cron to run; PHP shell execution must be permitted).
- Admin Settings > Telegram Publisher: Repair Thumbnail Lama; batches 120 posts per tick.
- Keeps already complete main thumbnail and 5 repeated RetroTube `thumbs` values.
- Resets failed attempts only on missing/unfinished records when admin starts repair.
- Automatically runs existing OS thumbnail-worker.sh and rotation-worker.sh with locks.
- Logs to OS temporary directory pejuanglendir-auto-media.log.
- Existing R2, player, Rank Math, webhook, and Telegram format unchanged.
- Optional aaPanel Shell Script (once/minute) for servers with disabled PHP exec:
  /bin/bash /www/wwwroot/pejuanglendir.site/wp-content/plugins/pejuanglendir-telegram-publisher/bin/auto-media-cron.sh
  (Legacy .site is the actual WordPress filesystem directory, not the public domain.)


V1.9.2 Rotation Always-On + Reliability
- Thumbnail Rotation sekarang AKTIF secara default dan otomatis dinyalakan satu kali saat upgrade
  (sebelumnya default OFF sehingga posting baru tidak pernah diantrikan rotasinya). Masih bisa dimatikan manual di Settings.
- Worker (bin/*.sh) tidak lagi memakai path hardcode: ROOT/PHP/WP-CLI/FFmpeg/FFprobe dideteksi otomatis lewat bin/common.sh.
  Override opsional: buat bin/config.local.sh berisi PLTP_ROOT=, PLTP_PHP=, PLTP_WP=, PLTP_FFMPEG=, PLTP_FFPROBE=.
- Supervisor auto-media memproses beberapa video per launch (maks. 6, ubah dengan PLTP_MAX_ROUNDS) sehingga antrean menumpuk tidak lagi 1 video/menit.
- Post yang gagal 3x kini ditandai (_pltp_rotation_failed / _pltp_thumb_failed), ditampilkan di panel admin,
  dan bisa diantrikan ulang dengan tombol "Ulangi yang Gagal". Tanda dihapus otomatis saat berhasil.
- Pemicu worker setelah posting tidak lagi dibatalkan bila WP-Cron belum terjadwal.
- Setelah upgrade: klik "Repair Thumbnail Lama" sekali untuk mengantrekan rotasi post lama.


V1.9.3 Proses Langsung (tanpa worker)
- Setelah link.mp4 + judul masuk ke bot, thumbnail utama dan 5 frame rotasi langsung dibuat di PHP
  (includes/class-pltp-inline-media.php) setelah balasan Telegram dikirim, lalu diupload ke R2/WordPress.
- Tidak butuh cron atau bin/*.sh selama proc_open aktif di PHP dan ffmpeg/ffprobe ada
  (path dicari otomatis; bisa ditentukan lewat define('PLTP_FFMPEG','/path') di wp-config.php).
- Jika gagal/tidak tersedia, post tetap di antrean dan worker lama jadi cadangan. Bisa dimatikan di Settings > Proses Langsung.
- Pastikan proc_open tidak ada di disable_functions php.ini.

V1.9.3.1
- Deteksi ffmpeg/ffprobe tetap berhasil walau open_basedir aaPanel aktif.
- Status "Proses Langsung" sekarang menyebut penyebabnya (proc_open diblokir / ffmpeg tidak ditemukan).
