<?php
// ============================================================
// WhatsFlow Pro - Public REST API v1  (front controller)
//   POST /api/v1/messages/template   send an approved template
//   POST /api/v1/messages/text       free text (inside the 24h window)
//   POST /api/v1/messages/batch      up to 50 messages in one call
//   GET  /api/v1/messages            list / filter your API messages
//   GET  /api/v1/messages/{id}       one message + delivery status
//   GET  /api/v1/messages/ref/{ref}  look up by your own client_ref
//   GET  /api/v1/templates           approved templates + variable counts
//   GET  /api/v1/wallet              balance and per-message prices
// Auth: Authorization: Bearer <api key>.  Full docs: API_DOCUMENTATION.md
// ============================================================
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/WhatsAppService.php';
require_once __DIR__ . '/../../includes/wallet.php';
require_once __DIR__ . '/../../includes/api_core.php';

header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$pos    = strpos($uri, '/api/v1');
$path   = trim($pos === false ? '' : substr($uri, $pos + 7), '/');
if (str_starts_with($path, 'index.php')) $path = trim(substr($path, 9), '/');
$seg    = $path === '' ? [] : explode('/', $path);
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'OPTIONS') { http_response_code(204); exit; }
if (!$seg) { echo json_encode(['name' => 'WP API Pro API', 'version' => 'v1']); exit; }

[$user, $key] = apiAuthenticate();
$uid = (int)$user['id']; $keyId = (int)$key['id'];

function bodyJson(): array {
    $raw = file_get_contents('php://input');
    $d = json_decode($raw, true);
    if (!is_array($d)) apiFail(400, 'invalid_json', 'Request body must be a JSON object. Send header "Content-Type: application/json".');
    return $d;
}

// Sends one message honouring Idempotency-Key; returns [http, view]
function sendOne(int $uid, int $keyId, string $type, array $in): array {
    $idem = trim((string)($in['idempotency_key'] ?? ($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? '')));
    if ($idem !== '') {
        if (strlen($idem) > 100) return [422, ['success' => false, 'error' => ['code' => 'invalid_idempotency_key', 'message' => 'Idempotency key max 100 chars']]];
        $in['idempotency_key'] = $idem;
        $ex = db()->row('SELECT * FROM api_messages WHERE user_id=? AND idempotency_key=?', [$uid, $idem]);
        if ($ex) return [200, ['success' => true, 'idempotent_replay' => true, 'message' => apiMessageView($ex)]];
    }
    if (apiRateLimited($keyId)) return [429, ['success' => false, 'error' => ['code' => 'rate_limited', 'message' => 'Too many requests. Limit: ' . API_RATE_PER_MIN . ' messages/minute per key.']]];
    try {
        [$http, $row, $cat] = $type === 'text' ? apiSendText($uid, $keyId, $in) : apiSendTemplate($uid, $keyId, $in);
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062 && $idem !== '') {   // two identical requests raced
            $ex = db()->row('SELECT * FROM api_messages WHERE user_id=? AND idempotency_key=?', [$uid, $idem]);
            if ($ex) return [200, ['success' => true, 'idempotent_replay' => true, 'message' => apiMessageView($ex)]];
        }
        error_log('API send: ' . $e->getMessage());
        return [500, ['success' => false, 'error' => ['code' => 'server_error', 'message' => 'Internal error']]];
    }
    $view = apiMessageView($row);
    if ($http === 200) return [200, ['success' => true, 'message' => $view]];
    return [$http, ['success' => false, 'error' => ['code' => $cat, 'message' => $row['error_message'], 'advice' => $view['error']['advice'] ?? null], 'message' => $view]];
}

$a = $seg[0]; $b = $seg[1] ?? null;

// ---- POST /messages/template | text | batch
if ($a === 'messages' && $method === 'POST' && in_array($b, ['template', 'text'], true)) {
    [$http, $out] = sendOne($uid, $keyId, $b, bodyJson());
    http_response_code($http); echo json_encode($out, JSON_UNESCAPED_UNICODE); exit;
}
if ($a === 'messages' && $method === 'POST' && $b === 'batch') {
    $d = bodyJson(); $items = $d['messages'] ?? null;
    if (!is_array($items) || !$items) apiFail(422, 'invalid_batch', '"messages" must be a non-empty array.');
    if (count($items) > API_BATCH_MAX) apiFail(422, 'batch_too_large', 'Max ' . API_BATCH_MAX . ' messages per batch.');
    $results = []; $ok = 0;
    foreach ($items as $i => $it) {
        $type = ($it['type'] ?? 'template') === 'text' ? 'text' : 'template';
        [$http, $out] = sendOne($uid, $keyId, $type, is_array($it) ? $it : []);
        if ($http === 200) $ok++;
        $results[] = ['index' => $i, 'http_status' => $http] + $out;
        if ($http === 429 || $http === 402) { foreach (array_slice($items, $i + 1, null, true) as $j => $_) $results[] = ['index' => $j, 'http_status' => 0, 'success' => false, 'error' => ['code' => 'not_attempted', 'message' => 'Skipped after ' . ($http === 402 ? 'insufficient balance' : 'rate limit')]]; break; }
    }
    echo json_encode(['success' => true, 'accepted' => $ok, 'total' => count($items), 'results' => $results], JSON_UNESCAPED_UNICODE); exit;
}

