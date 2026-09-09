# Review sistem iklan — 2026-09-09

Temuan dari pembacaan `app/ads.php`, `config/ads.php`, `storage/ads.json`,
`public/admin/ads-mobile.php`, `public/admin/ad-mobile-edit.php`, dan
`public/assets/player-ads-v1.js`.

| # | Temuan | Status |
|---|--------|--------|
| 1 | Empat slot aktif tidak ada di panel admin | **selesai** |
| 2 | Editor slot menimpa struktur desktop/mobile | **selesai** |
| 3 | `ad_slot_variant()` mengabaikan kode nested | **selesai** |
| 4 | `/f/` Pause Ad dimatikan paksa di kode | **selesai** |
| 5 | Slot `header` tidak pernah dirender | terbuka |
| 6 | Label tidak sinkron antara config dan runtime | **selesai** |
| 7 | Overlay before-play tidak menahan playback | terbuka |

---

## 1. Empat slot aktif tidak bisa dikelola dari admin

`public/admin/ads.php` sekarang redirect ke `ads-mobile.php`, dan
`ads-mobile.php` / `ad-mobile-edit.php` hanya mendaftar 5 slot:
`home_top`, `player_before_play`, `player_before_play_2`, `player_below`,
`footer`.

Tapi `ad_render()` juga dipanggil untuk `home_mid`, `player_above`,
`player_related`, dan `album_top`. Keempatnya **aktif dan berisi kode iklan
live** di `storage/ads.json`, tetapi tidak muncul di panel mana pun.

Akibat: iklan tersebut tayang dan tidak bisa dimatikan, diganti, atau
diaudit tanpa mengedit JSON langsung di server.

**Perbaikan:** tambahkan keempat slot ke `$slotDefinitions` di
`ads-mobile.php` dan `ad-mobile-edit.php`.

---

## 2. Editor slot menimpa struktur desktop/mobile

`ad-mobile-edit.php` menyimpan slot dengan **mengganti seluruh entri**:

```php
$runtime['slots'][$slotName] = [
    'label' => $label, 'active' => $active,
    'target' => $target, 'code' => $code,
];
```

Kalau slot itu sebelumnya berbentuk nested (`desktop`/`mobile` dengan kode
berbeda), menyimpannya sekali dari panel akan **menghapus kode per-perangkat**
dan menggantinya dengan satu kode saja. Form pre-fill-nya mengambil kode
mobile dulu, jatuh ke desktop — jadi kode desktop hilang tanpa peringatan.

Ini yang membuat `storage/ads.json` sekarang campur dua format.

**Perbaikan:** merge, jangan replace — pertahankan `desktop`/`mobile` kalau
sudah ada, atau migrasikan seluruh JSON ke satu format lalu buang cabang
legacy di `ad_slot_variant()`.

---

## 3. `ad_slot_variant()` diam-diam mengabaikan kode nested

`app/ads.php:137`:

```php
if (array_key_exists('target', $slot) || array_key_exists('code', $slot)) {
```

Cabang flat dicek **lebih dulu**. `ad_config()` me-merge `config/ads.php` ke
`storage/ads.json` secara rekursif, jadi slot yang di `config/ads.php`
didefinisikan flat (`player_before_play_2`) tapi di runtime disimpan nested
akan berakhir punya **kedua** set kunci. Cabang flat menang dan membaca
`code` kosong dari default → iklan hilang tanpa error.

**Perbaikan:** prioritaskan `desktop`/`mobile` kalau ada, atau normalisasi
satu format saat load.

---

## 4. Slot yang bisa diedit tapi tidak pernah tayang

- `header` — ada di `config/ads.php` dan `storage/ads.json`, tidak pernah
  dipanggil `ad_render('header')` di mana pun.
- `player_on_pause` — dipaksa mati di `app/ads.php:172`
  (`$variant['active'] = false`), dan di Full Player dipaksa
  `active => false, code => ''` di `fp_ads_public_payload()`
  (`app/ads.php:458`).

Kalau on-pause memang mau dipakai, hardcode itu harus dibuang. Kalau tidak,
buang slotnya dari config supaya tidak menyesatkan.

---

## 5. Label tidak sinkron

`album_top` berlabel `"Kategori • Sebelum Daftar Video"` di `config/ads.php`
tapi `"Album • Sebelum Daftar Video"` di `storage/ads.json`. Runtime menang,
jadi label config praktis mati. Kosmetik, tapi bikin bingung saat debug.

