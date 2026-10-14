<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/WhatsAppService.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/api_core.php';
requireLogin();
$pageTitle = 'Developer API';
requireFeature('api_access', 'Developer API');   // plan without API sees an upgrade card, not the key form
$uid = (int)currentUser()['id'];
$allowed = apiPlanAllows($uid);
$msg = $err = ''; $newKey = null; $testResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    if (!$allowed && $action !== '') { $err = 'API access is not included in your plan. Upgrade to use the API.'; }
    elseif ($action === 'create_key') {
        if (db()->count('SELECT COUNT(*) FROM api_keys WHERE user_id=? AND is_active=1', [$uid]) >= 10) $err = 'Maximum 10 active keys. Revoke one first.';
        else { [$id, $newKey] = apiGenerateKey($uid, trim($_POST['name'] ?? '')); logActivity('api_key_created', "Key #$id"); }
    } elseif ($action === 'revoke_key') {
        db()->run('UPDATE api_keys SET is_active=0, revoked_at=NOW() WHERE id=? AND user_id=?', [(int)$_POST['key_id'], $uid]);
        $msg = 'Key revoked. Software using it will get 401 immediately.';
    } elseif ($action === 'save_callback') {
        $url = trim($_POST['callback_url'] ?? '');
        if ($url !== '' && ($why = apiCallbackUrlAllowed($url))) $err = $why;
        else {
            $cur = db()->row('SELECT callback_secret FROM api_settings WHERE user_id=?', [$uid]);
            $secret = $cur['callback_secret'] ?? bin2hex(random_bytes(24));
            db()->run('INSERT INTO api_settings (user_id,callback_url,callback_secret) VALUES (?,?,?) ON DUPLICATE KEY UPDATE callback_url=VALUES(callback_url), callback_secret=COALESCE(callback_secret, VALUES(callback_secret))', [$uid, $url ?: null, $secret]);
            $msg = $url ? 'Callback URL saved.' : 'Callbacks turned off.';
        }
    } elseif ($action === 'regen_secret') {
        db()->run('UPDATE api_settings SET callback_secret=? WHERE user_id=?', [bin2hex(random_bytes(24)), $uid]);
        $msg = 'New signing secret generated — update it in your software.';
    } elseif ($action === 'test_callback') {
        [$ok, $info] = apiPostCallback($uid, ['event' => 'ping', 'message' => null]);
        $testResult = [$ok, $info];
    }
}

$keys = db()->all('SELECT * FROM api_keys WHERE user_id=? ORDER BY is_active DESC, id DESC', [$uid]);
$cb   = db()->row('SELECT * FROM api_settings WHERE user_id=?', [$uid]);
$base = rtrim(APP_URL, '/') . '/api/v1';
$tpl  = db()->row("SELECT template_name, body FROM message_templates WHERE user_id=? AND status='approved' ORDER BY id LIMIT 1", [$uid]);
$exName = $tpl['template_name'] ?? 'birthday_wish';
preg_match_all('/\{\{(\d+)\}\}/', (string)($tpl['body'] ?? '{{1}}'), $m);
$exParams = json_encode(array_fill(0, max(1, $m[1] ? max(array_map('intval', $m[1])) : 1), 'Asha'));
include __DIR__ . '/../includes/user_header.php';
?>
<?php if ($msg): ?><div class="alert alert-success">✓ <?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠ <?= e($err) ?></div><?php endif; ?>
<?php if (!$allowed): ?><div class="alert alert-danger">🔒 API access is not part of your current plan. <a href="/user/settings.php#billing" style="color:var(--accent)">Upgrade →</a></div><?php endif; ?>

<?php if ($newKey): ?>
<div class="card mb2" style="border-color:var(--accent);">
  <div class="card-header"><div class="card-title">🔑 Your new API key — copy it now</div></div>
  <div class="card-body">
    <div style="font-size:13px;color:var(--text2);margin-bottom:10px;">For security this key is shown <strong>only once</strong> and cannot be recovered. If you lose it, revoke it and create another.</div>
    <div style="display:flex;gap:8px;"><input id="newKey" class="form-control" readonly value="<?= e($newKey) ?>" style="font-family:monospace;"><button class="btn btn-primary" onclick="navigator.clipboard.writeText(document.getElementById('newKey').value);this.textContent='Copied ✓'">Copy</button></div>
  </div>
</div>
<?php endif; ?>

