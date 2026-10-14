<?php
// ============================================================
// WhatsFlow Pro - Public REST API core
// Third-party software (birthday / anniversary / reminder systems ...) sends WhatsApp messages
// through the customer's own WABA with an API key; every message is logged in api_messages and its
// delivery status is tracked, shown in the UI and optionally pushed back via signed callbacks.
// ============================================================

if (!defined('API_RATE_PER_MIN'))            define('API_RATE_PER_MIN', 600);
if (!defined('API_BATCH_MAX'))               define('API_BATCH_MAX', 50);
if (!defined('API_CALLBACK_MAX_ATTEMPTS'))   define('API_CALLBACK_MAX_ATTEMPTS', 6);
if (!defined('API_ALLOW_PRIVATE_CALLBACKS')) define('API_ALLOW_PRIVATE_CALLBACKS', false);   // true only for local testing

// ---------- keys ----------

function apiGenerateKey(int $uid, string $name): array {
    $plain = 'wf_live_' . bin2hex(random_bytes(20));
    $id = db()->insert('INSERT INTO api_keys (user_id,name,key_prefix,key_hash) VALUES (?,?,?,?)',
        [$uid, mb_substr($name, 0, 100) ?: 'API key', substr($plain, 0, 14), hash('sha256', $plain)]);
    return [(int)$id, $plain];
}

function apiPlanAllows(int $uid): bool {
    $u = db()->row('SELECT role FROM users WHERE id=?', [$uid]);
    if (($u['role'] ?? '') === 'superadmin') return true;
    return planAllows($uid, 'api_access');
}

function apiBearer(): string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if ($h === '' && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) if (strtolower($k) === 'authorization') $h = $v;
    }
    return preg_match('/^Bearer\s+(\S+)$/i', trim($h), $m) ? $m[1] : '';
}

function apiFail(int $http, string $code, string $message, array $extra = []): never {
    http_response_code($http);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'error' => array_merge(['code' => $code, 'message' => $message], $extra)], JSON_UNESCAPED_UNICODE);
    exit;
}

// Returns [userRow, keyRow]; ends the request with 401/403 when not allowed.
function apiAuthenticate(): array {
    $plain = apiBearer();
    if ($plain === '') apiFail(401, 'unauthorized', 'Send your API key as "Authorization: Bearer <key>".');
    $key = db()->row('SELECT * FROM api_keys WHERE key_hash=? AND is_active=1', [hash('sha256', $plain)]);
    if (!$key) apiFail(401, 'invalid_api_key', 'API key is invalid or has been revoked.');
    $user = db()->row('SELECT * FROM users WHERE id=? AND is_active=1', [$key['user_id']]);
    if (!$user) apiFail(403, 'account_disabled', 'This account is disabled.');
    if (!subscriptionActive((int)$user['id'])) apiFail(403, 'plan_inactive', 'Your plan is not active (expired or not yet activated). Renew it from Billing in the dashboard.');
    if (!apiPlanAllows((int)$user['id'])) apiFail(403, 'plan_no_api', 'API access is not included in your current plan.');
    db()->run('UPDATE api_keys SET last_used_at=NOW(), last_used_ip=? WHERE id=?', [substr($_SERVER['REMOTE_ADDR'] ?? '', 0, 45), $key['id']]);
    return [$user, $key];
}

// ---------- Meta error codes -> something a human (and a program) can act on ----------

