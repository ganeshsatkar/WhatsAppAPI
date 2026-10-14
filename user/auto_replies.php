<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/autoreply.php';
requireLogin();
$uid = (int)currentUser()['id'];
$pageTitle = 'Auto-replies & Opt-out';
$msg = $err = '';
$days = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $a = $_POST['action'] ?? '';
    if ($a === 'save_settings') {
        $tz = in_array($_POST['timezone'] ?? '', DateTimeZone::listIdentifiers(), true) ? $_POST['timezone'] : 'Asia/Kolkata';
        $hours = [];
        foreach ($days as $k => $_) {
            $from = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $_POST["from_$k"] ?? '') ? $_POST["from_$k"] : '09:00';
            $to   = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $_POST["to_$k"] ?? '') ? $_POST["to_$k"] : '18:00';
            $hours[$k] = ['on' => isset($_POST["on_$k"]) ? 1 : 0, 'from' => $from, 'to' => $to];
        }
        $kw = array_slice(array_values(array_unique(array_filter(array_map(fn($x) => mb_substr(autoReplyNormalize($x), 0, 30), explode(',', (string)($_POST['optout_keywords'] ?? '')))))), 0, 10);
        if (!$kw) $kw = ['stop', 'unsubscribe'];
        $t = fn($k) => mb_substr(trim(strip_tags((string)($_POST[$k] ?? ''))), 0, 1000);
        $hoursOn = isset($_POST['hours_enabled']) ? 1 : 0;
        $awayOn  = isset($_POST['away_enabled']) ? 1 : 0;
        if ($awayOn && !$hoursOn) { $awayOn = 0; $err = 'Away message needs business hours switched on – it was saved switched off.'; }
        if ($awayOn && $t('away_message') === '') { $awayOn = 0; $err = 'Write the away message first – it was saved switched off.'; }
        db()->run('INSERT INTO auto_reply_settings (user_id,timezone,hours_enabled,hours_json,away_enabled,away_message,away_cooldown_hours,welcome_enabled,welcome_message,optout_enabled,optout_keywords,optout_message,optin_message)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
                   ON DUPLICATE KEY UPDATE timezone=VALUES(timezone),hours_enabled=VALUES(hours_enabled),hours_json=VALUES(hours_json),away_enabled=VALUES(away_enabled),away_message=VALUES(away_message),
                   away_cooldown_hours=VALUES(away_cooldown_hours),welcome_enabled=VALUES(welcome_enabled),welcome_message=VALUES(welcome_message),optout_enabled=VALUES(optout_enabled),
                   optout_keywords=VALUES(optout_keywords),optout_message=VALUES(optout_message),optin_message=VALUES(optin_message)',
            [$uid, $tz, $hoursOn, json_encode($hours), $awayOn, $t('away_message'), max(1, min(168, (int)($_POST['away_cooldown_hours'] ?? 12))),
             isset($_POST['welcome_enabled']) && $t('welcome_message') !== '' ? 1 : 0, $t('welcome_message'), isset($_POST['optout_enabled']) ? 1 : 0, implode(',', $kw), $t('optout_message'), $t('optin_message')]);
        if (!$err) $msg = 'Saved.';
    }
    if ($a === 'qr_add' || $a === 'qr_edit') {
        $title = mb_substr(trim(strip_tags((string)($_POST['title'] ?? ''))), 0, 60);
        $body  = mb_substr(trim(strip_tags((string)($_POST['body'] ?? ''))), 0, 1000);
        if ($title === '' || $body === '') { $err = 'Both a title and the message are required.'; }
        elseif ($a === 'qr_add') {
            if (db()->count('SELECT COUNT(*) FROM quick_replies WHERE user_id=?', [$uid]) >= 40) $err = 'Maximum 40 quick replies.';
            else { db()->run('INSERT INTO quick_replies (user_id,title,body,sort_order) VALUES (?,?,?,?)', [$uid, $title, $body, (int)db()->row('SELECT COALESCE(MAX(sort_order),0)+1 n FROM quick_replies WHERE user_id=?', [$uid])['n']]); $msg = 'Quick reply added.'; }
        } else { db()->run('UPDATE quick_replies SET title=?, body=? WHERE id=? AND user_id=?', [$title, $body, (int)$_POST['id'], $uid]); $msg = 'Quick reply updated.'; }
    }
    if ($a === 'qr_delete') { db()->run('DELETE FROM quick_replies WHERE id=? AND user_id=?', [(int)$_POST['id'], $uid]); $msg = 'Deleted.'; }
}

