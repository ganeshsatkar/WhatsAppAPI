<?php
// ============================================================
// WhatsFlow Pro - Core Functions
// ============================================================

// ---- Session ----

function startSession(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_name('wpapi_sess');
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => str_starts_with(APP_URL, 'https://'),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', 1);
        ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
        session_start();
    }
}

// ---- Auth ----

function isLoggedIn(): bool {
    startSession();
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

function requireLogin(): void {
    if (!isLoggedIn()) {
        redirect('/login.php');
    }
    $u = currentUser();
    if (!$u || !(int)$u['is_active']) {      // deleted or suspended while logged in
        session_unset();
        session_destroy();
        redirect('/login.php?disabled=1');
    }
    enforceSubscriptionGate();
}

function isSuperAdmin(): bool {
    return (currentUser()['role'] ?? '') === 'superadmin';
}

function requireAdmin(): void {
    requireLogin();
    if (currentUser()['role'] !== 'superadmin') {
        redirect('/user/dashboard.php');
    }
}

function currentUser(): ?array {
    static $user = null;
    if ($user !== null) return $user;
    if (!isLoggedIn()) return null;
    $user = db()->row('SELECT u.*, up.company_name, up.phone, up.timezone FROM users u LEFT JOIN user_profiles up ON up.user_id = u.id WHERE u.id = ?', [$_SESSION['user_id']]);
    return $user;
}

function login(array $user): void {
    startSession();
    session_regenerate_id(true);
    $_SESSION['user_id']   = $user['id'];
    $_SESSION['user_role'] = $user['role'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['logged_in'] = true;

    db()->run('UPDATE users SET last_login = NOW() WHERE id = ?', [$user['id']]);
}

function logout(): void {
    startSession();
    session_unset();
    session_destroy();
    redirect('/login.php');
}

// ---- CSRF ----

function csrfToken(): string {
    startSession();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken()) . '">';
}

function verifyCsrf(): void {
    startSession();
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(403);
        header('Content-Type: application/json');
        die(json_encode(['success' => false, 'error' => 'Session expired. Please refresh the page and try again.']));
    }
}

// ---- Login / sign-up / reset throttling (per email key + IP) ----

const LOGIN_MAX_ATTEMPTS = 5;
const LOGIN_WINDOW_MIN   = 15;

function clientIp(): string {
    return substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 50);
}

function loginLocked(string $email): bool {
    $byEmail = db()->count('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND attempted_at > (NOW() - INTERVAL ' . LOGIN_WINDOW_MIN . ' MINUTE)', [$email]);
    $byIp    = db()->count('SELECT COUNT(*) FROM login_attempts WHERE ip_address = ? AND attempted_at > (NOW() - INTERVAL ' . LOGIN_WINDOW_MIN . ' MINUTE)', [clientIp()]);
    return $byEmail >= LOGIN_MAX_ATTEMPTS || $byIp >= LOGIN_MAX_ATTEMPTS * 4;
}

function loginFailed(string $email): void {
    db()->run('INSERT INTO login_attempts (email, ip_address) VALUES (?,?)', [$email, clientIp()]);
}

