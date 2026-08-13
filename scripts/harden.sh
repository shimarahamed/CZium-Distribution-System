#!/bin/bash
# DistributionOS — Post-Deploy Permission Hardening
# ====================================================
# Run this ONCE after uploading files and setting up dos-private/config.php.
# Fixes Security Audit items fl5 (dir/file perms) and fl6 (config perms).
#
# Usage (from SSH, in your home directory):
#   bash dos-scripts/harden.sh /home/youruser/public_html /home/youruser/dos-private

set -e

PUBLIC_HTML="${1:-./public_html}"
DOS_PRIVATE="${2:-./dos-private}"

echo "=== DistributionOS Permission Hardening ==="
echo "public_html: $PUBLIC_HTML"
echo "dos-private: $DOS_PRIVATE"
echo

if [ ! -d "$PUBLIC_HTML" ]; then
  echo "ERROR: $PUBLIC_HTML does not exist. Pass the correct path as arg 1."
  exit 1
fi

# fl5: directories 755, files 644 — readable/executable by owner+group,
# not writable by anyone else on the shared server.
echo "[1/3] Setting directory permissions to 755..."
find "$PUBLIC_HTML" -type d -exec chmod 755 {} \;

echo "[2/3] Setting file permissions to 644..."
find "$PUBLIC_HTML" -type f -exec chmod 644 {} \;

# Shell scripts need to stay executable
find "$PUBLIC_HTML" -name "*.sh" -exec chmod 755 {} \; 2>/dev/null || true

# fl6: config.php with DB credentials — owner read-only, nobody else.
if [ -f "$DOS_PRIVATE/config.php" ]; then
  echo "[3/3] Locking down $DOS_PRIVATE/config.php to 600..."
  chmod 600 "$DOS_PRIVATE/config.php"
  chmod 700 "$DOS_PRIVATE"
else
  echo "[3/3] WARNING: $DOS_PRIVATE/config.php not found — skipping. Set its permissions manually:"
  echo "         chmod 600 $DOS_PRIVATE/config.php"
fi

# db5: backup directory should not be web-writable either, if it already exists
for d in "$HOME/dos-backups" "$HOME/dos-scripts"; do
  if [ -d "$d" ]; then
    chmod 700 "$d"
    echo "Locked down $d (700)"
  fi
done

echo
echo "=== Done ==="
echo "Verify with: ls -la $DOS_PRIVATE   (config.php should show -rw------- )"
