# Panduan: Putar Video Lewat Cloudflare Worker

Setelah langkah ini selesai, video dan thumbnail di viduk.fun dimuat dari
`https://viduk.fun/media/BASE64…`. Domain CDN/R2 tidak tertulis lagi di halaman.

> Base64 hanya **menyamarkan** alamat, bukan mengenkripsi. Orang yang paham teknis
> masih bisa membacanya, tapi alamatnya tidak terlihat langsung.

## Syarat

- DNS `viduk.fun` sudah di Cloudflare dan ikon awannya **oranye (Proxied)**.

## 1. Buat Worker baru

Buat Worker **terpisah**. Jangan pakai `pejuanglendir-proxy`, supaya
pejuanglendir.cc tidak ikut terganggu.

1. Cloudflare → **Workers & Pages** → **Create** → **Create Worker**.
2. Beri nama, misal `viduk-media` → **Deploy**.
3. Klik **Edit code**, hapus semua isinya, lalu tempel seluruh isi file
   `worker/worker.mjs` → **Deploy**.

## 2. Isi variabel

Buka Worker `viduk-media` → **Settings** → **Variables and Secrets** → **Add**:

| Nama | Isi |
|---|---|
| `PLAYER_HOSTS` | `viduk.fun` |
| `ALLOWED_MEDIA_HOSTS` | domain CDN video **dan** thumbnail, dipisah koma, tanpa `https://`. Contoh: `cdn.domainabang.com,pub-xxxx.r2.dev` |

Cara mencari domain CDN: lihat link video yang dikirim ke bot. Misalnya dari
`https://cdn.domainabang.com/video.mp4`, domainnya `cdn.domainabang.com`.
Kalau thumbnail ada di domain lain, tambahkan juga.

Klik **Deploy** / **Save**.

## 3. Pasang route

Worker → **Settings** → **Domains & Routes** → **Add** → **Route**:

- Zone: `viduk.fun`
- Route: `viduk.fun/media/*` (pilih **Route**, bukan *Custom domain*)

Kalau route itu ditolak, `*viduk.fun/*` juga boleh dipakai. Dengan route itu
semua halaman viduk.fun lewat Worker dan diteruskan apa adanya ke server, tapi
setiap halaman ikut dihitung sebagai request Worker.

## 4. Cek Worker sudah aktif

Buka `https://viduk.fun/media/tes` di browser.
Kalau muncul tulisan **"Token video tidak valid."**, Worker sudah aktif. ✅

## 5. Aktifkan di admin

Admin → **05 / Pengaturan website** → centang
**"Putar video & thumbnail lewat Worker (/media/BASE64)"** → **Simpan pengaturan website**.

Buka salah satu video dan pastikan bisa diputar.

## Kalau video tidak bisa diputar

- Hilangkan centang di admin → Simpan. Video kembali diputar langsung dari CDN.
- Pesan **"Domain sumber video belum diizinkan."** artinya domain CDN belum
  dimasukkan ke `ALLOWED_MEDIA_HOSTS`.
- Pesan **"Isi ALLOWED_MEDIA_HOSTS…"** artinya variabel belum diisi.

## Catatan

- Video `.m3u8` (HLS) tetap diputar langsung, tidak lewat Worker.
- Thumbnail yang di-upload dari admin ke server sendiri (`/thumbs/…`) tidak
  lewat Worker karena sudah di domain viduk.fun.
- Setiap pemutaran video membuat beberapa request ke Worker. Paket gratis
  Cloudflare dibatasi 100.000 request/hari per akun. Cek paket abang di
  **Workers & Pages → Plans**.
