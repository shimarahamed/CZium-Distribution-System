<?php
/**
 * DistributionOS — Configuration
 * ================================
 * ⚠️  PLACE THIS FILE ABOVE public_html (NOT web-accessible)
 *
 * Recommended location:  /home/youruser/dos-private/config.php
 * The api/bootstrap.php looks for it there.
 *
 * If your host structure differs, set the DOS_CONFIG environment
 * variable, or edit the search paths in api/bootstrap.php.
 */

return [
    // ─── Database ─────────────────────────────────────────
    'db' => [
        'host'    => 'localhost',
        'name'    => 'garagea2_distribution',        // ← CHANGE (cPanel: user_dbname)
        'user'    => 'garagea2_distribution',        // ← CHANGE
        'pass'    => '0757828781sS.@',    // ← CHANGE
        'port'    => 3306,
        'charset' => 'utf8mb4',
    ],

    // ─── Application ──────────────────────────────────────
    'app' => [
        'name'  => 'DistributionOS',
        'url'   => 'https://distribution.czium.com', // ← CHANGE (no trailing slash)
        'env'   => 'production',             // 'production' | 'development'
        'debug' => false,                    // true only while debugging
    ],

    // ─── Security ─────────────────────────────────────────
    'security' => [
        // Generate with: php -r "echo bin2hex(random_bytes(32));"
        'jwt_secret'         => '99e40a45630472b0615164882b7b3a22b730a700e0cda84d03607daef78c2301',
        'session_lifetime'   => 86400,   // 24h
        'max_login_attempts' => 5,
        'lockout_duration'   => 900,     // 15 min
        'bcrypt_cost'        => 11,
        'reset_token_ttl'    => 3600,    // password reset link valid 1h
    ],

    // ─── Email (SMTP) ─────────────────────────────────────
    // Leave 'driver' as 'mail' to use PHP mail(), or 'smtp' for SMTP.
    'mail' => [
        'driver'      => 'mail',                    // 'mail' | 'smtp' | 'log'
        'from_email'  => 'jiira@czium.com',  // ← CHANGE
        'from_name'   => 'DistributionOS',
        'smtp_host'   => 'mail.czium.com',                         // e.g. smtp.gmail.com
        'smtp_port'   => 587,
        'smtp_user'   => '',
        'smtp_pass'   => '',
        'smtp_secure' => 'tls',                      // 'tls' | 'ssl'
    ],

    // ─── Pagination ───────────────────────────────────────
    'pagination' => [
        'default' => 25,
        'max'     => 100,
    ],
];
