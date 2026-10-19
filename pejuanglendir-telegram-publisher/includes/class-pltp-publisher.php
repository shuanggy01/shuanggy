<?php

if (!defined('ABSPATH')) {
    exit;
}

final class PLTP_Publisher
{
    private const OPT = 'pltp_v11_settings';
    private const SEEN = 'pltp_v11_seen_updates';
    private const NS = 'pejuanglendir/v1';
    private const ROUTE = '/telegram';
    private const BULK_BATCH_PREFIX = 'pltp_v1912_bulk_batch_';
    private const BACKFILL_OPT = 'pltp_v1917_full_sync_state';

    public static function boot(): void
    {
        add_action('rest_api_init', [self::class, 'register_route']);
        add_action('admin_menu', [self::class, 'admin_menu']);
        add_action('admin_init', [self::class, 'register_settings']);
        add_action('admin_enqueue_scripts', [self::class, 'admin_assets']);
        add_action('admin_post_pltp_v11_set_webhook', [self::class, 'set_webhook']);
        add_action('admin_post_pltp_v11_delete_webhook', [self::class, 'delete_webhook']);
        add_action('admin_post_pltp_r2_test', [self::class, 'r2_test']);
        add_action('admin_post_pltp_rotation_r2_test', [self::class, 'rotation_r2_test']);
        add_action('admin_notices', [self::class, 'notices']);
        add_action('init', [self::class, 'maybe_backfill_rank_math_video_schema'], 20);
        add_filter('rank_math/snippet/rich_snippet_videoobject_entity', [self::class, 'filter_rank_math_videoobject_entity'], 99, 1);
        add_filter('rank_math/json_ld', [self::class, 'filter_rank_math_json_ld'], 99, 2);
        add_filter('wp_get_attachment_url', [self::class, 'filter_r2_attachment_url'], 20, 2);
        add_filter('image_downsize', [self::class, 'filter_r2_image_downsize'], 20, 3);
        add_filter('wp_calculate_image_srcset', [self::class, 'filter_r2_srcset'], 20, 5);
        add_filter('wp_robots', [self::class, 'filter_navigation_taxonomy_robots'], 99, 1);
        add_filter('rank_math/frontend/robots', [self::class, 'filter_rank_math_navigation_robots'], 99, 1);
    }

    public static function activate(): void
    {
        $stored = self::stored_settings();

        if (empty($stored['secret']) && !defined('PLTP_WEBHOOK_SECRET')) {
            $stored['secret'] = wp_generate_password(48, false, false);
        }

        update_option(self::OPT, $stored, false);
    }

    private static function defaults(): array
    {
        return [
            'bot_token' => '',
            'allowed_chat_ids' => '',
            'author_id' => 1,
            'post_status' => 'publish',
            'notify' => 1,
            'auto_duration' => 1,
            'auto_thumbnail' => 1,
            'r2_enabled' => 0,
            'r2_account_id' => '',
            'r2_access_key_enc' => '',
            'r2_secret_key_enc' => '',
            'r2_bucket' => '',
            'r2_public_base_url' => '',

            'rotation_enabled' => 1,
            'rotation_count' => 5,
            'rotation_r2_bucket' => '',
            'rotation_r2_public_base_url' => 'https://play.pejuanglendir.cc',

            'auto_category' => 1,
            'category_rules' => '',
            'default_category' => '',
            'description_template' => '',
            'smart_content_enabled' => 1,
            'inline_media' => 1,

            'rank_math_enabled' => 1,
            'seo_title_template' => '{title} | {site_name}',
            'seo_description_template' => 'Tonton {title} terbaru di {site_name}.',
            'focus_keyword_source' => 'title',

            'secret' => '',
        ];
    }

    private static function stored_settings(): array
    {
        $stored = get_option(self::OPT, []);
        $stored = is_array($stored) ? $stored : [];

        return wp_parse_args($stored, self::defaults());
    }

    private static function settings(): array
    {
        $settings = self::stored_settings();

        if (defined('PLTP_BOT_TOKEN') && PLTP_BOT_TOKEN !== '') {
            $settings['bot_token'] = (string) PLTP_BOT_TOKEN;
        }

        if (defined('PLTP_WEBHOOK_SECRET') && PLTP_WEBHOOK_SECRET !== '') {
            $settings['secret'] = (string) PLTP_WEBHOOK_SECRET;
        }

        return $settings;
    }

    public static function register_settings(): void
    {
        register_setting(
            'pltp_v11_group',
            self::OPT,
            [
                'type' => 'array',
                'sanitize_callback' => [self::class, 'sanitize_settings'],
                'default' => self::defaults(),
            ]
        );
    }

    public static function sanitize_settings($input): array
    {
        $stored = self::stored_settings();
        $input = is_array($input) ? $input : [];

        $status = isset($input['post_status'])
            ? sanitize_key((string) $input['post_status'])
            : 'publish';

        if (!in_array($status, ['publish', 'draft'], true)) {
            $status = 'publish';
        }

        $focus_source = isset($input['focus_keyword_source'])
            ? sanitize_key((string) $input['focus_keyword_source'])
            : 'title';

        if (!in_array($focus_source, ['title', 'categories'], true)) {
            $focus_source = 'title';
        }

        $secret = (string) ($stored['secret'] ?? '');

        if ($secret === '' && !defined('PLTP_WEBHOOK_SECRET')) {
            $secret = wp_generate_password(48, false, false);
        }

        $r2_account_id = isset($input['r2_account_id'])
            ? sanitize_text_field((string) $input['r2_account_id'])
            : (string) ($stored['r2_account_id'] ?? '');

        $r2_bucket = isset($input['r2_bucket'])
            ? sanitize_text_field((string) $input['r2_bucket'])
            : (string) ($stored['r2_bucket'] ?? '');

        $r2_public_base_url = isset($input['r2_public_base_url'])
            ? untrailingslashit(
                esc_url_raw((string) $input['r2_public_base_url'])
            )
            : (string) ($stored['r2_public_base_url'] ?? '');

        $r2_access_key_enc = (string) ($stored['r2_access_key_enc'] ?? '');
        $r2_secret_key_enc = (string) ($stored['r2_secret_key_enc'] ?? '');

        $new_r2_access_key = isset($input['r2_access_key'])
            ? trim((string) $input['r2_access_key'])
            : '';

        $new_r2_secret_key = isset($input['r2_secret_key'])
            ? trim((string) $input['r2_secret_key'])
            : '';

        if ($new_r2_access_key !== '') {
            $r2_access_key_enc = self::encrypt_secret(
                $new_r2_access_key
            );
        }

        if ($new_r2_secret_key !== '') {
            $r2_secret_key_enc = self::encrypt_secret(
                $new_r2_secret_key
            );
        }

        // V1.9.1.8 final layout: native RetroTube rotation is fixed at 5 frames.
        $rotation_count = 5;

        $rotation_r2_bucket = isset($input['rotation_r2_bucket'])
            ? sanitize_text_field((string) $input['rotation_r2_bucket'])
            : (string) ($stored['rotation_r2_bucket'] ?? '');

        $rotation_r2_public_base_url = isset(
            $input['rotation_r2_public_base_url']
        )
            ? untrailingslashit(
                esc_url_raw(
                    (string) $input['rotation_r2_public_base_url']
                )
            )
            : (string) (
                $stored['rotation_r2_public_base_url']
                ?? 'https://play.pejuanglendir.cc'
            );

        return [
            'bot_token' => isset($input['bot_token'])
                ? trim((string) $input['bot_token'])
                : (string) ($stored['bot_token'] ?? ''),

            'allowed_chat_ids' => isset($input['allowed_chat_ids'])
                ? trim((string) $input['allowed_chat_ids'])
                : '',

            'author_id' => max(1, absint($input['author_id'] ?? 1)),
            'post_status' => $status,
            'notify' => !empty($input['notify']) ? 1 : 0,
            'auto_duration' => !empty($input['auto_duration']) ? 1 : 0,
            'auto_thumbnail' => !empty($input['auto_thumbnail']) ? 1 : 0,
            'r2_enabled' => !empty($input['r2_enabled']) ? 1 : 0,
            'r2_account_id' => $r2_account_id,
            'r2_access_key_enc' => $r2_access_key_enc,
            'r2_secret_key_enc' => $r2_secret_key_enc,
            'r2_bucket' => $r2_bucket,
            'r2_public_base_url' => $r2_public_base_url,

            'rotation_enabled' => !empty($input['rotation_enabled']) ? 1 : 0,
            'rotation_count' => $rotation_count,
            'rotation_r2_bucket' => $rotation_r2_bucket,
            'rotation_r2_public_base_url' => $rotation_r2_public_base_url,

            'auto_category' => !empty($input['auto_category']) ? 1 : 0,
            'category_rules' => isset($input['category_rules'])
                ? trim((string) $input['category_rules'])
                : '',
            'default_category' => isset($input['default_category'])
                ? sanitize_text_field((string) $input['default_category'])
                : '',
            'description_template' => isset($input['description_template'])
                ? wp_kses_post((string) $input['description_template'])
                : '',
            'smart_content_enabled' => !empty($input['smart_content_enabled']) ? 1 : 0,
            'inline_media' => !empty($input['inline_media']) ? 1 : 0,

            'rank_math_enabled' => !empty($input['rank_math_enabled']) ? 1 : 0,
            'seo_title_template' => isset($input['seo_title_template'])
                ? sanitize_text_field((string) $input['seo_title_template'])
                : '{title} | {site_name}',
            'seo_description_template' => isset($input['seo_description_template'])
                ? sanitize_textarea_field((string) $input['seo_description_template'])
                : 'Tonton {title} terbaru di {site_name}.',
            'focus_keyword_source' => $focus_source,

            'secret' => $secret,
        ];
    }

    public static function admin_assets(string $hook_suffix): void
    {
        if ($hook_suffix !== 'settings_page_pejuanglendir-telegram-publisher') {
            return;
        }

        $plugin_file = dirname(__DIR__) . '/pejuanglendir-telegram-publisher.php';

        wp_enqueue_style(
            'pltp-admin-mobile',
            plugins_url('assets/admin-mobile.css', $plugin_file),
            [],
            '1.9.1.6'
        );

        wp_enqueue_script(
            'pltp-admin-mobile',
            plugins_url('assets/admin-mobile.js', $plugin_file),
            [],
            '1.9.1',
            true
        );
    }

    public static function admin_menu(): void
    {
        add_options_page(
            'Telegram Publisher',
            'Telegram Publisher',
            'manage_options',
            'pejuanglendir-telegram-publisher',
            [self::class, 'render_admin']
        );
    }

    public static function render_admin(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = self::settings();
        $token_constant = defined('PLTP_BOT_TOKEN') && PLTP_BOT_TOKEN !== '';
        $secret_constant = defined('PLTP_WEBHOOK_SECRET') && PLTP_WEBHOOK_SECRET !== '';
        $rank_math_active = self::rank_math_active();
        $r2_ready = self::r2_config_ready();
        $stored_r2 = self::stored_settings();
        $r2_access_saved = !empty($stored_r2['r2_access_key_enc']);
        $r2_secret_saved = !empty($stored_r2['r2_secret_key_enc']);
        $rotation_r2_ready = self::rotation_r2_config_ready();
        ?>
        <div class="wrap">
            <h1>PejuangLendir Telegram Publisher V1.9.3.1</h1>

            <p><strong>Format Telegram — aktor & thumbnail opsional:</strong></p>
            <pre style="background:#fff;border:1px solid #ccd0d4;padding:12px;max-width:760px;">video_viral_hari_ini
Aktor: Icha Hyper
https://cdn.example.com/video-1.mp4

Judul Video 2
https://cdn.example.com/video-2.mp4
https://cdn.example.com/thumb-2.jpg</pre>
            <p class="description">
                Tanda <code>_</code> dan pemisah judul dirapikan otomatis.
                Judul seri seperti <code>Hijab Alisyaa (1)</code> menjadi <code>Hijab Alisyaa - Part 1</code>.
                Baris <code>Aktor:</code> opsional dan memakai taxonomy <code>actors</code> native RetroTube.
                Tanpa thumbnail = dibuat otomatis; thumbnail URL manual tetap didukung.
                Maksimal 3 tag navigasi dibuat otomatis dari aktor + kata penting pada judul.
                Smart Description memakai data faktual post (aktor, kategori, tag, durasi) dan diperbarui setelah FFprobe selesai.
                Archive Aktor dan Tag dibuat <code>noindex,follow</code>.
            </p>

            <form method="post" action="options.php" id="pltp-settings-form">
                <?php settings_fields('pltp_v11_group'); ?>

                <table class="form-table pltp-settings-table" role="presentation">
                    <tr>
                        <th scope="row">Bot Token</th>
                        <td>
                            <?php if ($token_constant): ?>
                                <code>Loaded from wp-config.php constant PLTP_BOT_TOKEN</code>
                            <?php else: ?>
                                <input type="password"
                                       name="<?php echo esc_attr(self::OPT); ?>[bot_token]"
                                       value="<?php echo esc_attr((string) $settings['bot_token']); ?>"
                                       class="regular-text"
                                       autocomplete="off">
                            <?php endif; ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Allowed Chat IDs</th>
                        <td>
                            <textarea name="<?php echo esc_attr(self::OPT); ?>[allowed_chat_ids]"
                                      rows="4"
                                      class="large-text"><?php echo esc_textarea((string) $settings['allowed_chat_ids']); ?></textarea>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Post Author</th>
                        <td>
                            <?php
                            wp_dropdown_users([
                                'name' => self::OPT . '[author_id]',
                                'selected' => (int) $settings['author_id'],
                            ]);
                            ?>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Post Status</th>
                        <td>
                            <select name="<?php echo esc_attr(self::OPT); ?>[post_status]">
                                <option value="publish" <?php selected($settings['post_status'], 'publish'); ?>>Publish</option>
                                <option value="draft" <?php selected($settings['post_status'], 'draft'); ?>>Draft</option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Telegram Confirmation</th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="<?php echo esc_attr(self::OPT); ?>[notify]"
                                       value="1"
                                       <?php checked(!empty($settings['notify'])); ?>>
                                Kirim balasan sukses/gagal ke Telegram
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Auto Duration</th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="<?php echo esc_attr(self::OPT); ?>[auto_duration]"
                                       value="1"
                                       <?php checked(!empty($settings['auto_duration'])); ?>>
                                Baca durasi direct MP4 dengan FFprobe worker
                            </label>
                            <p class="description">
                                Durasi disimpan sebagai detik pada meta <code>duration</code>,
                                sesuai field bawaan WP-Script/RetroTube, lalu disinkronkan ke VideoObject Rank Math.
                                Web PHP tetap tidak membutuhkan <code>exec()</code>.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Auto Thumbnail</th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="<?php echo esc_attr(self::OPT); ?>[auto_thumbnail]"
                                       value="1"
                                       <?php checked(!empty($settings['auto_thumbnail'])); ?>>
                                Jika thumbnail tidak dikirim, buat otomatis dari direct MP4
                            </label>
                            <p class="description">
                                Worker FFmpeg terpisah: semua video memakai satu cuplikan WebP;
                                collage/grid tidak dibuat lagi. Hasil masuk Media Library sebagai
                                Featured Image, lalu mengikuti Safe R2 Offload utama.
                                PHP web tetap tidak menjalankan <code>exec()</code>.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Cloudflare R2</th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="<?php echo esc_attr(self::OPT); ?>[r2_enabled]"
                                       value="1"
                                       <?php checked(!empty($settings['r2_enabled'])); ?>>
                                Safe offload thumbnail baru ke R2
                            </label>

                            <p class="description">
                                Mode V1.8.1 tetap <strong>copy-only</strong>.
                                File lokal WordPress tidak dihapus.
                            </p>

                            <p style="margin-top:14px;">
                                <label>
                                    <strong>Account ID</strong><br>
                                    <input
                                        type="text"
                                        name="<?php echo esc_attr(self::OPT); ?>[r2_account_id]"
                                        value="<?php echo esc_attr((string) ($stored_r2['r2_account_id'] ?? '')); ?>"
                                        class="regular-text"
                                        autocomplete="off"
                                        placeholder="Cloudflare Account ID"
                                    >
                                </label>
                            </p>

                            <p>
                                <label>
                                    <strong>Access Key ID</strong><br>
                                    <input
                                        type="password"
                                        name="<?php echo esc_attr(self::OPT); ?>[r2_access_key]"
                                        value=""
                                        class="regular-text"
                                        autocomplete="new-password"
                                        placeholder="<?php echo esc_attr(
                                            $r2_access_saved
                                                ? 'Tersimpan — kosongkan untuk mempertahankan'
                                                : 'Paste Access Key ID'
                                        ); ?>"
                                    >
                                </label>
                            </p>

