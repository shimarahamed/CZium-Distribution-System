<?php
// ═══ HTTP — responses & exceptions ═══
class Err      extends RuntimeException {}
class AuthErr  extends Err { public function __construct(string $m, int $c=401){ parent::__construct($m,$c); } }
class NotFound extends Err { public function __construct(string $m='Not found'){ parent::__construct($m,404); } }
class Conflict extends Err { public function __construct(string $m){ parent::__construct($m,409); } }
class Unproc   extends Err { public array $errs; public function __construct(string $m, array $e=[]){ $this->errs=$e; parent::__construct($m,422); } }

class Http {
    public static function ok(mixed $d=null, int $s=200): never { http_response_code($s); echo json_encode(['success'=>true,'data'=>$d]); exit; }
    public static function created(mixed $d): never { self::ok($d,201); }
    public static function noContent(): never { http_response_code(204); exit; }
    public static function paged(array $rows, array $pg): never { http_response_code(200); echo json_encode(['success'=>true,'data'=>$rows,'pagination'=>$pg]); exit; }
    public static function fail(string $m, int $s=400, array $errs=[]): never {
        http_response_code($s);
        $r=['success'=>false,'message'=>$m];
        if ($errs) $r['errors']=$errs;
        echo json_encode($r); exit;
    }
}
