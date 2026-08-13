<?php
/**
 * Bootstrap — loads config (from above webroot), DB, helpers.
 * Included by api/index.php and tests.
 */

// ─── Locate config (above webroot for security) ──────────
function dos_find_config(): array {
    // Typical shared hosting layout:
    //   /home/youruser/                  ← level 3 above bootstrap.php
    //       dos-private/config.php       ← target
    //       public_html/                 ← DOCUMENT_ROOT
    //           api/
    //               bootstrap.php        ← __FILE__ runs here
    //
    // dirname(__DIR__)    = public_html/api/../  = public_html/
    // dirname(__DIR__,2)  = public_html/api/../../  = home/youruser/  ← correct for above webroot
    //
    // Note: dirname(__DIR__) is equivalent to dirname(__FILE__, 2) since __DIR__ is already the
    // directory of bootstrap.php (i.e. public_html/api/), so dirname(__DIR__) = public_html/
    // and dirname(__DIR__,2) = /home/youruser/ — one level above public_html = above webroot.

    $aboveWebroot = dirname(__DIR__, 2);          // /home/youruser/  (works for public_html/api/)
    $webroot      = dirname(__DIR__);             // /home/youruser/public_html/
    $docRoot      = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');  // set by Apache/nginx

    $candidates = [
        // Highest-priority: explicit environment override for non-standard setups
        getenv('DOS_CONFIG') ?: null,
        // Standard layout: dos-private/ one level above public_html
        $aboveWebroot . '/dos-private/config.php',
        // Some hosts use private/ instead of dos-private/
        $aboveWebroot . '/private/config.php',
        // DOCUMENT_ROOT-relative (same destination via a different PHP global)
        $docRoot ? (dirname($docRoot) . '/dos-private/config.php') : null,
        $docRoot ? (dirname($docRoot) . '/private/config.php') : null,
        // dev/local fallback: config sits inside the project
        $webroot . '/private/config.php',
        dirname(__DIR__, 3) . '/dos-private/config.php',  // extra level up for subdirectory installs
    ];

    $tried = [];
    foreach ($candidates as $path) {
        if (!$path) continue;
        $real = realpath($path);
        if ($real && is_file($real)) return require $real;
        $tried[] = $path;
    }

    http_response_code(503);
    header('Content-Type: application/json');
    // Include the tried paths in dev mode only; never expose server paths in production.
    $detail = (($_SERVER['APP_ENV'] ?? getenv('APP_ENV')) === 'production')
        ? 'Place config.php in dos-private/ above your webroot and set correct DB credentials.'
        : 'Tried: ' . implode(' | ', $tried);
    echo json_encode(['success' => false, 'message' => 'Configuration file not found. ' . $detail]);
    exit;
}

$CFG = dos_find_config();

// ─── Fail-fast guard against unsafe defaults (Security Audit: ap1, pw3, db2) ─
// Refuses to boot if the placeholder secrets from config.example.php are
// still in place. A loud 500 here is far safer than a silent vulnerability.
function dos_guard_unsafe_defaults(array $cfg): void {
    $unsafe = [];
    if (($cfg['security']['jwt_secret'] ?? '') === '' ||
        str_contains($cfg['security']['jwt_secret'] ?? '', 'CHANGE-THIS')) {
        $unsafe[] = 'security.jwt_secret is still the placeholder value';
    }
    if (strlen($cfg['security']['jwt_secret'] ?? '') < 32) {
        $unsafe[] = 'security.jwt_secret is too short (need 32+ random bytes — see config.example.php comment)';
    }
    foreach (['your_db_name','your_db_user','your_db_password'] as $placeholder) {
        if (in_array($cfg['db']['name'] ?? '', [$placeholder], true) ||
            in_array($cfg['db']['user'] ?? '', [$placeholder], true) ||
            in_array($cfg['db']['pass'] ?? '', [$placeholder], true)) {
            $unsafe[] = 'db credentials still contain a placeholder value';
            break;
        }
    }
    if (($cfg['app']['url'] ?? '') === 'https://yourdomain.com') {
        $unsafe[] = 'app.url is still the placeholder domain';
    }
    if (!empty($unsafe)) {
        error_log('[DOS] FATAL — unsafe config detected: ' . implode('; ', $unsafe));
        http_response_code(500);
        header('Content-Type: application/json');
        // Message intentionally generic to the client; specifics are in error_log only.
        echo json_encode(['success' => false, 'message' => 'Server misconfigured. Check error log.']);
        exit;
    }
}
dos_guard_unsafe_defaults($CFG);

// ─── Define constants from config ─────────────────────────
define('DB_HOST', $CFG['db']['host']);
define('DB_NAME', $CFG['db']['name']);
define('DB_USER', $CFG['db']['user']);
define('DB_PASS', $CFG['db']['pass']);
define('DB_PORT', $CFG['db']['port']);
define('DB_CHARSET', $CFG['db']['charset']);
define('APP_NAME', $CFG['app']['name']);
define('APP_URL', $CFG['app']['url']);
define('APP_ENV', $CFG['app']['env']);
define('APP_DEBUG', $CFG['app']['debug']);
define('JWT_SECRET', $CFG['security']['jwt_secret']);
define('SESSION_LIFETIME', $CFG['security']['session_lifetime']);
define('MAX_LOGIN_ATTEMPTS', $CFG['security']['max_login_attempts']);
define('LOCKOUT_DURATION', $CFG['security']['lockout_duration']);
define('BCRYPT_COST', $CFG['security']['bcrypt_cost']);
define('RESET_TOKEN_TTL', $CFG['security']['reset_token_ttl']);
define('DEFAULT_PAGE_SIZE', $CFG['pagination']['default']);
define('MAX_PAGE_SIZE', $CFG['pagination']['max']);
define('MAIL_CONFIG', $CFG['mail']);

date_default_timezone_set('UTC');

// ─── Security hardening (Security Audit: sv1, sv3, sv4, sv7, sv8) ─
// sv1: display_errors must be Off in production — never leak stack traces
// sv3: log_errors On so failures are still recorded server-side
if (!APP_DEBUG) {
    error_reporting(0);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
}

// sv4: hide PHP version from response headers (expose_php may be locked by host,
// this removes the header at the app layer regardless of php.ini)
header_remove('X-Powered-By');

// sv7/sv8: harden session cookies if the app ever uses native PHP sessions
// (the JWT auth path does not depend on this, but it's cheap insurance)
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', '1');
    ini_set('session.use_only_cookies', '1');
    if (!empty($_SERVER['HTTPS'])) {
        ini_set('session.cookie_secure', '1');
    }
}

// sv2: warn (in logs only, never to the client) if running on an EOL PHP version
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    error_log('[DOS] WARNING: Running on PHP ' . PHP_VERSION . ' — upgrade to 8.0+ in cPanel MultiPHP Manager.');
}

// ─── Load library classes ─────────────────────────────────
require_once __DIR__ . '/lib/Db.php';
require_once __DIR__ . '/lib/Http.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Validator.php';
// Audit.php contains: Audit, Mailer, Notify, Csrf, RateLimiter, Workflow, Webhook
require_once __DIR__ . '/lib/Audit.php';
require_once __DIR__ . '/lib/Totp.php';
