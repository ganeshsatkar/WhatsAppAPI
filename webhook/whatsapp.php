<?php
// ============================================================
// WP API — WhatsApp Webhook Handler (Full Media Support)
// ============================================================
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/WhatsAppService.php';
require_once __DIR__ . '/../includes/bot_runtime.php';
require_once __DIR__ . '/../includes/campaign_core.php';
require_once __DIR__ . '/../includes/autoreply.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/api_core.php';

// ── GET: Verification ─────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $mode      = $_GET['hub_mode']         ?? '';
    $token     = $_GET['hub_verify_token'] ?? '';
    $challenge = $_GET['hub_challenge']    ?? '';
    $setting   = db()->row('SELECT id FROM user_whatsapp_settings WHERE verify_token=?', [$token]);
    // One app-level webhook (Embedded Signup) uses a single platform-wide verify token.
    $global    = defined('META_WEBHOOK_VERIFY_TOKEN') && META_WEBHOOK_VERIFY_TOKEN !== '' && hash_equals(META_WEBHOOK_VERIFY_TOKEN, (string)$token);
    if ($mode === 'subscribe' && ($setting || $global)) { echo $challenge; }
    else { http_response_code(403); echo 'Forbidden'; }
    exit;
}

// ── POST: Events ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $raw     = file_get_contents('php://input');
    // When the Meta app secret is configured, reject payloads not signed by Meta.
    if (defined('META_APP_SECRET') && META_APP_SECRET !== '') {
        $sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';
        if (!WhatsAppService::verifyWebhook(META_APP_SECRET, $sig, $raw)) { http_response_code(403); echo 'Bad signature'; exit; }
    } else {
        error_log('WhatsApp webhook: META_APP_SECRET not set — signature NOT verified. Set it in .env.');
    }
    $payload = json_decode($raw, true);
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode(['status'=>'ok']);
    if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

    if (!$payload || !isset($payload['entry'])) exit;

    foreach ($payload['entry'] as $entry) {
        foreach ($entry['changes'] ?? [] as $change) {
            $value  = $change['value'] ?? [];
            if (($change['field'] ?? '') === 'message_template_status_update') {
                try { handleTemplateStatus((string)($entry['id'] ?? ''), $value); } catch (\Throwable $e) { error_log('template status: ' . $e->getMessage()); }
                continue;
            }
            $pid    = $value['metadata']['phone_number_id'] ?? null;
            $setting= db()->row('SELECT * FROM user_whatsapp_settings WHERE phone_number_id=?', [$pid]);
            if (!$setting) continue;
            $uid = $setting['user_id'];

            foreach ($value['messages'] ?? []  as $msg)    handleIncoming($uid, $msg, $value['contacts'][0] ?? []);
            foreach ($value['calls'] ?? []     as $call)   handleCall($uid, $call, $value['contacts'][0] ?? []);
            foreach ($value['statuses'] ?? []  as $status) handleStatus($status);
        }
    }
    // Push pending delivery callbacks right away when the HTTP response is already gone (php-fpm); cron picks up the rest.
    if (function_exists('fastcgi_finish_request')) { try { apiRunCallbacks(20); } catch (\Throwable $e) { error_log('callbacks: ' . $e->getMessage()); } }
    exit;
}

// ── Contact + conversation helper (messages and calls) ───
function ensureConversation(int $uid, string $phone, string $name): array {
    $alt = (strlen($phone) === 12 && str_starts_with($phone, '91')) ? substr($phone, 2) : $phone;
    $ct = db()->row('SELECT * FROM contacts WHERE user_id=? AND (phone=? OR phone=?)', [$uid, $phone, $alt]);
    if (!$ct) {
        $cid = db()->insert('INSERT INTO contacts (user_id,name,phone,opt_in,source,created_at) VALUES (?,?,?,1,?,NOW())', [$uid, $name, $phone, 'webhook']);
        $ct  = db()->row('SELECT * FROM contacts WHERE id=?', [$cid]);
    } elseif ($name !== $phone && ($ct['name'] === $ct['phone'] || empty($ct['name']))) {
        db()->run('UPDATE contacts SET name=? WHERE id=?', [$name, $ct['id']]);
    }
    $cv = db()->row('SELECT * FROM conversations WHERE user_id=? AND contact_id=?', [$uid, $ct['id']]);
    if (!$cv) {
        $cvid = db()->insert('INSERT INTO conversations (user_id,contact_id,phone,status,is_bot_active,last_message_at,created_at) VALUES (?,?,?,?,1,NOW(),NOW())', [$uid, $ct['id'], $phone, 'open']);
        $cv = db()->row('SELECT * FROM conversations WHERE id=?', [$cvid]);
    } elseif ($cv['status'] === 'resolved') {
        db()->run("UPDATE conversations SET status='open' WHERE id=?", [$cv['id']]);
    }
    return [$ct, $cv];
}

