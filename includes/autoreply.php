<?php
// ============================================================
// Customer-facing automatic behaviour on incoming messages:
//  * STOP / START keywords  -> contact opt-out / opt-in (compliance)
//  * business hours         -> away message when closed (once per cooldown)
//  * welcome message        -> first message of a new conversation
// ============================================================

const AUTOREPLY_DEFAULT_OPTOUT = "You have been unsubscribed and will not receive promotional messages from us. Reply START to subscribe again.";
const AUTOREPLY_DEFAULT_OPTIN  = "You are subscribed again. Reply STOP at any time to unsubscribe.";
const AUTOREPLY_OPTIN_WORDS    = ['start', 'subscribe', 'unstop', 'resubscribe'];

function autoReplyDefaultHours(): array {
    $d = [];
    foreach (['mon', 'tue', 'wed', 'thu', 'fri', 'sat'] as $k) $d[$k] = ['on' => 1, 'from' => '09:00', 'to' => '18:00'];
    $d['sun'] = ['on' => 0, 'from' => '09:00', 'to' => '18:00'];
    return $d;
}

function autoReplySettings(int $uid): array {
    $r = db()->row('SELECT * FROM auto_reply_settings WHERE user_id=?', [$uid]) ?: [];
    $r += ['timezone' => 'Asia/Kolkata', 'hours_enabled' => 0, 'hours_json' => null, 'away_enabled' => 0, 'away_message' => null, 'away_cooldown_hours' => 12,
           'welcome_enabled' => 0, 'welcome_message' => null, 'optout_enabled' => 1, 'optout_keywords' => 'stop,unsubscribe,opt out,optout', 'optout_message' => null, 'optin_message' => null];
    $h = $r['hours_json'] ? json_decode($r['hours_json'], true) : null;
    $r['hours'] = is_array($h) ? $h + autoReplyDefaultHours() : autoReplyDefaultHours();
    foreach ($r['hours'] as $k => $d) {                       // never hand the UI a day without times
        $d = is_array($d) ? $d : [];
        $r['hours'][$k] = ['on' => !empty($d['on']) ? 1 : 0, 'from' => $d['from'] ?? '09:00', 'to' => $d['to'] ?? '18:00'];
    }
    return $r;
}

/** Are we open right now? (always true when business hours are switched off) */
function businessOpenNow(array $s, ?DateTime $now = null): bool {
    if (!(int)$s['hours_enabled']) return true;
    try { $tz = new DateTimeZone($s['timezone'] ?: 'Asia/Kolkata'); } catch (\Throwable $e) { $tz = new DateTimeZone('Asia/Kolkata'); }
    $now = $now ? (clone $now)->setTimezone($tz) : new DateTime('now', $tz);
    $day = strtolower($now->format('D'));
    $prev = strtolower((clone $now)->modify('-1 day')->format('D'));
    $t = $now->format('H:i');
    $hit = function (array $d, string $t, bool $yesterday): bool {
        if (empty($d['on'])) return false;
        $from = $d['from'] ?? '09:00'; $to = $d['to'] ?? '18:00';
        if ($from <= $to) return !$yesterday && $t >= $from && $t < $to;        // same-day window
        return $yesterday ? $t < $to : $t >= $from;                             // overnight window (e.g. 20:00–02:00)
    };
    return $hit($s['hours'][$day] ?? [], $t, false) || $hit($s['hours'][$prev] ?? [], $t, true);
}

/** lower-case, punctuation stripped, single spaces: "  STOP!! " -> "stop" */
function autoReplyNormalize(string $t): string {
    $t = mb_strtolower(trim($t));
    $t = preg_replace('/[^\p{L}\p{N}\s]+/u', '', $t);
    return trim(preg_replace('/\s+/u', ' ', $t));
}

function autoReplySend(int $uid, int $cvid, string $phone, array $contact, string $text): void {
    $wa = WhatsAppService::forUser($uid);
    if (!$wa || trim($text) === '') return;
    $text = botInterpolate($text, $contact);
    $r = $wa->sendText($phone, $text);
    botLog($uid, $cvid, 'text', $text, $r['message_id'] ?? null);
}

