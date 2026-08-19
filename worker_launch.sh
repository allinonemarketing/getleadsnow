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
    nohup php "$SCRIPT" >>"$LOG" 2>&1 &
done

exit 0