// ── Incoming message ─────────────────────────────────────
function handleIncoming(int $uid, array $msg, array $contactInfo): void {
    $phone = $msg['from'];
    $msgId = $msg['id'];
    $type  = $msg['type'] ?? 'text';
    $ts    = date('Y-m-d H:i:s', (int)($msg['timestamp'] ?? time()));
    $name  = $contactInfo['profile']['name'] ?? $phone;

    if (db()->count('SELECT COUNT(*) FROM messages WHERE whatsapp_message_id=?', [$msgId])) return;   // Meta retries

    [$ct, $cv] = ensureConversation($uid, $phone, $name);
    $cvid = (int)$cv['id'];

    // A reaction decorates an existing bubble; it is not a new message and does not open the 24h window.
    if ($type === 'reaction') {
        $target = $msg['reaction']['message_id'] ?? '';
        $emoji  = $msg['reaction']['emoji'] ?? '';
        if ($target !== '') db()->run('UPDATE messages SET reaction=? WHERE whatsapp_message_id=? AND conversation_id=?', [$emoji !== '' ? $emoji : null, $target, $cvid]);
        return;
    }

    [$content, $mediaId, $mediaUrl, $mimeType, $dbType] = extractMedia($msg, $uid);
    $replyTo = $msg['context']['id'] ?? null;

    db()->insert('INSERT INTO messages (conversation_id,user_id,direction,message_type,content,whatsapp_message_id,media_id,media_url,media_mime_type,reply_to_wamid,status,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)',
        [$cvid, $uid, 'inbound', $dbType, $content, $msgId, $mediaId, $mediaUrl, $mimeType, $replyTo, 'delivered', $ts]);

    db()->run('UPDATE conversations SET last_message_at=NOW(),last_inbound_at=NOW(),last_message_preview=?,unread_count=unread_count+1 WHERE id=?', [mb_substr($content, 0, 200), $cvid]);
    db()->run('UPDATE contacts SET last_message_at=NOW() WHERE id=?', [$ct['id']]);

    try { $wa = WhatsAppService::forUser($uid); if ($wa) $wa->markRead($msgId); } catch (\Throwable $e) {}

    // STOP / START keywords and welcome / away messages (compliance first: a STOP must never reach the chatbot or automations)
    try {
        if ($type === 'text' && handleOptKeywords($uid, $ct, $cvid, $content, $phone)) return;
        $firstIn = db()->count("SELECT COUNT(*) FROM messages WHERE conversation_id=? AND direction='inbound'", [$cvid]) <= 1;
        maybeAutoReply($uid, $ct, $cv, $phone, $firstIn);
    } catch (\Throwable $e) { error_log('autoreply: ' . $e->getMessage()); }

    if ($cv['is_bot_active'] ?? 1) processBotFlow($uid, (int)$ct['id'], $cvid, $content, $msg);
    triggerAutomation($uid, 'message_received', (int)$ct['id']);
}

