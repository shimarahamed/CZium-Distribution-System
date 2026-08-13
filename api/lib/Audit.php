<?php
// ═══ Audit ═══
class Audit {
    public static function log(string $act, string $type, ?string $eid=null, ?string $label=null, ?array $old=null, ?array $new=null): void {
        try {
            $u = Auth::me(); $tid = Auth::tid() ?: 'SYSTEM';
            Db::run("INSERT INTO audit_logs(tenant_id,user_id,user_name,action,entity_type,entity_id,entity_label,old_values,new_values,ip_address,user_agent) VALUES(?,?,?,?,?,?,?,?,?,?,?)",
                [$tid,$u['id']??null,$u['name']??'System',$act,$type,$eid,$label,
                 $old?json_encode($old):null,$new?json_encode($new):null,
                 Auth::ip(),substr($_SERVER['HTTP_USER_AGENT']??'',0,200)]);
        } catch (Throwable $e) { error_log('[Audit] '.$e->getMessage()); }
    }
    public static function diff(array $a, array $b): array {
        $d=[]; foreach ($b as $k=>$v) if (array_key_exists($k,$a)&&(string)$a[$k]!==(string)$v) $d[$k]=['from'=>$a[$k],'to'=>$v]; return $d;
    }
}

// ═══ Mailer — PHP mail() or SMTP ═══
class Mailer {
    public static function send(string $to, string $subject, string $body): bool {
        $cfg = MAIL_CONFIG;
        $from = $cfg['from_name'].' <'.$cfg['from_email'].'>';
        try {
            return match ($cfg['driver']) {
                'smtp' => self::smtp($to, $subject, $body, $cfg),
                'log'  => (bool) error_log("[MAIL] to=$to subject=$subject\n$body"),
                default => mail($to, $subject, $body, "From: $from\r\nContent-Type: text/plain; charset=UTF-8\r\n"),
            };
        } catch (Throwable $e) { error_log('[Mailer] '.$e->getMessage()); return false; }
    }
    // Minimal SMTP client (no external library). Works for most shared-host SMTP relays.
    private static function smtp(string $to, string $subj, string $body, array $c): bool {
        $secure = $c['smtp_secure']==='ssl' ? 'ssl://' : '';
        $fp = @fsockopen($secure.$c['smtp_host'], (int)$c['smtp_port'], $errno, $errstr, 15);
        if (!$fp) { error_log("[SMTP] connect failed: $errstr"); return false; }
        $read = function() use ($fp){ return fgets($fp, 512); };
        $cmd  = function(string $s) use ($fp,$read){ fwrite($fp, $s."\r\n"); return $read(); };
        $read();
        $cmd('EHLO '.($c['smtp_host']));
        if ($c['smtp_secure']==='tls') { $cmd('STARTTLS'); stream_socket_enable_crypto($fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT); $cmd('EHLO '.$c['smtp_host']); }
        $cmd('AUTH LOGIN');
        $cmd(base64_encode($c['smtp_user']));
        $cmd(base64_encode($c['smtp_pass']));
        $cmd('MAIL FROM:<'.$c['from_email'].'>');
        $cmd('RCPT TO:<'.$to.'>');
        $cmd('DATA');
        $headers = "From: {$c['from_name']} <{$c['from_email']}>\r\nTo: <$to>\r\nSubject: $subj\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n";
        $cmd($headers.$body."\r\n.");
        $cmd('QUIT');
        fclose($fp);
        return true;
    }
}

// ═══ Notifications (DB + optional email) ═══
class Notify {
    public static function send(string $tid, string $uid, string $type, string $title, string $msg, ?string $link=null, bool $email=false): void {
        try {
            Db::run("INSERT INTO notifications(tenant_id,user_id,type,title,message,link) VALUES(?,?,?,?,?,?)", [$tid,$uid,$type,$title,$msg,$link]);
            if ($email) {
                $u = Db::one("SELECT email,name FROM users WHERE id=? AND tenant_id=?", [$uid,$tid]);
                if ($u && $u['email']) Mailer::send($u['email'], $title, $msg.($link?"\n\n$link":''));
            }
        } catch (Throwable $e) { error_log('[Notify] '.$e->getMessage()); }
    }
    public static function toRole(string $tid, string $roleId, string $type, string $title, string $msg, bool $email=false): void {
        foreach (Db::all("SELECT id FROM users WHERE tenant_id=? AND role_id=? AND is_active=1 AND deleted_at IS NULL", [$tid,$roleId]) as $u)
            self::send($tid,$u['id'],$type,$title,$msg,null,$email);
    }
}

// ═══ CSRF — double-submit token for cookie-auth requests ═══
class Csrf {
    // Validates only when auth came from a cookie (browser). Bearer-token (API) clients are exempt.
    public static function verify(string $method): void {
        if (in_array($method, ['GET','HEAD','OPTIONS'])) return;
        $usingCookie = empty($_SERVER['HTTP_AUTHORIZATION']) && !empty($_COOKIE['dos_token']);
        if (!$usingCookie) return; // Bearer clients don't need CSRF
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $cookie = $_COOKIE['dos_csrf'] ?? '';
        if (!$header || !$cookie || !hash_equals($cookie, $header))
            throw new AuthErr('CSRF token missing or invalid.', 403);
    }
    public static function issue(): string {
        $tok = bin2hex(random_bytes(32));
        setcookie('dos_csrf', $tok, ['expires'=>time()+SESSION_LIFETIME,'path'=>'/','secure'=>!empty($_SERVER['HTTPS']),'httponly'=>false,'samesite'=>'Lax']);
        return $tok;
    }
}

