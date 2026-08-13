#!/bin/bash
# DistributionOS — MySQL Backup Script
# Cron example (daily at 2am):
#   0 2 * * * /home/youruser/dos-scripts/backup.sh >> /home/youruser/logs/backup.log 2>&1
#
# Set these variables:
DB_HOST="localhost"
DB_NAME="your_db_name"     # ← CHANGE
DB_USER="your_db_user"     # ← CHANGE
DB_PASS="your_db_password" # ← CHANGE
BACKUP_DIR="/home/youruser/dos-backups"  # ← CHANGE (must be above public_html)
KEEP_DAYS=30  # Delete backups older than this

mkdir -p "$BACKUP_DIR"
chmod 700 "$BACKUP_DIR"
DATE=$(date +"%Y-%m-%d_%H-%M-%S")
FILE="$BACKUP_DIR/dos_${DB_NAME}_${DATE}.sql.gz"

echo "[$(date)] Starting backup → $FILE"

# Security note: passing --password=X on the command line exposes the
# password to any other user on the box via `ps aux` for the life of the
# process. Use the MYSQL_PWD env var instead — it's still not perfect, but
# it isn't visible in the process list.
export MYSQL_PWD="$DB_PASS"

mysqldump \
  --host="$DB_HOST" \
  --user="$DB_USER" \
  --single-transaction \
  --routines \
  --triggers \
  "$DB_NAME" | gzip > "$FILE"

DUMP_STATUS=$?
unset MYSQL_PWD

if [ $DUMP_STATUS -eq 0 ]; then
  chmod 600 "$FILE"
  SIZE=$(du -sh "$FILE" | cut -f1)
  echo "[$(date)] Backup complete: $SIZE"
else
  echo "[$(date)] ERROR: Backup failed!"
  exit 1
fi

# Delete old backups
DELETED=$(find "$BACKUP_DIR" -name "*.sql.gz" -mtime +$KEEP_DAYS -delete -print | wc -l)
echo "[$(date)] Deleted $DELETED backup(s) older than $KEEP_DAYS days"
echo "[$(date)] Done."
