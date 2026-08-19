#!/usr/bin/env bash
#
# Watchdog for the search queue workers. Run every minute via Cloudways
# Cron Job Management:
#
#   * * * * * bash /home/master/applications/vraxbxzukk/public_html/worker_launch.sh
#
# It keeps $TARGET copies of search_worker.php running. Workers self-recycle
# (~30 min / 300 jobs); if any exit or die, the next cron run tops them back up.
# No sudo, no Supervisord, no Laravel needed. Change $TARGET to scale concurrency.

TARGET=5

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT="$DIR/search_worker.php"
LOG="/tmp/getleadsnow_worker.log"

# How many workers are currently running?
RUNNING=$(pgrep -fc "search_worker.php" 2>/dev/null || echo 0)
NEED=$(( TARGET - RUNNING ))
[ "$NEED" -lt 0 ] && NEED=0

for (( i=0; i<NEED; i++ )); do
    # setsid fully detaches the worker into its own session so it survives after
    # the cron shell exits; fall back to nohup if setsid isn't available.
    if command -v setsid >/dev/null 2>&1; then
        setsid php "$SCRIPT" >>"$LOG" 2>&1 < /dev/null &
    else
        nohup php "$SCRIPT" >>"$LOG" 2>&1 < /dev/null &
    fi
done

exit 0
