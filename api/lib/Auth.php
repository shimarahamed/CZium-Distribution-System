<?php
// ═══ Auth — JWT, login, password reset, RBAC ═══
class Auth {
    private static ?array $me = null;

    public static function login(string $email, string $pw, string $slug): array {
        RateLimiter::hit('login:'.self::ip(), 10, 60);
        $t = Db::one("SELECT * FROM tenants WHERE slug=? AND is_active=1 LIMIT 1", [$slug]);
        if (!$t) throw new AuthErr('Organisation not found or inactive.');
        Db::setTenant($t['id']);
        $u = Db::one("SELECT u.*,r.name role_name,r.permissions FROM users u JOIN roles r ON r.id=u.role_id WHERE u.tenant_id=? AND u.email=? AND u.deleted_at IS NULL LIMIT 1", [$t['id'], strtolower(trim($email))]);
        if (!$u) throw new AuthErr('Invalid email or password.');
        if ($u['locked_until'] && strtotime($u['locked_until'])>time()) {
            $m=(int)ceil((strtotime($u['locked_until'])-time())/60);
            throw new AuthErr("Account locked. Try again in {$m}min.", 423);
        }
        if (!$u['is_active']) throw new AuthErr('Account disabled.', 403);
        if (!password_verify($pw, $u['password_hash'])) {
            $att=(int)$u['failed_login_count']+1;
            $lk=$att>=MAX_LOGIN_ATTEMPTS?date('Y-m-d H:i:s',time()+LOCKOUT_DURATION):null;
            if ($lk) $att=0;
            Db::run("UPDATE users SET failed_login_count=?,locked_until=? WHERE id=?", [$att,$lk,$u['id']]);
            Audit::log('LOGIN','user',$u['id'],$u['name']);
            throw new AuthErr('Invalid email or password.');
        }
        Db::run("UPDATE users SET failed_login_count=0,locked_until=NULL,last_login_at=NOW(),last_login_ip=? WHERE id=?", [self::ip(),$u['id']]);
        Audit::log('LOGIN','user',$u['id'],$u['name']);
        // f23: if 2FA is enabled, don't issue a full session token yet — issue a
        // short-lived "pending" token that only /auth/verify-totp can exchange.
        if (!empty($u['totp_enabled'])) {
            $pending = self::mkPendingJwt(['uid'=>$u['id'],'tid'=>$t['id']]);
            return ['requires_totp' => true, 'pending_token' => $pending];
        }
        $tok = self::mkJwt(['uid'=>$u['id'],'tid'=>$t['id'],'role'=>$u['role_name']]);
        setcookie('dos_token', $tok, ['expires'=>time()+SESSION_LIFETIME,'path'=>'/','secure'=>!empty($_SERVER['HTTPS']),'httponly'=>true,'samesite'=>'Lax']);
        return ['token'=>$tok,'user'=>self::publicUser($u, $t)];
    }

    /** f23: complete login after a successful TOTP code, exchanging the pending token for a real session. */
    public static function verifyTotpLogin(string $pendingToken, string $code): array {
        $cl = self::verifyJwt($pendingToken);
        if (empty($cl['pending'])) throw new AuthErr('Invalid pending token.');
        Db::setTenant($cl['tid']);
        $u = Db::one("SELECT u.*,r.name role_name,r.permissions FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.tenant_id=? LIMIT 1", [$cl['uid'], $cl['tid']]);
        if (!$u) throw new AuthErr('User not found.');
        $t = Db::one("SELECT * FROM tenants WHERE id=?", [$cl['tid']]);
        $validTotp = !empty($u['totp_secret']) && Totp::verify($u['totp_secret'], $code);
        $validRecovery = false;
        if (!$validTotp && !empty($u['totp_recovery_codes'])) {
            $hashes = json_decode($u['totp_recovery_codes'], true) ?: [];
            $codeHash = hash('sha256', strtoupper(trim($code)));
            if (in_array($codeHash, $hashes, true)) {
                $validRecovery = true;
                // Recovery codes are single-use — remove it once spent.
                $remaining = array_values(array_diff($hashes, [$codeHash]));
                Db::run("UPDATE users SET totp_recovery_codes=? WHERE id=?", [json_encode($remaining), $u['id']]);
            }
        }
        if (!$validTotp && !$validRecovery) throw new AuthErr('Invalid authentication code.');
        $tok = self::mkJwt(['uid'=>$u['id'],'tid'=>$t['id'],'role'=>$u['role_name']]);
        setcookie('dos_token', $tok, ['expires'=>time()+SESSION_LIFETIME,'path'=>'/','secure'=>!empty($_SERVER['HTTPS']),'httponly'=>true,'samesite'=>'Lax']);
        Audit::log('LOGIN','user',$u['id'],$u['name'],null,['action'=>'2fa_verified']);
        return ['token'=>$tok,'user'=>self::publicUser($u, $t)];
    }

