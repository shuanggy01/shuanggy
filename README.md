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

| Slot                   | Dirender di                     | Bisa diedit di admin |
|------------------------|---------------------------------|----------------------|
| `home_top`             | `index.php`                     | ya                   |
| `home_mid`             | `index.php`                     | ya                   |
| `player_above`         | `watch.php`                     | ya                   |
| `player_below`         | `watch.php`                     | ya                   |
| `player_related`       | `watch.php`                     | ya                   |
| `album_top`            | `collection.php`, `category.php`| ya                   |
| `footer`               | `site_footer.php`               | ya                   |
| `player_before_play`   | `watch.php` (JS overlay)        | ya                   |
| `player_before_play_2` | `watch.php` (JS overlay)        | ya                   |
| `header`               | **tidak dirender**              | tidak                |
| `player_on_pause`      | **tidak dirender**              | tidak                |

### Bentuk slot

Sejak migrasi format, setiap slot di `storage/ads.json` hanya punya satu bentuk:

```jsonc
{
  "label": "Player • Bawah Video",
  "desktop": { "active": true, "code": "..." },
  "mobile":  { "active": true, "code": "..." }
}
```

Kode HP dan desktop disimpan terpisah, jadi menyimpan satu perangkat tidak
pernah menimpa kode perangkat lain. `ad_normalize_slot()` di `app/ads.php`
masih menerima bentuk lama (`active`/`target`/`code`) supaya JSON warisan tetap
terbaca, tetapi panel admin selalu menulis bentuk kanonik di atas.

Untuk merapikan JSON lama:

```bash
php scripts/migrate-ads-slot-format.php          # dry-run
php scripts/migrate-ads-slot-format.php --write  # terapkan
```

## Iklan Full Player /f/

Terpisah total di `storage/full-player-ads.json`, diatur lewat
`/admin/full-player-ads.php`. Slot: Top Floating, First Play, Before Play,
Pause Ad, Timed Trigger, Bottom Floating, Social Bar, dan Anti-AdBlock.

Konfigurasi reguler **tidak pernah** bocor ke `/f/` — `ad_mobile_scripts_render()`
dan `antiblock_render()` mengembalikan string kosong untuk scope `full`.

Lihat `docs/ADS-REVIEW.md` untuk temuan dan statusnya.