// ── Calls (WhatsApp Calling API webhook: field "calls") ──
// We log them in the chat like WhatsApp does ("Missed voice call", "Call 2:15"). Answering live audio
// needs the WebRTC leg of the Calling API, which is not part of this module.
function handleCall(int $uid, array $call, array $contactInfo): void {
    $callId = $call['id'] ?? '';
    if ($callId === '') return;
    $direction = strtoupper($call['direction'] ?? 'USER_INITIATED');
    $incoming  = $direction !== 'BUSINESS_INITIATED';
    $phone     = preg_replace('/\D/', '', $incoming ? ($call['from'] ?? '') : ($call['to'] ?? ''));
    if ($phone === '') return;
    $event = strtolower($call['event'] ?? '');
    $key   = 'call:' . $callId;

    [$ct, $cv] = ensureConversation($uid, $phone, $contactInfo['profile']['name'] ?? $phone);
    $cvid = (int)$cv['id'];
    $row  = db()->row('SELECT id FROM messages WHERE whatsapp_message_id=?', [$key]);

    if ($event === 'connect') {
        $text = $incoming ? '📞 Incoming WhatsApp call…' : '📞 Calling…';
        if (!$row) {
            db()->insert('INSERT INTO messages (conversation_id,user_id,direction,message_type,content,whatsapp_message_id,status,created_at) VALUES (?,?,?,?,?,?,?,NOW())',
                [$cvid, $uid, $incoming ? 'inbound' : 'outbound', 'call', $text, $key, 'delivered']);
            if ($incoming) db()->run('UPDATE conversations SET last_message_at=NOW(),last_message_preview=?,unread_count=unread_count+1 WHERE id=?', [$text, $cvid]);
        }
        return;
    }
    if ($event !== 'terminate') return;

    $duration  = (int)($call['duration'] ?? 0);
    $completed = strtoupper($call['status'] ?? '') === 'COMPLETED' || $duration > 0;
    $mins      = sprintf('%d:%02d', intdiv($duration, 60), $duration % 60);
    if ($completed) $text = ($incoming ? '📞 Voice call' : '📞 Outgoing call') . " · $mins";
    else            $text = $incoming ? '📵 Missed voice call' : '📵 Call not answered';

    if ($row) db()->run('UPDATE messages SET content=? WHERE id=?', [$text, $row['id']]);
    else      db()->insert('INSERT INTO messages (conversation_id,user_id,direction,message_type,content,whatsapp_message_id,status,created_at) VALUES (?,?,?,?,?,?,?,NOW())',
                  [$cvid, $uid, $incoming ? 'inbound' : 'outbound', 'call', $text, $key, 'delivered']);
    db()->run('UPDATE conversations SET last_message_at=NOW(),last_message_preview=?,unread_count=unread_count+? WHERE id=?', [$text, ($row ? 0 : ($incoming ? 1 : 0)), $cvid]);
    if ($incoming && !$completed) {
        notify($uid, '📵 Missed WhatsApp call', ($ct['name'] ?: $phone) . " ($phone) tried to call you. Message or call them back.", 'warning', '/user/inbox.php');
    }
}

// ── Extract content + media reference ────────────────────
// Returns [content, media_id, media_url, mime, db_message_type]. Media bytes are fetched lazily by media.php,
// because the download URL Meta gives expires within minutes.
function extractMedia(array $msg, int $uid): array {
    $type = $msg['type'] ?? 'text';
    $mediaId = null; $mimeType = null;
    $dbType = match ($type) {
        'text', 'image', 'video', 'document', 'sticker', 'interactive', 'location' => $type,
        'audio', 'voice'              => 'audio',
        'button'                      => 'interactive',
        'contacts'                    => 'contacts',
        default                       => 'unsupported',
    };

    if (in_array($type, ['image', 'video', 'audio', 'document', 'sticker', 'voice'], true)) {
        $obj      = $msg[$type] ?? [];
        $mediaId  = $obj['id'] ?? null;
        $mimeType = $obj['mime_type'] ?? null;
        $caption  = $obj['caption'] ?? '';
        $fname    = $obj['filename'] ?? '';
        $labels   = ['image' => '📷 Image', 'video' => '🎥 Video', 'audio' => '🎵 Audio', 'document' => '📄 Document', 'sticker' => '🎨 Sticker', 'voice' => '🎤 Voice message'];
        $content  = ($labels[$type] ?? strtoupper($type)) . ($fname ? ": $fname" : '') . ($caption ? " — $caption" : '');
    } else {
        $content = match ($type) {
            'text'        => $msg['text']['body'] ?? '',
            'location'    => '📍 ' . trim(($msg['location']['name'] ?? 'Location') . ' ' . ($msg['location']['address'] ?? ''))
                             . "\nhttps://maps.google.com/?q=" . ($msg['location']['latitude'] ?? '') . ',' . ($msg['location']['longitude'] ?? ''),
            'contacts'    => '👤 Shared contact: ' . implode(', ', array_map(fn($c) => ($c['name']['formatted_name'] ?? '?') . (isset($c['phones'][0]['phone']) ? ' (' . $c['phones'][0]['phone'] . ')' : ''), $msg['contacts'] ?? [])),
            'interactive' => $msg['interactive']['button_reply']['title'] ?? $msg['interactive']['list_reply']['title'] ?? '[Interactive reply]',
            'button'      => $msg['button']['text'] ?? '[Button]',
            default       => '[Unsupported message type: ' . $type . ']',
        };
    }
    // Customer came from a Click-to-WhatsApp ad / post
    if (!empty($msg['referral'])) {
        $r = $msg['referral'];
        $content .= "\n📣 via ad: " . ($r['headline'] ?? $r['source_url'] ?? 'Click-to-WhatsApp');
    }
    return [$content, $mediaId, null, $mimeType, $dbType];
}