$s  = autoReplySettings($uid);
$qr = db()->all('SELECT * FROM quick_replies WHERE user_id=? ORDER BY sort_order, id', [$uid]);
$optedOut = db()->count('SELECT COUNT(*) FROM contacts WHERE user_id=? AND opt_in=0', [$uid]);
$open = businessOpenNow($s);
include __DIR__ . '/../includes/user_header.php';
?>
<?php if ($msg): ?><div class="alert alert-success">✓ <?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠ <?= e($err) ?></div><?php endif; ?>

<form method="POST">
<?= csrfField() ?><input type="hidden" name="action" value="save_settings">
<div class="g2 mb2">
  <div class="card">
    <div class="card-header"><div class="card-title">🕘 Business hours</div><span class="badge <?= !(int)$s['hours_enabled'] ? 'badge-gray' : ($open ? 'badge-green' : 'badge-orange') ?>"><?= !(int)$s['hours_enabled'] ? 'Off' : ($open ? 'Open now' : 'Closed now') ?></span></div>
    <div class="card-body">
      <label style="display:block;margin-bottom:10px;font-size:13px;"><input type="checkbox" name="hours_enabled" <?= (int)$s['hours_enabled'] ? 'checked' : '' ?> style="accent-color:var(--accent)"> Use business hours</label>
      <div class="form-group"><label class="form-label">Time zone</label>
        <select name="timezone" class="form-control"><?php foreach (['Asia/Kolkata' => 'India (IST)', 'Asia/Dubai' => 'Dubai', 'Asia/Singapore' => 'Singapore', 'Europe/London' => 'London', 'America/New_York' => 'New York', 'UTC' => 'UTC'] as $z => $l): ?>
          <option value="<?= $z ?>" <?= $s['timezone'] === $z ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></div>
      <table style="width:100%;font-size:13px;"><?php foreach ($days as $k => $lab): $d = $s['hours'][$k]; ?>
        <tr><td style="padding:4px 0;"><label><input type="checkbox" name="on_<?= $k ?>" <?= !empty($d['on']) ? 'checked' : '' ?> style="accent-color:var(--accent)"> <?= $lab ?></label></td>
          <td><input type="time" name="from_<?= $k ?>" value="<?= e($d['from']) ?>" class="form-control" style="padding:4px 6px;"></td>
          <td style="text-align:center;color:var(--muted);">to</td>
          <td><input type="time" name="to_<?= $k ?>" value="<?= e($d['to']) ?>" class="form-control" style="padding:4px 6px;"></td></tr><?php endforeach; ?></table>
    </div>
  </div>

  <div>
    <div class="card mb2"><div class="card-header"><div class="card-title">🌙 Away message</div></div><div class="card-body">
      <label style="display:block;margin-bottom:8px;font-size:13px;"><input type="checkbox" name="away_enabled" <?= (int)$s['away_enabled'] ? 'checked' : '' ?> style="accent-color:var(--accent)"> Reply automatically when we are closed</label>
      <textarea name="away_message" class="form-control" rows="3" maxlength="1000" placeholder="Hi {{name}}, we are closed right now. We will reply when we open at 9 AM."><?= e((string)$s['away_message']) ?></textarea>
      <div style="display:flex;gap:8px;align-items:center;margin-top:8px;font-size:12px;color:var(--text2);">Send at most once every <input type="number" name="away_cooldown_hours" value="<?= (int)$s['away_cooldown_hours'] ?>" min="1" max="168" class="form-control" style="width:70px;padding:4px 6px;"> hours per customer</div>
      <p style="font-size:11.5px;color:var(--muted);margin-top:8px;">Not sent when a chatbot is active (it answers 24/7) or when you replied personally in the last hour. <code>{{name}}</code> becomes the customer's name.</p>
    </div></div>
    <div class="card"><div class="card-header"><div class="card-title">👋 Welcome message</div></div><div class="card-body">
      <label style="display:block;margin-bottom:8px;font-size:13px;"><input type="checkbox" name="welcome_enabled" <?= (int)$s['welcome_enabled'] ? 'checked' : '' ?> style="accent-color:var(--accent)"> Greet customers who message us for the first time</label>
      <textarea name="welcome_message" class="form-control" rows="3" maxlength="1000" placeholder="Welcome to Acme, {{name}}! How can we help?"><?= e((string)$s['welcome_message']) ?></textarea>
    </div></div>
  </div>