function apiErrorInfo(?int $code, string $message = ''): array {
    // category, advice, retryable
    $map = [
        131026 => ['not_on_whatsapp',  'The number is not on WhatsApp, has blocked business messages, or has not accepted WhatsApp terms. Do not retry; verify the number.', false],
        131049 => ['ecosystem_limit',  'WhatsApp withheld this marketing message to keep the user experience healthy (the person rarely engages with business messages). Retry no sooner than 24h later, or use a Utility template for transactional content.', true],
        131050 => ['opted_out',        'The person unsubscribed from marketing messages. Do not message them with marketing templates.', false],
        131047 => ['window_closed',    'More than 24h since the customer last messaged you: free-text is not allowed. Send an approved template instead.', false],
        131048 => ['spam_limit',       'Your number hit a spam-rate limit. Reduce marketing volume and review message quality.', true],
        131056 => ['pair_rate_limit',  'Too many messages to this same recipient in a short time. Wait and retry later.', true],
        130429 => ['rate_limit',       'Throughput limit reached on your WhatsApp number. Slow down and retry.', true],
        80007  => ['rate_limit',       'WhatsApp API rate limit reached. Slow down and retry.', true],
        131030 => ['not_allowed_number','The number is not in the allowed list of a test WhatsApp number. Add it as a recipient in Meta or use a live number.', false],
        131021 => ['same_number',      'Recipient is your own business number.', false],
        131042 => ['payment_issue',    'Your WhatsApp Business Account has a payment problem at Meta. Fix billing in Meta Business Manager.', false],
        131031 => ['account_locked',   'Your WhatsApp Business Account is locked by Meta. Check Business Manager.', false],
        131008 => ['bad_request',      'A required parameter is missing.', false],
        131009 => ['bad_param',        'A parameter has an invalid value.', false],
        132000 => ['params_mismatch',  'Number of template variables does not match the template.', false],
        132001 => ['template_missing', 'Template (or this language of it) does not exist at WhatsApp.', false],
        132005 => ['template_too_long','Translated template text is too long.', false],
        132007 => ['template_policy',  'Template content violates WhatsApp policy.', false],
        132012 => ['param_format',     'A template variable has a bad format (e.g. newlines, tabs or too many spaces).', false],
        132015 => ['template_paused',  'This template is paused by WhatsApp due to low quality. Edit or replace it.', false],
        132016 => ['template_disabled','This template is disabled by WhatsApp.', false],
        133010 => ['number_unregistered','Your WhatsApp number is not registered with the Cloud API.', false],
        190    => ['token_invalid',    'Your WhatsApp access token is invalid or expired. Reconnect WhatsApp in Settings.', false],
        131000 => ['whatsapp_error',   'Something went wrong at WhatsApp. Retry.', true],
        131016 => ['whatsapp_busy',    'WhatsApp service is temporarily overloaded. Retry.', true],
        131057 => ['whatsapp_busy',    'WhatsApp account is in maintenance. Retry later.', true],
    ];
    if ($code !== null && isset($map[$code])) return ['category' => $map[$code][0], 'advice' => $map[$code][1], 'retryable' => $map[$code][2]];
    return ['category' => 'other', 'advice' => $message !== '' ? $message : 'Delivery failed.', 'retryable' => false];
}

// ---------- phone / params helpers ----------

function apiNormalizePhone(string $raw): ?string {
    $p = preg_replace('/[^0-9]/', '', $raw);
    $p = ltrim($p, '0');
    if (strlen($p) === 10) $p = '91' . $p;
    return (strlen($p) >= 11 && strlen($p) <= 15) ? $p : null;
}

// WhatsApp rejects variables with newlines/tabs or 4+ consecutive spaces.
function apiCleanParam($v): string {
    $v = preg_replace('/[\r\n\t]+/', ' ', (string)$v);
    return trim(preg_replace('/ {4,}/', '   ', $v));
}

// ---------- the message view returned to callers ----------

function apiMessageView(array $r): array {
    $err = null;
    if (in_array($r['status'], ['failed', 'rejected'], true) || $r['error_message']) {
        $info = apiErrorInfo($r['error_code'] !== null ? (int)$r['error_code'] : null, (string)$r['error_message']);
        $err = ['code' => $r['error_code'] !== null ? (int)$r['error_code'] : null, 'category' => $r['error_category'] ?: $info['category'],
                'message' => $r['error_message'], 'advice' => $info['advice'], 'retryable' => $info['retryable']];
    }
    $iso = fn($d) => $d ? gmdate('Y-m-d\TH:i:s\Z', strtotime($d . ' UTC')) : null;
    return [
        'id' => (int)$r['id'], 'client_ref' => $r['client_ref'], 'to' => $r['to_phone'], 'type' => $r['message_type'],
        'template' => $r['template_name'], 'status' => $r['status'], 'whatsapp_message_id' => $r['wamid'],
        'cost' => (float)$r['cost'], 'refunded' => (bool)$r['refunded'], 'error' => $err,
        'timestamps' => ['created' => $iso($r['created_at']), 'sent' => $iso($r['sent_at']), 'delivered' => $iso($r['delivered_at']),
                         'read' => $iso($r['read_at']), 'failed' => $iso($r['failed_at'])],
    ];
}

function apiLoad(int $uid, int $id): ?array { return db()->row('SELECT * FROM api_messages WHERE id=? AND user_id=?', [$id, $uid]); }

// ---------- wallet ----------

