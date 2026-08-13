#!/bin/bash
# DistributionOS — Error Log Monitor
# =====================================
# Security Audit (mn2): "the PHP error log is being monitored."
# Run manually, or via cron with mail output, to catch new errors since
# the last check. Keeps a marker file so each run only shows what's new.
#
# Usage:
#   bash dos-scripts/check-errors.sh /home/youruser/logs/php_errors.log
#
# Cron example (daily 7am, emails you if anything new appears):
#   0 7 * * * bash ~/dos-scripts/check-errors.sh ~/logs/php_errors.log | mail -s "DOS error digest" you@yourdomain.com

LOG_FILE="${1:-$HOME/logs/php_errors.log}"
MARKER_FILE="$HOME/.dos-error-check-marker"

if [ ! -f "$LOG_FILE" ]; then
  echo "No log file found at $LOG_FILE — nothing to check yet."
  exit 0
fi

LAST_SIZE=0
[ -f "$MARKER_FILE" ] && LAST_SIZE=$(cat "$MARKER_FILE")
CURRENT_SIZE=$(wc -c < "$LOG_FILE")

if [ "$CURRENT_SIZE" -gt "$LAST_SIZE" ]; then
  echo "=== New entries in $LOG_FILE since last check ==="
  tail -c +"$((LAST_SIZE + 1))" "$LOG_FILE"
  echo "=== End of new entries ==="
else
  echo "No new log entries since last check."
fi

echo "$CURRENT_SIZE" > "$MARKER_FILE"
