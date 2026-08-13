<?php
/**
 * DistributionOS v2 — API Router
 * Upload this as: public_html/api/index.php
 */

require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/Scope.php';
require_once __DIR__ . '/lib/Pdf.php';
require_once __DIR__ . '/lib/Doc.php';
require_once __DIR__ . '/routes_v3.php';
require_once __DIR__ . '/routes_v5.php';

// ─── Headers ──────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
// ht7: set HSTS at the PHP layer too — some shared hosts don't apply
// mod_headers to PHP-generated responses, only to static files.
if (!empty($_SERVER['HTTPS'])) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// ht5: CORS is restricted to APP_URL in production. The localhost dev origins
// are only honored when APP_DEBUG=true, so a misconfigured production deploy
// can never accidentally reflect an arbitrary Origin header.
$origin  = $_SERVER['HTTP_ORIGIN'] ?? '';
$allowed = [APP_URL];
if (APP_DEBUG) { $allowed[] = 'http://localhost:5173'; $allowed[] = 'http://localhost:3000'; }
if (in_array($origin, $allowed, true)) {
    header("Access-Control-Allow-Origin: $origin");
    header('Vary: Origin');
} else {
    header('Access-Control-Allow-Origin: '.APP_URL);
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');
header('Access-Control-Allow-Credentials: true');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

// ─── Global exception → JSON ──────────────────────────────
set_exception_handler(function (Throwable $e) {
    $code = ($e instanceof Err) ? $e->getCode() : 500;
    $msg  = (APP_DEBUG || $e instanceof Err) ? $e->getMessage() : 'Server error';
    if (!($e instanceof Err)) error_log('[DOS] '.$e->getMessage().' @ '.$e->getFile().':'.$e->getLine());
    http_response_code(max(400, $code ?: 500));
    $r = ['success'=>false,'message'=>$msg];
    if ($e instanceof Unproc && $e->errs) $r['errors'] = $e->errs;
    echo json_encode($r); exit;
});

// ─── Parse request ────────────────────────────────────────
$method = $_SERVER['REQUEST_METHOD'];
$uri    = '/'.trim(preg_replace('#^.*/api#','',parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)),'/');
$body   = Validator::clean(json_decode(file_get_contents('php://input'),true) ?? []);
$q      = $_GET;
$segs   = array_values(array_filter(explode('/',$uri)));
$res    = $segs[0] ?? '';
$id     = $segs[1] ?? null;
$sub    = $segs[2] ?? null;

// ─── Global rate limit ─────────────────────────────────────
RateLimiter::hit('req:'.Auth::ip(), 200, 60);

// ═══ PUBLIC ROUTES ════════════════════════════════════════

if ($res === '' || $res === 'health') {
    Http::ok(['status'=>'ok','version'=>'2.0.0','php'=>PHP_VERSION]);
}

if ($res === 'auth') {
    // GET /auth/csrf — issue a CSRF token
    if ($method === 'GET' && $id === 'csrf') {
        Http::ok(['csrf_token' => Csrf::issue()]);
    }
    // POST /auth/login
    if ($method === 'POST' && $id === 'login') {
        Validator::check($body, ['email'=>'required|email','password'=>'required','tenant'=>'required']);
        Http::ok(Auth::login($body['email'], $body['password'], $body['tenant']));
    }
    // POST /auth/logout
    if ($method === 'POST' && $id === 'logout') {
        try { $u=Auth::require(); Audit::log('LOGOUT','user',$u['id'],$u['name']); } catch(Throwable){}
        setcookie('dos_token','',['expires'=>time()-3600,'path'=>'/','httponly'=>true]);
        setcookie('dos_csrf','',['expires'=>time()-3600,'path'=>'/']);
        Http::ok(['message'=>'Logged out']);
    }
    // POST /auth/forgot-password
    if ($method === 'POST' && $id === 'forgot-password') {
        Validator::check($body, ['email'=>'required|email','tenant'=>'required']);
        Auth::requestReset($body['email'], $body['tenant']);
        Http::ok(['message'=>'If that email exists, a reset link has been sent.']);
    }
    // POST /auth/reset-password
    if ($method === 'POST' && $id === 'reset-password') {
        Validator::check($body, ['token'=>'required','tenant'=>'required','password'=>'required|strong_password']);
        Auth::performReset($body['token'], $body['password'], $body['tenant']);
        Http::ok(['message'=>'Password updated. You can now log in.']);
    }
    // POST /auth/change-password — required when must_change_password=1, also usable any time
    if ($method === 'POST' && $id === 'change-password') {
        $u = Auth::require();
        Validator::check($body, ['current_password'=>'required','new_password'=>'required|strong_password']);
        if (!password_verify($body['current_password'], $u['password_hash'])) throw new AuthErr('Current password is incorrect.');
        Db::run("UPDATE users SET password_hash=?,must_change_password=0 WHERE id=? AND tenant_id=?",
            [Auth::hash($body['new_password']), $u['id'], Auth::tid()]);
        Audit::log('UPDATE','user',$u['id'],$u['name'],null,['action'=>'password_changed']);
        Http::ok(['message'=>'Password updated.']);
    }
    // POST /auth/verify-totp — second step of login when 2FA is enabled
    if ($method === 'POST' && $id === 'verify-totp') {
        Validator::check($body, ['pending_token'=>'required','code'=>'required']);
        Http::ok(Auth::verifyTotpLogin($body['pending_token'], $body['code']));
    }
    // GET /auth/me
    if ($method === 'GET' && $id === 'me') { Auth::require(); Http::ok(Auth::mePublic()); }
    throw new NotFound('Auth endpoint not found');
}

// ═══ ALL OTHER ROUTES REQUIRE AUTH + CSRF ════════════════
$user = Auth::require();
Csrf::verify($method);

// Security Audit (pw1): force a password change before anything else runs.
// /auth/change-password and /auth/logout are handled above and exempt.
if (!empty($user['must_change_password'])) {
    throw new Err('You must change your password before continuing.', 428);
}

// ─── Helper closures ──────────────────────────────────────
$tid = fn() => Auth::tid();
$uid = fn() => Auth::uid();
$nextNum = function(string $table, string $prefix) use ($tid): string {
    $n = (int) Db::val("SELECT COUNT(*) FROM $table WHERE tenant_id=? AND YEAR(created_at)=YEAR(NOW())", [$tid()]);
    return $prefix.'-'.date('Y').'-'.str_pad($n+1,4,'0',STR_PAD_LEFT);
};
$findOrFail = function(string $table, string $id) use ($tid): array {
    $r = Db::one("SELECT * FROM $table WHERE id=? AND tenant_id=? AND (deleted_at IS NULL OR deleted_at=0)", [$id,$tid()]);
    if (!$r) throw new NotFound(rtrim($table,'s').' not found');
    return $r;
};
$softDel = fn(string $table, string $id) => Db::run("UPDATE $table SET deleted_at=NOW() WHERE id=? AND tenant_id=?", [$id,$tid()]);

// ═══ DISPATCH ═════════════════════════════════════════════
match ($res) {
    'dashboard'       => route_dashboard($method,$id,$sub,$body,$q,$tid,$uid,$findOrFail,$softDel,$nextNum),
    'customers'       => route_customers($method,$id,$sub,$body,$q,$tid,$uid,$findOrFail,$softDel,$nextNum),
    'products'        => route_products($method,$id,$sub,$body,$q,$tid,$uid,$findOrFail,$softDel,$nextNum),
    'inventory'       => route_inventory($method,$id,$body,$q,$tid,$uid),
    'orders'          => route_orders($method,$id,$sub,$body,$q,$tid,$uid,$findOrFail,$softDel,$nextNum),
    'orders-bulk-status' => route_orders_bulk_status($method,$body,$tid,$uid),
    'purchase-orders' => route_po($method,$id,$sub,$body,$q,$tid,$uid,$findOrFail,$softDel,$nextNum),
    'suppliers'       => route_suppliers($method,$id,$body,$q,$tid,$uid,$findOrFail,$softDel),
    'invoices'        => route_invoices($method,$id,$body,$q,$tid,$uid,$nextNum),
    'payments'        => route_payments($method,$id,$body,$q,$tid,$uid),
    'notifications'   => route_notifications($method,$body,$tid,$uid),
    'audit-logs'      => route_audit($method,$q,$tid),
    'reports'         => route_reports($method,$q,$tid),
    'warehouses'      => route_warehouses($method,$id,$body,$tid,$uid),
    'users'           => route_users($method,$id,$body,$tid,$uid),
    'roles'           => route_roles($method,$id,$body,$tid,$uid),
    'workflow-rules'  => route_workflows($method,$id,$body,$tid),
    'export'          => route_export($method,$q,$tid),
    // ─── v3 roadmap routes (see routes_v3.php) ──────────────
    'price-lists'     => route_price_lists($method,$id,$sub,$body,$q,$tid,$uid,$findOrFail,$softDel),
    'credit-notes'    => route_credit_notes($method,$id,$body,$q,$tid,$uid,$nextNum),
    'returns'         => route_returns($method,$id,$sub,$body,$q,$tid,$uid,$findOrFail,$nextNum),
    'exchange-rates'  => route_exchange_rates($method,$id,$body,$q,$tid),
    'invoice-schedules' => route_invoice_schedules($method,$id,$body,$q,$tid,$uid),
    'bundles'         => route_bundles($method,$id,$body,$q,$tid),
    'webhooks'        => route_webhooks($method,$id,$body,$q,$tid,$uid),
    'integrations'    => route_integrations($method,$id,$sub,$body,$q,$tid,$uid),
    'api-keys'        => route_api_keys($method,$id,$body,$q,$tid,$uid),
    'two-factor'      => route_two_factor($method,$id,$body,$tid,$uid),
    'search'          => route_search($method,$q,$tid),
    'branding'        => route_branding($method,$body,$tid),
    'report-schedules' => route_report_schedules($method,$id,$body,$q,$tid,$uid),
    'data-requests'   => route_data_requests($method,$id,$body,$q,$tid,$uid),
    'sso-providers'   => route_sso_providers($method,$id,$body,$tid),
    'tax-report'      => route_tax_report($method,$q,$tid),
    'cogs-report'     => route_cogs_report($method,$q,$tid),
    'drivers'         => route_drivers($method,$id,$body,$tid),
    'vehicles'        => route_vehicles($method,$id,$body,$tid),
    'delivery-runs'   => route_delivery_runs($method,$id,$sub,$body,$q,$tid,$uid),
    'areas'           => route_areas($method,$id,$body,$q,$tid,$uid),
    'area-analytics'  => route_area_analytics($method,$q,$tid),
    'sales-reps'      => route_sales_reps($method,$id,$sub,$body,$q,$tid,$uid),
    'raw-materials'   => route_raw_materials($method,$id,$sub,$body,$q,$tid,$uid),
    'production'      => route_production($method,$id,$sub,$body,$q,$tid,$uid),
    'distributors'    => route_distributors($method,$id,$sub,$body,$q,$tid,$uid),
    'whatsapp-invoice'=> route_whatsapp_invoice($method,$id,$tid),
    'quotations'      => route_quotations($method,$id,$sub,$body,$q,$tid,$uid),
    'expenses'        => route_expenses($method,$id,$sub,$body,$q,$tid,$uid),
    'goods-receipts'  => route_goods_receipts($method,$id,$sub,$body,$q,$tid,$uid),
    'stock-adjustments'=> route_stock_adjustments($method,$id,$sub,$body,$q,$tid,$uid),
    'rep-visits'      => route_rep_visits($method,$id,$body,$q,$tid,$uid),
    'targets'         => route_targets($method,$id,$sub,$body,$q,$tid,$uid),
    'accounts'        => route_accounts($method,$id,$q,$tid),
    'analytics'       => route_reports_v5($method,$id,$q,$tid),
    'documents'       => (function() use ($method,$segs,$q,$tid,$uid) {
                             // /documents/{type}/{id}/pdf   |  /documents/{type}/{id}/share
                             $type = $segs[1] ?? ''; $docId = $segs[2] ?? ''; $action = $segs[3] ?? '';
                             if (!$type || !$docId) throw new NotFound('Document not specified');
                             if ($action === 'pdf')   route_document_pdf($method,$type,$docId,$tid);
                             if ($action === 'share') route_share_link($method,$type,$docId,$q,$tid,$uid);
                             throw new NotFound('Unknown document action');
                         })(),
    default           => throw new NotFound("Endpoint '/$res' not found"),
};

