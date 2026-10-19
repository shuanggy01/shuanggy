<?php
/**
 * Non-invasive auto media runner + resumable legacy thumbnail repair.
 * Additive companion to the stable PLTP_Publisher 1.9.1 baseline.
 */
if (!defined('ABSPATH')) {
    exit;
}

final class PLTP_Auto_Media
{
    private const CRON_HOOK = 'pltp_media_auto_tick_v19111';
    private const REPAIR_OPT = 'pltp_media_repair_v19111';
    private const LAST_RUN_OPT = 'pltp_media_last_launch_v19111';
    private const MIGRATED_OPT = 'pltp_rotation_default_on_v192';
    private const SCAN_BATCH = 120;

    public static function boot(): void
    {
        add_filter('cron_schedules', [self::class, 'cron_schedules']);
        add_action('init', [self::class, 'migrate_rotation_default'], 5);
        add_action('init', [self::class, 'ensure_schedule'], 40);
        add_action(self::CRON_HOOK, [self::class, 'tick']);
        add_action('pltp_after_admin_panel', [self::class, 'render_admin']);
        add_action('admin_post_pltp_media_repair_start', [self::class, 'start_repair']);
        add_action('admin_post_pltp_media_repair_stop', [self::class, 'stop_repair']);
        add_action('admin_post_pltp_media_run_once', [self::class, 'run_once']);
        add_action('admin_post_pltp_media_retry_failed', [self::class, 'retry_failed']);
    }

    public static function cron_schedules(array $schedules): array
    {
        $schedules['pltp_each_minute'] = [
            'interval' => 60,
            'display' => 'PejuangLendir: setiap menit',
        ];
        return $schedules;
    }

    public static function activate(): void
    {
        self::migrate_rotation_default();
        self::ensure_schedule();
    }

