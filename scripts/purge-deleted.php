<?php
/**
 * DistributionOS — Soft-Delete Purge Job
 * =========================================
 * Permanently removes soft-deleted records older than $retainDays.
 * Safe: only deletes records already soft-deleted AND older than retention window.
 *
 * Cron example (weekly Sunday at 3am):
 *   0 3 * * 0 php /home/youruser/dos-scripts/purge-deleted.php >> /home/youruser/logs/purge.log 2>&1
 *
 * Run manually: php scripts/purge-deleted.php [--dry-run]
 */

$dryRun   = in_array('--dry-run', $argv ?? [], true);
$retainDays = 90;  // Keep soft-deleted records for 90 days before permanent removal

// Bootstrap (adjust path if running from different location)
$root = dirname(__DIR__).'/api';
require_once $root.'/bootstrap.php';

$cutoff = date('Y-m-d H:i:s', strtotime("-{$retainDays} days"));

echo "[".date('Y-m-d H:i:s')."] DistributionOS Purge Job".($dryRun?" (DRY RUN)":"")."\n";
echo "[".date('Y-m-d H:i:s')."] Cutoff: $cutoff (records deleted before this date)\n";

$tables = [
    'customers'        => ['check' => "SELECT COUNT(*) FROM sales_orders WHERE customer_id=t.id AND status NOT IN('Delivered','Cancelled')"],
    'products'         => ['check' => "SELECT COUNT(*) FROM sales_order_items soi JOIN sales_orders so ON so.id=soi.order_id WHERE soi.product_id=t.id AND so.status NOT IN('Delivered','Cancelled')"],
    'sales_orders'     => ['check' => null],
    'purchase_orders'  => ['check' => null],
    'suppliers'        => ['check' => null],
    'users'            => ['check' => null],
];

$totalPurged = 0;

foreach ($tables as $table => $opts) {
    $rows = Db::all("SELECT id,tenant_id FROM `$table` WHERE deleted_at IS NOT NULL AND deleted_at < ?", [$cutoff]);

    if (empty($rows)) {
        echo "  [SKIP] $table — no eligible records\n";
        continue;
    }

    $purged = 0; $blocked = 0;

    foreach ($rows as $row) {
        // Safety check: don't purge if still referenced in active records
        if ($opts['check']) {
            $sql = str_replace('t.id', '?', $opts['check']);
            $count = (int) Db::val($sql, [$row['id']]);
            if ($count > 0) { $blocked++; continue; }
        }

        if (!$dryRun) {
            Db::run("DELETE FROM `$table` WHERE id=? AND deleted_at IS NOT NULL AND deleted_at < ?", [$row['id'], $cutoff]);
        }
        $purged++;
    }

    echo "  [".($dryRun?"DRY":"DONE")."] $table — would purge $purged, blocked $blocked (still referenced)\n";
    $totalPurged += $purged;
}

// Also clean up expired password reset tokens and old rate limit entries
if (!$dryRun) {
    $prCleaned = Db::run("DELETE FROM password_resets WHERE expires_at < NOW() - INTERVAL 7 DAY")->rowCount();
    $rlCleaned = Db::run("DELETE FROM rate_limits WHERE expires_at < NOW()")->rowCount();
    echo "  [DONE] password_resets — removed $prCleaned expired tokens\n";
    echo "  [DONE] rate_limits — removed $rlCleaned expired buckets\n";
    // Clean old audit logs older than 2 years (configurable)
    $auditCleaned = Db::run("DELETE FROM audit_logs WHERE created_at < NOW() - INTERVAL 2 YEAR")->rowCount();
    echo "  [DONE] audit_logs — removed $auditCleaned records older than 2 years\n";
} else {
    $prCount = (int) Db::val("SELECT COUNT(*) FROM password_resets WHERE expires_at < NOW() - INTERVAL 7 DAY");
    $rlCount = (int) Db::val("SELECT COUNT(*) FROM rate_limits WHERE expires_at < NOW()");
    echo "  [DRY] Would clean $prCount expired password_resets, $rlCount rate_limit entries\n";
}

echo "[".date('Y-m-d H:i:s')."] Purge complete. Total eligible: $totalPurged".($dryRun?" (not deleted — dry run)":"")."\n";