// ═══ Rate Limiter — DB-backed (multi-server safe) ═══
class RateLimiter {
    public static function hit(string $key, int $max, int $window): void {
        try {
            $now = time();
            $bucket = (int) floor($now / $window);
            $k = $key.':'.$bucket;
            Db::run("INSERT INTO rate_limits(rl_key,hits,expires_at) VALUES(?,1,?) ON DUPLICATE KEY UPDATE hits=hits+1",
                [$k, date('Y-m-d H:i:s', ($bucket+1)*$window)]);
            $hits = (int) Db::val("SELECT hits FROM rate_limits WHERE rl_key=?", [$k]);
            if ($hits > $max) Http::fail('Too many requests. Please slow down.', 429);
            // opportunistic cleanup (1% of requests)
            if (mt_rand(1,100) === 1) Db::run("DELETE FROM rate_limits WHERE expires_at < NOW()");
        } catch (Throwable $e) {
            // If rate-limit table missing or DB busy, fail open (don't block legit traffic)
            error_log('[RateLimit] '.$e->getMessage());
        }
    }
}

// ═══ Workflow Engine ═══
class Workflow {
    public static function run(string $entity, string $event, array $data): void {
        foreach (Db::all("SELECT * FROM workflow_rules WHERE tenant_id=? AND entity_type=? AND trigger_event=? AND is_active=1 ORDER BY sort_order", [Auth::tid(),$entity,$event]) as $rule) {
            $conds=json_decode($rule['conditions']??'[]',true); $acts=json_decode($rule['actions']??'[]',true);
            $pass=true;
            foreach ($conds as $c) {
                $v=$data[$c['field']]??null;
                $pass=match($c['operator']??'='){'>='=>(float)$v>=(float)$c['value'],'<='=>(float)$v<=(float)$c['value'],'>'=>(float)$v>(float)$c['value'],'<'=>(float)$v<(float)$c['value'],'!='=> $v!=$c['value'],default=>$v==$c['value']};
                if (!$pass) break;
            }
            if (!$pass) continue;
            foreach ($acts as $a) {
                $msg=preg_replace_callback('/\{(\w+)\}/',fn($m)=>$data[$m[1]]??$m[0],$a['message']??'');
                if (($a['type']??'')==='notify' && $rule['approver_role']) Notify::toRole(Auth::tid(),$rule['approver_role'],'workflow',$rule['name'],$msg,true);
                if (($a['type']??'')==='require_approval') Db::run("UPDATE sales_orders SET status='Pending Approval' WHERE id=? AND tenant_id=?",[$data['id']??'',Auth::tid()]);
            }
        }
        // f18: fire any registered webhooks for this entity.event after workflow rules run
        Webhook::dispatch($entity.'.'.$event, $data);
    }
}

// ═══ Webhook Dispatcher (f18) ═══
// Publishes events to configured URLs. Payloads are HMAC-SHA256 signed so
// receivers can verify authenticity (header: X-DOS-Signature).
class Webhook {
    public static function dispatch(string $event, array $data): void {
        try {
            $tid = Auth::tid();
            if (!$tid) return; // no tenant context (e.g. called outside a request) — skip silently
            $subs = Db::all("SELECT * FROM webhook_subscriptions WHERE tenant_id=? AND is_active=1", [$tid]);
            foreach ($subs as $sub) {
                $events = json_decode($sub['events'] ?? '[]', true) ?: [];
                if (!in_array($event, $events, true) && !in_array('*', $events, true)) continue;
                self::deliver($sub, $event, $data);
            }
        } catch (Throwable $e) { error_log('[Webhook] dispatch error: '.$e->getMessage()); }
    }

    private static function deliver(array $sub, string $event, array $data): void {
        $payload = json_encode(['event' => $event, 'data' => $data, 'timestamp' => time()]);
        $sig = hash_hmac('sha256', $payload, $sub['secret']);
        $ok = false; $code = 0;
        try {
            $ctx = stream_context_create([
                'http' => [
                    'method'  => 'POST',
                    'header'  => "Content-Type: application/json\r\nX-DOS-Signature: $sig\r\nX-DOS-Event: $event\r\n",
                    'content' => $payload,
                    'timeout' => 5,
                    'ignore_errors' => true,
                ],
            ]);
            $result = @file_get_contents($sub['url'], false, $ctx);
            // $http_response_header is populated by PHP after a stream-wrapped request
            if (isset($http_response_header[0]) && preg_match('/(\d{3})/', $http_response_header[0], $m)) {
                $code = (int) $m[1];
            }
            $ok = $code >= 200 && $code < 300;
        } catch (Throwable $e) {
            error_log('[Webhook] delivery failed: '.$e->getMessage());
        }
        Db::run("INSERT INTO webhook_deliveries(id,tenant_id,subscription_id,event,payload,status_code,success,attempts) VALUES(?,?,?,?,?,?,?,1)",
            [Db::uuid(), $sub['tenant_id'], $sub['id'], $event, $payload, $code ?: null, $ok ? 1 : 0]);
    }

    public static function newSecret(): string { return bin2hex(random_bytes(24)); }
}
