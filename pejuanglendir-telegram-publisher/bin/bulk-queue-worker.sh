#!/usr/bin/env bash
set -u

# shellcheck source=common.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/common.sh"

# Performance-safe mode: background queue should yield to web/PHP traffic.
renice 5 -p $$ >/dev/null 2>&1 || true
ionice -c2 -n7 -p $$ >/dev/null 2>&1 || true

LOCK_FILE="/tmp/pejuanglendir-bulk-queue-worker.lock"

exec 9>"$LOCK_FILE"

if command -v flock >/dev/null 2>&1; then
    flock -n 9 || exit 0
fi

if [ ! -x "$PHP" ] || [ ! -f "$WP" ]; then
    echo "ERROR: PHP/WP-CLI tidak tersedia"
    exit 1
fi

"$PHP" "$WP" eval '
$result = PLTP_Publisher::process_bulk_queue_worker(1, 35);
echo wp_json_encode($result, JSON_UNESCAPED_SLASHES) . PHP_EOL;
' --path="$ROOT"