// ---- GET /messages , /messages/{id} , /messages/ref/{ref}
if ($a === 'messages' && $method === 'GET') {
    if ($b === 'ref' && isset($seg[2])) {
        $rows = db()->all('SELECT * FROM api_messages WHERE user_id=? AND client_ref=? ORDER BY id DESC LIMIT 50', [$uid, urldecode($seg[2])]);
        echo json_encode(['success' => true, 'data' => array_map('apiMessageView', $rows)], JSON_UNESCAPED_UNICODE); exit;
    }
    if ($b !== null && ctype_digit($b)) {
        $r = apiLoad($uid, (int)$b);
        if (!$r) apiFail(404, 'not_found', 'Message not found.');
        echo json_encode(['success' => true, 'message' => apiMessageView($r)], JSON_UNESCAPED_UNICODE); exit;
    }
    $w = ['user_id=?']; $p = [$uid];
    if (!empty($_GET['status']) && in_array($_GET['status'], ['queued', 'sent', 'delivered', 'read', 'failed', 'rejected'], true)) { $w[] = 'status=?'; $p[] = $_GET['status']; }
    if (!empty($_GET['to']))         { $w[] = 'to_phone=?';   $p[] = apiNormalizePhone($_GET['to']) ?? $_GET['to']; }
    if (!empty($_GET['client_ref'])) { $w[] = 'client_ref=?'; $p[] = $_GET['client_ref']; }
    if (!empty($_GET['template']))   { $w[] = 'template_name=?'; $p[] = $_GET['template']; }
    if (!empty($_GET['from']) && strtotime($_GET['from'])) { $w[] = 'created_at >= ?'; $p[] = gmdate('Y-m-d H:i:s', strtotime($_GET['from'])); }
    if (!empty($_GET['until']) && strtotime($_GET['until'])) { $w[] = 'created_at <= ?'; $p[] = gmdate('Y-m-d H:i:s', strtotime($_GET['until'])); }
    if (!empty($_GET['cursor']) && ctype_digit($_GET['cursor'])) { $w[] = 'id < ?'; $p[] = (int)$_GET['cursor']; }
    $limit = max(1, min(200, (int)($_GET['limit'] ?? 50)));
    $rows = db()->all('SELECT * FROM api_messages WHERE ' . implode(' AND ', $w) . ' ORDER BY id DESC LIMIT ' . ($limit + 1), $p);
    $more = count($rows) > $limit; if ($more) array_pop($rows);
    echo json_encode(['success' => true, 'data' => array_map('apiMessageView', $rows), 'next_cursor' => $more ? (string)end($rows)['id'] : null], JSON_UNESCAPED_UNICODE); exit;
}

// ---- GET /templates
if ($a === 'templates' && $method === 'GET') {
    $out = [];
    foreach (db()->all("SELECT template_name,language,category,body,header_type,buttons FROM message_templates WHERE user_id=? AND status='approved' ORDER BY template_name", [$uid]) as $t) {
        preg_match_all('/\{\{(\d+)\}\}/', (string)$t['body'], $m);
        $out[] = ['name' => $t['template_name'], 'language' => $t['language'], 'category' => $t['category'], 'body' => $t['body'],
                  'body_params' => $m[1] ? max(array_map('intval', $m[1])) : 0, 'header_type' => $t['header_type'], 'price' => msgPrice($uid, (string)$t['category'])];
    }
    echo json_encode(['success' => true, 'data' => $out], JSON_UNESCAPED_UNICODE); exit;
}

// ---- GET /wallet
if ($a === 'wallet' && $method === 'GET') {
    $prices = [];
    foreach (['MARKETING', 'UTILITY', 'AUTHENTICATION', 'SERVICE'] as $c) $prices[strtolower($c)] = msgPrice($uid, $c);
    echo json_encode(['success' => true, 'balance' => walletBalance($uid), 'currency' => 'INR', 'price_per_message' => $prices]); exit;
}

apiFail(404, 'not_found', 'Unknown endpoint or method.');
