<?php

declare(strict_types=1);

/**
 * Menormalkan storage/ads.json ke satu bentuk slot (label + desktop/mobile).
 *
 * Sebelum ini satu file bisa memuat dua bentuk sekaligus — bentuk lama
 * (active/target/code) dan bentuk per-perangkat — dan bentuk lama diam-diam
 * membayangi bentuk baru sehingga kode iklan hilang tanpa error.
 *
 * Pemakaian:
 *   php scripts/migrate-ads-slot-format.php            # dry-run, tidak menulis
 *   php scripts/migrate-ads-slot-format.php --write    # terapkan
 */

$root = dirname(__DIR__);
require $root . '/app/ads.php';

$write = in_array('--write', $argv, true);
$path = $root . '/storage/ads.json';

if (!is_file($path)) {
    fwrite(STDERR, "Tidak ada storage/ads.json — tidak ada yang dimigrasikan.\n");
    exit(1);
}

$raw = file_get_contents($path);
if ($raw === false) {
    fwrite(STDERR, "Gagal membaca {$path}.\n");
    exit(1);
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    fwrite(STDERR, "storage/ads.json bukan JSON yang valid.\n");
    exit(1);
}

$slots = is_array($data['slots'] ?? null) ? $data['slots'] : [];
$changed = 0;

foreach ($slots as $name => $slot) {
    if (!is_array($slot)) {
        continue;
    }

    $legacy = array_key_exists('code', $slot) || array_key_exists('target', $slot);
    $canonical = ad_normalize_slot($slot);

    if (!isset($canonical['label']) && isset($slot['label'])) {
        $canonical['label'] = (string) $slot['label'];
    }

    $ordered = [
        'label' => (string) ($canonical['label'] ?? $name),
        'desktop' => $canonical['desktop'],
        'mobile' => $canonical['mobile'],
    ];

    if ($ordered !== $slot) {
        $changed++;
        printf(
            "%-24s %s  desktop[%s %db]  mobile[%s %db]\n",
            $name,
            $legacy ? 'bentuk-lama ->' : 'dirapikan   ->',
            $ordered['desktop']['active'] ? 'on ' : 'off',
            strlen($ordered['desktop']['code']),
            $ordered['mobile']['active'] ? 'on ' : 'off',
            strlen($ordered['mobile']['code'])
        );
    }

    $slots[$name] = $ordered;
}

$data['slots'] = $slots;

echo "\n{$changed} slot perlu dinormalkan.\n";

if (!$write) {
    echo "Dry-run — tidak ada yang ditulis. Jalankan ulang dengan --write.\n";
    exit(0);
}

if ($changed === 0) {
    echo "Sudah rapi, tidak ada perubahan.\n";
    exit(0);
}

$json = json_encode(
    $data,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
);

if ($json === false) {
    fwrite(STDERR, "Gagal meng-encode JSON.\n");
    exit(1);
}

$tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
if (file_put_contents($tmp, $json . PHP_EOL, LOCK_EX) === false) {
    fwrite(STDERR, "Gagal menulis file sementara.\n");
    exit(1);
}
@chmod($tmp, 0640);

if (!rename($tmp, $path)) {
    @unlink($tmp);
    fwrite(STDERR, "Gagal mengganti storage/ads.json.\n");
    exit(1);
}

echo "storage/ads.json dinormalkan.\n";
