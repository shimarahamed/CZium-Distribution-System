<?php
/**
 * DistributionOS v3 — Roadmap Feature Route Handlers
 * Loaded by api/index.php. Each function mirrors the signature style of
 * the existing route_* functions in index.php so they share the same
 * $tid/$uid/$findOrFail closures where useful.
 */

// ═══════════════════════════════════════════════════════════
// PHASE 3 — QUICK WINS
// ═══════════════════════════════════════════════════════════

// ─── f04: Customer-specific price lists ───────────────────
function route_price_lists(string $m, $id, $sub, array $b, array $q, $tid, $uid, $findOrFail, $softDel): never {
    $t = $tid();
    if ($id && $sub === 'items') {
        Auth::need('products', 'read');
        if ($m === 'GET') {
            Http::ok(Db::all("SELECT pli.*,p.name product_name,p.sku FROM price_list_items pli JOIN products p ON p.id=pli.product_id WHERE pli.price_list_id=? AND pli.tenant_id=?", [$id, $t]));
        }
        if ($m === 'POST') {
            Auth::need('products', 'update');
            Validator::check($b, ['product_id'=>'required','price'=>'required|numeric|min_val:0']);
            $pid = Db::uuid();
            Db::run("INSERT INTO price_list_items(id,tenant_id,price_list_id,product_id,price) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE price=?",
                [$pid, $t, $id, $b['product_id'], $b['price'], $b['price']]);
            Audit::log('UPDATE', 'price_list', $id, 'price item set');
            Http::ok(['message'=>'Price set']);
        }
        throw new Err('Method not allowed', 405);
    }
    switch ($m) {
        case 'GET':
            Auth::need('products', 'read');
            if ($id) Http::ok(Db::one("SELECT * FROM price_lists WHERE id=? AND tenant_id=?", [$id, $t]));
            Http::ok(Db::all("SELECT * FROM price_lists WHERE tenant_id=? ORDER BY name", [$t]));
        case 'POST':
            Auth::need('products', 'create');
            Validator::check($b, ['name'=>'required|max:150']);
            $id2 = Db::uuid();
            Db::run("INSERT INTO price_lists(id,tenant_id,name,currency,is_active) VALUES(?,?,?,?,1)",
                [$id2, $t, $b['name'], $b['currency'] ?? 'USD']);
            Audit::log('CREATE', 'price_list', $id2, $b['name']);
            Http::created(Db::one("SELECT * FROM price_lists WHERE id=? AND tenant_id=?", [$id2, $t]));
        case 'PUT':
            Auth::need('products', 'update');
            $row = $findOrFail('price_lists', $id);
            Db::run("UPDATE price_lists SET name=?,currency=?,is_active=? WHERE id=? AND tenant_id=?",
                [$b['name'] ?? $row['name'], $b['currency'] ?? $row['currency'], (int)($b['is_active'] ?? 1), $id, $t]);
            Http::ok(Db::one("SELECT * FROM price_lists WHERE id=? AND tenant_id=?", [$id, $t]));
        case 'DELETE':
            Auth::need('products', 'delete');
            $row = $findOrFail('price_lists', $id);
            Db::run("UPDATE customers SET price_list_id=NULL WHERE price_list_id=? AND tenant_id=?", [$id, $t]);
            Db::run("DELETE FROM price_list_items WHERE price_list_id=? AND tenant_id=?", [$id, $t]);
            Db::run("DELETE FROM price_lists WHERE id=? AND tenant_id=?", [$id, $t]);
            Audit::log('DELETE', 'price_list', $id, $row['name']);
            Http::noContent();
        default: throw new Err('Method not allowed', 405);
    }
}

function dos_resolve_price(string $tid, string $productId, ?string $customerId, float $defaultPrice): float {
    if (!$customerId) return $defaultPrice;
    $cust = Db::one("SELECT price_list_id FROM customers WHERE id=? AND tenant_id=?", [$customerId, $tid]);
    if (empty($cust['price_list_id'])) return $defaultPrice;
    $override = Db::val("SELECT price FROM price_list_items WHERE price_list_id=? AND product_id=? AND tenant_id=?", [$cust['price_list_id'], $productId, $tid]);
    return $override !== null && $override !== false ? (float)$override : $defaultPrice;
}

// ─── f07: Credit notes ──────────────────────────────────────
function route_credit_notes(string $m, $id, array $b, array $q, $tid, $uid, $nextNum): never {
    $t = $tid();
    Auth::need('invoices', 'read');
    if ($m === 'GET') {
        if ($id) Http::ok(Db::one("SELECT cn.*,c.name customer_name FROM credit_notes cn JOIN customers c ON c.id=cn.customer_id WHERE cn.id=? AND cn.tenant_id=?", [$id, $t]));
        $p = [$t]; $w = "WHERE cn.tenant_id=?";
        if (!empty($q['customer_id'])) { $w .= " AND cn.customer_id=?"; $p[] = $q['customer_id']; }
        [$rows, $pg] = Db::paged("SELECT cn.*,c.name customer_name FROM credit_notes cn JOIN customers c ON c.id=cn.customer_id $w ORDER BY cn.issued_date DESC", $p, (int)($q['page'] ?? 1));
        Http::paged($rows, $pg);
    }
    if ($m === 'POST') {
        Auth::need('invoices', 'create');
        Validator::check($b, ['customer_id'=>'required','amount'=>'required|numeric|min_val:0.01']);
        $cust = Db::one("SELECT * FROM customers WHERE id=? AND tenant_id=? AND deleted_at IS NULL", [$b['customer_id'], $t]);
        if (!$cust) throw new NotFound('Customer not found');
        if (!empty($b['invoice_id'])) {
            $inv = Db::one("SELECT * FROM invoices WHERE id=? AND tenant_id=?", [$b['invoice_id'], $t]);
            if (!$inv) throw new NotFound('Invoice not found');
        }
        $n = (int) Db::val("SELECT COUNT(*) FROM credit_notes WHERE tenant_id=?", [$t]);
        $cnum = 'CN-' . date('Y') . '-' . str_pad($n + 1, 4, '0', STR_PAD_LEFT);
        $id2 = Db::uuid();
        Db::begin();
        try {
            Db::run("INSERT INTO credit_notes(id,tenant_id,credit_number,invoice_id,customer_id,reason,amount,status,issued_date,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)",
                [$id2, $t, $cnum, $b['invoice_id'] ?? null, $b['customer_id'], $b['reason'] ?? null, (float)$b['amount'], 'Issued', date('Y-m-d'), $b['notes'] ?? null, $uid()]);
            Db::run("UPDATE customers SET outstanding_balance = GREATEST(0, outstanding_balance - ?) WHERE id=? AND tenant_id=?", [(float)$b['amount'], $b['customer_id'], $t]);
            if (!empty($b['invoice_id'])) {
                Db::run("UPDATE invoices SET paid_amount = paid_amount + ? WHERE id=? AND tenant_id=?", [(float)$b['amount'], $b['invoice_id'], $t]);
            }
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }
        Audit::log('CREATE', 'credit_note', $id2, $cnum, null, ['amount' => $b['amount']]);
        Http::created(Db::one("SELECT * FROM credit_notes WHERE id=? AND tenant_id=?", [$id2, $t]));
    }
    if ($m === 'PUT') {
        Auth::need('invoices', 'update');
        $row = Db::one("SELECT * FROM credit_notes WHERE id=? AND tenant_id=?", [$id, $t]);
        if (!$row) throw new NotFound();
        Db::run("UPDATE credit_notes SET status=?,notes=? WHERE id=? AND tenant_id=?", [$b['status'] ?? $row['status'], $b['notes'] ?? $row['notes'], $id, $t]);
        Http::ok(Db::one("SELECT * FROM credit_notes WHERE id=? AND tenant_id=?", [$id, $t]));
    }
    throw new Err('Method not allowed', 405);
}

