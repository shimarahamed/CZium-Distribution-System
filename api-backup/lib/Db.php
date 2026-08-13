<?php
// ═══ Database — PDO wrapper, tenant scoping, pagination ═══
class Db {
    private static ?PDO $c = null;
    private static string $tid = '';

    public static function conn(): PDO {
        if (!self::$c) {
            try {
                self::$c = new PDO(
                    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', DB_HOST, DB_PORT, DB_NAME, DB_CHARSET),
                    DB_USER, DB_PASS,
                    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]
                );
            } catch (PDOException $e) {
                error_log('[DOS-DB] '.$e->getMessage());
                Http::fail('Database connection failed. Check credentials in config.php', 503);
            }
        }
        return self::$c;
    }
    public static function setTenant(string $t): void { self::$tid = $t; }
    public static function tenant(): string { return self::$tid; }

    public static function run(string $sql, array $p = []): PDOStatement {
        $s = self::conn()->prepare($sql); $s->execute($p); return $s;
    }
    public static function one(string $q, array $p = []): ?array { return self::run($q,$p)->fetch() ?: null; }
    public static function all(string $q, array $p = []): array  { return self::run($q,$p)->fetchAll(); }
    public static function val(string $q, array $p = []): mixed  { return self::run($q,$p)->fetchColumn(); }

    public static function paged(string $sql, array $p, int $page, int $perPage = null): array {
        $perPage = min($perPage ?? DEFAULT_PAGE_SIZE, MAX_PAGE_SIZE);
        $page = max(1, $page); $off = ($page-1)*$perPage;
        $cnt = preg_replace('/\s+ORDER\s+BY\s+.+$/si','',$sql);
        $total = (int) self::val("SELECT COUNT(*) FROM ($cnt) _c", $p);
        $rows = self::all($sql." LIMIT $perPage OFFSET $off", $p);
        return [$rows, [
            'total'=>$total,'per_page'=>$perPage,'current_page'=>$page,
            'last_page'=>(int)ceil($total/max(1,$perPage)),
            'from'=>$total?$off+1:0,'to'=>min($off+$perPage,$total)
        ]];
    }
    public static function uuid(): string {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff),
            mt_rand(0,0x0fff)|0x4000,mt_rand(0,0x3fff)|0x8000,
            mt_rand(0,0xffff),mt_rand(0,0xffff),mt_rand(0,0xffff));
    }
    public static function begin(): void  { self::conn()->beginTransaction(); }
    public static function commit(): void { self::conn()->commit(); }
    public static function rollback(): void { if (self::conn()->inTransaction()) self::conn()->rollBack(); }
}