<div class="g2 mb2">
  <div class="card">
    <div class="card-header"><div class="card-title">🔑 API keys</div></div>
    <div class="card-body">
      <form method="POST" style="display:flex;gap:8px;margin-bottom:14px;">
        <?= csrfField() ?><input type="hidden" name="action" value="create_key">
        <input type="text" name="name" class="form-control" placeholder="e.g. Birthday software" maxlength="100" required>
        <button class="btn btn-primary" <?= $allowed ? '' : 'disabled' ?>>Create key</button>
      </form>
      <?php if (!$keys): ?><div style="color:var(--muted);font-size:13px;">No keys yet.</div><?php endif; ?>
      <?php foreach ($keys as $k): ?>
      <div style="display:flex;align-items:center;gap:10px;padding:10px 0;border-top:1px solid var(--border);">
        <div style="flex:1;min-width:0;">
          <div style="font-weight:700;color:#fff;"><?= e($k['name']) ?> <?= $k['is_active'] ? '' : '<span class="badge badge-red">revoked</span>' ?></div>
          <div style="font-size:12px;color:var(--muted);"><code><?= e($k['key_prefix']) ?>…</code> · last used <?= $k['last_used_at'] ? '<time data-ts="' . e($k['last_used_at']) . '"></time>' : 'never' ?></div>
        </div>
        <?php if ($k['is_active']): ?>
        <form method="POST" onsubmit="return confirm('Revoke this key? Software using it stops working immediately.')">
          <?= csrfField() ?><input type="hidden" name="action" value="revoke_key"><input type="hidden" name="key_id" value="<?= (int)$k['id'] ?>">
          <button class="btn btn-secondary">Revoke</button>
        </form>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <div class="card">
    <div class="card-header"><div class="card-title">🔔 Delivery callbacks (optional)</div></div>
    <div class="card-body">
      <p style="font-size:12.5px;color:var(--text2);margin-bottom:10px;">We POST a JSON update to your software whenever a message is <strong>delivered, read or fails</strong>, so your own system knows too. Retries automatically for ~1 hour if your server is down.</p>
      <form method="POST">
        <?= csrfField() ?><input type="hidden" name="action" value="save_callback">
        <input type="url" name="callback_url" class="form-control" placeholder="https://your-software.com/whatsapp-status" value="<?= e($cb['callback_url'] ?? '') ?>">
        <div style="display:flex;gap:8px;margin-top:8px;"><button class="btn btn-primary">Save</button></div>
      </form>
      <?php if (!empty($cb['callback_url'])): ?>
      <div style="margin-top:12px;font-size:12px;color:var(--text2);">Signing secret (HMAC-SHA256): <code style="word-break:break-all;"><?= e($cb['callback_secret']) ?></code></div>
      <div style="display:flex;gap:8px;margin-top:8px;">
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="test_callback"><button class="btn btn-secondary">Send test ping</button></form>
        <form method="POST" onsubmit="return confirm('Generate a new secret? Update it in your software.')"><?= csrfField() ?><input type="hidden" name="action" value="regen_secret"><button class="btn btn-secondary">New secret</button></form>
      </div>
      <?php if ($testResult): ?><div style="margin-top:8px;font-size:12.5px;color:<?= $testResult[0] ? 'var(--accent)' : 'var(--danger)' ?>;"><?= $testResult[0] ? '✓ Your server answered ' : '✗ Failed: ' ?><?= e($testResult[1]) ?></div><?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>

<div class="card mb2">
  <div class="card-header"><div class="card-title">🚀 Quick start — send a birthday message from your software</div></div>
  <div class="card-body" style="font-size:13px;color:var(--text2);line-height:1.7;">
<pre style="background:var(--surface3);padding:14px;border-radius:8px;overflow:auto;font-size:12px;color:var(--text);">curl -X POST <?= e($base) ?>/messages/template \
  -H "Authorization: Bearer YOUR_API_KEY" \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: bday-1042-2026" \
  -d '{
    "to": "9876543210",
    "template": "<?= e($exName) ?>",
    "language": "en",
    "params": <?= e($exParams) ?>,
    "client_ref": "customer-1042"
  }'</pre>
    <strong>Response</strong> — the message id and status; <code>status</code> moves <code>sent → delivered → read</code>, or <code>failed</code> with the reason:
<pre style="background:var(--surface3);padding:14px;border-radius:8px;overflow:auto;font-size:12px;color:var(--text);">{ "success": true,
  "message": { "id": 811, "client_ref": "customer-1042", "to": "919876543210", "status": "sent",
               "error": null, "cost": 0.1595, "timestamps": { "sent": "2026-10-14T03:00:02Z", ... } } }</pre>
    <ul style="padding-left:18px;">
      <li><strong>Idempotency-Key</strong>: use a unique id per birthday (e.g. <code>bday-&lt;customer&gt;-&lt;year&gt;</code>). If your software retries, the customer is never messaged twice or charged twice.</li>
      <li><strong>Check status later</strong>: <code>GET <?= e($base) ?>/messages/{id}</code> or <code>/messages/ref/{your client_ref}</code>. List/filter: <code>GET /messages?status=failed&amp;from=2026-10-14</code>.</li>
      <li><strong>Many at once</strong>: <code>POST /messages/batch</code> with up to 50 messages.</li>
      <li><strong>Billing</strong>: each message is charged from your wallet; messages that fail are refunded automatically. Everything is visible in <a href="/user/api_logs.php" style="color:var(--accent);">API Messages</a>.</li>
      <li><strong>Verify callbacks</strong>: header <code>X-WhatsFlow-Signature: sha256=HMAC_SHA256(secret, timestamp + "." + rawBody)</code>, with <code>X-WhatsFlow-Timestamp</code>.</li>
    </ul>
    Full reference: <code>API_DOCUMENTATION.md</code> in the package.
  </div>
</div>
<script>document.querySelectorAll('time[data-ts]').forEach(t=>{t.textContent=new Date(t.dataset.ts.replace(' ','T')+'Z').toLocaleString('en-IN',{day:'numeric',month:'short',hour:'2-digit',minute:'2-digit'});});</script>
<?php include __DIR__ . '/../includes/user_footer.php'; ?>