// ─── f10: Returns Management (RMA) ─────────────────────────
function route_returns(string $m, $id, $sub, array $b, array $q, $tid, $uid, $findOrFail, $nextNum): never {
    $t = $tid();
    if ($id && $sub === 'receive' && $m === 'POST') {
        Auth::need('inventory', 'update');
        $ret = Db::one("SELECT * FROM returns WHERE id=? AND tenant_id=?", [$id, $t]);
        if (!$ret) throw new NotFound('Return not found');
        if (!in_array($ret['status'], ['Requested', 'Approved'])) throw new Unproc("Return status '{$ret['status']}' cannot be received.");
        $items = Db::all("SELECT * FROM return_items WHERE return_id=? AND tenant_id=?", [$id, $t]);
        $warehouseId = $b['warehouse_id'] ?? null;
        Db::begin();
        try {
            foreach ($items as $item) {
                $condition = $b['conditions'][$item['id']] ?? $item['condition'];
                Db::run("UPDATE return_items SET condition=? WHERE id=? AND tenant_id=?", [$condition, $item['id'], $t]);
                if ($condition === 'Resellable' && $warehouseId) {
                    $prod = Db::one("SELECT * FROM products WHERE id=? AND tenant_id=?", [$item['product_id'], $t]);
                    $inv = Db::one("SELECT * FROM inventory WHERE product_id=? AND warehouse_id=? AND tenant_id=?", [$item['product_id'], $warehouseId, $t]);
                    $before = (float)($inv['qty_on_hand'] ?? 0);
                    $after = $before + (float)$item['qty'];
                    Db::run("INSERT INTO inventory(id,tenant_id,product_id,warehouse_id,qty_on_hand,avg_cost) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE qty_on_hand=qty_on_hand+?",
                        [Db::uuid(), $t, $item['product_id'], $warehouseId, $after, $prod['cost_price'] ?? 0, (float)$item['qty']]);
                    Db::run("INSERT INTO stock_movements(tenant_id,product_id,warehouse_id,type,reference_type,reference_id,qty,unit_cost,qty_before,qty_after,reason,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)",
                        [$t, $item['product_id'], $warehouseId, 'RETURN', 'return', $id, (float)$item['qty'], $prod['cost_price'] ?? 0, $before, $after, "Return: {$ret['rma_number']}", $uid()]);
                    Db::run("UPDATE return_items SET restocked=1 WHERE id=? AND tenant_id=?", [$item['id'], $t]);
                }
            }
            Db::run("UPDATE returns SET status='Received',received_date=NOW() WHERE id=? AND tenant_id=?", [$id, $t]);
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }
        Audit::log('UPDATE', 'return', $id, $ret['rma_number'], ['status' => $ret['status']], ['status' => 'Received']);
        Http::ok(['message' => 'Return received', 'rma_number' => $ret['rma_number']]);
    }
    if ($id && $sub === 'credit-note' && $m === 'POST') {
        Auth::need('invoices', 'create');
        $ret = Db::one("SELECT r.*,c.name customer_name FROM returns r JOIN customers c ON c.id=r.customer_id WHERE r.id=? AND r.tenant_id=?", [$id, $t]);
        if (!$ret) throw new NotFound('Return not found');
        if ($ret['status'] !== 'Received') throw new Unproc('Return must be Received before issuing a credit note.');
        $total = (float) Db::val("SELECT COALESCE(SUM(qty*unit_price),0) FROM return_items WHERE return_id=? AND tenant_id=?", [$id, $t]);
        $n = (int) Db::val("SELECT COUNT(*) FROM credit_notes WHERE tenant_id=?", [$t]);
        $cnum = 'CN-' . date('Y') . '-' . str_pad($n + 1, 4, '0', STR_PAD_LEFT);
        $cnId = Db::uuid();
        Db::begin();
        try {
            Db::run("INSERT INTO credit_notes(id,tenant_id,credit_number,customer_id,reason,amount,status,issued_date,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)",
                [$cnId, $t, $cnum, $ret['customer_id'], 'Return: ' . $ret['rma_number'], $total, 'Issued', date('Y-m-d'), $b['notes'] ?? null, $uid()]);
            Db::run("UPDATE customers SET outstanding_balance = GREATEST(0, outstanding_balance - ?) WHERE id=? AND tenant_id=?", [$total, $ret['customer_id'], $t]);
            Db::run("UPDATE returns SET status='Credited',credit_note_id=? WHERE id=? AND tenant_id=?", [$cnId, $id, $t]);
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }
        Audit::log('CREATE', 'credit_note', $cnId, $cnum, null, ['from_return' => $ret['rma_number']]);
        Http::created(['credit_note' => Db::one("SELECT * FROM credit_notes WHERE id=? AND tenant_id=?", [$cnId, $t]), 'amount' => $total]);
    }
    switch ($m) {
        case 'GET':
            Auth::need('orders', 'read');
            if ($id) {
                $ret = Db::one("SELECT r.*,c.name customer_name,so.order_number FROM returns r JOIN customers c ON c.id=r.customer_id JOIN sales_orders so ON so.id=r.order_id WHERE r.id=? AND r.tenant_id=?", [$id, $t]);
                if (!$ret) throw new NotFound();
                $items = Db::all("SELECT ri.*,p.name product_name,p.sku FROM return_items ri JOIN products p ON p.id=ri.product_id WHERE ri.return_id=? AND ri.tenant_id=?", [$id, $t]);
                Http::ok(['return' => $ret, 'items' => $items]);
            }
            $p = [$t]; $w = "WHERE r.tenant_id=?";
            if (!empty($q['status'])) { $w .= " AND r.status=?"; $p[] = $q['status']; }
            [$rows, $pg] = Db::paged("SELECT r.*,c.name customer_name,so.order_number FROM returns r JOIN customers c ON c.id=r.customer_id JOIN sales_orders so ON so.id=r.order_id $w ORDER BY r.requested_date DESC", $p, (int)($q['page'] ?? 1));
            Http::paged($rows, $pg);
        case 'POST':
            Auth::need('orders', 'create');
            Validator::check($b, ['order_id' => 'required']);
            if (empty($b['items']) || !is_array($b['items'])) throw new Unproc('At least one item is required.');
            $order = Db::one("SELECT * FROM sales_orders WHERE id=? AND tenant_id=? AND deleted_at IS NULL", [$b['order_id'], $t]);
            if (!$order) throw new NotFound('Order not found');
            if (!in_array($order['status'], ['Delivered', 'Shipped'])) throw new Unproc('Returns can only be raised against Delivered or Shipped orders.');
            $n = (int) Db::val("SELECT COUNT(*) FROM returns WHERE tenant_id=?", [$t]);
            $rnum = 'RMA-' . date('Y') . '-' . str_pad($n + 1, 4, '0', STR_PAD_LEFT);
            $id2 = Db::uuid();
            Db::begin();
            try {
                Db::run("INSERT INTO returns(id,tenant_id,rma_number,order_id,customer_id,status,reason,requested_date,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)",
                    [$id2, $t, $rnum, $b['order_id'], $order['customer_id'], 'Requested', $b['reason'] ?? null, date('Y-m-d'), $b['notes'] ?? null, $uid()]);
                foreach ($b['items'] as $it) {
                    if (empty($it['product_id']) || empty($it['qty'])) continue;
                    Db::run("INSERT INTO return_items(id,tenant_id,return_id,product_id,qty,unit_price,condition) VALUES(?,?,?,?,?,?,?)",
                        [Db::uuid(), $t, $id2, $it['product_id'], (float)$it['qty'], (float)($it['unit_price'] ?? 0), $it['condition'] ?? 'Resellable']);
                }
                Db::commit();
            } catch (Throwable $e) { Db::rollback(); throw $e; }
            Audit::log('CREATE', 'return', $id2, $rnum, null, ['order' => $order['order_number']]);
            Http::created(Db::one("SELECT * FROM returns WHERE id=? AND tenant_id=?", [$id2, $t]));
        case 'PUT':
            Auth::need('orders', 'update');
            $ret = $findOrFail('returns', $id);
            if (!empty($b['status']) && !in_array($b['status'], ['Requested','Approved','Received','Credited','Rejected','Cancelled'])) throw new Unproc('Invalid status.');
            Db::run("UPDATE returns SET status=?,notes=? WHERE id=? AND tenant_id=?", [$b['status'] ?? $ret['status'], $b['notes'] ?? $ret['notes'], $id, $t]);
            Audit::log('UPDATE', 'return', $id, $ret['rma_number'], ['status' => $ret['status']], ['status' => $b['status'] ?? $ret['status']]);
            Http::ok(Db::one("SELECT * FROM returns WHERE id=? AND tenant_id=?", [$id, $t]));
        default: throw new Err('Method not allowed', 405);
    }
}

// ═══════════════════════════════════════════════════════════
// PHASE 4 — BUSINESS DEPTH
// ═══════════════════════════════════════════════════════════

// ─── f09: Multi-currency ────────────────────────────────────
function route_exchange_rates(string $m, $id, array $b, array $q, $tid): never {
    $t = $tid();
    Auth::need('settings', 'read');
    if ($m === 'GET') {
        Http::ok(Db::all("SELECT * FROM exchange_rates WHERE tenant_id=? ORDER BY effective_date DESC, currency", [$t]));
    }
    if ($m === 'POST') {
        Auth::need('settings', 'update');
        Validator::check($b, ['currency' => 'required|max:3', 'rate_to_base' => 'required|numeric|min_val:0.00000001']);
        $id2 = Db::uuid();
        Db::run("INSERT INTO exchange_rates(id,tenant_id,currency,rate_to_base,effective_date) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE rate_to_base=?",
            [$id2, $t, strtoupper($b['currency']), (float)$b['rate_to_base'], $b['effective_date'] ?? date('Y-m-d'), (float)$b['rate_to_base']]);
        Audit::log('CREATE', 'exchange_rate', $id2, $b['currency']);
        Http::created(['message' => 'Rate set']);
    }
    throw new Err('Method not allowed', 405);
}

/** Latest known rate for a currency, or 1.0 if none set (assume same as base). */
function dos_latest_rate(string $tid, string $currency): float {
    if ($currency === 'USD') return 1.0;
    $rate = Db::val("SELECT rate_to_base FROM exchange_rates WHERE tenant_id=? AND currency=? ORDER BY effective_date DESC LIMIT 1", [$tid, $currency]);
    return $rate !== false && $rate !== null ? (float)$rate : 1.0;
}

// ─── f11: Back-order management ─────────────────────────────
function dos_handle_order_split(string $tid, string $uid, string $orderId, array $body): array {
    $order = Db::one("SELECT * FROM sales_orders WHERE id=? AND tenant_id=? AND deleted_at IS NULL", [$orderId, $tid]);
    if (!$order) throw new NotFound('Order not found');
    $items = Db::all("SELECT * FROM sales_order_items WHERE order_id=? AND tenant_id=?", [$orderId, $tid]);
    if (!$order['warehouse_id']) throw new Unproc('Order has no warehouse assigned; cannot check stock availability.');

    $hasBackorder = false;
    Db::begin();
    try {
        foreach ($items as $item) {
            $avail = (float) Db::val("SELECT qty_available FROM inventory WHERE product_id=? AND warehouse_id=? AND tenant_id=?", [$item['product_id'], $order['warehouse_id'], $tid]);
            [$fulfill, $back] = Calc::splitBackorder((float)$item['qty_ordered'], $avail ?: 0);
            if ($back > 0) {
                $hasBackorder = true;
                Db::run("UPDATE sales_order_items SET qty_backordered=? WHERE id=? AND tenant_id=?", [$back, $item['id'], $tid]);
            }
        }
        Db::run("UPDATE sales_orders SET has_backorder=? WHERE id=? AND tenant_id=?", [$hasBackorder ? 1 : 0, $orderId, $tid]);
        Db::commit();
    } catch (Throwable $e) { Db::rollback(); throw $e; }
    Audit::log('UPDATE', 'sales_order', $orderId, $order['order_number'], null, ['action' => 'backorder_check', 'has_backorder' => $hasBackorder]);
    return ['has_backorder' => $hasBackorder, 'order_number' => $order['order_number']];
}

// ─── f12: Recurring invoices ──────────────────────────────
function route_invoice_schedules(string $m, $id, array $b, array $q, $tid, $uid): never {
    $t = $tid();
    Auth::need('invoices', 'read');
    if ($m === 'GET') {
        if ($id) Http::ok(Db::one("SELECT * FROM invoice_schedules WHERE id=? AND tenant_id=?", [$id, $t]));
        Http::ok(Db::all("SELECT s.*,c.name customer_name FROM invoice_schedules s JOIN customers c ON c.id=s.customer_id WHERE s.tenant_id=? ORDER BY s.next_run_date", [$t]));
    }
    if ($m === 'POST') {
        Auth::need('invoices', 'create');
        Validator::check($b, ['customer_id' => 'required', 'amount' => 'required|numeric|min_val:0.01', 'frequency' => 'required|in:Weekly,Monthly,Quarterly,Annually']);
        $cust = Db::one("SELECT * FROM customers WHERE id=? AND tenant_id=? AND deleted_at IS NULL", [$b['customer_id'], $t]);
        if (!$cust) throw new NotFound('Customer not found');
        $id2 = Db::uuid();
        Db::run("INSERT INTO invoice_schedules(id,tenant_id,customer_id,frequency,amount,currency,description,next_run_date,is_active,created_by) VALUES(?,?,?,?,?,?,?,?,1,?)",
            [$id2, $t, $b['customer_id'], $b['frequency'], (float)$b['amount'], $b['currency'] ?? 'USD', $b['description'] ?? null, $b['next_run_date'] ?? date('Y-m-d'), $uid()]);
        Audit::log('CREATE', 'invoice_schedule', $id2, $cust['name']);
        Http::created(Db::one("SELECT * FROM invoice_schedules WHERE id=? AND tenant_id=?", [$id2, $t]));
    }
    if ($m === 'PUT') {
        Auth::need('invoices', 'update');
        $row = Db::one("SELECT * FROM invoice_schedules WHERE id=? AND tenant_id=?", [$id, $t]);
        if (!$row) throw new NotFound();
        Db::run("UPDATE invoice_schedules SET is_active=?,amount=?,frequency=? WHERE id=? AND tenant_id=?",
            [(int)($b['is_active'] ?? $row['is_active']), $b['amount'] ?? $row['amount'], $b['frequency'] ?? $row['frequency'], $id, $t]);
        Http::ok(Db::one("SELECT * FROM invoice_schedules WHERE id=? AND tenant_id=?", [$id, $t]));
    }
    if ($m === 'DELETE') {
        Auth::need('invoices', 'delete');
        Db::run("DELETE FROM invoice_schedules WHERE id=? AND tenant_id=?", [$id, $t]);
        Http::noContent();
    }
    throw new Err('Method not allowed', 405);
}

/** Cron entry point: generate due recurring invoices. */
function dos_run_invoice_schedules(): int {
    $generated = 0;
    $due = Db::all("SELECT s.* FROM invoice_schedules s WHERE s.is_active=1 AND s.next_run_date<=CURDATE()");
    foreach ($due as $sched) {
        Db::setTenant($sched['tenant_id']);
        $cust = Db::one("SELECT * FROM customers WHERE id=? AND tenant_id=?", [$sched['customer_id'], $sched['tenant_id']]);
        if (!$cust) continue;
        $n = (int) Db::val("SELECT COUNT(*) FROM invoices WHERE tenant_id=?", [$sched['tenant_id']]);
        $inum = 'INV-' . date('Y') . '-' . str_pad($n + 1, 4, '0', STR_PAD_LEFT);
        $invId = Db::uuid();
        Db::run("INSERT INTO invoices(id,tenant_id,invoice_number,customer_id,status,invoice_date,due_date,total_amount,currency,notes) VALUES(?,?,?,?,?,?,?,?,?,?)",
            [$invId, $sched['tenant_id'], $inum, $sched['customer_id'], 'Sent', date('Y-m-d'), date('Y-m-d', strtotime('+30 days')), $sched['amount'], $sched['currency'], $sched['description']]);
        if (!empty($cust['email'])) {
            Mailer::send($cust['email'], "Invoice $inum", "Dear {$cust['name']},\n\nYour recurring invoice $inum for {$sched['amount']} {$sched['currency']} has been generated.\n\nThank you.");
        }
        $next = Calc::nextScheduleDate($sched['next_run_date'], $sched['frequency']);
        Db::run("UPDATE invoice_schedules SET next_run_date=?,last_run_at=NOW() WHERE id=? AND tenant_id=?", [$next, $sched['id'], $sched['tenant_id']]);
        $generated++;
    }
    return $generated;
}

