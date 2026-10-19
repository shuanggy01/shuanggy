#!/bin/bash
set -u

# shellcheck source=common.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/common.sh"

LOCK="/tmp/pejuanglendir-backfill.lock"

exec 9>"$LOCK"
/usr/bin/flock -n 9 || exit 0

cd "$ROOT" || exit 1

RUNNER=("$PHP" "$WP" eval 'print_r(PLTP_Publisher::backfill_worker_run(1));' --allow-root --path="$ROOT")

if command -v ionice >/dev/null 2>&1; then
    exec nice -n 15 ionice -c2 -n7 "${RUNNER[@]}"
fi

exec nice -n 15 "${RUNNER[@]}"