    // ─── Forgot password: create reset token, email link ──
    public static function requestReset(string $email, string $slug): void {
        RateLimiter::hit('reset:'.self::ip(), 5, 300);
        // Security Audit em4: also limit per target email, independent of IP —
        // otherwise an attacker can rotate IPs to flood one victim's inbox.
        RateLimiter::hit('reset-email:'.strtolower(trim($email)), 3, 900);
        $t = Db::one("SELECT * FROM tenants WHERE slug=? AND is_active=1 LIMIT 1", [$slug]);
        if (!$t) return; // do not reveal
        Db::setTenant($t['id']);
        $u = Db::one("SELECT * FROM users WHERE tenant_id=? AND email=? AND deleted_at IS NULL AND is_active=1 LIMIT 1", [$t['id'], strtolower(trim($email))]);
        if (!$u) return; // silently succeed (no enumeration)
        $token = bin2hex(random_bytes(32));
        $hash  = hash('sha256', $token);
        $exp   = date('Y-m-d H:i:s', time()+RESET_TOKEN_TTL);
        Db::run("INSERT INTO password_resets(id,tenant_id,user_id,token_hash,expires_at) VALUES(?,?,?,?,?)",
            [Db::uuid(),$t['id'],$u['id'],$hash,$exp]);
        $link = APP_URL.'/reset-password?token='.$token.'&tenant='.urlencode($slug);
        Mailer::send($u['email'], 'Reset your password',
            "Hello {$u['name']},\n\nA password reset was requested for your DistributionOS account.\n\nReset link (valid 1 hour):\n$link\n\nIf you didn't request this, ignore this email.\n");
        Audit::log('UPDATE','user',$u['id'],$u['name'],null,['action'=>'password_reset_requested']);
    }

    public static function performReset(string $token, string $newPw, string $slug): void {
        if (!Validator::isStrongPassword($newPw)) throw new Unproc('Password must be 8+ chars with uppercase, lowercase, and a number.', ['password'=>['Weak password']]);
        $t = Db::one("SELECT * FROM tenants WHERE slug=? LIMIT 1", [$slug]);
        if (!$t) throw new AuthErr('Invalid reset link.');
        Db::setTenant($t['id']);
        $hash = hash('sha256', $token);
        $row = Db::one("SELECT * FROM password_resets WHERE tenant_id=? AND token_hash=? AND used_at IS NULL AND expires_at>NOW() LIMIT 1", [$t['id'],$hash]);
        if (!$row) throw new AuthErr('Reset link is invalid or expired.');
        Db::begin();
        try {
            Db::run("UPDATE users SET password_hash=?, failed_login_count=0, locked_until=NULL WHERE id=? AND tenant_id=?", [self::hash($newPw),$row['user_id'],$t['id']]);
            Db::run("UPDATE password_resets SET used_at=NOW() WHERE id=?", [$row['id']]);
            Db::commit();
        } catch (Throwable $e) { Db::rollback(); throw $e; }
        Audit::log('UPDATE','user',$row['user_id'],null,null,['action'=>'password_reset_completed']);
    }