function loginSucceeded(string $email): void {
    db()->run('DELETE FROM login_attempts WHERE email = ?', [$email]);
    db()->run('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
}

// ---- Helpers ----

function redirect(string $path): never {
    header('Location: ' . APP_URL . $path);
    exit;
}

function e(string|int|float|null $str): string {
    return htmlspecialchars((string)$str, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

function jsonResponse(array $data, int $code = 200): never {
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

function sanitize(?string $str): string {
    return trim(strip_tags((string)$str));
}

function slugify(string $text): string {
    return strtolower(preg_replace('/[^a-z0-9]+/i', '-', trim($text)));
}

function generateToken(int $length = 32): string {
    return bin2hex(random_bytes(intdiv($length, 2)));
}

function hashPassword(string $password): string {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

function verifyPassword(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

function timeAgo(\DateTime|string $datetime): string {
    $time = is_string($datetime) ? strtotime($datetime) : $datetime->getTimestamp();
    $diff = time() - $time;
    if ($diff < 60)         return 'just now';
    if ($diff < 3600)       return (int)($diff / 60) . 'm ago';
    if ($diff < 86400)      return (int)($diff / 3600) . 'h ago';
    if ($diff < 604800)     return (int)($diff / 86400) . 'd ago';
    return date('M j', $time);
}

function formatNumber(int|float $n): string {
    if ($n >= 1_000_000) return round($n / 1_000_000, 1) . 'M';
    if ($n >= 1_000)     return round($n / 1_000, 1) . 'K';
    return (string)$n;
}

function currentPlan(): ?array {
    $user = currentUser();
    if (!$user) return null;
    return subscriptionState((int)$user['id'])['plan'];   // plan in force (or the last plan when inactive)
}

function canDo(string $feature): bool {
    $user = currentUser();
    return $user ? planAllows((int)$user['id'], $feature) : false;
}

function apiToken(): ?string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (str_starts_with($h, 'Bearer ')) {
        return substr($h, 7);
    }
    return null;   // never accept tokens in the URL (they end up in logs)
}

function requireApiAuth(): array {
    $token = apiToken();
    if (!$token) jsonResponse(['error' => 'Unauthorized'], 401);
    $user = db()->row('SELECT * FROM users WHERE api_token = ? AND is_active = 1', [$token]);
    if (!$user) jsonResponse(['error' => 'Invalid API token'], 401);
    return $user;
}

function logActivity(string $action, string $desc = '', ?int $userId = null): void {
    $uid = $userId ?? ($_SESSION['user_id'] ?? null);
    $ua  = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 500);
    db()->run('INSERT INTO activity_logs (user_id, action, description, ip_address, user_agent) VALUES (?,?,?,?,?)',
        [$uid, $action, $desc, clientIp(), $ua]);
}

function notify(int $userId, string $title, string $body = '', string $type = 'info', string $link = ''): void {
    db()->run('INSERT INTO notifications (user_id, title, body, type, link) VALUES (?,?,?,?,?)',
        [$userId, $title, $body, $type, $link]);
}

// ---- Automation ----

function triggerAutomation(int $userId, string $event, int $contactId): void {
    if (!planAllows($userId, 'automation_workflows')) return;   // plan expired or automation not in plan
    $rules = db()->all('SELECT * FROM automation_rules WHERE user_id = ? AND trigger_event = ? AND is_active = 1', [$userId, $event]);
    foreach ($rules as $rule) {
        if ($rule['run_once']) {
            $already = db()->count('SELECT COUNT(*) FROM automation_logs WHERE rule_id = ? AND contact_id = ?', [$rule['id'], $contactId]);
            if ($already) continue;
        }
        $runAt = date('Y-m-d H:i:s', time() + ((int)($rule['delay_minutes'] ?? 0)) * 60);
        db()->insert('INSERT INTO automation_logs (rule_id, user_id, contact_id, status, trigger_data, created_at) VALUES (?,?,?,?,?,?)',
            [$rule['id'], $userId, $contactId, 'pending', json_encode(['event' => $event]), $runAt]);
    }
}

function paginate(string $table, array $conditions = [], int $page = 1, int $perPage = PER_PAGE): array {
    $where  = $conditions ? ' WHERE ' . implode(' AND ', array_keys($conditions)) : '';
    $params = array_values($conditions);
    $total  = db()->count("SELECT COUNT(*) FROM $table$where", $params);
    $offset = ($page - 1) * $perPage;
    $rows   = db()->all("SELECT * FROM $table$where LIMIT $perPage OFFSET $offset", $params);
    return [
        'data'         => $rows,
        'total'        => $total,
        'page'         => $page,
        'per_page'     => $perPage,
        'total_pages'  => (int)ceil($total / $perPage),
    ];
}

// ---- WhatsApp helpers shared by inbox, campaigns and cron ----

// Digits only, no international "00" / trunk "0" prefix, 10-digit Indian numbers get the 91 prefix.
function normalizePhone(?string $phone): string {
    $p = preg_replace('/[^0-9]/', '', (string)$phone);
    if (str_starts_with($p, '00')) $p = substr($p, 2);
    $p = ltrim($p, '0');
    return strlen($p) === 10 ? '91' . $p : $p;
}

// A plausible WhatsApp number after normalizePhone(): 8–15 digits.
function isValidPhone(string $normalized): bool {
    return (bool)preg_match('/^\d{8,15}$/', $normalized);
}

// Forms a phone may have been stored in (with and without the 91 prefix).
function phoneVariants(string $phone): array {
    $p = normalizePhone($phone);
    $v = [$p];
    if (strlen($p) === 12 && str_starts_with($p, '91')) { $v[] = substr($p, 2); $v[] = '+' . $p; }
    return array_values(array_unique($v));
}

// ---- Time zones: DB is UTC, users enter/read dates in their own zone ----

function userTimezone(): string {
    $tz = currentUser()['timezone'] ?? '';
    return ($tz && $tz !== 'UTC' && in_array($tz, timezone_identifiers_list(), true)) ? $tz : APP_TIMEZONE;
}

function localToUtc(string $local): ?string {
    try {
        return (new DateTime($local, new DateTimeZone(userTimezone())))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    } catch (\Throwable $e) { return null; }
}

function toLocalTime(?string $utc, string $format = 'M j, Y g:i A'): string {
    if (!$utc) return '';
    try {
        return (new DateTime($utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(userTimezone()))->format($format);
    } catch (\Throwable $e) { return $utc; }
}

// ---- Secrets at rest (WhatsApp access tokens) — AES-256-GCM with SECRET_KEY ----

function encryptSecret(?string $plain): ?string {
    if ($plain === null || $plain === '' || str_starts_with($plain, 'enc:v1:')) return $plain;
    $key = hash('sha256', SECRET_KEY, true);
    $iv  = random_bytes(12);
    $ct  = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    return 'enc:v1:' . base64_encode($iv . $tag . $ct);
}

// Legacy plain-text values are returned unchanged, so old rows keep working.
function decryptSecret(?string $stored): ?string {
    if ($stored === null || !str_starts_with($stored, 'enc:v1:')) return $stored;
    $raw = base64_decode(substr($stored, 7), true);
    if ($raw === false || strlen($raw) < 29) return null;
    $key = hash('sha256', SECRET_KEY, true);
    $pt  = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $pt === false ? null : $pt;
}

// ---- Email (SMTP STARTTLS 587 / TLS 465). Without credentials → storage/logs/mail.log ----

function sendMail(string $to, string $subject, string $html): bool {
    if (MAIL_PASS === '' || MAIL_USER === '') {
        $line = '[' . date('c') . "] TO: $to | SUBJECT: $subject\n" . strip_tags(str_replace(['<br>', '</p>'], "\n", $html)) . "\n----\n";
        return (bool)file_put_contents(APP_ROOT . '/storage/logs/mail.log', $line, FILE_APPEND);
    }
    $host = (MAIL_PORT === 465 ? 'ssl://' : 'tcp://') . MAIL_HOST . ':' . MAIL_PORT;
    $fp = @stream_socket_client($host, $errno, $errstr, 15);
    if (!$fp) { error_log("SMTP connect failed: $errstr"); return false; }
    stream_set_timeout($fp, 15);
    $read = function () use ($fp): string {
        $out = '';
        while (($line = fgets($fp, 515)) !== false) { $out .= $line; if (isset($line[3]) && $line[3] === ' ') break; }
        return $out;
    };
    $cmd = function (string $c, array $ok) use ($fp, $read): bool {
        fwrite($fp, $c . "\r\n");
        $r = $read();
        if (!in_array((int)substr($r, 0, 3), $ok, true)) { error_log('SMTP error after "' . explode(' ', $c)[0] . '": ' . trim($r)); return false; }
        return true;
    };
    $read();
    $domain = parse_url(APP_URL, PHP_URL_HOST) ?: 'localhost';
    $ok = $cmd('EHLO ' . $domain, [250]);
    if ($ok && MAIL_PORT !== 465) {
        $ok = $cmd('STARTTLS', [220])
           && stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)
           && $cmd('EHLO ' . $domain, [250]);
    }
    $ok = $ok && $cmd('AUTH LOGIN', [334]) && $cmd(base64_encode(MAIL_USER), [334]) && $cmd(base64_encode(MAIL_PASS), [235])
        && $cmd('MAIL FROM:<' . MAIL_FROM . '>', [250]) && $cmd('RCPT TO:<' . $to . '>', [250, 251]) && $cmd('DATA', [354]);
    if ($ok) {
        $headers = 'From: ' . mb_encode_mimeheader(MAIL_FROM_NAME) . ' <' . MAIL_FROM . ">\r\n"
                 . "To: <$to>\r\nSubject: " . mb_encode_mimeheader($subject) . "\r\n"
                 . 'Date: ' . date('r') . "\r\nMessage-ID: <" . bin2hex(random_bytes(8)) . '@' . $domain . ">\r\n"
                 . "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
        $ok = $cmd($headers . "\r\n" . chunk_split(base64_encode($html)) . "\r\n.", [250]);
    }
    fwrite($fp, "QUIT\r\n");
    fclose($fp);
    return $ok;
}

function mailLayout(string $title, string $bodyHtml, string $btnText = '', string $btnUrl = ''): string {
    $btn = $btnUrl ? '<p style="margin:28px 0"><a href="' . e($btnUrl) . '" style="background:#25d366;color:#000;padding:12px 24px;border-radius:8px;text-decoration:none;font-weight:bold">' . e($btnText) . '</a></p>'
                   . '<p style="font-size:12px;color:#777">Or open this link: ' . e($btnUrl) . '</p>' : '';
    return '<div style="font-family:Arial,sans-serif;max-width:520px;margin:auto;padding:24px;color:#222">'
         . '<h2 style="color:#128c7e">' . e(APP_NAME) . '</h2><h3>' . e($title) . '</h3>' . $bodyHtml . $btn
         . '<p style="font-size:12px;color:#999;margin-top:32px">Need help? ' . e(SUPPORT_EMAIL) . '</p></div>';
}

// True while the customer-service window is open (customer wrote in the last 24h),
// i.e. free-form (non-template) replies are allowed by WhatsApp.
function conversationWindowOpen(int $conversationId): bool {
    return conversationWindowSeconds($conversationId) > 0;
}

// Seconds left in the 24h customer-service window (0 = closed).
function conversationWindowSeconds(int $conversationId): int {
    $r = db()->row('SELECT TIMESTAMPDIFF(SECOND, NOW(), last_inbound_at + INTERVAL 24 HOUR) AS s FROM conversations WHERE id=? AND last_inbound_at IS NOT NULL', [$conversationId]);
    return max(0, (int)($r['s'] ?? 0));
}

function buildTemplateComponents(array $template, string $contactName = '', string $phone = ''): array {
    $components = [];
    $body = $template['body'] ?? '';

    // Body parameters — fill {{1}}, {{2}}... using contact name/phone as sensible defaults
    preg_match_all('/\{\{(\d+)\}\}/', $body, $nm);
    $numVars = array_values(array_unique($nm[1] ?? []));
    sort($numVars, SORT_NUMERIC);
    if (!empty($numVars)) {
        $bParams = [];
        foreach ($numVars as $vn) {
            $i = (int)$vn;
            $val = match($i) { 1 => $contactName ?: 'Customer', 2 => $phone, default => $contactName ?: 'Customer' };
            $bParams[] = ['type' => 'text', 'text' => (string)$val];
        }
        $components[] = ['type' => 'body', 'parameters' => $bParams];
    }

    // Header parameters
    if (!empty($template['header_content']) && $template['header_type'] !== 'none') {
        $htype = $template['header_type'];
        $hcont = $template['header_content'];
        if ($htype === 'text') {
            preg_match_all('/\{\{(\d+)\}\}/', $hcont, $hm);
            if (!empty($hm[1])) {
                $components[] = ['type' => 'header', 'parameters' => [['type' => 'text', 'text' => $contactName ?: 'Customer']]];
            }
        } elseif (in_array($htype, ['image', 'video', 'document'])) {
            if (is_numeric($hcont) || strlen($hcont) > 30) {
                $key = is_numeric($hcont) ? 'id' : 'link';
                $components[] = ['type' => 'header', 'parameters' => [['type' => $htype, $htype => [$key => $hcont]]]];
            }
        }
    }

    return $components;
}

require_once __DIR__ . '/subscription.php';
