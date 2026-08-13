<?php
// ═══ TOTP — RFC 6238 Time-based One-Time Password (f23: 2FA) ═══
// Pure PHP, no external library — compatible with Google Authenticator, Authy, etc.
class Totp {
    private const PERIOD = 30;
    private const DIGITS = 6;

    /** Generate a new base32 secret (160 bits / 20 bytes, the standard size). */
    public static function generateSecret(): string {
        return self::base32Encode(random_bytes(20));
    }

    /** Build the otpauth:// URI for QR-code generation in the frontend. */
    public static function authUri(string $secret, string $accountName, string $issuer = 'DistributionOS'): string {
        $label = rawurlencode($issuer.':'.$accountName);
        $params = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ]);
        return "otpauth://totp/$label?$params";
    }

    /** Verify a 6-digit code, allowing ±1 time step for clock drift. */
    public static function verify(string $secret, string $code): bool {
        $code = preg_replace('/\s+/', '', $code);
        if (!ctype_digit($code) || strlen($code) !== self::DIGITS) return false;
        $now = floor(time() / self::PERIOD);
        for ($drift = -1; $drift <= 1; $drift++) {
            if (hash_equals(self::generateCode($secret, (int)$now + $drift), $code)) return true;
        }
        return false;
    }

    public static function generateCode(string $secret, ?int $timeStep = null): string {
        $timeStep ??= (int) floor(time() / self::PERIOD);
        $key = self::base32Decode($secret);
        $time = pack('N*', 0, $timeStep); // 64-bit big-endian counter
        $hash = hash_hmac('sha1', $time, $key, true);
        $offset = ord($hash[19]) & 0xf;
        $value = (ord($hash[$offset]) & 0x7f) << 24
               | (ord($hash[$offset+1]) & 0xff) << 16
               | (ord($hash[$offset+2]) & 0xff) << 8
               | (ord($hash[$offset+3]) & 0xff);
        return str_pad((string)($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /** 10 single-use recovery codes, returned as plaintext once; store only their hashes. */
    public static function generateRecoveryCodes(int $count = 10): array {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
        }
        return $codes;
    }

    private static function base32Encode(string $data): string {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = ''; foreach (str_split($data) as $c) $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $out .= $alphabet[bindec($chunk)];
        }
        return $out;
    }
    private static function base32Decode(string $b32): string {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper(rtrim($b32, '='));
        $bits = '';
        foreach (str_split($b32) as $c) {
            $pos = strpos($alphabet, $c);
            if ($pos === false) continue;
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) $bytes .= chr(bindec($byte));
        }
        return $bytes;
    }
}