function apiRefund(array $row, string $why): void {
    // atomic claim: only one caller can refund a given message
    $st = db()->getPDO()->prepare('UPDATE api_messages SET refunded=1 WHERE id=? AND refunded=0 AND cost>0');
    $st->execute([$row['id']]);
    if ($st->rowCount() !== 1) return;
    $cat = strtoupper((string)$row['category']);
    // Reverse exactly what was booked at send time (rates may have changed since).
    $orig = db()->row("SELECT meta_cost FROM wallet_transactions WHERE user_id=? AND ref_type='api_message' AND ref_id=? AND type='debit' ORDER BY id DESC LIMIT 1", [$row['user_id'], $row['id']]);
    $mc = $orig && $orig['meta_cost'] !== null ? (float)$orig['meta_cost'] : round(metaCost($cat), 4);
    creditWallet((int)$row['user_id'], (float)$row['cost'], "Refund: API message #{$row['id']} $why", 'api_refund', null, null,
        ['meta_cost' => $mc, 'category' => $cat, 'msg_count' => 1]);
}

// ---------- sending ----------

// Creates the log row, validates, bills, sends. Returns [httpStatus, rowArray, errorCode|null].
function apiReject(int $uid, ?int $keyId, array $in, string $category, string $message, int $http, ?int $code = null): array {
    $to = apiNormalizePhone((string)($in['to'] ?? '')) ?? mb_substr(preg_replace('/\s+/', '', (string)($in['to'] ?? '')), 0, 30);
    $id = db()->insert("INSERT INTO api_messages (user_id,api_key_id,client_ref,idempotency_key,to_phone,message_type,template_name,language,request_json,status,error_code,error_category,error_message,failed_at)
                        VALUES (?,?,?,?,?,?,?,?,?,'rejected',?,?,?,NOW())",
        [$uid, $keyId, mb_substr((string)($in['client_ref'] ?? ''), 0, 100) ?: null, ($in['idempotency_key'] ?? null) ?: null, $to !== '' ? $to : '-',
         ($in['_type'] ?? 'template'), $in['template'] ?? null, $in['language'] ?? null, json_encode($in, JSON_UNESCAPED_UNICODE), $code, $category, mb_substr($message, 0, 500)]);
    return [$http, apiLoad($uid, (int)$id), $category];
}