/** STOP / START handling. Returns true when the message was an opt keyword (nothing else should run on it). */
function handleOptKeywords(int $uid, array $ct, int $cvid, string $text, string $phone): bool {
    $s = autoReplySettings($uid);
    if (!(int)$s['optout_enabled']) return false;
    $n = autoReplyNormalize($text);
    if ($n === '' || mb_strlen($n) > 30) return false;

    $out = array_filter(array_map('autoReplyNormalize', explode(',', (string)$s['optout_keywords'])));
    $canReply = subscriptionActive($uid);
    if (in_array($n, $out, true)) {
        $was = (int)$ct['opt_in'];
        db()->run('UPDATE contacts SET opt_in=0 WHERE id=?', [$ct['id']]);
        if ($was) notify($uid, 'Customer unsubscribed', ($ct['name'] ?: $phone) . " ($phone) replied “" . mb_substr(trim($text), 0, 30) . '” – no more marketing messages will be sent to them.', 'warning', '/user/contacts.php');
        if ($canReply) autoReplySend($uid, $cvid, $phone, $ct, trim((string)$s['optout_message']) ?: AUTOREPLY_DEFAULT_OPTOUT);
        return true;
    }
    if (in_array($n, AUTOREPLY_OPTIN_WORDS, true) && !(int)$ct['opt_in']) {
        db()->run('UPDATE contacts SET opt_in=1 WHERE id=?', [$ct['id']]);
        if ($canReply) autoReplySend($uid, $cvid, $phone, $ct, trim((string)$s['optin_message']) ?: AUTOREPLY_DEFAULT_OPTIN);
        return true;
    }
    return false;
}

/** Welcome / away message for an incoming message. Never fires when a chatbot or a human is already answering. */
function maybeAutoReply(int $uid, array $ct, array $cv, string $phone, bool $firstInbound): void {
    $s = autoReplySettings($uid);
    $wantWelcome = (int)$s['welcome_enabled'] && trim((string)$s['welcome_message']) !== '' && $firstInbound && empty($cv['welcome_sent_at']);
    $closed = (int)$s['hours_enabled'] && !businessOpenNow($s);
    $wantAway = (int)$s['away_enabled'] && (int)$s['hours_enabled'] && trim((string)$s['away_message']) !== '' && $closed;
    if (!$wantWelcome && !$wantAway) return;
    if (!subscriptionActive($uid)) return;

    // A chatbot answers 24/7 – don't talk over it.
    if (($cv['is_bot_active'] ?? 1) && planAllows($uid, 'chatbot_builder') && db()->count('SELECT COUNT(*) FROM bot_flows WHERE user_id=? AND is_active=1', [$uid]) > 0) return;
    // A human already replied in the last hour.
    if (db()->count("SELECT COUNT(*) FROM messages WHERE conversation_id=? AND direction='outbound' AND sent_by IS NOT NULL AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)", [$cv['id']])) return;

    $cvid = (int)$cv['id'];
    if ($wantAway) {
        $cool = max(1, (int)$s['away_cooldown_hours']);
        $last = db()->row('SELECT last_away_at, (last_away_at IS NULL OR last_away_at < DATE_SUB(NOW(), INTERVAL ? HOUR)) AS due FROM conversations WHERE id=?', [$cool, $cvid]);
        if ($last && (int)$last['due']) {
            db()->run('UPDATE conversations SET last_away_at=NOW() WHERE id=?', [$cvid]);
            if ($wantWelcome) db()->run('UPDATE conversations SET welcome_sent_at=NOW() WHERE id=?', [$cvid]);
            autoReplySend($uid, $cvid, $phone, $ct, (string)$s['away_message']);
        }
        return;      // closed: away message replaces the welcome text
    }
    if ($wantWelcome) {
        db()->run('UPDATE conversations SET welcome_sent_at=NOW() WHERE id=? AND welcome_sent_at IS NULL', [$cvid]);
        autoReplySend($uid, $cvid, $phone, $ct, (string)$s['welcome_message']);
    }
}
