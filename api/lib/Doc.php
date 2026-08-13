<?php
// ═══════════════════════════════════════════════════════════
// Doc — builds branded PDF documents (invoice, quotation, order)
// and manages public share tokens used for WhatsApp links.
// ═══════════════════════════════════════════════════════════

class Doc {

    private const INK    = [17, 24, 39];
    private const MUTED  = [107, 114, 128];
    private const RULE   = [226, 232, 240];
    private const ZEBRA  = [248, 250, 252];

    /** Accent colour from tenant branding, falling back to the app blue. */
    private static function accent(array $branding): array {
        $hex = $branding['primary_color'] ?? '#2563EB';
        if (!preg_match('/^#?([0-9a-fA-F]{6})$/', (string)$hex, $m)) return [37, 99, 235];
        return [
            hexdec(substr($m[1],0,2)),
            hexdec(substr($m[1],2,2)),
            hexdec(substr($m[1],4,2)),
        ];
    }

    private static function money($n): string {
        return number_format((float)$n, 2);
    }

    /** Tenant header block + branding row. */
    private static function header(Pdf $p, array $tenant, array $branding, string $docTitle, array $accent): void {
        $p->rect(0, 0, $p->pageWidth(), 84, $accent);
        $p->text($branding['company_name'] ?? $tenant['name'] ?? 'CZium Distribution',
                 $p->marginL, 24, 17, 'B', [255,255,255]);
        $sub = $branding['tagline'] ?? 'Distribution Management System';
        $p->text($sub, $p->marginL, 46, 8.5, '', [235, 242, 255]);

        $addr = trim((string)($branding['address'] ?? ''));
        if ($addr !== '') $p->text($addr, $p->marginL, 62, 8, '', [235, 242, 255]);

        $p->textRight(strtoupper($docTitle), $p->pageWidth() - $p->marginR, 28, 15, 'B', [255,255,255]);
        $contact = array_filter([$branding['phone'] ?? null, $branding['email'] ?? null]);
        if ($contact) {
            $p->textRight(implode('  ·  ', $contact), $p->pageWidth() - $p->marginR, 52, 8, '', [235,242,255]);
        }
        $p->setY(104);
    }

    /** Two-column meta block: bill-to on the left, document facts on the right. */
    private static function metaBlock(Pdf $p, array $left, array $right, array $accent): void {
        $top     = $p->getY();
        $colR    = $p->pageWidth() / 2 + 20;
        $rightX  = $p->pageWidth() - $p->marginR;

        $p->text('BILL TO', $p->marginL, $top, 7.5, 'B', self::MUTED);
        $y = $top + 15;
        foreach ($left as $i => $line) {
            if ($line === null || $line === '') continue;
            $p->text((string)$line, $p->marginL, $y, $i === 0 ? 10.5 : 9,
                     $i === 0 ? 'B' : '', $i === 0 ? self::INK : self::MUTED);
            $y += $i === 0 ? 15 : 12.5;
        }

        $ry = $top;
        foreach ($right as $label => $value) {
            if ($value === null || $value === '') continue;
            $p->text($label, $colR, $ry, 8, '', self::MUTED);
            $p->textRight((string)$value, $rightX, $ry, 9, 'B', self::INK);
            $ry += 14;
        }

        $p->setY(max($y, $ry) + 10);
    }