function apiSendTemplate(int $uid, ?int $keyId, array $in): array {
    $in['_type'] = 'template';
    $to = apiNormalizePhone((string)($in['to'] ?? ''));
    if (!$to) return apiReject($uid, $keyId, $in, 'invalid_number', '"to" must be a valid phone number with country code (10-digit numbers are treated as India +91).', 422);
    $name = trim((string)($in['template'] ?? ''));
    if ($name === '') return apiReject($uid, $keyId, $in, 'template_required', '"template" (approved template name) is required.', 422);

    $lang = trim((string)($in['language'] ?? ''));
    $tpl = $lang !== ''
        ? db()->row("SELECT * FROM message_templates WHERE user_id=? AND template_name=? AND language=?", [$uid, $name, $lang])
        : db()->row("SELECT * FROM message_templates WHERE user_id=? AND template_name=? ORDER BY (status='approved') DESC, (language='en') DESC, id LIMIT 1", [$uid, $name]);
    if (!$tpl) return apiReject($uid, $keyId, $in, 'template_not_found', "Template \"$name\"" . ($lang ? " ($lang)" : '') . ' does not exist in your account.', 404);
    if ($tpl['status'] !== 'approved') return apiReject($uid, $keyId, $in, 'template_not_approved', "Template \"$name\" is {$tpl['status']}, only approved templates can be sent.", 422);
    $in['language'] = $tpl['language'] ?: 'en';

    // People who replied STOP must not get marketing templates (utility / authentication are still allowed).
    if (strtoupper((string)$tpl['category']) === 'MARKETING') {
        $alt = (strlen($to) === 12 && str_starts_with($to, '91')) ? substr($to, 2) : $to;
        if (db()->count('SELECT COUNT(*) FROM contacts WHERE user_id=? AND phone IN (?,?) AND (opt_in=0 OR is_blocked=1)', [$uid, $to, $alt]))
            return apiReject($uid, $keyId, $in, 'opted_out', 'This person unsubscribed (replied STOP) or is blocked; marketing templates are not sent to them. Use a Utility template for transactional messages.', 422, 131050);
    }

    // body variables {{1}}..{{n}}
    preg_match_all('/\{\{(\d+)\}\}/', (string)$tpl['body'], $nm);
    $need = $nm[1] ? max(array_map('intval', $nm[1])) : 0;
    $params = array_values((array)($in['params'] ?? []));
    if (count($params) !== $need) return apiReject($uid, $keyId, $in, 'params_mismatch', "Template \"$name\" needs $need body parameter(s) in \"params\", got " . count($params) . '.', 422, 132000);
    $bodyParams = [];
    foreach ($params as $i => $p) {
        $c = apiCleanParam(is_scalar($p) ? $p : '');
        if ($c === '') return apiReject($uid, $keyId, $in, 'param_empty', 'params[' . $i . '] is empty — WhatsApp rejects empty variables.', 422, 132012);
        $bodyParams[] = ['type' => 'text', 'text' => $c];
    }
    $components = [];
    if ($bodyParams) $components[] = ['type' => 'body', 'parameters' => $bodyParams];

    // header: text variable, or media (image/video/document) from the request or the template default
    $ht = $tpl['header_type'] ?? 'none'; $hc = (string)($tpl['header_content'] ?? '');
    if ($ht === 'text' && preg_match('/\{\{\d+\}\}/', $hc)) {
        $hp = apiCleanParam($in['header_param'] ?? '');
        if ($hp === '') return apiReject($uid, $keyId, $in, 'param_empty', 'This template has a header variable: send "header_param".', 422, 132000);
        $components[] = ['type' => 'header', 'parameters' => [['type' => 'text', 'text' => $hp]]];
    } elseif (in_array($ht, ['image', 'video', 'document'], true)) {
        $media = trim((string)($in['header_media'] ?? '')) ?: $hc;
        if ($media === '') return apiReject($uid, $keyId, $in, 'header_media_required', 'This template has a media header: send "header_media" (https link).', 422, 132000);
        $key = (preg_match('#^https?://#i', $media)) ? 'link' : 'id';
        $components[] = ['type' => 'header', 'parameters' => [['type' => $ht, $ht => [$key => $media]]]];
    }
    // URL buttons with a variable
    $btns = json_decode((string)($tpl['buttons'] ?? '[]'), true) ?: [];
    $bp = array_values((array)($in['button_params'] ?? []));
    foreach ($btns as $i => $b) {
        if (strtoupper($b['type'] ?? '') === 'URL' && str_contains((string)($b['url'] ?? ''), '{{')) {
            $v = apiCleanParam($bp[0] ?? '');
            if ($v === '') return apiReject($uid, $keyId, $in, 'param_empty', 'Template has a dynamic URL button: send "button_params": ["suffix"].', 422, 132000);
            $components[] = ['type' => 'button', 'sub_type' => 'url', 'index' => (string)$i, 'parameters' => [['type' => 'text', 'text' => $v]]];
            array_shift($bp);
        }
    }

    $category = strtoupper($tpl['category'] ?: 'UTILITY');
    $price = msgPrice($uid, $category);
    $rowId = db()->insert("INSERT INTO api_messages (user_id,api_key_id,client_ref,idempotency_key,to_phone,message_type,template_name,language,category,request_json,status,cost)
                           VALUES (?,?,?,?,?,'template',?,?,?,?,'queued',?)",
        [$uid, $keyId, mb_substr((string)($in['client_ref'] ?? ''), 0, 100) ?: null, ($in['idempotency_key'] ?? null) ?: null, $to, $name, $in['language'], $category,
         json_encode($in, JSON_UNESCAPED_UNICODE), $price]);
    $rowId = (int)$rowId;

    $fail = function (string $status, int $http, string $cat, string $msg, ?int $code = null) use ($uid, $rowId): array {
        db()->run("UPDATE api_messages SET status=?, error_category=?, error_message=?, error_code=?, failed_at=NOW() WHERE id=?", [$status, $cat, mb_substr($msg, 0, 500), $code, $rowId]);
        apiRefund(apiLoad($uid, $rowId), 'not sent');
        if ($status === 'rejected') db()->run('UPDATE api_messages SET cost=0 WHERE id=? AND refunded=0 AND cost>0', [$rowId]);
        return [$http, apiLoad($uid, $rowId), $cat];
    };

    // bill first (atomic, can never overdraw); refunded below if WhatsApp does not accept it
    if ($price > 0 && !debitWallet($uid, 1, $category, 0, 'api_message', "API message #$rowId ($name → $to)", $rowId)) {
        db()->run("UPDATE api_messages SET cost=0, refunded=1, status='rejected', error_category='insufficient_balance', error_message=?, failed_at=NOW() WHERE id=?",
            ['Wallet balance is too low for a ' . strtolower($category) . ' message (' . inr($price) . '). Recharge the wallet.', $rowId]);
        return [402, apiLoad($uid, $rowId), 'insufficient_balance'];
    }

    $wa = WhatsAppService::forUser($uid);
    if (!$wa) return $fail('rejected', 409, 'whatsapp_not_connected', 'WhatsApp is not connected for this account.');
    $res = $wa->sendTemplate($to, $name, $in['language'], $components);

    if (!empty($res['success'])) {
        db()->run("UPDATE api_messages SET status='sent', wamid=?, sent_at=NOW() WHERE id=? AND status='queued'", [$res['message_id'] ?? null, $rowId]);
        db()->run("UPDATE api_messages SET wamid=? WHERE id=? AND wamid IS NULL", [$res['message_id'] ?? null, $rowId]);
        if (!empty($res['message_id'])) apiApplyBuffered($res['message_id']);
        apiMirrorToInbox($uid, $to, $name, $res['message_id'] ?? null);
        return [200, apiLoad($uid, $rowId), null];
    }
    $code = isset($res['error_code']) ? (int)$res['error_code'] : null;
    $info = apiErrorInfo($code, (string)($res['error'] ?? ''));
    $out = $fail('failed', 502, $info['category'], (string)($res['error'] ?? 'WhatsApp rejected the message'), $code);
    return $out;
}

