<?php
// ═══════════════════════════════════════════════════════════
// share.php — PUBLIC document viewer for WhatsApp links.
//
// No authentication: access is granted solely by an unguessable,
// expiring token stored in invoice_share_tokens. Only the single
// document bound to that token is ever exposed.
// ═══════════════════════════════════════════════════════════

require __DIR__ . '/api/bootstrap.php';
require __DIR__ . '/api/lib/Pdf.php';
require __DIR__ . '/api/lib/Doc.php';

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

function bail(string $title, string $msg, int $code = 404): never {
    http_response_code($code);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
       . '<title>' . htmlspecialchars($title) . '</title>'
       . '<style>body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#f8fafc;color:#0f172a;'
       . 'display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:24px}'
       . '.c{background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:32px;max-width:380px;text-align:center;'
       . 'box-shadow:0 1px 3px rgba(0,0,0,.08)}h1{font-size:17px;margin:0 0 8px}p{font-size:14px;color:#64748b;margin:0;line-height:1.6}</style>'
       . '<div class="c"><h1>' . htmlspecialchars($title) . '</h1><p>' . htmlspecialchars($msg) . '</p></div>';
    exit;
}

$token = (string)($_GET['t'] ?? '');
if ($token === '' || !preg_match('/^[a-f0-9]{32,64}$/', $token)) {
    bail('Invalid link', 'This link is not valid. Please request a new one from the sender.');
}

try {
    $share = Doc::resolveToken($token);
} catch (Throwable $e) {
    bail('Unavailable', 'This document cannot be loaded right now. Please try again later.', 503);
}
if (!$share) {
    bail('Link expired', 'This link has expired or has been revoked. Please request a new one from the sender.', 410);
}

// The token carries its own tenant — scope the connection to it.
Db::setTenant($share['tenant_id']);
$tenantId = $share['tenant_id'];
$tenant   = Db::one("SELECT id,name,slug FROM tenants WHERE id=? AND is_active=1", [$tenantId]);
if (!$tenant) bail('Unavailable', 'This organisation is no longer active.', 410);
$branding = Doc::branding($tenantId);

$wantsPdf = isset($_GET['pdf']);

