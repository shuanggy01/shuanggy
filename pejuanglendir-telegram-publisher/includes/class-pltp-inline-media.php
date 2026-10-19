<?php
/**
 * Inline media processing (V1.9.3): builds the main thumbnail and the five
 * rotation frames right after a Telegram post is created, inside the same PHP
 * process (after the HTTP response was flushed), without cron or bin/*.sh.
 *
 * Requires proc_open() and FFmpeg/FFprobe binaries. When any of that is
 * unavailable or a step fails, the post simply stays in the pending queue and
 * the existing OS workers pick it up, exactly like before.
 */
if (!defined('ABSPATH')) {
    exit;
}

final class PLTP_Inline_Media
{
    private const LOCK_FILE = 'pejuanglendir-media-heavy.lock';
    private const FRAME_TIMEOUT = 90;
    private const FRAME_TRIES = 3;

    /** @var int[] */
    private static array $queue = [];
    private static bool $registered = false;

    public static function enabled(): bool
    {
        $settings = get_option('pltp_v11_settings', []);
        $on = !is_array($settings) || !array_key_exists('inline_media', $settings) || !empty($settings['inline_media']);
        return $on && self::can_run();
    }

    public static function can_run(): bool
    {
        return function_exists('proc_open') && self::binary('ffmpeg') !== '' && self::binary('ffprobe') !== '';
    }

    /** Called once the post and its pending flags exist. */
    public static function schedule(int $post_id): void
    {
        if ($post_id <= 0) {
            return;
        }
        self::$queue[$post_id] = $post_id;
        if (!self::$registered) {
            self::$registered = true;
            register_shutdown_function([self::class, 'run_queue']);
        }
    }

