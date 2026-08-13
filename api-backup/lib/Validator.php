<?php
// ═══ Validator ═══
class Validator {
    public static function check(array $data, array $rules): void {
        $errs = [];
        foreach ($rules as $f => $rs) {
            $v = $data[$f] ?? null;
            $l = ucfirst(str_replace('_',' ',$f));
            foreach (explode('|',$rs) as $rule) {
                [$n,$param] = array_pad(explode(':',$rule,2),2,null);
                $e = match($n) {
                    'required' => ($v===null||$v==='') ? "$l is required" : null,
                    'email'    => ($v&&!filter_var($v,FILTER_VALIDATE_EMAIL)) ? "$l must be a valid email" : null,
                    'numeric'  => ($v!==null&&$v!==''&&!is_numeric($v)) ? "$l must be a number" : null,
                    'int'      => ($v!==null&&$v!==''&&!ctype_digit((string)$v)) ? "$l must be an integer" : null,
                    'min'      => ($v!==null&&strlen((string)$v)<(int)$param) ? "$l min $param chars" : null,
                    'max'      => ($v!==null&&strlen((string)$v)>(int)$param) ? "$l max $param chars" : null,
                    'min_val'  => ($v!==null&&$v!==''&&(float)$v<(float)$param) ? "$l must be ≥ $param" : null,
                    'date'     => ($v&&!strtotime($v)) ? "$l must be a valid date" : null,
                    'in'       => ($v&&!in_array($v,explode(',',$param))) ? "$l is invalid" : null,
                    // Security Audit pw2: complexity, not just length — require upper+lower+digit
                    'strong_password' => ($v && !self::isStrongPassword((string)$v))
                        ? "$l must be 8+ chars and include an uppercase letter, a lowercase letter, and a number" : null,
                    default    => null,
                };
                if ($e) $errs[$f][] = $e;
            }
        }
        if ($errs) throw new Unproc('Validation failed', $errs);
    }
    public static function clean(array $d): array { array_walk_recursive($d, fn(&$v)=>is_string($v)?$v=trim($v):$v); return $d; }

    // Security Audit pw2: 8+ chars, at least one upper, one lower, one digit.
    public static function isStrongPassword(string $pw): bool {
        return strlen($pw) >= 8
            && preg_match('/[A-Z]/', $pw)
            && preg_match('/[a-z]/', $pw)
            && preg_match('/[0-9]/', $pw);
    }
}

// ─── Pure business-logic functions (unit-testable) ───────
class Calc {
    /** Compute a sales order line total. Returns [net, tax, total]. */
    public static function lineTotal(float $qty, float $price, float $discPct=0, float $taxPct=0): array {
        $disc = round($qty*$price*$discPct/100, 2);
        $net  = round($qty*$price-$disc, 2);
        $tax  = round($net*$taxPct/100, 2);
        return [$net, $tax, round($net+$tax,2), $disc];
    }
    /** Does an order exceed a customer's available credit? */
    public static function exceedsCredit(float $creditLimit, float $balance, float $orderTotal): bool {
        if ($creditLimit <= 0) return false; // 0 = unlimited
        return ($balance + $orderTotal) > $creditLimit;
    }
    /** New stock after a movement; clamps at 0 for OUT/DAMAGED. */
    public static function applyMovement(float $before, float $qty, string $type): float {
        $delta = in_array($type,['OUT','DAMAGED']) ? -abs($qty) : abs($qty);
        if ($type==='ADJUSTMENT') $delta = $qty;
        return max(0, $before + $delta);
    }
    /** Is a sales-order status transition allowed? */
    public static function canTransition(string $from, string $to): bool {
        $map = [
            'Draft'=>['Pending Approval','Processing','Cancelled'],
            'Pending Approval'=>['Approved','Cancelled'],
            'Approved'=>['Processing','Cancelled'],
            'Processing'=>['Picking','Cancelled','On Hold'],
            'Picking'=>['Packing','On Hold'],
            'Packing'=>['Shipped'],
            'Shipped'=>['Delivered'],
            'On Hold'=>['Processing','Cancelled'],
            'Delivered'=>[], 'Cancelled'=>[],
        ];
        return in_array($to, $map[$from] ?? []);
    }
    public static function allowedTransitions(string $from): array {
        $map = [
            'Draft'=>['Pending Approval','Processing','Cancelled'],'Pending Approval'=>['Approved','Cancelled'],
            'Approved'=>['Processing','Cancelled'],'Processing'=>['Picking','Cancelled','On Hold'],
            'Picking'=>['Packing','On Hold'],'Packing'=>['Shipped'],'Shipped'=>['Delivered'],
            'On Hold'=>['Processing','Cancelled'],'Delivered'=>[],'Cancelled'=>[],
        ];
        return $map[$from] ?? [];
    }

    // ─── f09: Multi-currency ──────────────────────────────
    /** Convert an amount from one currency to base using a stored rate (rate = 1 foreign unit -> X base units). */
    public static function toBase(float $amount, float $rateToBase): float {
        return round($amount * $rateToBase, 2);
    }
    public static function fromBase(float $baseAmount, float $rateToBase): float {
        if ($rateToBase <= 0) return 0.0;
        return round($baseAmount / $rateToBase, 2);
    }

    // ─── f11: Back-orders ─────────────────────────────────
    /** Split an ordered qty into [fulfillable, backordered] given available stock. */
    public static function splitBackorder(float $qtyOrdered, float $qtyAvailable): array {
        $fulfill = max(0, min($qtyOrdered, $qtyAvailable));
        $back    = max(0, $qtyOrdered - $fulfill);
        return [$fulfill, $back];
    }

    // ─── f16: COGS / Gross Profit ──────────────────────────
    /** Cost of goods sold for a line = qty * unit cost at time of sale (avg cost method). */
    public static function cogs(float $qty, float $unitCost): float {
        return round($qty * $unitCost, 2);
    }
    /** Gross profit = revenue (line net total, excluding tax) - COGS. */
    public static function grossProfit(float $lineNet, float $cogs): float {
        return round($lineNet - $cogs, 2);
    }
    public static function grossMarginPct(float $lineNet, float $grossProfit): float {
        if ($lineNet <= 0) return 0.0;
        return round(($grossProfit / $lineNet) * 100, 2);
    }

    // ─── f17: VAT / Tax ────────────────────────────────────
    /** Net VAT payable = output tax (collected on sales) - input tax (paid on purchases). */
    public static function netTaxPayable(float $outputTax, float $inputTax): float {
        return round($outputTax - $inputTax, 2);
    }

    // ─── f13: Payment reminder scheduling ─────────────────
    /** Which reminder thresholds (days overdue) have been crossed but not yet sent? */
    public static function dueReminders(int $daysOverdue, array $thresholds, array $alreadySent): array {
        $due = [];
        foreach ($thresholds as $t) {
            if ($daysOverdue >= $t && !in_array($t, $alreadySent, true)) $due[] = $t;
        }
        return $due;
    }

    // ─── f12: Recurring invoice scheduling ────────────────
    /** Next run date after the current one, for a given frequency. */
    public static function nextScheduleDate(string $currentDate, string $frequency): string {
        $interval = match($frequency) {
            'Weekly'    => '+1 week',
            'Monthly'   => '+1 month',
            'Quarterly' => '+3 months',
            'Annually'  => '+1 year',
            default     => '+1 month',
        };
        return date('Y-m-d', strtotime($currentDate.' '.$interval));
    }
}
