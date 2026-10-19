#!/usr/bin/env bash
set -u

# shellcheck source=common.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/common.sh"

# V1.9.1.10 - Portrait-safe Rotation Frame Patch
# - Prioritizes newest pending posts.
# - A post waiting for auto-thumbnail no longer blocks the next candidate.
# - Adds visible per-frame progress logs.
# - Uses longer FFmpeg timeouts + per-frame retry for remote CDN MP4s.
# - Preserves the performance-safe rule: max 1 rotation video per run.

renice 10 -p $$ >/dev/null 2>&1 || true
ionice -c2 -n7 -p $$ >/dev/null 2>&1 || true


LOCK_FILE="/tmp/pejuanglendir-rotation-worker.lock"
MEDIA_LOCK_FILE="/tmp/pejuanglendir-media-heavy.lock"

FRAME_TIMEOUT=90
FALLBACK_TIMEOUT=120
FRAME_TRIES=3

exec 9>"$LOCK_FILE"
if command -v flock >/dev/null 2>&1; then
    flock -n 9 || exit 0
fi

# Serialize heavy media work. Give auto-thumbnail a chance to grab the lock first.
sleep 5
exec 8>"$MEDIA_LOCK_FILE"
if command -v flock >/dev/null 2>&1; then
    flock -n 8 || exit 0
fi

log() {
    printf '%s\n' "$*"
}

if \
    [ ! -x "$PHP" ] \
    || [ ! -f "$WP" ] \
    || [ ! -x "$FFMPEG" ] \
    || [ ! -x "$FFPROBE" ]
then
    log "ERROR: PHP/WP-CLI/FFmpeg/FFprobe tidak tersedia"
    exit 1
fi

IDS="$(
    "$PHP" "$WP" post list \
        --post_type=post \
        --post_status=publish,draft \
        --meta_key=_pltp_rotation_pending \
        --meta_value=1 \
        --orderby=ID \
        --order=DESC \
        --format=ids \
        --path="$ROOT" \
        2>/dev/null
)"

[ -n "$IDS" ] || exit 0

PROCESSED=0

extract_fast() {
    local ts="$1"
    local url="$2"
    local out="$3"

    timeout "$FRAME_TIMEOUT"s "$FFMPEG" \
        -nostdin \
        -hide_banner \
        -loglevel error \
        -y \
        -threads 1 \
        -ss "$ts" \
        -i "$url" \
        -frames:v 1 \
        -an \
        -vf "scale=480:270:force_original_aspect_ratio=decrease,pad=480:270:(ow-iw)/2:(oh-ih)/2:black" \
        -c:v libwebp \
        -quality 72 \
        "$out"
}

extract_fallback() {
    local ts="$1"
    local url="$2"
    local out="$3"

    timeout "$FALLBACK_TIMEOUT"s "$FFMPEG" \
        -nostdin \
        -hide_banner \
        -loglevel error \
        -y \
        -threads 1 \
        -i "$url" \
        -ss "$ts" \
        -frames:v 1 \
        -an \
        -vf "scale=480:270:force_original_aspect_ratio=decrease,pad=480:270:(ow-iw)/2:(oh-ih)/2:black" \
        -c:v libwebp \
        -quality 72 \
        "$out"
}