// ─── f13: Payment reminders ───────────────────────────────
/** Cron entry point: send reminder emails for overdue invoices at 7/14/30 day thresholds. */
function dos_run_payment_reminders(): int {
    $sent = 0;
    $thresholds = [7, 14, 30];
    $overdue = Db::all("SELECT i.*,c.name customer_name,c.email customer_email FROM invoices i JOIN customers c ON c.id=i.customer_id WHERE i.status IN('Sent','Partially Paid','Overdue') AND i.due_date < CURDATE()");
    foreach ($overdue as $inv) {
        Db::setTenant($inv['tenant_id']);
        $daysOverdue = (int) floor((time() - strtotime($inv['due_date'])) / 86400);
        $sentRows = Db::all("SELECT days_overdue FROM reminder_log WHERE invoice_id=? AND tenant_id=?", [$inv['id'], $inv['tenant_id']]);
        $alreadySent = array_map('intval', array_column($sentRows, 'days_overdue'));
        $due = Calc::dueReminders($daysOverdue, $thresholds, $alreadySent);
        foreach ($due as $threshold) {
            if (!empty($inv['customer_email'])) {
                Mailer::send($inv['customer_email'], "Payment Reminder: Invoice {$inv['invoice_number']}",
                    "Dear {$inv['customer_name']},\n\nInvoice {$inv['invoice_number']} for {$inv['total_amount']} {$inv['currency']} is now $daysOverdue days overdue.\n\nPlease arrange payment at your earliest convenience.");
                Db::run("INSERT INTO reminder_log(id,tenant_id,invoice_id,days_overdue) VALUES(?,?,?,?)", [Db::uuid(), $inv['tenant_id'], $inv['id'], $threshold]);
                $sent++;
            }
        }
        if ($daysOverdue > 0 && $inv['status'] !== 'Overdue') {
            Db::run("UPDATE invoices SET status='Overdue' WHERE id=? AND tenant_id=?", [$inv['id'], $inv['tenant_id']]);
        }
    }
    return $sent;
}

// ─── f15: Product bundles ───────────────────────────────────
function route_bundles(string $m, $id, array $b, array $q, $tid): never {
    $t = $tid();
    Auth::need('products', 'read');
    if ($m === 'GET') {
        Http::ok(Db::all("SELECT pb.*,p.name component_name,p.sku component_sku FROM product_bundles pb JOIN products p ON p.id=pb.component_product_id WHERE pb.bundle_product_id=? AND pb.tenant_id=?", [$id, $t]));
    }
    if ($m === 'POST') {
        Auth::need('products', 'update');
        Validator::check($b, ['bundle_product_id' => 'required', 'component_product_id' => 'required', 'qty' => 'required|numeric|min_val:0.01']);
        Db::run("UPDATE products SET is_bundle=1 WHERE id=? AND tenant_id=?", [$b['bundle_product_id'], $t]);
        Db::run("INSERT INTO product_bundles(id,tenant_id,bundle_product_id,component_product_id,qty) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE qty=?",
            [Db::uuid(), $t, $b['bundle_product_id'], $b['component_product_id'], (float)$b['qty'], (float)$b['qty']]);
        Http::ok(['message' => 'Bundle component added']);
    }
    if ($m === 'DELETE') {
        Auth::need('products', 'update');
        Db::run("DELETE FROM product_bundles WHERE id=? AND tenant_id=?", [$id, $t]);
        Http::noContent();
    }
    throw new Err('Method not allowed', 405);
}

/** When a bundle product is sold, deduct component stock. */
function dos_explode_bundle(string $tid, string $warehouseId, string $bundleProductId, float $qtySold, string $uid): void {
    $components = Db::all("SELECT * FROM product_bundles WHERE bundle_product_id=? AND tenant_id=?", [$bundleProductId, $tid]);
    foreach ($components as $comp) {
        $deductQty = $comp['qty'] * $qtySold;
        $prod = Db::one("SELECT * FROM products WHERE id=? AND tenant_id=?", [$comp['component_product_id'], $tid]);
        $inv = Db::one("SELECT * FROM inventory WHERE product_id=? AND warehouse_id=? AND tenant_id=?", [$comp['component_product_id'], $warehouseId, $tid]);
        $before = (float)($inv['qty_on_hand'] ?? 0);
        $after = max(0, $before - $deductQty);
        Db::run("UPDATE inventory SET qty_on_hand=? WHERE product_id=? AND warehouse_id=? AND tenant_id=?", [$after, $comp['component_product_id'], $warehouseId, $tid]);
        Db::run("INSERT INTO stock_movements(tenant_id,product_id,warehouse_id,type,qty,unit_cost,qty_before,qty_after,reason,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)",
            [$tid, $comp['component_product_id'], $warehouseId, 'OUT', $deductQty, $prod['cost_price'] ?? 0, $before, $after, 'Bundle component deduction', $uid]);
    }
}

// ─── f16: COGS & Gross Profit report ────────────────────────
function route_cogs_report(string $m, array $q, $tid): never {
    if ($m !== 'GET') throw new Err('Method not allowed', 405);
    Auth::need('reports', 'read');
    $t = $tid();
    $from = $q['from'] ?? date('Y-m-01');
    $to = $q['to'] ?? date('Y-m-d');
    $rows = Db::all(
        "SELECT p.id,p.sku,p.name,
                SUM(soi.qty_ordered) qty_sold,
                SUM(soi.line_total - soi.tax_amount) revenue,
                SUM(soi.qty_ordered * p.cost_price) cogs
         FROM sales_order_items soi
         JOIN products p ON p.id = soi.product_id
         JOIN sales_orders so ON so.id = soi.order_id
         WHERE soi.tenant_id=? AND so.order_date BETWEEN ? AND ? AND so.status != 'Cancelled' AND so.deleted_at IS NULL
         GROUP BY p.id, p.sku, p.name
         ORDER BY revenue DESC",
        [$t, $from, $to]
    );
    $report = array_map(function ($r) {
        $gp = Calc::grossProfit((float)$r['revenue'], (float)$r['cogs']);
        return array_merge($r, [
            'gross_profit' => $gp,
            'gross_margin_pct' => Calc::grossMarginPct((float)$r['revenue'], $gp),
        ]);
    }, $rows);
    $totals = [
        'revenue' => array_sum(array_column($report, 'revenue')),
        'cogs' => array_sum(array_column($report, 'cogs')),
        'gross_profit' => array_sum(array_column($report, 'gross_profit')),
    ];
    $totals['gross_margin_pct'] = Calc::grossMarginPct((float)$totals['revenue'], (float)$totals['gross_profit']);
    Http::ok(['rows' => $report, 'totals' => $totals, 'period' => ['from' => $from, 'to' => $to]]);
}

// ─── f17: VAT / Tax report ───────────────────────────────────
function route_tax_report(string $m, array $q, $tid): never {
    if ($m !== 'GET') throw new Err('Method not allowed', 405);
    Auth::need('reports', 'read');
    $t = $tid();
    $from = $q['from'] ?? date('Y-m-01');
    $to = $q['to'] ?? date('Y-m-d');
    $outputTax = (float) Db::val(
        "SELECT COALESCE(SUM(tax_amount),0) FROM sales_orders WHERE tenant_id=? AND order_date BETWEEN ? AND ? AND status != 'Cancelled' AND deleted_at IS NULL",
        [$t, $from, $to]
    );
    $inputTax = (float) Db::val(
        "SELECT COALESCE(SUM(tax_amount),0) FROM purchase_orders WHERE tenant_id=? AND order_date BETWEEN ? AND ? AND status != 'Cancelled' AND deleted_at IS NULL",
        [$t, $from, $to]
    );
    Http::ok([
        'period' => ['from' => $from, 'to' => $to],
        'output_tax' => $outputTax,
        'input_tax' => $inputTax,
        'net_payable' => Calc::netTaxPayable($outputTax, $inputTax),
    ]);
}

// ═══════════════════════════════════════════════════════════
// PHASE 5 — INTEGRATIONS
// ═══════════════════════════════════════════════════════════

// ─── f18: Webhooks ───────────────────────────────────────────
function route_webhooks(string $m, $id, array $b, array $q, $tid, $uid): never {
    $t = $tid();
    Auth::need('settings', 'read');
    if ($m === 'GET') {
        if ($id === 'deliveries') {
            Http::ok(Db::all("SELECT wd.* FROM webhook_deliveries wd WHERE wd.tenant_id=? ORDER BY wd.created_at DESC LIMIT 50", [$t]));
        }
        if ($id) Http::ok(Db::one("SELECT id,tenant_id,url,events,is_active,created_at FROM webhook_subscriptions WHERE id=? AND tenant_id=?", [$id, $t]));
        Http::ok(Db::all("SELECT id,tenant_id,url,events,is_active,created_at FROM webhook_subscriptions WHERE tenant_id=? ORDER BY created_at DESC", [$t]));
    }
    if ($m === 'POST') {
        Auth::need('settings', 'update');
        Validator::check($b, ['url' => 'required|max:500']);
        if (!filter_var($b['url'], FILTER_VALIDATE_URL) || !str_starts_with($b['url'], 'https://')) {
            throw new Unproc('Webhook URL must be a valid https:// URL.', ['url' => ['Must be https://']]);
        }
        if (!Webhook::isUrlSafe($b['url'])) {
            throw new Unproc('Webhook URL must not point to a private, loopback, or link-local address.', ['url' => ['Not allowed']]);
        }
        $events = $b['events'] ?? ['*'];
        $id2 = Db::uuid();
        $secret = Webhook::newSecret();
        Db::run("INSERT INTO webhook_subscriptions(id,tenant_id,url,secret,events,is_active,created_by) VALUES(?,?,?,?,?,1,?)",
            [$id2, $t, $b['url'], $secret, json_encode($events), $uid()]);
        Audit::log('CREATE', 'webhook', $id2, $b['url']);
        // Secret is only ever shown once, at creation — store it securely on the receiving end.
        Http::created(['id' => $id2, 'url' => $b['url'], 'secret' => $secret, 'events' => $events]);
    }
    if ($m === 'PUT') {
        Auth::need('settings', 'update');
        $row = Db::one("SELECT * FROM webhook_subscriptions WHERE id=? AND tenant_id=?", [$id, $t]);
        if (!$row) throw new NotFound();
        Db::run("UPDATE webhook_subscriptions SET is_active=?,events=? WHERE id=? AND tenant_id=?",
            [(int)($b['is_active'] ?? $row['is_active']), json_encode($b['events'] ?? json_decode($row['events'], true)), $id, $t]);
        Http::ok(['message' => 'Updated']);
    }
    if ($m === 'DELETE') {
        Auth::need('settings', 'update');
        Db::run("DELETE FROM webhook_subscriptions WHERE id=? AND tenant_id=?", [$id, $t]);
        Http::noContent();
    }
    throw new Err('Method not allowed', 405);
}

