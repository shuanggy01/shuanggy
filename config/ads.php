<?php

declare(strict_types=1);

/**
 * Default iklan reguler. Full Player /f/ sengaja tidak ada di sini karena
 * seluruh monetisasi /f/ hidup di storage/full-player-ads.json.
 */

return [
    'enabled' => true,
    'test_mode' => false,

    'slots' => [
        'header' => [
            'label' => 'Header • Atas Halaman',
            'desktop' => ['active' => false, 'code' => ''],
            'mobile' => ['active' => false, 'code' => ''],
        ],
        'footer' => [
            'label' => 'Footer • Bawah Halaman',
            'desktop' => ['active' => false, 'code' => ''],
            'mobile' => ['active' => false, 'code' => ''],
        ],
        'home_top' => [
            'label' => 'Homepage • Atas Trending',
            'desktop' => ['active' => false, 'code' => ''],
            'mobile' => ['active' => false, 'code' => ''],
        ],
        'home_mid' => [
            'label' => 'Homepage • Sebelum Video Terbaru',
            'desktop' => ['active' => false, 'code' => ''],
            'mobile' => ['active' => false, 'code' => ''],
        ],
        'player_above' => [
            'label' => 'Player • Atas Video',
            'desktop' => ['active' => false, 'code' => ''],
            'mobile' => ['active' => false, 'code' => ''],
        ],
        'player_before_play' => [
            'label' => 'Player • Before Play (Overlay)',
            'desktop' => ['active' => false, 'code' => ''],
            'mobile' => ['active' => false, 'code' => ''],
        ],
        'player_before_play_2' => [
            'label' => 'Player • Sebelum Play 2',
            'active' => false,
            'target' => 'mobile',
            'code' => '',
        ],
        'player_on_pause' => [
            'label' => 'Player • On Pause (Overlay)',
            'desktop' => ['active' => false, 'code' => ''],
            'mobile' => ['active' => false, 'code' => ''],
        ],
        'player_below' => [
            'label' => 'Player • Bawah Video',
            'desktop' => ['active' => false, 'code' => ''],
            'mobile' => ['active' => false, 'code' => ''],
        ],
        'player_related' => [
            'label' => 'Player • Sebelum Related Video',
            'desktop' => ['active' => false, 'code' => ''],
            'mobile' => ['active' => false, 'code' => ''],
        ],
        'album_top' => [
            'label' => 'Kategori • Sebelum Daftar Video',
            'desktop' => ['active' => false, 'code' => ''],
            'mobile' => ['active' => false, 'code' => ''],
        ],
    ],

    'mobile_scripts' => [
        'active' => false,
        'target' => 'mobile',
        'code' => '',
        'scopes' => [
            'home' => true,
            'video' => true,
            'category' => true,
            'download' => true,
        ],
    ],

    'meta_verification' => [
        'active' => false,
        'code' => '',
    ],

    'antiblock' => [
        'active' => false,
        'position' => 'head',
        'code' => '',
        'scopes' => [
            'home' => true,
            'video' => true,
            'category' => true,
            'download' => true,
        ],
    ],
];
