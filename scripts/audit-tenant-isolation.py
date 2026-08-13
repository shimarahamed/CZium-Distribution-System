#!/usr/bin/env python3
"""
DistributionOS — Tenant Isolation Static Audit
================================================
Zero-trust regression check: scans every SQL query in api/index.php and
api/routes_v3.php for tenant-scoped tables referenced without a tenant_id
filter on the same query. Run this after any change to either file.

Usage:
    python3 scripts/audit-tenant-isolation.py

Exit code 0 = clean, 1 = found unscoped queries (must fix before deploying).
"""
import re
import sys
import os

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
FILES = [
    os.path.join(ROOT, 'api', 'index.php'),
    os.path.join(ROOT, 'api', 'routes_v3.php'),
]

# Every table that has a tenant_id column per schema.sql / schema_v3.sql.
# Update this list whenever a new tenant-scoped table is added.
TENANT_TABLES = [
    'users', 'customers', 'products', 'inventory', 'sales_orders', 'sales_order_items',
    'purchase_orders', 'purchase_order_items', 'suppliers', 'invoices', 'payments',
    'warehouses', 'stock_movements', 'audit_log', 'workflow_rules', 'notifications',
    'price_lists', 'price_list_items', 'credit_notes', 'returns', 'return_items',
    'exchange_rates', 'invoice_schedules', 'reminder_log', 'product_bundles',
    'webhook_subscriptions', 'webhook_deliveries', 'integrations', 'integration_sync_log',
    'api_keys', 'tenant_branding', 'report_schedules', 'data_requests', 'sso_providers',
]

# Tables that are intentionally global (no tenant_id) — never flag these.
GLOBAL_TABLES = ['tenants', 'roles', 'password_resets', 'rate_limits', 'categories']


def check_bare_id_queries(filepath):
    """Find Db::one/all/val calls with WHERE id=? but no tenant_id on the same line."""
    issues = []
    lines = open(filepath).read().split('\n')
    for i, line in enumerate(lines, 1):
        matches = re.findall(r'Db::(one|all|val)\("([^"]*WHERE\s+id\s*=\s*\?[^"]*)"', line)
        for method, sql in matches:
            if 'tenant_id' not in sql:
                issues.append((i, f'Db::{method}() bare WHERE id=? — {sql[:90]}'))
    return issues


def check_unscoped_table_refs(filepath):
    """Find FROM/JOIN/UPDATE/INTO references to tenant tables with no tenant_id
    anywhere in a wide surrounding window (catches $w-built WHERE clauses)."""
    issues = []
    content = open(filepath).read()
    lines = content.split('\n')
    for i, line in enumerate(lines, 1):
        if not re.search(r'(SELECT|UPDATE|DELETE FROM|INSERT INTO)', line, re.IGNORECASE):
            continue
        for table in TENANT_TABLES:
            if re.search(rf'\b(FROM|JOIN|UPDATE|INTO)\s+`?{table}`?\b', line, re.IGNORECASE):
                window = '\n'.join(lines[max(0, i - 9):min(i + 4, len(lines))])
                if 'tenant_id' not in window:
                    issues.append((i, f'[{table}] no tenant_id in surrounding context — {line.strip()[:90]}'))
    return issues


def main():
    total_issues = 0
    for filepath in FILES:
        if not os.path.exists(filepath):
            print(f"SKIP: {filepath} not found")
            continue
        fname = os.path.basename(filepath)
        bare_id_issues = check_bare_id_queries(filepath)
        wide_issues = check_unscoped_table_refs(filepath)
        all_issues = sorted(set(bare_id_issues + wide_issues))

        if all_issues:
            print(f"\n{fname}: {len(all_issues)} potential issue(s)")
            for lineno, msg in all_issues:
                print(f"  line {lineno}: {msg}")
            total_issues += len(all_issues)
        else:
            print(f"{fname}: clean — all tenant-scoped table references properly filtered")

    print()
    if total_issues:
        print(f"FAILED: {total_issues} potential tenant-isolation issue(s) found.")
        print("Review each one — some may be false positives (e.g. a value already")
        print("validated by findOrFail() earlier in the same function), but every")
        print("query should be defensively scoped on its own regardless.")
        sys.exit(1)
    else:
        print("PASSED: no tenant-isolation issues detected.")
        sys.exit(0)


if __name__ == '__main__':
    main()
