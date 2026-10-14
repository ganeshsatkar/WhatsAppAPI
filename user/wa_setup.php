<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/WhatsAppService.php';
requireLogin();
$pageTitle = 'WhatsApp Setup & Test';
$uid = currentUser()['id'];

$was = db()->row('SELECT * FROM user_whatsapp_settings WHERE user_id=?', [$uid]);
$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if ($_POST['action'] === 'test_send') {
        $phone = preg_replace('/[^0-9]/', '', $_POST['phone'] ?? '');
        if (strlen($phone) === 10) $phone = '91' . $phone;
        $wa  = WhatsAppService::forUser($uid);
        if (!$wa) { $err = 'API not connected.'; }
        else {
            $res = $wa->sendText($phone, 'Hello from WP API! This is a test message. ✅');
            if ($res['success']) $msg = '✅ Message sent! ID: ' . $res['message_id'];
            else $err = '❌ Failed: ' . ($res['error'] ?? 'Unknown') . ' (Code: ' . ($res['code'] ?? '?') . ')';
        }
    }
}

// Simulate incoming webhook for testing
if (isset($_GET['simulate']) && APP_ENV !== 'production') {
    $testPhone = '919999900001';
    $wa = WhatsAppService::forUser($uid);
    // Create a fake incoming message
    $phone = $testPhone;
    $contact = db()->row('SELECT * FROM contacts WHERE user_id=? AND phone=?', [$uid, $phone]);
    if (!$contact) {
        $cid = db()->insert('INSERT INTO contacts (user_id,name,phone,opt_in,source) VALUES (?,?,?,1,?)', [$uid,'Test User',$phone,'manual']);
    } else { $cid = $contact['id']; }
    $conv = db()->row('SELECT * FROM conversations WHERE user_id=? AND contact_id=?', [$uid, $cid]);
    if (!$conv) {
        $convId = db()->insert('INSERT INTO conversations (user_id,contact_id,phone,status,is_bot_active,last_message_at) VALUES (?,?,?,?,0,NOW())', [$uid,$cid,$phone,'open']);
    } else { $convId = $conv['id']; }
    db()->insert('INSERT INTO messages (conversation_id,user_id,direction,message_type,content,status,created_at) VALUES (?,?,?,?,?,?,NOW())',
        [$convId,$uid,'inbound','text','Hello! This is a simulated incoming message 👋','delivered']);
    db()->run('UPDATE conversations SET last_message_at=NOW(),last_message_preview=?,unread_count=unread_count+1 WHERE id=?',
        ['Hello! This is a simulated incoming message 👋', $convId]);
    header('Location: /user/inbox.php');
    exit;
}

include __DIR__ . '/../includes/user_header.php';
?>

<?php if ($msg): ?><div class="alert alert-success"><?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠ <?= e($err) ?></div><?php endif; ?>

<div class="g2 mb2">
  <!-- API Status -->
  <div class="card">
    <div class="card-header"><div class="card-title">📱 WhatsApp API Status</div></div>
    <div class="card-body">
      <?php if (!$was): ?>
      <div style="background:var(--danger-dim);border:1px solid rgba(255,77,109,0.3);border-radius:9px;padding:14px;margin-bottom:14px;">
        <div style="font-weight:700;color:var(--danger);margin-bottom:5px;">❌ Not Connected</div>
        <div style="font-size:13px;color:var(--text2);">Go to Settings → WhatsApp API to connect.</div>
      </div>
      <a href="/user/settings.php" class="btn btn-primary">Connect WhatsApp API →</a>
      <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:8px;font-size:13px;margin-bottom:16px;">
        <div style="display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--border);">
          <span style="color:var(--text2);">Status</span>
          <span class="badge <?= $was['is_connected'] ? 'badge-green' : 'badge-red' ?>"><?= $was['is_connected'] ? '● Connected' : '● Disconnected' ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--border);">
          <span style="color:var(--text2);">Phone Number</span>
          <strong style="color:#fff;"><?= e($was['phone_number'] ?? '—') ?></strong>
        </div>
        <div style="display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--border);">
          <span style="color:var(--text2);">Phone Number ID</span>
          <code style="font-size:12px;color:var(--accent);"><?= e($was['phone_number_id'] ?? '—') ?></code>
        </div>
        <div style="display:flex;justify-content:space-between;padding:9px 0;border-bottom:1px solid var(--border);">
          <span style="color:var(--text2);">Access Token</span>
          <span style="color:var(--accent);"><?= $was['access_token'] ? '✓ Set (' . strlen((string)decryptSecret($was['access_token'])) . ' chars)' : '✗ Missing' ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;padding:9px 0;">
          <span style="color:var(--text2);">Verify Token</span>
          <code style="font-size:12px;color:var(--text2);"><?= e($was['verify_token'] ?? '—') ?></code>
        </div>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- Test Send -->
  <div class="card">
    <div class="card-header"><div class="card-title">🧪 Test Send Message</div></div>
    <div class="card-body">
      <form method="POST">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="test_send">
        <div class="form-group">
          <label class="form-label">Phone Number</label>
          <input type="text" name="phone" class="form-control" placeholder="9876543210">
          <div style="font-size:11px;color:var(--muted);margin-top:4px;">+91 added automatically for 10-digit numbers</div>
        </div>
        <button type="submit" class="btn btn-primary" style="width:100%;">📤 Send Test Message</button>
      </form>
      <div style="margin-top:14px;border-top:1px solid var(--border);padding-top:14px;">
        <div style="font-size:12px;font-weight:700;color:var(--muted);margin-bottom:8px;">TEST INCOMING MESSAGE</div>
        <p style="font-size:12px;color:var(--text2);margin-bottom:10px;">Simulate an incoming message to test your inbox without a real customer:</p>
        <a href="?simulate=1" class="btn btn-secondary" style="width:100%;justify-content:center;">💬 Simulate Incoming Message</a>
      </div>
    </div>
  </div>