for ID in $IDS; do
    # Do not let an older post waiting for auto-thumbnail consume the one-video quota.
    THUMB_PENDING="$(
        "$PHP" "$WP" post meta get "$ID" _pltp_thumb_pending \
            --path="$ROOT" \
            2>/dev/null
    )"

    if [ "$THUMB_PENDING" = "1" ]; then
        log "POST $ID WAIT auto-thumbnail"
        continue
    fi

    # Performance-safe mode: max 1 actual rotation video per run.
    PROCESSED=$((PROCESSED + 1))
    [ "$PROCESSED" -le 1 ] || break

    VIDEO_URL="$(
        "$PHP" "$WP" post meta get "$ID" video_url \
            --path="$ROOT" \
            2>/dev/null
    )"

    if [ -z "$VIDEO_URL" ]; then
        "$PHP" "$WP" post meta update "$ID" _pltp_rotation_error \
            "video_url kosong" \
            --path="$ROOT" >/dev/null 2>&1

        "$PHP" "$WP" post meta delete "$ID" _pltp_rotation_pending \
            --path="$ROOT" >/dev/null 2>&1

        log "POST $ID ERROR video_url kosong"
        continue
    fi

    COUNT="$(
        "$PHP" "$WP" post meta get "$ID" _pltp_rotation_count \
            --path="$ROOT" \
            2>/dev/null
    )"

    case "$COUNT" in
        4|5) ;;
        *) COUNT=5 ;;
    esac

    ATTEMPTS="$(
        "$PHP" "$WP" post meta get "$ID" _pltp_rotation_attempts \
            --path="$ROOT" \
            2>/dev/null
    )"

    case "$ATTEMPTS" in
        ''|*[!0-9]*) ATTEMPTS=0 ;;
    esac

    DURATION="$(
        "$PHP" "$WP" post meta get "$ID" duration \
            --path="$ROOT" \
            2>/dev/null
    )"

    if ! awk -v d="$DURATION" 'BEGIN { exit !(d+0 > 0.05) }'; then
        DURATION="$(
            timeout 45s "$FFPROBE" \
                -v error \
                -show_entries format=duration \
                -of default=noprint_wrappers=1:nokey=1 \
                "$VIDEO_URL" \
                2>/dev/null \
            | tail -n 1
        )"
    fi

    if ! awk -v d="$DURATION" 'BEGIN { exit !(d+0 > 0.05) }'; then
        ATTEMPTS=$((ATTEMPTS + 1))

        "$PHP" "$WP" post meta update "$ID" _pltp_rotation_attempts "$ATTEMPTS" \
            --path="$ROOT" >/dev/null 2>&1

        "$PHP" "$WP" post meta update "$ID" _pltp_rotation_error \
            "Durasi video gagal dibaca (attempt $ATTEMPTS/3)" \
            --path="$ROOT" >/dev/null 2>&1

        if [ "$ATTEMPTS" -ge 3 ]; then
            "$PHP" "$WP" post meta delete "$ID" _pltp_rotation_pending \
                --path="$ROOT" >/dev/null 2>&1
            "$PHP" "$WP" post meta update "$ID" _pltp_rotation_failed 1 \
                --path="$ROOT" >/dev/null 2>&1
        fi

        log "POST $ID ERROR duration attempt=$ATTEMPTS"
        continue
    fi

    TMP="/tmp/pltp-rotation-${ID}-$$"
    rm -rf "$TMP"
    mkdir -p "$TMP"

    if [ "$COUNT" -eq 4 ]; then
        PERCENTS="15 38 62 85"
    else
        PERCENTS="10 30 50 70 90"
    fi

    INDEX=0
    FAILED=0

    log "POST $ID START rotation=${COUNT} duration=${DURATION}s"

    for PERCENT in $PERCENTS; do
        INDEX=$((INDEX + 1))

        TS="$(
            awk -v d="$DURATION" -v p="$PERCENT" '
                BEGIN {
                    t = d * p / 100;
                    if (t < 0.05) t = 0.05;
                    printf "%.3f", t;
                }
            '
        )"

        OUT="$TMP/$(printf 'frame-%02d.webp' "$INDEX")"
        FRAME_OK=0

        log "POST $ID FRAME ${INDEX}/${COUNT} percent=${PERCENT} ts=${TS}s"

        TRY=1
        while [ "$TRY" -le "$FRAME_TRIES" ]; do
            rm -f "$OUT"

            if extract_fast "$TS" "$VIDEO_URL" "$OUT" && [ -s "$OUT" ]; then
                FRAME_OK=1
                break
            fi

            log "POST $ID FRAME ${INDEX}/${COUNT} retry=${TRY}/${FRAME_TRIES}"
            TRY=$((TRY + 1))
            sleep 2
        done

        if [ "$FRAME_OK" -ne 1 ]; then
            rm -f "$OUT"
            log "POST $ID FRAME ${INDEX}/${COUNT} fallback"

            if extract_fallback "$TS" "$VIDEO_URL" "$OUT" && [ -s "$OUT" ]; then
                FRAME_OK=1
            fi
        fi

        if [ "$FRAME_OK" -ne 1 ]; then
            FAILED=1
            log "POST $ID FRAME ${INDEX}/${COUNT} FAILED"
            break
        fi

        log "POST $ID FRAME ${INDEX}/${COUNT} OK"
    done

    if [ "$FAILED" -ne 0 ]; then
        rm -rf "$TMP"

        ATTEMPTS=$((ATTEMPTS + 1))

        "$PHP" "$WP" post meta update "$ID" _pltp_rotation_attempts "$ATTEMPTS" \
            --path="$ROOT" >/dev/null 2>&1

        "$PHP" "$WP" post meta update "$ID" _pltp_rotation_error \
            "FFmpeg frame gagal (attempt $ATTEMPTS/3)" \
            --path="$ROOT" >/dev/null 2>&1

        if [ "$ATTEMPTS" -ge 3 ]; then
            "$PHP" "$WP" post meta delete "$ID" _pltp_rotation_pending \
                --path="$ROOT" >/dev/null 2>&1
            "$PHP" "$WP" post meta update "$ID" _pltp_rotation_failed 1 \
                --path="$ROOT" >/dev/null 2>&1
        fi

        log "POST $ID ERROR ffmpeg attempt=$ATTEMPTS"
        continue
    fi

    COMMIT_OUTPUT="$(
        PLTP_ROTATION_POST_ID="$ID" \
        PLTP_ROTATION_DIR="$TMP" \
        PLTP_ROTATION_COUNT="$COUNT" \
        "$PHP" "$WP" eval '
            $id = (int) getenv("PLTP_ROTATION_POST_ID");
            $dir = (string) getenv("PLTP_ROTATION_DIR");
            $count = (int) getenv("PLTP_ROTATION_COUNT");

            $result = PLTP_Publisher::rotation_worker_commit(
                $id,
                $dir,
                $count
            );

            if (is_wp_error($result)) {
                fwrite(STDERR, $result->get_error_message() . PHP_EOL);
                exit(3);
            }

            echo count($result);
        ' \
        --path="$ROOT" \
        2>&1
    )"
    COMMIT_STATUS=$?

    if [ "$COMMIT_STATUS" -ne 0 ]; then
        rm -rf "$TMP"

        ATTEMPTS=$((ATTEMPTS + 1))

        "$PHP" "$WP" post meta update "$ID" _pltp_rotation_attempts "$ATTEMPTS" \
            --path="$ROOT" >/dev/null 2>&1

        "$PHP" "$WP" post meta update "$ID" _pltp_rotation_error \
            "Upload R2 rotation gagal (attempt $ATTEMPTS/3)" \
            --path="$ROOT" >/dev/null 2>&1

        if [ "$ATTEMPTS" -ge 3 ]; then
            "$PHP" "$WP" post meta delete "$ID" _pltp_rotation_pending \
                --path="$ROOT" >/dev/null 2>&1
            "$PHP" "$WP" post meta update "$ID" _pltp_rotation_failed 1 \
                --path="$ROOT" >/dev/null 2>&1
        fi

        log "POST $ID ERROR r2 attempt=$ATTEMPTS detail=${COMMIT_OUTPUT}"
        continue
    fi

    rm -rf "$TMP"

    log "POST $ID OK rotation=${COUNT} frames"
done