// ════════════════════════════════════════════════════════════
// ROUTE HANDLERS
// ════════════════════════════════════════════════════════════

function route_dashboard(string $m, $id, $sub, array $b, array $q, $tid, $uid, $findOrFail, $softDel, $nextNum): never {
    if ($m!=='GET') throw new Err('Method not allowed',405);
    Auth::need('dashboard','read');
    $t=$tid();
    // f08: accept an optional ?from=&to= range; defaults to the last 30 days
    // exactly as before when no range is supplied, so this is backward-compatible.
    [$rangeFrom,$rangeTo] = dos_resolve_date_range($q);
    $kpis = Db::one("SELECT
        (SELECT COALESCE(SUM(total_amount),0) FROM sales_orders WHERE tenant_id=? AND status='Delivered' AND order_date BETWEEN ? AND ? AND deleted_at IS NULL) monthly_revenue,
        (SELECT COUNT(*) FROM sales_orders WHERE tenant_id=? AND status IN('Draft','Pending Approval','Processing','Picking','Packing') AND deleted_at IS NULL) pending_orders,
        (SELECT COUNT(*) FROM customers WHERE tenant_id=? AND status='Active' AND deleted_at IS NULL) active_customers,
        (SELECT COUNT(*) FROM products p JOIN inventory i ON i.product_id=p.id WHERE p.tenant_id=? AND i.qty_on_hand<=p.reorder_point AND p.deleted_at IS NULL) low_stock_count,
        (SELECT COALESCE(SUM(total_amount-paid_amount),0) FROM invoices WHERE tenant_id=? AND status IN('Sent','Partially Paid','Overdue')) outstanding_ar",
        [$t,$rangeFrom,$rangeTo,$t,$t,$t,$t]);
    $revenue = Db::all("SELECT DATE_FORMAT(order_date,'%b %Y') label,DATE_FORMAT(order_date,'%Y-%m') month_key,COALESCE(SUM(total_amount),0) revenue,COUNT(*) order_count FROM sales_orders WHERE tenant_id=? AND status!='Cancelled' AND deleted_at IS NULL AND order_date BETWEEN ? AND ? GROUP BY DATE_FORMAT(order_date,'%Y-%m'),label ORDER BY month_key",[$t,$rangeFrom,$rangeTo]);
    $statusBreakdown = Db::all("SELECT status,COUNT(*) count,COALESCE(SUM(total_amount),0) total FROM sales_orders WHERE tenant_id=? AND order_date BETWEEN ? AND ? AND deleted_at IS NULL GROUP BY status",[$t,$rangeFrom,$rangeTo]);
    [$scSql,$scP] = Scope::orders('so');
    $recentOrders = Db::all("SELECT so.id,so.order_number,so.order_date,so.status,so.payment_status,so.total_amount,so.payment_mode,c.name customer_name FROM sales_orders so JOIN customers c ON c.id=so.customer_id WHERE so.tenant_id=? AND so.deleted_at IS NULL $scSql ORDER BY so.created_at DESC LIMIT 8",array_merge([$t],$scP));
    $lowStock = Db::all("SELECT p.id,p.sku,p.name,p.reorder_point,i.qty_on_hand,i.qty_available,w.name warehouse_name FROM products p JOIN inventory i ON i.product_id=p.id JOIN warehouses w ON w.id=i.warehouse_id WHERE p.tenant_id=? AND i.qty_on_hand<=p.reorder_point AND p.deleted_at IS NULL ORDER BY (i.qty_on_hand/NULLIF(p.reorder_point,0)) LIMIT 6",[$t]);
    Http::ok(compact('kpis','revenue','statusBreakdown','recentOrders','lowStock')+['range'=>['from'=>$rangeFrom,'to'=>$rangeTo]]);
}

