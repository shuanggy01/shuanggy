#!/usr/bin/env bash
set -u

# shellcheck source=common.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/common.sh"

# Optional aaPanel cron fallback, only required when web PHP cannot spawn
# background commands or WordPress' internal cron is disabled.
SELF="$(readlink -f "$0")"
HERE="$(dirname "$SELF")"

if [ "$(id -u)" -eq 0 ] && id www >/dev/null 2>&1; then
    exec su -s /bin/bash www -c "/bin/bash '$SELF'"
fi

if [ -x "$PHP" ] && [ -f "$WP" ]; then
    "$PHP" "$WP" eval 'PLTP_Auto_Media::tick();' --path="$ROOT" >/dev/null 2>&1 || true
fi

# Direct invocation as a fallback when PHP's exec/proc_open is disabled.
exec /bin/bash "$HERE/auto-media-worker.sh"