    /** Line-items table with automatic page breaks and repeated headers. */
    private static function itemsTable(Pdf $p, array $items, array $accent): void {
        $x0 = $p->marginL;
        $x1 = $p->pageWidth() - $p->marginR;

        // Column x-positions (right edges for numeric columns)
        $cDesc = $x0 + 4;
        $cQty  = $x0 + 300;
        $cRate = $x0 + 385;
        $cDisc = $x0 + 445;
        $cAmt  = $x1 - 4;

        $drawHead = function () use ($p, $x0, $x1, $cDesc, $cQty, $cRate, $cDisc, $cAmt, $accent) {
            $y = $p->getY();
            $p->rect($x0, $y, $x1 - $x0, 22, $accent);
            $p->text('DESCRIPTION', $cDesc, $y + 7, 7.5, 'B', [255,255,255]);
            $p->textRight('QTY',      $cQty,  $y + 7, 7.5, 'B', [255,255,255]);
            $p->textRight('RATE',     $cRate, $y + 7, 7.5, 'B', [255,255,255]);
            $p->textRight('DISC',     $cDisc, $y + 7, 7.5, 'B', [255,255,255]);
            $p->textRight('AMOUNT',   $cAmt,  $y + 7, 7.5, 'B', [255,255,255]);
            $p->setY($y + 22);
        };

        $drawHead();
        $zebra = false;

        foreach ($items as $it) {
            $hasSku = !empty($it['sku']);
            $rowH   = $hasSku ? 26 : 20;
            if ($p->ensure($rowH + 10)) { $p->setY($p->marginT); $drawHead(); $zebra = false; }
            $y = $p->getY();

            if ($zebra) $p->rect($x0, $y, $x1 - $x0, $rowH, self::ZEBRA);
            $zebra = !$zebra;

            $full = (string)($it['name'] ?? $it['description'] ?? 'Item');
            $name = $full;
            // Truncate rather than wrap, so every row keeps a predictable height.
            while ($p->textWidth($name, 9) > 285 && mb_strlen($name) > 4) {
                $name = mb_substr($name, 0, mb_strlen($name) - 2);
            }
            if ($name !== $full) $name = rtrim($name) . '…';

            $p->text($name, $cDesc, $y + 6, 9, '', self::INK);
            // SKU sits on its own line beneath the name — drawing both at the
            // same Y overlaps the glyphs into an unreadable smear.
            if ($hasSku) {
                $p->text((string)$it['sku'], $cDesc, $y + 16, 7, '', self::MUTED);
            }

            $numY = $y + 6;
            $p->textRight(rtrim(rtrim(number_format((float)($it['qty'] ?? 0), 2, '.', ''), '0'), '.') ?: '0',
                          $cQty,  $numY, 9, '', self::INK);
            $p->textRight(self::money($it['unit_price'] ?? 0), $cRate, $numY, 9, '', self::INK);
            $disc = (float)($it['discount_pct'] ?? 0);
            $p->textRight($disc > 0 ? rtrim(rtrim(number_format($disc,2,'.',''),'0'),'.').'%' : '—',
                          $cDisc, $numY, 9, '', self::MUTED);
            $p->textRight(self::money($it['line_total'] ?? 0), $cAmt, $numY, 9, 'B', self::INK);

            $p->setY($y + $rowH);
            $p->hr(null, self::RULE, 0.4);
        }
    }

    /** Totals stack, right-aligned. */
    private static function totals(Pdf $p, array $rows, array $accent): void {
        $x1     = $p->pageWidth() - $p->marginR;
        $labelX = $x1 - 190;
        $p->moveY(10);

        foreach ($rows as $r) {
            $isGrand = !empty($r['grand']);
            if ($p->ensure(26)) $p->setY($p->marginT);
            $y = $p->getY();

            if ($isGrand) {
                $p->rect($labelX - 12, $y - 3, $x1 - $labelX + 16, 26, $accent);
                $p->text($r['label'], $labelX, $y + 5, 10.5, 'B', [255,255,255]);
                $p->textRight($r['value'], $x1 - 2, $y + 5, 12, 'B', [255,255,255]);
                $p->setY($y + 30);
            } else {
                $p->text($r['label'], $labelX, $y, 9, '', self::MUTED);
                $p->textRight($r['value'], $x1 - 2, $y, 9.5,
                              !empty($r['bold']) ? 'B' : '', self::INK);
                $p->setY($y + 16);
            }
        }
    }