// ── Delivery status updates ──────────────────────────────
function handleStatus(array $status): void {
    $msgId = $status['id'] ?? '';
    $st    = $status['status'] ?? '';
    if ($msgId === '') return;
    $ts = date('Y-m-d H:i:s', (int)($status['timestamp'] ?? time()));

    // Messages sent through the public API are tracked (and refunded / called back) separately.
    try { apiHandleStatus($msgId, $st, $ts, $status['errors'] ?? []); } catch (\Throwable $e) { error_log('apiHandleStatus: ' . $e->getMessage()); }

    // Statuses can arrive out of order — never move a message backwards (read -> delivered).
    switch ($st) {
        case 'delivered':
            db()->run("UPDATE messages SET status='delivered',delivered_at=? WHERE whatsapp_message_id=? AND status='sent'", [$ts, $msgId]);
            break;
        case 'read':
            db()->run("UPDATE messages SET status='read',read_at=?,delivered_at=COALESCE(delivered_at,?) WHERE whatsapp_message_id=? AND status IN ('sent','delivered')", [$ts, $ts, $msgId]);
            break;
        case 'failed':
            $e   = $status['errors'][0] ?? [];
            $why = trim(($e['title'] ?? '') . (isset($e['message']) ? ': ' . $e['message'] : '') . (isset($e['code']) ? ' (code ' . $e['code'] . ')' : ''));
            db()->run("UPDATE messages SET status='failed',error_message=? WHERE whatsapp_message_id=? AND status<>'read'", [mb_substr($why ?: 'Delivery failed', 0, 500), $msgId]);
            // Campaign recipient: record why, refund the charge (Meta does not bill undelivered messages) and count it as failed.
            foreach (db()->all("SELECT id FROM campaign_logs WHERE message_id=? AND status IN ('pending','sent')", [$msgId]) as $cl) {
                try { campaignLogFailed((int)$cl['id'], $why ?: 'Delivery failed', isset($e['code']) ? (int)$e['code'] : null); }
                catch (\Throwable $ex) { error_log('campaign fail: ' . $ex->getMessage()); }
            }
            return;
        default:
            return;
    }

    // Campaign counters: bump only on a real transition so Meta's retries cannot double count.
    $log = db()->row('SELECT campaign_id FROM campaign_logs WHERE message_id=? LIMIT 1', [$msgId]);
    if ($log) {
        $cur = db()->row('SELECT status FROM campaign_logs WHERE message_id=?', [$msgId])['status'];
        if ($st === 'delivered' && in_array($cur, ['pending', 'sent'], true)) {
            db()->run("UPDATE campaign_logs SET status='delivered' WHERE message_id=?", [$msgId]);
            db()->run('UPDATE campaigns SET delivered_count=delivered_count+1 WHERE id=?', [$log['campaign_id']]);
        } elseif ($st === 'read' && $cur !== 'read' && $cur !== 'replied') {
            db()->run("UPDATE campaign_logs SET status='read' WHERE message_id=?", [$msgId]);
            db()->run('UPDATE campaigns SET read_count=read_count+1 WHERE id=?', [$log['campaign_id']]);
        }
    }
}

// ── Template approval / rejection pushed by Meta ──────────
function handleTemplateStatus(string $wabaId, array $v): void {
    $name = $v['message_template_name'] ?? '';
    $lang = $v['message_template_language'] ?? '';
    $evt  = strtoupper($v['event'] ?? '');
    if ($wabaId === '' || $name === '' || $evt === '') return;
    $status = match ($evt) {
        'APPROVED'            => 'approved',
        'REJECTED'            => 'rejected',
        'PAUSED', 'DISABLED'  => 'paused',
        'PENDING'             => 'pending',
        default               => null,   // FLAGGED, REINSTATED etc: ignore
    };
    if ($status === null) return;
    $reason = ($status === 'approved') ? null : (($v['reason'] ?? '') && strtoupper($v['reason']) !== 'NONE' ? $v['reason'] : ($v['other_info']['description'] ?? null));
    foreach (db()->all('SELECT user_id FROM user_whatsapp_settings WHERE business_account_id=?', [$wabaId]) as $u) {
        $tpls = db()->all('SELECT id,language FROM message_templates WHERE user_id=? AND template_name=?', [$u['user_id'], $name]);
        foreach ($tpls as $t) {
            if ($lang !== '' && !empty($t['language']) && $t['language'] !== $lang) continue;
            db()->run('UPDATE message_templates SET status=?, rejection_reason=?, updated_at=NOW() WHERE id=?', [$status, $reason, $t['id']]);
        }
        if ($tpls) {
            $label = ['approved' => '✅ approved', 'rejected' => '❌ rejected', 'paused' => '⏸ paused', 'pending' => '⏳ pending'][$status];
            notify((int)$u['user_id'], "Template \"$name\" $label", $reason ? "Reason: $reason" : '', $status === 'approved' ? 'success' : 'warning', '/user/templates.php');
        }
    }
}
