<?php
// ============================================================
// WP API - Configuration
// All secrets live in the .env file in the app root (never commit it,
// never put real values in this file). See .env.example.
// ============================================================

if (defined('APP_ROOT')) return;
define('APP_ROOT', dirname(__DIR__));

(function () {
    $file = APP_ROOT . '/.env';
    if (!is_readable($file)) return;
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        if (strlen($v) >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[-1] === $v[0]) $v = substr($v, 1, -1);
        elseif (($p = strpos($v, ' #')) !== false) $v = rtrim(substr($v, 0, $p));
        if (getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
    }
})();

function env(string $key, $default = null) {
    $v = $_ENV[$key] ?? getenv($key);
    if ($v === false || $v === null || $v === '') return $default;
    return match (strtolower((string)$v)) { 'true' => true, 'false' => false, default => $v };
}

define('APP_NAME',    env('APP_NAME', 'WP API Pro'));
define('APP_VERSION', '2.0.0');
define('APP_URL',     rtrim(env('APP_URL', 'https://wpapi.grdpinfotech.com'), '/'));
define('APP_ENV',     env('APP_ENV', 'production'));
define('APP_DEBUG',   APP_ENV !== 'production' && env('APP_DEBUG', false));

// Errors: never shown to visitors in production, always logged
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
if (!is_dir(APP_ROOT . '/storage/logs')) @mkdir(APP_ROOT . '/storage/logs', 0750, true);
ini_set('error_log', APP_ROOT . '/storage/logs/php-error.log');

// Database
define('DB_HOST',    env('DB_HOST', 'localhost'));
define('DB_NAME',    env('DB_NAME', ''));
define('DB_USER',    env('DB_USER', ''));
define('DB_PASS',    env('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

// Security
define('SECRET_KEY',        env('SECRET_KEY', ''));
define('CSRF_TOKEN_EXPIRE', 3600);
define('SESSION_LIFETIME',  86400);

// WhatsApp Cloud API (WA_GRAPH_URL is only for local mocks)
define('WA_API_VERSION', env('WA_API_VERSION', 'v23.0'));
define('WA_API_BASE',    rtrim(env('WA_GRAPH_URL', 'https://graph.facebook.com'), '/') . '/' . WA_API_VERSION);

// Meta app (webhook signature + Embedded Signup). Features stay off while empty.
define('META_APP_ID',               env('META_APP_ID', ''));
define('META_APP_SECRET',           env('META_APP_SECRET', env('WA_APP_SECRET', '')));
define('META_ES_CONFIG_ID',         env('META_ES_CONFIG_ID', ''));
define('META_WEBHOOK_VERIFY_TOKEN', env('META_WEBHOOK_VERIFY_TOKEN', ''));
define('META_ES_REGISTER_PIN',      env('META_ES_REGISTER_PIN', ''));

// Razorpay (wallet recharge)
define('RAZORPAY_KEY_ID',         env('RAZORPAY_KEY_ID', env('RZP_KEY_ID', '')));
define('RAZORPAY_KEY_SECRET',     env('RAZORPAY_KEY_SECRET', env('RZP_KEY_SECRET', '')));
define('RAZORPAY_WEBHOOK_SECRET', env('RAZORPAY_WEBHOOK_SECRET', env('RZP_WEBHOOK_SECRET', '')));
if (env('RAZORPAY_API_BASE')) define('RAZORPAY_API_BASE', env('RAZORPAY_API_BASE')); // local mocks only

// New sign-ups get the Trial plan automatically for this many days (0 = admin activates manually)
define('AUTO_TRIAL_DAYS', max(0, min(30, (int)env('AUTO_TRIAL_DAYS', 7))));

// Public API
define('API_ALLOW_PRIVATE_CALLBACKS', APP_ENV !== 'production' && env('API_ALLOW_PRIVATE_CALLBACKS', false));

// File Upload
define('UPLOAD_PATH',     APP_ROOT . '/assets/uploads/');
define('UPLOAD_URL',      APP_URL . '/assets/uploads/');
define('MAX_UPLOAD_SIZE', 16 * 1024 * 1024);

// Email (SMTP). If MAIL_PASS is empty, emails are written to storage/logs/mail.log instead.
define('MAIL_HOST',      env('MAIL_HOST', 'smtp.hostinger.com'));
define('MAIL_PORT',      (int)env('MAIL_PORT', 587));
define('MAIL_USER',      env('MAIL_USER', ''));
define('MAIL_PASS',      env('MAIL_PASS', ''));
define('MAIL_FROM',      env('MAIL_FROM', env('MAIL_USER', 'noreply@localhost')));
define('MAIL_FROM_NAME', APP_NAME);

// Support contact shown on public pages
define('SUPPORT_EMAIL', env('SUPPORT_EMAIL', 'info@grdpinfotech.com'));
define('SUPPORT_PHONE', env('SUPPORT_PHONE', ''));

// Legal / business details shown on Privacy, Terms, Refund and Contact pages
define('LEGAL_COMPANY',   env('LEGAL_COMPANY', 'GRDP InfoTech'));
define('LEGAL_ADDRESS',   env('LEGAL_ADDRESS', ''));          // registered address (required by Razorpay)
define('LEGAL_GSTIN',     env('LEGAL_GSTIN', ''));
define('LEGAL_CITY',      env('LEGAL_CITY', ''));             // courts' jurisdiction, e.g. "Pune, Maharashtra"
define('GRIEVANCE_NAME',  env('GRIEVANCE_NAME', ''));         // grievance officer (DPDP Act 2023)
define('LEGAL_UPDATED',   env('LEGAL_UPDATED', '14 October 2026'));
define('REFUND_DAYS',     max(1, (int)env('REFUND_DAYS', 7))); // working days to process an approved refund

// Cron secret (only needed when cron / migrate.php are triggered over HTTP)
define('CRON_SECRET', env('CRON_SECRET', ''));

// QR Code base URL
define('QR_REDIRECT_URL', APP_URL . '/qr/');

// Pagination
define('PER_PAGE', 25);

// Timezone — DB stores UTC; APP_TIMEZONE is used for user-entered dates
date_default_timezone_set('UTC');
define('APP_TIMEZONE', env('APP_TIMEZONE', 'Asia/Kolkata'));

if (DB_NAME === '' || SECRET_KEY === '') {
    http_response_code(500);
    error_log('Missing .env configuration (DB_NAME / SECRET_KEY).');
    exit(PHP_SAPI === 'cli' ? "Missing .env configuration. Copy .env.example to .env\n" : 'Server is not configured.');
}
