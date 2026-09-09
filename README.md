# AsupanLendir

Situs video PHP (tanpa framework) dengan sistem iklan yang dapat dikelola
lewat panel admin.

## Struktur

| Path          | Isi                                                        |
|---------------|------------------------------------------------------------|
| `public/`     | Document root. Halaman publik + `public/admin/` panel admin |
| `app/`        | Logic bersama (`ads.php`, `admin.php`, model, helper)        |
| `config/`     | Default statis (`ads.php`, `database.php`, dll)              |
| `storage/`    | State runtime yang ditulis panel admin (JSON), log, cache    |
| `database/`   | Migrasi SQL                                                  |
| `scripts/`    | Utilitas CLI sekali jalan                                    |
| `bin/`        | Worker background                                            |
| `cloudflare/` | Worker proxy                                                 |
| `docs/`       | Catatan rilis/patch historis                                 |

## Setup

```bash
cp .env.example .env   # isi DB_USERNAME / DB_PASSWORD
# jalankan migrasi di database/migrations/ berurutan
php scripts/create_admin.php
```

Arahkan document root web server ke `public/`.
`storage/` harus writable oleh PHP dan **tidak boleh** dapat diakses dari web.

## Sistem iklan

Konfigurasi datang dari dua sumber yang digabung berurutan:

1. `config/ads.php` — default statis (semua slot mati).
2. `storage/ads.json` — runtime, ditulis panel admin. Menimpa nomor 1.

Full Player (`/f/`) sengaja dipisah total di `storage/full-player-ads.json`
dan tidak pernah mewarisi kode dari konfigurasi reguler.

### Slot

Slot dirender server-side lewat `ad_render('nama_slot')`, kecuali
`player_before_play` / `player_before_play_2` yang dikirim ke browser sebagai
`window.AL_PLAYER_ADS` lalu dipasang `public/assets/player-ads-v1.js`.

| Slot                   | Dirender di                  | Bisa diedit di admin |
|------------------------|------------------------------|----------------------|
| `home_top`             | `index.php`                  | ya                   |
| `home_mid`             | `index.php`                  | **tidak**            |
| `player_above`         | `watch.php`                  | **tidak**            |
| `player_below`         | `watch.php`                  | ya                   |
| `player_related`       | `watch.php`                  | **tidak**            |
| `album_top`            | `collection.php`,`category.php`| **tidak**          |
| `footer`               | `site_footer.php`            | ya                   |
| `player_before_play`   | `watch.php` (JS overlay)     | ya                   |
| `player_before_play_2` | `watch.php` (JS overlay)     | ya                   |
| `header`               | **tidak dirender**           | tidak                |
| `player_on_pause`      | **dipaksa mati di kode**     | tidak                |

Lihat `docs/ADS-REVIEW.md` untuk temuan yang belum diperbaiki.

### Dua format slot

`storage/ads.json` memuat dua bentuk slot sekaligus:

```jsonc
// flat — ditulis panel admin sekarang
{ "label": "...", "active": true, "target": "all", "code": "..." }

// nested — data lama, masih dibaca
{ "label": "...", "desktop": {"active":true,"code":"..."},
                  "mobile":  {"active":true,"code":"..."} }
```

`ad_slot_variant()` memprioritaskan bentuk flat. Kalau satu slot punya kedua
bentuk sekaligus, kode per-perangkat pada bentuk nested **diabaikan diam-diam**.
