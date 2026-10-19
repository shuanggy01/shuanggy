#!/usr/bin/env bash
set -u

# Lightweight supervisor for the independent thumbnail/rotation workers.
# V1.9.2: drains the queue (up to MAX_ROUNDS videos per launch) instead of one
# video per launch, so a backlog no longer needs one cron tick per video.
# Each underlying worker still processes exactly one video per call and keeps
# its own lock, so server load stays the same.
HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
LOCK="/tmp/pejuanglendir-auto-media-supervisor.lock"
MAX_ROUNDS="${PLTP_MAX_ROUNDS:-6}"

exec 9>"$LOCK"
if command -v flock >/dev/null 2>&1; then
    flock -n 9 || exit 0
fi

printf '[%s] MEDIA RUN BEGIN\n' "$(date '+%F %T')"

ROUND=0
while [ "$ROUND" -lt "$MAX_ROUNDS" ]; do
    ROUND=$((ROUND + 1))
    WORKED=0

    OUT="$(/bin/bash "$HERE/thumbnail-worker.sh" 2>&1)"
    [ -n "$OUT" ] && printf '%s\n' "$OUT"
    case "$OUT" in *" OK "*) WORKED=1 ;; esac

    OUT="$(/bin/bash "$HERE/rotation-worker.sh" 2>&1)"
    [ -n "$OUT" ] && printf '%s\n' "$OUT"
    case "$OUT" in *" OK "*) WORKED=1 ;; esac

    # Nothing finished this round (empty queue, or only errors/waits): stop.
    [ "$WORKED" -eq 1 ] || break
done

printf '[%s] MEDIA RUN END (rounds=%s)\n' "$(date '+%F %T')" "$ROUND"