function apiSendText(int $uid, ?int $keyId, array $in): array {
    $in['_type'] = 'text';
    $to = apiNormalizePhone((string)($in['to'] ?? ''));
    if (!$to) return apiReject($uid, $keyId, $in, 'invalid_number', '"to" must be a valid phone number with country code.', 422);
    $text = trim((string)($in['text'] ?? ''));
    if ($text === '' || mb_strlen($text) > 4096) return apiReject($uid, $keyId, $in, 'invalid_text', '"text" is required (max 4096 characters).', 422);
    $alt = (strlen($to) === 12 && str_starts_with($to, '91')) ? substr($to, 2) : $to;
    $cv = db()->row('SELECT cv.id FROM conversations cv JOIN contacts c ON c.id=cv.contact_id WHERE cv.user_id=? AND c.phone IN (?,?) ORDER BY cv.id DESC LIMIT 1', [$uid, $to, $alt]);
    if (!$cv || !conversationWindowOpen((int)$cv['id'])) {
        return apiReject($uid, $keyId, $in, 'window_closed', 'Free-text is only allowed within 24h of the customer\'s last message. Send an approved template instead.', 422, 131047);
    }
    $price = msgPrice($uid, 'SERVICE');
    $rowId = (int)db()->insert("INSERT INTO api_messages (user_id,api_key_id,client_ref,idempotency_key,to_phone,message_type,category,request_json,status,cost) VALUES (?,?,?,?,?,'text','SERVICE',?,'queued',?)",
        [$uid, $keyId, mb_substr((string)($in['client_ref'] ?? ''), 0, 100) ?: null, ($in['idempotency_key'] ?? null) ?: null, $to, json_encode($in, JSON_UNESCAPED_UNICODE), $price]);
    if ($price > 0 && !debitWallet($uid, 1, 'SERVICE', 0, 'api_message', "API text #$rowId → $to", $rowId)) {
        db()->run("UPDATE api_messages SET cost=0, refunded=1, status='rejected', error_category='insufficient_balance', error_message='Wallet balance too low.', failed_at=NOW() WHERE id=?", [$rowId]);
        return [402, apiLoad($uid, $rowId), 'insufficient_balance'];
    }
    $wa  = WhatsAppService::forUser($uid);
    $res = $wa ? $wa->sendText($to, $text) : ['success' => false, 'error' => 'WhatsApp is not connected'];
    if (!empty($res['success'])) {
        db()->run("UPDATE api_messages SET status='sent', wamid=?, sent_at=NOW() WHERE id=? AND status='queued'", [$res['message_id'] ?? null, $rowId]);
        db()->run("UPDATE api_messages SET wamid=? WHERE id=? AND wamid IS NULL", [$res['message_id'] ?? null, $rowId]);
        if (!empty($res['message_id'])) apiApplyBuffered($res['message_id']);
        db()->insert('INSERT INTO messages (conversation_id,user_id,direction,message_type,content,whatsapp_message_id,status,created_at) VALUES (?,?,?,?,?,?,?,NOW())', [$cv['id'], $uid, 'outbound', 'text', $text, $res['message_id'] ?? null, 'sent']);
        return [200, apiLoad($uid, $rowId), null];
    }
    $code = isset($res['error_code']) ? (int)$res['error_code'] : null;
    $info = apiErrorInfo($code, (string)($res['error'] ?? ''));
    db()->run("UPDATE api_messages SET status='failed', error_category=?, error_message=?, error_code=?, failed_at=NOW() WHERE id=?", [$info['category'], mb_substr((string)($res['error'] ?? 'failed'), 0, 500), $code, $rowId]);
    apiRefund(apiLoad($uid, $rowId), 'not sent');
    return [502, apiLoad($uid, $rowId), $info['category']];
}