                            <p>
                                <label>
                                    <strong>Secret Access Key</strong><br>
                                    <input
                                        type="password"
                                        name="<?php echo esc_attr(self::OPT); ?>[r2_secret_key]"
                                        value=""
                                        class="regular-text"
                                        autocomplete="new-password"
                                        placeholder="<?php echo esc_attr(
                                            $r2_secret_saved
                                                ? 'Tersimpan — kosongkan untuk mempertahankan'
                                                : 'Paste Secret Access Key'
                                        ); ?>"
                                    >
                                </label>
                            </p>

                            <p>
                                <label>
                                    <strong>Bucket</strong><br>
                                    <input
                                        type="text"
                                        name="<?php echo esc_attr(self::OPT); ?>[r2_bucket]"
                                        value="<?php echo esc_attr((string) ($stored_r2['r2_bucket'] ?? '')); ?>"
                                        class="regular-text"
                                        autocomplete="off"
                                        placeholder="Nama bucket R2"
                                    >
                                </label>
                            </p>

                            <p>
                                <label>
                                    <strong>Public / CDN URL</strong><br>
                                    <input
                                        type="url"
                                        name="<?php echo esc_attr(self::OPT); ?>[r2_public_base_url]"
                                        value="<?php echo esc_attr((string) ($stored_r2['r2_public_base_url'] ?? '')); ?>"
                                        class="regular-text"
                                        autocomplete="off"
                                        placeholder="https://cdn.example.com"
                                    >
                                </label>
                            </p>

                            <p class="description">
                                Access Key dan Secret disimpan terenkripsi dengan
                                AES-256-GCM menggunakan WordPress salts.
                                Nilai rahasia tidak ditampilkan kembali di admin.
                            </p>

                            <p class="description">
                                Jika setting plugin masih kosong, constant
                                <code>PLTP_R2_*</code> dari <code>wp-config.php</code>
                                tetap dipakai sebagai fallback.
                            </p>

                            <p class="description">
                                Status:
                                <?php if ($r2_ready): ?>
                                    <strong style="color:#16833b;">READY</strong>
                                    — <?php echo esc_html(self::r2_public_base_for_display()); ?>
                                <?php else: ?>
                                    <strong style="color:#b32d2e;">BELUM LENGKAP</strong>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Thumbnail Rotation</th>
                        <td>
                            <label>
                                <input
                                    type="checkbox"
                                    name="<?php echo esc_attr(self::OPT); ?>[rotation_enabled]"
                                    value="1"
                                    <?php checked(!empty($settings['rotation_enabled'])); ?>
                                >
                                Buat cuplikan otomatis untuk rotation thumbnail RetroTube
                            </label>

                            <p class="description">
                                Worker FFmpeg mengambil frame dari direct MP4 lalu upload
                                langsung ke R2. Tidak memakai <code>exec()</code> dari PHP web.
                            </p>

                            <p>
                                <label>
                                    <strong>Jumlah cuplikan</strong><br>
                                    <select name="<?php echo esc_attr(self::OPT); ?>[rotation_count]">
                                        <option value="5" selected>5 cuplikan</option>
                                    </select>
                                </label>
                            </p>

                            <p>
                                <label>
                                    <strong>Rotation R2 Bucket</strong><br>
                                    <input
                                        type="text"
                                        name="<?php echo esc_attr(self::OPT); ?>[rotation_r2_bucket]"
                                        value="<?php echo esc_attr((string) $settings['rotation_r2_bucket']); ?>"
                                        class="regular-text"
                                        placeholder="Kosong = pakai bucket R2 utama"
                                    >
                                </label>
                            </p>

                            <p>
                                <label>
                                    <strong>Rotation Public / CDN URL</strong><br>
                                    <input
                                        type="url"
                                        name="<?php echo esc_attr(self::OPT); ?>[rotation_r2_public_base_url]"
                                        value="<?php echo esc_attr((string) $settings['rotation_r2_public_base_url']); ?>"
                                        class="regular-text"
                                        placeholder="https://play.pejuanglendir.cc"
                                    >
                                </label>
                            </p>

                            <p class="description">
                                Account ID, Access Key dan Secret memakai credential R2 utama.
                                Bucket boleh sama atau berbeda.
                            </p>

                            <p class="description">
                                Object: <code>rotation/YYYY/MM/post-ID/frame-01.webp</code>
                            </p>

                            <p class="description">
                                Status:
                                <?php if ($rotation_r2_ready): ?>
                                    <strong style="color:#16833b;">READY</strong>
                                    — <?php echo esc_html(self::rotation_r2_public_base_for_display()); ?>
                                <?php else: ?>
                                    <strong style="color:#b32d2e;">BELUM LENGKAP</strong>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Proses Langsung</th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="<?php echo esc_attr(self::OPT); ?>[inline_media]"
                                       value="1"
                                       <?php checked(!empty($settings['inline_media'])); ?>>
                                Buat thumbnail + 5 frame rotasi langsung setelah posting (tanpa worker/cron)
                            </label>
                            <p class="description">
                                Berjalan di PHP setelah balasan Telegram dikirim. Butuh <code>proc_open</code> aktif
                                dan FFmpeg/FFprobe terpasang.
                                Status: <?php if (class_exists('PLTP_Inline_Media') && PLTP_Inline_Media::can_run()): ?>
                                    <strong style="color:#16833b;">SIAP</strong>
                                <?php else: ?>
                                    <strong style="color:#b32d2e;">TIDAK TERSEDIA</strong>
                                    (<?php echo esc_html(class_exists('PLTP_Inline_Media') ? implode(', ', PLTP_Inline_Media::missing()) : 'class tidak dimuat'); ?>)
                                    — worker/cron lama dipakai sebagai cadangan.
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Auto Category</th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="<?php echo esc_attr(self::OPT); ?>[auto_category]"
                                       value="1"
                                       <?php checked(!empty($settings['auto_category'])); ?>>
                                Cocokkan kategori dari kata kunci pada judul
                            </label>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Category Rules</th>
                        <td>
                            <textarea name="<?php echo esc_attr(self::OPT); ?>[category_rules]"
                                      rows="8"
                                      class="large-text code"
                                      placeholder="Hijab | hijab, jilbab, kerudung"><?php echo esc_textarea((string) $settings['category_rules']); ?></textarea>
                            <p class="description">Satu aturan per baris. Satu post boleh cocok ke beberapa kategori.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Default Category</th>
                        <td>
                            <input type="text"
                                   name="<?php echo esc_attr(self::OPT); ?>[default_category]"
                                   value="<?php echo esc_attr((string) $settings['default_category']); ?>"
                                   class="regular-text">
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Post Description Template</th>
                        <td>
                            <textarea name="<?php echo esc_attr(self::OPT); ?>[description_template]"
                                      rows="5"
                                      class="large-text"><?php echo esc_textarea((string) $settings['description_template']); ?></textarea>
                            <p class="description">
                                Token: <code>{title}</code>, <code>{video_url}</code>,
                                <code>{thumbnail_url}</code>, <code>{site_name}</code>,
                                <code>{categories}</code>.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Smart Title & Description</th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="<?php echo esc_attr(self::OPT); ?>[smart_content_enabled]"
                                       value="1"
                                       <?php checked(!empty($settings['smart_content_enabled'])); ?>>
                                Aktifkan judul Part + deskripsi faktual otomatis
                            </label>
                            <p class="description">
                                Contoh <code>Judul (10)</code> → <code>Judul - Part 10</code>.
                                Deskripsi memakai judul, aktor, kategori, tag, dan durasi yang benar-benar tersimpan.
                                Ini membantu struktur dan keunikan halaman, tetapi bukan jaminan ranking Google.
                                Matikan opsi ini jika ingin memakai Post Description Template secara penuh.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Rank Math SEO</th>
                        <td>
                            <label>
                                <input type="checkbox"
                                       name="<?php echo esc_attr(self::OPT); ?>[rank_math_enabled]"
                                       value="1"
                                       <?php checked(!empty($settings['rank_math_enabled'])); ?>>
                                Isi metadata Rank Math otomatis
                            </label>
                            <p class="description">
                                Status plugin:
                                <?php if ($rank_math_active): ?>
                                    <strong style="color:#16833b;">ACTIVE</strong>
                                <?php else: ?>
                                    <strong style="color:#b32d2e;">NOT DETECTED</strong>
                                <?php endif; ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">SEO Title Template</th>
                        <td>
                            <input type="text"
                                   name="<?php echo esc_attr(self::OPT); ?>[seo_title_template]"
                                   value="<?php echo esc_attr((string) $settings['seo_title_template']); ?>"
                                   class="large-text">
                            <p class="description">Rekomendasi: <code>{title} | {site_name}</code></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">SEO Description Template</th>
                        <td>
                            <textarea name="<?php echo esc_attr(self::OPT); ?>[seo_description_template]"
                                      rows="4"
                                      class="large-text"><?php echo esc_textarea((string) $settings['seo_description_template']); ?></textarea>
                            <p class="description">
                                Token: <code>{title}</code>, <code>{site_name}</code>, <code>{categories}</code>.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Focus Keyword</th>
                        <td>
                            <select name="<?php echo esc_attr(self::OPT); ?>[focus_keyword_source]">
                                <option value="title" <?php selected($settings['focus_keyword_source'], 'title'); ?>>Judul post</option>
                                <option value="categories" <?php selected($settings['focus_keyword_source'], 'categories'); ?>>Kategori yang cocok</option>
                            </select>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Social Image</th>
                        <td>
                            <code>Featured Image hasil thumbnail Telegram</code>
                            <p class="description">Canonical tetap dibuat otomatis oleh Rank Math.</p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">Webhook URL</th>
                        <td><code><?php echo esc_html(rest_url(self::NS . self::ROUTE)); ?></code></td>
                    </tr>

                    <tr>
                        <th scope="row">Webhook Secret</th>
                        <td>
                            <?php if ($secret_constant): ?>
                                <code>Loaded from wp-config.php constant PLTP_WEBHOOK_SECRET</code>
                            <?php else: ?>
                                <code>Generated and hidden</code>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>

                <div class="pltp-submit-bar">
                    <?php submit_button('Simpan Pengaturan'); ?>
                </div>
            </form>

            <hr>
            <h2>Cloudflare R2 Test</h2>
            <p>
                Test ini membuat object kecil sementara, memverifikasi S3 API + public CDN,
                lalu menghapus object test tersebut.
            </p>
            <p>
                <a class="button button-secondary"
                   href="<?php echo esc_url(wp_nonce_url(
                       admin_url('admin-post.php?action=pltp_r2_test'),
                       'pltp_r2_test'
                   )); ?>">Test R2 Connection</a>
            </p>

            <hr>
            <h2>Rotation R2 Test</h2>
            <p>
                Menguji bucket/CDN untuk cuplikan rotation menggunakan credential R2 utama.
            </p>
            <p>
                <a class="button button-secondary"
                   href="<?php echo esc_url(wp_nonce_url(
                       admin_url('admin-post.php?action=pltp_rotation_r2_test'),
                       'pltp_rotation_r2_test'
                   )); ?>">Test Rotation R2</a>
            </p>

            <hr>
            <h2>Webhook</h2>
            <p>
                <a class="button button-primary"
                   href="<?php echo esc_url(wp_nonce_url(
                       admin_url('admin-post.php?action=pltp_v11_set_webhook'),
                       'pltp_v11_set_webhook'
                   )); ?>">Aktifkan Webhook</a>

                <a class="button"
                   href="<?php echo esc_url(wp_nonce_url(
                       admin_url('admin-post.php?action=pltp_v11_delete_webhook'),
                       'pltp_v11_delete_webhook'
                   )); ?>">Hapus Webhook</a>
            </p>

            <?php do_action('pltp_after_admin_panel'); ?>

            <hr>
            <h2>Rank Math Mapping</h2>
            <ul>
                <li><code>rank_math_title</code></li>
                <li><code>rank_math_description</code></li>
                <li><code>rank_math_focus_keyword</code></li>
                <li><code>rank_math_facebook_title</code></li>
                <li><code>rank_math_facebook_description</code></li>
                <li><code>rank_math_facebook_image</code></li>
                <li><code>rank_math_facebook_image_id</code></li>
                <li><code>rank_math_schema_VideoObject</code> = schema VideoObject persisten untuk video sitemap</li>
                <li>Featured Image baru dapat dicopy ke Cloudflare R2</li>
                <li>R2 object prefix: <code>wp-media/YYYY/MM/...</code></li>
                <li>File lokal Featured Image tetap disimpan sebagai fallback</li>
                <li><code>thumbs</code> = multiple post meta URL rotation native RetroTube/WP-Script</li>
                <li>Rotation frame disimpan di R2 <code>play.pejuanglendir.cc</code></li>
                <li><code>duration</code> = total detik bawaan RetroTube/WP-Script</li>
                <li>VideoObject <code>duration</code> = ISO 8601 (contoh <code>PT15S</code>)</li>
            </ul>
        </div>
        <?php
    }

    public static function notices(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        if (($_GET['page'] ?? '') !== 'pejuanglendir-telegram-publisher') {
            return;
        }

        $key = sanitize_key((string) ($_GET['pltp_notice'] ?? ''));

        $map = [
            'webhook_ok' => ['success', 'Webhook Telegram berhasil diaktifkan.'],
            'webhook_deleted' => ['success', 'Webhook Telegram berhasil dihapus.'],
            'token_missing' => ['error', 'Bot Token belum diisi.'],
            'telegram_error' => ['error', 'Telegram API mengembalikan error.'],
            'r2_ok' => ['success', 'R2 S3 API dan public CDN berhasil diverifikasi.'],
            'r2_config_missing' => ['error', 'Konfigurasi PLTP_R2_* belum lengkap.'],
            'r2_api_error' => ['error', 'R2 S3 API gagal. Cek Account ID, Access Key, Secret, dan bucket.'],
            'r2_public_error' => ['error', 'Upload R2 berhasil tetapi object tidak dapat dibaca dari Public Base URL.'],
            'rotation_r2_ok' => ['success', 'Rotation R2 API dan public CDN berhasil diverifikasi.'],
            'rotation_r2_config_missing' => ['error', 'Konfigurasi Rotation R2 belum lengkap.'],
            'rotation_r2_api_error' => ['error', 'Rotation R2 S3 API gagal. Cek bucket dan izin token.'],
            'rotation_r2_public_error' => ['error', 'Rotation R2 upload berhasil tetapi Public URL tidak dapat dibaca.'],
        ];

        if (!isset($map[$key])) {
            return;
        }

        [$type, $message] = $map[$key];

        printf(
            '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
            esc_attr($type),
            esc_html($message)
        );
    }

    public static function register_route(): void
    {
        register_rest_route(
            self::NS,
            self::ROUTE,
            [
                'methods' => 'POST',
                'callback' => [self::class, 'webhook'],
                'permission_callback' => '__return_true',
            ]
        );
    }

    public static function webhook(WP_REST_Request $request): WP_REST_Response
    {
        $settings = self::settings();
        $secret = (string) ($settings['secret'] ?? '');
        $provided = (string) $request->get_header(
            'x-telegram-bot-api-secret-token'
        );

        if ($secret === '' || !hash_equals($secret, $provided)) {
            return new WP_REST_Response(['ok' => false], 403);
        }

        $update = $request->get_json_params();

        if (!is_array($update)) {
            return new WP_REST_Response(['ok' => true], 200);
        }

        $update_id = isset($update['update_id'])
            ? (int) $update['update_id']
            : 0;

        if ($update_id > 0 && self::is_seen($update_id)) {
            return new WP_REST_Response(
                ['ok' => true, 'duplicate' => true],
                200
            );
        }

        $message = null;

        if (
            isset($update['message'])
            && is_array($update['message'])
        ) {
            $message = $update['message'];
        } elseif (
            isset($update['channel_post'])
            && is_array($update['channel_post'])
        ) {
            $message = $update['channel_post'];
        }

        if (!$message) {
            self::mark_seen($update_id);

            return new WP_REST_Response(
                ['ok' => true, 'ignored' => true],
                200
            );
        }

        $chat_id = isset($message['chat']['id'])
            ? (string) $message['chat']['id']
            : '';

        if (
            !self::chat_allowed(
                $chat_id,
                (string) $settings['allowed_chat_ids']
            )
        ) {
            self::mark_seen($update_id);

            return new WP_REST_Response(
                ['ok' => true, 'ignored' => true],
                200
            );
        }

        $text = isset($message['text'])
            ? trim((string) $message['text'])
            : '';

        if ($text === '' || str_starts_with($text, '/start')) {
            self::notify(
                $chat_id,
                "Format auto thumbnail:\n"
                . "Judul_Video\n"
                . "Aktor: Nama Aktor (opsional)\n"
                . "https://domain.com/video.mp4\n\n"
                . "Thumbnail manual tetap bisa ditambah setelah URL video.\n"
                . "Aktor bisa lebih dari satu, pisahkan dengan koma.\n"
                . "Judul otomatis dibersihkan dari _ / pemisah. Tag navigasi dibuat otomatis (maks. 3).\n\n"
                . "Bulk: semua item dalam satu pesan diantrikan dan diproses satu per satu.",
                $settings
            );

            self::mark_seen($update_id);

            return new WP_REST_Response(
                ['ok' => true],
                200
            );
        }

        $items = self::parse_bulk_message($text);

        if (is_wp_error($items)) {
            self::notify(
                $chat_id,
                '❌ ' . $items->get_error_message(),
                $settings
            );

            self::mark_seen($update_id);

            return new WP_REST_Response(
                ['ok' => true],
                200
            );
        }

        /*
         * Bulk is queued instead of processed inside the Telegram webhook.
         * This returns HTTP 200 quickly, avoids Telegram retries/timeouts,
         * and lets the OS worker process each video strictly one by one.
         */
        if (count($items) > 1) {
            $batch_id = self::enqueue_bulk_batch(
                $items,
                $chat_id,
                $update_id
            );

            if (is_wp_error($batch_id)) {
                self::notify(
                    $chat_id,
                    '❌ Gagal membuat antrian bulk: ' . $batch_id->get_error_message(),
                    $settings
                );

                self::mark_seen($update_id);

                return new WP_REST_Response(
                    ['ok' => true, 'queued' => false],
                    200
                );
            }

            self::mark_seen($update_id);

            self::notify(
                $chat_id,
                "📥 ANTRIAN DITERIMA\n"
                . 'Total: ' . count($items) . " video\n"
                . "Diproses satu per satu. Video berikutnya baru diproses setelah item sebelumnya selesai.\n"
                . "Status akan dikirim setiap kali satu video selesai diproses.",
                $settings
            );

            return new WP_REST_Response(
                [
                    'ok' => true,
                    'queued' => true,
                    'batch_id' => $batch_id,
                    'total' => count($items),
                ],
                200
            );
        }

        @set_time_limit(180);

        $results = [];
        $published = 0;
        $drafts = 0;
        $duplicates = 0;
        $failed = 0;

        foreach ($items as $index => $item) {
            $title = isset($item[0]) ? (string) $item[0] : '';
            $video_url = isset($item[1]) ? (string) $item[1] : '';
            $thumbnail_url = isset($item[2]) ? (string) $item[2] : '';
            $actors = isset($item[3]) && is_array($item[3]) ? $item[3] : [];

            $result = self::process_video_item(
                $title,
                $video_url,
                $thumbnail_url,
                $actors,
                $settings
            );

            if (is_wp_error($result)) {
                $failed++;

                $results[] = [
                    'index' => $index + 1,
                    'title' => $title,
                    'status' => 'failed',
                    'message' => $result->get_error_message(),
                ];

                continue;
            }

            $status = (string) ($result['status'] ?? '');

            if ($status === 'duplicate') {
                $duplicates++;
            } elseif ($status === 'publish') {
                $published++;
            } elseif ($status === 'draft') {
                $drafts++;
            } else {
                $failed++;
            }

            $results[] = array_merge(
                [
                    'index' => $index + 1,
                    'title' => $title,
                ],
                $result
            );
        }

        self::mark_seen($update_id);

        if (count($items) === 1) {
            $result = $results[0];

            if (($result['status'] ?? '') === 'duplicate') {
                self::notify(
                    $chat_id,
                    "⏭️ Video sudah pernah dipost.\n\n"
                    . $result['title']
                    . "\n"
                    . ($result['link'] ?? ''),
                    $settings
                );
            } elseif (($result['status'] ?? '') === 'failed') {
                self::notify(
                    $chat_id,
                    "❌ Gagal\n\n"
                    . $result['title']
                    . "\n"
                    . ($result['message'] ?? 'Unknown error'),
                    $settings
                );
            } else {
                self::notify(
                    $chat_id,
                    "✅ "
                    . (($result['status'] ?? '') === 'publish'
                        ? 'Published'
                        : 'Draft')
                    . ($result['thumbnail_line'] ?? '')
                    . ($result['r2_line'] ?? '')
                    . ($result['duration_line'] ?? '')
                    . ($result['rotation_line'] ?? '')
                    . ($result['category_line'] ?? '')
                    . ($result['actor_line'] ?? '')
                    . ($result['tag_line'] ?? '')
                    . ($result['rank_math_line'] ?? '')
                    . "\n\n"
                    . $result['title']
                    . "\n"
                    . ($result['link'] ?? ''),
                    $settings
                );
            }
        } else {
            $summary = "✅ BULK SELESAI\n"
                . "Total: " . count($items)
                . " | Publish: {$published}"
                . ($drafts > 0 ? " | Draft: {$drafts}" : '')
                . ($duplicates > 0 ? " | Duplikat: {$duplicates}" : '')
                . ($failed > 0 ? " | Gagal: {$failed}" : '')
                . "\n\n";

            foreach ($results as $result) {
                $status = (string) ($result['status'] ?? '');

                if ($status === 'publish') {
                    $icon = '✅';
                } elseif ($status === 'draft') {
                    $icon = '📝';
                } elseif ($status === 'duplicate') {
                    $icon = '⏭️';
                } else {
                    $icon = '❌';
                }

                $summary .= $result['index']
                    . '. '
                    . $icon
                    . ' '
                    . self::limit_text(
                        (string) $result['title'],
                        70
                    )
                    . "\n";

                if (!empty($result['link'])) {
                    $summary .= (string) $result['link'] . "\n";
                }
            }

            self::notify(
                $chat_id,
                self::limit_text($summary, 3900),
                $settings
            );
        }

        return new WP_REST_Response(
            [
                'ok' => true,
                'bulk' => count($items) > 1,
                'total' => count($items),
                'published' => $published,
                'drafts' => $drafts,
                'duplicates' => $duplicates,
                'failed' => $failed,
                'results' => $results,
            ],
            200
        );
    }

    /**
     * Store one Telegram bulk message as a background batch.
     * Each batch is a non-autoloaded option, so no large queue is loaded on
     * normal page requests and concurrent incoming batches do not overwrite
     * one another.
     */
    private static function enqueue_bulk_batch(
        array $items,
        string $chat_id,
        int $update_id
    ) {
        if (count($items) < 2) {
            return new WP_Error('bulk_queue_small', 'Bulk minimal 2 video.');
        }

        $clean_items = [];

        foreach ($items as $item) {
            if (!is_array($item) || count($item) < 3) {
                continue;
            }

            $actors = isset($item[3]) && is_array($item[3])
                ? self::sanitize_actor_names($item[3])
                : [];

            $clean_items[] = [
                self::clean_title((string) $item[0]),
                esc_url_raw((string) $item[1]),
                esc_url_raw((string) $item[2]),
                $actors,
            ];
        }

        if (count($clean_items) < 2) {
            return new WP_Error('bulk_queue_invalid', 'Item bulk tidak valid.');
        }

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $batch_id = strtolower(
                base_convert((string) time(), 10, 36)
                . '-' . absint($update_id)
                . '-' . wp_generate_password(6, false, false)
            );
            $batch_id = sanitize_key($batch_id);
            $option = self::BULK_BATCH_PREFIX . $batch_id;

            $batch = [
                'version' => 1,
                'batch_id' => $batch_id,
                'chat_id' => $chat_id,
                'update_id' => $update_id,
                'created_at' => time(),
                'updated_at' => time(),
                'total' => count($clean_items),
                'next_index' => 0,
                'items' => $clean_items,
                'results' => [],
                'published' => 0,
                'drafts' => 0,
                'duplicates' => 0,
                'failed' => 0,
            ];

            if (add_option($option, $batch, '', false)) {
                return $batch_id;
            }
        }

        return new WP_Error(
            'bulk_queue_create_failed',
            'Tidak dapat membuat batch antrian.'
        );
    }

    /**
     * OS worker entry point. Processes items strictly sequentially and keeps
     * a short time budget per cron run. If a worker dies after publishing an
     * item but before saving progress, video_url duplicate protection makes
     * the retry idempotent on the next run.
     */
    public static function process_bulk_queue_worker(
        int $max_items = 10,
        int $max_seconds = 50
    ): array {
        global $wpdb;

        $max_items = max(1, min(50, $max_items));
        $max_seconds = max(10, min(240, $max_seconds));
        $started = microtime(true);
        $processed = 0;
        $completed_batches = 0;
        $settings = self::settings();

        while (
            $processed < $max_items
            && (microtime(true) - $started) < $max_seconds
        ) {
            $like = $wpdb->esc_like(self::BULK_BATCH_PREFIX) . '%';
            $option_name = (string) $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT option_name
                     FROM {$wpdb->options}
                     WHERE option_name LIKE %s
                     ORDER BY option_id ASC
                     LIMIT 1",
                    $like
                )
            );

            if ($option_name === '') {
                break;
            }

            $batch = get_option($option_name, []);

            if (!is_array($batch) || empty($batch['items'])) {
                delete_option($option_name);
                continue;
            }

            $created_at = absint($batch['created_at'] ?? 0);

            if ($created_at > 0 && (time() - $created_at) > 2 * DAY_IN_SECONDS) {
                self::notify(
                    (string) ($batch['chat_id'] ?? ''),
                    "❌ Antrian bulk kedaluwarsa dan dibersihkan.\nBatch: "
                    . (string) ($batch['batch_id'] ?? '-'),
                    $settings
                );
                delete_option($option_name);
                continue;
            }

            $items = array_values((array) $batch['items']);
            $index = absint($batch['next_index'] ?? 0);
            $total = count($items);

            if ($index >= $total) {
                self::finish_bulk_batch($option_name, $batch, $settings);
                $completed_batches++;
                continue;
            }

            $item = $items[$index];
            $title = isset($item[0]) ? (string) $item[0] : '';
            $video_url = isset($item[1]) ? (string) $item[1] : '';
            $thumbnail_url = isset($item[2]) ? (string) $item[2] : '';
            $actors = isset($item[3]) && is_array($item[3]) ? $item[3] : [];

            try {
                $result = self::process_video_item(
                    $title,
                    $video_url,
                    $thumbnail_url,
                    $actors,
                    $settings
                );
            } catch (Throwable $e) {
                $result = new WP_Error(
                    'bulk_worker_exception',
                    $e->getMessage()
                );
            }

            if (is_wp_error($result)) {
                $batch['failed'] = absint($batch['failed'] ?? 0) + 1;
                $stored_result = [
                    'index' => $index + 1,
                    'title' => $title,
                    'status' => 'failed',
                    'message' => $result->get_error_message(),
                ];
            } else {
                $status = (string) ($result['status'] ?? 'failed');

                if ($status === 'publish') {
                    $batch['published'] = absint($batch['published'] ?? 0) + 1;
                } elseif ($status === 'draft') {
                    $batch['drafts'] = absint($batch['drafts'] ?? 0) + 1;
                } elseif ($status === 'duplicate') {
                    $batch['duplicates'] = absint($batch['duplicates'] ?? 0) + 1;
                } else {
                    $batch['failed'] = absint($batch['failed'] ?? 0) + 1;
                }

                $stored_result = array_merge(
                    [
                        'index' => $index + 1,
                        'title' => $title,
                    ],
                    $result
                );
            }

            $batch['results'][] = $stored_result;
            $batch['next_index'] = $index + 1;
            $batch['updated_at'] = time();
            update_option($option_name, $batch, false);
            $processed++;

            self::notify_bulk_item_progress(
                (string) ($batch['chat_id'] ?? ''),
                $stored_result,
                $index + 1,
                $total,
                $settings
            );

            if (($index + 1) >= $total) {
                self::finish_bulk_batch($option_name, $batch, $settings);
                $completed_batches++;
            }
        }

        return [
            'processed' => $processed,
            'completed_batches' => $completed_batches,
            'seconds' => round(microtime(true) - $started, 3),
        ];
    }

    /**
     * Kirim status segera setelah satu item bulk selesai diproses.
     * Ini membuat admin bisa melihat progres tanpa menunggu batch berakhir.
     */
    private static function notify_bulk_item_progress(
        string $chat_id,
        array $result,
        int $current,
        int $total,
        array $settings
    ): void {
        if ($chat_id === '') {
            return;
        }

        $status = (string) ($result['status'] ?? 'failed');
        $title = self::limit_text(
            (string) ($result['title'] ?? ''),
            100
        );
        $progress = '[' . max(1, $current) . '/' . max(1, $total) . ']';

        if ($status === 'publish' || $status === 'draft') {
            $text = ($status === 'publish' ? '✅ ' : '📝 ')
                . $progress
                . ' '
                . ($status === 'publish' ? 'Published' : 'Draft')
                . ($result['thumbnail_line'] ?? '')
                . ($result['r2_line'] ?? '')
                . ($result['duration_line'] ?? '')
                . ($result['rotation_line'] ?? '')
                . ($result['category_line'] ?? '')
                . ($result['rank_math_line'] ?? '')
                . "\n\n"
                . $title;

            if (!empty($result['link'])) {
                $text .= "\n" . (string) $result['link'];
            }

            self::notify($chat_id, $text, $settings);
            return;
        }

        if ($status === 'duplicate') {
            $text = "⏭️ {$progress} Video sudah pernah dipost.\n\n"
                . $title;

            if (!empty($result['link'])) {
                $text .= "\n" . (string) $result['link'];
            }

            self::notify($chat_id, $text, $settings);
            return;
        }

        $message = self::limit_text(
            (string) ($result['message'] ?? 'Unknown error'),
            300
        );

        self::notify(
            $chat_id,
            "❌ {$progress} Gagal\n\n"
            . $title
            . "\n"
            . $message,
            $settings
        );
    }

    private static function finish_bulk_batch(
        string $option_name,
        array $batch,
        array $settings
    ): void {
        $total = absint($batch['total'] ?? count((array) ($batch['items'] ?? [])));
        $published = absint($batch['published'] ?? 0);
        $drafts = absint($batch['drafts'] ?? 0);
        $duplicates = absint($batch['duplicates'] ?? 0);
        $failed = absint($batch['failed'] ?? 0);

        $summary = "✅ BULK SELESAI\n"
            . "Total: {$total} | Publish: {$published}"
            . ($drafts > 0 ? " | Draft: {$drafts}" : '')
            . ($duplicates > 0 ? " | Duplikat: {$duplicates}" : '')
            . ($failed > 0 ? " | Gagal: {$failed}" : '');

        self::notify(
            (string) ($batch['chat_id'] ?? ''),
            $summary,
            $settings
        );

        delete_option($option_name);
    }

    private static function process_video_item(
        string $title,
        string $video_url,
        string $thumbnail_url,
        array $actors,
        array $settings
    ) {
        $title = self::clean_title($title);
        $actors = self::sanitize_actor_names($actors);

        $existing_id = self::find_post_by_video_url($video_url);

        if ($existing_id > 0) {
            return [
                'status' => 'duplicate',
                'post_id' => $existing_id,
                'link' => get_permalink($existing_id),
            ];
        }

        $final_status = $settings['post_status'] === 'draft'
            ? 'draft'
            : 'publish';

        /*
         * Always prepare as draft first, then publish only after the
         * thumbnail/categories/SEO metadata are ready.
         */
        $post_id = wp_insert_post(
            [
                'post_type' => 'post',
                'post_status' => 'draft',
                'post_title' => $title,
                'post_name' => sanitize_title($title),
                'post_content' => '',
                'post_excerpt' => '',
                'post_author' => (int) $settings['author_id'],
            ],
            true
        );

        if (is_wp_error($post_id)) {
            return new WP_Error(
                'bulk_post_insert_failed',
                'Gagal membuat post WordPress.'
            );
        }

        $post_id = (int) $post_id;

        update_post_meta(
            $post_id,
            'video_url',
            esc_url_raw($video_url)
        );

        $rotation_line = '';

        if (!empty($settings['rotation_enabled'])) {
            update_post_meta(
                $post_id,
                '_pltp_rotation_pending',
                1
            );

            update_post_meta(
                $post_id,
                '_pltp_rotation_attempts',
                0
            );

            update_post_meta(
                $post_id,
                '_pltp_rotation_count',
                5
            );

            delete_post_meta(
                $post_id,
                '_pltp_rotation_error'
            );

            $rotation_line = "\n🎞 Rotation: antre";
        }

        $duration_line = '';

        if (!empty($settings['auto_duration'])) {
            update_post_meta($post_id, 'duration', 0);
            update_post_meta(
                $post_id,
                '_pltp_duration_seconds',
                0
            );
            update_post_meta(
                $post_id,
                '_pltp_duration_pending',
                1
            );
            update_post_meta(
                $post_id,
                '_pltp_duration_attempts',
                0
            );
            delete_post_meta(
                $post_id,
                '_pltp_duration_error'
            );

            $duration_line = "\n⏱ Duration: antre";
        }

        $category_names = [];

        if (!empty($settings['auto_category'])) {
            $category_names = self::apply_categories(
                $post_id,
                $title,
                (string) $settings['category_rules'],
                (string) $settings['default_category']
            );
        }

        $navigation_terms = self::apply_actor_and_tags(
            $post_id,
            $title,
            $actors
        );
        $actor_names = $navigation_terms['actors'];
        $tag_names = $navigation_terms['tags'];

        if (!empty($settings['smart_content_enabled'])) {
            update_post_meta($post_id, '_pltp_smart_content_managed', 1);
            self::refresh_smart_content($post_id);
        } else {
            delete_post_meta($post_id, '_pltp_smart_content_managed');

            $description = self::render_content_template(
                (string) $settings['description_template'],
                $title,
                $video_url,
                $thumbnail_url,
                $category_names
            );

            if ($description !== '') {
                wp_update_post(
                    [
                        'ID' => $post_id,
                        'post_content' => $description,
                        'post_excerpt' => wp_trim_words(
                            wp_strip_all_tags($description),
                            35,
                            ''
                        ),
                    ]
                );
            }
        }

        self::clear_old_thumbnail_state($post_id);

        $thumbnail_id = 0;
        $final_image_url = '';
        $thumbnail_line = '';
        $r2_line = '';

        if ($thumbnail_url !== '') {
            // Stable V1.9.1 manual-thumbnail path: kept intact.
            $thumbnail = self::sideload_thumbnail(
                $post_id,
                $thumbnail_url,
                $title
            );

            if (is_wp_error($thumbnail)) {
                update_post_meta(
                    $post_id,
                    '_pltp_thumbnail_error',
                    $thumbnail->get_error_message()
                );

                $thumbnail_line = "\n🖼 Thumbnail: gagal";
                $final_image_url = $thumbnail_url;
            } else {
                $thumbnail_id = (int) $thumbnail;

                $final_image_url = (string) wp_get_attachment_image_url(
                    $thumbnail_id,
                    'full'
                );

                if ($final_image_url === '') {
                    $final_image_url = $thumbnail_url;
                }

                $thumbnail_line = "\n🖼 Thumbnail: OK";
            }

            if (!empty($settings['r2_enabled'])) {
                $r2_error = (string) get_post_meta(
                    $post_id,
                    '_pltp_r2_error',
                    true
                );

                $r2_url = (string) get_post_meta(
                    $post_id,
                    '_pltp_thumbnail_r2_url',
                    true
                );

                if ($r2_error !== '') {
                    $r2_line = "\n☁️ R2: gagal, lokal dipakai";
                } elseif ($r2_url !== '') {
                    $r2_line = "\n☁️ R2: OK";
                    $final_image_url = $r2_url;
                }
            }
        } elseif (!empty($settings['auto_thumbnail'])) {
            // No thumbnail URL: leave heavy FFmpeg work to the OS worker.
            update_post_meta($post_id, '_pltp_thumb_pending', 1);
            update_post_meta($post_id, '_pltp_thumb_attempts', 0);
            delete_post_meta($post_id, '_pltp_thumb_done');
            delete_post_meta($post_id, '_pltp_thumbnail_error');

            $thumbnail_line = "\n🖼 Thumbnail: AUTO antre";

            if (!empty($settings['r2_enabled'])) {
                $r2_line = "\n☁️ R2: menunggu thumbnail";
            }
        } else {
            $thumbnail_line = "\n🖼 Thumbnail: kosong";
        }

        $rank_math_line = '';

        if (
            !empty($settings['rank_math_enabled'])
            && self::rank_math_active()
        ) {
            self::apply_rank_math(
                $post_id,
                $title,
                $final_image_url,
                $thumbnail_id,
                $category_names,
                $settings
            );

            self::ensure_rank_math_video_schema(
                $post_id,
                $video_url
            );

            self::safe_refresh_rank_math_video_sitemap_cache();

            $rank_math_line = "\n🔎 Rank Math: OK";
        }

        if ($final_status === 'publish') {
            $published = wp_update_post(
                [
                    'ID' => $post_id,
                    'post_status' => 'publish',
                ],
                true
            );

            if (is_wp_error($published)) {
                return new WP_Error(
                    'bulk_publish_failed',
                    'Post dibuat sebagai draft, tetapi gagal dipublikasikan.'
                );
            }
        }

        clean_post_cache($post_id);

        // Fire-and-forget: Telegram receives a fast response while the OS
        // worker handles the main thumbnail and five rotation frames.
        if (class_exists('PLTP_Auto_Media')) {
            PLTP_Auto_Media::post_ready($post_id);
        }

        $category_line = !empty($category_names)
            ? "\n📂 " . implode(', ', $category_names)
            : '';

        $actor_line = !empty($actor_names)
            ? "\n⭐ Aktor: " . implode(', ', $actor_names)
            : '';

        $tag_line = !empty($tag_names)
            ? "\n🏷 Tag: " . implode(', ', $tag_names)
            : '';

        $link = $final_status === 'publish'
            ? get_permalink($post_id)
            : admin_url(
                'post.php?post='
                . $post_id
                . '&action=edit'
            );

        return [
            'status' => $final_status,
            'post_id' => $post_id,
            'thumbnail_id' => $thumbnail_id,
            'categories' => $category_names,
            'actors' => $actor_names,
            'tags' => $tag_names,
            'link' => $link,
            'thumbnail_line' => $thumbnail_line,
            'r2_line' => $r2_line,
            'duration_line' => $duration_line,
            'rotation_line' => $rotation_line,
            'category_line' => $category_line,
            'actor_line' => $actor_line,
            'tag_line' => $tag_line,
            'rank_math_line' => $rank_math_line,
            'r2_url' => (string) get_post_meta(
                $post_id,
                '_pltp_thumbnail_r2_url',
                true
            ),
        ];
    }

    /**
     * Duplicate protection for Telegram retries and repeated bulk lists.
     */
    private static function find_post_by_video_url(
        string $video_url
    ): int {
        $ids = get_posts(
            [
                'post_type' => 'post',
                'post_status' => [
                    'publish',
                    'draft',
                    'pending',
                    'private',
                    'future',
                ],
                'posts_per_page' => 1,
                'fields' => 'ids',
                'meta_key' => 'video_url',
                'meta_value' => esc_url_raw($video_url),
                'orderby' => 'ID',
                'order' => 'DESC',
                'no_found_rows' => true,
            ]
        );

        return !empty($ids)
            ? (int) $ids[0]
            : 0;
    }

    /**
     * Keep Rank Math's public VideoObject duration synchronized with
     * UltimaTube's native `duration` post meta.
     *
     * Rank Math's rank_math/json_ld filter receives the final JSON-LD array.
     * We only change VideoObject nodes and leave every other schema untouched.
     */
    /**
     * Primary Rank Math VideoObject integration.
     *
     * Rank Math may auto-generate VideoObject without persisting
     * rank_math_schema_VideoObject post meta. V1.7.1 therefore reads the
     * native UltimaTube `duration` meta and injects it directly into the
     * generated VideoObject entity.
     */
    public static function filter_rank_math_videoobject_entity($entity)
    {
        if (!is_singular('post') || !is_array($entity)) {
            return $entity;
        }

        $post_id = (int) get_queried_object_id();

        if ($post_id <= 0) {
            return $entity;
        }

        $seconds = (int) get_post_meta($post_id, 'duration', true);

        if ($seconds <= 0) {
            return $entity;
        }

        $entity['duration'] = self::duration_to_iso8601($seconds);

        return $entity;
    }

    public static function filter_rank_math_json_ld($data, $jsonld)
    {
        if (!is_singular('post') || !is_array($data)) {
            return $data;
        }

        $post_id = (int) get_queried_object_id();

        if ($post_id <= 0) {
            return $data;
        }

        $seconds = (int) get_post_meta($post_id, 'duration', true);

        if ($seconds <= 0) {
            return $data;
        }

        $iso = self::duration_to_iso8601($seconds);
        self::inject_video_duration($data, $iso);

        return $data;
    }

    private static function inject_video_duration(array &$node, string $iso): void
    {
        if (isset($node['@type'])) {
            $types = is_array($node['@type'])
                ? $node['@type']
                : [$node['@type']];

            if (in_array('VideoObject', $types, true)) {
                $node['duration'] = $iso;
            }
        }

        foreach ($node as &$value) {
            if (is_array($value)) {
                self::inject_video_duration($value, $iso);
            }
        }

        unset($value);
    }

    private static function duration_to_iso8601(int $seconds): string
    {
        $seconds = max(0, $seconds);

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        $value = 'PT';

        if ($hours > 0) {
            $value .= $hours . 'H';
        }

        if ($minutes > 0) {
            $value .= $minutes . 'M';
        }

        if ($secs > 0 || $value === 'PT') {
            $value .= $secs . 'S';
        }

        return $value;
    }

    private static function parse_bulk_message(
        string $text
    ) {
        $lines = preg_split('/\R/u', trim($text));

        if (!is_array($lines)) {
            return new WP_Error(
                'bad_message',
                'Pesan tidak valid.'
            );
        }

        $lines = array_values(
            array_filter(
                array_map('trim', $lines),
                static fn($value) => $value !== ''
            )
        );

        if (count($lines) < 2) {
            return new WP_Error(
                'bad_format',
                "Format minimal:\n"
                . "Judul Video\n"
                . "Aktor: Nama Aktor (opsional)\n"
                . "https://domain.com/video.mp4"
            );
        }

        $items = [];
        $i = 0;
        $count = count($lines);

        while ($i < $count) {
            $title = self::clean_title((string) $lines[$i]);
            $i++;

            if ($title === '') {
                return new WP_Error('empty_title', 'Judul kosong.');
            }

            $actors = [];

            // Actor line may be placed immediately after title.
            if ($i < $count && self::is_actor_line((string) $lines[$i])) {
                $actors = self::parse_actor_line((string) $lines[$i]);
                $i++;
            }

            if ($i >= $count) {
                return new WP_Error(
                    'bulk_incomplete',
                    'Judul terakhir tidak memiliki direct URL .mp4.'
                );
            }

            $video_url = esc_url_raw((string) $lines[$i]);
            $i++;

            if (!self::is_http_url($video_url)) {
                return new WP_Error(
                    'bad_video_url',
                    'Video #' . (count($items) + 1) . ': URL video tidak valid.'
                );
            }

            $video_path = (string) wp_parse_url($video_url, PHP_URL_PATH);

            if (!preg_match('/\.mp4$/i', $video_path)) {
                return new WP_Error(
                    'not_mp4',
                    'Video #' . (count($items) + 1) . ': baris video harus direct URL .mp4.'
                );
            }

            // Also accept "Aktor:" after the video URL.
            if ($i < $count && self::is_actor_line((string) $lines[$i])) {
                $actors = array_values(
                    array_unique(
                        array_merge(
                            $actors,
                            self::parse_actor_line((string) $lines[$i])
                        )
                    )
                );
                $i++;
            }

            $thumbnail_url = '';

            // Optional manual thumbnail URL. Otherwise auto-thumbnail stays active.
            if ($i < $count) {
                $candidate = esc_url_raw((string) $lines[$i]);
                $candidate_path = (string) wp_parse_url($candidate, PHP_URL_PATH);

                if (
                    self::is_http_url($candidate)
                    && !preg_match('/\.mp4$/i', $candidate_path)
                ) {
                    $thumbnail_url = $candidate;
                    $i++;
                }
            }

            $items[] = [
                $title,
                $video_url,
                $thumbnail_url,
                self::sanitize_actor_names($actors),
            ];
        }

        return $items;
    }

    private static function parse_message(string $text)
    {
        $items = self::parse_bulk_message($text);

        if (is_wp_error($items)) {
            return $items;
        }

        if (count($items) !== 1) {
            return new WP_Error(
                'bad_single_format',
                'Format single hanya boleh berisi satu video.'
            );
        }

        return $items[0];
    }

    /**
     * Rapikan judul dan normalkan penomoran seri.
     *
     * Contoh:
     * - video_viral_hari_ini       -> video viral hari ini
     * - Hijab Alisyaa (1)          -> Hijab Alisyaa - Part 1
     * - Hijab Alisyaa [10]         -> Hijab Alisyaa - Part 10
     * - Hijab-Alisyaa Part-2       -> Hijab Alisyaa - Part 2
     */
    private static function clean_title(string $title): string
    {
        $title = wp_strip_all_tags($title);
        $title = html_entity_decode($title, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $title = str_replace(array('_', "\t"), ' ', $title);
        $title = preg_replace('/\s+/u', ' ', trim((string) $title));

        $part = 0;

        if (
            preg_match(
                '/(?:\(\s*(\d{1,4})\s*\)|\[\s*(\d{1,4})\s*\])\s*$/u',
                $title,
                $m
            )
        ) {
            $part = (int) (!empty($m[1]) ? $m[1] : $m[2]);
            $title = preg_replace(
                '/(?:\(\s*\d{1,4}\s*\)|\[\s*\d{1,4}\s*\])\s*$/u',
                '',
                $title
            );
        } elseif (
            preg_match(
                '/\b(?:part|bagian|episode|ep)\s*[-#:]*\s*(\d{1,4})\s*$/iu',
                $title,
                $m
            )
        ) {
            $part = (int) $m[1];
            $title = preg_replace(
                '/\b(?:part|bagian|episode|ep)\s*[-#:]*\s*\d{1,4}\s*$/iu',
                '',
                $title
            );
        }

        $title = preg_replace('/\s*[-–—]+\s*/u', ' ', (string) $title);
        $title = preg_replace('/\s+/u', ' ', trim((string) $title));
        $title = sanitize_text_field((string) $title);

        if ($part > 0) {
            $title .= ' - Part ' . $part;
        }

        return trim($title);
    }

    private static function is_actor_line(string $line): bool
    {
        return (bool) preg_match('/^(aktor|actor)\s*:/iu', trim($line));
    }

    private static function parse_actor_line(string $line): array
    {
        $value = preg_replace('/^(aktor|actor)\s*:\s*/iu', '', trim($line));
        $parts = preg_split('/\s*[,|\/]\s*/u', (string) $value);
        $parts = is_array($parts) ? $parts : [];

        return self::sanitize_actor_names($parts);
    }

    private static function sanitize_actor_names(array $actors): array
    {
        $clean = [];

        foreach ($actors as $actor) {
            $actor = sanitize_text_field((string) $actor);
            $actor = preg_replace('/\s+/u', ' ', trim((string) $actor));

            if ($actor === '') {
                continue;
            }

            $key = function_exists('mb_strtolower')
                ? mb_strtolower($actor, 'UTF-8')
                : strtolower($actor);

            $clean[$key] = $actor;

            if (count($clean) >= 8) {
                break;
            }
        }

        return array_values($clean);
    }

    /**
     * Pakai taxonomy "actors" bawaan RetroTube, lalu buat maksimal 3 post tags.
     * Actor dimasukkan dulu sebagai tag navigasi, sisanya kata penting judul.
     */
    private static function apply_actor_and_tags(
        int $post_id,
        string $title,
        array $actors
    ): array {
        $actor_names = self::sanitize_actor_names($actors);

        if (!empty($actor_names) && taxonomy_exists('actors')) {
            $actor_result = wp_set_object_terms(
                $post_id,
                $actor_names,
                'actors',
                false
            );

            if (is_wp_error($actor_result)) {
                $actor_names = [];
            }
        } elseif (!taxonomy_exists('actors')) {
            $actor_names = [];
        }

        $tags = [];

        foreach ($actor_names as $actor_name) {
            $tags[] = $actor_name;

            if (count($tags) >= 3) {
                break;
            }
        }

        if (count($tags) < 3) {
            $title_for_tags = self::clean_title($title);

            foreach ($actor_names as $actor_name) {
                $title_for_tags = str_ireplace($actor_name, ' ', $title_for_tags);
            }

            $tokens = preg_split(
                '/[^\p{L}\p{N}]+/u',
                $title_for_tags
            );
            $tokens = is_array($tokens) ? $tokens : [];

            $stopwords = array_fill_keys(
                [
                    'video', 'videos', 'terbaru', 'telegram', 'tele',
                    'pejuanglendir', 'pejuanglendir01', 'update', 'full',
                    'part', 'episode', 'eps', 'yang', 'dan', 'atau',
                    'dengan', 'dari', 'untuk', 'pada', 'dalam', 'ini',
                    'itu', 'aja', 'saja', 'lagi', 'ada', 'sama', 'buat',
                    'hari', 'baru', 'the', 'and', 'with', 'bokep',
                ],
                true
            );

            $seen = [];

            foreach ($tags as $tag) {
                $seen[
                    function_exists('mb_strtolower')
                        ? mb_strtolower($tag, 'UTF-8')
                        : strtolower($tag)
                ] = true;
            }

            foreach ($tokens as $token) {
                $token = sanitize_text_field((string) $token);
                $token = trim($token);

                if ($token === '') {
                    continue;
                }

                $lower = function_exists('mb_strtolower')
                    ? mb_strtolower($token, 'UTF-8')
                    : strtolower($token);

                $length = function_exists('mb_strlen')
                    ? mb_strlen($token, 'UTF-8')
                    : strlen($token);

                if (
                    $length < 3
                    || isset($stopwords[$lower])
                    || isset($seen[$lower])
                    || ctype_digit($token)
                ) {
                    continue;
                }

                $tags[] = $token;
                $seen[$lower] = true;

                if (count($tags) >= 3) {
                    break;
                }
            }
        }

        $tags = array_values(array_slice(array_unique($tags), 0, 3));

        if (!empty($tags)) {
            $tag_result = wp_set_post_tags($post_id, $tags, false);

            if (is_wp_error($tag_result)) {
                $tags = [];
            }
        }

        return [
            'actors' => $actor_names,
            'tags' => $tags,
        ];
    }

    /**
     * Actor/tag hanya untuk navigasi pengunjung, bukan landing page SEO.
     */
    public static function filter_navigation_taxonomy_robots(array $robots): array
    {
        if (is_tag() || is_tax('actors')) {
            $robots['noindex'] = true;
            unset($robots['nofollow']);
        }

        return $robots;
    }

    public static function filter_rank_math_navigation_robots(array $robots): array
    {
        if (is_tag() || is_tax('actors')) {
            $robots['index'] = 'noindex';
            $robots['follow'] = 'follow';
        }

        return $robots;
    }

    /**
     * Build a short factual description from metadata that actually exists.
     * No invented adjectives or claims are added.
     */
    private static function build_smart_description(
        int $post_id,
        string $title = '',
        array $categories = []
    ): string {
        if ($title === '') {
            $title = self::clean_title((string) get_the_title($post_id));
        }

        if (empty($categories)) {
            $categories = wp_get_post_categories(
                $post_id,
                ['fields' => 'names']
            );
        }

        $categories = is_array($categories)
            ? array_values(array_filter(array_map('strval', $categories)))
            : [];

        $actors = [];

        if (taxonomy_exists('actors')) {
            $actor_terms = wp_get_post_terms(
                $post_id,
                'actors',
                ['fields' => 'names']
            );

            if (!is_wp_error($actor_terms) && is_array($actor_terms)) {
                $actors = array_values(
                    array_filter(array_map('strval', $actor_terms))
                );
            }
        }

        $tags = wp_get_post_tags(
            $post_id,
            ['fields' => 'names']
        );
        $tags = is_array($tags)
            ? array_values(array_filter(array_map('strval', $tags)))
            : [];

        $seconds = max(
            0,
            (int) get_post_meta($post_id, 'duration', true)
        );

        $site_name = trim(wp_strip_all_tags(get_bloginfo('name')));
        $sentences = [];

        $lead = 'Tonton ' . $title;
        if ($site_name !== '') {
            $lead .= ' di ' . $site_name;
        }
        $sentences[] = $lead . '.';

        $facts = [];

        if (!empty($actors)) {
            $facts[] = 'Aktor: ' . implode(', ', array_slice($actors, 0, 3));
        }

        if (!empty($categories)) {
            $facts[] = 'Kategori: ' . implode(', ', array_slice($categories, 0, 3));
        }

        if ($seconds > 0) {
            $clock = $seconds >= HOUR_IN_SECONDS
                ? gmdate('H:i:s', $seconds)
                : gmdate('i:s', $seconds);

            $facts[] = 'Durasi: ' . $clock;
        }

        if (!empty($facts)) {
            $sentences[] = implode(' • ', $facts) . '.';
        }

        if (!empty($tags)) {
            $sentences[] = 'Topik: '
                . implode(', ', array_slice($tags, 0, 3))
                . '.';
        }

        if (!empty($actors)) {
            $sentences[] = 'Lihat video '
                . $actors[0]
                . ' lainnya yang tersedia di situs.';
        } elseif (!empty($categories)) {
            $sentences[] = 'Jelajahi video terkait lainnya dalam kategori '
                . $categories[0]
                . '.';
        }

        return trim(
            preg_replace(
                '/\s+/u',
                ' ',
                implode(' ', $sentences)
            )
        );
    }

    /**
     * Refresh post content/excerpt + Rank Math description after factual
     * metadata (especially duration) becomes available.
     */
    public static function refresh_smart_content(int $post_id): void
    {
        if ($post_id <= 0) {
            return;
        }

        $settings = self::settings();

        if (
            empty($settings['smart_content_enabled'])
            || (int) get_post_meta(
                $post_id,
                '_pltp_smart_content_managed',
                true
            ) !== 1
        ) {
            return;
        }

        $title = self::clean_title((string) get_the_title($post_id));
        $categories = wp_get_post_categories(
            $post_id,
            ['fields' => 'names']
        );
        $categories = is_array($categories)
            ? array_values(array_map('strval', $categories))
            : [];

        $description = self::build_smart_description(
            $post_id,
            $title,
            $categories
        );

        if ($description === '') {
            return;
        }

        wp_update_post(
            [
                'ID' => $post_id,
                'post_content' => wpautop(esc_html($description)),
                'post_excerpt' => self::limit_text(
                    wp_strip_all_tags($description),
                    220
                ),
            ]
        );

        if (
            !empty($settings['rank_math_enabled'])
            && self::rank_math_active()
        ) {
            $seo_description = self::limit_text(
                wp_strip_all_tags($description),
                160
            );

            update_post_meta(
                $post_id,
                'rank_math_description',
                $seo_description
            );
            update_post_meta(
                $post_id,
                'rank_math_facebook_description',
                $seo_description
            );
        }

        clean_post_cache($post_id);
    }


    /**
     * Start a conservative oldest-to-newest synchronization for every
     * WordPress post that has a video_url. Existing views, slugs, actors,
     * manual thumbnails and manually-written long descriptions are preserved.
     */
    public static function backfill_start(bool $restart = false): array
    {
        $existing = get_option(self::BACKFILL_OPT, []);

        if (
            !$restart
            && is_array($existing)
            && !empty($existing['running'])
            && empty($existing['done'])
        ) {
            return self::backfill_status();
        }

        global $wpdb;

        $total = (int) $wpdb->get_var(
            "SELECT COUNT(DISTINCT p.ID)
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm
                ON pm.post_id = p.ID
               AND pm.meta_key = 'video_url'
             WHERE p.post_type = 'post'
               AND p.post_status IN ('publish','draft')
               AND pm.meta_value <> ''"
        );

        $state = [
            'running' => 1,
            'done' => 0,
            'started_at' => time(),
            'updated_at' => time(),
            'finished_at' => 0,
            'last_id' => 0,
            'total' => max(0, $total),
            'processed' => 0,
            'changed_titles' => 0,
            'actors_matched' => 0,
            'tags_added' => 0,
            'thumb_queued' => 0,
            'r2_checked' => 0,
            'duration_queued' => 0,
            'rotation_queued' => 0,
            'rank_math_refreshed' => 0,
            'download_refreshed' => 0,
            'views_initialized' => 0,
            'smart_content_refreshed' => 0,
            'errors' => 0,
            'last_error' => '',
        ];

        update_option(self::BACKFILL_OPT, $state, false);

        return $state;
    }

    public static function backfill_status(): array
    {
        $state = get_option(self::BACKFILL_OPT, []);

        return is_array($state) ? $state : [];
    }

    /**
     * One light item per cron run. Heavy FFmpeg work is only queued here and
     * remains handled by the existing performance-safe workers.
     */
    public static function backfill_worker_run(int $limit = 1): array
    {
        $limit = max(1, min(3, $limit));
        $state = self::backfill_status();

        if (empty($state) || empty($state['running']) || !empty($state['done'])) {
            return [
                'status' => 'idle',
                'state' => $state,
            ];
        }

        global $wpdb;

        $last_id = absint($state['last_id'] ?? 0);

        $ids = $wpdb->get_col(
            $wpdb->prepare(
                "SELECT DISTINCT p.ID
                 FROM {$wpdb->posts} p
                 INNER JOIN {$wpdb->postmeta} pm
                    ON pm.post_id = p.ID
                   AND pm.meta_key = 'video_url'
                 WHERE p.post_type = 'post'
                   AND p.post_status IN ('publish','draft')
                   AND pm.meta_value <> ''
                   AND p.ID > %d
                 ORDER BY p.ID ASC
                 LIMIT %d",
                $last_id,
                $limit
            )
        );

        $ids = array_values(array_map('intval', is_array($ids) ? $ids : []));

        if (empty($ids)) {
            $state['running'] = 0;
            $state['done'] = 1;
            $state['finished_at'] = time();
            $state['updated_at'] = time();
            update_option(self::BACKFILL_OPT, $state, false);

            return [
                'status' => 'done',
                'state' => $state,
            ];
        }

        $results = [];

        foreach ($ids as $post_id) {
            try {
                $result = self::backfill_one_post($post_id);
                $results[] = $result;

                foreach (
                    [
                        'changed_titles',
                        'actors_matched',
                        'tags_added',
                        'thumb_queued',
                        'r2_checked',
                        'duration_queued',
                        'rotation_queued',
                        'rank_math_refreshed',
                        'download_refreshed',
                        'views_initialized',
                        'smart_content_refreshed',
                    ] as $key
                ) {
                    if (!empty($result[$key])) {
                        $state[$key] = absint($state[$key] ?? 0) + absint($result[$key]);
                    }
                }
            } catch (Throwable $e) {
                $state['errors'] = absint($state['errors'] ?? 0) + 1;
                $state['last_error'] = self::limit_text($e->getMessage(), 500);

                $results[] = [
                    'post_id' => $post_id,
                    'error' => $state['last_error'],
                ];
            }

            $state['last_id'] = $post_id;
            $state['processed'] = absint($state['processed'] ?? 0) + 1;
            $state['updated_at'] = time();
            update_option(self::BACKFILL_OPT, $state, false);
        }

        return [
            'status' => 'working',
            'items' => $results,
            'state' => $state,
        ];
    }

    private static function backfill_one_post(int $post_id): array
    {
        $post = get_post($post_id);

        if (!$post || !in_array($post->post_status, ['publish', 'draft'], true)) {
            throw new RuntimeException('Post tidak ditemukan atau status tidak didukung.');
        }

        $video_url = trim((string) get_post_meta($post_id, 'video_url', true));

        if ($video_url === '') {
            throw new RuntimeException('video_url kosong.');
        }

        $settings = self::settings();
        $result = [
            'post_id' => $post_id,
            'changed_titles' => 0,
            'actors_matched' => 0,
            'tags_added' => 0,
            'thumb_queued' => 0,
            'r2_checked' => 0,
            'duration_queued' => 0,
            'rotation_queued' => 0,
            'rank_math_refreshed' => 0,
            'download_refreshed' => 0,
            'views_initialized' => 0,
            'smart_content_refreshed' => 0,
        ];

        /*
         * 1. Clean the visible title, but preserve the old permalink/slug.
         * This avoids changing already-shared URLs.
         */
        $old_title = (string) $post->post_title;
        $clean_title = self::clean_title($old_title);

        if ($clean_title !== '' && $clean_title !== $old_title) {
            if (!metadata_exists('post', $post_id, '_pltp_backfill_old_title_v1917')) {
                add_post_meta(
                    $post_id,
                    '_pltp_backfill_old_title_v1917',
                    $old_title,
                    true
                );
            }

            wp_update_post(
                [
                    'ID' => $post_id,
                    'post_title' => $clean_title,
                    'post_name' => (string) $post->post_name,
                ]
            );
            $result['changed_titles'] = 1;
        }

        if ($clean_title === '') {
            $clean_title = $old_title;
        }

        /*
         * 2. Preserve existing actors. If none exist, only match actor terms
         * that already exist in RetroTube and whose full name occurs in title.
         * Never invent an actor from arbitrary words.
         */
        $actors = [];

        if (taxonomy_exists('actors')) {
            $actor_terms = wp_get_post_terms(
                $post_id,
                'actors',
                ['fields' => 'names']
            );

            if (!is_wp_error($actor_terms) && is_array($actor_terms)) {
                $actors = self::sanitize_actor_names(array_map('strval', $actor_terms));
            }

            if (empty($actors)) {
                $actors = self::match_existing_actors_from_title($clean_title);

                if (!empty($actors)) {
                    $actor_set = wp_set_object_terms(
                        $post_id,
                        $actors,
                        'actors',
                        false
                    );

                    if (!is_wp_error($actor_set)) {
                        $result['actors_matched'] = count($actors);
                    } else {
                        $actors = [];
                    }
                }
            }
        }

        /*
         * 3. Do not delete old manual tags. Only generate the standard 2-3
         * navigation tags when the post has no tags yet.
         */
        $existing_tags = wp_get_post_tags($post_id, ['fields' => 'names']);
        $existing_tags = is_array($existing_tags)
            ? array_values(array_filter(array_map('strval', $existing_tags)))
            : [];

        if (empty($existing_tags)) {
            $nav = self::apply_actor_and_tags($post_id, $clean_title, $actors);
            $result['tags_added'] = count((array) ($nav['tags'] ?? []));
        }

        /*
         * 4. Preserve categories; only apply current category rules if a legacy
         * video somehow has no category assignment.
         */
        $category_names = wp_get_post_categories($post_id, ['fields' => 'names']);
        $category_names = is_array($category_names)
            ? array_values(array_filter(array_map('strval', $category_names)))
            : [];

        if (empty($category_names) && !empty($settings['auto_category'])) {
            $category_names = self::apply_categories(
                $post_id,
                $clean_title,
                (string) $settings['category_rules'],
                (string) $settings['default_category']
            );
        }

        /*
         * 5. Thumbnail/R2: never overwrite an existing Featured Image.
         * Queue auto-thumbnail only when the post truly has none.
         */
        $thumbnail_id = (int) get_post_thumbnail_id($post_id);

        if ($thumbnail_id <= 0 && !empty($settings['auto_thumbnail'])) {
            update_post_meta($post_id, '_pltp_thumb_pending', 1);
            update_post_meta($post_id, '_pltp_thumb_attempts', 0);
            delete_post_meta($post_id, '_pltp_thumbnail_error');
            $result['thumb_queued'] = 1;
        } elseif ($thumbnail_id > 0) {
            $image_url = (string) wp_get_attachment_image_url($thumbnail_id, 'full');

            if (!empty($settings['r2_enabled'])) {
                $r2_active = (int) get_post_meta($thumbnail_id, '_pltp_r2_active', true);

                if ($r2_active !== 1) {
                    $offload = self::r2_offload_attachment($thumbnail_id);

                    if (!is_wp_error($offload) && !empty($offload['full_url'])) {
                        $image_url = (string) $offload['full_url'];
                    }
                }

                $result['r2_checked'] = 1;
            }

            if ($image_url !== '') {
                update_post_meta($post_id, 'thumb', esc_url_raw($image_url));
            }
        }

        /*
         * 6. Duration and rotation: queue only missing work. Existing data is
         * preserved and never reset.
         */
        $duration = (int) get_post_meta($post_id, 'duration', true);

        if ($duration <= 0 && !empty($settings['auto_duration'])) {
            update_post_meta($post_id, '_pltp_duration_pending', 1);
            update_post_meta($post_id, '_pltp_duration_attempts', 0);
            delete_post_meta($post_id, '_pltp_duration_error');
            $result['duration_queued'] = 1;
        }

        $thumbs = get_post_meta($post_id, 'thumbs', false);

        if (
            !empty($settings['rotation_enabled'])
            && empty($thumbs)
            && (int) get_post_meta($post_id, '_pltp_rotation_pending', true) !== 1
        ) {
            update_post_meta($post_id, '_pltp_rotation_pending', 1);
            update_post_meta($post_id, '_pltp_rotation_attempts', 0);
            update_post_meta(
                $post_id,
                '_pltp_rotation_count',
                (int) $settings['rotation_count']
            );
            delete_post_meta($post_id, '_pltp_rotation_error');
            $result['rotation_queued'] = 1;
        }

        /*
         * 7. Smart description: only overwrite blank/known auto-managed legacy
         * content. Long/manual editorial descriptions are left intact.
         */
        $plain_content = trim(wp_strip_all_tags((string) $post->post_content));
        $looks_auto = (
            $plain_content === ''
            || (int) get_post_meta($post_id, '_pltp_smart_content_managed', true) === 1
            || stripos($plain_content, 'Tonton video terbaru lainnya di') !== false
            || stripos($plain_content, 'Tonton ') === 0
        );

        if (!empty($settings['smart_content_enabled']) && $looks_auto) {
            if (!metadata_exists('post', $post_id, '_pltp_backfill_old_content_v1917')) {
                add_post_meta(
                    $post_id,
                    '_pltp_backfill_old_content_v1917',
                    (string) $post->post_content,
                    true
                );
                add_post_meta(
                    $post_id,
                    '_pltp_backfill_old_excerpt_v1917',
                    (string) $post->post_excerpt,
                    true
                );
            }

            update_post_meta($post_id, '_pltp_smart_content_managed', 1);
            self::refresh_smart_content($post_id);
            $result['smart_content_refreshed'] = 1;
        }

        /*
         * 8. Rank Math + persistent VideoObject. Existing thumbnail is used;
         * if auto-thumbnail is still pending, a later thumbnail worker refresh
         * will update social image again.
         */
        if (
            !empty($settings['rank_math_enabled'])
            && self::rank_math_active()
        ) {
            $thumbnail_id = (int) get_post_thumbnail_id($post_id);
            $image_url = $thumbnail_id > 0
                ? (string) wp_get_attachment_image_url($thumbnail_id, 'full')
                : (string) get_post_meta($post_id, 'thumb', true);

            self::apply_rank_math(
                $post_id,
                $clean_title,
                $image_url,
                $thumbnail_id,
                $category_names,
                $settings
            );

            self::ensure_rank_math_video_schema($post_id, $video_url);
            $result['rank_math_refreshed'] = 1;
        }

        /*
         * 9. Download Page is owned by PejuangLendir Player & Download.
         * Re-sync its tracking_url when that plugin is active.
         */
        if (function_exists('plpd_sync_tracking_url')) {
            plpd_sync_tracking_url($post_id);
            $result['download_refreshed'] = 1;
        }

        /*
         * 10. Views are never reset. Initialize only when the native RetroTube
         * post_views_count meta does not exist yet.
         */
        if (!metadata_exists('post', $post_id, 'post_views_count')) {
            add_post_meta($post_id, 'post_views_count', 0, true);
            $result['views_initialized'] = 1;
        }

        update_post_meta($post_id, '_pltp_backfill_synced_v1917', time());
        clean_post_cache($post_id);

        return $result;
    }

    private static function match_existing_actors_from_title(string $title): array
    {
        if (!taxonomy_exists('actors') || $title === '') {
            return [];
        }

        static $known = null;

        if ($known === null) {
            $terms = get_terms(
                [
                    'taxonomy' => 'actors',
                    'hide_empty' => false,
                ]
            );

            $known = [];

            if (!is_wp_error($terms) && is_array($terms)) {
                foreach ($terms as $term) {
                    $name = sanitize_text_field((string) $term->name);

                    if ($name !== '') {
                        $known[] = $name;
                    }
                }

                usort(
                    $known,
                    static fn($a, $b) => strlen($b) <=> strlen($a)
                );
            }
        }

        $matches = [];

        foreach ((array) $known as $name) {
            $length = function_exists('mb_strlen')
                ? mb_strlen($name, 'UTF-8')
                : strlen($name);

            if ($length < 3) {
                continue;
            }

            $found = function_exists('mb_stripos')
                ? mb_stripos($title, $name, 0, 'UTF-8')
                : stripos($title, $name);

            if ($found !== false) {
                $matches[] = $name;

                if (count($matches) >= 3) {
                    break;
                }
            }
        }

        return self::sanitize_actor_names($matches);
    }

    private static function is_http_url(string $url): bool
    {
        if ($url === '' || !wp_http_validate_url($url)) {
            return false;
        }

        $scheme = strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true);
    }

    private static function render_content_template(
        string $template,
        string $title,
        string $video_url,
        string $thumbnail_url,
        array $categories
    ): string {
        if (trim($template) === '') {
            return '';
        }

        return wp_kses_post(strtr(
            $template,
            [
                '{title}' => esc_html($title),
                '{video_url}' => esc_url($video_url),
                '{thumbnail_url}' => esc_url($thumbnail_url),
                '{site_name}' => esc_html(get_bloginfo('name')),
                '{categories}' => esc_html(implode(', ', $categories)),
            ]
        ));
    }

    private static function render_plain_template(
        string $template,
        string $title,
        array $categories
    ): string {
        return trim(wp_strip_all_tags(strtr(
            $template,
            [
                '{title}' => $title,
                '{site_name}' => wp_strip_all_tags(get_bloginfo('name')),
                '{categories}' => implode(', ', $categories),
            ]
        )));
    }

    private static function apply_rank_math(
        int $post_id,
        string $title,
        string $image_url,
        int $image_id,
        array $categories,
        array $settings
    ): void {
        $seo_title = self::limit_text(
            self::render_plain_template(
                (string) $settings['seo_title_template'],
                $title,
                $categories
            ),
            70
        );

        if ($seo_title === '') {
            $seo_title = self::limit_text($title, 70);
        }

        if (
            !empty($settings['smart_content_enabled'])
            && (int) get_post_meta(
                $post_id,
                '_pltp_smart_content_managed',
                true
            ) === 1
        ) {
            $seo_description = self::limit_text(
                self::build_smart_description(
                    $post_id,
                    $title,
                    $categories
                ),
                160
            );
        } else {
            $seo_description = self::limit_text(
                self::render_plain_template(
                    (string) $settings['seo_description_template'],
                    $title,
                    $categories
                ),
                160
            );
        }

        if ($seo_description === '') {
            $seo_description = self::limit_text($title, 160);
        }

        $focus_keyword = (
            $settings['focus_keyword_source'] === 'categories'
            && !empty($categories)
        )
            ? implode(', ', $categories)
            : $title;

        update_post_meta($post_id, 'rank_math_title', $seo_title);
        update_post_meta($post_id, 'rank_math_description', $seo_description);
        update_post_meta($post_id, 'rank_math_focus_keyword', $focus_keyword);

        update_post_meta($post_id, 'rank_math_facebook_title', $seo_title);
        update_post_meta($post_id, 'rank_math_facebook_description', $seo_description);

        if ($image_url !== '') {
            update_post_meta($post_id, 'rank_math_facebook_image', esc_url_raw($image_url));
        }

        if ($image_id > 0) {
            update_post_meta($post_id, 'rank_math_facebook_image_id', $image_id);
        }

        /* Canonical is deliberately left to Rank Math's normal self-canonical. */
    }

    /**
     * Persist the VideoObject schema in the exact Rank Math post-meta family
     * used by Rank Math PRO's video sitemap provider.
     *
     * Some Rank Math flows can render VideoObject in public JSON-LD without
     * persisting rank_math_schema_VideoObject. The PRO video sitemap provider,
     * however, discovers posts from that persistent meta key. V1.7.3 ensures
     * both paths are aligned.
     */
    private static function ensure_rank_math_video_schema(
        int $post_id,
        string $video_url
    ): void {
        if ($post_id <= 0 || $video_url === '') {
            return;
        }

        $existing = get_post_meta(
            $post_id,
            'rank_math_schema_VideoObject',
            true
        );

        $seconds = max(
            0,
            (int) get_post_meta($post_id, 'duration', true)
        );

        /*
         * Preserve an existing user/Rank Math schema and only keep the fields
         * that are owned by this publisher synchronized.
         */
        if (is_array($existing) && !empty($existing)) {
            $existing['@type'] = 'VideoObject';
            $existing['contentUrl'] = esc_url_raw($video_url);

            if (
                !isset($existing['metadata'])
                || !is_array($existing['metadata'])
            ) {
                $existing['metadata'] = [];
            }

            $existing['metadata']['duration'] = $seconds;

            update_post_meta(
                $post_id,
                'rank_math_schema_VideoObject',
                $existing
            );

            return;
        }

        $shortcode_id = 's-' . substr(
            md5('pltp-video-' . $post_id),
            0,
            12
        );

        $schema = [
            '@type' => 'VideoObject',

            'metadata' => [
                'title' => 'Video',
                'type' => 'template',
                'shortcode' => $shortcode_id,
                'isPrimary' => true,
                'reviewLocationShortcode' => '[rank_math_rich_snippet]',
                'category' => '%categories%',
                'tags' => '%tags%',
                'isAutoGenerated' => true,
                'duration' => $seconds,
            ],

            'name' => '%seo_title%',
            'description' => '%seo_description%',
            'uploadDate' => '%date(Y-m-dTH:i:sP)%',
            'thumbnailUrl' => '%post_thumbnail%',
            'embedUrl' => '',
            'contentUrl' => esc_url_raw($video_url),

            /*
             * Public duration is injected at render time from UltimaTube's
             * native `duration` meta by the V1.7.x Rank Math filters.
             */
            'duration' => '',

            'width' => '',
            'height' => '',

            /*
             * This project contains adult material, so never advertise the
             * videos as family-friendly.
             */
            'isFamilyFriendly' => false,
        ];

        update_post_meta(
            $post_id,
            'rank_math_schema_VideoObject',
            $schema
        );
    }

    /**
     * Repair existing Telegram/UltimaTube posts once after upgrading to V1.7.3.
     * This includes posts such as the V1.7.2 test posts that have video_url and
     * Rank Math SEO metadata but no persistent VideoObject schema meta.
     */
    public static function maybe_backfill_rank_math_video_schema(): void
    {
        if (get_option('pltp_v173_schema_backfill') === 'done') {
            return;
        }

        if (!self::rank_math_active()) {
            return;
        }

        $ids = get_posts([
            'post_type' => 'post',
            'post_status' => ['publish', 'draft'],
            'posts_per_page' => 500,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
            'meta_key' => 'video_url',
        ]);

        foreach ($ids as $post_id) {
            $video_url = (string) get_post_meta(
                (int) $post_id,
                'video_url',
                true
            );

            if ($video_url === '') {
                continue;
            }

            self::ensure_rank_math_video_schema(
                (int) $post_id,
                $video_url
            );
        }

        self::safe_refresh_rank_math_video_sitemap_cache();

        update_option(
            'pltp_v173_schema_backfill',
            'done',
            false
        );
    }

    /**
     * Safely clear Rank Math video sitemap caches without WP_Filesystem.
     *
     * This avoids the FTPext fatal previously seen from WP-CLI on aaPanel.
     */
    private static function safe_refresh_rank_math_video_sitemap_cache(): void
    {
        global $wpdb;

        $like_transient = $wpdb->esc_like(
            '_transient_sitemap_video_'
        ) . '%';

        $like_timeout = $wpdb->esc_like(
            '_transient_timeout_sitemap_video_'
        ) . '%';

        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options}
                 WHERE option_name LIKE %s
                    OR option_name LIKE %s",
                $like_transient,
                $like_timeout
            )
        );

        /*
         * Rank Math stores the list of file-backed sitemap caches via
         * Helper::option('sitemap_cache_files'). Use that API to avoid
         * guessing the concrete option name, but delete files directly
         * instead of asking WP_Filesystem/FTPext to do it.
         */
        if (class_exists('\\RankMath\\Helper')) {
            $files = \RankMath\Helper::option(
                'sitemap_cache_files'
            );

            if (is_array($files) && !empty($files)) {
                $uploads = wp_upload_dir();
                $dir = trailingslashit(
                    $uploads['basedir']
                ) . 'rank-math/';

                $keep = [];

                foreach ($files as $file => $type) {
                    if ('video' === (string) $type) {
                        $path = $dir . basename(
                            (string) $file
                        );

                        if (is_file($path)) {
                            @unlink($path);
                        }

                        continue;
                    }

                    $keep[$file] = $type;
                }

                \RankMath\Helper::option(
                    'sitemap_cache_files',
                    $keep
                );
            }
        }
    }

    private static function limit_text(string $text, int $limit): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') > $limit
                ? rtrim(mb_substr($text, 0, $limit - 1, 'UTF-8')) . '…'
                : $text;
        }

        return strlen($text) > $limit
            ? rtrim(substr($text, 0, $limit - 3)) . '...'
            : $text;
    }

    private static function rank_math_active(): bool
    {
        return defined('RANK_MATH_VERSION')
            || class_exists('RankMath\\Helper')
            || function_exists('rank_math');
    }

    private static function apply_categories(
        int $post_id,
        string $title,
        string $raw_rules,
        string $default_category
    ): array {
        $matched_ids = [];
        $matched_names = [];
        $haystack = function_exists('mb_strtolower')
            ? mb_strtolower($title, 'UTF-8')
            : strtolower($title);

        $lines = preg_split('/\R/u', trim($raw_rules));
        $lines = is_array($lines) ? $lines : [];

        foreach ($lines as $line) {
            $line = trim((string) $line);

            if ($line === '' || !str_contains($line, '|')) {
                continue;
            }

            [$category_name, $keyword_part] = array_map('trim', explode('|', $line, 2));

            if ($category_name === '' || $keyword_part === '') {
                continue;
            }

            $keywords = array_values(array_filter(array_map('trim', explode(',', $keyword_part))));
            $matched = false;

            foreach ($keywords as $keyword) {
                $needle = function_exists('mb_strtolower')
                    ? mb_strtolower($keyword, 'UTF-8')
                    : strtolower($keyword);

                if ($needle !== '' && str_contains($haystack, $needle)) {
                    $matched = true;
                    break;
                }
            }

            if (!$matched) {
                continue;
            }

            $term_id = self::get_or_create_category($category_name);

            if ($term_id > 0) {
                $matched_ids[] = $term_id;
                $matched_names[] = $category_name;
            }
        }

        if (empty($matched_ids) && trim($default_category) !== '') {
            $term_id = self::get_or_create_category($default_category);

            if ($term_id > 0) {
                $matched_ids[] = $term_id;
                $matched_names[] = $default_category;
            }
        }

        $matched_ids = array_values(array_unique(array_map('intval', $matched_ids)));
        $matched_names = array_values(array_unique(array_map('strval', $matched_names)));

        if (!empty($matched_ids)) {
            wp_set_post_categories($post_id, $matched_ids, false);
        }

        return $matched_names;
    }

    private static function get_or_create_category(string $name): int
    {
        $name = sanitize_text_field($name);

        if ($name === '') {
            return 0;
        }

        $existing = term_exists($name, 'category');

        if (is_array($existing) && !empty($existing['term_id'])) {
            return (int) $existing['term_id'];
        }

        if (is_int($existing)) {
            return $existing;
        }

        $created = wp_insert_term($name, 'category');

        if (is_wp_error($created) || empty($created['term_id'])) {
            return 0;
        }

        return (int) $created['term_id'];
    }

    private static function clear_old_thumbnail_state(int $post_id): void
    {
        delete_post_meta($post_id, '_pltp_thumb_pending');
        delete_post_meta($post_id, '_pltp_thumb_chat_id');
        delete_post_meta($post_id, '_pltp_collage_attachment_id'); // legacy V1.9.1.1-1.9.1.7
        delete_post_meta($post_id, '_pltp_auto_thumbnail_attachment_id');
        delete_post_meta($post_id, '_pltp_thumbnail_error');
    }

    private static function sideload_thumbnail(int $post_id, string $thumbnail_url, string $title)
    {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $tmp = download_url($thumbnail_url, 30);

        if (is_wp_error($tmp)) {
            return new WP_Error('thumb_download_failed', 'Gagal mengunduh thumbnail.');
        }

        $url_path = (string) wp_parse_url($thumbnail_url, PHP_URL_PATH);
        $name = sanitize_file_name(wp_basename($url_path));
        $extension = strtolower((string) pathinfo($name, PATHINFO_EXTENSION));
        $allowed_extensions = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif'];

        if ($name === '' || !in_array($extension, $allowed_extensions, true)) {
            $mime = function_exists('wp_get_image_mime') ? wp_get_image_mime($tmp) : false;
            $map = [
                'image/jpeg' => 'jpg',
                'image/png' => 'png',
                'image/webp' => 'webp',
                'image/gif' => 'gif',
                'image/avif' => 'avif',
            ];
            $extension = isset($map[$mime]) ? $map[$mime] : 'jpg';
            $name = sanitize_title($title) . '-' . $post_id . '.' . $extension;
        }

        $file_array = [
            'name' => $name,
            'tmp_name' => $tmp,
        ];

        $attachment_id = media_handle_sideload($file_array, $post_id, $title);

        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            return $attachment_id;
        }

        set_post_thumbnail($post_id, (int) $attachment_id);
        update_post_meta((int) $attachment_id, '_wp_attachment_image_alt', $title);
        update_post_meta($post_id, '_pltp_thumbnail_source_url', esc_url_raw($thumbnail_url));

        $settings = self::settings();

        if (!empty($settings['r2_enabled'])) {
            $offload = self::r2_offload_attachment((int) $attachment_id);

            if (is_wp_error($offload)) {
                update_post_meta(
                    $post_id,
                    '_pltp_r2_error',
                    $offload->get_error_message()
                );
            } else {
                delete_post_meta($post_id, '_pltp_r2_error');

                if (!empty($offload['full_url'])) {
                    update_post_meta(
                        $post_id,
                        '_pltp_thumbnail_r2_url',
                        esc_url_raw((string) $offload['full_url'])
                    );
                }
            }
        }

        return (int) $attachment_id;
    }

    public static function r2_test(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('pltp_r2_test');

        if (!self::r2_config_ready()) {
            self::redirect_notice('r2_config_missing');
        }

        $tmp = wp_tempnam('pltp-r2-test');

        if (!$tmp) {
            self::redirect_notice('r2_api_error');
        }

        $content = 'PejuangLendir R2 connection test ' . gmdate('c');
        file_put_contents($tmp, $content);

        $key = 'pltp-health/connection-test-' . gmdate('Ymd-His')
            . '-' . wp_generate_password(8, false, false) . '.txt';

        $put = self::r2_put_file($tmp, $key, 'text/plain');
        @unlink($tmp);

        if (is_wp_error($put)) {
            self::redirect_notice('r2_api_error');
        }

        $head = self::r2_request('HEAD', $key);

        if (is_wp_error($head)) {
            self::r2_request('DELETE', $key);
            self::redirect_notice('r2_api_error');
        }

        $public_url = self::r2_public_url($key);

        $response = wp_remote_get(
            add_query_arg('pltp_r2_test', time(), $public_url),
            [
                'timeout' => 20,
                'redirection' => 3,
                'headers' => [
                    'Cache-Control' => 'no-cache',
                ],
            ]
        );

        self::r2_request('DELETE', $key);

        if (
            is_wp_error($response)
            || wp_remote_retrieve_response_code($response) !== 200
        ) {
            self::redirect_notice('r2_public_error');
        }

        self::redirect_notice('r2_ok');
    }

    public static function rotation_r2_test(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('pltp_rotation_r2_test');

        $config = self::rotation_r2_config();

        if (is_wp_error($config)) {
            self::redirect_notice(
                'rotation_r2_config_missing'
            );
        }

        $tmp = wp_tempnam('pltp-rotation-r2-test');

        if (!$tmp) {
            self::redirect_notice(
                'rotation_r2_api_error'
            );
        }

        file_put_contents(
            $tmp,
            'PejuangLendir Rotation R2 test ' . gmdate('c')
        );

        $key = 'rotation-health/test-'
            . gmdate('Ymd-His')
            . '-'
            . wp_generate_password(8, false, false)
            . '.txt';

        $put = self::r2_put_file(
            $tmp,
            $key,
            'text/plain',
            $config
        );

        @unlink($tmp);

        if (is_wp_error($put)) {
            self::redirect_notice(
                'rotation_r2_api_error'
            );
        }

        $head = self::r2_request(
            'HEAD',
            $key,
            '',
            '',
            '',
            $config
        );

        if (is_wp_error($head)) {
            self::r2_request(
                'DELETE',
                $key,
                '',
                '',
                '',
                $config
            );

            self::redirect_notice(
                'rotation_r2_api_error'
            );
        }

        $public_url = self::r2_public_url(
            $key,
            $config
        );

        $response = wp_remote_get(
            add_query_arg(
                'pltp_rotation_test',
                time(),
                $public_url
            ),
            [
                'timeout' => 20,
                'redirection' => 3,
                'headers' => [
                    'Cache-Control' => 'no-cache',
                ],
            ]
        );

        self::r2_request(
            'DELETE',
            $key,
            '',
            '',
            '',
            $config
        );

        if (
            is_wp_error($response)
            || wp_remote_retrieve_response_code($response) !== 200
        ) {
            self::redirect_notice(
                'rotation_r2_public_error'
            );
        }

        self::redirect_notice('rotation_r2_ok');
    }

    private static function rotation_r2_config_ready(): bool
    {
        return !is_wp_error(
            self::rotation_r2_config()
        );
    }

    private static function rotation_r2_public_base_for_display(): string
    {
        $config = self::rotation_r2_config();

        return is_wp_error($config)
            ? ''
            : (string) $config['public_base'];
    }

    private static function rotation_r2_config()
    {
        $main = self::r2_config();

        if (is_wp_error($main)) {
            return $main;
        }

        $settings = self::stored_settings();

        $bucket = trim(
            (string) (
                $settings['rotation_r2_bucket']
                ?? ''
            )
        );

        if ($bucket === '') {
            $bucket = (string) $main['bucket'];
        }

        $public_base = untrailingslashit(
            trim(
                (string) (
                    $settings['rotation_r2_public_base_url']
                    ?? ''
                )
            )
        );

        if ($public_base === '') {
            return new WP_Error(
                'rotation_r2_public_missing',
                'Rotation Public URL belum diisi.'
            );
        }

        if (!wp_http_validate_url($public_base)) {
            return new WP_Error(
                'rotation_r2_public_invalid',
                'Rotation Public URL tidak valid.'
            );
        }

        $main['bucket'] = $bucket;
        $main['public_base'] = $public_base;

        return $main;
    }

    /**
     * Called only from the OS auto-thumbnail worker after the WebP file has
     * been generated. Heavy FFmpeg work never runs inside web PHP.
     */
    public static function auto_thumbnail_worker_commit(
        int $post_id,
        string $file
    ) {
        if ($post_id <= 0 || !is_file($file) || !is_readable($file)) {
            return new WP_Error(
                'auto_thumb_worker_input',
                'Input worker auto thumbnail tidak valid.'
            );
        }

        $post = get_post($post_id);

        if (!$post || !in_array($post->post_status, ['publish', 'draft'], true)) {
            return new WP_Error(
                'auto_thumb_post_missing',
                'Post tidak ditemukan.'
            );
        }

        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';

        $title = get_the_title($post_id);
        $name = sanitize_title($title) . '-' . $post_id . '-auto.webp';

        $file_array = [
            'name' => $name,
            'tmp_name' => $file,
        ];

        $attachment_id = media_handle_sideload(
            $file_array,
            $post_id,
            $title
        );

        if (is_wp_error($attachment_id)) {
            return $attachment_id;
        }

        $attachment_id = (int) $attachment_id;

        set_post_thumbnail($post_id, $attachment_id);
        update_post_meta(
            $attachment_id,
            '_wp_attachment_image_alt',
            $title
        );
        update_post_meta($post_id, '_pltp_thumbnail_auto', 1);
        update_post_meta(
            $post_id,
            '_pltp_auto_thumbnail_attachment_id',
            $attachment_id
        );

        $settings = self::settings();
        $final_image_url = (string) wp_get_attachment_image_url(
            $attachment_id,
            'full'
        );

        if (!empty($settings['r2_enabled'])) {
            $offload = self::r2_offload_attachment($attachment_id);

            if (is_wp_error($offload)) {
                // Safe fallback: keep the local WordPress Featured Image.
                update_post_meta(
                    $post_id,
                    '_pltp_r2_error',
                    $offload->get_error_message()
                );
            } else {
                delete_post_meta($post_id, '_pltp_r2_error');

                if (!empty($offload['full_url'])) {
                    $final_image_url = esc_url_raw(
                        (string) $offload['full_url']
                    );

                    update_post_meta(
                        $post_id,
                        '_pltp_thumbnail_r2_url',
                        $final_image_url
                    );
                }
            }
        }

        if ($final_image_url === '') {
            $final_image_url = (string) wp_get_attachment_url(
                $attachment_id
            );
        }

        if ($final_image_url !== '') {
            // RetroTube fallback/poster meta; Featured Image stays primary.
            update_post_meta(
                $post_id,
                'thumb',
                esc_url_raw($final_image_url)
            );
        }

        $category_names = wp_get_post_categories(
            $post_id,
            ['fields' => 'names']
        );
        $category_names = is_array($category_names)
            ? array_values(array_map('strval', $category_names))
            : [];

        // Refresh template only in legacy/template mode. Smart mode is
        // refreshed by its own metadata-aware helper and duration worker.
        if (
            empty($settings['smart_content_enabled'])
            && $final_image_url !== ''
            && str_contains(
                (string) $settings['description_template'],
                '{thumbnail_url}'
            )
        ) {
            $description = self::render_content_template(
                (string) $settings['description_template'],
                $title,
                (string) get_post_meta($post_id, 'video_url', true),
                $final_image_url,
                $category_names
            );

            if ($description !== '') {
                wp_update_post([
                    'ID' => $post_id,
                    'post_content' => $description,
                    'post_excerpt' => wp_trim_words(
                        wp_strip_all_tags($description),
                        35,
                        ''
                    ),
                ]);
            }
        }

        if (
            !empty($settings['rank_math_enabled'])
            && self::rank_math_active()
        ) {
            self::apply_rank_math(
                $post_id,
                $title,
                $final_image_url,
                $attachment_id,
                $category_names,
                $settings
            );

            self::ensure_rank_math_video_schema(
                $post_id,
                (string) get_post_meta($post_id, 'video_url', true)
            );

            self::safe_refresh_rank_math_video_sitemap_cache();
        }

        delete_post_meta($post_id, '_pltp_thumb_pending');
        delete_post_meta($post_id, '_pltp_thumb_failed');
        delete_post_meta($post_id, '_pltp_thumb_attempts');
        delete_post_meta($post_id, '_pltp_thumbnail_error');
        update_post_meta($post_id, '_pltp_thumb_done', 1);

        clean_post_cache($post_id);

        return [
            'attachment_id' => $attachment_id,
            'url' => $final_image_url,
        ];
    }

    /**
     * Called only from the OS rotation worker after all local WebP frames
     * have been generated successfully.
     */
    public static function rotation_worker_commit(
        int $post_id,
        string $directory,
        int $count
    ) {
        if ($post_id <= 0 || !is_dir($directory)) {
            return new WP_Error(
                'rotation_worker_input',
                'Input worker rotation tidak valid.'
            );
        }

        if (!in_array($count, [4, 5], true)) {
            $count = 5;
        }

        $config = self::rotation_r2_config();

        if (is_wp_error($config)) {
            return $config;
        }

        $post = get_post($post_id);

        if (!$post) {
            return new WP_Error(
                'rotation_post_missing',
                'Post tidak ditemukan.'
            );
        }

        $year = get_post_time(
            'Y',
            true,
            $post_id
        );

        $month = get_post_time(
            'm',
            true,
            $post_id
        );

        if (!$year || !$month) {
            $year = gmdate('Y');
            $month = gmdate('m');
        }

        $uploaded_keys = [];
        $urls = [];

        for ($i = 1; $i <= $count; $i++) {
            $file = trailingslashit($directory)
                . sprintf(
                    'frame-%02d.webp',
                    $i
                );

            if (!is_file($file) || !is_readable($file)) {
                return new WP_Error(
                    'rotation_frame_missing',
                    'Frame rotation belum lengkap.'
                );
            }

            $key = sprintf(
                'rotation/%s/%s/post-%d/frame-%02d.webp',
                $year,
                $month,
                $post_id,
                $i
            );

            $put = self::r2_put_file(
                $file,
                $key,
                'image/webp',
                $config
            );

            if (is_wp_error($put)) {
                foreach ($uploaded_keys as $uploaded_key) {
                    self::r2_request(
                        'DELETE',
                        $uploaded_key,
                        '',
                        '',
                        '',
                        $config
                    );
                }

                return $put;
            }

            $uploaded_keys[] = $key;
            $urls[] = self::r2_public_url(
                $key,
                $config
            );
        }

        /*
         * UltimaTube reads get_post_meta($post_id, 'thumbs', false),
         * therefore each preview image must be a separate repeated meta value.
         */
        delete_post_meta($post_id, 'thumbs');

        foreach ($urls as $url) {
            add_post_meta(
                $post_id,
                'thumbs',
                esc_url_raw($url),
                false
            );
        }

        update_post_meta(
            $post_id,
            '_pltp_rotation_urls',
            $urls
        );

        update_post_meta(
            $post_id,
            '_pltp_rotation_done',
            time()
        );

        delete_post_meta(
            $post_id,
            '_pltp_rotation_pending'
        );

        delete_post_meta(
            $post_id,
            '_pltp_rotation_failed'
        );

        delete_post_meta(
            $post_id,
            '_pltp_rotation_attempts'
        );

        delete_post_meta(
            $post_id,
            '_pltp_rotation_error'
        );

        clean_post_cache($post_id);

        return $urls;
    }

    private static function r2_public_base_for_display(): string
    {
        $config = self::r2_config();

        if (is_wp_error($config)) {
            return '';
        }

        return (string) $config['public_base'];
    }

    private static function r2_config_ready(): bool
    {
        return !is_wp_error(self::r2_config());
    }

    private static function r2_config()
    {
        $stored = self::stored_settings();

        $account_id = trim(
            (string) ($stored['r2_account_id'] ?? '')
        );

        $bucket = trim(
            (string) ($stored['r2_bucket'] ?? '')
        );

        $public_base = untrailingslashit(
            trim(
                (string) ($stored['r2_public_base_url'] ?? '')
            )
        );

        $access_key = self::decrypt_secret(
            (string) ($stored['r2_access_key_enc'] ?? '')
        );

        $secret_key = self::decrypt_secret(
            (string) ($stored['r2_secret_key_enc'] ?? '')
        );

        /*
         * Settings plugin menjadi sumber utama.
         * Constant wp-config.php tetap menjadi fallback agar upgrade
         * dari V1.8.0 tidak memutus koneksi R2.
         */
        if (
            $account_id === ''
            && defined('PLTP_R2_ACCOUNT_ID')
        ) {
            $account_id = trim(
                (string) PLTP_R2_ACCOUNT_ID
            );
        }

        if (
            $access_key === ''
            && defined('PLTP_R2_ACCESS_KEY_ID')
        ) {
            $access_key = trim(
                (string) PLTP_R2_ACCESS_KEY_ID
            );
        }

        if (
            $secret_key === ''
            && defined('PLTP_R2_SECRET_ACCESS_KEY')
        ) {
            $secret_key = trim(
                (string) PLTP_R2_SECRET_ACCESS_KEY
            );
        }

        if (
            $bucket === ''
            && defined('PLTP_R2_BUCKET')
        ) {
            $bucket = trim(
                (string) PLTP_R2_BUCKET
            );
        }

        if (
            $public_base === ''
            && defined('PLTP_R2_PUBLIC_BASE_URL')
        ) {
            $public_base = untrailingslashit(
                trim(
                    (string) PLTP_R2_PUBLIC_BASE_URL
                )
            );
        }

        if (
            $account_id === ''
            || $access_key === ''
            || $secret_key === ''
            || $bucket === ''
            || $public_base === ''
        ) {
            return new WP_Error(
                'r2_config_missing',
                'Konfigurasi R2 belum lengkap.'
            );
        }

        if (!preg_match('/^[a-f0-9]{32}$/i', $account_id)) {
            return new WP_Error(
                'r2_account_invalid',
                'Format Account ID R2 tidak valid.'
            );
        }

        if (!wp_http_validate_url($public_base)) {
            return new WP_Error(
                'r2_public_url_invalid',
                'Public / CDN URL R2 tidak valid.'
            );
        }

        return [
            'account_id' => $account_id,
            'access_key' => $access_key,
            'secret_key' => $secret_key,
            'bucket' => $bucket,
            'public_base' => $public_base,
        ];
    }

    private static function encrypt_secret(string $value): string
    {
        if ($value === '' || !function_exists('openssl_encrypt')) {
            return '';
        }

        try {
            $iv = random_bytes(12);
        } catch (Throwable $e) {
            return '';
        }

        $tag = '';

        $cipher = openssl_encrypt(
            $value,
            'aes-256-gcm',
            self::r2_crypto_key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($cipher === false || $tag === '') {
            return '';
        }

        return 'v1:' . base64_encode(
            $iv . $tag . $cipher
        );
    }

    private static function decrypt_secret(string $stored): string
    {
        if (
            $stored === ''
            || !str_starts_with($stored, 'v1:')
            || !function_exists('openssl_decrypt')
        ) {
            return '';
        }

        $raw = base64_decode(
            substr($stored, 3),
            true
        );

        if ($raw === false || strlen($raw) <= 28) {
            return '';
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);

        $plain = openssl_decrypt(
            $cipher,
            'aes-256-gcm',
            self::r2_crypto_key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        return is_string($plain)
            ? $plain
            : '';
    }

    private static function r2_crypto_key(): string
    {
        return hash(
            'sha256',
            wp_salt('auth')
            . '|'
            . wp_salt('secure_auth')
            . '|pltp-r2-v1',
            true
        );
    }

    private static function r2_offload_attachment(int $attachment_id)
    {
        $config = self::r2_config();

        if (is_wp_error($config)) {
            return $config;
        }

        $relative = (string) get_post_meta(
            $attachment_id,
            '_wp_attached_file',
            true
        );

        if ($relative === '') {
            return new WP_Error(
                'r2_attachment_path_missing',
                'Path attachment lokal tidak ditemukan.'
            );
        }

        $uploads = wp_upload_dir();

        if (!empty($uploads['error'])) {
            return new WP_Error(
                'r2_upload_dir_error',
                'Upload directory WordPress bermasalah.'
            );
        }

        $basedir = trailingslashit((string) $uploads['basedir']);
        $metadata = wp_get_attachment_metadata($attachment_id);
        $metadata = is_array($metadata) ? $metadata : [];

        $directory = dirname($relative);
        $directory = $directory === '.' ? '' : trim($directory, '/');

        $items = [];

        $items['full'] = [
            'relative' => ltrim($relative, '/'),
            'path' => $basedir . ltrim($relative, '/'),
            'width' => isset($metadata['width']) ? (int) $metadata['width'] : 0,
            'height' => isset($metadata['height']) ? (int) $metadata['height'] : 0,
        ];

        if (!empty($metadata['sizes']) && is_array($metadata['sizes'])) {
            foreach ($metadata['sizes'] as $size_name => $size) {
                if (
                    !is_array($size)
                    || empty($size['file'])
                ) {
                    continue;
                }

                $rel = ($directory !== '' ? $directory . '/' : '')
                    . ltrim((string) $size['file'], '/');

                $items[(string) $size_name] = [
                    'relative' => $rel,
                    'path' => $basedir . $rel,
                    'width' => isset($size['width']) ? (int) $size['width'] : 0,
                    'height' => isset($size['height']) ? (int) $size['height'] : 0,
                ];
            }
        }

        $r2_data = [
            'full' => [],
            'sizes' => [],
            'by_file' => [],
        ];

        foreach ($items as $size_name => $item) {
            $local_path = (string) $item['path'];

            if (!is_file($local_path) || !is_readable($local_path)) {
                return new WP_Error(
                    'r2_local_file_missing',
                    'Salah satu file thumbnail lokal tidak ditemukan.'
                );
            }

            $object_key = 'wp-media/' . ltrim(
                (string) $item['relative'],
                '/'
            );

            $type = wp_check_filetype($local_path);
            $mime = !empty($type['type'])
                ? (string) $type['type']
                : 'application/octet-stream';

            $uploaded = self::r2_put_file(
                $local_path,
                $object_key,
                $mime
            );

            if (is_wp_error($uploaded)) {
                return $uploaded;
            }

            $url = self::r2_public_url($object_key);

            $entry = [
                'url' => $url,
                'key' => $object_key,
                'file' => basename((string) $item['relative']),
                'width' => (int) $item['width'],
                'height' => (int) $item['height'],
            ];

            if ($size_name === 'full') {
                $r2_data['full'] = $entry;
            } else {
                $r2_data['sizes'][(string) $size_name] = $entry;
            }

            $r2_data['by_file'][$entry['file']] = $url;
        }

        if (empty($r2_data['full']['url'])) {
            return new WP_Error(
                'r2_full_upload_missing',
                'Original thumbnail tidak berhasil dicopy ke R2.'
            );
        }

        update_post_meta(
            $attachment_id,
            '_pltp_r2_data',
            $r2_data
        );

        update_post_meta(
            $attachment_id,
            '_pltp_r2_active',
            1
        );

        return [
            'full_url' => (string) $r2_data['full']['url'],
            'data' => $r2_data,
        ];
    }

    public static function filter_r2_attachment_url(
        string $url,
        int $attachment_id
    ): string {
        if (!self::r2_runtime_enabled($attachment_id)) {
            return $url;
        }

        $data = get_post_meta(
            $attachment_id,
            '_pltp_r2_data',
            true
        );

        if (
            is_array($data)
            && !empty($data['full']['url'])
        ) {
            return esc_url_raw((string) $data['full']['url']);
        }

        return $url;
    }

    public static function filter_r2_image_downsize(
        $downsize,
        int $attachment_id,
        $size
    ) {
        if ($downsize !== false) {
            return $downsize;
        }

        if (!self::r2_runtime_enabled($attachment_id)) {
            return false;
        }

        $data = get_post_meta(
            $attachment_id,
            '_pltp_r2_data',
            true
        );

        if (!is_array($data) || empty($data['full']['url'])) {
            return false;
        }

        if ($size === 'full') {
            $meta = wp_get_attachment_metadata($attachment_id);
            $meta = is_array($meta) ? $meta : [];

            return [
                (string) $data['full']['url'],
                isset($meta['width']) ? (int) $meta['width'] : 0,
                isset($meta['height']) ? (int) $meta['height'] : 0,
                false,
            ];
        }

        if (
            is_string($size)
            && !empty($data['sizes'][$size]['url'])
        ) {
            $entry = $data['sizes'][$size];

            return [
                (string) $entry['url'],
                (int) ($entry['width'] ?? 0),
                (int) ($entry['height'] ?? 0),
                true,
            ];
        }

        if (is_array($size)) {
            $intermediate = image_get_intermediate_size(
                $attachment_id,
                $size
            );

            if (
                is_array($intermediate)
                && !empty($intermediate['file'])
            ) {
                $file = basename((string) $intermediate['file']);

                if (!empty($data['by_file'][$file])) {
                    return [
                        (string) $data['by_file'][$file],
                        (int) ($intermediate['width'] ?? 0),
                        (int) ($intermediate['height'] ?? 0),
                        true,
                    ];
                }
            }
        }

        return false;
    }

    public static function filter_r2_srcset(
        array $sources,
        array $size_array,
        string $image_src,
        array $image_meta,
        int $attachment_id
    ): array {
        if (!self::r2_runtime_enabled($attachment_id)) {
            return $sources;
        }

        $data = get_post_meta(
            $attachment_id,
            '_pltp_r2_data',
            true
        );

        if (
            !is_array($data)
            || empty($data['by_file'])
            || !is_array($data['by_file'])
        ) {
            return $sources;
        }

        foreach ($sources as &$source) {
            if (empty($source['url'])) {
                continue;
            }

            $path = (string) wp_parse_url(
                (string) $source['url'],
                PHP_URL_PATH
            );

            $file = basename($path);

            if (!empty($data['by_file'][$file])) {
                $source['url'] = (string) $data['by_file'][$file];
            }
        }

        unset($source);

        return $sources;
    }

    private static function r2_runtime_enabled(int $attachment_id): bool
    {
        $settings = self::stored_settings();

        if (empty($settings['r2_enabled'])) {
            return false;
        }

        return (int) get_post_meta(
            $attachment_id,
            '_pltp_r2_active',
            true
        ) === 1;
    }

    private static function r2_put_file(
        string $local_path,
        string $key,
        string $content_type = 'application/octet-stream',
        ?array $config_override = null
    ) {
        if (!is_file($local_path) || !is_readable($local_path)) {
            return new WP_Error(
                'r2_file_unreadable',
                'File lokal tidak dapat dibaca.'
            );
        }

        $payload_hash = hash_file('sha256', $local_path);

        if (!$payload_hash) {
            return new WP_Error(
                'r2_hash_failed',
                'Gagal menghitung SHA256 file.'
            );
        }

        return self::r2_request(
            'PUT',
            $key,
            $local_path,
            $content_type,
            $payload_hash,
            $config_override
        );
    }

    private static function r2_request(
        string $method,
        string $key,
        string $local_path = '',
        string $content_type = '',
        string $payload_hash = '',
        ?array $config_override = null
    ) {
        $config = is_array($config_override)
            ? $config_override
            : self::r2_config();

        if (is_wp_error($config)) {
            return $config;
        }

        if (!function_exists('curl_init')) {
            return new WP_Error(
                'r2_curl_missing',
                'PHP cURL tidak tersedia.'
            );
        }

        $method = strtoupper($method);

        if ($payload_hash === '') {
            $payload_hash = hash('sha256', '');
        }

        $account = (string) $config['account_id'];
        $bucket = (string) $config['bucket'];
        $access_key = (string) $config['access_key'];
        $secret_key = (string) $config['secret_key'];

        $host = $account . '.r2.cloudflarestorage.com';

        $canonical_uri = '/'
            . rawurlencode($bucket)
            . '/'
            . self::r2_encode_key($key);

        $url = 'https://' . $host . $canonical_uri;

        $amz_date = gmdate('Ymd\THis\Z');
        $date_stamp = gmdate('Ymd');

        $canonical_headers = '';
        $signed_headers = [];

        $headers_to_sign = [
            'host' => $host,
            'x-amz-content-sha256' => $payload_hash,
            'x-amz-date' => $amz_date,
        ];

        if ($content_type !== '') {
            $headers_to_sign['content-type'] = $content_type;
        }

        ksort($headers_to_sign);

        foreach ($headers_to_sign as $name => $value) {
            $canonical_headers .= strtolower($name)
                . ':'
                . trim((string) $value)
                . "\n";

            $signed_headers[] = strtolower($name);
        }

        $signed_headers_string = implode(';', $signed_headers);

        $canonical_request = $method . "\n"
            . $canonical_uri . "\n"
            . "\n"
            . $canonical_headers . "\n"
            . $signed_headers_string . "\n"
            . $payload_hash;

        $credential_scope = $date_stamp
            . '/auto/s3/aws4_request';

        $string_to_sign = 'AWS4-HMAC-SHA256' . "\n"
            . $amz_date . "\n"
            . $credential_scope . "\n"
            . hash('sha256', $canonical_request);

        $k_date = hash_hmac(
            'sha256',
            $date_stamp,
            'AWS4' . $secret_key,
            true
        );

        $k_region = hash_hmac(
            'sha256',
            'auto',
            $k_date,
            true
        );

        $k_service = hash_hmac(
            'sha256',
            's3',
            $k_region,
            true
        );

        $k_signing = hash_hmac(
            'sha256',
            'aws4_request',
            $k_service,
            true
        );

        $signature = hash_hmac(
            'sha256',
            $string_to_sign,
            $k_signing
        );

        $authorization = 'AWS4-HMAC-SHA256 Credential='
            . $access_key . '/'
            . $credential_scope
            . ', SignedHeaders='
            . $signed_headers_string
            . ', Signature='
            . $signature;

        $headers = [
            'Authorization: ' . $authorization,
            'Host: ' . $host,
            'x-amz-content-sha256: ' . $payload_hash,
            'x-amz-date: ' . $amz_date,
            'Expect:',
        ];

        if ($content_type !== '') {
            $headers[] = 'Content-Type: ' . $content_type;
        }

        $ch = curl_init($url);

        if (!$ch) {
            return new WP_Error(
                'r2_curl_init_failed',
                'Gagal membuat koneksi cURL.'
            );
        }

        curl_setopt_array(
            $ch,
            [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 90,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => $headers,
            ]
        );

        $handle = null;

        if ($method === 'PUT') {
            $handle = fopen($local_path, 'rb');

            if (!$handle) {
                curl_close($ch);

                return new WP_Error(
                    'r2_file_open_failed',
                    'Gagal membuka file lokal.'
                );
            }

            curl_setopt($ch, CURLOPT_UPLOAD, true);
            curl_setopt($ch, CURLOPT_INFILE, $handle);
            curl_setopt($ch, CURLOPT_INFILESIZE, filesize($local_path));
        } elseif ($method === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'HEAD');
        } else {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        }

        $body = curl_exec($ch);
        $curl_error = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        if (is_resource($handle)) {
            fclose($handle);
        }

        curl_close($ch);

        if ($body === false && $curl_error !== '') {
            return new WP_Error(
                'r2_curl_error',
                'R2 cURL error.'
            );
        }

        $accepted = [
            'PUT' => [200, 201],
            'HEAD' => [200],
            'DELETE' => [200, 204],
        ];

        $valid_codes = $accepted[$method] ?? [200, 204];

        if (!in_array($code, $valid_codes, true)) {
            return new WP_Error(
                'r2_http_error',
                'R2 HTTP ' . $code
            );
        }

        return [
            'code' => $code,
            'body' => is_string($body) ? $body : '',
            'url' => $url,
        ];
    }

    private static function r2_public_url(
        string $key,
        ?array $config_override = null
    ): string {
        $config = is_array($config_override)
            ? $config_override
            : self::r2_config();

        if (is_wp_error($config)) {
            return '';
        }

        return untrailingslashit(
            (string) $config['public_base']
        ) . '/' . self::r2_encode_key($key);
    }

    private static function r2_encode_key(string $key): string
    {
        $segments = array_filter(
            explode('/', str_replace('\\', '/', $key)),
            static fn($segment) => $segment !== ''
        );

        return implode(
            '/',
            array_map(
                static fn($segment) => rawurlencode($segment),
                $segments
            )
        );
    }

    private static function chat_allowed(string $chat_id, string $raw): bool
    {
        if ($chat_id === '') {
            return false;
        }

        $ids = preg_split('/[\s,]+/', trim($raw));

        if (!is_array($ids)) {
            return false;
        }

        $ids = array_values(array_filter(array_map('trim', $ids)));

        return in_array($chat_id, $ids, true);
    }

    private static function is_seen(int $update_id): bool
    {
        if ($update_id <= 0) {
            return false;
        }

        $seen = get_option(self::SEEN, []);

        return is_array($seen) && in_array($update_id, array_map('intval', $seen), true);
    }

    private static function mark_seen(int $update_id): void
    {
        if ($update_id <= 0) {
            return;
        }

        $seen = get_option(self::SEEN, []);
        $seen = is_array($seen) ? $seen : [];

        $seen[] = $update_id;
        $seen = array_values(array_unique(array_map('intval', $seen)));

        if (count($seen) > 300) {
            $seen = array_slice($seen, -300);
        }

        update_option(self::SEEN, $seen, false);
    }

    private static function notify(string $chat_id, string $text, array $settings): void
    {
        if (empty($settings['notify']) || $chat_id === '') {
            return;
        }

        self::telegram_api(
            'sendMessage',
            [
                'chat_id' => $chat_id,
                'text' => $text,
                'disable_web_page_preview' => 'true',
            ],
            $settings
        );
    }

    private static function telegram_api(string $method, array $payload, array $settings)
    {
        $token = trim((string) ($settings['bot_token'] ?? ''));

        if ($token === '') {
            return new WP_Error('missing_token', 'Bot Token kosong.');
        }

        $response = wp_remote_post(
            'https://api.telegram.org/bot' . $token . '/' . $method,
            [
                'timeout' => 20,
                'body' => $payload,
            ]
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        $json = json_decode(wp_remote_retrieve_body($response), true);

        if ($code < 200 || $code >= 300 || !is_array($json) || empty($json['ok'])) {
            return new WP_Error('telegram_error', 'Telegram API error.');
        }

        return $json;
    }

    public static function set_webhook(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('pltp_v11_set_webhook');
        $settings = self::settings();

        if (empty($settings['bot_token'])) {
            self::redirect_notice('token_missing');
        }

        $result = self::telegram_api(
            'setWebhook',
            [
                'url' => rest_url(self::NS . self::ROUTE),
                'secret_token' => (string) $settings['secret'],
                'allowed_updates' => wp_json_encode(['message', 'channel_post']),
                'drop_pending_updates' => 'true',
            ],
            $settings
        );

        self::redirect_notice(is_wp_error($result) ? 'telegram_error' : 'webhook_ok');
    }

    public static function delete_webhook(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized');
        }

        check_admin_referer('pltp_v11_delete_webhook');
        $settings = self::settings();

        if (empty($settings['bot_token'])) {
            self::redirect_notice('token_missing');
        }

        $result = self::telegram_api(
            'deleteWebhook',
            ['drop_pending_updates' => 'true'],
            $settings
        );

        self::redirect_notice(is_wp_error($result) ? 'telegram_error' : 'webhook_deleted');
    }

    private static function redirect_notice(string $notice): void
    {
        wp_safe_redirect(
            add_query_arg(
                [
                    'page' => 'pejuanglendir-telegram-publisher',
                    'pltp_notice' => $notice,
                ],
                admin_url('options-general.php')
            )
        );
        exit;
    }
}