    public static function run_queue(): void
    {
        $ids = array_values(self::$queue);
        self::$queue = [];
        if (!$ids) {
            return;
        }
        // Telegram/browser already got the response; keep working in background.
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        @ignore_user_abort(true);
        @set_time_limit(0);

        $lock = @fopen(rtrim(sys_get_temp_dir(), '/\\') . '/' . self::LOCK_FILE, 'c');
        if (!$lock || !flock($lock, LOCK_EX)) {
            self::fallback();
            return;
        }
        try {
            foreach ($ids as $post_id) {
                self::process((int) $post_id);
            }
        } catch (\Throwable $e) {
            self::log('ERROR ' . $e->getMessage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        self::fallback();
    }

    /** Anything still pending is left to the OS workers. */
    private static function fallback(): void
    {
        if (class_exists('PLTP_Auto_Media')) {
            PLTP_Auto_Media::post_ready_fallback();
        }
    }

    public static function process(int $post_id): void
    {
        $video = trim((string) get_post_meta($post_id, 'video_url', true));
        if ($video === '' || !wp_http_validate_url($video)) {
            return;
        }
        $want_thumb = (int) get_post_meta($post_id, '_pltp_thumb_pending', true) === 1;
        $want_rotation = (int) get_post_meta($post_id, '_pltp_rotation_pending', true) === 1;
        if (!$want_thumb && !$want_rotation) {
            return;
        }

        $duration = self::duration($post_id, $video);
        $tmp = trailingslashit(sys_get_temp_dir()) . 'pltp-inline-' . $post_id . '-' . wp_generate_password(6, false, false);
        if (!wp_mkdir_p($tmp)) {
            return;
        }

        try {
            if ($want_thumb) {
                self::make_thumbnail($post_id, $video, $duration, $tmp);
            }
            // Same rule as the worker: rotation waits for the main thumbnail.
            if ($want_rotation && (int) get_post_meta($post_id, '_pltp_thumb_pending', true) !== 1) {
                self::make_rotation($post_id, $video, $duration, $tmp);
            }
        } finally {
            self::rm_dir($tmp);
        }
    }

    private static function make_thumbnail(int $post_id, string $video, float $duration, string $tmp): void
    {
        $out = $tmp . '/auto-thumb.webp';
        if ($duration > 10) {
            $ts = min(10, max(2, $duration * 0.20));
        } else {
            $ts = 2;
        }
        $vf = "scale='min(1280,iw)':-2:flags=lanczos";
        $ok = self::frame($video, $ts, $out, $vf, 82, true);
        if (!$ok) {
            self::mark_attempt($post_id, '_pltp_thumb_pending', '_pltp_thumb_attempts', '_pltp_thumbnail_error', '_pltp_thumb_failed', 'FFmpeg thumbnail gagal');
            return;
        }
        $result = PLTP_Publisher::auto_thumbnail_worker_commit($post_id, $out);
        if (is_wp_error($result)) {
            self::mark_attempt($post_id, '_pltp_thumb_pending', '_pltp_thumb_attempts', '_pltp_thumbnail_error', '_pltp_thumb_failed', 'WordPress/R2 commit gagal: ' . $result->get_error_message());
            return;
        }
        self::log("POST $post_id OK thumbnail=inline");
    }

    private static function make_rotation(int $post_id, string $video, float $duration, string $tmp): void
    {
        if ($duration <= 0.05) {
            self::mark_attempt($post_id, '_pltp_rotation_pending', '_pltp_rotation_attempts', '_pltp_rotation_error', '_pltp_rotation_failed', 'Durasi video gagal dibaca');
            return;
        }
        $dir = $tmp . '/rotation';
        wp_mkdir_p($dir);
        $vf = 'scale=480:270:force_original_aspect_ratio=decrease,pad=480:270:(ow-iw)/2:(oh-ih)/2:black';
        foreach ([10, 30, 50, 70, 90] as $i => $percent) {
            $ts = max(0.05, $duration * $percent / 100);
            $out = sprintf('%s/frame-%02d.webp', $dir, $i + 1);
            $ok = false;
            for ($try = 1; $try <= self::FRAME_TRIES && !$ok; $try++) {
                $ok = self::frame($video, $ts, $out, $vf, 72, true);
            }
            if (!$ok) {
                $ok = self::frame($video, $ts, $out, $vf, 72, false);
            }
            if (!$ok) {
                self::mark_attempt($post_id, '_pltp_rotation_pending', '_pltp_rotation_attempts', '_pltp_rotation_error', '_pltp_rotation_failed', 'FFmpeg frame ' . ($i + 1) . ' gagal');
                return;
            }
        }
        $result = PLTP_Publisher::rotation_worker_commit($post_id, $dir, 5);
        if (is_wp_error($result)) {
            self::mark_attempt($post_id, '_pltp_rotation_pending', '_pltp_rotation_attempts', '_pltp_rotation_error', '_pltp_rotation_failed', 'Upload R2 rotation gagal: ' . $result->get_error_message());
            return;
        }
        self::log("POST $post_id OK rotation=5 frames inline");
    }

    /** Same 3-attempt policy as the shell workers (attempts shared with them). */
    private static function mark_attempt(int $post_id, string $pending, string $attempts, string $error, string $failed, string $message): void
    {
        $n = (int) get_post_meta($post_id, $attempts, true) + 1;
        update_post_meta($post_id, $attempts, $n);
        update_post_meta($post_id, $error, $message . " (attempt $n/3)");
        if ($n >= 3) {
            delete_post_meta($post_id, $pending);
            update_post_meta($post_id, $failed, 1);
        }
        self::log("POST $post_id ERROR $message attempt=$n");
    }

    private static function duration(int $post_id, string $video): float
    {
        $stored = (float) get_post_meta($post_id, 'duration', true);
        if ($stored > 0.05) {
            return $stored;
        }
        $res = self::run([
            self::binary('ffprobe'), '-v', 'error', '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1', $video,
        ], 45);
        if ($res['code'] !== 0) {
            return 0.0;
        }
        $lines = array_filter(array_map('trim', explode("\n", $res['out'])));
        return (float) end($lines);
    }

    private static function frame(string $video, float $ts, string $out, string $vf, int $quality, bool $fast_seek): bool
    {
        @unlink($out);
        $ts = sprintf('%.3f', $ts);
        $cmd = [self::binary('ffmpeg'), '-nostdin', '-hide_banner', '-loglevel', 'error', '-y', '-threads', '1'];
        // Fast seek (-ss before -i) first; accurate seek as fallback.
        $cmd = $fast_seek
            ? array_merge($cmd, ['-ss', $ts, '-i', $video])
            : array_merge($cmd, ['-i', $video, '-ss', $ts]);
        $cmd = array_merge($cmd, ['-frames:v', '1', '-an', '-vf', $vf, '-c:v', 'libwebp', '-quality', (string) $quality, $out]);
        $res = self::run($cmd, $fast_seek ? self::FRAME_TIMEOUT : 120);
        return $res['code'] === 0 && is_file($out) && filesize($out) > 0;
    }

    /** @return array{code:int,out:string} no shell is involved; argv array only. */
    private static function run(array $argv, int $timeout): array
    {
        $proc = @proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($proc)) {
            return ['code' => 1, 'out' => ''];
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $out = '';
        $start = time();
        $code = -1;
        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            stream_get_contents($pipes[2]);
            $status = proc_get_status($proc);
            if (!$status['running']) {
                $code = (int) $status['exitcode'];
                break;
            }
            if (time() - $start > $timeout) {
                proc_terminate($proc, 9);
                $code = 124;
                break;
            }
            usleep(150000);
        }
        $out .= (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);
        return ['code' => $code, 'out' => $out];
    }

    private static function binary(string $name): string
    {
        static $cache = [];
        if (isset($cache[$name])) {
            return $cache[$name];
        }
        $const = 'PLTP_' . strtoupper($name);
        $paths = defined($const) ? [(string) constant($const)] : [];
        $paths = array_merge($paths, ['/usr/bin/' . $name, '/usr/local/bin/' . $name, '/opt/homebrew/bin/' . $name]);
        foreach ($paths as $path) {
            if ($path !== '' && @is_file($path) && @is_executable($path)) {
                return $cache[$name] = $path;
            }
        }
        return $cache[$name] = '';
    }

    private static function rm_dir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            is_dir($path) ? self::rm_dir($path) : @unlink($path);
        }
        @rmdir($dir);
    }

    private static function log(string $line): void
    {
        $file = rtrim(sys_get_temp_dir(), '/\\') . '/pejuanglendir-auto-media.log';
        @file_put_contents($file, '[' . gmdate('Y-m-d H:i:s') . "] INLINE $line\n", FILE_APPEND);
    }
}
