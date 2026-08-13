<?php
// ═══════════════════════════════════════════════════════════
// CZium Distribution — routes_v5.php
// Completion release: quotations, expenses, GRN, stock adjustments,
// rep visits, targets, accounts, reports, PDF + public share links.
//
// Every query is tenant-scoped. Rep row-level scoping is applied via
// Scope:: helpers wherever a rep could otherwise see another rep's data.
// ═══════════════════════════════════════════════════════════

/** Next sequential document number for a tenant, e.g. QT-2026-0007. */
function czium_next_number(string $table, string $col, string $prefix, string $tenant): string {
    $year = date('Y');
    $like = "$prefix-$year-%";
    $max  = Db::val(
        "SELECT MAX(CAST(SUBSTRING_INDEX(`$col`,'-',-1) AS UNSIGNED))
         FROM `$table` WHERE tenant_id=? AND `$col` LIKE ?",
        [$tenant, $like]
    );
    return sprintf('%s-%s-%04d', $prefix, $year, ((int)$max) + 1);
}

/** Recalculate and persist quotation totals from its items. */
function czium_recalc_quote(string $qid, string $tenant): array {
    $rows = Db::all("SELECT qty,unit_price,discount_pct,tax_pct FROM quotation_items WHERE quotation_id=? AND tenant_id=?", [$qid, $tenant]);
    $sub = $disc = $tax = 0.0;
    foreach ($rows as $r) {
        $gross = (float)$r['qty'] * (float)$r['unit_price'];
        $d     = $gross * ((float)$r['discount_pct'] / 100);
        $net   = $gross - $d;
        $sub  += $gross;
        $disc += $d;
        $tax  += $net * ((float)$r['tax_pct'] / 100);
    }
    $total = $sub - $disc + $tax;
    Db::run("UPDATE quotations SET subtotal=?,discount_amount=?,tax_amount=?,total_amount=? WHERE id=? AND tenant_id=?",
            [$sub, $disc, $tax, $total, $qid, $tenant]);
    return compact('sub', 'disc', 'tax', 'total');
}