---

## 6. Overlay before-play tidak menahan playback

`public/assets/player-ads-v1.js` memasang overlay lalu:

```js
video.addEventListener('play', () => { if (before) before.hidden = true; });
```

Overlay hanya disembunyikan saat play — tidak ada yang mencegah video jalan.
Kalau player autoplay atau user menekan kontrol native, overlay langsung
hilang dan impresi iklan tidak terjadi.

Overlay juga di-append ke `video.parentElement`. Kalau elemen itu
`position: static`, overlay absolut akan lepas dari player.

---

## 7. Catatan keamanan

- Kode iklan sengaja dirender mentah (`<?= $slot['code'] ?>`) — wajar untuk
  ad tag, tapi artinya **admin panel efektif setara RCE di browser
  pengunjung**. Pastikan login admin kuat dan `storage/` tidak web-accessible.
- Cleanup ini menghapus `public/admin/public/admin/mobile.php`: salinan basi
  (30 Agu) dari `public/admin/mobile.php` (4 Sep) yang **bisa diakses dari web**
  di `/admin/public/admin/mobile.php`. Kemungkinan besar efek samping patch
  script yang menulis ke path relatif salah.
- `.env` berisi `DB_PASSWORD` asli. Sekarang masuk `.gitignore`; template
  ada di `.env.example`. Kalau file itu pernah ter-push ke remote publik,
  rotasi password DB.


---

# Perbaikan yang sudah diterapkan

## Temuan 1 — slot yatim sekarang bisa dikelola

`home_mid`, `player_above`, `player_related`, dan `album_top` ditambahkan ke
`$slotDefinitions` di `ads-mobile.php` dan `ad-mobile-edit.php`. Sembilan slot
yang benar-benar dirender kini semuanya muncul di panel.

## Temuan 2 & 3 — satu bentuk slot, kode per-perangkat terpisah

- `ad_normalize_slot()` / `ad_normalize_slots()` baru di `app/ads.php`
  mengubah bentuk apa pun ke bentuk kanonik `label + desktop/mobile`.
- `ad_config()` menormalkan **per sumber** sebelum menggabung, jadi bentuk lama
  di `config/ads.php` tidak bisa lagi membayangi bentuk baru di
  `storage/ads.json`.
- `ad_slot_variant()` sekarang hanya membaca bentuk kanonik, dan idempoten.
- `ad-mobile-edit.php` punya kotak kode HP dan Desktop terpisah, plus opsi
  "Samakan dengan HP". Penyimpanan selalu dalam bentuk kanonik.
- `scripts/migrate-ads-slot-format.php` merapikan JSON lama (`--write`).

Regresi diverifikasi: seluruh slot live resolve byte-identik sebelum dan
sesudah perubahan, dan test slot dua-bentuk yang gagal di kode lama sekarang
lolos.

## Temuan 4 — Pause Ad `/f/` hidup kembali

`fp_ads_public_payload()` dulu memaksa `on_pause` jadi
`active => false, code => ''`, padahal form admin, penyimpanan JSON, dan
overlay di `full-player-ads-v3.js` semuanya sudah lengkap. Sekarang nilainya
dibaca dari konfigurasi, jadi kartu **Pause Ad** di `/admin/full-player-ads.php`
benar-benar berfungsi.

Hardcode serupa untuk `player_on_pause` di situs reguler juga dibuang dari
`ad_slot_payload()`. Slot itu tetap tidak tayang karena memang tidak ada
`ad_render('player_on_pause')` di halaman mana pun — lihat temuan 5.

## Temuan 6 — label

Label runtime yang kosong tidak lagi menimpa label dari `config/ads.php`;
`ad_normalize_slot()` hanya menyertakan `label` bila terisi.

---

# Masih terbuka

**Temuan 5** — slot `header` ada di config dan JSON tapi tidak pernah dipanggil
`ad_render('header')`. Perlu keputusan: pasang di layout, atau buang slotnya.
Sama untuk `player_on_pause` di situs reguler.

**Temuan 7** — `public/assets/player-ads-v1.js` hanya menyembunyikan overlay saat
`play`, tidak menahan playback, dan meng-append ke `video.parentElement` yang
bisa `position: static`. Perlu perbaikan terpisah karena menyentuh perilaku
player.