// ─── f19/f20: Integrations (Xero, QuickBooks, Shopify, WooCommerce) ───
// NOTE: this is the connection-management scaffold — status tracking, settings
// storage, and a manual "sync now" trigger that logs what *would* sync. Wiring
// up real OAuth requires registering an app with each provider and is left as
// the final step once you have client_id/client_secret for your account.
function route_integrations(string $m, $id, $sub, array $b, array $q, $tid, $uid): never {
    $t = $tid();
    Auth::need('settings', 'read');
    if ($id && $sub === 'sync' && $m === 'POST') {
        Auth::need('settings', 'update');
        $integ = Db::one("SELECT * FROM integrations WHERE id=? AND tenant_id=?", [$id, $t]);
        if (!$integ) throw new NotFound('Integration not found');
        if ($integ['status'] !== 'connected') throw new Unproc('Connect the integration before syncing.');
        // Placeholder sync — counts entities that would be pushed. Replace with
        // real API calls once OAuth credentials are stored in `credentials`.
        $orderCount = (int) Db::val("SELECT COUNT(*) FROM sales_orders WHERE tenant_id=? AND status='Delivered' AND deleted_at IS NULL", [$t]);
        Db::run("INSERT INTO integration_sync_log(id,tenant_id,integration_id,direction,entity_type,entity_count,success,message) VALUES(?,?,?,?,?,?,1,?)",
            [Db::uuid(), $t, $id, 'push', 'orders', $orderCount, "Sync scaffold ran — {$orderCount} delivered orders identified for export to {$integ['provider']}."]);
        Db::run("UPDATE integrations SET last_synced_at=NOW() WHERE id=?", [$id]);
        Http::ok(['message' => "Sync scaffold complete: {$orderCount} orders identified.", 'note' => 'Connect real OAuth credentials to enable live push to '.$integ['provider'].'.']);
    }
    switch ($m) {
        case 'GET':
            if ($id) Http::ok(Db::one("SELECT id,tenant_id,provider,status,settings,last_synced_at,last_error FROM integrations WHERE id=? AND tenant_id=?", [$id, $t]));
            Http::ok(Db::all("SELECT id,tenant_id,provider,status,settings,last_synced_at,last_error FROM integrations WHERE tenant_id=?", [$t]));
        case 'POST':
            Auth::need('settings', 'update');
            Validator::check($b, ['provider' => 'required|in:xero,quickbooks,shopify,woocommerce']);
            $id2 = Db::uuid();
            Db::run("INSERT INTO integrations(id,tenant_id,provider,status,settings) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE settings=?",
                [$id2, $t, $b['provider'], 'disconnected', json_encode($b['settings'] ?? []), json_encode($b['settings'] ?? [])]);
            Audit::log('CREATE', 'integration', $id2, $b['provider']);
            Http::created(['id' => $id2, 'provider' => $b['provider'], 'status' => 'disconnected',
                'note' => 'Created in disconnected state. OAuth connect flow must be completed per-provider before syncing.']);
        case 'PUT':
            Auth::need('settings', 'update');
            $row = Db::one("SELECT * FROM integrations WHERE id=? AND tenant_id=?", [$id, $t]);
            if (!$row) throw new NotFound();
            Db::run("UPDATE integrations SET status=?,settings=? WHERE id=? AND tenant_id=?",
                [$b['status'] ?? $row['status'], json_encode($b['settings'] ?? json_decode($row['settings'] ?? '{}', true)), $id, $t]);
            Http::ok(['message' => 'Updated']);
        case 'DELETE':
            Auth::need('settings', 'update');
            Db::run("DELETE FROM integrations WHERE id=? AND tenant_id=?", [$id, $t]);
            Http::noContent();
        default: throw new Err('Method not allowed', 405);
    }
}

// ─── f22: API key management ─────────────────────────────────
function route_api_keys(string $m, $id, array $b, array $q, $tid, $uid): never {
    $t = $tid();
    Auth::need('settings', 'read');
    if ($m === 'GET') {
        Http::ok(Db::all("SELECT id,name,key_prefix,scopes,is_active,last_used_at,expires_at,created_at,revoked_at FROM api_keys WHERE tenant_id=? ORDER BY created_at DESC", [$t]));
    }
    if ($m === 'POST') {
        Auth::need('settings', 'update');
        Validator::check($b, ['name' => 'required|max:150']);
        $rawKey = 'dos_live_' . bin2hex(random_bytes(24));
        $prefix = substr($rawKey, 0, 16);
        $hash = hash('sha256', $rawKey);
        $id2 = Db::uuid();
        Db::run("INSERT INTO api_keys(id,tenant_id,name,key_prefix,key_hash,scopes,is_active,expires_at,created_by) VALUES(?,?,?,?,?,?,1,?,?)",
            [$id2, $t, $b['name'], $prefix, $hash, json_encode($b['scopes'] ?? ['orders:read']), $b['expires_at'] ?? null, $uid()]);
        Audit::log('CREATE', 'api_key', $id2, $b['name']);
        // The raw key is shown exactly once — it cannot be retrieved again after this response.
        Http::created(['id' => $id2, 'name' => $b['name'], 'key' => $rawKey, 'prefix' => $prefix,
            'warning' => 'Copy this key now — it will not be shown again.']);
    }
    if ($m === 'DELETE') {
        Auth::need('settings', 'update');
        Db::run("UPDATE api_keys SET is_active=0,revoked_at=NOW() WHERE id=? AND tenant_id=?", [$id, $t]);
        Audit::log('DELETE', 'api_key', $id, 'revoked');
        Http::noContent();
    }
    throw new Err('Method not allowed', 405);
}

// ═══════════════════════════════════════════════════════════
// PHASE 6 — SCALE & ENTERPRISE
// ═══════════════════════════════════════════════════════════

// ─── f23: Two-Factor Authentication (TOTP) ──────────────────
function route_two_factor(string $m, $id, array $b, $tid, $uid): never {
    $t = $tid(); $u = $uid();
    // POST /two-factor/setup — generate a secret + QR URI, not yet enabled
    if ($id === 'setup' && $m === 'POST') {
        $user = Db::one("SELECT * FROM users WHERE id=? AND tenant_id=?", [$u, $t]);
        $secret = Totp::generateSecret();
        Db::run("UPDATE users SET totp_secret=?,totp_enabled=0 WHERE id=? AND tenant_id=?", [$secret, $u, $t]);
        Http::ok(['secret' => $secret, 'qr_uri' => Totp::authUri($secret, $user['email'])]);
    }
    // POST /two-factor/enable — confirm with a code, turn on, issue recovery codes
    if ($id === 'enable' && $m === 'POST') {
        Validator::check($b, ['code' => 'required']);
        $user = Db::one("SELECT * FROM users WHERE id=? AND tenant_id=?", [$u, $t]);
        if (empty($user['totp_secret'])) throw new Unproc('Call /two-factor/setup first.');
        if (!Totp::verify($user['totp_secret'], $b['code'])) throw new Unproc('Invalid code.', ['code' => ['Incorrect code']]);
        $codes = Totp::generateRecoveryCodes();
        $hashedCodes = array_map(fn($c) => hash('sha256', $c), $codes);
        Db::run("UPDATE users SET totp_enabled=1,totp_recovery_codes=? WHERE id=? AND tenant_id=?", [json_encode($hashedCodes), $u, $t]);
        Audit::log('UPDATE', 'user', $u, $user['name'], null, ['action' => '2fa_enabled']);
        Http::ok(['message' => '2FA enabled.', 'recovery_codes' => $codes, 'warning' => 'Save these now — they will not be shown again.']);
    }
    // POST /two-factor/disable
    if ($id === 'disable' && $m === 'POST') {
        Validator::check($b, ['current_password' => 'required']);
        $user = Db::one("SELECT * FROM users WHERE id=? AND tenant_id=?", [$u, $t]);
        if (!password_verify($b['current_password'], $user['password_hash'])) throw new AuthErr('Current password is incorrect.');
        Db::run("UPDATE users SET totp_enabled=0,totp_secret=NULL,totp_recovery_codes=NULL WHERE id=? AND tenant_id=?", [$u, $t]);
        Audit::log('UPDATE', 'user', $u, $user['name'], null, ['action' => '2fa_disabled']);
        Http::ok(['message' => '2FA disabled.']);
    }
    // POST /two-factor/verify — used during login when totp_enabled=1 (called pre-auth, see index.php note)
    throw new NotFound('Two-factor endpoint not found');
}

// ─── f24: Full-text search ─────────────────────────────────
function route_search(string $m, array $q, $tid): never {
    if ($m !== 'GET') throw new Err('Method not allowed', 405);
    Auth::need('dashboard', 'read');
    $t = $tid();
    $query = trim($q['q'] ?? '');
    if (strlen($query) < 2) throw new Unproc('Search query must be at least 2 characters.');
    $like = "%$query%";
    // f24: prefer real FULLTEXT (faster, ranked, handles word stemming) when the
    // index from schema_v3.sql has been applied; fall back to LIKE automatically
    // if it hasn't (e.g. instance hasn't run the v3 migration yet) so search
    // never breaks during a rolling upgrade.
    $useFulltext = (bool) Db::val("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND INDEX_NAME='ft_customers_search'");
    if ($useFulltext) {
        $results = [
            'orders' => Db::all("SELECT id,order_number label,status,total_amount FROM sales_orders WHERE tenant_id=? AND order_number LIKE ? AND deleted_at IS NULL LIMIT 5", [$t, $like]),
            'customers' => Db::all("SELECT id,name label,code,email FROM customers WHERE tenant_id=? AND MATCH(name,email,code) AGAINST(? IN BOOLEAN MODE) AND deleted_at IS NULL LIMIT 5", [$t, $query.'*']),
            'products' => Db::all("SELECT id,name label,sku,sale_price FROM products WHERE tenant_id=? AND MATCH(name,sku,description) AGAINST(? IN BOOLEAN MODE) AND deleted_at IS NULL LIMIT 5", [$t, $query.'*']),
            'suppliers' => Db::all("SELECT id,name label,code FROM suppliers WHERE tenant_id=? AND MATCH(name,code) AGAINST(? IN BOOLEAN MODE) AND deleted_at IS NULL LIMIT 5", [$t, $query.'*']),
        ];
    } else {
        $results = [
            'orders' => Db::all("SELECT id,order_number label,status,total_amount FROM sales_orders WHERE tenant_id=? AND order_number LIKE ? AND deleted_at IS NULL LIMIT 5", [$t, $like]),
            'customers' => Db::all("SELECT id,name label,code,email FROM customers WHERE tenant_id=? AND (name LIKE ? OR code LIKE ? OR email LIKE ?) AND deleted_at IS NULL LIMIT 5", [$t, $like, $like, $like]),
            'products' => Db::all("SELECT id,name label,sku,sale_price FROM products WHERE tenant_id=? AND (name LIKE ? OR sku LIKE ?) AND deleted_at IS NULL LIMIT 5", [$t, $like, $like]),
            'suppliers' => Db::all("SELECT id,name label,code FROM suppliers WHERE tenant_id=? AND name LIKE ? AND deleted_at IS NULL LIMIT 5", [$t, $like]),
        ];
    }
    $total = array_sum(array_map('count', $results));
    Http::ok(['query' => $query, 'total_results' => $total, 'results' => $results]);
}

// ─── f25: White-label branding per tenant ───────────────────
function route_branding(string $m, array $b, $tid): never {
    $t = $tid();
    if ($m === 'GET') {
        Auth::need('settings', 'read');
        $row = Db::one("SELECT * FROM tenant_branding WHERE tenant_id=?", [$t]);
        Http::ok($row ?? ['tenant_id' => $t, 'logo_url' => null, 'primary_color' => '#2563EB', 'company_name' => null, 'custom_domain' => null]);
    }
    if ($m === 'PUT') {
        Auth::need('settings', 'update');
        if (!empty($b['primary_color']) && !preg_match('/^#[0-9a-fA-F]{6}$/', $b['primary_color'])) {
            throw new Unproc('primary_color must be a hex color like #2563EB.', ['primary_color' => ['Invalid format']]);
        }
        Db::run("INSERT INTO tenant_branding(tenant_id,logo_url,primary_color,company_name,custom_domain) VALUES(?,?,?,?,?)
                 ON DUPLICATE KEY UPDATE logo_url=?,primary_color=?,company_name=?,custom_domain=?",
            [$t, $b['logo_url'] ?? null, $b['primary_color'] ?? '#2563EB', $b['company_name'] ?? null, $b['custom_domain'] ?? null,
             $b['logo_url'] ?? null, $b['primary_color'] ?? '#2563EB', $b['company_name'] ?? null, $b['custom_domain'] ?? null]);
        Audit::log('UPDATE', 'branding', $t, 'tenant branding updated');
        Http::ok(['message' => 'Branding updated']);
    }
    throw new Err('Method not allowed', 405);
}