    /**
     * V1.9.2: Thumbnail Rotation used to default to OFF, so new posts never got
     * the five `thumbs` frames. Switch it on once on upgrade; an admin can still
     * turn it off afterwards (the flag keeps us from re-enabling it).
     */
    public static function migrate_rotation_default(): void
    {
        if (get_option(self::MIGRATED_OPT)) {
            return;
        }
        $settings = get_option('pltp_v11_settings', []);
        if (is_array($settings) && empty($settings['rotation_enabled'])) {
            $settings['rotation_enabled'] = 1;
            update_option('pltp_v11_settings', $settings, false);
        }
        update_option(self::MIGRATED_OPT, time(), false);
    }

    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function ensure_schedule(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 30, 'pltp_each_minute', self::CRON_HOOK);
        }
    }

    /** New Telegram post: metadata has already been saved by the publisher. */
    public static function post_ready(int $post_id): void
    {
        if ($post_id <= 0) {
            return;
        }
        // Recurring WP-Cron is the fallback if process functions are disabled.
        self::ensure_schedule();
        // Spawn immediately: the Telegram webhook never waits for FFmpeg.
        self::launch_if_queued();
    }

    /** Background, low-resource polling and limited incremental repair scan. */
    public static function tick(): void
    {
        self::repair_scan_batch();
        self::launch_if_queued();
    }

    private static function has_pending_work(): bool
    {
        global $wpdb;
        $found = $wpdb->get_var(
            "SELECT meta_id FROM {$wpdb->postmeta}
             WHERE meta_key IN ('_pltp_thumb_pending','_pltp_rotation_pending')
               AND meta_value = '1'
             LIMIT 1"
        );
        return !empty($found);
    }

    private static function launch_if_queued(): bool
    {
        if (!self::has_pending_work()) {
            return false;
        }
        if (get_transient('pltp_auto_media_launch_cooldown')) {
            return false;
        }
        set_transient('pltp_auto_media_launch_cooldown', 1, 20);

        $script = dirname(__DIR__) . '/bin/auto-media-worker.sh';
        if (!is_file($script) || !is_readable($script)) {
            update_option(self::LAST_RUN_OPT, [
                'time' => time(), 'status' => 'File auto-media-worker.sh tidak ditemukan',
            ], false);
            delete_transient('pltp_auto_media_launch_cooldown');
            return false;
        }

        $log = rtrim(sys_get_temp_dir(), '/\\') . '/pejuanglendir-auto-media.log';
        // Escape every dynamic shell argument. No user-provided shell strings.
        $command = 'nohup /bin/bash ' . escapeshellarg($script)
            . ' >> ' . escapeshellarg($log)
            . ' 2>&1 < /dev/null &';

        $launched = false;
        $method = 'unavailable';
        if (function_exists('exec')) {
            $output = [];
            $status = 1;
            @exec($command, $output, $status);
            $launched = ($status === 0);
            $method = 'exec';
        } elseif (function_exists('proc_open')) {
            $descriptors = [
                0 => ['file', '/dev/null', 'r'],
                1 => ['file', '/dev/null', 'a'],
                2 => ['file', '/dev/null', 'a'],
            ];
            $pipes = [];
            $proc = @proc_open('/bin/sh -c ' . escapeshellarg($command), $descriptors, $pipes);
            if (is_resource($proc)) {
                $launched = (@proc_close($proc) === 0);
            }
            $method = 'proc_open';
        }

        update_option(self::LAST_RUN_OPT, [
            'time' => time(),
            'status' => $launched ? 'Worker dipanggil' : 'Eksekusi shell tidak tersedia/gagal',
            'method' => $method,
        ], false);
        if (!$launched) {
            delete_transient('pltp_auto_media_launch_cooldown');
        }
        return $launched;
    }

    private static function state(): array
    {
        $state = get_option(self::REPAIR_OPT, []);
        return is_array($state) ? $state : [];
    }

    private static function save_state(array $state): void
    {
        update_option(self::REPAIR_OPT, $state, false);
    }

    public static function start_repair(): void
    {
        self::check_admin('pltp_media_repair_start');
        global $wpdb;
        // Snapshot avoids chasing newly-created posts indefinitely.
        $max_id = (int) $wpdb->get_var("SELECT MAX(ID) FROM {$wpdb->posts} WHERE post_type='post'");
        $total = (int) $wpdb->get_var(
            "SELECT COUNT(ID) FROM {$wpdb->posts}
             WHERE post_type='post' AND post_status IN ('publish','draft') AND ID <= " . $max_id
        );
        self::save_state([
            'status' => 'scanning',
            'started_at' => time(),
            'updated_at' => time(),
            'max_id' => $max_id,
            'cursor' => 0,
            'total' => $total,
            'scanned' => 0,
            'thumb_queued' => 0,
            'rotation_queued' => 0,
            'skipped_no_video' => 0,
            'done' => 0,
        ]);
        self::repair_scan_batch();
        self::launch_if_queued();
        self::redirect('repair_started');
    }

    public static function stop_repair(): void
    {
        self::check_admin('pltp_media_repair_stop');
        $state = self::state();
        if (($state['status'] ?? '') === 'scanning') {
            $state['status'] = 'stopped';
            $state['updated_at'] = time();
            self::save_state($state);
        }
        // Existing worker queue is intentionally NOT deleted.
        self::redirect('repair_stopped');
    }

    public static function run_once(): void
    {
        self::check_admin('pltp_media_run_once');
        self::tick();
        self::redirect('worker_checked');
    }

    /** Re-queue posts whose worker gave up after 3 attempts. */
    public static function retry_failed(): void
    {
        self::check_admin('pltp_media_retry_failed');
        global $wpdb;
        $map = [
            '_pltp_rotation_failed' => ['_pltp_rotation_pending', '_pltp_rotation_attempts', '_pltp_rotation_error'],
            '_pltp_thumb_failed' => ['_pltp_thumb_pending', '_pltp_thumb_attempts', '_pltp_thumbnail_error'],
        ];
        foreach ($map as $failed_key => [$pending, $attempts, $error]) {
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = '1' LIMIT 500",
                $failed_key
            ));
            foreach (array_map('intval', $ids) as $post_id) {
                update_post_meta($post_id, $pending, 1);
                update_post_meta($post_id, $attempts, 0);
                delete_post_meta($post_id, $error);
                delete_post_meta($post_id, $failed_key);
                if ($pending === '_pltp_rotation_pending') {
                    update_post_meta($post_id, '_pltp_rotation_count', 5);
                }
            }
        }
        self::launch_if_queued();
        self::redirect('retry_started');
    }

    private static function check_admin(string $action): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Tidak memiliki izin untuk menjalankan fitur ini.');
        }
        check_admin_referer($action);
    }

    private static function redirect(string $notice): void
    {
        wp_safe_redirect(add_query_arg(
            ['page' => 'pejuanglendir-telegram-publisher', 'pltp_media_notice' => $notice],
            admin_url('options-general.php')
        ));
        exit;
    }

    private static function repair_scan_batch(): void
    {
        global $wpdb;
        $state = self::state();
        if (($state['status'] ?? '') !== 'scanning') {
            return;
        }
        $cursor = max(0, (int) ($state['cursor'] ?? 0));
        $max_id = max(0, (int) ($state['max_id'] ?? 0));
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type='post' AND post_status IN ('publish','draft')
               AND ID > %d AND ID <= %d
             ORDER BY ID ASC LIMIT %d",
            $cursor, $max_id, self::SCAN_BATCH
        ));
        if (!$ids) {
            $state['status'] = 'queued';
            $state['updated_at'] = time();
            $state['finished_at'] = time();
            self::save_state($state);
            return;
        }

        $ids = array_map('intval', $ids);
        update_meta_cache('post', $ids);
        foreach ($ids as $post_id) {
            $state['cursor'] = $post_id;
            $state['scanned']++;
            $video_url = trim((string) get_post_meta($post_id, 'video_url', true));
            if (!$video_url) {
                $state['skipped_no_video']++;
                continue;
            }
            $queue_something = false;
            $thumbnail_id = (int) get_post_meta($post_id, '_thumbnail_id', true);
            $thumb_url = trim((string) get_post_meta($post_id, 'thumb', true));
            $r2_thumb = trim((string) get_post_meta($post_id, '_pltp_thumbnail_r2_url', true));
            if ($thumbnail_id <= 0 && $thumb_url === '' && $r2_thumb === '') {
                if ((int) get_post_meta($post_id, '_pltp_thumb_pending', true) !== 1) {
                    update_post_meta($post_id, '_pltp_thumb_pending', 1);
                    update_post_meta($post_id, '_pltp_thumb_attempts', 0);
                    delete_post_meta($post_id, '_pltp_thumbnail_error');
                    delete_post_meta($post_id, '_pltp_thumb_failed');
                    $state['thumb_queued']++;
                }
                $queue_something = true;
            }

            // RetroTube needs five separate `thumbs` meta values.
            $rotation = get_post_meta($post_id, 'thumbs', false);
            $valid_rotation = 0;
            foreach ($rotation as $image) {
                if (is_string($image) && filter_var($image, FILTER_VALIDATE_URL)) {
                    $valid_rotation++;
                }
            }
            if ($valid_rotation < 5) {
                if ((int) get_post_meta($post_id, '_pltp_rotation_pending', true) !== 1) {
                    update_post_meta($post_id, '_pltp_rotation_pending', 1);
                    update_post_meta($post_id, '_pltp_rotation_attempts', 0);
                    update_post_meta($post_id, '_pltp_rotation_count', 5);
                    delete_post_meta($post_id, '_pltp_rotation_error');
                    delete_post_meta($post_id, '_pltp_rotation_failed');
                    $state['rotation_queued']++;
                }
                $queue_something = true;
            }
            if (!$queue_something) {
                $state['done']++;
            }
        }
        $state['updated_at'] = time();
        if (count($ids) < self::SCAN_BATCH || (int) $state['cursor'] >= $max_id) {
            $state['status'] = 'queued';
            $state['finished_at'] = time();
        }
        self::save_state($state);
    }

    public static function render_admin(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }
        $state = self::state();
        $last = get_option(self::LAST_RUN_OPT, []);
        $last = is_array($last) ? $last : [];
        $notice = sanitize_key((string) ($_GET['pltp_media_notice'] ?? ''));
        $notices = [
            'repair_started' => 'Pemindaian thumbnail lama dimulai. Postingan lama yang belum lengkap akan diantrikan otomatis.',
            'repair_stopped' => 'Pemindaian dihentikan. Antrean yang sudah terbentuk tetap bisa diproses.',
            'worker_checked' => 'Pemeriksaan antrean telah dijalankan.',
            'retry_started' => 'Postingan yang gagal diproses diantrekan ulang.',
        ];
        global $wpdb;
        $pending_thumb = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_pltp_thumb_pending' AND meta_value='1'");
        $pending_rotation = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_pltp_rotation_pending' AND meta_value='1'");
        $failed_rotation = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_pltp_rotation_failed' AND meta_value='1'");
        $failed_thumb = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key='_pltp_thumb_failed' AND meta_value='1'");
        $publisher_settings = get_option('pltp_v11_settings', []);
        $rotation_active = is_array($publisher_settings) && !empty($publisher_settings['rotation_enabled']);
        $log_path = rtrim(sys_get_temp_dir(), '/\\') . '/pejuanglendir-auto-media.log';
        $log_tail = '';
        if (is_readable($log_path)) {
            $file_size = @filesize($log_path);
            $file_size = is_int($file_size) ? $file_size : 0;
            $handle = @fopen($log_path, 'rb');
            if ($handle) {
                if ($file_size > 5000) {
                    @fseek($handle, -5000, SEEK_END);
                }
                $log_tail = (string) stream_get_contents($handle);
                fclose($handle);
                $lines = preg_split('/\r?\n/', trim($log_tail));
                $log_tail = implode("\n", array_slice(is_array($lines) ? $lines : [], -10));
            }
        }
        ?>
        <hr>
        <section id="pltp-auto-media" style="max-width:850px;padding:14px;border:1px solid #c3c4c7;background:#fff;border-radius:8px;">
            <h2>🖼 Auto Thumbnail + Repair Thumbnail Lama (V1.9.2)</h2>
            <p>Posting Telegram baru langsung mengantrekan <strong>thumbnail utama dan lima frame rotasi</strong> ke worker background. Proses tidak memperlambat posting Telegram. Jika gambar sudah lengkap, tidak akan ditimpa.</p>
            <?php if (isset($notices[$notice])): ?>
                <div class="notice notice-success inline"><p><?php echo esc_html($notices[$notice]); ?></p></div>
            <?php endif; ?>
            <?php if (!$rotation_active): ?>
                <div class="notice notice-warning inline"><p><strong>Rotasi OFF:</strong> aktifkan <em>Rotation Thumbnail</em> pada pengaturan Telegram Publisher untuk otomatis membuat lima frame pada posting berikutnya. Tombol repair lama tetap bisa mengantrekan rotasi.</p></div>
            <?php endif; ?>
            <p><strong>Antrean saat ini:</strong> Thumbnail utama: <strong><?php echo number_format_i18n($pending_thumb); ?></strong> | Rotasi: <strong><?php echo number_format_i18n($pending_rotation); ?></strong></p>
            <?php if ($failed_rotation || $failed_thumb): ?>
                <p style="color:#b32d2e;"><strong>Gagal (menyerah setelah 3 percobaan):</strong>
                    Thumbnail utama <?php echo number_format_i18n($failed_thumb); ?> | Rotasi <?php echo number_format_i18n($failed_rotation); ?>
                    — cek log di bawah (biasanya URL MP4 tidak bisa dibaca atau kredensial R2 salah), lalu tekan <em>Ulangi yang Gagal</em>.</p>
            <?php endif; ?>
            <p><strong>Pemindaian lama:</strong> <?php echo esc_html((string) ($state['status'] ?? 'belum dimulai')); ?>
                <?php if (!empty($state['total'])): ?>
                — diperiksa <?php echo number_format_i18n((int) ($state['scanned'] ?? 0)); ?> / <?php echo number_format_i18n((int) $state['total']); ?>,
                antre thumbnail <?php echo number_format_i18n((int) ($state['thumb_queued'] ?? 0)); ?>,
                antre rotasi <?php echo number_format_i18n((int) ($state['rotation_queued'] ?? 0)); ?>,
                sudah lengkap <?php echo number_format_i18n((int) ($state['done'] ?? 0)); ?>
                <?php endif; ?>
            </p>
            <p><strong>Pemicu worker terakhir:</strong>
                <?php echo esc_html((string) ($last['status'] ?? 'belum dijalankan')); ?>
                <?php if (!empty($last['time'])): ?>
                    (<?php echo esc_html(wp_date('d M Y H:i:s', (int) $last['time'])); ?>)
                <?php endif; ?>
            </p>
            <p><strong>WP-Cron:</strong> <?php echo wp_next_scheduled(self::CRON_HOOK) ? 'Terjadwal setiap menit' : 'Belum terjadwal'; ?><?php if (defined('DISABLE_WP_CRON') && DISABLE_WP_CRON): ?> — <strong>DISABLE_WP_CRON aktif; butuh server cron eksternal untuk jadwal berkala.</strong><?php endif; ?></p>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin:12px 0;">
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                    <input type="hidden" name="action" value="pltp_media_repair_start">
                    <?php wp_nonce_field('pltp_media_repair_start'); ?>
                    <?php submit_button('🔧 Repair Thumbnail Lama', 'primary', 'submit', false); ?>
                </form>
                <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                    <input type="hidden" name="action" value="pltp_media_run_once">
                    <?php wp_nonce_field('pltp_media_run_once'); ?>
                    <?php submit_button('▶ Periksa Antrean Sekarang', 'secondary', 'submit', false); ?>
                </form>
                <?php if ($failed_rotation || $failed_thumb): ?>
                    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                        <input type="hidden" name="action" value="pltp_media_retry_failed">
                        <?php wp_nonce_field('pltp_media_retry_failed'); ?>
                        <?php submit_button('↻ Ulangi yang Gagal', 'secondary', 'submit', false); ?>
                    </form>
                <?php endif; ?>
                <?php if (($state['status'] ?? '') === 'scanning'): ?>
                    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
                        <input type="hidden" name="action" value="pltp_media_repair_stop">
                        <?php wp_nonce_field('pltp_media_repair_stop'); ?>
                        <?php submit_button('Hentikan Pemindaian', 'secondary', 'submit', false); ?>
                    </form>
                <?php endif; ?>
            </div>
            <?php if ($log_tail !== ''): ?>
                <details style="margin:10px 0;"><summary><strong>10 baris log worker terbaru</strong></summary>
                    <pre style="max-height:240px;overflow:auto;background:#f6f7f7;padding:10px;white-space:pre-wrap;"><?php echo esc_html($log_tail); ?></pre>
                </details>
            <?php endif; ?>
            <p class="description">Repair hanya mengantrekan pekerjaan yang belum lengkap. Worker memproses media satu per satu dengan lock untuk mencegah proses FFmpeg bertabrakan. Refresh halaman untuk melihat perkembangan. Thumbnail yang link-nya rusak tetapi masih tercatat di database tidak dapat dideteksi tanpa pemeriksaan URL terpisah.</p>
        </section>
        <?php
    }
}
