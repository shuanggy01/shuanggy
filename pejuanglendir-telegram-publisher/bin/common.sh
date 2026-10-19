#!/usr/bin/env bash
# Shared environment detection for all PejuangLendir OS workers (V1.9.2).
# Every value can be overridden in bin/config.local.sh (not overwritten by upgrades
# if you keep a copy) or through environment variables PLTP_ROOT / PLTP_PHP / PLTP_WP /
# PLTP_FFMPEG / PLTP_FFPROBE.

PLTP_BIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

[ -f "$PLTP_BIN_DIR/config.local.sh" ] && . "$PLTP_BIN_DIR/config.local.sh"

# WordPress root: <root>/wp-content/plugins/<plugin>/bin
if [ -z "${PLTP_ROOT:-}" ]; then
    PLTP_ROOT="$(cd "$PLTP_BIN_DIR/../../../.." 2>/dev/null && pwd)"
fi
if [ ! -f "$PLTP_ROOT/wp-config.php" ] && [ ! -f "$PLTP_ROOT/../wp-config.php" ]; then
    [ -d /www/wwwroot/pejuanglendir.site ] && PLTP_ROOT="/www/wwwroot/pejuanglendir.site"
fi

pltp_find_bin() {
    local name="$1"; shift
    local candidate
    for candidate in "$@"; do
        [ -n "$candidate" ] && [ -x "$candidate" ] && { printf '%s' "$candidate"; return 0; }
    done
    command -v "$name" 2>/dev/null
}

if [ -z "${PLTP_PHP:-}" ]; then
    PLTP_PHP="$(pltp_find_bin php \
        /www/server/php/84/bin/php /www/server/php/83/bin/php /www/server/php/82/bin/php \
        /www/server/php/81/bin/php /www/server/php/80/bin/php)"
fi
if [ -z "${PLTP_WP:-}" ]; then
    PLTP_WP="$(pltp_find_bin wp /usr/local/bin/wp /usr/bin/wp)"
fi
[ -n "${PLTP_FFMPEG:-}" ]  || PLTP_FFMPEG="$(pltp_find_bin ffmpeg /usr/bin/ffmpeg /usr/local/bin/ffmpeg)"
[ -n "${PLTP_FFPROBE:-}" ] || PLTP_FFPROBE="$(pltp_find_bin ffprobe /usr/bin/ffprobe /usr/local/bin/ffprobe)"

ROOT="$PLTP_ROOT"
PHP="$PLTP_PHP"
WP="$PLTP_WP"
FFMPEG="$PLTP_FFMPEG"
FFPROBE="$PLTP_FFPROBE"