// ─── INVOICE ───────────────────────────────────────────────
if ($share['doc_type'] === 'invoice') {
    $inv = Db::one("SELECT i.*, i.paid_amount AS amount_paid,
          c.name customer_name, c.phone customer_phone, c.address customer_address,
          so.order_number, so.subtotal, so.discount_amount, so.tax_amount, so.payment_mode,
          a.name area_name, r.name rep_name
        FROM invoices i
        JOIN customers c ON c.id=i.customer_id AND c.tenant_id=i.tenant_id
        LEFT JOIN sales_orders so ON so.id=i.order_id
        LEFT JOIN areas a ON a.id=COALESCE(i.area_id, so.area_id)
        LEFT JOIN sales_reps r ON r.id=COALESCE(i.rep_id, so.rep_id)
        WHERE i.id=? AND i.tenant_id=?", [$share['doc_id'], $tenantId]);
    if (!$inv) bail('Not found', 'This invoice no longer exists.');

    $items = Db::all("SELECT p.name,p.sku,soi.qty_ordered qty,soi.unit_price,soi.discount_pct,soi.line_total
        FROM sales_order_items soi JOIN products p ON p.id=soi.product_id
        WHERE soi.order_id=? AND soi.tenant_id=? ORDER BY soi.sort_order",
        [$inv['order_id'], $tenantId]) ?: [];
    if ($inv['subtotal'] === null) $inv['subtotal'] = $inv['total_amount'];

    if ($wantsPdf) Doc::invoice($inv,$items,$tenant,$branding)->send("Invoice-{$inv['invoice_number']}.pdf", false);

    $doc = [
        'kind'     => 'Invoice',
        'number'   => $inv['invoice_number'],
        'date'     => $inv['invoice_date'],
        'due'      => $inv['due_date'],
        'customer' => $inv['customer_name'],
        'address'  => $inv['customer_address'],
        'area'     => $inv['area_name'],
        'rep'      => $inv['rep_name'],
        'mode'     => $inv['payment_mode'],
        'subtotal' => $inv['subtotal'],
        'discount' => $inv['discount_amount'],
        'tax'      => $inv['tax_amount'],
        'total'    => $inv['total_amount'],
        'paid'     => $inv['amount_paid'],
        'status'   => $inv['status'],
        'notes'    => $inv['notes'],
    ];
}
// ─── QUOTATION ─────────────────────────────────────────────
elseif ($share['doc_type'] === 'quotation') {
    $q = Db::one("SELECT q.*,c.name customer_name,c.phone customer_phone,c.address customer_address,
          a.name area_name,r.name rep_name
        FROM quotations q
        JOIN customers c ON c.id=q.customer_id AND c.tenant_id=q.tenant_id
        LEFT JOIN areas a ON a.id=q.area_id
        LEFT JOIN sales_reps r ON r.id=q.rep_id
        WHERE q.id=? AND q.tenant_id=? AND q.deleted_at IS NULL", [$share['doc_id'], $tenantId]);
    if (!$q) bail('Not found', 'This quotation no longer exists.');

    $items = Db::all("SELECT p.name,p.sku,qi.qty,qi.unit_price,qi.discount_pct,qi.line_total
        FROM quotation_items qi JOIN products p ON p.id=qi.product_id
        WHERE qi.quotation_id=? AND qi.tenant_id=? ORDER BY qi.sort_order",
        [$share['doc_id'], $tenantId]) ?: [];

    if ($wantsPdf) Doc::quotation($q,$items,$tenant,$branding)->send("Quotation-{$q['quote_number']}.pdf", false);

    $doc = [
        'kind'     => 'Quotation',
        'number'   => $q['quote_number'],
        'date'     => $q['quote_date'],
        'due'      => $q['valid_until'],
        'customer' => $q['customer_name'],
        'address'  => $q['customer_address'],
        'area'     => $q['area_name'],
        'rep'      => $q['rep_name'],
        'mode'     => null,
        'subtotal' => $q['subtotal'],
        'discount' => $q['discount_amount'],
        'tax'      => $q['tax_amount'],
        'total'    => $q['total_amount'],
        'paid'     => 0,
        'status'   => $q['status'],
        'notes'    => $q['notes'],
    ];
} else {
    bail('Unsupported', 'This document type cannot be viewed.');
}

$accent  = $branding['primary_color'] ?? '#2563EB';
$company = $branding['company_name'] ?? $tenant['name'];
$balance = (float)$doc['total'] - (float)$doc['paid'];
$h  = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$mn = fn($n) => number_format((float)$n, 2);
$dt = fn($d) => $d ? date('d M Y', strtotime((string)$d)) : '';
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title><?= $h($doc['kind']) ?> <?= $h($doc['number']) ?> — <?= $h($company) ?></title>
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'Inter',system-ui,-apple-system,'Segoe UI',Roboto,sans-serif;background:#f1f5f9;color:#0f172a;
       -webkit-font-smoothing:antialiased;padding:0 0 88px}
  .sheet{max-width:760px;margin:0 auto;background:#fff;min-height:100vh;box-shadow:0 0 40px rgba(0,0,0,.06)}
  .hd{background:<?= $h($accent) ?>;color:#fff;padding:28px 24px}
  .hd h1{font-size:20px;font-weight:800;letter-spacing:-.02em}
  .hd .sub{font-size:12px;opacity:.85;margin-top:3px}
  .hd .kind{float:right;font-size:15px;font-weight:700;letter-spacing:.06em;text-transform:uppercase}
  .body{padding:24px}
  .meta{display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:22px}
  .lbl{font-size:10px;font-weight:700;color:#94a3b8;letter-spacing:.08em;text-transform:uppercase;margin-bottom:6px}
  .cust{font-size:15px;font-weight:700}
  .muted{font-size:13px;color:#64748b;line-height:1.7}
  .facts{font-size:13px}
  .facts div{display:flex;justify-content:space-between;padding:3px 0;gap:12px}
  .facts span:first-child{color:#64748b}
  .facts span:last-child{font-weight:600;text-align:right}
  table{width:100%;border-collapse:collapse;margin:8px 0 4px;font-size:13px}
  thead th{background:<?= $h($accent) ?>;color:#fff;font-size:10px;letter-spacing:.06em;text-transform:uppercase;
           padding:9px 10px;text-align:left}
  thead th.r{text-align:right}
  tbody td{padding:10px;border-bottom:1px solid #e2e8f0}
  tbody td.r{text-align:right;font-variant-numeric:tabular-nums}
  tbody tr:nth-child(even){background:#f8fafc}
  .sku{font-size:11px;color:#94a3b8;display:block;margin-top:2px}
  .tot{margin-left:auto;width:100%;max-width:290px;margin-top:16px;font-size:13px}
  .tot div{display:flex;justify-content:space-between;padding:6px 0}
  .tot .grand{background:<?= $h($accent) ?>;color:#fff;padding:12px 14px;border-radius:8px;margin-top:8px;
              font-size:16px;font-weight:800}
  .tot .bal{font-weight:700;border-top:1px solid #e2e8f0;margin-top:4px;padding-top:10px}
  .pill{display:inline-block;padding:3px 11px;border-radius:99px;font-size:11px;font-weight:700}
  .ok{background:#dcfce7;color:#15803d}.due{background:#fee2e2;color:#b91c1c}
  .notes{margin-top:26px;padding-top:18px;border-top:1px solid #e2e8f0;font-size:12.5px;color:#64748b;line-height:1.7}
  .bar{position:fixed;left:0;right:0;bottom:0;background:#fff;border-top:1px solid #e2e8f0;padding:12px 16px;
       padding-bottom:calc(12px + env(safe-area-inset-bottom));display:flex;gap:10px;justify-content:center;z-index:10}
  .btn{flex:1;max-width:230px;text-align:center;padding:13px;border-radius:10px;font-size:14px;font-weight:700;
       text-decoration:none;border:none;cursor:pointer;font-family:inherit}
  .primary{background:<?= $h($accent) ?>;color:#fff}
  .ghost{background:#f1f5f9;color:#334155}
  .foot{text-align:center;font-size:11px;color:#94a3b8;padding:22px 16px 8px}
  @media(max-width:560px){.meta{grid-template-columns:1fr;gap:16px}.hd .kind{float:none;display:block;margin-top:10px}}
  @media print{.bar{display:none}body{background:#fff;padding:0}.sheet{box-shadow:none;max-width:none}}
</style></head><body>
<div class="sheet">
  <div class="hd">
    <span class="kind"><?= $h($doc['kind']) ?></span>
    <h1><?= $h($company) ?></h1>
    <div class="sub"><?= $h($branding['tagline'] ?? 'Distribution Management System') ?></div>
    <?php if (!empty($branding['address'])): ?><div class="sub"><?= $h($branding['address']) ?></div><?php endif; ?>
  </div>

  <div class="body">
    <div class="meta">
      <div>
        <div class="lbl">Bill To</div>
        <div class="cust"><?= $h($doc['customer']) ?></div>
        <div class="muted">
          <?php if ($doc['address']): ?><?= $h($doc['address']) ?><br><?php endif; ?>
          <?php if ($doc['area']): ?>Area: <?= $h($doc['area']) ?><?php endif; ?>
        </div>
      </div>
      <div class="facts">
        <div><span><?= $h($doc['kind']) ?> No</span><span><?= $h($doc['number']) ?></span></div>
        <div><span>Date</span><span><?= $h($dt($doc['date'])) ?></span></div>
        <?php if ($doc['due']): ?>
        <div><span><?= $doc['kind']==='Invoice'?'Due Date':'Valid Until' ?></span><span><?= $h($dt($doc['due'])) ?></span></div>
        <?php endif; ?>
        <?php if ($doc['rep']): ?><div><span>Sales Rep</span><span><?= $h($doc['rep']) ?></span></div><?php endif; ?>
        <?php if ($doc['mode']): ?><div><span>Payment</span><span><?= $h(ucfirst($doc['mode'])) ?></span></div><?php endif; ?>
        <div><span>Status</span><span>
          <span class="pill <?= $balance <= 0 ? 'ok' : 'due' ?>"><?= $h($doc['status']) ?></span>
        </span></div>
      </div>
    </div>

    <table>
      <thead><tr>
        <th>Description</th><th class="r">Qty</th><th class="r">Rate</th><th class="r">Amount</th>
      </tr></thead>
      <tbody>
      <?php if (!$items): ?>
        <tr><td colspan="4" style="text-align:center;color:#94a3b8;padding:28px">No line items</td></tr>
      <?php else: foreach ($items as $it): ?>
        <tr>
          <td><?= $h($it['name']) ?><?php if (!empty($it['sku'])): ?><span class="sku"><?= $h($it['sku']) ?></span><?php endif; ?></td>
          <td class="r"><?= $h(rtrim(rtrim(number_format((float)$it['qty'],2,'.',''),'0'),'.')) ?></td>
          <td class="r"><?= $h($mn($it['unit_price'])) ?></td>
          <td class="r"><strong><?= $h($mn($it['line_total'])) ?></strong></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>

    <div class="tot">
      <div><span>Subtotal</span><span><?= $h($mn($doc['subtotal'])) ?></span></div>
      <?php if ((float)$doc['discount'] > 0): ?>
      <div><span>Discount</span><span>-<?= $h($mn($doc['discount'])) ?></span></div><?php endif; ?>
      <?php if ((float)$doc['tax'] > 0): ?>
      <div><span>Tax</span><span><?= $h($mn($doc['tax'])) ?></span></div><?php endif; ?>
      <div class="grand"><span>Total</span><span>Rs. <?= $h($mn($doc['total'])) ?></span></div>
      <?php if ((float)$doc['paid'] > 0): ?>
      <div style="margin-top:8px"><span>Paid</span><span><?= $h($mn($doc['paid'])) ?></span></div>
      <div class="bal"><span>Balance Due</span><span>Rs. <?= $h($mn($balance)) ?></span></div>
      <?php endif; ?>
    </div>

    <?php if ($doc['notes'] || !empty($branding['bank_details'])): ?>
    <div class="notes">
      <?php if ($doc['notes']): ?><strong>Notes:</strong> <?= $h($doc['notes']) ?><br><br><?php endif; ?>
      <?php if (!empty($branding['bank_details'])): ?><strong>Payment details:</strong> <?= $h($branding['bank_details']) ?><?php endif; ?>
    </div><?php endif; ?>
  </div>

  <div class="foot">This is a computer-generated document from <?= $h($company) ?>.</div>
</div>

<div class="bar">
  <a class="btn primary" href="?t=<?= $h($token) ?>&amp;pdf=1">Download PDF</a>
  <button class="btn ghost" onclick="window.print()">Print</button>
</div>
</body></html>