// ─── CUSTOMERS ────────────────────────────────────────────
function route_customers(string $m, $id, $sub, array $b, array $q, $tid, $uid, $findOrFail, $softDel, $nextNum): never {
    // POST /customers/import — bulk CSV import
    if ($m==='POST' && $id==='import') {
        Auth::need('customers','create');
        $rows = $b['rows'] ?? [];
        if (!is_array($rows)||empty($rows)) throw new Unproc('No rows provided. Send {"rows":[...]} from parsed CSV.');
        $imported=0; $skipped=0; $errors=[];
        Db::begin();
        try {
            foreach ($rows as $i=>$row) {
                if (empty($row['name'])) { $skipped++; $errors[]="Row $i: name required"; continue; }
                $exists = Db::one("SELECT id FROM customers WHERE tenant_id=? AND LOWER(name)=LOWER(?)",[$tid(),$row['name']]);
                if ($exists) { $skipped++; continue; }
                $code = 'C'.str_pad((int)Db::val("SELECT COUNT(*) FROM customers WHERE tenant_id=?",[$tid()])+1,4,'0',STR_PAD_LEFT);
                Db::run("INSERT INTO customers(id,tenant_id,code,name,type,contact_name,email,phone,territory,credit_limit,payment_terms,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    [Db::uuid(),$tid(),$code,$row['name'],$row['type']??'Retail',$row['contact_name']??'',$row['email']??null,$row['phone']??null,$row['territory']??null,(float)($row['credit_limit']??0),$row['payment_terms']??'Net 30',$row['status']??'Active',$uid()]);
                $imported++;
            }
            Db::commit();
        } catch(Throwable $e){ Db::rollback(); throw $e; }
        Audit::log('CREATE','customer','bulk','CSV import',null,['imported'=>$imported,'skipped'=>$skipped]);
        Http::ok(['imported'=>$imported,'skipped'=>$skipped,'errors'=>$errors]);
    }
    $t=$tid();
    switch ($m) {
        case 'GET':
            Auth::need('customers','read');
            if ($id) {
                $r = $findOrFail('customers',$id);
                $orders = Db::all("SELECT id,order_number,order_date,status,payment_status,total_amount FROM sales_orders WHERE customer_id=? AND tenant_id=? AND deleted_at IS NULL ORDER BY order_date DESC LIMIT 6",[$id,$t]);
                Audit::log('VIEW','customer',$id,$r['name']);
                Http::ok(['customer'=>$r,'recent_orders'=>$orders]);
            }
            $s=$q['search']??''; $st=$q['status']??'';
            $p=[$t]; $w="WHERE tenant_id=? AND deleted_at IS NULL";
            if ($s){$w.=" AND(name LIKE ? OR code LIKE ? OR contact_name LIKE ? OR email LIKE ?)";$p=array_merge($p,["%$s%","%$s%","%$s%","%$s%"]);}
            if ($st){$w.=" AND status=?";$p[]=$st;}
            // Row-level scoping: a rep only sees shops inside their assigned areas.
            [$scSql,$scP] = Scope::customers('customers');
            if ($scSql) { $w .= $scSql; $p = array_merge($p, $scP); }
            [$rows,$pg]=Db::paged("SELECT * FROM customers $w ORDER BY name",$p,(int)($q['page']??1));
            Http::paged($rows,$pg);
        case 'POST':
            Auth::need('customers','create');
            Validator::check($b,['name'=>'required|max:200','type'=>'required','contact_name'=>'required','email'=>'email']);
            $id2=Db::uuid(); $code='C'.str_pad((int)Db::val("SELECT COUNT(*) FROM customers WHERE tenant_id=?",[$t])+1,4,'0',STR_PAD_LEFT);
            Db::run("INSERT INTO customers(id,tenant_id,code,name,type,group_name,contact_name,email,phone,address,city,country,territory,credit_limit,payment_terms,tax_number,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$id2,$t,$code,$b['name'],$b['type']??'Retail',$b['group_name']??null,$b['contact_name'],$b['email']??null,$b['phone']??null,$b['address']??null,$b['city']??null,$b['country']??null,$b['territory']??null,(float)($b['credit_limit']??0),$b['payment_terms']??'Net 30',$b['tax_number']??null,$b['status']??'Active',$b['notes']??null,$uid()]);
            Audit::log('CREATE','customer',$id2,$b['name'],null,$b);
            Http::created(Db::one("SELECT * FROM customers WHERE id=? AND tenant_id=?",[$id2,$t]));
        case 'PUT':
            Auth::need('customers','update');
            $row=$findOrFail('customers',$id);
            Validator::check($b,['name'=>'required|max:200','contact_name'=>'required','email'=>'email']);
            Db::run("UPDATE customers SET name=?,type=?,group_name=?,contact_name=?,email=?,phone=?,address=?,city=?,country=?,territory=?,credit_limit=?,payment_terms=?,tax_number=?,status=?,notes=? WHERE id=? AND tenant_id=?",
                [$b['name'],$b['type']??$row['type'],$b['group_name']??null,$b['contact_name'],$b['email']??null,$b['phone']??null,$b['address']??null,$b['city']??null,$b['country']??null,$b['territory']??null,(float)($b['credit_limit']??0),$b['payment_terms']??'Net 30',$b['tax_number']??null,$b['status']??'Active',$b['notes']??null,$id,$t]);
            Audit::log('UPDATE','customer',$id,$b['name'],$row,Audit::diff($row,$b));
            Http::ok(Db::one("SELECT * FROM customers WHERE id=? AND tenant_id=?",[$id,$t]));
        case 'DELETE':
            Auth::need('customers','delete');
            $row=$findOrFail('customers',$id);
            if (Db::val("SELECT COUNT(*) FROM sales_orders WHERE customer_id=? AND status NOT IN('Delivered','Cancelled') AND deleted_at IS NULL",[$id])) throw new Conflict('Customer has active orders.');
            $softDel('customers',$id);
            Audit::log('DELETE','customer',$id,$row['name'],$row);
            Http::noContent();
        default: throw new Err('Method not allowed',405);
    }
}

// ─── PRODUCTS ─────────────────────────────────────────────
function route_products(string $m, $id, $sub, array $b, array $q, $tid, $uid, $findOrFail, $softDel, $nextNum): never {
    // POST /products/import — bulk CSV import
    if ($m==='POST' && $id==='import') {
        Auth::need('products','create');
        $rows=$b['rows']??[];
        if (!is_array($rows)||empty($rows)) throw new Unproc('No rows provided.');
        $imported=0; $skipped=0; $errors=[];
        Db::begin();
        try {
            foreach ($rows as $i=>$row) {
                if (empty($row['name'])||empty($row['sku'])) { $errors[]="Row $i: name+sku required"; $skipped++; continue; }
                if (Db::one("SELECT id FROM products WHERE tenant_id=? AND sku=? AND deleted_at IS NULL",[$tid(),$row['sku']])) { $skipped++; continue; }
                Db::run("INSERT INTO products(id,tenant_id,sku,name,brand,unit_of_measure,cost_price,sale_price,reorder_point,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)",
                    [Db::uuid(),$tid(),$row['sku'],$row['name'],$row['brand']??null,$row['unit_of_measure']??'Piece',(float)($row['cost_price']??0),(float)($row['sale_price']??0),(int)($row['reorder_point']??0),$row['status']??'Active',$uid()]);
                $imported++;
            }
            Db::commit();
        } catch(Throwable $e){ Db::rollback(); throw $e; }
        Audit::log('CREATE','product','bulk','CSV import',null,['imported'=>$imported]);
        Http::ok(['imported'=>$imported,'skipped'=>$skipped,'errors'=>$errors]);
    }
    $t=$tid();
    switch ($m) {
        case 'GET':
            Auth::need('products','read');
            if ($id) {
                $r=$findOrFail('products',$id);
                $stock=Db::all("SELECT i.*,w.name warehouse_name FROM inventory i JOIN warehouses w ON w.id=i.warehouse_id WHERE i.product_id=? AND i.tenant_id=?",[$id,$t]);
                $moves=Db::all("SELECT sm.*,w.name warehouse_name FROM stock_movements sm JOIN warehouses w ON w.id=sm.warehouse_id WHERE sm.product_id=? AND sm.tenant_id=? ORDER BY sm.created_at DESC LIMIT 20",[$id,$t]);
                Http::ok(['product'=>$r,'stock'=>$stock,'movements'=>$moves]);
            }
            $s=$q['search']??''; $st=$q['status']??'';
            $p=[$t]; $w="WHERE p.tenant_id=? AND p.deleted_at IS NULL";
            if ($s){$w.=" AND(p.name LIKE ? OR p.sku LIKE ? OR p.brand LIKE ?)";$p=array_merge($p,["%$s%","%$s%","%$s%"]);}
            if ($st){$w.=" AND p.status=?";$p[]=$st;}
            [$rows,$pg]=Db::paged("SELECT p.*,cat.name category_name,COALESCE(SUM(i.qty_on_hand),0) total_stock,COALESCE(SUM(i.qty_reserved),0) total_reserved,COALESCE(SUM(i.qty_available),0) total_available FROM products p LEFT JOIN categories cat ON cat.id=p.category_id LEFT JOIN inventory i ON i.product_id=p.id $w GROUP BY p.id ORDER BY p.name",$p,(int)($q['page']??1));
            Http::paged($rows,$pg);
        case 'POST':
            Auth::need('products','create');
            Validator::check($b,['name'=>'required|max:300','sku'=>'required|max:100','cost_price'=>'required|numeric|min_val:0','sale_price'=>'required|numeric|min_val:0']);
            if (Db::one("SELECT id FROM products WHERE tenant_id=? AND sku=? AND deleted_at IS NULL",[$t,$b['sku']])) throw new Conflict('SKU already exists.');
            $id2=Db::uuid();
            Db::begin();
            try {
                Db::run("INSERT INTO products(id,tenant_id,category_id,sku,barcode,name,description,brand,unit_of_measure,cost_price,sale_price,reorder_point,reorder_qty,track_batches,track_serials,has_expiry,costing_method,status,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    [$id2,$t,$b['category_id']??null,$b['sku'],$b['barcode']??null,$b['name'],$b['description']??null,$b['brand']??null,$b['unit_of_measure']??'Piece',(float)$b['cost_price'],(float)$b['sale_price'],(int)($b['reorder_point']??0),(int)($b['reorder_qty']??0),(int)($b['track_batches']??0),(int)($b['track_serials']??0),(int)($b['has_expiry']??0),$b['costing_method']??'FIFO',$b['status']??'Active',$uid()]);
                if (!empty($b['warehouse_id']) && !empty($b['opening_stock'])) {
                    $qty=(float)$b['opening_stock'];
                    Db::run("INSERT INTO inventory(id,tenant_id,product_id,warehouse_id,qty_on_hand,avg_cost) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE qty_on_hand=qty_on_hand+?,avg_cost=?",[Db::uuid(),$t,$id2,$b['warehouse_id'],$qty,(float)$b['cost_price'],$qty,(float)$b['cost_price']]);
                    Db::run("INSERT INTO stock_movements(tenant_id,product_id,warehouse_id,type,qty,unit_cost,qty_before,qty_after,reason,created_by) VALUES(?,?,?,?,?,?,?,?,?,?)",[$t,$id2,$b['warehouse_id'],'IN',$qty,(float)$b['cost_price'],0,$qty,'Opening Stock',$uid()]);
                }
                Db::commit();
            } catch(Throwable $e){ Db::rollback(); throw $e; }
            Audit::log('CREATE','product',$id2,$b['name'],null,$b);
            Http::created(Db::one("SELECT * FROM products WHERE id=? AND tenant_id=?",[$id2,$t]));
        case 'PUT':
            Auth::need('products','update');
            $row=$findOrFail('products',$id);
            Validator::check($b,['name'=>'required|max:300','sku'=>'required|max:100','cost_price'=>'required|numeric|min_val:0','sale_price'=>'required|numeric|min_val:0']);
            Db::run("UPDATE products SET category_id=?,sku=?,barcode=?,name=?,description=?,brand=?,unit_of_measure=?,cost_price=?,sale_price=?,reorder_point=?,reorder_qty=?,track_batches=?,track_serials=?,has_expiry=?,costing_method=?,status=? WHERE id=? AND tenant_id=?",
                [$b['category_id']??null,$b['sku'],$b['barcode']??null,$b['name'],$b['description']??null,$b['brand']??null,$b['unit_of_measure']??'Piece',(float)$b['cost_price'],(float)$b['sale_price'],(int)($b['reorder_point']??0),(int)($b['reorder_qty']??0),(int)($b['track_batches']??0),(int)($b['track_serials']??0),(int)($b['has_expiry']??0),$b['costing_method']??'FIFO',$b['status']??'Active',$id,$t]);
            Audit::log('UPDATE','product',$id,$b['name'],$row,Audit::diff($row,$b));
            Http::ok(Db::one("SELECT * FROM products WHERE id=? AND tenant_id=?",[$id,$t]));
        case 'DELETE':
            Auth::need('products','delete');
            $row=$findOrFail('products',$id);
            if (Db::val("SELECT COUNT(*) FROM sales_order_items soi JOIN sales_orders so ON so.id=soi.order_id WHERE soi.product_id=? AND so.status NOT IN('Delivered','Cancelled') AND so.deleted_at IS NULL",[$id])) throw new Conflict('Product is in active orders.');
            Db::run("UPDATE products SET deleted_at=NOW(),status='Discontinued' WHERE id=? AND tenant_id=?",[$id,$t]);
            Audit::log('DELETE','product',$id,$row['name'],$row);
            Http::noContent();
        default: throw new Err('Method not allowed',405);
    }
}

// ─── INVENTORY ────────────────────────────────────────────
function route_inventory(string $m, $id, array $b, array $q, $tid, $uid): never {
    $t=$tid();
    if ($m==='GET') {
        Auth::need('inventory','read');
        $s=$q['search']??''; $al=$q['alert']??''; $wh=$q['warehouse_id']??'';
        $p=[$t]; $w="WHERE p.tenant_id=? AND p.deleted_at IS NULL";
        if ($s){$w.=" AND(p.name LIKE ? OR p.sku LIKE ?)";$p=array_merge($p,["%$s%","%$s%"]);}
        if ($al==='low')  $w.=" AND COALESCE(i.qty_on_hand,0)<=p.reorder_point";
        if ($al==='zero') $w.=" AND COALESCE(i.qty_on_hand,0)=0";
        if ($wh){$w.=" AND i.warehouse_id=?";$p[]=$wh;}
        [$rows,$pg]=Db::paged("SELECT p.id,p.sku,p.name,p.brand,p.unit_of_measure,p.reorder_point,p.cost_price,p.sale_price,p.status,i.warehouse_id,i.qty_on_hand,i.qty_reserved,i.qty_available,i.avg_cost,w.name warehouse_name,cat.name category_name FROM products p LEFT JOIN inventory i ON i.product_id=p.id LEFT JOIN warehouses w ON w.id=i.warehouse_id LEFT JOIN categories cat ON cat.id=p.category_id $w ORDER BY p.name",$p,(int)($q['page']??1));
        Http::paged($rows,$pg);
    }
    if ($m==='POST') {
        Auth::need('inventory','update');
        Validator::check($b,['product_id'=>'required','warehouse_id'=>'required','qty'=>'required|numeric','type'=>'required|in:IN,OUT,ADJUSTMENT,TRANSFER,RETURN,DAMAGED,COUNT','reason'=>'required']);
        $prod=Db::one("SELECT * FROM products WHERE id=? AND tenant_id=? AND deleted_at IS NULL",[$b['product_id'],$t]);
        if (!$prod) throw new NotFound('Product not found');
        Db::begin();
        try {
            $inv=Db::one("SELECT * FROM inventory WHERE product_id=? AND warehouse_id=? AND tenant_id=?",[$b['product_id'],$b['warehouse_id'],$t]);
            $before=(float)($inv['qty_on_hand']??0);
            $after=Calc::applyMovement($before,(float)$b['qty'],$b['type']);
            if (in_array($b['type'],['OUT','DAMAGED'])&&($before-abs((float)$b['qty']))<0) throw new Unproc("Insufficient stock. Available: $before");
            $reserved=(float)($inv['qty_reserved']??0);
            $afterAvail=max(0,$after-$reserved);
            Db::run("INSERT INTO inventory(id,tenant_id,product_id,warehouse_id,qty_on_hand,qty_available,avg_cost) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE qty_on_hand=?,qty_available=?",
                [Db::uuid(),$t,$b['product_id'],$b['warehouse_id'],$after,$afterAvail,$prod['cost_price'],$after,$afterAvail]);
            Db::run("INSERT INTO stock_movements(tenant_id,product_id,warehouse_id,type,batch_number,lot_number,expiry_date,qty,unit_cost,qty_before,qty_after,reason,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$t,$b['product_id'],$b['warehouse_id'],$b['type'],$b['batch_number']??null,$b['lot_number']??null,$b['expiry_date']??null,abs((float)$b['qty']),$prod['cost_price'],$before,$after,$b['reason'],$b['notes']??null,$uid()]);
            if ($after<=$prod['reorder_point']) Workflow::run('inventory','stock_below_reorder',['product_name'=>$prod['name'],'qty_on_hand'=>$after,'reorder_point'=>$prod['reorder_point']]);
            Db::commit();
        } catch(Throwable $e){ Db::rollback(); throw $e; }
        Audit::log('UPDATE','inventory',$b['product_id'],$prod['name'],['qty'=>$before],['qty'=>$after]);
        Http::ok(['qty_before'=>$before,'qty_after'=>$after,'delta'=>$after-$before]);
    }
    throw new Err('Method not allowed',405);
}

// ─── ORDERS ───────────────────────────────────────────────
function route_orders(string $m, $id, $sub, array $b, array $q, $tid, $uid, $findOrFail, $softDel, $nextNum): never {
    $t=$tid();
    // Sub-actions
    if ($id && $sub) {
        // GET /orders/{id}/picking-list — f02: warehouse-optimised pick list data,
        // grouped by bin location so pickers walk the warehouse in one efficient pass.
        if ($sub==='picking-list' && $m==='GET') {
            Auth::need('orders','read');
            $o=Db::one("SELECT so.*,c.name customer_name,w.name warehouse_name FROM sales_orders so JOIN customers c ON c.id=so.customer_id LEFT JOIN warehouses w ON w.id=so.warehouse_id WHERE so.id=? AND so.tenant_id=? AND so.deleted_at IS NULL",[$id,$t]);
            if (!$o) throw new NotFound('Order not found');
            $items=Db::all("SELECT soi.qty_ordered,soi.qty_backordered,p.sku,p.name product_name,p.unit_of_measure,COALESCE(i.bin_location,'—') bin_location FROM sales_order_items soi JOIN products p ON p.id=soi.product_id LEFT JOIN inventory i ON i.product_id=p.id AND i.warehouse_id=so.warehouse_id JOIN sales_orders so ON so.id=soi.order_id WHERE soi.order_id=? AND soi.tenant_id=? ORDER BY bin_location,p.sku",[$id,$t]);
            Audit::log('VIEW','sales_order',$id,$o['order_number'],null,['action'=>'picking_list_viewed']);
            Http::ok(['order'=>$o,'items'=>$items,'generated_at'=>date('c')]);
        }
        // GET /orders/{id}/packing-slip — f03: customer-facing delivery note data
        // (no prices — packing slips ship with goods, not the invoice amount).
        if ($sub==='packing-slip' && $m==='GET') {
            Auth::need('orders','read');
            $o=Db::one("SELECT so.*,c.name customer_name,c.address,c.city,c.country,c.contact_name FROM sales_orders so JOIN customers c ON c.id=so.customer_id WHERE so.id=? AND so.tenant_id=? AND so.deleted_at IS NULL",[$id,$t]);
            if (!$o) throw new NotFound('Order not found');
            $items=Db::all("SELECT soi.qty_ordered,p.sku,p.name product_name,p.unit_of_measure FROM sales_order_items soi JOIN products p ON p.id=soi.product_id WHERE soi.order_id=? AND soi.tenant_id=? ORDER BY p.sku",[$id,$t]);
            Audit::log('VIEW','sales_order',$id,$o['order_number'],null,['action'=>'packing_slip_viewed']);
            Http::ok(['order'=>$o,'items'=>$items,'generated_at'=>date('c')]);
        }
        // POST /orders/{id}/approve
        if ($sub==='approve' && $m==='POST') {
            Auth::need('orders','approve');
            $o=$findOrFail('sales_orders',$id);
            if ($o['status']!=='Pending Approval') throw new Unproc("Order is '{$o['status']}', not pending approval.");
            Db::run("UPDATE sales_orders SET status='Approved',approved_by=?,approved_at=NOW() WHERE id=? AND tenant_id=?",[$uid(),$id,$t]);
            Audit::log('UPDATE','sales_order',$id,$o['order_number'],['status'=>'Pending Approval'],['status'=>'Approved']);
            Http::ok(['message'=>'Order approved','order_number'=>$o['order_number']]);
        }
        // POST /orders/{id}/split — f11 back-order management: check stock availability
        // per line and flag any shortfall as backordered, without blocking the order.
        if ($sub==='split' && $m==='POST') {
            Auth::need('orders','update');
            Http::ok(dos_handle_order_split($t, $uid(), $id, $b));
        }
        // POST /orders/{id}/invoice — generate invoice from delivered order
        if ($sub==='invoice' && $m==='POST') {
            Auth::need('invoices','create');
            $o=Db::one("SELECT so.*,c.name customer_name,c.email customer_email FROM sales_orders so JOIN customers c ON c.id=so.customer_id WHERE so.id=? AND so.tenant_id=? AND so.deleted_at IS NULL",[$id,$t]);
            if (!$o) throw new NotFound('Order not found');
            if (!in_array($o['status'],['Delivered','Shipped'])) throw new Unproc('Can only invoice Delivered or Shipped orders.');
            if (Db::one("SELECT id FROM invoices WHERE order_id=? AND tenant_id=? AND status NOT IN('Cancelled')",[$id,$t])) throw new Conflict('An invoice already exists for this order.');
            $n=(int)Db::val("SELECT COUNT(*) FROM invoices WHERE tenant_id=?",[$t]);
            $inum='INV-'.date('Y').'-'.str_pad($n+1,4,'0',STR_PAD_LEFT);
            $due=date('Y-m-d',strtotime('+'.(int)($b['payment_days']??30).' days'));
            $invId=Db::uuid();
            Db::run("INSERT INTO invoices(id,tenant_id,invoice_number,order_id,customer_id,status,invoice_date,due_date,subtotal,tax_amount,shipping_amount,total_amount,currency,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$invId,$t,$inum,$id,$o['customer_id'],'Sent',date('Y-m-d'),$due,$o['subtotal']??$o['total_amount'],$o['tax_amount']??0,$o['shipping_amount']??0,$o['total_amount'],$o['currency']??'USD',$b['notes']??null,$uid()]);
            // Update order payment status to Unpaid (invoiced)
            Db::run("UPDATE sales_orders SET payment_status='Unpaid' WHERE id=? AND tenant_id=?",[$id,$t]);
            Audit::log('CREATE','invoice',$invId,$inum,null,['from_order'=>$o['order_number']]);
            // Notify customer if email exists
            if ($o['customer_email']) Mailer::send($o['customer_email'],"Invoice $inum from ".APP_NAME,"Dear {$o['customer_name']},\n\nPlease find your invoice $inum for order {$o['order_number']}.\n\nAmount Due: {$o['total_amount']} {$o['currency']}\nDue Date: $due\n\nThank you for your business.");
            Http::created(Db::one("SELECT * FROM invoices WHERE id=? AND tenant_id=?",[$invId,$t]));
        }
        // GET /orders/{id}/invoice — look up the invoice already raised for this order, if any
        if ($sub==='invoice' && $m==='GET') {
            Auth::need('invoices','read');
            $inv=Db::one("SELECT * FROM invoices WHERE order_id=? AND tenant_id=? AND status NOT IN('Cancelled') ORDER BY created_at DESC LIMIT 1",[$id,$t]);
            if (!$inv) throw new NotFound('No invoice has been created for this order yet.');
            Http::ok($inv);
        }
        // GET /orders/{id}/items
        if ($sub==='items' && $m==='GET') {
            Auth::need('orders','read');
            Http::ok(Db::all("SELECT soi.*,p.name product_name,p.sku,p.unit_of_measure FROM sales_order_items soi JOIN products p ON p.id=soi.product_id WHERE soi.order_id=? AND soi.tenant_id=? ORDER BY soi.sort_order",[$id,$t]));
        }
        throw new NotFound();
    }
    switch ($m) {
        case 'GET':
            Auth::need('orders','read');
            if ($id) {
                $o=Db::one("SELECT so.*,c.name customer_name,c.credit_limit,c.outstanding_balance,w.name warehouse_name,r.name rep_name FROM sales_orders so JOIN customers c ON c.id=so.customer_id LEFT JOIN warehouses w ON w.id=so.warehouse_id LEFT JOIN sales_reps r ON r.id=so.rep_id WHERE so.id=? AND so.tenant_id=? AND so.deleted_at IS NULL",[$id,$t]);
                if (!$o) throw new NotFound('Order not found');
                $items=Db::all("SELECT soi.*,p.name product_name,p.sku,p.unit_of_measure FROM sales_order_items soi JOIN products p ON p.id=soi.product_id WHERE soi.order_id=? AND soi.tenant_id=? ORDER BY soi.sort_order",[$id,$t]);
                Audit::log('VIEW','sales_order',$id,$o['order_number']);
                Http::ok(['order'=>$o,'items'=>$items]);
            }
            $s=$q['search']??''; $st=$q['status']??''; $rp=$q['rep_id']??'';
            $p=[$t]; $w="WHERE so.tenant_id=? AND so.deleted_at IS NULL";
            if ($s){$w.=" AND(so.order_number LIKE ? OR c.name LIKE ?)";$p=array_merge($p,["%$s%","%$s%"]);}
            if ($st){$w.=" AND so.status=?";$p[]=$st;}
            if ($rp){$w.=" AND so.rep_id=?";$p[]=$rp;}
            // Row-level scoping: a field rep only ever sees their own orders.
            [$scSql,$scP] = Scope::orders('so');
            if ($scSql) { $w .= $scSql; $p = array_merge($p, $scP); }
            [$rows,$pg]=Db::paged("SELECT so.*,c.name customer_name,r.name rep_name FROM sales_orders so JOIN customers c ON c.id=so.customer_id LEFT JOIN sales_reps r ON r.id=so.rep_id $w ORDER BY so.order_date DESC,so.created_at DESC",$p,(int)($q['page']??1));
            Http::paged($rows,$pg);
        case 'POST':
            Auth::need('orders','create');
            Validator::check($b,['customer_id'=>'required','order_date'=>'required|date']);
            if (empty($b['items'])||!is_array($b['items'])) throw new Unproc('At least one item is required.');
            $cust=Db::one("SELECT * FROM customers WHERE id=? AND tenant_id=? AND deleted_at IS NULL",[$b['customer_id'],$t]);
            if (!$cust) throw new NotFound('Customer not found');
            if ($cust['status']==='On Hold') throw new Unproc('Customer is on credit hold. Orders cannot be placed.');
            // A field rep's own orders are always attributed to them; a back-office user
            // creating on a rep's behalf may pass rep_id explicitly in the body.
            $b = Scope::stampRep($b);
            Db::begin();
            try {
                $oid=Db::uuid(); $onum=$nextNum('sales_orders','SO');
                $sub2=$tax=$disc=0; $idata=[];
                foreach ($b['items'] as $i=>$it) {
                    if (empty($it['product_id'])) continue;
                    $prod=Db::one("SELECT * FROM products WHERE id=? AND tenant_id=? AND deleted_at IS NULL",[$it['product_id'],$t]);
                    if (!$prod) continue;
                    $qty=(float)($it['qty_ordered']??1); $price=(float)($it['unit_price']??$prod['sale_price']);
                    $dp=(float)($it['discount_pct']??0); $tp=(float)($it['tax_pct']??0);
                    [$net,$taxA,$lt,$da]=Calc::lineTotal($qty,$price,$dp,$tp);
                    $sub2+=$net; $tax+=$taxA; $disc+=$da;
                    $idata[]=['id'=>Db::uuid(),'pid'=>$prod['id'],'qty'=>$qty,'price'=>$price,'dp'=>$dp,'da'=>$da,'tp'=>$tp,'ta'=>$taxA,'lt'=>$lt,'sort'=>$i,'notes'=>$it['notes']??null];
                }
                if (!$idata) throw new Unproc('No valid items found.');
                $total=round($sub2+$tax+(float)($b['shipping_amount']??0),2);
                if (Calc::exceedsCredit($cust['credit_limit'],$cust['outstanding_balance'],$total))
                    throw new Unproc(sprintf('Order exceeds credit limit ($%.2f). Balance: $%.2f, Order: $%.2f',$cust['credit_limit'],$cust['outstanding_balance'],$total));
                Db::run("INSERT INTO sales_orders(id,tenant_id,order_number,customer_id,warehouse_id,rep_id,status,payment_status,priority,order_date,delivery_date,subtotal,discount_amount,tax_amount,shipping_amount,total_amount,currency,notes,internal_notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    [$oid,$t,$onum,$b['customer_id'],$b['warehouse_id']??null,$b['rep_id']??null,$b['status']??'Draft','Unpaid',$b['priority']??'Normal',$b['order_date'],$b['delivery_date']??null,$sub2,$disc,$tax,(float)($b['shipping_amount']??0),$total,$b['currency']??'USD',$b['notes']??null,$b['internal_notes']??null,$uid()]);
                foreach ($idata as $it)
                    Db::run("INSERT INTO sales_order_items(id,tenant_id,order_id,product_id,qty_ordered,unit_price,discount_pct,discount_amount,tax_pct,tax_amount,line_total,sort_order,notes) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)",
                        [$it['id'],$t,$oid,$it['pid'],$it['qty'],$it['price'],$it['dp'],$it['da'],$it['tp'],$it['ta'],$it['lt'],$it['sort'],$it['notes']]);
                if (!empty($b['warehouse_id']))
                    foreach ($idata as $it) Db::run("UPDATE inventory SET qty_reserved=qty_reserved+? WHERE product_id=? AND warehouse_id=? AND tenant_id=?",[$it['qty'],$it['pid'],$b['warehouse_id'],$t]);
                Workflow::run('sales_order','created',['id'=>$oid,'order_number'=>$onum,'total_amount'=>$total,'customer_name'=>$cust['name']]);
                Db::commit();
            } catch(Throwable $e){ Db::rollback(); throw $e; }
            Audit::log('CREATE','sales_order',$oid,$onum,null,['total'=>$total]);
            Http::created(['order'=>Db::one("SELECT * FROM sales_orders WHERE id=? AND tenant_id=?",[$oid,$t]),'items'=>$idata]);
        case 'PUT':
            Auth::need('orders','update');
            $o=Db::one("SELECT * FROM sales_orders WHERE id=? AND tenant_id=? AND deleted_at IS NULL",[$id,$t]);
            if (!$o) throw new NotFound('Order not found');
            $newStatus = $b['status'] ?? $o['status'];
            if (!empty($b['status'])&&$b['status']!==$o['status']) {
                if (!Calc::canTransition($o['status'],$b['status']))
                    throw new Unproc("Cannot change '{$o['status']}' → '{$b['status']}'. Allowed: ".implode(', ',Calc::allowedTransitions($o['status'])));
            }
            $dat=($b['status']??'')==='Delivered'&&!$o['delivered_at']?date('Y-m-d H:i:s'):$o['delivered_at'];
            Db::begin();
            try {
                Db::run("UPDATE sales_orders SET status=?,payment_status=?,priority=?,delivery_date=?,notes=?,delivered_at=?,updated_by=? WHERE id=? AND tenant_id=?",
                    [$newStatus,$b['payment_status']??$o['payment_status'],$b['priority']??$o['priority'],$b['delivery_date']??$o['delivery_date'],$b['notes']??$o['notes'],$dat,$uid(),$id,$t]);
                // Inventory reservation lifecycle: qty_reserved is incremented once at order
                // creation (if a warehouse was set) and must be released exactly once, at
                // whichever of these two terminal transitions happens first — otherwise the
                // stock stays locked forever (Cancelled) or is double-counted as both on-hand
                // and reserved (Shipped, where goods actually leave the warehouse).
                if ($o['warehouse_id'] && $newStatus!==$o['status']) {
                    if ($newStatus==='Cancelled') {
                        dos_release_order_reservation($t,$o['warehouse_id'],$id);
                    } elseif ($newStatus==='Shipped') {
                        dos_ship_order_inventory($t,$uid(),$o['warehouse_id'],$id);
                    }
                }
                Db::commit();
            } catch (Throwable $e) { Db::rollback(); throw $e; }
            Audit::log('UPDATE','sales_order',$id,$o['order_number'],$o,Audit::diff($o,$b));
            Http::ok(Db::one("SELECT * FROM sales_orders WHERE id=? AND tenant_id=?",[$id,$t]));
        case 'DELETE':
            Auth::need('orders','delete');
            $o=$findOrFail('sales_orders',$id);
            if (!in_array($o['status'],['Draft','Cancelled'])) throw new Conflict('Only Draft or Cancelled orders can be deleted.');
            $softDel('sales_orders',$id);
            Audit::log('DELETE','sales_order',$id,$o['order_number'],$o);
            Http::noContent();
        default: throw new Err('Method not allowed',405);
    }
}

/**
 * Release the qty_reserved placed on a cancelled order's line items back to
 * available stock. Idempotent per-call within a transaction; must be paired
 * with the caller checking $order['warehouse_id'] was actually set (no
 * warehouse => no reservation was ever made at creation time).
 */
function dos_release_order_reservation(string $tid, string $warehouseId, string $orderId): void {
    $items = Db::all("SELECT product_id,qty_ordered FROM sales_order_items WHERE order_id=? AND tenant_id=?", [$orderId, $tid]);
    foreach ($items as $it) {
        Db::run("UPDATE inventory SET qty_reserved=GREATEST(0,qty_reserved-?) WHERE product_id=? AND warehouse_id=? AND tenant_id=?",
            [$it['qty_ordered'], $it['product_id'], $warehouseId, $tid]);
    }
}

/**
 * Goods physically leave the warehouse at Shipped: convert the standing
 * reservation into an actual stock deduction (qty_on_hand -= qty, qty_reserved
 * -= qty) and record a stock movement per line for the audit trail.
 */
function dos_ship_order_inventory(string $tid, string $uid, string $warehouseId, string $orderId): void {
    $items = Db::all("SELECT soi.product_id,soi.qty_ordered,p.cost_price FROM sales_order_items soi JOIN products p ON p.id=soi.product_id WHERE soi.order_id=? AND soi.tenant_id=?", [$orderId, $tid]);
    foreach ($items as $it) {
        $qty = (float) $it['qty_ordered'];
        $inv = Db::one("SELECT qty_on_hand FROM inventory WHERE product_id=? AND warehouse_id=? AND tenant_id=?", [$it['product_id'], $warehouseId, $tid]);
        $before = (float) ($inv['qty_on_hand'] ?? 0);
        $after  = max(0, $before - $qty);
        Db::run("UPDATE inventory SET qty_on_hand=?,qty_reserved=GREATEST(0,qty_reserved-?) WHERE product_id=? AND warehouse_id=? AND tenant_id=?",
            [$after, $qty, $it['product_id'], $warehouseId, $tid]);
        Db::run("INSERT INTO stock_movements(tenant_id,product_id,warehouse_id,type,reference_type,reference_id,qty,unit_cost,qty_before,qty_after,reason,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)",
            [$tid, $it['product_id'], $warehouseId, 'OUT', 'sales_order', $orderId, $qty, $it['cost_price'], $before, $after, 'Order Shipped', $uid]);
    }
}

// ─── PURCHASE ORDERS ──────────────────────────────────────
function route_po(string $m, $id, $sub, array $b, array $q, $tid, $uid, $findOrFail, $softDel, $nextNum): never {
    $t=$tid();
    // POST /purchase-orders/{id}/receive — receive goods into inventory
    if ($id && $sub==='receive' && $m==='POST') {
        Auth::need('inventory','update');
        $po=Db::one("SELECT po.*,w.id warehouse_id FROM purchase_orders po LEFT JOIN warehouses w ON w.id=po.warehouse_id WHERE po.id=? AND po.tenant_id=? AND po.deleted_at IS NULL",[$id,$t]);
        if (!$po) throw new NotFound('PO not found');
        if (!in_array($po['status'],['Confirmed','Sent','Partially Received'])) throw new Unproc("PO status '{$po['status']}' cannot be received.");
        $receiveItems=$b['items']??[];
        if (empty($receiveItems)) throw new Unproc('Provide items array with product_id and qty_received.');
        Db::begin();
        try {
            $allReceived=true;
            foreach ($receiveItems as $ri) {
                if (empty($ri['product_id'])||!isset($ri['qty_received'])||(float)$ri['qty_received']<=0) continue;
                $poItem=Db::one("SELECT * FROM purchase_order_items WHERE po_id=? AND product_id=? AND tenant_id=?",[$id,$ri['product_id'],$t]);
                if (!$poItem) continue;
                $qtyRec=(float)$ri['qty_received'];
                $newRec=min((float)$poItem['qty_ordered'],(float)$poItem['qty_received']+$qtyRec);
                Db::run("UPDATE purchase_order_items SET qty_received=? WHERE id=? AND tenant_id=?",[$newRec,$poItem['id'],$t]);
                // Increment inventory
                $wh=$ri['warehouse_id']??$po['warehouse_id'];
                if (!$wh) continue;
                $inv=Db::one("SELECT * FROM inventory WHERE product_id=? AND warehouse_id=? AND tenant_id=?",[$ri['product_id'],$wh,$t]);
                $before=(float)($inv['qty_on_hand']??0); $after=$before+$qtyRec;
                Db::run("INSERT INTO inventory(id,tenant_id,product_id,warehouse_id,qty_on_hand,avg_cost) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE qty_on_hand=qty_on_hand+?",
                    [Db::uuid(),$t,$ri['product_id'],$wh,$after,$poItem['unit_cost'],$qtyRec]);
                Db::run("INSERT INTO stock_movements(tenant_id,product_id,warehouse_id,type,reference_type,reference_id,batch_number,lot_number,expiry_date,qty,unit_cost,qty_before,qty_after,reason,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    [$t,$ri['product_id'],$wh,'IN','purchase_order',$id,$ri['batch_number']??null,$ri['lot_number']??null,$ri['expiry_date']??null,$qtyRec,$poItem['unit_cost'],$before,$after,"PO Receipt: {$po['po_number']}",$ri['notes']??null,$uid()]);
                if ($newRec<(float)$poItem['qty_ordered']) $allReceived=false;
            }
            // Update PO status
            $newPoStatus=$allReceived?'Received':'Partially Received';
            Db::run("UPDATE purchase_orders SET status=? WHERE id=? AND tenant_id=?",[$newPoStatus,$id,$t]);
            Db::commit();
        } catch(Throwable $e){ Db::rollback(); throw $e; }
        Audit::log('UPDATE','purchase_order',$id,$po['po_number'],['status'=>$po['status']],['status'=>$allReceived?'Received':'Partially Received']);
        Http::ok(['message'=>'Goods received','status'=>$allReceived?'Received':'Partially Received','po_number'=>$po['po_number']]);
    }
    switch ($m) {
        case 'GET':
            Auth::need('procurement','read');
            if ($id) {
                $po=Db::one("SELECT po.*,s.name supplier_name,w.name warehouse_name FROM purchase_orders po JOIN suppliers s ON s.id=po.supplier_id LEFT JOIN warehouses w ON w.id=po.warehouse_id WHERE po.id=? AND po.tenant_id=? AND po.deleted_at IS NULL",[$id,$t]);
                if (!$po) throw new NotFound('PO not found');
                $items=Db::all("SELECT poi.*,p.name product_name,p.sku,p.unit_of_measure FROM purchase_order_items poi JOIN products p ON p.id=poi.product_id WHERE poi.po_id=? AND poi.tenant_id=? ORDER BY poi.sort_order",[$id,$t]);
                Http::ok(['po'=>$po,'items'=>$items]);
            }
            $s=$q['search']??''; $st=$q['status']??''; $sup=$q['supplier_id']??'';
            $p=[$t]; $w="WHERE po.tenant_id=? AND po.deleted_at IS NULL";
            if ($s){$w.=" AND(po.po_number LIKE ? OR su.name LIKE ?)";$p=array_merge($p,["%$s%","%$s%"]);}
            if ($st){$w.=" AND po.status=?";$p[]=$st;}
            if ($sup){$w.=" AND po.supplier_id=?";$p[]=$sup;}
            [$rows,$pg]=Db::paged("SELECT po.*,su.name supplier_name FROM purchase_orders po JOIN suppliers su ON su.id=po.supplier_id $w ORDER BY po.order_date DESC",$p,(int)($q['page']??1));
            Http::paged($rows,$pg);
        case 'POST':
            Auth::need('procurement','create');
            Validator::check($b,['supplier_id'=>'required','order_date'=>'required|date']);
            if (empty($b['items'])) throw new Unproc('At least one item required.');
            $supp=Db::one("SELECT * FROM suppliers WHERE id=? AND tenant_id=? AND deleted_at IS NULL",[$b['supplier_id'],$t]);
            if (!$supp) throw new NotFound('Supplier not found');
            Db::begin();
            try {
                $pid=Db::uuid(); $pnum=$nextNum('purchase_orders','PO');
                $sub2=$tax=0; $idata=[];
                foreach ($b['items'] as $i=>$it) {
                    if (empty($it['product_id'])) continue;
                    $prod=Db::one("SELECT * FROM products WHERE id=? AND tenant_id=? AND deleted_at IS NULL",[$it['product_id'],$t]);
                    if (!$prod) continue;
                    $qty=(float)($it['qty_ordered']??1); $cost=(float)($it['unit_cost']??$prod['cost_price']); $tp=(float)($it['tax_pct']??0);
                    $ta=round($qty*$cost*$tp/100,2); $lt=round($qty*$cost+$ta,2);
                    $sub2+=$qty*$cost; $tax+=$ta;
                    $idata[]=['id'=>Db::uuid(),'pid'=>$prod['id'],'qty'=>$qty,'cost'=>$cost,'tp'=>$tp,'ta'=>$ta,'lt'=>$lt,'sort'=>$i];
                }
                if (!$idata) throw new Unproc('No valid items found.');
                $total=round($sub2+$tax+(float)($b['shipping_amount']??0),2);
                Db::run("INSERT INTO purchase_orders(id,tenant_id,po_number,supplier_id,warehouse_id,status,order_date,expected_date,subtotal,tax_amount,shipping_amount,total_amount,currency,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                    [$pid,$t,$pnum,$b['supplier_id'],$b['warehouse_id']??null,$b['status']??'Draft',$b['order_date'],$b['expected_date']??null,$sub2,$tax,(float)($b['shipping_amount']??0),$total,$b['currency']??'USD',$b['notes']??null,$uid()]);
                foreach ($idata as $it)
                    Db::run("INSERT INTO purchase_order_items(id,tenant_id,po_id,product_id,qty_ordered,qty_received,unit_cost,tax_pct,tax_amount,line_total,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?)",
                        [$it['id'],$t,$pid,$it['pid'],$it['qty'],0,$it['cost'],$it['tp'],$it['ta'],$it['lt'],$it['sort']]);
                Db::commit();
            } catch(Throwable $e){ Db::rollback(); throw $e; }
            Audit::log('CREATE','purchase_order',$pid,$pnum,null,['total'=>$total]);
            Http::created(['po'=>Db::one("SELECT * FROM purchase_orders WHERE id=? AND tenant_id=?",[$pid,$t]),'items'=>$idata]);
        case 'PUT':
            Auth::need('procurement','update');
            $po=$findOrFail('purchase_orders',$id);
            Db::run("UPDATE purchase_orders SET status=?,expected_date=?,notes=?,updated_by=? WHERE id=? AND tenant_id=?",[$b['status']??$po['status'],$b['expected_date']??$po['expected_date'],$b['notes']??$po['notes'],$uid(),$id,$t]);
            Audit::log('UPDATE','purchase_order',$id,$po['po_number'],$po,Audit::diff($po,$b));
            Http::ok(Db::one("SELECT * FROM purchase_orders WHERE id=? AND tenant_id=?",[$id,$t]));
        case 'DELETE':
            Auth::need('procurement','delete');
            $po=$findOrFail('purchase_orders',$id);
            if (!in_array($po['status'],['Draft','Cancelled'])) throw new Conflict('Only Draft/Cancelled POs can be deleted.');
            $softDel('purchase_orders',$id);
            Audit::log('DELETE','purchase_order',$id,$po['po_number'],$po);
            Http::noContent();
        default: throw new Err('Method not allowed',405);
    }
}

// ─── SUPPLIERS ────────────────────────────────────────────
function route_suppliers(string $m, $id, array $b, array $q, $tid, $uid, $findOrFail, $softDel): never {
    $t=$tid();
    switch ($m) {
        case 'GET':
            Auth::need('suppliers','read');
            if ($id) Http::ok($findOrFail('suppliers',$id));
            $s=$q['search']??''; $st=$q['status']??''; $p=[$t]; $w="WHERE tenant_id=? AND deleted_at IS NULL";
            if ($s){$w.=" AND(name LIKE ? OR code LIKE ?)";$p=array_merge($p,["%$s%","%$s%"]);}
            if ($st){$w.=" AND status=?";$p[]=$st;}
            [$rows,$pg]=Db::paged("SELECT * FROM suppliers $w ORDER BY name",$p,(int)($q['page']??1));
            Http::paged($rows,$pg);
        case 'POST':
            Auth::need('suppliers','create');
            Validator::check($b,['name'=>'required|max:200','contact_name'=>'required','email'=>'email']);
            $id2=Db::uuid(); $code='S'.str_pad((int)Db::val("SELECT COUNT(*) FROM suppliers WHERE tenant_id=?",[$t])+1,4,'0',STR_PAD_LEFT);
            Db::run("INSERT INTO suppliers(id,tenant_id,code,name,contact_name,email,phone,address,city,country,payment_terms,tax_number,status,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)",
                [$id2,$t,$code,$b['name'],$b['contact_name'],$b['email']??null,$b['phone']??null,$b['address']??null,$b['city']??null,$b['country']??null,$b['payment_terms']??'Net 30',$b['tax_number']??null,$b['status']??'Active',$b['notes']??null,$uid()]);
            Audit::log('CREATE','supplier',$id2,$b['name'],null,$b);
            Http::created(Db::one("SELECT * FROM suppliers WHERE id=? AND tenant_id=?",[$id2,$t]));
        case 'PUT':
            Auth::need('suppliers','update');
            $row=$findOrFail('suppliers',$id);
            Db::run("UPDATE suppliers SET name=?,contact_name=?,email=?,phone=?,address=?,city=?,country=?,payment_terms=?,tax_number=?,status=?,notes=? WHERE id=? AND tenant_id=?",
                [$b['name']??$row['name'],$b['contact_name']??$row['contact_name'],$b['email']??null,$b['phone']??null,$b['address']??null,$b['city']??null,$b['country']??null,$b['payment_terms']??'Net 30',$b['tax_number']??null,$b['status']??'Active',$b['notes']??null,$id,$t]);
            Audit::log('UPDATE','supplier',$id,$b['name']??$row['name'],$row,Audit::diff($row,$b));
            Http::ok(Db::one("SELECT * FROM suppliers WHERE id=? AND tenant_id=?",[$id,$t]));
        case 'DELETE':
            Auth::need('suppliers','delete');
            $row=$findOrFail('suppliers',$id);
            $softDel('suppliers',$id);
            Audit::log('DELETE','supplier',$id,$row['name'],$row);
            Http::noContent();
        default: throw new Err('Method not allowed',405);
    }
}

// ─── INVOICES ─────────────────────────────────────────────
function route_invoices(string $m, $id, array $b, array $q, $tid, $uid, $nextNum): never {
    $t=$tid(); Auth::need('invoices','read');
    if ($m==='GET') {
        if ($id) { $r=Db::one("SELECT i.*,c.name customer_name FROM invoices i JOIN customers c ON c.id=i.customer_id WHERE i.id=? AND i.tenant_id=?",[$id,$t]); if(!$r) throw new NotFound(); Http::ok($r); }
        $p=[$t]; $w="WHERE i.tenant_id=?";
        if (!empty($q['search'])){$w.=" AND(i.invoice_number LIKE ? OR c.name LIKE ?)";$p[]="%{$q['search']}%";$p[]="%{$q['search']}%";}
        if (!empty($q['status'])){$w.=" AND i.status=?";$p[]=$q['status'];}
        if (!empty($q['customer_id'])){$w.=" AND i.customer_id=?";$p[]=$q['customer_id'];}
        // Row-level scoping: a field rep only sees invoices they raised.
        [$scSql,$scP] = Scope::invoices('i');
        if ($scSql) { $w .= $scSql; $p = array_merge($p, $scP); }
        [$rows,$pg]=Db::paged("SELECT i.*,c.name customer_name FROM invoices i JOIN customers c ON c.id=i.customer_id $w ORDER BY i.invoice_date DESC",$p,(int)($q['page']??1));
        Http::paged($rows,$pg);
    }
    if ($m==='POST') {
        Auth::need('invoices','create');
        Validator::check($b,['customer_id'=>'required','total_amount'=>'required|numeric|min_val:0']);
        $cust=Db::one("SELECT id FROM customers WHERE id=? AND tenant_id=? AND deleted_at IS NULL",[$b['customer_id'],$t]);
        if (!$cust) throw new NotFound('Customer not found');
        $id2=Db::uuid(); $inum='INV-'.date('Y').'-'.str_pad((int)Db::val("SELECT COUNT(*) FROM invoices WHERE tenant_id=?",[$t])+1,4,'0',STR_PAD_LEFT);
        Db::run("INSERT INTO invoices(id,tenant_id,invoice_number,order_id,customer_id,status,invoice_date,due_date,total_amount,currency,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)",
            [$id2,$t,$inum,$b['order_id']??null,$b['customer_id'],$b['status']??'Draft',$b['invoice_date']??date('Y-m-d'),$b['due_date']??null,(float)$b['total_amount'],$b['currency']??'USD',$b['notes']??null,$uid()]);
        Audit::log('CREATE','invoice',$id2,$inum,null,['total'=>$b['total_amount']]);
        Http::created(Db::one("SELECT * FROM invoices WHERE id=? AND tenant_id=?",[$id2,$t]));
    }
    if ($m==='PUT') {
        Auth::need('invoices','update');
        $row=Db::one("SELECT * FROM invoices WHERE id=? AND tenant_id=?",[$id,$t]);
        if (!$row) throw new NotFound('Invoice not found');
        Db::run("UPDATE invoices SET status=?,due_date=?,notes=? WHERE id=? AND tenant_id=?",[$b['status']??'Draft',$b['due_date']??null,$b['notes']??null,$id,$t]);
        Audit::log('UPDATE','invoice',$id,$row['invoice_number'],$row,Audit::diff($row,$b));
        Http::ok(Db::one("SELECT * FROM invoices WHERE id=? AND tenant_id=?",[$id,$t]));
    }
    throw new Err('Method not allowed',405);
}

// ─── PAYMENTS ─────────────────────────────────────────────
function route_payments(string $m, $id, array $b, array $q, $tid, $uid): never {
    $t=$tid(); Auth::need('payments','read');
    if ($m==='GET') Http::ok(Db::all("SELECT p.*,c.name customer_name FROM payments p LEFT JOIN customers c ON c.id=p.customer_id WHERE p.tenant_id=? ORDER BY p.payment_date DESC LIMIT 100",[$t]));
    if ($m==='POST') {
        Auth::need('payments','create');
        Validator::check($b,['amount'=>'required|numeric|min_val:0.01','payment_date'=>'required|date','method'=>'required']);
        $id2=Db::uuid();
        Db::begin();
        try {
            Db::run("INSERT INTO payments(id,tenant_id,invoice_id,customer_id,amount,currency,method,reference,payment_date,notes,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)",
                [$id2,$t,$b['invoice_id']??null,$b['customer_id']??null,(float)$b['amount'],$b['currency']??'USD',$b['method'],$b['reference']??null,$b['payment_date'],$b['notes']??null,$uid()]);
            if (!empty($b['invoice_id'])) {
                // Lock the invoice row for this transaction so two concurrent payments
                // against the same invoice can't both read the same paid_amount and race.
                $inv=Db::one("SELECT * FROM invoices WHERE id=? AND tenant_id=? FOR UPDATE",[$b['invoice_id'],$t]);
                if ($inv) {
                    $np=(float)$inv['paid_amount']+(float)$b['amount'];
                    $ns=$np>=(float)$inv['total_amount']?'Paid':($np>0?'Partially Paid':$inv['status']);
                    Db::run("UPDATE invoices SET paid_amount=?,status=? WHERE id=? AND tenant_id=?",[$np,$ns,$b['invoice_id'],$t]);
                    Db::run("UPDATE customers SET outstanding_balance=outstanding_balance-? WHERE id=? AND tenant_id=?",[(float)$b['amount'],$inv['customer_id'],$t]);
                }
            }
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }
        Audit::log('CREATE','payment',$id2,'$'.$b['amount']);
        Http::created(Db::one("SELECT * FROM payments WHERE id=? AND tenant_id=?",[$id2,$t]));
    }
    throw new Err('Method not allowed',405);
}

// ─── NOTIFICATIONS ────────────────────────────────────────
function route_notifications(string $m, array $b, $tid, $uid): never {
    $t=$tid(); $u=$uid();
    if ($m==='GET') { $uc=(int)Db::val("SELECT COUNT(*) FROM notifications WHERE user_id=? AND tenant_id=? AND is_read=0",[$u,$t]); Http::ok(['unread_count'=>$uc,'notifications'=>Db::all("SELECT * FROM notifications WHERE user_id=? AND tenant_id=? ORDER BY created_at DESC LIMIT 30",[$u,$t])]); }
    if ($m==='POST') { if (!empty($b['id'])) Db::run("UPDATE notifications SET is_read=1,read_at=NOW() WHERE id=? AND user_id=?",[$b['id'],$u]); else Db::run("UPDATE notifications SET is_read=1,read_at=NOW() WHERE user_id=? AND tenant_id=?",[$u,$t]); Http::ok(['message'=>'Marked as read']); }
    throw new Err('Method not allowed',405);
}

// ─── AUDIT LOG ────────────────────────────────────────────
function route_audit(string $m, array $q, $tid): never {
    if ($m!=='GET') throw new Err('Method not allowed',405);
    Auth::need('audit','read'); $t=$tid(); $p=[$t]; $w="WHERE tenant_id=?";
    if (!empty($q['entity_type'])){$w.=" AND entity_type=?";$p[]=$q['entity_type'];}
    if (!empty($q['user_id'])){$w.=" AND user_id=?";$p[]=$q['user_id'];}
    [$rows,$pg]=Db::paged("SELECT * FROM audit_logs $w ORDER BY created_at DESC",$p,(int)($q['page']??1),50);
    Http::paged($rows,$pg);
}

// ─── REPORTS ──────────────────────────────────────────────
function route_reports(string $m, array $q, $tid): never {
    if ($m!=='GET') throw new Err('Method not allowed',405);
    Auth::need('reports','read'); $t=$tid(); $from=$q['from']??date('Y-m-01'); $to=$q['to']??date('Y-m-d'); $p=[$t,$from,$to];
    $salesSummary=Db::all("SELECT status,COUNT(*) count,COALESCE(SUM(total_amount),0) total,COALESCE(SUM(paid_amount),0) paid FROM sales_orders WHERE tenant_id=? AND order_date BETWEEN ? AND ? AND deleted_at IS NULL GROUP BY status",$p);
    $topCustomers=Db::all("SELECT c.name,COUNT(so.id) order_count,COALESCE(SUM(so.total_amount),0) revenue FROM sales_orders so JOIN customers c ON c.id=so.customer_id WHERE so.tenant_id=? AND so.order_date BETWEEN ? AND ? AND so.status!='Cancelled' AND so.deleted_at IS NULL GROUP BY c.id,c.name ORDER BY revenue DESC LIMIT 10",$p);
    $topProducts=Db::all("SELECT p.name,p.sku,SUM(soi.qty_ordered) qty_sold,SUM(soi.line_total) revenue FROM sales_order_items soi JOIN products p ON p.id=soi.product_id JOIN sales_orders so ON so.id=soi.order_id WHERE soi.tenant_id=? AND so.order_date BETWEEN ? AND ? AND so.status!='Cancelled' AND so.deleted_at IS NULL GROUP BY p.id,p.name,p.sku ORDER BY revenue DESC LIMIT 10",$p);
    $monthlyTrend=Db::all("SELECT DATE_FORMAT(order_date,'%Y-%m') month,DATE_FORMAT(order_date,'%b %Y') label,COUNT(*) orders,COALESCE(SUM(total_amount),0) revenue FROM sales_orders WHERE tenant_id=? AND order_date BETWEEN DATE_SUB(?,INTERVAL 5 MONTH) AND ? AND deleted_at IS NULL GROUP BY DATE_FORMAT(order_date,'%Y-%m') ORDER BY month",[$t,$from,$to]);
    $arAgeing=Db::one("SELECT SUM(CASE WHEN DATEDIFF(CURDATE(),invoice_date)<=30 THEN total_amount-paid_amount ELSE 0 END) current_30,SUM(CASE WHEN DATEDIFF(CURDATE(),invoice_date) BETWEEN 31 AND 60 THEN total_amount-paid_amount ELSE 0 END) days_31_60,SUM(CASE WHEN DATEDIFF(CURDATE(),invoice_date) BETWEEN 61 AND 90 THEN total_amount-paid_amount ELSE 0 END) days_61_90,SUM(CASE WHEN DATEDIFF(CURDATE(),invoice_date)>90 THEN total_amount-paid_amount ELSE 0 END) over_90 FROM invoices WHERE tenant_id=? AND status NOT IN('Paid','Cancelled','Written Off')",[$t]);
    Http::ok(compact('salesSummary','topCustomers','topProducts','monthlyTrend','arAgeing'));
}

// ─── EXPORT (CSV + simple PDF/Excel-compatible) ───────────
function route_export(string $m, array $q, $tid): never {
    if ($m!=='GET') throw new Err('Method not allowed',405);
    Auth::need('reports','read'); $t=$tid();
    $type=$q['type']??'orders'; $fmt=$q['format']??'csv';
    Audit::log('EXPORT',$type,null,"export.$fmt");
    $data=match($type) {
        'orders'    => Db::all("SELECT so.order_number,c.name customer,so.order_date,so.delivery_date,so.status,so.payment_status,so.total_amount,so.paid_amount FROM sales_orders so JOIN customers c ON c.id=so.customer_id WHERE so.tenant_id=? AND so.deleted_at IS NULL ORDER BY so.order_date DESC LIMIT 5000",[$t]),
        'customers' => Db::all("SELECT code,name,type,contact_name,email,phone,territory,credit_limit,outstanding_balance,status FROM customers WHERE tenant_id=? AND deleted_at IS NULL ORDER BY name LIMIT 5000",[$t]),
        'products'  => Db::all("SELECT p.sku,p.name,p.brand,cat.name category,p.unit_of_measure,p.cost_price,p.sale_price,p.reorder_point,p.status,COALESCE(SUM(i.qty_on_hand),0) stock FROM products p LEFT JOIN categories cat ON cat.id=p.category_id LEFT JOIN inventory i ON i.product_id=p.id WHERE p.tenant_id=? AND p.deleted_at IS NULL GROUP BY p.id ORDER BY p.name LIMIT 5000",[$t]),
        'inventory' => Db::all("SELECT p.sku,p.name,w.name warehouse,i.qty_on_hand,i.qty_reserved,i.qty_available,p.reorder_point FROM inventory i JOIN products p ON p.id=i.product_id JOIN warehouses w ON w.id=i.warehouse_id WHERE i.tenant_id=? ORDER BY p.name LIMIT 5000",[$t]),
        default     => throw new Unproc('Invalid export type. Use: orders|customers|products|inventory'),
    };
    if (empty($data)) Http::ok(['message'=>'No data to export','rows'=>0]);
    // Return JSON (frontend converts to CSV/Excel via SheetJS or downloads as CSV)
    Http::ok(['type'=>$type,'format'=>$fmt,'rows'=>count($data),'data'=>$data,'headers'=>array_keys($data[0]??[])]);
}

// ─── WAREHOUSES ───────────────────────────────────────────
function route_warehouses(string $m, $id, array $b, $tid, $uid): never {
    $t=$tid(); Auth::need('warehouses','read');
    if ($m==='GET') { if ($id) Http::ok(Db::one("SELECT * FROM warehouses WHERE id=? AND tenant_id=?",[$id,$t])); Http::ok(Db::all("SELECT * FROM warehouses WHERE tenant_id=? ORDER BY name",[$t])); }
    if ($m==='POST') { Auth::need('warehouses','create'); $id2=Db::uuid(); Db::run("INSERT INTO warehouses(id,tenant_id,code,name,address,city,country,is_active) VALUES(?,?,?,?,?,?,?,1)",[$id2,$t,strtoupper($b['code']??''),$b['name']??'',$b['address']??null,$b['city']??null,$b['country']??null]); Http::created(Db::one("SELECT * FROM warehouses WHERE id=? AND tenant_id=?",[$id2,$t])); }
    if ($m==='PUT') { Auth::need('warehouses','update'); Db::run("UPDATE warehouses SET name=?,city=?,country=?,is_active=? WHERE id=? AND tenant_id=?",[$b['name']??'',$b['city']??null,$b['country']??null,(int)($b['is_active']??1),$id,$t]); Http::ok(Db::one("SELECT * FROM warehouses WHERE id=? AND tenant_id=?",[$id,$t])); }
    throw new Err('Warehouses cannot be deleted. Set is_active=0 instead.',405);
}

// ─── USERS ────────────────────────────────────────────────
function route_users(string $m, $id, array $b, $tid, $uid): never {
    $t=$tid(); Auth::need('users','read');
    if ($m==='GET') Http::ok(Db::all("SELECT u.id,u.name,u.email,u.phone,u.is_active,u.last_login_at,r.name role_name FROM users u JOIN roles r ON r.id=u.role_id WHERE u.tenant_id=? AND u.deleted_at IS NULL ORDER BY u.name",[$t]));
    if ($m==='POST') { Auth::need('users','create'); Validator::check($b,['name'=>'required','email'=>'required|email','password'=>'required|strong_password','role_id'=>'required']); if (Db::one("SELECT id FROM users WHERE tenant_id=? AND email=? AND deleted_at IS NULL",[$t,strtolower($b['email'])])) throw new Conflict('Email already in use.'); $id2=Db::uuid(); Db::run("INSERT INTO users(id,tenant_id,role_id,name,email,password_hash,phone,is_active) VALUES(?,?,?,?,?,?,?,1)",[$id2,$t,$b['role_id'],$b['name'],strtolower($b['email']),Auth::hash($b['password']),$b['phone']??null]); Audit::log('CREATE','user',$id2,$b['name']); Http::created(['id'=>$id2,'name'=>$b['name'],'email'=>$b['email']]); }
    if ($m==='PUT') {
        Auth::need('users','update');
        $ps=[$b['name']??'',$b['phone']??null,(int)($b['is_active']??1)]; $ss="UPDATE users SET name=?,phone=?,is_active=?";
        if (!empty($b['password'])){
            if (!Validator::isStrongPassword($b['password'])) throw new Unproc('Password must be 8+ chars with uppercase, lowercase, and a number.', ['password'=>['Weak password']]);
            $ss.=",password_hash=?";$ps[]=Auth::hash($b['password']);
        }
        if (!empty($b['role_id'])){$ss.=",role_id=?";$ps[]=$b['role_id'];}
        $ps[]=$id; $ps[]=$t; Db::run($ss." WHERE id=? AND tenant_id=?",$ps);
        Audit::log('UPDATE','user',$id,$b['name']??'');
        Http::ok(Db::one("SELECT id,name,email,phone,is_active FROM users WHERE id=? AND tenant_id=?",[$id,$t]));
    }
    if ($m==='DELETE') { Auth::need('users','delete'); if ($id===$uid()) throw new Unproc('Cannot delete your own account.'); Db::run("UPDATE users SET deleted_at=NOW(),is_active=0 WHERE id=? AND tenant_id=?",[$id,$t]); Audit::log('DELETE','user',$id,''); Http::noContent(); }
    throw new Err('Method not allowed',405);
}

// ─── ROLES ────────────────────────────────────────────────
function route_roles(string $m, $id, array $b, $tid, $uid): never {
    $t=$tid(); Auth::need('settings','read');
    if ($m==='GET') Http::ok(Db::all("SELECT id,name,permissions,is_system FROM roles WHERE tenant_id=? ORDER BY name",[$t]));
    if ($m==='POST') {
        Auth::need('settings','update');
        Validator::check($b,['name'=>'required|max:100']);
        if (Db::one("SELECT id FROM roles WHERE tenant_id=? AND name=?",[$t,$b['name']])) throw new Conflict('A role with this name already exists.');
        $id2=Db::uuid();
        Db::run("INSERT INTO roles(id,tenant_id,name,permissions,is_system) VALUES(?,?,?,?,0)",
            [$id2,$t,$b['name'],json_encode($b['permissions']??[])]);
        Audit::log('CREATE','role',$id2,$b['name']);
        Http::created(Db::one("SELECT id,name,permissions,is_system FROM roles WHERE id=? AND tenant_id=?",[$id2,$t]));
    }
    if ($m==='PUT') {
        Auth::need('settings','update');
        $row=Db::one("SELECT * FROM roles WHERE id=? AND tenant_id=?",[$id,$t]);
        if (!$row) throw new NotFound('Role not found');
        if (!empty($row['is_system'])) throw new Unproc('System roles cannot be edited.');
        Validator::check($b,['name'=>'required|max:100']);
        Db::run("UPDATE roles SET name=?,permissions=? WHERE id=? AND tenant_id=?",
            [$b['name'],json_encode($b['permissions']??json_decode($row['permissions'],true)??[]),$id,$t]);
        Audit::log('UPDATE','role',$id,$b['name'],$row,['permissions'=>$b['permissions']??null]);
        Http::ok(Db::one("SELECT id,name,permissions,is_system FROM roles WHERE id=? AND tenant_id=?",[$id,$t]));
    }
    if ($m==='DELETE') {
        Auth::need('settings','update');
        $row=Db::one("SELECT * FROM roles WHERE id=? AND tenant_id=?",[$id,$t]);
        if (!$row) throw new NotFound('Role not found');
        if (!empty($row['is_system'])) throw new Unproc('System roles cannot be deleted.');
        if (Db::val("SELECT COUNT(*) FROM users WHERE role_id=? AND tenant_id=? AND deleted_at IS NULL",[$id,$t])) throw new Conflict('Role is assigned to one or more users.');
        Db::run("DELETE FROM roles WHERE id=? AND tenant_id=?",[$id,$t]);
        Audit::log('DELETE','role',$id,$row['name'],$row);
        Http::noContent();
    }
    throw new Err('Method not allowed',405);
}

// ─── WORKFLOW RULES ───────────────────────────────────────
function route_workflows(string $m, $id, array $b, $tid): never {
    Auth::need('settings','read');
    if ($m==='GET') Http::ok(Db::all("SELECT * FROM workflow_rules WHERE tenant_id=? ORDER BY sort_order",[$tid()]));
    if ($m==='PUT') { Auth::need('settings','update'); Db::run("UPDATE workflow_rules SET is_active=?,name=? WHERE id=? AND tenant_id=?",[(int)($b['is_active']??1),$b['name']??'',$id,$tid()]); Http::ok(['message'=>'Updated']); }
    throw new Err('Method not allowed',405);
}