// ─── f26: Scheduled email reports ───────────────────────────
function route_report_schedules(string $m, $id, array $b, array $q, $tid, $uid): never {
    $t = $tid();
    Auth::need('reports', 'read');
    if ($m === 'GET') {
        Http::ok(Db::all("SELECT * FROM report_schedules WHERE tenant_id=? ORDER BY next_run_date", [$t]));
    }
    if ($m === 'POST') {
        Auth::need('reports', 'read');
        Validator::check($b, ['report_type' => 'required|in:revenue_summary,low_stock_digest,ar_ageing', 'frequency' => 'required|in:Daily,Weekly,Monthly']);
        if (empty($b['recipients']) || !is_array($b['recipients'])) throw new Unproc('recipients must be a non-empty array of emails.');
        $id2 = Db::uuid();
        Db::run("INSERT INTO report_schedules(id,tenant_id,report_type,frequency,recipients,next_run_date,is_active,created_by) VALUES(?,?,?,?,?,?,1,?)",
            [$id2, $t, $b['report_type'], $b['frequency'], json_encode($b['recipients']), $b['next_run_date'] ?? date('Y-m-d'), $uid()]);
        Audit::log('CREATE', 'report_schedule', $id2, $b['report_type']);
        Http::created(Db::one("SELECT * FROM report_schedules WHERE id=? AND tenant_id=?", [$id2, $t]));
    }
    if ($m === 'DELETE') {
        Db::run("DELETE FROM report_schedules WHERE id=? AND tenant_id=?", [$id, $t]);
        Http::noContent();
    }
    throw new Err('Method not allowed', 405);
}

/** Cron entry point: send due scheduled reports. */
function dos_run_report_schedules(): int {
    $sent = 0;
    $due = Db::all("SELECT * FROM report_schedules WHERE is_active=1 AND next_run_date<=CURDATE()");
    foreach ($due as $sched) {
        Db::setTenant($sched['tenant_id']);
        $recipients = json_decode($sched['recipients'], true) ?: [];
        $body = match ($sched['report_type']) {
            'revenue_summary' => 'Revenue summary for the period attached.',
            'low_stock_digest' => 'Products below reorder point: see attached digest.',
            'ar_ageing' => 'Accounts receivable ageing report attached.',
            default => 'Scheduled report.',
        };
        foreach ($recipients as $email) {
            Mailer::send($email, ucwords(str_replace('_', ' ', $sched['report_type'])) . ' Report', $body);
        }
        $next = Calc::nextScheduleDate($sched['next_run_date'], $sched['frequency'] === 'Daily' ? 'Weekly' : $sched['frequency']);
        if ($sched['frequency'] === 'Daily') $next = date('Y-m-d', strtotime($sched['next_run_date'].' +1 day'));
        Db::run("UPDATE report_schedules SET next_run_date=? WHERE id=? AND tenant_id=?", [$next, $sched['id'], $sched['tenant_id']]);
        $sent++;
    }
    return $sent;
}

// ─── f27: GDPR — data export & right to erasure ─────────────
function route_data_requests(string $m, $id, array $b, array $q, $tid, $uid): never {
    $t = $tid();
    if ($id && $m === 'GET' && ($_GET['action'] ?? '') === 'export') {
        Auth::need('customers', 'read');
        $cust = Db::one("SELECT * FROM customers WHERE id=? AND tenant_id=?", [$id, $t]);
        if (!$cust) throw new NotFound('Customer not found');
        $data = [
            'customer' => $cust,
            'orders' => Db::all("SELECT * FROM sales_orders WHERE customer_id=? AND tenant_id=?", [$id, $t]),
            'invoices' => Db::all("SELECT * FROM invoices WHERE customer_id=? AND tenant_id=?", [$id, $t]),
            'payments' => Db::all("SELECT * FROM payments WHERE customer_id=? AND tenant_id=?", [$id, $t]),
        ];
        Db::run("INSERT INTO data_requests(id,tenant_id,customer_id,type,status,requested_by,completed_at) VALUES(?,?,?,?,?,?,NOW())",
            [Db::uuid(), $t, $id, 'export', 'completed', $uid()]);
        Audit::log('EXPORT', 'customer', $id, $cust['name'], null, ['action' => 'gdpr_export']);
        Http::ok($data);
    }
    if ($id && $m === 'POST' && ($b['action'] ?? '') === 'erasure') {
        Auth::need('customers', 'delete');
        $cust = Db::one("SELECT * FROM customers WHERE id=? AND tenant_id=?", [$id, $t]);
        if (!$cust) throw new NotFound('Customer not found');
        if (Db::val("SELECT COUNT(*) FROM sales_orders WHERE customer_id=? AND tenant_id=? AND status NOT IN('Delivered','Cancelled') AND deleted_at IS NULL", [$id, $t])) {
            throw new Conflict('Cannot erase a customer with active orders. Complete or cancel them first.');
        }
        Db::begin();
        try {
            // Anonymise rather than hard-delete financial records (legal/audit retention),
            // but scrub all personally identifying fields.
            Db::run("UPDATE customers SET name='[ERASED]',contact_name=NULL,email=NULL,phone=NULL,address=NULL,notes=NULL,deleted_at=NOW() WHERE id=? AND tenant_id=?", [$id, $t]);
            Db::run("INSERT INTO data_requests(id,tenant_id,customer_id,type,status,requested_by,completed_at) VALUES(?,?,?,?,?,?,NOW())",
                [Db::uuid(), $t, $id, 'erasure', 'completed', $uid()]);
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }
        Audit::log('DELETE', 'customer', $id, '[ERASED]', null, ['action' => 'gdpr_erasure']);
        Http::ok(['message' => 'Customer data anonymised per GDPR erasure request.']);
    }
    if ($m === 'GET') {
        Auth::need('audit', 'read');
        Http::ok(Db::all("SELECT * FROM data_requests WHERE tenant_id=? ORDER BY created_at DESC", [$t]));
    }
    throw new NotFound('Data request endpoint not found');
}

// ─── f28: SSO — SAML / OAuth scaffold ───────────────────────
// Full SAML assertion validation and OAuth token exchange require a registered
// app with each IdP (Azure AD app registration, Google Cloud OAuth client,
// or your own SAML metadata). This provides the connection/config storage and
// the activation toggle; the actual redirect+callback handlers are provider-
// specific and should be added once you have real IdP credentials to test against.
function route_sso_providers(string $m, $id, array $b, $tid): never {
    $t = $tid();
    Auth::need('settings', 'read');
    if ($m === 'GET') {
        Http::ok(Db::all("SELECT id,provider_type,entity_id,sso_url,client_id,is_active FROM sso_providers WHERE tenant_id=?", [$t]));
    }
    if ($m === 'POST') {
        Auth::need('settings', 'update');
        Validator::check($b, ['provider_type' => 'required|in:saml,oauth_google,oauth_azure']);
        $id2 = Db::uuid();
        Db::run("INSERT INTO sso_providers(id,tenant_id,provider_type,entity_id,sso_url,certificate,client_id,client_secret,is_active)
                 VALUES(?,?,?,?,?,?,?,?,0) ON DUPLICATE KEY UPDATE entity_id=?,sso_url=?,certificate=?,client_id=?,client_secret=?",
            [$id2, $t, $b['provider_type'], $b['entity_id'] ?? null, $b['sso_url'] ?? null, $b['certificate'] ?? null, $b['client_id'] ?? null, $b['client_secret'] ?? null,
             $b['entity_id'] ?? null, $b['sso_url'] ?? null, $b['certificate'] ?? null, $b['client_id'] ?? null, $b['client_secret'] ?? null]);
        Audit::log('CREATE', 'sso_provider', $id2, $b['provider_type']);
        Http::created(['id' => $id2, 'provider_type' => $b['provider_type'], 'status' => 'inactive',
            'note' => 'Configuration saved but not yet activated. Test the connection before setting is_active=1.']);
    }
    if ($m === 'PUT') {
        Auth::need('settings', 'update');
        Db::run("UPDATE sso_providers SET is_active=? WHERE id=? AND tenant_id=?", [(int)($b['is_active'] ?? 0), $id, $t]);
        Http::ok(['message' => 'Updated']);
    }
    throw new Err('Method not allowed', 405);
}

// ═══════════════════════════════════════════════════════════
// FINAL QUICK WINS — f01, f06, f08, f14
// (f02 picking-list and f03 packing-slip data endpoints live in
// index.php's route_orders() as sub-actions, alongside the other
// order sub-actions like approve/split/invoice.)
// ═══════════════════════════════════════════════════════════

// ─── f01: Bulk Order Status Update ──────────────────────────
function route_orders_bulk_status(string $m, array $b, $tid, $uid): never {
    if ($m !== 'POST') throw new Err('Method not allowed', 405);
    Auth::need('orders', 'update');
    $t = $tid();
    Validator::check($b, ['order_ids' => 'required', 'status' => 'required']);
    if (!is_array($b['order_ids']) || empty($b['order_ids'])) throw new Unproc('order_ids must be a non-empty array.');
    if (count($b['order_ids']) > 200) throw new Unproc('Maximum 200 orders per bulk update.');

    $updated = []; $skipped = [];
    Db::begin();
    try {
        foreach ($b['order_ids'] as $orderId) {
            // Re-fetch and re-validate EVERY order individually, scoped to this tenant —
            // never trust a client-supplied ID list without per-row tenant + transition checks.
            $o = Db::one("SELECT * FROM sales_orders WHERE id=? AND tenant_id=? AND deleted_at IS NULL", [$orderId, $t]);
            if (!$o) { $skipped[] = ['id' => $orderId, 'reason' => 'not found']; continue; }
            if ($o['status'] === $b['status']) { $skipped[] = ['id' => $orderId, 'reason' => 'already '.$b['status']]; continue; }
            if (!Calc::canTransition($o['status'], $b['status'])) {
                $skipped[] = ['id' => $orderId, 'reason' => "cannot go {$o['status']} → {$b['status']}"];
                continue;
            }
            $deliveredAt = ($b['status'] === 'Delivered' && !$o['delivered_at']) ? date('Y-m-d H:i:s') : $o['delivered_at'];
            Db::run("UPDATE sales_orders SET status=?,delivered_at=?,updated_by=? WHERE id=? AND tenant_id=?",
                [$b['status'], $deliveredAt, $uid(), $orderId, $t]);
            Audit::log('UPDATE', 'sales_order', $orderId, $o['order_number'], ['status' => $o['status']], ['status' => $b['status']]);
            $updated[] = ['id' => $orderId, 'order_number' => $o['order_number']];
        }
        Db::commit();
    } catch (Throwable $e) { Db::rollback(); throw $e; }

    Http::ok(['updated' => $updated, 'skipped' => $skipped, 'updated_count' => count($updated), 'skipped_count' => count($skipped)]);
}

// ─── f08: Dashboard Date Range ───────────────────────────────
// The existing GET /dashboard handler in index.php already accepts no params;
// this widens it to accept ?from=&to= so the same endpoint serves both the
// default (last 30 days) and any custom range the frontend's date picker sends.
// See the dashboard route in index.php for the actual query — this function
// is the shared date-range resolver both routes call so the logic lives once.
function dos_resolve_date_range(array $q): array {
    $to = $q['to'] ?? date('Y-m-d');
    $from = $q['from'] ?? date('Y-m-d', strtotime('-30 days'));
    // Guard against an inverted or absurd range rather than letting a bad
    // query silently return zero rows or scan the whole table.
    if (strtotime($from) > strtotime($to)) { [$from, $to] = [$to, $from]; }
    if (strtotime($to) - strtotime($from) > 86400 * 730) { $from = date('Y-m-d', strtotime($to.' -730 days')); }
    return [$from, $to];
}

