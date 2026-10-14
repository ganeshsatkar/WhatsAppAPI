<?php
// ============================================================
// WhatsFlow Pro - Chatbot runtime
// Executes the flows made in the visual builder (user/chatbots.php).
// Node types: trigger, message, condition, delay, action, end.
// Used by webhook/whatsapp.php (incoming messages) and cron/process.php (delay resume).
// ============================================================

const BOT_MAX_STEPS      = 25;     // loop guard against cyclic flows
const BOT_SESSION_TTL_H  = 24;     // an abandoned session is dropped after this many hours

function processBotFlow(int $uid, int $cid, int $cvid, string $input, array $rawMsg): void {
    if (!planAllows($uid, 'chatbot_builder')) return;   // plan expired or chatbot not in plan
    $session = db()->row("SELECT * FROM bot_sessions WHERE user_id=? AND contact_id=? AND status='active' ORDER BY id DESC LIMIT 1", [$uid, $cid]);
    if ($session) {
        $age = time() - strtotime($session['updated_at'] ?: $session['started_at']);
        if ($age > BOT_SESSION_TTL_H * 3600) {
            db()->run("UPDATE bot_sessions SET status='expired' WHERE id=?", [$session['id']]);
            $session = null;
        }
    }
    if ($session) { advanceBotSession($uid, $cid, $cvid, $session, $input); return; }

    $inp = strtolower(trim($input));
    foreach (db()->all('SELECT * FROM bot_flows WHERE user_id=? AND is_active=1 ORDER BY id', [$uid]) as $flow) {
        $kw = strtolower(trim($flow['trigger_keyword'] ?? ''));
        $match = match ($flow['trigger_type'] ?? 'keyword') {
            'keyword' => $kw !== '' && str_contains($inp, $kw),
            'any'     => true,
            default   => false,
        };
        if ($match) { startBotFlow($uid, $cid, $cvid, $flow); return; }
    }
}

function startBotFlow(int $uid, int $cid, int $cvid, array $flow): void {
    $fd = json_decode($flow['flow_data'] ?? '{}', true) ?: [];
    $trigger = null;
    foreach ($fd['nodes'] ?? [] as $n) { if (($n['type'] ?? '') === 'trigger') { $trigger = $n; break; } }
    if (!$trigger) return;
    $sid = db()->insert('INSERT INTO bot_sessions (user_id,contact_id,flow_id,current_node_id,status) VALUES (?,?,?,?,?)',
        [$uid, $cid, $flow['id'], $trigger['id'], 'active']);
    runBotFrom($uid, $cid, $cvid, $flow, (int)$sid, $trigger['id'], '');
}

function advanceBotSession(int $uid, int $cid, int $cvid, array $session, string $input): void {
    // Waiting out a delay node: customer messages do not skip the wait.
    $sd = json_decode($session['session_data'] ?? '', true) ?: [];
    if (!empty($sd['resume_at']) && strtotime($sd['resume_at']) > time()) return;
    $flow = db()->row('SELECT * FROM bot_flows WHERE id=?', [$session['flow_id']]);
    if (!$flow) { db()->run("UPDATE bot_sessions SET status='completed' WHERE id=?", [$session['id']]); return; }
    runBotFrom($uid, $cid, $cvid, $flow, (int)$session['id'], $session['current_node_id'], $input);
}

// Cron: continue sessions whose delay has elapsed.
function resumeDelayedBotSessions(): int {
    $n = 0;
    foreach (db()->all("SELECT * FROM bot_sessions WHERE status='active' AND session_data IS NOT NULL") as $s) {
        $sd = json_decode($s['session_data'], true) ?: [];
        if (empty($sd['resume_at']) || strtotime($sd['resume_at']) > time()) continue;
        $flow = db()->row('SELECT * FROM bot_flows WHERE id=?', [$s['flow_id']]);
        $cv   = db()->row('SELECT id FROM conversations WHERE user_id=? AND contact_id=? ORDER BY id DESC LIMIT 1', [$s['user_id'], $s['contact_id']]);
        db()->run('UPDATE bot_sessions SET session_data=NULL WHERE id=?', [$s['id']]);
        if (!$flow || !$cv) { db()->run("UPDATE bot_sessions SET status='completed' WHERE id=?", [$s['id']]); continue; }
        runBotFrom((int)$s['user_id'], (int)$s['contact_id'], (int)$cv['id'], $flow, (int)$s['id'], $s['current_node_id'], '');
        $n++;
    }
    return $n;
}

function botInterpolate(string $text, array $contact): string {
    return str_replace(['{{name}}', '{{phone}}'], [$contact['name'] ?? '', $contact['phone'] ?? ''], $text);
}

function botLog(int $uid, int $cvid, string $type, string $content, ?string $waId): void {
    db()->insert('INSERT INTO messages (conversation_id,user_id,direction,message_type,content,whatsapp_message_id,status,sent_by,created_at) VALUES (?,?,?,?,?,?,?,?,NOW())',
        [$cvid, $uid, 'outbound', $type, $content, $waId, $waId ? 'sent' : 'failed', null]);
    db()->run('UPDATE conversations SET last_message_at=NOW() WHERE id=?', [$cvid]);
}

