ASUPANLENDIR - BRANDING V2

Tujuan:
- Tambahkan ikon P ke header Homepage, Player, Album.
- Favicon final dari logo yang diberikan.
- OG/share image default untuk Homepage.
- Tidak mengubah fitur PHP, database, counter, bot, URL, player, album, search, SEO logic.

PASANG:
1. Upload branding-asupanlendir-v2.zip ke:
   /www/wwwroot/asupanlendir.sbs/public/

2. Extract langsung di folder public.

3. Jalankan:
   cd /www/wwwroot/asupanlendir.sbs/public
   python3 apply-branding-v2.py

4. Cek:
   php -l index.php
   php -l watch.php
   php -l collection.php

Backup otomatis:
- index.php.bak-branding-v2
- watch.php.bak-branding-v2
- collection.php.bak-branding-v2