// If the customer already has a chat in the inbox, show what was sent there too (agents see context when they reply).
function apiMirrorToInbox(int $uid, string $to, string $template, ?string $wamid): void {
    $alt = (strlen($to) === 12 && str_starts_with($to, '91')) ? substr($to, 2) : $to;
    $cv = db()->row('SELECT cv.id FROM conversations cv JOIN contacts c ON c.id=cv.contact_id WHERE cv.user_id=? AND c.phone IN (?,?) ORDER BY cv.id DESC LIMIT 1', [$uid, $to, $alt]);
    if (!$cv) return;
    db()->insert('INSERT INTO messages (conversation_id,user_id,direction,message_type,content,whatsapp_message_id,status,created_at) VALUES (?,?,?,?,?,?,?,NOW())',
        [$cv['id'], $uid, 'outbound', 'template', '[Template via API: ' . $template . ']', $wamid, 'sent']);
}

function apiRateLimited(int $keyId): bool {
    return db()->count('SELECT COUNT(*) FROM api_messages WHERE api_key_id=? AND created_at >= (NOW() - INTERVAL 60 SECOND)', [$keyId]) >= API_RATE_PER_MIN;
}

// ---------- status tracking (called from webhook/whatsapp.php) ----------

function apiHandleStatus(string $wamid, string $st, string $ts, array $errors = []): void {
    $row = db()->row('SELECT * FROM api_messages WHERE wamid=? LIMIT 1', [$wamid]);
    if (!$row) {
        // Only park it if an API send is in flight right now (its message id is not saved yet).
        if (db()->count("SELECT COUNT(*) FROM api_messages WHERE status='queued' AND wamid IS NULL AND created_at >= (NOW() - INTERVAL 2 MINUTE)")) {
            db()->run('INSERT INTO api_status_buffer (wamid,status,ts,errors_json) VALUES (?,?,?,?)', [$wamid, $st, $ts, json_encode($errors)]);
        }
        return;
    }
    $changed = false;
    if ($st === 'sent') {
        if ($row['status'] === 'queued') { db()->run("UPDATE api_messages SET status='sent', sent_at=COALESCE(sent_at,?) WHERE id=?", [$ts, $row['id']]); }
    } elseif ($st === 'delivered') {
        $changed = db()->run("UPDATE api_messages SET status='delivered', delivered_at=? WHERE id=? AND status IN ('queued','sent')", [$ts, $row['id']])->rowCount() > 0;
    } elseif ($st === 'read') {
        $changed = db()->run("UPDATE api_messages SET status='read', read_at=?, delivered_at=COALESCE(delivered_at,?) WHERE id=? AND status IN ('queued','sent','delivered')", [$ts, $ts, $row['id']])->rowCount() > 0;
    } elseif ($st === 'failed') {
        $e = $errors[0] ?? [];
        $code = isset($e['code']) ? (int)$e['code'] : null;
        $msg  = trim(($e['title'] ?? 'Delivery failed') . (isset($e['message']) ? ': ' . $e['message'] : '') . (isset($e['error_data']['details']) ? ' — ' . $e['error_data']['details'] : ''));
        $info = apiErrorInfo($code, $msg);
        $changed = db()->run("UPDATE api_messages SET status='failed', failed_at=?, error_code=?, error_category=?, error_message=? WHERE id=? AND status IN ('queued','sent','delivered')",
            [$ts, $code, $info['category'], mb_substr($msg, 0, 500), $row['id']])->rowCount() > 0;
        if ($changed) apiRefund(apiLoad((int)$row['user_id'], (int)$row['id']), 'delivery failed');
    }
    if ($changed) apiQueueCallback((int)$row['user_id'], (int)$row['id']);
}