</div>

<div class="card mb2"><div class="card-header"><div class="card-title">🚫 Opt-out (STOP) handling</div><span class="badge badge-gray"><?= number_format($optedOut) ?> contact(s) opted out</span></div><div class="card-body">
  <label style="display:block;margin-bottom:10px;font-size:13px;"><input type="checkbox" name="optout_enabled" <?= (int)$s['optout_enabled'] ? 'checked' : '' ?> style="accent-color:var(--accent)"> Automatically unsubscribe people who send a stop word (recommended – WhatsApp expects it)</label>
  <div class="g2">
    <div class="form-group"><label class="form-label">Stop words (comma separated, whole message only)</label><input type="text" name="optout_keywords" class="form-control" value="<?= e($s['optout_keywords']) ?>" maxlength="300"></div>
    <div class="form-group"><label class="form-label">Re-subscribe words</label><input type="text" class="form-control" value="start, subscribe" disabled></div>
  </div>
  <div class="g2">
    <div class="form-group"><label class="form-label">Reply after STOP (blank = default)</label><textarea name="optout_message" class="form-control" rows="2" maxlength="1000" placeholder="<?= e(AUTOREPLY_DEFAULT_OPTOUT) ?>"><?= e((string)$s['optout_message']) ?></textarea></div>
    <div class="form-group"><label class="form-label">Reply after START (blank = default)</label><textarea name="optin_message" class="form-control" rows="2" maxlength="1000" placeholder="<?= e(AUTOREPLY_DEFAULT_OPTIN) ?>"><?= e((string)$s['optin_message']) ?></textarea></div>
  </div>
  <p style="font-size:11.5px;color:var(--muted);">Opted-out people are skipped by marketing campaigns and by marketing templates sent through the API (utility/authentication messages still go). Their chat stays open.</p>
</div></div>
<div style="margin-bottom:26px;"><button class="btn btn-primary">Save settings</button></div>
</form>

<div class="card" id="quick"><div class="card-header"><div class="card-title">⚡ Quick replies</div><span class="badge badge-green"><?= count($qr) ?></span></div><div class="card-body">
  <p style="font-size:12.5px;color:var(--text2);margin-bottom:12px;">Shown in the Inbox side panel – one click puts the text in the reply box. Use <code>{name}</code> for the customer's first name.</p>
  <?php foreach ($qr as $q): ?>
  <form method="POST" style="display:grid;grid-template-columns:1fr 3fr auto auto;gap:8px;margin-bottom:8px;align-items:start;">
    <?= csrfField() ?><input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
    <input type="text" name="title" class="form-control" value="<?= e($q['title']) ?>" maxlength="60">
    <textarea name="body" class="form-control" rows="2" maxlength="1000"><?= e($q['body']) ?></textarea>
    <button name="action" value="qr_edit" class="btn btn-secondary btn-sm">Save</button>
    <button name="action" value="qr_delete" class="btn btn-danger btn-sm" onclick="return confirm('Delete this quick reply?')">🗑</button>
  </form>
  <?php endforeach; ?>
  <form method="POST" style="display:grid;grid-template-columns:1fr 3fr auto;gap:8px;margin-top:14px;align-items:start;border-top:1px solid var(--border);padding-top:14px;">
    <?= csrfField() ?><input type="hidden" name="action" value="qr_add">
    <input type="text" name="title" class="form-control" placeholder="Title (e.g. Pricing)" maxlength="60" required>
    <textarea name="body" class="form-control" rows="2" placeholder="Message… e.g. Hi {name}, our prices start at ₹499." maxlength="1000" required></textarea>
    <button class="btn btn-primary btn-sm">+ Add</button>
  </form>
</div></div>
<?php include __DIR__ . '/../includes/user_footer.php'; ?>