    public static function require(): array {
        $tok = self::extractTok();
        if (!$tok) throw new AuthErr('Authentication required.');
        // f22: API keys are prefixed dos_live_ and authenticate without a JWT.
        // They resolve to a synthetic "system" user scoped by the key's stored
        // permissions rather than a role, so Auth::can()/need() still work.
        if (str_starts_with($tok, 'dos_live_')) return self::requireApiKey($tok);
        $cl = self::verifyJwt($tok);
        // f23: a pending (pre-2FA) token must never grant a real session even if
        // someone tries to use it directly against a normal endpoint.
        if (!empty($cl['pending'])) throw new AuthErr('Two-factor verification required.', 401);
        Db::setTenant($cl['tid']);
        // Zero-trust: re-verify the tenant is still active on EVERY request, not just
        // at login. A tenant suspended mid-session must be locked out immediately —
        // an already-issued JWT (valid up to 24h) must not outlive a suspension.
        $tenantActive = (bool) Db::val("SELECT is_active FROM tenants WHERE id=?", [$cl['tid']]);
        if (!$tenantActive) throw new AuthErr('This organisation has been suspended.', 403);
        $u = Db::one("SELECT u.*,r.name role_name,r.permissions FROM users u JOIN roles r ON r.id=u.role_id WHERE u.id=? AND u.tenant_id=? AND u.is_active=1 AND u.deleted_at IS NULL LIMIT 1", [$cl['uid'],$cl['tid']]);
        if (!$u) throw new AuthErr('User not found or inactive.');
        self::$me = $u;
        return $u;
    }

    private static function requireApiKey(string $key): array {
        $hash = hash('sha256', $key);
        $row = Db::one("SELECT * FROM api_keys WHERE key_hash=? AND is_active=1 AND revoked_at IS NULL LIMIT 1", [$hash]);
        if (!$row) throw new AuthErr('Invalid or revoked API key.');
        if ($row['expires_at'] && strtotime($row['expires_at']) < time()) throw new AuthErr('API key has expired.');
        Db::setTenant($row['tenant_id']);
        $tenantActive = (bool) Db::val("SELECT is_active FROM tenants WHERE id=?", [$row['tenant_id']]);
        if (!$tenantActive) throw new AuthErr('This organisation has been suspended.', 403);
        Db::run("UPDATE api_keys SET last_used_at=NOW() WHERE id=? AND tenant_id=?", [$row['id'], $row['tenant_id']]);
        // Build a permissions map from the key's scopes, e.g. "orders:read" -> permissions.orders.read = true
        $perms = [];
        foreach (json_decode($row['scopes'] ?? '[]', true) ?: [] as $scope) {
            [$res, $act] = array_pad(explode(':', $scope, 2), 2, 'read');
            $perms[$res][$act] = true;
        }
        self::$me = [
            'id' => 'api-key:'.$row['id'], 'name' => 'API Key: '.$row['name'], 'role_name' => 'ApiKey',
            'permissions' => json_encode($perms), 'is_active' => 1, 'must_change_password' => 0,
        ];
        return self::$me;
    }

    public static function can(string $r, string $a): bool {
        $p = json_decode(self::$me['permissions'] ?? '{}', true) ?: [];
        // Admins ("all": true) bypass every check, including rep row-scoping.
        if (!empty($p['all'])) return true;
        // Internal sentinel used by Scope::isRep() — only "all" grants it.
        if ($r === '_bypass_scope') return false;
        // Baseline: every authenticated user may read the dashboard. Without
        // this a role whose blob predates a module upgrade gets locked out of
        // the landing page entirely, which reads as a broken app rather than a
        // permissions problem.
        if ($r === 'dashboard' && $a === 'read') return true;
        return !empty($p[$r][$a]);
    }
    public static function need(string $r, string $a): void { if (!self::can($r,$a)) throw new AuthErr("Permission denied: $r.$a", 403); }
    public static function me(): ?array { return self::$me; }

    /**
     * Sanitised view of the current user for /auth/me.
     * Security: never return the raw row — it carries password_hash,
     * totp_secret and totp_recovery_codes.
     */
    public static function mePublic(): array {
        $u = self::$me;
        if (!$u) throw new AuthErr('Authentication required.');
        $t = Db::one("SELECT id,name,slug FROM tenants WHERE id=?", [Db::tenant()])
             ?: ['id'=>Db::tenant(),'name'=>'','slug'=>''];
        return self::publicUser($u, $t);
    }
    public static function uid(): string { return self::$me['id'] ?? ''; }
    public static function tid(): string { return Db::tenant(); }
    public static function role(): string { return self::$me['role_name'] ?? ''; }

