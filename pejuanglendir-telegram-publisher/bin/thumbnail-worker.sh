#!/usr/bin/env bash
set -u

# shellcheck source=common.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/common.sh"

# Performance-safe mode: FFmpeg runs at low CPU / I/O priority.
renice 10 -p $$ >/dev/null 2>&1 || true
ionice -c2 -n7 -p $$ >/dev/null 2>&1 || true


LOCK_FILE="/tmp/pejuanglendir-thumbnail-worker.lock"

exec 9>"$LOCK_FILE"

if command -v flock >/dev/null 2>&1; then
    flock -n 9 || exit 0
fi

# Only one heavy FFmpeg worker at a time. Auto-thumbnail gets priority.
MEDIA_LOCK_FILE="/tmp/pejuanglendir-media-heavy.lock"
exec 8>"$MEDIA_LOCK_FILE"
if command -v flock >/dev/null 2>&1; then
    flock -n 8 || exit 0
fi

log() {
    printf '%s\n' "$*"
}

if [ ! -x "$PHP" ] || [ ! -f "$WP" ] || [ ! -x "$FFMPEG" ] || [ ! -x "$FFPROBE" ]; then
    log "ERROR: PHP/WP-CLI/FFmpeg/FFprobe tidak tersedia"
    exit 1
fi

IDS="$(
    "$PHP" "$WP" post list \
        --post_type=post \
        --post_status=publish,draft \
        --meta_key=_pltp_thumb_pending \
        --meta_value=1 \
        --orderby=ID \
        --order=DESC \
        --format=ids \
        --path="$ROOT" \
        2>/dev/null
)"

[ -n "$IDS" ] || exit 0

PROCESSED=0

for ID in $IDS; do
    PROCESSED=$((PROCESSED + 1))
    [ "$PROCESSED" -le 1 ] || break

    VIDEO_URL="$(
        "$PHP" "$WP" post meta get "$ID" video_url \
            --path="$ROOT" \
            2>/dev/null
    )"

    if [ -z "$VIDEO_URL" ]; then
        "$PHP" "$WP" post meta update "$ID" _pltp_thumbnail_error \
            "video_url kosong" --path="$ROOT" >/dev/null 2>&1
        "$PHP" "$WP" post meta delete "$ID" _pltp_thumb_pending \
            --path="$ROOT" >/dev/null 2>&1
        log "POST $ID ERROR video_url kosong"
        continue
    fi

    ATTEMPTS="$(
        "$PHP" "$WP" post meta get "$ID" _pltp_thumb_attempts \
            --path="$ROOT" 2>/dev/null
    )"

    case "$ATTEMPTS" in
        ''|*[!0-9]*) ATTEMPTS=0 ;;
    esac

    DURATION="$(
        timeout 25s "$FFPROBE" \
            -v error \
            -show_entries format=duration \
            -of default=noprint_wrappers=1:nokey=1 \
            "$VIDEO_URL" 2>/dev/null | tail -n 1
    )"

    TMP="$(mktemp -d "/tmp/pltp-thumb-${ID}-XXXXXX")"
    OUT="$TMP/auto-thumb.webp"
    MADE=0
    MODE="single"

    # V1.9.1.8: always generate ONE main thumbnail frame.
    # Keep the source aspect ratio so portrait videos remain portrait here;
    # RetroTube can crop the card visually, while the Featured Image/poster
    # itself is no longer a 3x3 collage.
    TS="$(awk -v d="$DURATION" 'BEGIN {
        if (d+0 > 10) {
            t=(d*0.20); if (t < 2) t=2; if (t > 10) t=10;
        } else { t=2; }
        printf "%.2f", t;
    }')"

    if timeout 80s "$FFMPEG" \
        -hide_banner -loglevel error -y \
        -threads 1 \
        -ss "$TS" \
        -i "$VIDEO_URL" \
        -frames:v 1 -an \
        -vf "scale='min(1280,iw)':-2:flags=lanczos" \
        -c:v libwebp -quality 82 -compression_level 4 \
        "$OUT" \
        && [ -s "$OUT" ]; then
        MADE=1
    fi

    # Fallback for remote MP4s where input-side seeking is unreliable.
    if [ "$MADE" -ne 1 ]; then
        if timeout 120s "$FFMPEG" \
            -hide_banner -loglevel error -y \
            -threads 1 \
            -i "$VIDEO_URL" \
            -ss "$TS" \
            -frames:v 1 -an \
            -vf "scale='min(1280,iw)':-2:flags=lanczos" \
            -c:v libwebp -quality 82 -compression_level 4 \
            "$OUT" \
            && [ -s "$OUT" ]; then
            MADE=1
        fi
    fi

    if [ "$MADE" -ne 1 ]; then
        rm -rf "$TMP"
        ATTEMPTS=$((ATTEMPTS + 1))

        "$PHP" "$WP" post meta update "$ID" _pltp_thumb_attempts "$ATTEMPTS" \
            --path="$ROOT" >/dev/null 2>&1
        "$PHP" "$WP" post meta update "$ID" _pltp_thumbnail_error \
            "FFmpeg thumbnail gagal (attempt $ATTEMPTS/3)" \
            --path="$ROOT" >/dev/null 2>&1

        if [ "$ATTEMPTS" -ge 3 ]; then
            "$PHP" "$WP" post meta delete "$ID" _pltp_thumb_pending \
                --path="$ROOT" >/dev/null 2>&1
            "$PHP" "$WP" post meta update "$ID" _pltp_thumb_failed 1 \
                --path="$ROOT" >/dev/null 2>&1
        fi

        log "POST $ID ERROR ffmpeg attempt=$ATTEMPTS"
        continue
    fi

    if ! PLTP_THUMB_POST_ID="$ID" PLTP_THUMB_FILE="$OUT" \
        "$PHP" "$WP" eval '
            $id = (int) getenv("PLTP_THUMB_POST_ID");
            $file = (string) getenv("PLTP_THUMB_FILE");

            $result = PLTP_Publisher::auto_thumbnail_worker_commit(
                $id,
                $file
            );

            if (is_wp_error($result)) {
                fwrite(STDERR, $result->get_error_message() . PHP_EOL);
                exit(3);
            }

            echo (string) ($result["url"] ?? "");
        ' \
        --path="$ROOT" \
        >/dev/null 2>&1
    then
        # media_handle_sideload may move OUT; that is expected on success only.
        rm -rf "$TMP"
        ATTEMPTS=$((ATTEMPTS + 1))

        "$PHP" "$WP" post meta update "$ID" _pltp_thumb_attempts "$ATTEMPTS" \
            --path="$ROOT" >/dev/null 2>&1
        "$PHP" "$WP" post meta update "$ID" _pltp_thumbnail_error \
            "WordPress/R2 commit gagal (attempt $ATTEMPTS/3)" \
            --path="$ROOT" >/dev/null 2>&1

        if [ "$ATTEMPTS" -ge 3 ]; then
            "$PHP" "$WP" post meta delete "$ID" _pltp_thumb_pending \
                --path="$ROOT" >/dev/null 2>&1
            "$PHP" "$WP" post meta update "$ID" _pltp_thumb_failed 1 \
                --path="$ROOT" >/dev/null 2>&1
        fi

        log "POST $ID ERROR commit attempt=$ATTEMPTS"
        continue
    fi

    rm -rf "$TMP"
    log "POST $ID OK thumbnail=$MODE"
done
