#!/bin/bash
# DistributionOS — Backup Restore Test
# =======================================
# Security Audit (db6): "a backup that has never been tested is not a backup."
# This restores the latest backup into a SEPARATE, temporary database so it
# never touches your live data, then reports row counts so you can sanity-check.
#
# Usage:
#   bash dos-scripts/test-restore.sh
#
# Run this monthly, or right after you first set up backups.

set -e

DB_HOST="localhost"
DB_USER="your_db_user"       # ← CHANGE (needs CREATE privilege for the test DB)
DB_PASS="your_db_password"   # ← CHANGE
BACKUP_DIR="/home/youruser/dos-backups"  # ← CHANGE, same as backup.sh
TEST_DB="dos_restore_test_$(date +%s)"

LATEST=$(ls -t "$BACKUP_DIR"/*.sql.gz 2>/dev/null | head -n1)

if [ -z "$LATEST" ]; then
  echo "ERROR: No backup files found in $BACKUP_DIR"
  echo "Run backup.sh first."
  exit 1
fi

echo "=== DistributionOS Restore Test ==="
echo "Testing: $LATEST"
echo "Into temporary database: $TEST_DB"
echo

export MYSQL_PWD="$DB_PASS"

echo "[1/4] Creating temporary test database..."
mysql --host="$DB_HOST" --user="$DB_USER" -e "CREATE DATABASE \`$TEST_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "[2/4] Restoring backup into test database..."
gunzip -c "$LATEST" | mysql --host="$DB_HOST" --user="$DB_USER" "$TEST_DB"

echo "[3/4] Verifying row counts..."
for TABLE in tenants users customers products sales_orders purchase_orders invoices; do
  COUNT=$(mysql --host="$DB_HOST" --user="$DB_USER" -N -e "SELECT COUNT(*) FROM \`$TEST_DB\`.\`$TABLE\`" 2>/dev/null || echo "MISSING")
  printf "  %-20s %s\n" "$TABLE:" "$COUNT"
done

echo "[4/4] Cleaning up test database..."
mysql --host="$DB_HOST" --user="$DB_USER" -e "DROP DATABASE \`$TEST_DB\`;"

unset MYSQL_PWD

echo
echo "=== Restore test complete ==="
echo "If the row counts above look right (non-zero, non-MISSING), your backup is good."
echo "If anything shows 0 or MISSING, investigate backup.sh before relying on it."