// ═══════════════════════════════════════════════════════════
// QUOTATIONS
// ═══════════════════════════════════════════════════════════
function route_quotations(string $m, $id, $sub, array $b, array $q, $tid, $uid): never {
    $t = $tid();

    // ── Convert to sales order ──
    if ($id && $sub === 'convert' && $m === 'POST') {
        Auth::need('quotations', 'update');
        Auth::need('orders', 'create');
        $quote = Db::one("SELECT * FROM quotations WHERE id=? AND tenant_id=? AND deleted_at IS NULL", [$id, $t]);
        if (!$quote) throw new NotFound('Quotation not found');
        Scope::assertOwnsRep($quote['rep_id'], 'quotations');
        if ($quote['status'] === 'Converted') throw new Conflict('This quotation has already been converted.');

        $items = Db::all("SELECT * FROM quotation_items WHERE quotation_id=? AND tenant_id=? ORDER BY sort_order", [$id, $t]);
        if (!$items) throw new Unproc('Cannot convert a quotation with no line items.');

        Db::begin();
        try {
            $oid = Db::uuid();
            $num = czium_next_number('sales_orders', 'order_number', 'SO', $t);
            Db::run("INSERT INTO sales_orders
                (id,tenant_id,order_number,customer_id,area_id,rep_id,quotation_id,status,payment_status,payment_mode,
                 order_date,subtotal,discount_amount,tax_amount,total_amount,paid_amount,notes,created_by)
                VALUES(?,?,?,?,?,?,?,'Pending Approval','Unpaid',?,CURDATE(),?,?,?,?,0,?,?)",
                [$oid,$t,$num,$quote['customer_id'],$quote['area_id'],$quote['rep_id'],$id,
                 $b['payment_mode'] ?? 'credit',
                 $quote['subtotal'],$quote['discount_amount'],$quote['tax_amount'],$quote['total_amount'],
                 $quote['notes'],$uid()]);

            foreach ($items as $i => $it) {
                Db::run("INSERT INTO sales_order_items
                    (id,tenant_id,order_id,product_id,description,qty_ordered,unit_price,discount_pct,tax_pct,line_total,sort_order)
                    VALUES(?,?,?,?,?,?,?,?,?,?,?)",
                    [Db::uuid(),$t,$oid,$it['product_id'],$it['description'],$it['qty'],
                     $it['unit_price'],$it['discount_pct'],$it['tax_pct'],$it['line_total'],$i]);
            }
            Db::run("UPDATE quotations SET status='Converted',converted_order_id=? WHERE id=? AND tenant_id=?", [$oid,$id,$t]);
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }

        Audit::log('CREATE','sales_order',$oid,$num,null,['from_quotation'=>$quote['quote_number']]);
        Http::created(['order_id'=>$oid,'order_number'=>$num,'message'=>'Quotation converted to order '.$num]);
    }

    switch ($m) {
        case 'GET':
            if ($id) {
                [$sc,$sp] = Scope::quotations('q');
                $quote = Db::one("SELECT q.*,c.name customer_name,c.phone customer_phone,c.address customer_address,
                    a.name area_name,r.name rep_name
                    FROM quotations q
                    JOIN customers c ON c.id=q.customer_id AND c.tenant_id=q.tenant_id
                    LEFT JOIN areas a ON a.id=q.area_id
                    LEFT JOIN sales_reps r ON r.id=q.rep_id
                    WHERE q.id=? AND q.tenant_id=? AND q.deleted_at IS NULL $sc",
                    array_merge([$id,$t],$sp));
                if (!$quote) throw new NotFound('Quotation not found');
                $items = Db::all("SELECT qi.*,p.name,p.sku FROM quotation_items qi
                    JOIN products p ON p.id=qi.product_id
                    WHERE qi.quotation_id=? AND qi.tenant_id=? ORDER BY qi.sort_order", [$id,$t]);
                Http::ok(['quotation'=>$quote,'items'=>$items]);
            }
            [$sc,$sp] = Scope::quotations('q');
            $w = "q.tenant_id=? AND q.deleted_at IS NULL $sc";
            $p = array_merge([$t],$sp);
            if (!empty($q['status'])) { $w .= " AND q.status=?"; $p[] = $q['status']; }
            if (!empty($q['search'])) { $w .= " AND (q.quote_number LIKE ? OR c.name LIKE ?)"; $p[] = "%{$q['search']}%"; $p[] = "%{$q['search']}%"; }
            if (!empty($q['from']))   { $w .= " AND q.quote_date >= ?"; $p[] = $q['from']; }
            if (!empty($q['to']))     { $w .= " AND q.quote_date <= ?"; $p[] = $q['to']; }
            Http::ok(Db::all("SELECT q.*,c.name customer_name,a.name area_name,r.name rep_name
                FROM quotations q
                JOIN customers c ON c.id=q.customer_id AND c.tenant_id=q.tenant_id
                LEFT JOIN areas a ON a.id=q.area_id
                LEFT JOIN sales_reps r ON r.id=q.rep_id
                WHERE $w ORDER BY q.quote_date DESC, q.created_at DESC LIMIT 300", $p));

        case 'POST':
            Auth::need('quotations','create');
            Validator::check($b, ['customer_id'=>'required','quote_date'=>'required']);
            $b = Scope::stampRep($b);
            $cust = Db::one("SELECT id,area_id FROM customers WHERE id=? AND tenant_id=? AND deleted_at IS NULL", [$b['customer_id'],$t]);
            if (!$cust) throw new Unproc('Customer not found.');
            Scope::assertInArea($cust['area_id']);

            $qid = Db::uuid();
            $num = czium_next_number('quotations','quote_number','QT',$t);
            Db::begin();
            try {
                Db::run("INSERT INTO quotations
                    (id,tenant_id,quote_number,customer_id,area_id,rep_id,quote_date,valid_until,status,notes,terms,created_by)
                    VALUES(?,?,?,?,?,?,?,?,?,?,?,?)",
                    [$qid,$t,$num,$b['customer_id'],$cust['area_id'],$b['rep_id']??null,$b['quote_date'],
                     $b['valid_until']??null,$b['status']??'Draft',$b['notes']??null,$b['terms']??null,$uid()]);
                foreach (($b['items'] ?? []) as $i => $it) {
                    if (empty($it['product_id'])) continue;
                    $qty=(float)($it['qty']??0); $up=(float)($it['unit_price']??0);
                    $dp=(float)($it['discount_pct']??0); $tp=(float)($it['tax_pct']??0);
                    $net=$qty*$up*(1-$dp/100); $lt=$net*(1+$tp/100);
                    Db::run("INSERT INTO quotation_items
                        (id,tenant_id,quotation_id,product_id,description,qty,unit_price,discount_pct,tax_pct,line_total,sort_order)
                        VALUES(?,?,?,?,?,?,?,?,?,?,?)",
                        [Db::uuid(),$t,$qid,$it['product_id'],$it['description']??null,$qty,$up,$dp,$tp,$lt,$i]);
                }
                czium_recalc_quote($qid,$t);
                Db::commit();
            } catch (Throwable $e) { Db::rollback(); throw $e; }
            Audit::log('CREATE','quotation',$qid,$num);
            Http::created(Db::one("SELECT * FROM quotations WHERE id=? AND tenant_id=?", [$qid,$t]));

        case 'PUT':
            Auth::need('quotations','update');
            $quote = Db::one("SELECT * FROM quotations WHERE id=? AND tenant_id=? AND deleted_at IS NULL", [$id,$t]);
            if (!$quote) throw new NotFound('Quotation not found');
            Scope::assertOwnsRep($quote['rep_id'],'quotations');
            if ($quote['status']==='Converted') throw new Conflict('A converted quotation cannot be edited.');

            Db::begin();
            try {
                Db::run("UPDATE quotations SET quote_date=?,valid_until=?,status=?,notes=?,terms=? WHERE id=? AND tenant_id=?",
                    [$b['quote_date']??$quote['quote_date'],$b['valid_until']??$quote['valid_until'],
                     $b['status']??$quote['status'],$b['notes']??$quote['notes'],$b['terms']??$quote['terms'],$id,$t]);
                if (isset($b['items']) && is_array($b['items'])) {
                    Db::run("DELETE FROM quotation_items WHERE quotation_id=? AND tenant_id=?", [$id,$t]);
                    foreach ($b['items'] as $i => $it) {
                        if (empty($it['product_id'])) continue;
                        $qty=(float)($it['qty']??0); $up=(float)($it['unit_price']??0);
                        $dp=(float)($it['discount_pct']??0); $tp=(float)($it['tax_pct']??0);
                        $net=$qty*$up*(1-$dp/100); $lt=$net*(1+$tp/100);
                        Db::run("INSERT INTO quotation_items
                            (id,tenant_id,quotation_id,product_id,description,qty,unit_price,discount_pct,tax_pct,line_total,sort_order)
                            VALUES(?,?,?,?,?,?,?,?,?,?,?)",
                            [Db::uuid(),$t,$id,$it['product_id'],$it['description']??null,$qty,$up,$dp,$tp,$lt,$i]);
                    }
                    czium_recalc_quote($id,$t);
                }
                Db::commit();
            } catch (Throwable $e) { Db::rollback(); throw $e; }
            Audit::log('UPDATE','quotation',$id,$quote['quote_number']);
            Http::ok(['message'=>'Quotation updated']);

        case 'DELETE':
            Auth::need('quotations','delete');
            $quote = Db::one("SELECT * FROM quotations WHERE id=? AND tenant_id=?", [$id,$t]);
            if (!$quote) throw new NotFound('Quotation not found');
            Scope::assertOwnsRep($quote['rep_id'],'quotations');
            Db::run("UPDATE quotations SET deleted_at=NOW() WHERE id=? AND tenant_id=?", [$id,$t]);
            Audit::log('DELETE','quotation',$id,$quote['quote_number']);
            Http::noContent();

        default: throw new Err('Method not allowed',405);
    }
}

// ═══════════════════════════════════════════════════════════
// EXPENSES
// ═══════════════════════════════════════════════════════════
function route_expenses(string $m, $id, $sub, array $b, array $q, $tid, $uid): never {
    $t = $tid();

    if ($sub === 'categories' || $id === 'categories') {
        if ($m === 'GET') {
            Auth::need('expenses','read');
            Http::ok(Db::all("SELECT * FROM expense_categories WHERE tenant_id=? AND is_active=1 ORDER BY name", [$t]));
        }
        if ($m === 'POST') {
            Auth::need('expenses','create');
            Validator::check($b, ['name'=>'required|max:120']);
            $cid = Db::uuid();
            Db::run("INSERT INTO expense_categories(id,tenant_id,name) VALUES(?,?,?)", [$cid,$t,$b['name']]);
            Http::created(Db::one("SELECT * FROM expense_categories WHERE id=? AND tenant_id=?", [$cid,$t]));
        }
        throw new Err('Method not allowed',405);
    }

    switch ($m) {
        case 'GET':
            Auth::need('expenses','read');
            if ($id) {
                $e = Db::one("SELECT e.*,ec.name category_name,s.name supplier_name,r.name rep_name
                    FROM expenses e
                    LEFT JOIN expense_categories ec ON ec.id=e.category_id
                    LEFT JOIN suppliers s ON s.id=e.supplier_id
                    LEFT JOIN sales_reps r ON r.id=e.rep_id
                    WHERE e.id=? AND e.tenant_id=? AND e.deleted_at IS NULL", [$id,$t]);
                if (!$e) throw new NotFound('Expense not found');
                Http::ok($e);
            }
            [$sc,$sp] = Scope::byRep('e');
            $w = "e.tenant_id=? AND e.deleted_at IS NULL $sc"; $p = array_merge([$t],$sp);
            if (!empty($q['from']))        { $w.=" AND e.expense_date>=?"; $p[]=$q['from']; }
            if (!empty($q['to']))          { $w.=" AND e.expense_date<=?"; $p[]=$q['to']; }
            if (!empty($q['category_id'])) { $w.=" AND e.category_id=?";   $p[]=$q['category_id']; }
            if (!empty($q['search']))      { $w.=" AND (e.expense_number LIKE ? OR e.paid_to LIKE ? OR e.description LIKE ?)";
                                             $p[]="%{$q['search']}%"; $p[]="%{$q['search']}%"; $p[]="%{$q['search']}%"; }
            Http::ok(Db::all("SELECT e.*,ec.name category_name,r.name rep_name
                FROM expenses e
                LEFT JOIN expense_categories ec ON ec.id=e.category_id
                LEFT JOIN sales_reps r ON r.id=e.rep_id
                WHERE $w ORDER BY e.expense_date DESC, e.created_at DESC LIMIT 300", $p));

        case 'POST':
            Auth::need('expenses','create');
            Validator::check($b, ['expense_date'=>'required','amount'=>'required']);
            if ((float)$b['amount'] <= 0) throw new Unproc('Amount must be greater than zero.', ['amount'=>['Must be > 0']]);
            $b = Scope::stampRep($b);
            $eid = Db::uuid();
            $num = czium_next_number('expenses','expense_number','EXP',$t);
            Db::run("INSERT INTO expenses
                (id,tenant_id,expense_number,category_id,expense_date,amount,tax_amount,payment_mode,paid_to,supplier_id,rep_id,area_id,reference,description,created_by)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$eid,$t,$num,$b['category_id']??null,$b['expense_date'],(float)$b['amount'],(float)($b['tax_amount']??0),
                 $b['payment_mode']??'cash',$b['paid_to']??null,$b['supplier_id']??null,$b['rep_id']??null,
                 $b['area_id']??null,$b['reference']??null,$b['description']??null,$uid()]);
            Audit::log('CREATE','expense',$eid,$num,null,['amount'=>$b['amount']]);
            Http::created(Db::one("SELECT * FROM expenses WHERE id=? AND tenant_id=?", [$eid,$t]));

        case 'PUT':
            Auth::need('expenses','update');
            $e = Db::one("SELECT * FROM expenses WHERE id=? AND tenant_id=? AND deleted_at IS NULL", [$id,$t]);
            if (!$e) throw new NotFound('Expense not found');
            Scope::assertOwnsRep($e['rep_id'],'expenses');
            Db::run("UPDATE expenses SET category_id=?,expense_date=?,amount=?,tax_amount=?,payment_mode=?,paid_to=?,supplier_id=?,reference=?,description=?
                     WHERE id=? AND tenant_id=?",
                [$b['category_id']??$e['category_id'],$b['expense_date']??$e['expense_date'],
                 (float)($b['amount']??$e['amount']),(float)($b['tax_amount']??$e['tax_amount']),
                 $b['payment_mode']??$e['payment_mode'],$b['paid_to']??$e['paid_to'],
                 $b['supplier_id']??$e['supplier_id'],$b['reference']??$e['reference'],
                 $b['description']??$e['description'],$id,$t]);
            Audit::log('UPDATE','expense',$id,$e['expense_number']);
            Http::ok(['message'=>'Expense updated']);

        case 'DELETE':
            Auth::need('expenses','update');
            $e = Db::one("SELECT * FROM expenses WHERE id=? AND tenant_id=?", [$id,$t]);
            if (!$e) throw new NotFound('Expense not found');
            Db::run("UPDATE expenses SET deleted_at=NOW() WHERE id=? AND tenant_id=?", [$id,$t]);
            Audit::log('DELETE','expense',$id,$e['expense_number']);
            Http::noContent();

        default: throw new Err('Method not allowed',405);
    }
}

// ═══════════════════════════════════════════════════════════
// GOODS RECEIPTS (GRN)
// ═══════════════════════════════════════════════════════════
function route_goods_receipts(string $m, $id, $sub, array $b, array $q, $tid, $uid): never {
    $t = $tid();

    // ── Receive: post stock and close the GRN ──
    if ($id && $sub === 'receive' && $m === 'PUT') {
        Auth::need('purchasing','update');
        $grn = Db::one("SELECT * FROM goods_receipts WHERE id=? AND tenant_id=?", [$id,$t]);
        if (!$grn) throw new NotFound('GRN not found');
        if ($grn['status'] !== 'Draft') throw new Unproc("GRN is '{$grn['status']}' and cannot be received again.");

        $items = Db::all("SELECT * FROM goods_receipt_items WHERE grn_id=? AND tenant_id=?", [$id,$t]);
        if (!$items) throw new Unproc('GRN has no line items.');

        Db::begin();
        try {
            $wh = $grn['warehouse_id'] ?: Db::val("SELECT id FROM warehouses WHERE tenant_id=? LIMIT 1", [$t]);
            foreach ($items as $it) {
                $qty = (float)$it['qty_received'];
                if ($qty <= 0) continue;

                if ($it['item_type'] === 'raw_material' && $it['material_id']) {
                    Db::run("UPDATE raw_materials SET current_stock=current_stock+?, cost_per_unit=? WHERE id=? AND tenant_id=?",
                            [$qty,(float)$it['unit_cost'],$it['material_id'],$t]);
                    Db::run("INSERT INTO raw_material_movements(id,tenant_id,material_id,type,qty,unit_cost,reference_type,reference_id,notes,created_by)
                             VALUES(?,?,?,'IN',?,?,'goods_receipt',?,?,?)",
                            [Db::uuid(),$t,$it['material_id'],$qty,(float)$it['unit_cost'],$id,"GRN {$grn['grn_number']}",$uid()]);
                } elseif ($it['product_id']) {
                    $inv = Db::val("SELECT id FROM inventory WHERE product_id=? AND warehouse_id=? AND tenant_id=?",
                                   [$it['product_id'],$wh,$t]);
                    if ($inv) {
                        Db::run("UPDATE inventory SET qty_on_hand=qty_on_hand+?, qty_available=qty_available+? WHERE id=? AND tenant_id=?",
                                [$qty,$qty,$inv,$t]);
                    } else {
                        Db::run("INSERT INTO inventory(id,tenant_id,product_id,warehouse_id,qty_on_hand,qty_reserved,qty_available,avg_cost)
                                 VALUES(?,?,?,?,?,0,?,?)",
                                [Db::uuid(),$t,$it['product_id'],$wh,$qty,$qty,(float)$it['unit_cost']]);
                    }
                }
            }
            Db::run("UPDATE goods_receipts SET status='Received',received_by=? WHERE id=? AND tenant_id=?", [$uid(),$id,$t]);
            if ($grn['purchase_order_id']) {
                Db::run("UPDATE purchase_orders SET grn_status='Complete' WHERE id=? AND tenant_id=?", [$grn['purchase_order_id'],$t]);
            }
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }

        Audit::log('UPDATE','goods_receipt',$id,$grn['grn_number'],['status'=>'Draft'],['status'=>'Received']);
        Http::ok(['message'=>'Goods received and stock updated.']);
    }

    switch ($m) {
        case 'GET':
            Auth::need('purchasing','read');
            if ($id) {
                $grn = Db::one("SELECT g.*,s.name supplier_name,w.name warehouse_name,po.po_number
                    FROM goods_receipts g
                    LEFT JOIN suppliers s ON s.id=g.supplier_id
                    LEFT JOIN warehouses w ON w.id=g.warehouse_id
                    LEFT JOIN purchase_orders po ON po.id=g.purchase_order_id
                    WHERE g.id=? AND g.tenant_id=?", [$id,$t]);
                if (!$grn) throw new NotFound('GRN not found');
                $items = Db::all("SELECT gi.*,p.name product_name,p.sku,rm.name material_name,rm.unit material_unit
                    FROM goods_receipt_items gi
                    LEFT JOIN products p ON p.id=gi.product_id
                    LEFT JOIN raw_materials rm ON rm.id=gi.material_id
                    WHERE gi.grn_id=? AND gi.tenant_id=?", [$id,$t]);
                Http::ok(['grn'=>$grn,'items'=>$items]);
            }
            $w="g.tenant_id=?"; $p=[$t];
            if (!empty($q['status'])) { $w.=" AND g.status=?"; $p[]=$q['status']; }
            if (!empty($q['from']))   { $w.=" AND g.receipt_date>=?"; $p[]=$q['from']; }
            if (!empty($q['to']))     { $w.=" AND g.receipt_date<=?"; $p[]=$q['to']; }
            Http::ok(Db::all("SELECT g.*,s.name supplier_name,po.po_number
                FROM goods_receipts g
                LEFT JOIN suppliers s ON s.id=g.supplier_id
                LEFT JOIN purchase_orders po ON po.id=g.purchase_order_id
                WHERE $w ORDER BY g.receipt_date DESC LIMIT 200", $p));

        case 'POST':
            Auth::need('purchasing','create');
            Validator::check($b, ['supplier_id'=>'required','receipt_date'=>'required']);
            $gid = Db::uuid();
            $num = czium_next_number('goods_receipts','grn_number','GRN',$t);
            Db::begin();
            try {
                $total = 0.0;
                Db::run("INSERT INTO goods_receipts
                    (id,tenant_id,grn_number,purchase_order_id,supplier_id,warehouse_id,receipt_date,status,invoice_ref,notes,received_by)
                    VALUES(?,?,?,?,?,?,?, 'Draft',?,?,?)",
                    [$gid,$t,$num,$b['purchase_order_id']??null,$b['supplier_id'],$b['warehouse_id']??null,
                     $b['receipt_date'],$b['invoice_ref']??null,$b['notes']??null,$uid()]);
                foreach (($b['items'] ?? []) as $it) {
                    $type = ($it['item_type'] ?? 'product') === 'raw_material' ? 'raw_material' : 'product';
                    if ($type === 'product' && empty($it['product_id']))  continue;
                    if ($type === 'raw_material' && empty($it['material_id'])) continue;
                    $qr = (float)($it['qty_received'] ?? 0);
                    $uc = (float)($it['unit_cost'] ?? 0);
                    $lt = $qr * $uc; $total += $lt;
                    Db::run("INSERT INTO goods_receipt_items
                        (id,tenant_id,grn_id,item_type,product_id,material_id,qty_ordered,qty_received,qty_rejected,unit_cost,line_total,notes)
                        VALUES(?,?,?,?,?,?,?,?,?,?,?,?)",
                        [Db::uuid(),$t,$gid,$type,$it['product_id']??null,$it['material_id']??null,
                         (float)($it['qty_ordered']??0),$qr,(float)($it['qty_rejected']??0),$uc,$lt,$it['notes']??null]);
                }
                Db::run("UPDATE goods_receipts SET total_cost=? WHERE id=? AND tenant_id=?", [$total,$gid,$t]);
                Db::commit();
            } catch (Throwable $e) { Db::rollback(); throw $e; }
            Audit::log('CREATE','goods_receipt',$gid,$num);
            Http::created(Db::one("SELECT * FROM goods_receipts WHERE id=? AND tenant_id=?", [$gid,$t]));

        case 'DELETE':
            Auth::need('purchasing','update');
            $grn = Db::one("SELECT * FROM goods_receipts WHERE id=? AND tenant_id=?", [$id,$t]);
            if (!$grn) throw new NotFound('GRN not found');
            if ($grn['status']==='Received') throw new Unproc('A received GRN cannot be deleted; raise a stock adjustment instead.');
            Db::run("UPDATE goods_receipts SET status='Cancelled' WHERE id=? AND tenant_id=?", [$id,$t]);
            Http::noContent();

        default: throw new Err('Method not allowed',405);
    }
}

// ═══════════════════════════════════════════════════════════
// STOCK ADJUSTMENTS (damaged / expired / recount)
// ═══════════════════════════════════════════════════════════
function route_stock_adjustments(string $m, $id, $sub, array $b, array $q, $tid, $uid): never {
    $t = $tid();

    if ($id && $sub === 'apply' && $m === 'PUT') {
        Auth::need('inventory','update');
        $adj = Db::one("SELECT * FROM stock_adjustments WHERE id=? AND tenant_id=?", [$id,$t]);
        if (!$adj) throw new NotFound('Adjustment not found');
        if ($adj['status'] !== 'Draft') throw new Unproc("Adjustment is '{$adj['status']}' and cannot be applied again.");
        $items = Db::all("SELECT * FROM stock_adjustment_items WHERE adjustment_id=? AND tenant_id=?", [$id,$t]);
        if (!$items) throw new Unproc('Adjustment has no line items.');

        Db::begin();
        try {
            $wh = $adj['warehouse_id'] ?: Db::val("SELECT id FROM warehouses WHERE tenant_id=? LIMIT 1", [$t]);
            foreach ($items as $it) {
                $delta = (float)$it['qty_change'];
                if ($it['item_type']==='raw_material' && $it['material_id']) {
                    Db::run("UPDATE raw_materials SET current_stock=GREATEST(0,current_stock+?) WHERE id=? AND tenant_id=?",
                            [$delta,$it['material_id'],$t]);
                    Db::run("INSERT INTO raw_material_movements(id,tenant_id,material_id,type,qty,reference_type,reference_id,notes,created_by)
                             VALUES(?,?,?,?,?,'stock_adjustment',?,?,?)",
                            [Db::uuid(),$t,$it['material_id'],
                             in_array($adj['reason'],['Damaged','Expired'],true)?$adj['reason']==='Damaged'?'DAMAGED':'EXPIRED':'ADJUST',
                             abs($delta),$id,"Adj {$adj['adj_number']}: {$adj['reason']}",$uid()]);
                } elseif ($it['product_id']) {
                    $inv = Db::val("SELECT id FROM inventory WHERE product_id=? AND warehouse_id=? AND tenant_id=?",
                                   [$it['product_id'],$wh,$t]);
                    if ($inv) {
                        Db::run("UPDATE inventory SET qty_on_hand=GREATEST(0,qty_on_hand+?), qty_available=GREATEST(0,qty_available+?) WHERE id=? AND tenant_id=?",
                                [$delta,$delta,$inv,$t]);
                    } elseif ($delta > 0) {
                        Db::run("INSERT INTO inventory(id,tenant_id,product_id,warehouse_id,qty_on_hand,qty_reserved,qty_available)
                                 VALUES(?,?,?,?,?,0,?)", [Db::uuid(),$t,$it['product_id'],$wh,$delta,$delta]);
                    }
                }
            }
            Db::run("UPDATE stock_adjustments SET status='Applied',applied_at=NOW() WHERE id=? AND tenant_id=?", [$id,$t]);
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }
        Audit::log('UPDATE','stock_adjustment',$id,$adj['adj_number'],['status'=>'Draft'],['status'=>'Applied','reason'=>$adj['reason']]);
        Http::ok(['message'=>'Adjustment applied to stock.']);
    }

    switch ($m) {
        case 'GET':
            Auth::need('inventory','read');
            if ($id) {
                $adj = Db::one("SELECT a.*,w.name warehouse_name FROM stock_adjustments a
                    LEFT JOIN warehouses w ON w.id=a.warehouse_id
                    WHERE a.id=? AND a.tenant_id=?", [$id,$t]);
                if (!$adj) throw new NotFound('Adjustment not found');
                $items = Db::all("SELECT ai.*,p.name product_name,p.sku,rm.name material_name,rm.unit material_unit
                    FROM stock_adjustment_items ai
                    LEFT JOIN products p ON p.id=ai.product_id
                    LEFT JOIN raw_materials rm ON rm.id=ai.material_id
                    WHERE ai.adjustment_id=? AND ai.tenant_id=?", [$id,$t]);
                Http::ok(['adjustment'=>$adj,'items'=>$items]);
            }
            $w="a.tenant_id=?"; $p=[$t];
            if (!empty($q['reason'])) { $w.=" AND a.reason=?"; $p[]=$q['reason']; }
            if (!empty($q['status'])) { $w.=" AND a.status=?"; $p[]=$q['status']; }
            if (!empty($q['from']))   { $w.=" AND a.adj_date>=?"; $p[]=$q['from']; }
            if (!empty($q['to']))     { $w.=" AND a.adj_date<=?"; $p[]=$q['to']; }
            Http::ok(Db::all("SELECT a.*,w.name warehouse_name FROM stock_adjustments a
                LEFT JOIN warehouses w ON w.id=a.warehouse_id
                WHERE $w ORDER BY a.adj_date DESC LIMIT 200", $p));

        case 'POST':
            Auth::need('inventory','update');
            Validator::check($b, ['adj_date'=>'required','reason'=>'required']);
            $aid = Db::uuid();
            $num = czium_next_number('stock_adjustments','adj_number','ADJ',$t);
            Db::begin();
            try {
                Db::run("INSERT INTO stock_adjustments(id,tenant_id,adj_number,warehouse_id,adj_date,reason,status,notes,created_by)
                         VALUES(?,?,?,?,?,?, 'Draft',?,?)",
                    [$aid,$t,$num,$b['warehouse_id']??null,$b['adj_date'],$b['reason'],$b['notes']??null,$uid()]);
                $val = 0.0;
                foreach (($b['items'] ?? []) as $it) {
                    $type = ($it['item_type'] ?? 'product')==='raw_material' ? 'raw_material':'product';
                    if ($type==='product' && empty($it['product_id'])) continue;
                    if ($type==='raw_material' && empty($it['material_id'])) continue;
                    $before=(float)($it['qty_before']??0); $chg=(float)($it['qty_change']??0);
                    $cost=(float)($it['unit_cost']??0); $lv=abs($chg)*$cost; $val+=$lv;
                    Db::run("INSERT INTO stock_adjustment_items
                        (id,tenant_id,adjustment_id,item_type,product_id,material_id,qty_before,qty_change,qty_after,unit_cost,line_value,notes)
                        VALUES(?,?,?,?,?,?,?,?,?,?,?,?)",
                        [Db::uuid(),$t,$aid,$type,$it['product_id']??null,$it['material_id']??null,
                         $before,$chg,$before+$chg,$cost,$lv,$it['notes']??null]);
                }
                Db::run("UPDATE stock_adjustments SET total_value=? WHERE id=? AND tenant_id=?", [$val,$aid,$t]);
                Db::commit();
            } catch (Throwable $e) { Db::rollback(); throw $e; }
            Audit::log('CREATE','stock_adjustment',$aid,$num,null,['reason'=>$b['reason']]);
            Http::created(Db::one("SELECT * FROM stock_adjustments WHERE id=? AND tenant_id=?", [$aid,$t]));

        default: throw new Err('Method not allowed',405);
    }
}

// ═══════════════════════════════════════════════════════════
// REP VISITS
// ═══════════════════════════════════════════════════════════
function route_rep_visits(string $m, $id, array $b, array $q, $tid, $uid): never {
    $t = $tid();
    switch ($m) {
        case 'GET':
            Auth::need('reps','read');
            [$sc,$sp] = Scope::byRep('v');
            $w = "v.tenant_id=? $sc"; $p = array_merge([$t],$sp);
            if (!empty($q['rep_id']))  { $w.=" AND v.rep_id=?"; $p[]=$q['rep_id']; }
            if (!empty($q['from']))    { $w.=" AND v.visit_date>=?"; $p[]=$q['from']; }
            if (!empty($q['to']))      { $w.=" AND v.visit_date<=?"; $p[]=$q['to']; }
            if (!empty($q['outcome'])) { $w.=" AND v.outcome=?"; $p[]=$q['outcome']; }
            Http::ok(Db::all("SELECT v.*,c.name customer_name,r.name rep_name,a.name area_name,so.order_number
                FROM rep_visits v
                JOIN customers c ON c.id=v.customer_id AND c.tenant_id=v.tenant_id
                LEFT JOIN sales_reps r ON r.id=v.rep_id
                LEFT JOIN areas a ON a.id=v.area_id
                LEFT JOIN sales_orders so ON so.id=v.order_id
                WHERE $w ORDER BY v.visit_date DESC, v.created_at DESC LIMIT 300", $p));

        case 'POST':
            Auth::need('reps','read');
            Validator::check($b, ['customer_id'=>'required','visit_date'=>'required']);
            $b = Scope::stampRep($b);
            if (empty($b['rep_id'])) throw new Unproc('A rep must be specified for the visit.');
            $cust = Db::one("SELECT area_id FROM customers WHERE id=? AND tenant_id=?", [$b['customer_id'],$t]);
            if (!$cust) throw new Unproc('Customer not found.');
            $vid = Db::uuid();
            Db::run("INSERT INTO rep_visits(id,tenant_id,rep_id,customer_id,area_id,visit_date,visit_time,outcome,order_id,notes)
                     VALUES(?,?,?,?,?,?,?,?,?,?)",
                [$vid,$t,$b['rep_id'],$b['customer_id'],$cust['area_id'],$b['visit_date'],
                 $b['visit_time']??null,$b['outcome']??'Order',$b['order_id']??null,$b['notes']??null]);
            Http::created(Db::one("SELECT * FROM rep_visits WHERE id=? AND tenant_id=?", [$vid,$t]));

        case 'DELETE':
            Auth::need('reps','update');
            Db::run("DELETE FROM rep_visits WHERE id=? AND tenant_id=?", [$id,$t]);
            Http::noContent();

        default: throw new Err('Method not allowed',405);
    }
}

// ═══════════════════════════════════════════════════════════
// TARGETS (rep + distributor, with achievement)
// ═══════════════════════════════════════════════════════════
function route_targets(string $m, $id, $sub, array $b, array $q, $tid, $uid): never {
    $t = $tid();
    $year  = (int)($q['year']  ?? date('Y'));
    $month = (int)($q['month'] ?? date('n'));

    if ($m === 'GET') {
        Auth::need('targets','read');
        [$sc,$sp] = Scope::byRep('r', 'id');
        $reps = Db::all("SELECT r.id,r.name,
              COALESCE(t.target_amount,0) target_amount,
              COALESCE((SELECT SUM(so.total_amount) FROM sales_orders so
                        WHERE so.rep_id=r.id AND so.tenant_id=r.tenant_id
                          AND so.status NOT IN('Cancelled','Draft')
                          AND YEAR(so.order_date)=? AND MONTH(so.order_date)=?),0) achieved
            FROM sales_reps r
            LEFT JOIN rep_targets t ON t.rep_id=r.id AND t.period_year=? AND t.period_month=?
            WHERE r.tenant_id=? AND r.is_active=1 $sc
            ORDER BY r.name", array_merge([$year,$month,$year,$month,$t],$sp));

        $dists = Db::all("SELECT d.id,d.name,
              COALESCE(dt.target_amount,0) target_amount,
              COALESCE((SELECT SUM(so.total_amount) FROM sales_orders so
                        WHERE so.distributor_id=d.id AND so.tenant_id=d.tenant_id
                          AND so.status NOT IN('Cancelled','Draft')
                          AND YEAR(so.order_date)=? AND MONTH(so.order_date)=?),0) achieved
            FROM distributors d
            LEFT JOIN distributor_targets dt ON dt.distributor_id=d.id AND dt.period_year=? AND dt.period_month=?
            WHERE d.tenant_id=? AND d.is_active=1
            ORDER BY d.name", [$year,$month,$year,$month,$t]);

        // 6-month trend for comparison charts
        $trend = Db::all("SELECT DATE_FORMAT(so.order_date,'%Y-%m') period,
                  SUM(so.total_amount) revenue, COUNT(*) orders
              FROM sales_orders so
              WHERE so.tenant_id=? AND so.status NOT IN('Cancelled','Draft')
                AND so.order_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
              GROUP BY period ORDER BY period", [$t]);

        Http::ok(['reps'=>$reps,'distributors'=>$dists,'trend'=>$trend,'year'=>$year,'month'=>$month]);
    }

    if ($m === 'POST') {
        Auth::need('targets','update');
        Validator::check($b, ['type'=>'required','entity_id'=>'required','target_amount'=>'required']);
        $y = (int)($b['period_year'] ?? date('Y'));
        $mo= (int)($b['period_month'] ?? date('n'));
        $amt = (float)$b['target_amount'];

        if ($b['type'] === 'rep') {
            Db::run("INSERT INTO rep_targets(id,tenant_id,rep_id,period_year,period_month,target_amount,achieved_amount)
                     VALUES(?,?,?,?,?,?,0) ON DUPLICATE KEY UPDATE target_amount=VALUES(target_amount)",
                [Db::uuid(),$t,$b['entity_id'],$y,$mo,$amt]);
        } elseif ($b['type'] === 'distributor') {
            Db::run("INSERT INTO distributor_targets(id,tenant_id,distributor_id,period_year,period_month,target_amount,achieved_amount)
                     VALUES(?,?,?,?,?,?,0) ON DUPLICATE KEY UPDATE target_amount=VALUES(target_amount)",
                [Db::uuid(),$t,$b['entity_id'],$y,$mo,$amt]);
        } else {
            throw new Unproc("type must be 'rep' or 'distributor'.");
        }
        Audit::log('UPDATE','target',$b['entity_id'],$b['type'],null,['amount'=>$amt,'period'=>"$y-$mo"]);
        Http::created(['message'=>'Target saved']);
    }

    throw new Err('Method not allowed',405);
}

// ═══════════════════════════════════════════════════════════
// ACCOUNTS — receivables, payables, P&L
// ═══════════════════════════════════════════════════════════
function route_accounts(string $m, $sub, array $q, $tid): never {
    if ($m !== 'GET') throw new Err('Method not allowed',405);
    Auth::need('accounts','read');
    $t = $tid();
    [$from,$to] = dos_resolve_date_range($q);

    if ($sub === 'receivables') {
        Http::ok(Db::all("SELECT i.id,i.invoice_number,i.invoice_date,i.due_date,i.total_amount,i.paid_amount,
              (i.total_amount-i.paid_amount) balance, i.status,
              c.name customer_name, c.phone customer_phone,
              DATEDIFF(CURDATE(), i.due_date) days_overdue
            FROM invoices i
            JOIN customers c ON c.id=i.customer_id AND c.tenant_id=i.tenant_id
            WHERE i.tenant_id=? AND i.status NOT IN('Paid','Cancelled') AND (i.total_amount-i.paid_amount) > 0
            ORDER BY days_overdue DESC LIMIT 300", [$t]));
    }

    if ($sub === 'payables') {
        Http::ok(Db::all("SELECT po.id,po.po_number,po.order_date,po.total_amount,po.paid_amount,
              (po.total_amount-po.paid_amount) balance, po.status, s.name supplier_name
            FROM purchase_orders po
            LEFT JOIN suppliers s ON s.id=po.supplier_id
            WHERE po.tenant_id=? AND po.deleted_at IS NULL
              AND (po.total_amount-po.paid_amount) > 0 AND po.status NOT IN('Cancelled')
            ORDER BY po.order_date ASC LIMIT 300", [$t]));
    }

    if ($sub === 'pnl' || $sub === null || $sub === '') {
        $rev = Db::one("SELECT COALESCE(SUM(total_amount),0) revenue, COUNT(*) orders,
              COALESCE(SUM(CASE WHEN payment_mode='cash' THEN total_amount ELSE 0 END),0) cash_sales,
              COALESCE(SUM(CASE WHEN payment_mode='credit' THEN total_amount ELSE 0 END),0) credit_sales
            FROM sales_orders
            WHERE tenant_id=? AND status NOT IN('Cancelled','Draft') AND order_date BETWEEN ? AND ?",
            [$t,$from,$to]);

        $cogs = (float)(Db::val("SELECT COALESCE(SUM(soi.qty_ordered*p.cost_price),0)
            FROM sales_order_items soi
            JOIN products p ON p.id=soi.product_id
            JOIN sales_orders so ON so.id=soi.order_id
            WHERE so.tenant_id=? AND so.status NOT IN('Cancelled','Draft') AND so.order_date BETWEEN ? AND ?",
            [$t,$from,$to]) ?? 0);

        $exp = Db::all("SELECT ec.name category, COALESCE(SUM(e.amount),0) total
            FROM expenses e LEFT JOIN expense_categories ec ON ec.id=e.category_id
            WHERE e.tenant_id=? AND e.deleted_at IS NULL AND e.expense_date BETWEEN ? AND ?
            GROUP BY e.category_id ORDER BY total DESC", [$t,$from,$to]);
        $expTotal = array_sum(array_map(fn($r) => (float)$r['total'], $exp));

        $collected = (float)(Db::val("SELECT COALESCE(SUM(amount),0) FROM payments
            WHERE tenant_id=? AND payment_date BETWEEN ? AND ?", [$t,$from,$to]) ?? 0);

        $revenue    = (float)$rev['revenue'];
        $grossProfit= $revenue - $cogs;
        $netProfit  = $grossProfit - $expTotal;

        Http::ok([
            'range'          => ['from'=>$from,'to'=>$to],
            'revenue'        => $revenue,
            'orders'         => (int)$rev['orders'],
            'cash_sales'     => (float)$rev['cash_sales'],
            'credit_sales'   => (float)$rev['credit_sales'],
            'cogs'           => $cogs,
            'gross_profit'   => $grossProfit,
            'gross_margin'   => $revenue > 0 ? round($grossProfit / $revenue * 100, 2) : 0,
            'expenses'       => $exp,
            'expenses_total' => $expTotal,
            'net_profit'     => $netProfit,
            'net_margin'     => $revenue > 0 ? round($netProfit / $revenue * 100, 2) : 0,
            'collected'      => $collected,
            'receivables'    => (float)(Db::val("SELECT COALESCE(SUM(total_amount-paid_amount),0) FROM invoices
                                   WHERE tenant_id=? AND status NOT IN('Paid','Cancelled')", [$t]) ?? 0),
            'payables'       => (float)(Db::val("SELECT COALESCE(SUM(total_amount-paid_amount),0) FROM purchase_orders
                                   WHERE tenant_id=? AND deleted_at IS NULL AND status NOT IN('Cancelled')", [$t]) ?? 0),
        ]);
    }

    throw new NotFound('Unknown accounts endpoint');
}

// ═══════════════════════════════════════════════════════════
// REPORTS — daily, monthly, product-wise, area-wise, rep-wise
// ═══════════════════════════════════════════════════════════
function route_reports_v5(string $m, $sub, array $q, $tid): never {
    if ($m !== 'GET') throw new Err('Method not allowed',405);
    Auth::need('reports','read');
    $t = $tid();
    [$from,$to] = dos_resolve_date_range($q);
    [$sc,$sp]   = Scope::orders('so');

    switch ($sub) {
        case 'daily':
            Http::ok(Db::all("SELECT so.order_date day, COUNT(*) orders,
                  COALESCE(SUM(so.total_amount),0) revenue,
                  COALESCE(SUM(CASE WHEN so.payment_mode='cash'   THEN so.total_amount ELSE 0 END),0) cash,
                  COALESCE(SUM(CASE WHEN so.payment_mode='credit' THEN so.total_amount ELSE 0 END),0) credit
                FROM sales_orders so
                WHERE so.tenant_id=? AND so.status NOT IN('Cancelled','Draft')
                  AND so.order_date BETWEEN ? AND ? $sc
                GROUP BY so.order_date ORDER BY so.order_date DESC",
                array_merge([$t,$from,$to],$sp)));

        case 'monthly':
            Http::ok(Db::all("SELECT DATE_FORMAT(so.order_date,'%Y-%m') month, COUNT(*) orders,
                  COALESCE(SUM(so.total_amount),0) revenue,
                  COALESCE(SUM(CASE WHEN so.payment_mode='cash'   THEN so.total_amount ELSE 0 END),0) cash,
                  COALESCE(SUM(CASE WHEN so.payment_mode='credit' THEN so.total_amount ELSE 0 END),0) credit
                FROM sales_orders so
                WHERE so.tenant_id=? AND so.status NOT IN('Cancelled','Draft')
                  AND so.order_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) $sc
                GROUP BY month ORDER BY month DESC",
                array_merge([$t],$sp)));

        case 'product-wise':
            $rows = Db::all("SELECT p.id,p.name,p.sku,p.product_category,
                  SUM(soi.qty_ordered) units, SUM(soi.line_total) revenue,
                  COUNT(DISTINCT so.id) order_count,
                  SUM(soi.qty_ordered * p.cost_price) cost
                FROM sales_order_items soi
                JOIN products p ON p.id=soi.product_id AND p.tenant_id=?
                JOIN sales_orders so ON so.id=soi.order_id AND so.tenant_id=?
                  AND so.status NOT IN('Cancelled','Draft') AND so.order_date BETWEEN ? AND ? $sc
                GROUP BY p.id ORDER BY units DESC",
                array_merge([$t,$t,$from,$to],$sp));
            $totalUnits = array_sum(array_map(fn($r)=>(float)$r['units'], $rows));
            foreach ($rows as &$r) {
                $r['share_pct'] = $totalUnits > 0 ? round((float)$r['units']/$totalUnits*100,2) : 0;
                $r['margin']    = (float)$r['revenue'] - (float)$r['cost'];
                $r['velocity']  = 'Slow';
            }
            unset($r);
            // Top third by units are "Fast", middle third "Medium".
            $n = count($rows);
            foreach ($rows as $i => &$r) {
                if     ($n && $i < ceil($n/3))   $r['velocity']='Fast';
                elseif ($n && $i < ceil($n*2/3)) $r['velocity']='Medium';
            }
            unset($r);
            Http::ok(['range'=>['from'=>$from,'to'=>$to],'products'=>$rows,'total_units'=>$totalUnits]);

        case 'area-wise':
            Http::ok(Db::all("SELECT a.id,a.name,a.district,
                  COUNT(DISTINCT so.id) orders,
                  COALESCE(SUM(so.total_amount),0) revenue,
                  COALESCE(SUM(CASE WHEN so.payment_mode='cash'   THEN so.total_amount ELSE 0 END),0) cash,
                  COALESCE(SUM(CASE WHEN so.payment_mode='credit' THEN so.total_amount ELSE 0 END),0) credit,
                  COUNT(DISTINCT so.customer_id) customers
                FROM areas a
                LEFT JOIN sales_orders so ON so.area_id=a.id AND so.tenant_id=a.tenant_id
                  AND so.status NOT IN('Cancelled','Draft') AND so.order_date BETWEEN ? AND ?
                WHERE a.tenant_id=? AND a.is_active=1
                GROUP BY a.id ORDER BY revenue DESC", [$from,$to,$t]));

        case 'rep-wise':
            Http::ok(Db::all("SELECT r.id,r.name,r.route_name,
                  COUNT(DISTINCT so.id) orders,
                  COALESCE(SUM(so.total_amount),0) revenue,
                  COALESCE(SUM(CASE WHEN so.payment_mode='cash'   THEN so.total_amount ELSE 0 END),0) cash,
                  COALESCE(SUM(CASE WHEN so.payment_mode='credit' THEN so.total_amount ELSE 0 END),0) credit,
                  COUNT(DISTINCT so.customer_id) shops,
                  (SELECT COUNT(*) FROM rep_visits v WHERE v.rep_id=r.id AND v.visit_date BETWEEN ? AND ?) visits,
                  COALESCE(tg.target_amount,0) target
                FROM sales_reps r
                LEFT JOIN sales_orders so ON so.rep_id=r.id AND so.tenant_id=r.tenant_id
                  AND so.status NOT IN('Cancelled','Draft') AND so.order_date BETWEEN ? AND ?
                LEFT JOIN rep_targets tg ON tg.rep_id=r.id AND tg.period_year=YEAR(CURDATE()) AND tg.period_month=MONTH(CURDATE())
                WHERE r.tenant_id=? AND r.is_active=1
                GROUP BY r.id ORDER BY revenue DESC", [$from,$to,$from,$to,$t]));

        default:
            throw new NotFound('Unknown report');
    }
}

// ═══════════════════════════════════════════════════════════
// DOCUMENT PDF + SHARE LINKS
// ═══════════════════════════════════════════════════════════

/** Assemble an invoice with everything the PDF renderer needs. */
function czium_load_invoice(string $id, string $t): array {
    $inv = Db::one("SELECT i.*, i.paid_amount AS amount_paid,
          c.name customer_name, c.phone customer_phone, c.address customer_address,
          so.order_number, so.subtotal, so.discount_amount, so.tax_amount, so.payment_mode,
          a.name area_name, r.name rep_name
        FROM invoices i
        JOIN customers c ON c.id=i.customer_id AND c.tenant_id=i.tenant_id
        LEFT JOIN sales_orders so ON so.id=i.order_id
        LEFT JOIN areas a ON a.id=COALESCE(i.area_id, so.area_id)
        LEFT JOIN sales_reps r ON r.id=COALESCE(i.rep_id, so.rep_id)
        WHERE i.id=? AND i.tenant_id=?", [$id,$t]);
    if (!$inv) throw new NotFound('Invoice not found');

    $items = Db::all("SELECT p.name, p.sku, soi.qty_ordered qty, soi.unit_price, soi.discount_pct, soi.line_total
        FROM sales_order_items soi
        JOIN products p ON p.id=soi.product_id
        WHERE soi.order_id=? AND soi.tenant_id=? ORDER BY soi.sort_order",
        [$inv['order_id'],$t]);

    // Invoice without a linked order still renders, just with no lines.
    if (!$items) $items = [];
    // Subtotal falls back to the invoice total when no order is linked.
    if ($inv['subtotal'] === null) $inv['subtotal'] = $inv['total_amount'];
    return [$inv,$items];
}

function route_document_pdf(string $m, string $type, $id, $tid): never {
    if ($m !== 'GET') throw new Err('Method not allowed',405);
    $t = $tid();
    $tenant   = Db::one("SELECT id,name,slug FROM tenants WHERE id=?", [$t]) ?: ['name'=>'CZium Distribution'];
    $branding = Doc::branding($t);

    if ($type === 'invoice') {
        Auth::need('invoices','read');
        [$inv,$items] = czium_load_invoice($id,$t);
        if (Scope::isRep()) Scope::assertOwnsRep($inv['rep_id'] ?? null,'invoices');
        Doc::invoice($inv,$items,$tenant,$branding)->send("Invoice-{$inv['invoice_number']}.pdf");
    }

    if ($type === 'quotation') {
        Auth::need('quotations','read');
        $q = Db::one("SELECT q.*,c.name customer_name,c.phone customer_phone,c.address customer_address,
              a.name area_name,r.name rep_name
            FROM quotations q
            JOIN customers c ON c.id=q.customer_id AND c.tenant_id=q.tenant_id
            LEFT JOIN areas a ON a.id=q.area_id
            LEFT JOIN sales_reps r ON r.id=q.rep_id
            WHERE q.id=? AND q.tenant_id=? AND q.deleted_at IS NULL", [$id,$t]);
        if (!$q) throw new NotFound('Quotation not found');
        Scope::assertOwnsRep($q['rep_id'],'quotations');
        $items = Db::all("SELECT p.name,p.sku,qi.qty,qi.unit_price,qi.discount_pct,qi.line_total
            FROM quotation_items qi JOIN products p ON p.id=qi.product_id
            WHERE qi.quotation_id=? AND qi.tenant_id=? ORDER BY qi.sort_order", [$id,$t]);
        Doc::quotation($q,$items,$tenant,$branding)->send("Quotation-{$q['quote_number']}.pdf");
    }

    throw new NotFound('Unknown document type');
}

/** Issue a share link + WhatsApp deep link for a document. */
function route_share_link(string $m, string $type, $id, array $q, $tid, $uid): never {
    if ($m !== 'POST' && $m !== 'GET') throw new Err('Method not allowed',405);
    $t = $tid();

    if ($type === 'invoice') {
        Auth::need('invoices','read');
        [$inv,] = czium_load_invoice($id,$t);
        if (Scope::isRep()) Scope::assertOwnsRep($inv['rep_id'] ?? null,'invoices');
        $token = Doc::shareToken('invoice',$id,$t,$uid());
        $url   = Doc::shareUrl($token);
        $bal   = (float)$inv['total_amount'] - (float)$inv['amount_paid'];
        $msg   = "Invoice {$inv['invoice_number']}\n"
               . ($inv['customer_name'] ? "For: {$inv['customer_name']}\n" : '')
               . "Amount: Rs. " . number_format((float)$inv['total_amount'],2) . "\n"
               . ($bal > 0 ? "Balance: Rs. " . number_format($bal,2) . "\n" : "Status: Paid in full\n")
               . "\nView or download the invoice here:\n$url";
        Http::ok([
            'share_url'    => $url,
            'whatsapp_url' => Doc::whatsappUrl($inv['customer_phone'] ?? '', $msg),
            'pdf_url'      => rtrim(APP_URL,'/') . "/api/documents/invoice/$id/pdf",
            'message'      => $msg,
        ]);
    }

    if ($type === 'quotation') {
        Auth::need('quotations','read');
        $quote = Db::one("SELECT q.*,c.name customer_name,c.phone customer_phone
            FROM quotations q JOIN customers c ON c.id=q.customer_id AND c.tenant_id=q.tenant_id
            WHERE q.id=? AND q.tenant_id=?", [$id,$t]);
        if (!$quote) throw new NotFound('Quotation not found');
        Scope::assertOwnsRep($quote['rep_id'],'quotations');
        $token = Doc::shareToken('quotation',$id,$t,$uid());
        $url   = Doc::shareUrl($token);
        $msg   = "Quotation {$quote['quote_number']}\n"
               . "For: {$quote['customer_name']}\n"
               . "Amount: Rs. " . number_format((float)$quote['total_amount'],2) . "\n"
               . ($quote['valid_until'] ? "Valid until: " . date('d M Y', strtotime($quote['valid_until'])) . "\n" : '')
               . "\nView or download the quotation here:\n$url";
        Http::ok([
            'share_url'    => $url,
            'whatsapp_url' => Doc::whatsappUrl($quote['customer_phone'] ?? '', $msg),
            'pdf_url'      => rtrim(APP_URL,'/') . "/api/documents/quotation/$id/pdf",
            'message'      => $msg,
        ]);
    }

    throw new NotFound('Unknown document type');
}