    public static function hash(string $pw): string { return password_hash($pw, PASSWORD_BCRYPT, ['cost'=>BCRYPT_COST]); }

    private static function publicUser(array $u, array $t): array {
        $perms = json_decode($u['permissions'] ?? '{}', true) ?: [];
        return [
            'id'                   => $u['id'],
            'name'                 => $u['name'],
            'email'                => $u['email'],
            // Both keys are returned: `role` for backwards compatibility with
            // older clients, `role_name` because that is what the UI reads.
            'role'                 => $u['role_name'],
            'role_name'            => $u['role_name'],
            'permissions'          => $perms,
            // Present and non-null only for field reps. The UI uses this to
            // switch to the rep-scoped mobile experience.
            'rep_id'               => $u['rep_id'] ?? null,
            'is_rep'               => !empty($u['rep_id']) && empty($perms['all']),
            'tenant'               => ['id'=>$t['id'],'name'=>$t['name'],'slug'=>$t['slug']],
            'must_change_password' => (bool)$u['must_change_password'],
        ];
    }
    public static function mkJwt(array $pl): string {
        $h=self::b64e(json_encode(['typ'=>'JWT','alg'=>'HS256']));
        $pl=array_merge($pl,['iat'=>time(),'exp'=>time()+SESSION_LIFETIME,'jti'=>bin2hex(random_bytes(8))]);
        $p=self::b64e(json_encode($pl));
        return "$h.$p.".self::b64e(hash_hmac('sha256',"$h.$p",JWT_SECRET,true));
    }
    /** f23: 5-minute token issued after password check, before TOTP is verified. Marked 'pending'
     *  so it cannot be used as a normal session token even if intercepted (Auth::require() never
     *  accepts it because it's only consumed by verifyTotpLogin(), not the normal JWT path). */
    public static function mkPendingJwt(array $pl): string {
        $h=self::b64e(json_encode(['typ'=>'JWT','alg'=>'HS256']));
        $pl=array_merge($pl,['pending'=>true,'iat'=>time(),'exp'=>time()+300,'jti'=>bin2hex(random_bytes(8))]);
        $p=self::b64e(json_encode($pl));
        return "$h.$p.".self::b64e(hash_hmac('sha256',"$h.$p",JWT_SECRET,true));
    }
    public static function verifyJwt(string $tok): array {
        $pts=explode('.',$tok);
        if (count($pts)!==3) throw new AuthErr('Invalid token.');
        [$h,$p,$sig]=$pts;
        if (!hash_equals(self::b64e(hash_hmac('sha256',"$h.$p",JWT_SECRET,true)),$sig)) throw new AuthErr('Token invalid.');
        $cl=json_decode(self::b64d($p),true);
        if (!$cl||empty($cl['exp'])||$cl['exp']<time()) throw new AuthErr('Token expired.');
        return $cl;
    }
    private static function extractTok(): ?string {
        // Try every known server variable that Apache or FastCGI might use for the
        // Authorization header — shared hosts strip HTTP_AUTHORIZATION by default
        // and the actual variable name depends on the PHP SAPI and rewrite config.
        $apacheHeader = function_exists('apache_request_headers')
            ? (apache_request_headers()['Authorization'] ?? '')
            : '';
        $candidates = [
            $_SERVER['HTTP_AUTHORIZATION']          ?? '',
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '',
            $_SERVER['Authorization']               ?? '',
            $apacheHeader,
        ];
        foreach ($candidates as $h) {
            if ($h && preg_match('/^Bearer\s+(.+)$/i', $h, $m)) return trim($m[1]);
        }
        // Cookie fallback — works when Authorization header is completely unavailable.
        return $_COOKIE['dos_token'] ?? null;
    }
    private static function b64e(string $d): string { return rtrim(strtr(base64_encode($d),'+/','-_'),'='); }
    private static function b64d(string $d): string { return base64_decode(strtr($d,'-_','+/')); }
    public static function ip(): string {
        foreach (['HTTP_X_FORWARDED_FOR','HTTP_X_REAL_IP','REMOTE_ADDR'] as $k)
            if (!empty($_SERVER[$k])) return trim(explode(',',$_SERVER[$k])[0]);
        return '0.0.0.0';
    }
}