// ─── f14: Delivery Scheduling & Route Planning ──────────────
function route_drivers(string $m, $id, array $b, $tid): never {
    $t = $tid();
    Auth::need('orders', 'read');
    if ($m === 'GET') Http::ok(Db::all("SELECT * FROM drivers WHERE tenant_id=? ORDER BY name", [$t]));
    if ($m === 'POST') {
        Auth::need('orders', 'update');
        Validator::check($b, ['name' => 'required|max:150']);
        $id2 = Db::uuid();
        Db::run("INSERT INTO drivers(id,tenant_id,name,phone,is_active) VALUES(?,?,?,?,1)", [$id2, $t, $b['name'], $b['phone'] ?? null]);
        Http::created(Db::one("SELECT * FROM drivers WHERE id=? AND tenant_id=?", [$id2, $t]));
    }
    if ($m === 'PUT') {
        Auth::need('orders', 'update');
        Db::run("UPDATE drivers SET name=?,phone=?,is_active=? WHERE id=? AND tenant_id=?",
            [$b['name'] ?? '', $b['phone'] ?? null, (int)($b['is_active'] ?? 1), $id, $t]);
        Http::ok(['message' => 'Updated']);
    }
    if ($m === 'DELETE') { Auth::need('orders', 'update'); Db::run("DELETE FROM drivers WHERE id=? AND tenant_id=?", [$id, $t]); Http::noContent(); }
    throw new Err('Method not allowed', 405);
}

function route_vehicles(string $m, $id, array $b, $tid): never {
    $t = $tid();
    Auth::need('orders', 'read');
    if ($m === 'GET') Http::ok(Db::all("SELECT * FROM vehicles WHERE tenant_id=? ORDER BY name", [$t]));
    if ($m === 'POST') {
        Auth::need('orders', 'update');
        Validator::check($b, ['name' => 'required|max:100']);
        $id2 = Db::uuid();
        Db::run("INSERT INTO vehicles(id,tenant_id,name,capacity_note,is_active) VALUES(?,?,?,?,1)", [$id2, $t, $b['name'], $b['capacity_note'] ?? null]);
        Http::created(Db::one("SELECT * FROM vehicles WHERE id=? AND tenant_id=?", [$id2, $t]));
    }
    if ($m === 'DELETE') { Auth::need('orders', 'update'); Db::run("DELETE FROM vehicles WHERE id=? AND tenant_id=?", [$id, $t]); Http::noContent(); }
    throw new Err('Method not allowed', 405);
}

function route_delivery_runs(string $m, $id, $sub, array $b, array $q, $tid, $uid): never {
    $t = $tid();
    Auth::need('orders', 'read');

    // POST /delivery-runs/{id}/stops — add an order as a stop on a run
    if ($id && $sub === 'stops' && $m === 'POST') {
        Auth::need('orders', 'update');
        Validator::check($b, ['order_id' => 'required']);
        $run = Db::one("SELECT * FROM delivery_runs WHERE id=? AND tenant_id=?", [$id, $t]);
        if (!$run) throw new NotFound('Delivery run not found');
        $order = Db::one("SELECT * FROM sales_orders WHERE id=? AND tenant_id=? AND deleted_at IS NULL", [$b['order_id'], $t]);
        if (!$order) throw new NotFound('Order not found');
        $seq = (int) Db::val("SELECT COALESCE(MAX(stop_sequence),0)+1 FROM delivery_stops WHERE delivery_run_id=? AND tenant_id=?", [$id, $t]);
        $stopId = Db::uuid();
        Db::run("INSERT INTO delivery_stops(id,tenant_id,delivery_run_id,order_id,stop_sequence,delivery_window) VALUES(?,?,?,?,?,?)",
            [$stopId, $t, $id, $b['order_id'], $seq, $b['delivery_window'] ?? null]);
        Http::created(['id' => $stopId, 'stop_sequence' => $seq]);
    }
    // PUT /delivery-runs/{id}/stops/{stopId} — mark a stop delivered/failed
    if ($id && $sub && str_starts_with($sub, 'stops/') && $m === 'PUT') {
        Auth::need('orders', 'update');
        $stopId = substr($sub, 6);
        $stop = Db::one("SELECT * FROM delivery_stops WHERE id=? AND tenant_id=? AND delivery_run_id=?", [$stopId, $t, $id]);
        if (!$stop) throw new NotFound('Stop not found');
        $status = $b['status'] ?? $stop['status'];
        $deliveredAt = $status === 'Delivered' ? date('Y-m-d H:i:s') : $stop['delivered_at'];
        Db::run("UPDATE delivery_stops SET status=?,delivered_at=?,notes=? WHERE id=? AND tenant_id=?",
            [$status, $deliveredAt, $b['notes'] ?? $stop['notes'], $stopId, $t]);
        if ($status === 'Delivered') {
            Db::run("UPDATE sales_orders SET status='Delivered',delivered_at=? WHERE id=? AND tenant_id=? AND status='Shipped'", [$deliveredAt, $stop['order_id'], $t]);
        }
        Http::ok(['message' => 'Stop updated']);
    }

    switch ($m) {
        case 'GET':
            if ($id) {
                $run = Db::one("SELECT dr.*,d.name driver_name,v.name vehicle_name FROM delivery_runs dr LEFT JOIN drivers d ON d.id=dr.driver_id LEFT JOIN vehicles v ON v.id=dr.vehicle_id WHERE dr.id=? AND dr.tenant_id=?", [$id, $t]);
                if (!$run) throw new NotFound();
                $stops = Db::all("SELECT ds.*,so.order_number,c.name customer_name,c.address,c.city FROM delivery_stops ds JOIN sales_orders so ON so.id=ds.order_id JOIN customers c ON c.id=so.customer_id WHERE ds.delivery_run_id=? AND ds.tenant_id=? ORDER BY ds.stop_sequence", [$id, $t]);
                Http::ok(['run' => $run, 'stops' => $stops]);
            }
            $date = $q['date'] ?? date('Y-m-d');
            Http::ok(Db::all("SELECT dr.*,d.name driver_name,v.name vehicle_name,COUNT(ds.id) stop_count FROM delivery_runs dr LEFT JOIN drivers d ON d.id=dr.driver_id LEFT JOIN vehicles v ON v.id=dr.vehicle_id LEFT JOIN delivery_stops ds ON ds.delivery_run_id=dr.id WHERE dr.tenant_id=? AND dr.run_date=? GROUP BY dr.id ORDER BY dr.created_at", [$t, $date]));
        case 'POST':
            Auth::need('orders', 'update');
            Validator::check($b, ['run_date' => 'required']);
            $id2 = Db::uuid();
            Db::run("INSERT INTO delivery_runs(id,tenant_id,run_date,driver_id,vehicle_id,status,notes,created_by) VALUES(?,?,?,?,?,'Planned',?,?)",
                [$id2, $t, $b['run_date'], $b['driver_id'] ?? null, $b['vehicle_id'] ?? null, $b['notes'] ?? null, $uid()]);
            Audit::log('CREATE', 'delivery_run', $id2, $b['run_date']);
            Http::created(Db::one("SELECT * FROM delivery_runs WHERE id=? AND tenant_id=?", [$id2, $t]));
        case 'PUT':
            Auth::need('orders', 'update');
            $run = Db::one("SELECT * FROM delivery_runs WHERE id=? AND tenant_id=?", [$id, $t]);
            if (!$run) throw new NotFound();
            Db::run("UPDATE delivery_runs SET status=?,driver_id=?,vehicle_id=?,notes=? WHERE id=? AND tenant_id=?",
                [$b['status'] ?? $run['status'], $b['driver_id'] ?? $run['driver_id'], $b['vehicle_id'] ?? $run['vehicle_id'], $b['notes'] ?? $run['notes'], $id, $t]);
            Http::ok(['message' => 'Updated']);
        case 'DELETE':
            Auth::need('orders', 'update');
            Db::run("DELETE FROM delivery_runs WHERE id=? AND tenant_id=?", [$id, $t]);
            Http::noContent();
        default: throw new Err('Method not allowed', 405);
    }
}

// ═══════════════════════════════════════════════════════════
// CZIUM DISTRIBUTION — PHASE 2 API ROUTES
// Areas, Sales Reps, Raw Materials, Production, Distributors
// ═══════════════════════════════════════════════════════════