</div>

<!-- Webhook Setup -->
<div class="card mb2">
  <div class="card-header"><div class="card-title">🔗 Webhook Configuration</div></div>
  <div class="card-body">
    <div style="background:rgba(37,211,102,0.05);border:1px solid rgba(37,211,102,0.2);border-radius:10px;padding:16px;margin-bottom:16px;">
      <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.7px;margin-bottom:10px;">Your Webhook URL</div>
      <div style="display:flex;align-items:center;gap:10px;">
        <code style="flex:1;font-family:monospace;font-size:13px;color:var(--accent);background:var(--surface2);padding:10px 14px;border-radius:8px;word-break:break-all;"><?= APP_URL ?>/webhook/whatsapp.php</code>
        <button onclick="navigator.clipboard.writeText('<?= APP_URL ?>/webhook/whatsapp.php');this.textContent='✓ Copied!';setTimeout(()=>this.textContent='Copy',2000);" class="btn btn-secondary btn-sm">Copy</button>
      </div>
    </div>

    <div style="background:var(--surface2);border-radius:10px;padding:16px;margin-bottom:16px;">
      <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.7px;margin-bottom:10px;">Verify Token</div>
      <code style="font-family:monospace;font-size:13px;color:var(--text2);word-break:break-all;"><?= e($was['verify_token'] ?? 'Connect API first to get verify token') ?></code>
    </div>

    <div style="font-size:13px;color:var(--text2);line-height:1.8;">
      <strong style="color:#fff;">How to set up webhook in Meta:</strong><br>
      1. Go to <a href="https://developers.facebook.com" target="_blank" style="color:var(--accent);">developers.facebook.com</a><br>
      2. Your App → WhatsApp → Configuration<br>
      3. Edit Webhook → paste the <strong>Webhook URL</strong> above<br>
      4. Paste the <strong>Verify Token</strong> above<br>
      5. Click Verify → Subscribe to: <code style="background:var(--surface3);padding:1px 5px;border-radius:4px;">messages</code><br>
      6. Save ✅
    </div>
  </div>
</div>

<!-- Recent messages test -->
<div class="card">
  <div class="card-header">
    <div class="card-title">📊 Recent Messages (Last 10)</div>
    <a href="/user/inbox.php" class="btn btn-primary btn-sm">Open Inbox →</a>
  </div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>Direction</th><th>Contact</th><th>Message</th><th>Status</th><th>Time</th></tr></thead>
      <tbody>
        <?php
        $msgs = db()->all("SELECT m.*,c.name as contact_name,c.phone FROM messages m JOIN conversations cv ON cv.id=m.conversation_id JOIN contacts c ON c.id=cv.contact_id WHERE cv.user_id=? ORDER BY m.created_at DESC LIMIT 10", [$uid]);
        if (empty($msgs)):
        ?>
        <tr><td colspan="5" style="text-align:center;padding:30px;color:var(--muted);">No messages yet. Send a test above or simulate an incoming message.</td></tr>
        <?php else: foreach ($msgs as $m): ?>
        <tr>
          <td><span class="badge <?= $m['direction']==='inbound'?'badge-blue':'badge-green' ?>"><?= $m['direction']==='inbound'?'↓ In':'↑ Out' ?></span></td>
          <td class="td-bold"><?= e($m['contact_name']) ?><div style="font-size:11px;color:var(--muted);"><?= e($m['phone']) ?></div></td>
          <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= e($m['content']) ?></td>
          <td><span class="badge badge-gray"><?= e($m['status']) ?></span></td>
          <td style="font-size:11px;color:var(--muted);"><?= date('M j H:i', strtotime($m['created_at'])) ?></td>
        </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<?php include __DIR__ . '/../includes/user_footer.php'; ?>
