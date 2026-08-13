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
        'name'    => 'your_db_name',        // ← CHANGE (cPanel: user_dbname)
        'user'    => 'your_db_user',        // ← CHANGE
        'pass'    => 'your_db_password',    // ← CHANGE
        'port'    => 3306,
        'charset' => 'utf8mb4',
    ],

    // ─── Application ──────────────────────────────────────
    'app' => [
        'name'  => 'DistributionOS',
        'url'   => 'https://yourdomain.com', // ← CHANGE (no trailing slash)
        'env'   => 'production',             // 'production' | 'development'
        'debug' => false,                    // true only while debugging
    ],

    // ─── Security ─────────────────────────────────────────
    'security' => [
        // Generate with: php -r "echo bin2hex(random_bytes(32));"
        'jwt_secret'         => 'CHANGE-THIS-run-the-command-above-to-generate-64-hex-chars',
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
        'from_email'  => 'noreply@yourdomain.com',  // ← CHANGE
        'from_name'   => 'DistributionOS',
        'smtp_host'   => '',                         // e.g. smtp.gmail.com
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
