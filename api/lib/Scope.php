<?php
// ═══════════════════════════════════════════════════════════
// Scope — row-level access control for field sales reps.
//
// A user with users.rep_id set is a field rep. They may only see:
//   • orders / quotations / invoices they themselves raised
//   • customers inside the areas assigned to them
//   • their own visits, collections and targets
//
// Managers, admins and back-office roles are unrestricted (within tenant).
//
// Every helper returns a SQL fragment + bound params, so callers compose:
//     [$sql, $params] = Scope::orders('so');
//     Db::all("SELECT ... WHERE so.tenant_id=? $sql", array_merge([$t], $params));
//
// This runs IN ADDITION to tenant isolation — never instead of it.
// ═══════════════════════════════════════════════════════════

class Scope {

    private static ?array $cache = null;

    /** Returns the sales_reps.id for the current user, or null if not a rep. */
    public static function repId(): ?string {
        $me = Auth::me();
        if (!$me) return null;
        return !empty($me['rep_id']) ? $me['rep_id'] : null;
    }

    /** True when the current user is restricted to their own rows. */
    public static function isRep(): bool {
        // An explicit "all" permission (admin) always wins over rep scoping,
        // so an admin who also happens to be linked to a rep record is not limited.
        if (Auth::can('_bypass_scope', 'any')) return false;
        return self::repId() !== null;
    }

    /** Area IDs assigned to the current rep. Empty array = no restriction. */
    public static function areaIds(): array {
        if (self::$cache !== null) return self::$cache;
        $rid = self::repId();
        if (!$rid || !self::isRep()) return self::$cache = [];
        $rows = Db::all(
            "SELECT area_id FROM rep_areas WHERE rep_id=? AND tenant_id=?",
            [$rid, Db::tenant()]
        );
        return self::$cache = array_column($rows, 'area_id');
    }

    /**
     * Constrain a sales_orders query to the current rep.
     * @param string $alias table alias for sales_orders
     * @return array [sqlFragment, params]
     */
    public static function orders(string $alias = 'so'): array {
        if (!self::isRep()) return ['', []];
        return [" AND {$alias}.rep_id = ?", [self::repId()]];
    }

    /** Constrain quotations to the current rep. */
    public static function quotations(string $alias = 'q'): array {
        if (!self::isRep()) return ['', []];
        return [" AND {$alias}.rep_id = ?", [self::repId()]];
    }

    /** Constrain invoices to the current rep. */
    public static function invoices(string $alias = 'i'): array {
        if (!self::isRep()) return ['', []];
        return [" AND {$alias}.rep_id = ?", [self::repId()]];
    }

    /**
     * Constrain customers to the rep's assigned areas.
     * A rep with no areas assigned sees nothing rather than everything —
     * failing closed is the safe default for row-level security.
     */
    public static function customers(string $alias = 'c'): array {
        if (!self::isRep()) return ['', []];
        $areas = self::areaIds();
        if (!$areas) return [" AND 1=0", []];
        $ph = implode(',', array_fill(0, count($areas), '?'));
        return [" AND {$alias}.area_id IN ($ph)", $areas];
    }

    /** Constrain any table carrying an area_id column. */
    public static function byArea(string $alias, string $col = 'area_id'): array {
        if (!self::isRep()) return ['', []];
        $areas = self::areaIds();
        if (!$areas) return [" AND 1=0", []];
        $ph = implode(',', array_fill(0, count($areas), '?'));
        return [" AND {$alias}.{$col} IN ($ph)", $areas];
    }

    /** Constrain a table carrying a rep_id column. */
    public static function byRep(string $alias, string $col = 'rep_id'): array {
        if (!self::isRep()) return ['', []];
        return [" AND {$alias}.{$col} = ?", [self::repId()]];
    }

    /**
     * Assert the current user may read/modify a specific record that carries
     * a rep_id. Throws 403 rather than 404 so the caller knows it exists but
     * is out of scope.
     */
    public static function assertOwnsRep(?string $rowRepId, string $what = 'record'): void {
        if (!self::isRep()) return;
        if ($rowRepId !== self::repId()) {
            throw new AuthErr("You can only access your own {$what}.", 403);
        }
    }

    /** Assert the current user may touch a customer in a given area. */
    public static function assertInArea(?string $areaId, string $what = 'customer'): void {
        if (!self::isRep()) return;
        $areas = self::areaIds();
        if (!$areaId || !in_array($areaId, $areas, true)) {
            throw new AuthErr("That {$what} is outside your assigned areas.", 403);
        }
    }

    /**
     * Force rep_id onto a payload being written by a rep, so a rep cannot
     * create a record attributed to someone else.
     */
    public static function stampRep(array $body): array {
        if (self::isRep()) $body['rep_id'] = self::repId();
        return $body;
    }

    /** Reset the memoised area list (used by tests between requests). */
    public static function reset(): void { self::$cache = null; }
}