// Replay statuses that arrived before we knew the message id (oldest first), then tidy up.
function apiApplyBuffered(string $wamid): void {
    $order = ['sent' => 1, 'delivered' => 2, 'read' => 3, 'failed' => 4];
    $rows = db()->all('SELECT * FROM api_status_buffer WHERE wamid=? ORDER BY id', [$wamid]);
    usort($rows, fn($a, $b) => ($order[$a['status']] ?? 0) <=> ($order[$b['status']] ?? 0));
    foreach ($rows as $r) { apiHandleStatus($wamid, $r['status'], $r['ts'], json_decode($r['errors_json'] ?? '[]', true) ?: []); }
    if ($rows) db()->run('DELETE FROM api_status_buffer WHERE wamid=?', [$wamid]);
    db()->run('DELETE FROM api_status_buffer WHERE created_at < (NOW() - INTERVAL 1 DAY)');
}

// ---------- callbacks (we tell the third-party app what happened) ----------

function apiCallbackUrlAllowed(string $url): ?string {
    $p = parse_url($url);
    if (!$p || empty($p['host']) || !in_array(strtolower($p['scheme'] ?? ''), ['https', 'http'], true)) return 'Callback URL must start with https://';
    if (API_ALLOW_PRIVATE_CALLBACKS) return null;
    if (strtolower($p['scheme']) !== 'https') return 'Callback URL must use https://';
    $ips = filter_var($p['host'], FILTER_VALIDATE_IP) ? [$p['host']] : (gethostbynamel($p['host']) ?: []);
    if (!$ips) return 'Callback host does not resolve.';
    foreach ($ips as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return 'Callback URL must point to a public server.';
    return null;
}

function apiQueueCallback(int $uid, int $id): void {
    $s = db()->row('SELECT callback_url FROM api_settings WHERE user_id=?', [$uid]);
    if (!$s || !$s['callback_url']) return;
    db()->run("UPDATE api_messages SET callback_state='pending', callback_attempts=0, next_callback_at=NOW() WHERE id=?", [$id]);
}

function apiPostCallback(int $uid, array $payload): array {
    $s = db()->row('SELECT * FROM api_settings WHERE user_id=?', [$uid]);
    if (!$s || !$s['callback_url']) return [false, 'No callback URL configured'];
    if ($why = apiCallbackUrlAllowed($s['callback_url'])) return [false, $why];
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $ts   = (string)time();
    $sig  = hash_hmac('sha256', $ts . '.' . $body, (string)$s['callback_secret']);
    $ch = curl_init($s['callback_url']);
    curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 8, CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-WhatsFlow-Timestamp: ' . $ts, 'X-WhatsFlow-Signature: sha256=' . $sig, 'User-Agent: WhatsFlow-Callback/1.0']]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch);
    curl_close($ch);
    return ($code >= 200 && $code < 300) ? [true, "HTTP $code"] : [false, $err ?: "HTTP $code"];
}

function apiRunCallbacks(int $limit = 50): int {
    $n = 0;
    foreach (db()->all("SELECT * FROM api_messages WHERE callback_state='pending' AND next_callback_at <= NOW() ORDER BY id LIMIT " . (int)$limit) as $r) {
        [$ok, $info] = apiPostCallback((int)$r['user_id'], ['event' => 'message.status', 'message' => apiMessageView($r)]);
        $att = (int)$r['callback_attempts'] + 1;
        if ($ok) db()->run("UPDATE api_messages SET callback_state='done', callback_attempts=?, last_callback_error=NULL WHERE id=?", [$att, $r['id']]);
        elseif ($att >= API_CALLBACK_MAX_ATTEMPTS) db()->run("UPDATE api_messages SET callback_state='failed', callback_attempts=?, last_callback_error=? WHERE id=?", [$att, mb_substr($info, 0, 255), $r['id']]);
        else db()->run("UPDATE api_messages SET callback_attempts=?, last_callback_error=?, next_callback_at=NOW() + INTERVAL ? MINUTE WHERE id=?", [$att, mb_substr($info, 0, 255), 2 ** $att, $r['id']]);
        $n++;
    }
    return $n;
}
