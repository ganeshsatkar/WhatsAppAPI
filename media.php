<?php
// ============================================================
// WP API — Media Proxy
// Fetches WhatsApp media (requires auth token) and serves it
// Usage: /media.php?id=MSG_ID
// ============================================================
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

// Auth check — must be logged in
if (!isLoggedIn()) {
    http_response_code(403);
    exit;
}

$msgId = (int)($_GET['id'] ?? 0);
$uid   = (int)$_SESSION['user_id'];

if (!$msgId) {
    http_response_code(400);
    exit;
}

$isSuper = isSuperAdmin();

// Get message: Super Admin can view any message media; regular users only their own
if ($isSuper) {
    $msg = db()->row(
        'SELECT m.media_url, m.media_id, m.media_mime_type, m.message_type, cv.user_id as owner_uid 
         FROM messages m
         JOIN conversations cv ON cv.id = m.conversation_id
         WHERE m.id = ?',
        [$msgId]
    );
} else {
    $msg = db()->row(
        'SELECT m.media_url, m.media_id, m.media_mime_type, m.message_type, cv.user_id as owner_uid 
         FROM messages m
         JOIN conversations cv ON cv.id = m.conversation_id
         WHERE m.id = ? AND cv.user_id = ?',
        [$msgId, $uid]
    );
}

if (!$msg || (!$msg['media_url'] && !$msg['media_id'])) {
    http_response_code(404);
    exit;
}

$mediaOwnerUid = (int)$msg['owner_uid'];
$cacheDir  = APP_ROOT . '/storage/media/';
$cacheKey  = md5($msg['media_id'] ?: $msg['media_url']);
$cacheFile = $cacheDir . $cacheKey;
if (!is_dir($cacheDir)) @mkdir($cacheDir, 0750, true);

function serveMedia(string $bytes, ?string $mime, string $fallback): void {
    $mime = $mime ?: $fallback ?: 'application/octet-stream';
    if (!preg_match('#^(image|video|audio)/|^application/(pdf|msword|vnd\.)|^text/plain#', $mime)) {
        $mime = 'application/octet-stream';
    }
    header('Content-Type: ' . $mime);
    header("Content-Security-Policy: default-src 'self' data: blob:;");
    if (!preg_match('#^(image|video|audio)/#', $mime)) {
        header('Content-Disposition: attachment; filename="file"');
    }
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: private, max-age=604800');
    header('X-Content-Type-Options: nosniff');
    echo $bytes;
    exit;
}

if (file_exists($cacheFile) && filesize($cacheFile) > 0) {
    serveMedia(file_get_contents($cacheFile), $msg['media_mime_type'], @mime_content_type($cacheFile) ?: '');
}

$settings = db()->row('SELECT access_token FROM user_whatsapp_settings WHERE user_id=?', [$mediaOwnerUid]);
if (!$settings || !$settings['access_token']) {
    http_response_code(403);
    exit;
}
$token = (string)decryptSecret($settings['access_token']);
if ($token === '') {
    http_response_code(403);
    exit;
}

// Meta's download URLs expire after a few minutes, so always ask for a fresh one by media id
$url = $msg['media_url'];
if (!$msg['media_id'] && preg_match('/[?&]mid=(\d+)/', (string)($msg['media_url'] ?? ''), $mMatch)) {
    $msg['media_id'] = $mMatch[1];
}
if ($msg['media_id']) {
    require_once __DIR__ . '/includes/WhatsAppService.php';
    $r = (new WhatsAppService('', $token))->getMediaUrl($msg['media_id']);
    if (!empty($r['url'])) {
        $url = $r['url'];
        if (!$msg['media_mime_type'] && !empty($r['mime_type'])) {
            $msg['media_mime_type'] = $r['mime_type'];
        }
    }
}
if (!$url) {
    http_response_code(404);
    exit;
}

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64)'],
    CURLOPT_TIMEOUT        => 30,
]);
$data  = curl_exec($ch);
$code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$ctype = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
curl_close($ch);

if ($code !== 200 || !$data) {
    http_response_code(404);
    exit;
}

file_put_contents($cacheFile, $data);
serveMedia($data, $msg['media_mime_type'], $ctype);