function botSend(WhatsAppService $wa, int $uid, int $cvid, string $phone, string $text, array $buttons = []): void {
    $text = trim($text);
    if ($text === '') return;
    $buttons = array_values(array_filter(array_map('trim', $buttons), 'strlen'));
    if ($buttons && count($buttons) <= 3) {
        $btns = [];
        foreach ($buttons as $i => $b) $btns[] = ['id' => 'b' . ($i + 1), 'title' => mb_substr($b, 0, 20)];
        $r = $wa->sendButtons($phone, $text, $btns);
    } elseif ($buttons) {
        $rows = [];
        foreach (array_slice($buttons, 0, 10) as $i => $b) $rows[] = ['id' => 'r' . ($i + 1), 'title' => mb_substr($b, 0, 24)];
        $r = $wa->sendList($phone, $text, 'Choose', [['title' => 'Options', 'rows' => $rows]]);
    } else {
        $r = $wa->sendText($phone, $text);
    }
    $log = $text . ($buttons ? "\n[" . implode(' | ', $buttons) . ']' : '');
    botLog($uid, $cvid, $buttons ? 'interactive' : 'text', $log, $r['message_id'] ?? null);
}

function runBotFrom(int $uid, int $cid, int $cvid, array $flow, int $sid, string $cur, string $input): void {
    $fd    = json_decode($flow['flow_data'] ?? '{}', true) ?: [];
    $nodes = array_column($fd['nodes'] ?? [], null, 'id');
    $edges = $fd['edges'] ?? [];
    $contact = db()->row('SELECT * FROM contacts WHERE id=?', [$cid]);
    $wa      = WhatsAppService::forUser($uid);
    if (!$contact || !$wa) return;
    $phone = $contact['phone'];
    $inputAvailable = trim($input) !== '';
    $status = 'active';

    for ($step = 0; $step < BOT_MAX_STEPS; $step++) {
        $nextId = null;
        foreach ($edges as $e) { if (($e['source'] ?? null) === $cur) { $nextId = $e['target']; break; } }
        if (!$nextId || !isset($nodes[$nextId])) { $status = 'completed'; break; }
        $node = $nodes[$nextId];
        $cfg  = $node['data'] ?? [];

        switch ($node['type'] ?? '') {
            case 'message':
                botSend($wa, $uid, $cvid, $phone, botInterpolate((string)($cfg['text'] ?? ''), $contact), preg_split('/\R/', (string)($cfg['buttons'] ?? '')) ?: []);
                $cur = $nextId;
                break;

            case 'condition':
                if (!$inputAvailable) break 2;                 // wait for the customer's answer
                $inputAvailable = false;
                $want = strtolower(trim((string)($cfg['variable'] ?? '')));
                if ($want !== '' && strtolower(trim($input)) !== $want && !str_contains(strtolower($input), $want)) {
                    break 2;                                    // no match: stay where we are, keep waiting
                }
                $cur = $nextId;
                break;

            case 'delay':
                $mins = max(0, (int)($cfg['minutes'] ?? 0));
                $cur = $nextId;
                if ($mins > 0) {
                    db()->run('UPDATE bot_sessions SET current_node_id=?, session_data=?, updated_at=NOW() WHERE id=?',
                        [$cur, json_encode(['resume_at' => date('Y-m-d H:i:s', time() + $mins * 60)]), $sid]);
                    return;
                }
                break;

            case 'action':
                $tag = trim((string)($cfg['tag'] ?? ''));
                if ($tag !== '') {
                    if (!db()->count('SELECT COUNT(*) FROM contact_tags WHERE contact_id=? AND tag=?', [$cid, $tag])) {
                        db()->run('INSERT INTO contact_tags (user_id,contact_id,tag) VALUES (?,?,?)', [$uid, $cid, $tag]);
                    }
                    if (!db()->count('SELECT COUNT(*) FROM conversation_tags WHERE conversation_id=? AND tag=?', [$cvid, $tag])) {
                        db()->run('INSERT INTO conversation_tags (conversation_id,tag) VALUES (?,?)', [$cvid, $tag]);
                    }
                }
                $note = trim((string)($cfg['note'] ?? ''));
                if ($note !== '') {
                    db()->run("UPDATE contacts SET notes = CONCAT(COALESCE(notes,''), IF(notes IS NULL OR notes='', '', '\n'), ?) WHERE id=?", [$note, $cid]);
                }
                $cur = $nextId;
                break;

            case 'end':
                botSend($wa, $uid, $cvid, $phone, botInterpolate((string)($cfg['message'] ?? ''), $contact));
                $cur = $nextId;
                $status = 'completed';
                break 2;

            default:   // trigger or unknown node: pass through
                $cur = $nextId;
        }
    }
    db()->run('UPDATE bot_sessions SET current_node_id=?, status=?, session_data=NULL, updated_at=NOW() WHERE id=?', [$cur, $status, $sid]);
}