// ─── AREAS ────────────────────────────────────────────────
function route_areas(string $m, $id, array $b, array $q, $tid, $uid): never {
    $t = $tid(); Auth::need('areas', 'read');
    if ($m === 'GET') {
        if ($id) {
            $area = Db::one("SELECT * FROM areas WHERE id=? AND tenant_id=?", [$id, $t]);
            if (!$area) throw new NotFound('Area not found');
            // Sales summary for this area
            [$from, $to] = dos_resolve_date_range($q);
            $sales = Db::one("SELECT COUNT(*) orders_count, COALESCE(SUM(total_amount),0) revenue,
                COALESCE(SUM(CASE WHEN payment_mode='cash' THEN total_amount ELSE 0 END),0) cash_sales,
                COALESCE(SUM(CASE WHEN payment_mode='credit' THEN total_amount ELSE 0 END),0) credit_sales
                FROM sales_orders WHERE area_id=? AND tenant_id=? AND status NOT IN('Cancelled','Draft')
                AND order_date BETWEEN ? AND ?", [$id, $t, $from, $to]);
            $top_products = Db::all("SELECT p.name, p.sku, SUM(soi.qty_ordered) units, SUM(soi.line_total) revenue
                FROM sales_order_items soi JOIN products p ON p.id=soi.product_id
                JOIN sales_orders so ON so.id=soi.order_id
                WHERE so.area_id=? AND so.tenant_id=? AND so.order_date BETWEEN ? AND ?
                GROUP BY p.id ORDER BY units DESC LIMIT 10", [$id, $t, $from, $to]);
            Http::ok(['area' => $area, 'sales' => $sales, 'top_products' => $top_products, 'range' => ['from' => $from, 'to' => $to]]);
        }
        Http::ok(Db::all("SELECT a.*, COUNT(DISTINCT c.id) customer_count
            FROM areas a LEFT JOIN customers c ON c.area_id=a.id AND c.deleted_at IS NULL
            WHERE a.tenant_id=? GROUP BY a.id ORDER BY a.name", [$t]));
    }
    if ($m === 'POST') {
        Auth::need('areas', 'update');
        Validator::check($b, ['name' => 'required|max:100']);
        $id2 = Db::uuid();
        Db::run("INSERT INTO areas(id,tenant_id,name,district,is_active) VALUES(?,?,?,?,1)",
            [$id2, $t, $b['name'], $b['district'] ?? null]);
        Http::created(Db::one("SELECT * FROM areas WHERE id=? AND tenant_id=?", [$id2, $t]));
    }
    if ($m === 'PUT') {
        Auth::need('areas', 'update');
        Db::run("UPDATE areas SET name=?,district=?,is_active=? WHERE id=? AND tenant_id=?",
            [$b['name'] ?? '', $b['district'] ?? null, (int)($b['is_active'] ?? 1), $id, $t]);
        Http::ok(['message' => 'Updated']);
    }
    if ($m === 'DELETE') {
        Auth::need('areas', 'update');
        Db::run("DELETE FROM areas WHERE id=? AND tenant_id=?", [$id, $t]);
        Http::noContent();
    }
    throw new Err('Method not allowed', 405);
}

// ─── AREA COMPARISON (for analytics page) ─────────────────
function route_area_analytics(string $m, array $q, $tid): never {
    if ($m !== 'GET') throw new Err('Method not allowed', 405);
    Auth::need('areas', 'read');
    $t = $tid();
    [$from, $to] = dos_resolve_date_range($q);
    $areas = Db::all("SELECT a.id, a.name,
        COALESCE(SUM(so.total_amount),0) revenue,
        COUNT(DISTINCT so.id) orders_count,
        COALESCE(SUM(CASE WHEN so.payment_mode='cash' THEN so.total_amount ELSE 0 END),0) cash_sales,
        COALESCE(SUM(CASE WHEN so.payment_mode='credit' THEN so.total_amount ELSE 0 END),0) credit_sales,
        COUNT(DISTINCT so.rep_id) reps_active
        FROM areas a
        LEFT JOIN sales_orders so ON so.area_id=a.id AND so.tenant_id=a.tenant_id
            AND so.status NOT IN('Cancelled','Draft') AND so.order_date BETWEEN ? AND ?
        WHERE a.tenant_id=? AND a.is_active=1
        GROUP BY a.id ORDER BY revenue DESC", [$from, $to, $t]);
    // Product-wise breakdown across all areas
    $products = Db::all("SELECT p.name, p.sku, p.product_category,
        SUM(soi.qty_ordered) units_sold, SUM(soi.line_total) revenue
        FROM sales_order_items soi
        JOIN products p ON p.id=soi.product_id AND p.tenant_id=?
        JOIN sales_orders so ON so.id=soi.order_id AND so.tenant_id=?
            AND so.status NOT IN('Cancelled','Draft') AND so.order_date BETWEEN ? AND ?
        GROUP BY p.id ORDER BY units_sold DESC LIMIT 20", [$t, $t, $from, $to]);
    Http::ok(['areas' => $areas, 'products' => $products, 'range' => ['from' => $from, 'to' => $to]]);
}

// ─── SALES REPS ────────────────────────────────────────────
function route_sales_reps(string $m, $id, $sub, array $b, array $q, $tid, $uid): never {
    $t = $tid(); Auth::need('reps', 'read');
    if ($id && $sub === 'performance' && $m === 'GET') {
        [$from, $to] = dos_resolve_date_range($q);
        $rep = Db::one("SELECT * FROM sales_reps WHERE id=? AND tenant_id=?", [$id, $t]);
        if (!$rep) throw new NotFound('Rep not found');
        $sales = Db::one("SELECT COUNT(*) orders_count, COALESCE(SUM(total_amount),0) revenue,
            COALESCE(SUM(CASE WHEN payment_mode='cash' THEN total_amount ELSE 0 END),0) cash_sales,
            COALESCE(SUM(CASE WHEN payment_mode='credit' THEN total_amount ELSE 0 END),0) credit_sales
            FROM sales_orders WHERE rep_id=? AND tenant_id=? AND status NOT IN('Cancelled','Draft')
            AND order_date BETWEEN ? AND ?", [$id, $t, $from, $to]);
        $target = Db::one("SELECT target_amount, achieved_amount FROM rep_targets
            WHERE rep_id=? AND period_year=YEAR(CURDATE()) AND period_month=MONTH(CURDATE())", [$id]);
        $collections = Db::all("SELECT * FROM rep_collections WHERE rep_id=? AND tenant_id=?
            AND collection_date BETWEEN ? AND ? ORDER BY collection_date DESC", [$id, $t, $from, $to]);
        $top_areas = Db::all("SELECT a.name, COUNT(so.id) orders, SUM(so.total_amount) revenue
            FROM sales_orders so LEFT JOIN areas a ON a.id=so.area_id
            WHERE so.rep_id=? AND so.tenant_id=? AND so.order_date BETWEEN ? AND ?
            GROUP BY so.area_id ORDER BY revenue DESC LIMIT 5", [$id, $t, $from, $to]);
        Http::ok(compact('rep', 'sales', 'target', 'collections', 'top_areas') + ['range' => ['from' => $from, 'to' => $to]]);
    }
    if ($id && $sub === 'collections' && $m === 'POST') {
        Auth::need('orders', 'update');
        Validator::check($b, ['collection_date' => 'required']);
        $id2 = Db::uuid();
        Db::run("INSERT INTO rep_collections(id,tenant_id,rep_id,collection_date,cash_amount,credit_amount,collection_amount,orders_count,notes)
            VALUES(?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE cash_amount=VALUES(cash_amount),credit_amount=VALUES(credit_amount),
            collection_amount=VALUES(collection_amount),orders_count=VALUES(orders_count),notes=VALUES(notes)",
            [$id2,$t,$id,$b['collection_date'],(float)($b['cash_amount']??0),(float)($b['credit_amount']??0),
            (float)($b['collection_amount']??0),(int)($b['orders_count']??0),$b['notes']??null]);
        Http::created(['message' => 'Collection recorded']);
    }
    if ($id && $sub === 'targets' && $m === 'POST') {
        Auth::need('reps', 'update');
        Validator::check($b, ['target_amount' => 'required', 'period_year' => 'required', 'period_month' => 'required']);
        $id2 = Db::uuid();
        Db::run("INSERT INTO rep_targets(id,tenant_id,rep_id,period_year,period_month,target_amount,achieved_amount)
            VALUES(?,?,?,?,?,?,0)
            ON DUPLICATE KEY UPDATE target_amount=VALUES(target_amount)",
            [$id2,$t,$id,(int)$b['period_year'],(int)$b['period_month'],(float)$b['target_amount']]);
        Http::created(['message' => 'Target set']);
    }
    switch ($m) {
        case 'GET':
            if ($id) Http::ok(Db::one("SELECT r.*, u.email rep_email FROM sales_reps r LEFT JOIN users u ON u.id=r.user_id WHERE r.id=? AND r.tenant_id=?", [$id, $t]));
            Http::ok(Db::all("SELECT r.*, u.email rep_email,
                (SELECT COUNT(*) FROM sales_orders so WHERE so.rep_id=r.id AND so.order_date=CURDATE()) today_orders,
                (SELECT COALESCE(SUM(total_amount),0) FROM sales_orders so WHERE so.rep_id=r.id AND so.order_date=CURDATE()) today_sales,
                t.target_amount, t.achieved_amount
                FROM sales_reps r LEFT JOIN users u ON u.id=r.user_id
                LEFT JOIN rep_targets t ON t.rep_id=r.id AND t.period_year=YEAR(CURDATE()) AND t.period_month=MONTH(CURDATE())
                WHERE r.tenant_id=? ORDER BY r.name", [$t]));
        case 'POST':
            Auth::need('reps', 'update');
            Validator::check($b, ['name' => 'required|max:150']);
            $id2 = Db::uuid();
            Db::run("INSERT INTO sales_reps(id,tenant_id,user_id,name,phone,route_name,is_active) VALUES(?,?,?,?,?,?,1)",
                [$id2,$t,$b['user_id']??null,$b['name'],$b['phone']??null,$b['route_name']??null]);
            Http::created(Db::one("SELECT * FROM sales_reps WHERE id=? AND tenant_id=?", [$id2, $t]));
        case 'PUT':
            Auth::need('reps', 'update');
            Db::run("UPDATE sales_reps SET name=?,phone=?,route_name=?,is_active=? WHERE id=? AND tenant_id=?",
                [$b['name']??'',$b['phone']??null,$b['route_name']??null,(int)($b['is_active']??1),$id,$t]);
            Http::ok(['message' => 'Updated']);
        case 'DELETE':
            Auth::need('reps', 'update');
            Db::run("UPDATE sales_reps SET is_active=0 WHERE id=? AND tenant_id=?", [$id, $t]);
            Http::noContent();
        default: throw new Err('Method not allowed', 405);
    }
}

// ─── RAW MATERIALS ─────────────────────────────────────────
function route_raw_materials(string $m, $id, $sub, array $b, array $q, $tid, $uid): never {
    $t = $tid(); Auth::need('inventory', 'read');
    if ($id && $sub === 'receive' && $m === 'POST') {
        Auth::need('inventory', 'update');
        Validator::check($b, ['qty' => 'required', 'unit_cost' => 'required']);
        $qty = (float)$b['qty'];
        Db::run("UPDATE raw_materials SET current_stock=current_stock+?, cost_per_unit=? WHERE id=? AND tenant_id=?",
            [$qty, (float)$b['unit_cost'], $id, $t]);
        Db::run("INSERT INTO raw_material_movements(id,tenant_id,material_id,type,qty,unit_cost,reference_type,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?)",
            [Db::uuid(),$t,$id,'IN',$qty,(float)$b['unit_cost'],'purchase_order',$b['notes']??null,$uid()]);
        Http::ok(['message' => 'Stock received', 'new_stock' => Db::val("SELECT current_stock FROM raw_materials WHERE id=? AND tenant_id=?", [$id, $t])]);
    }
    switch ($m) {
        case 'GET':
            if ($id) {
                $mat = Db::one("SELECT * FROM raw_materials WHERE id=? AND tenant_id=?", [$id, $t]);
                if (!$mat) throw new NotFound('Material not found');
                $movements = Db::all("SELECT * FROM raw_material_movements WHERE material_id=? AND tenant_id=? ORDER BY created_at DESC LIMIT 30", [$id, $t]);
                Http::ok(['material' => $mat, 'movements' => $movements]);
            }
            $low = !empty($q['low_stock']);
            $sql = "SELECT m.*, s.name supplier_name FROM raw_materials m LEFT JOIN suppliers s ON s.id=m.supplier_id WHERE m.tenant_id=?"
                . ($low ? " AND m.current_stock <= m.reorder_point" : "") . " ORDER BY m.name";
            Http::ok(Db::all($sql, [$t]));
        case 'POST':
            Auth::need('inventory', 'update');
            Validator::check($b, ['name' => 'required|max:200', 'unit' => 'required']);
            $id2 = Db::uuid();
            Db::run("INSERT INTO raw_materials(id,tenant_id,name,unit,current_stock,reorder_point,cost_per_unit,supplier_id,is_active) VALUES(?,?,?,?,?,?,?,?,1)",
                [$id2,$t,$b['name'],$b['unit'],(float)($b['current_stock']??0),(float)($b['reorder_point']??0),(float)($b['cost_per_unit']??0),$b['supplier_id']??null]);
            Http::created(Db::one("SELECT * FROM raw_materials WHERE id=? AND tenant_id=?", [$id2, $t]));
        case 'PUT':
            Auth::need('inventory', 'update');
            Db::run("UPDATE raw_materials SET name=?,unit=?,reorder_point=?,cost_per_unit=?,supplier_id=?,is_active=? WHERE id=? AND tenant_id=?",
                [$b['name']??'',$b['unit']??'kg',(float)($b['reorder_point']??0),(float)($b['cost_per_unit']??0),$b['supplier_id']??null,(int)($b['is_active']??1),$id,$t]);
            Http::ok(['message' => 'Updated']);
        default: throw new Err('Method not allowed', 405);
    }
}

// ─── PRODUCTION BATCHES ────────────────────────────────────
function route_production(string $m, $id, $sub, array $b, array $q, $tid, $uid): never {
    $t = $tid(); Auth::need('production', 'read');

    if ($id && $sub === 'start' && $m === 'PUT') {
        Auth::need('inventory', 'update');
        $batch = Db::one("SELECT * FROM production_batches WHERE id=? AND tenant_id=?", [$id, $t]);
        if (!$batch) throw new NotFound('Batch not found');
        if ($batch['status'] !== 'Planned') throw new Unproc("Batch is '{$batch['status']}', cannot start.");
        Db::run("UPDATE production_batches SET status='In Progress' WHERE id=? AND tenant_id=?", [$id, $t]);
        Http::ok(['message' => 'Batch started']);
    }

    if ($id && $sub === 'complete' && $m === 'PUT') {
        Auth::need('inventory', 'update');
        $batch = Db::one("SELECT * FROM production_batches WHERE id=? AND tenant_id=?", [$id, $t]);
        if (!$batch) throw new NotFound('Batch not found');
        if ($batch['status'] !== 'In Progress') throw new Unproc("Batch must be In Progress to complete.");
        $actualQty = (float)($b['actual_qty'] ?? $batch['planned_qty']);
        Db::begin();
        try {
            // Deduct raw materials
            $ingredients = Db::all("SELECT bi.*, rm.name mat_name FROM batch_ingredients bi
                JOIN raw_materials rm ON rm.id=bi.material_id
                WHERE bi.batch_id=? AND bi.tenant_id=?", [$id, $t]);
            foreach ($ingredients as $ing) {
                $used = (float)($ing['actual_qty'] ?? $ing['required_qty']);
                Db::run("UPDATE raw_materials SET current_stock=GREATEST(0,current_stock-?) WHERE id=? AND tenant_id=?",
                    [$used, $ing['material_id'], $t]);
                Db::run("INSERT INTO raw_material_movements(id,tenant_id,material_id,type,qty,reference_type,reference_id,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?)",
                    [Db::uuid(),$t,$ing['material_id'],'OUT',$used,'production_batch',$id,"Used in {$batch['batch_number']}",$uid()]);
            }
            // Add finished goods to inventory
            $warehouseId = $batch['warehouse_id'] ?? Db::val("SELECT id FROM warehouses WHERE tenant_id=? LIMIT 1", [$t]);
            if (!$warehouseId) throw new Unproc('No warehouse available — create a warehouse before completing production batches.');
            $existing = Db::val("SELECT id FROM inventory WHERE product_id=? AND warehouse_id=? AND tenant_id=?",
                [$batch['product_id'], $warehouseId, $t]);
            if ($existing) {
                Db::run("UPDATE inventory SET qty_on_hand=qty_on_hand+?, qty_available=qty_available+? WHERE product_id=? AND warehouse_id=? AND tenant_id=?",
                    [$actualQty, $actualQty, $batch['product_id'], $warehouseId, $t]);
            } else {
                Db::run("INSERT INTO inventory(id,tenant_id,product_id,warehouse_id,qty_on_hand,qty_reserved,qty_available) VALUES(?,?,?,?,?,0,?)",
                    [Db::uuid(),$t,$batch['product_id'],$warehouseId,$actualQty,$actualQty]);
            }
            Db::run("UPDATE production_batches SET status='Completed',actual_qty=?,completed_at=NOW() WHERE id=? AND tenant_id=?",
                [$actualQty, $id, $t]);
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }
        Audit::log('UPDATE','production_batch',$id,$batch['batch_number'],['status'=>'In Progress'],['status'=>'Completed','actual_qty'=>$actualQty]);
        Http::ok(['message' => 'Batch completed. '.$actualQty.' units added to inventory.']);
    }

    switch ($m) {
        case 'GET':
            if ($id) {
                $batch = Db::one("SELECT pb.*, p.name product_name, p.sku FROM production_batches pb JOIN products p ON p.id=pb.product_id WHERE pb.id=? AND pb.tenant_id=?", [$id, $t]);
                if (!$batch) throw new NotFound('Batch not found');
                $ingredients = Db::all("SELECT bi.*, rm.name mat_name, rm.unit, rm.current_stock FROM batch_ingredients bi JOIN raw_materials rm ON rm.id=bi.material_id WHERE bi.batch_id=? AND bi.tenant_id=?", [$id, $t]);
                Http::ok(['batch' => $batch, 'ingredients' => $ingredients]);
            }
            [$from, $to] = dos_resolve_date_range($q);
            $status = $q['status'] ?? '';
            $sql = "SELECT pb.*, p.name product_name, p.sku FROM production_batches pb JOIN products p ON p.id=pb.product_id WHERE pb.tenant_id=? AND pb.production_date BETWEEN ? AND ?";
            $params = [$t, $from, $to];
            if ($status) { $sql .= " AND pb.status=?"; $params[] = $status; }
            Http::ok(Db::all($sql . " ORDER BY pb.production_date DESC, pb.created_at DESC", $params));
        case 'POST':
            Auth::need('inventory', 'update');
            Validator::check($b, ['product_id' => 'required', 'planned_qty' => 'required', 'production_date' => 'required']);
            $batchNum = 'BTH-' . date('Y') . '-' . str_pad((int)Db::val("SELECT COUNT(*)+1 FROM production_batches WHERE tenant_id=? AND YEAR(created_at)=YEAR(NOW())", [$t]), 4, '0', STR_PAD_LEFT);
            $id2 = Db::uuid();
            Db::run("INSERT INTO production_batches(id,tenant_id,batch_number,product_id,warehouse_id,planned_qty,production_date,expiry_date,notes,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,'Planned',?)",
                [$id2,$t,$batchNum,$b['product_id'],$b['warehouse_id']??null,(float)$b['planned_qty'],$b['production_date'],$b['expiry_date']??null,$b['notes']??null,$uid()]);
            // Auto-fill recipe ingredients
            $recipe = Db::all("SELECT * FROM product_recipes WHERE product_id=? AND tenant_id=?", [$b['product_id'], $t]);
            foreach ($recipe as $r) {
                Db::run("INSERT INTO batch_ingredients(id,tenant_id,batch_id,material_id,required_qty,unit) VALUES(?,?,?,?,?,?)",
                    [Db::uuid(),$t,$id2,$r['material_id'],$r['qty_per_batch'],$r['unit']]);
            }
            Http::created(Db::one("SELECT pb.*, p.name product_name FROM production_batches pb JOIN products p ON p.id=pb.product_id WHERE pb.id=? AND pb.tenant_id=?", [$id2, $t]));
        case 'PUT':
            Auth::need('inventory', 'update');
            $batch = Db::one("SELECT * FROM production_batches WHERE id=? AND tenant_id=?", [$id, $t]);
            if (!$batch) throw new NotFound();
            if ($batch['status'] === 'Completed') throw new Unproc('Cannot edit a completed batch.');
            Db::run("UPDATE production_batches SET notes=?,expiry_date=?,planned_qty=? WHERE id=? AND tenant_id=?",
                [$b['notes']??$batch['notes'],$b['expiry_date']??$batch['expiry_date'],(float)($b['planned_qty']??$batch['planned_qty']),$id,$t]);
            Http::ok(['message' => 'Updated']);
        default: throw new Err('Method not allowed', 405);
    }
}

// ─── DISTRIBUTORS ──────────────────────────────────────────
function route_distributors(string $m, $id, $sub, array $b, array $q, $tid, $uid): never {
    $t = $tid(); Auth::need('distributors', 'read');
    if ($id && $sub === 'stock' && $m === 'POST') {
        Auth::need('inventory', 'update');
        Validator::check($b, ['product_id' => 'required', 'snapshot_date' => 'required']);
        $id2 = Db::uuid();
        Db::run("INSERT INTO distributor_stock(id,tenant_id,distributor_id,product_id,snapshot_date,opening_qty,received_qty,sold_qty,closing_qty) VALUES(?,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE opening_qty=VALUES(opening_qty),received_qty=VALUES(received_qty),sold_qty=VALUES(sold_qty),closing_qty=VALUES(closing_qty)",
            [$id2,$t,$id,$b['product_id'],$b['snapshot_date'],(float)($b['opening_qty']??0),(float)($b['received_qty']??0),(float)($b['sold_qty']??0),(float)($b['closing_qty']??0)]);
        Http::created(['message' => 'Stock updated']);
    }
    switch ($m) {
        case 'GET':
            if ($id) {
                $dist = Db::one("SELECT d.*, a.name area_name FROM distributors d LEFT JOIN areas a ON a.id=d.area_id WHERE d.id=? AND d.tenant_id=?", [$id, $t]);
                if (!$dist) throw new NotFound('Distributor not found');
                $outstanding = Db::val("SELECT COALESCE(SUM(i.total_amount-i.paid_amount),0) FROM invoices i JOIN sales_orders so ON so.id=i.order_id WHERE so.distributor_id=? AND i.tenant_id=? AND i.status NOT IN('Paid','Cancelled')", [$id, $t]) ?? 0;
                $stock = Db::all("SELECT ds.*, p.name product_name, p.sku FROM distributor_stock ds JOIN products p ON p.id=ds.product_id WHERE ds.distributor_id=? AND ds.tenant_id=? ORDER BY ds.snapshot_date DESC LIMIT 30", [$id, $t]);
                Http::ok(['distributor' => $dist, 'outstanding' => $outstanding, 'stock' => $stock]);
            }
            Http::ok(Db::all("SELECT d.*, a.name area_name,
                COALESCE((SELECT SUM(i.total_amount-i.paid_amount) FROM invoices i JOIN sales_orders so ON so.id=i.order_id WHERE so.distributor_id=d.id AND i.tenant_id=d.tenant_id AND i.status NOT IN('Paid','Cancelled')),0) outstanding
                FROM distributors d LEFT JOIN areas a ON a.id=d.area_id WHERE d.tenant_id=? AND d.is_active=1 ORDER BY d.name", [$t]));
        case 'POST':
            Auth::need('distributors', 'update');
            Validator::check($b, ['name' => 'required|max:200']);
            $id2 = Db::uuid();
            Db::run("INSERT INTO distributors(id,tenant_id,name,contact_name,phone,email,address,area_id,credit_limit,is_active) VALUES(?,?,?,?,?,?,?,?,?,1)",
                [$id2,$t,$b['name'],$b['contact_name']??null,$b['phone']??null,$b['email']??null,$b['address']??null,$b['area_id']??null,(float)($b['credit_limit']??0)]);
            Http::created(Db::one("SELECT * FROM distributors WHERE id=? AND tenant_id=?", [$id2, $t]));
        case 'PUT':
            Auth::need('distributors', 'update');
            Db::run("UPDATE distributors SET name=?,contact_name=?,phone=?,email=?,address=?,area_id=?,credit_limit=?,is_active=? WHERE id=? AND tenant_id=?",
                [$b['name']??'',$b['contact_name']??null,$b['phone']??null,$b['email']??null,$b['address']??null,$b['area_id']??null,(float)($b['credit_limit']??0),(int)($b['is_active']??1),$id,$t]);
            Http::ok(['message' => 'Updated']);
        case 'DELETE':
            Auth::need('distributors', 'update');
            Db::run("UPDATE distributors SET is_active=0 WHERE id=? AND tenant_id=?", [$id, $t]);
            Http::noContent();
        default: throw new Err('Method not allowed', 405);
    }
}

// ─── WHATSAPP INVOICE LINK ─────────────────────────────────
function route_whatsapp_invoice(string $m, $id, $tid): never {
    if ($m !== 'GET') throw new Err('Method not allowed', 405);
    Auth::need('orders', 'read');
    $t = $tid();
    $order = Db::one("SELECT so.*, c.name customer_name, c.phone customer_phone FROM sales_orders so JOIN customers c ON c.id=so.customer_id WHERE so.id=? AND so.tenant_id=?", [$id, $t]);
    if (!$order) throw new NotFound('Order not found');
    $items = Db::all("SELECT p.name, soi.qty_ordered, soi.unit_price, soi.line_total FROM sales_order_items soi JOIN products p ON p.id=soi.product_id WHERE soi.order_id=? AND soi.tenant_id=?", [$id, $t]);
    $msg  = "*CZium Distribution — Invoice*\n";
    $msg .= "Order: {$order['order_number']}\n";
    $msg .= "Customer: {$order['customer_name']}\n";
    $msg .= "Date: {$order['order_date']}\n\n";
    $msg .= "*Items:*\n";
    foreach ($items as $i) {
        $msg .= "• {$i['name']} x{$i['qty_ordered']} @ Rs.{$i['unit_price']} = Rs.{$i['line_total']}\n";
    }
    $msg .= "\n*Total: Rs." . number_format($order['total_amount'], 2) . "*\n";
    $msg .= "Payment: " . ucfirst($order['payment_mode'] ?? 'credit') . "\n";
    $waLink = 'https://wa.me/' . preg_replace('/[^0-9]/', '', $order['customer_phone'] ?? '') . '?text=' . rawurlencode($msg);
    Http::ok(['whatsapp_url' => $waLink, 'message_preview' => $msg, 'customer_phone' => $order['customer_phone']]);
}
