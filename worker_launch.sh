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

# 2 workers ≈ 40-60 city-searches/min — plenty for launch. Each PHP CLI worker
# costs ~40-60MB RSS; on a small box RAM is the scarce resource, not throughput.
# Raise this only after the server has real memory headroom.
TARGET=2

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SCRIPT="$DIR/search_worker.php"

# Per-user log file. The Cloudways cron runs as the APP user while SSH is the
# MASTER user — a shared /tmp log created by one is not appendable by the other,
# and a failed redirection in bash aborts the command itself (so the cron's
# worker-spawn lines silently did nothing). Prefer the app's private logs dir;
# fall back to a per-user /tmp file; last resort /dev/null so spawning never fails.
if [ -d "$DIR/../logs" ] && [ -w "$DIR/../logs" ]; then
    LOG="$DIR/../logs/search_worker.log"
else
    LOG="/tmp/getleadsnow_worker_$(id -un).log"
fi
touch "$LOG" 2>/dev/null || LOG="/dev/null"

# How many workers are currently running? (pgrep -c prints "0" and exits non-zero
# when none match, so take its stdout directly and only default to 0 if empty.)
RUNNING=$(pgrep -fc "search_worker.php" 2>/dev/null)
[ -z "$RUNNING" ] && RUNNING=0
NEED=$(( TARGET - RUNNING ))
[ "$NEED" -lt 0 ] && NEED=0

# Heartbeat so we can confirm the cron is firing this and see the environment it
# runs in (empty php=/pgrep=/setsid= means that tool isn't on the cron user's PATH).
echo "$(date '+%F %T') watchdog running=$RUNNING need=$NEED php=$(command -v php) pgrep=$(command -v pgrep) setsid=$(command -v setsid)" >> "$LOG"

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
