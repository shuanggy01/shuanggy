PATCH BOT POST + SEO - AsupanLendir

File yang diubah:
- public/telegram/bot.php
- storage/bot/seo-settings.json

Cara pakai:
1. Backup public/telegram/bot.php yang aktif.
2. Upload/replace public/telegram/bot.php dari patch ini.
3. Upload storage/bot/seo-settings.json (opsional; bot juga punya default jika file belum ada).
4. Buka Telegram bot dan kirim /seo.
5. Atur tombol:
   - Auto Deskripsi
   - Skip URL Duplikat
   - Auto Part Judul

Catatan:
- Setting hanya memengaruhi post baru dari bot.
- SEO manual lama di /admin/seo.php dan SEO Mobile tidak dihapus.
- Auto Part mengubah judul berakhiran angka 2-99 (mis. _3 setelah cleaning menjadi Part 3) dan memberi Part berikutnya pada judul yang sama persis.
- Skip URL Duplikat mengecek video_url sebelum thumbnail dibuat, sehingga menghemat proses FFmpeg untuk URL yang sudah pernah dipost.