    /** Footer notes, terms and signature line. */
    private static function footer(Pdf $p, ?string $notes, ?string $terms, array $branding): void {
        $p->moveY(16);
        if ($p->ensure(120)) $p->setY($p->marginT);

        if ($notes) {
            $p->text('NOTES', $p->marginL, $p->getY(), 7.5, 'B', self::MUTED);
            $p->moveY(13);
            $p->paragraph($notes, $p->marginL, $p->contentWidth(), 8.5, '', self::INK);
            $p->moveY(8);
        }
        if ($terms) {
            $p->text('TERMS & CONDITIONS', $p->marginL, $p->getY(), 7.5, 'B', self::MUTED);
            $p->moveY(13);
            $p->paragraph($terms, $p->marginL, $p->contentWidth(), 8.5, '', self::MUTED);
            $p->moveY(8);
        }
        if (!empty($branding['bank_details'])) {
            $p->text('PAYMENT DETAILS', $p->marginL, $p->getY(), 7.5, 'B', self::MUTED);
            $p->moveY(13);
            $p->paragraph((string)$branding['bank_details'], $p->marginL, $p->contentWidth(), 8.5, '', self::INK);
            $p->moveY(8);
        }

        if ($p->ensure(70)) $p->setY($p->marginT);
        $p->moveY(26);
        $x1 = $p->pageWidth() - $p->marginR;
        $p->line($x1 - 170, $p->getY(), $x1, $p->getY(), 0.6, self::RULE);
        $p->text('Authorised Signature', $x1 - 170, $p->getY() + 6, 8, '', self::MUTED);
        $p->moveY(24);
        $p->textCenter('This is a computer-generated document.',
                       $p->pageWidth() / 2, $p->getY(), 7.5, '', self::MUTED);
    }

    // ═══════════════════════════════════════════════════════
    // PUBLIC BUILDERS
    // ═══════════════════════════════════════════════════════

    /** Load tenant branding, tolerating a missing branding row. */
    public static function branding(string $tenantId): array {
        $b = Db::one("SELECT * FROM tenant_branding WHERE tenant_id=? LIMIT 1", [$tenantId]);
        return $b ?: [];
    }

    /**
     * Render an invoice to a Pdf instance.
     * $inv must already be tenant-scoped by the caller.
     */
    public static function invoice(array $inv, array $items, array $tenant, array $branding): Pdf {
        $p  = new Pdf();
        $ac = self::accent($branding);
        self::header($p, $tenant, $branding, 'Invoice', $ac);

        $paid    = (float)($inv['amount_paid'] ?? 0);
        $total   = (float)($inv['total_amount'] ?? 0);
        $balance = $total - $paid;

        self::metaBlock($p,
            [
                $inv['customer_name'] ?? 'Customer',
                $inv['customer_address'] ?? null,
                $inv['customer_phone'] ?? null,
                !empty($inv['area_name']) ? 'Area: ' . $inv['area_name'] : null,
            ],
            [
                'Invoice No'   => $inv['invoice_number'] ?? '',
                'Invoice Date' => !empty($inv['invoice_date']) ? date('d M Y', strtotime($inv['invoice_date'])) : '',
                'Due Date'     => !empty($inv['due_date'])     ? date('d M Y', strtotime($inv['due_date']))     : null,
                'Order Ref'    => $inv['order_number'] ?? null,
                'Sales Rep'    => $inv['rep_name'] ?? null,
                'Payment'      => ucfirst((string)($inv['payment_mode'] ?? 'credit')),
            ],
            $ac
        );

        self::itemsTable($p, $items, $ac);

        $rows = [['label' => 'Subtotal', 'value' => self::money($inv['subtotal'] ?? 0)]];
        if ((float)($inv['discount_amount'] ?? 0) > 0)
            $rows[] = ['label' => 'Discount', 'value' => '-' . self::money($inv['discount_amount'])];
        if ((float)($inv['tax_amount'] ?? 0) > 0)
            $rows[] = ['label' => 'Tax', 'value' => self::money($inv['tax_amount'])];
        $rows[] = ['label' => 'TOTAL (Rs.)', 'value' => self::money($total), 'grand' => true];
        if ($paid > 0) {
            $rows[] = ['label' => 'Paid',            'value' => self::money($paid)];
            $rows[] = ['label' => 'Balance Due',     'value' => self::money($balance), 'bold' => true];
        }
        self::totals($p, $rows, $ac);

        self::footer($p, $inv['notes'] ?? null, $inv['terms'] ?? ($branding['invoice_terms'] ?? null), $branding);
        return $p;
    }

