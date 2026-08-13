<?php
/**
 * DistributionOS v3 — Scheduled Jobs Runner
 * ============================================
 * Runs the three time-based roadmap features in one process:
 *   f12 — generate due recurring invoices
 *   f13 — send payment reminder emails for overdue invoices
 *   f26 — send scheduled email reports
 *
 * Cron example (daily at 6am, after backups but before business hours):
 *   0 6 * * * php /home/youruser/dos-scripts/run-schedules.php >> /home/youruser/logs/schedules.log 2>&1
 *
 * Safe to run more than once a day — every job only acts on rows where
 * next_run_date <= today, so re-running mid-day is a no-op until tomorrow.
 */

require_once __DIR__ . '/../api/bootstrap.php';
require_once __DIR__ . '/../api/routes_v3.php';

echo "[" . date('Y-m-d H:i:s') . "] DistributionOS scheduled jobs starting...\n";

try {
    $invoicesGenerated = dos_run_invoice_schedules();
    echo "  f12 recurring invoices: {$invoicesGenerated} generated\n";
} catch (Throwable $e) {
    echo "  f12 ERROR: " . $e->getMessage() . "\n";
    error_log('[run-schedules] f12 failed: ' . $e->getMessage());
}

try {
    $remindersSent = dos_run_payment_reminders();
    echo "  f13 payment reminders: {$remindersSent} sent\n";
} catch (Throwable $e) {
    echo "  f13 ERROR: " . $e->getMessage() . "\n";
    error_log('[run-schedules] f13 failed: ' . $e->getMessage());
}

try {
    $reportsSent = dos_run_report_schedules();
    echo "  f26 scheduled reports: {$reportsSent} sent\n";
} catch (Throwable $e) {
    echo "  f26 ERROR: " . $e->getMessage() . "\n";
    error_log('[run-schedules] f26 failed: ' . $e->getMessage());
}

echo "[" . date('Y-m-d H:i:s') . "] Done.\n";