    /** Render a quotation to a Pdf instance. */
    public static function quotation(array $q, array $items, array $tenant, array $branding): Pdf {
        $p  = new Pdf();
        $ac = self::accent($branding);
        self::header($p, $tenant, $branding, 'Quotation', $ac);

        self::metaBlock($p,
            [
                $q['customer_name'] ?? 'Customer',
                $q['customer_address'] ?? null,
                $q['customer_phone'] ?? null,
                !empty($q['area_name']) ? 'Area: ' . $q['area_name'] : null,
            ],
            [
                'Quote No'    => $q['quote_number'] ?? '',
                'Quote Date'  => !empty($q['quote_date'])  ? date('d M Y', strtotime($q['quote_date']))  : '',
                'Valid Until' => !empty($q['valid_until']) ? date('d M Y', strtotime($q['valid_until'])) : null,
                'Sales Rep'   => $q['rep_name'] ?? null,
                'Status'      => $q['status'] ?? null,
            ],
            $ac
        );

        self::itemsTable($p, $items, $ac);

        $rows = [['label' => 'Subtotal', 'value' => self::money($q['subtotal'] ?? 0)]];
        if ((float)($q['discount_amount'] ?? 0) > 0)
            $rows[] = ['label' => 'Discount', 'value' => '-' . self::money($q['discount_amount'])];
        if ((float)($q['tax_amount'] ?? 0) > 0)
            $rows[] = ['label' => 'Tax', 'value' => self::money($q['tax_amount'])];
        $rows[] = ['label' => 'TOTAL (Rs.)', 'value' => self::money($q['total_amount'] ?? 0), 'grand' => true];
        self::totals($p, $rows, $ac);

        self::footer($p, $q['notes'] ?? null, $q['terms'] ?? null, $branding);
        return $p;
    }

    // ═══════════════════════════════════════════════════════
    // SHARE TOKENS (public WhatsApp links)
    // ═══════════════════════════════════════════════════════

    /**
     * Create or reuse a share token for a document.
     * Tokens are random 48-hex strings stored in invoice_share_tokens —
     * unguessable, revocable, and independently expirable.
     */
    public static function shareToken(string $docType, string $docId, string $tenantId,
                                      ?string $userId = null, int $days = 90): string {
        $existing = Db::one(
            "SELECT token, expires_at FROM invoice_share_tokens
             WHERE doc_type=? AND doc_id=? AND tenant_id=?
               AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY created_at DESC LIMIT 1",
            [$docType, $docId, $tenantId]
        );
        if ($existing) return $existing['token'];

        $token = bin2hex(random_bytes(24));
        Db::run(
            "INSERT INTO invoice_share_tokens(id,tenant_id,doc_type,doc_id,token,expires_at,created_by)
             VALUES(?,?,?,?,?,DATE_ADD(NOW(), INTERVAL ? DAY),?)",
            [Db::uuid(), $tenantId, $docType, $docId, $token, $days, $userId]
        );
        return $token;
    }

    /**
     * Resolve a share token to its document, or null when invalid/expired.
     *
     * TENANT ISOLATION NOTE: this query is deliberately NOT scoped by
     * tenant_id. The public share page has no session, so the random token IS
     * the credential and the tenant is derived *from* it — there is no tenant
     * context to scope by at this point. Safety comes from three properties:
     *   1. the token is 48 hex chars of CSPRNG output (unguessable),
     *   2. it is bound to exactly one doc_type + doc_id,
     *   3. it expires.
     * The tenant's active status is verified here rather than left to the
     * caller, so a suspended organisation's links stop working everywhere.
     */
    public static function resolveToken(string $token): ?array {
        $row = Db::one(
            "SELECT t.* FROM invoice_share_tokens t
             JOIN tenants tn ON tn.id = t.tenant_id AND tn.is_active = 1
             WHERE t.token = ? AND (t.expires_at IS NULL OR t.expires_at > NOW())
             LIMIT 1",
            [$token]
        );
        if (!$row) return null;
        Db::run("UPDATE invoice_share_tokens SET view_count=view_count+1, last_viewed=NOW()
                 WHERE id=? AND tenant_id=?", [$row['id'], $row['tenant_id']]);
        return $row;
    }

    /** Absolute public URL for a share token. */
    public static function shareUrl(string $token): string {
        $base = rtrim(defined('APP_URL') ? APP_URL : '', '/');
        return $base . '/share.php?t=' . $token;
    }

    /** Build a wa.me deep link with a short message plus the share URL. */
    public static function whatsappUrl(?string $phone, string $message): string {
        $digits = preg_replace('/[^0-9]/', '', (string)$phone);
        return 'https://wa.me/' . $digits . '?text=' . rawurlencode($message);
    }
}
