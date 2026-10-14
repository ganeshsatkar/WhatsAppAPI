<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/WhatsAppService.php';

requireLogin();
$pageTitle = 'Settings';
$uid  = currentUser()['id'];
$user = currentUser();
$msg  = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $section = $_POST['section'] ?? '';

    // ---- WhatsApp API Settings ----
    if ($section === 'whatsapp') {
        $existing           = db()->row('SELECT * FROM user_whatsapp_settings WHERE user_id = ?', [$uid]);
        $phoneNumberId      = preg_replace('/\D/', '', $_POST['phone_number_id'] ?? '');
        $businessAccountId  = preg_replace('/\D/', '', $_POST['business_account_id'] ?? '');
        // Blank token field = keep the saved one (the token is never sent back to the browser)
        $accessToken        = trim($_POST['access_token'] ?? '') ?: (string)decryptSecret($existing['access_token'] ?? null);
        $phoneNumber        = normalizePhone($_POST['phone_number'] ?? '');
        $displayName        = mb_substr(sanitize($_POST['display_name'] ?? ''), 0, 200);

        if (!$phoneNumberId || !$accessToken) {
            $err = 'Phone Number ID and Access Token are required.';
        } elseif (db()->row('SELECT id FROM user_whatsapp_settings WHERE phone_number_id = ? AND user_id <> ?', [$phoneNumberId, $uid])) {
            $err = 'This Phone Number ID is already connected to another account.';
        } else {
            // Test connection against the phone number itself (and the WABA when given)
            $test = WhatsAppService::graph('GET', $phoneNumberId, ['fields' => 'display_phone_number,verified_name'], null, $accessToken);
            $connected = isset($test['id']);
            if ($connected && $businessAccountId) {
                $t2 = (new WhatsAppService($phoneNumberId, $accessToken))->getTemplates($businessAccountId);
                if (!isset($t2['data'])) { $connected = false; $test = $t2; }
            }
            if ($connected) {
                $displayName = $displayName ?: (string)($test['verified_name'] ?? '');
                $phoneNumber = $phoneNumber ?: normalizePhone($test['display_phone_number'] ?? '');
            }

            db()->run('INSERT INTO user_whatsapp_settings (user_id, phone_number_id, business_account_id, access_token, verify_token, phone_number, display_name, is_connected, last_verified)
                VALUES (?,?,?,?,?,?,?,?,NOW())
                ON DUPLICATE KEY UPDATE
                phone_number_id=VALUES(phone_number_id),
                business_account_id=VALUES(business_account_id),
                access_token=VALUES(access_token),
                verify_token=COALESCE(NULLIF(verify_token,\'\'), VALUES(verify_token)),
                phone_number=VALUES(phone_number),
                display_name=VALUES(display_name),
                is_connected=VALUES(is_connected),
                last_verified=VALUES(last_verified)',
                [$uid, $phoneNumberId, $businessAccountId ?: null, encryptSecret($accessToken), generateToken(32), $phoneNumber ?: null, $displayName, $connected ? 1 : 0]);

            logActivity('whatsapp_settings', $connected ? 'Connected' : 'Saved (not verified)');
            if ($connected) $msg = '✓ WhatsApp API connected successfully!';
            else $err = 'Settings saved but connection failed: ' . ($test['error']['message'] ?? 'check your Phone Number ID and token.');
        }
    }

    // ---- Profile Settings ----
    if ($section === 'profile') {
        $name    = sanitize($_POST['name'] ?? '');
        $company = sanitize($_POST['company_name'] ?? '');
        $phone   = sanitize($_POST['phone'] ?? '');
        $tz      = sanitize($_POST['timezone'] ?? 'UTC');

        db()->run('UPDATE users SET name = ? WHERE id = ?', [$name, $uid]);
        db()->run('UPDATE user_profiles SET company_name = ?, phone = ?, timezone = ? WHERE user_id = ?', [$company, $phone, $tz, $uid]);
        $msg = 'Profile updated.';
    }

    // ---- Password Change ----
    if ($section === 'password') {
        $current = $_POST['current_password'] ?? '';
        $new     = $_POST['new_password'] ?? '';
        $confirm = $_POST['confirm_password'] ?? '';

        if (!$current || !$new || !$confirm) {
            $err = 'All password fields are required.';
        } elseif ($new !== $confirm) {
            $err = 'New passwords do not match.';
        } elseif (strlen($new) < 8) {
            $err = 'Password must be at least 8 characters.';
        } else {
            $dbUser = db()->row('SELECT password FROM users WHERE id = ?', [$uid]);
            if (!verifyPassword($current, $dbUser['password'])) {
                $err = 'Current password is incorrect.';
            } else {
                db()->run('UPDATE users SET password = ? WHERE id = ?', [hashPassword($new), $uid]);
                $msg = 'Password updated successfully.';
            }
        }
    }

    // ---- Regenerate API token ----
    if ($section === 'regen_token') {
        $newToken = generateToken(64);
        db()->run('UPDATE users SET api_token = ? WHERE id = ?', [$newToken, $uid]);
        $msg = 'API token regenerated.';
    }
}

$waSettings = db()->row('SELECT * FROM user_whatsapp_settings WHERE user_id = ?', [$uid]);
$profile    = db()->row('SELECT * FROM user_profiles WHERE user_id = ?', [$uid]);
$plan       = currentPlan();

$timezones = ['UTC', 'Asia/Kolkata', 'Asia/Dubai', 'America/New_York', 'America/Los_Angeles', 'Europe/London', 'Europe/Paris', 'Australia/Sydney'];

include __DIR__ . '/../includes/user_header.php';
?>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠ <?= e($err) ?></div><?php endif; ?>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">

  <!-- WhatsApp API Card -->
  <div class="card" style="grid-column:1/-1;">
    <div class="card-header">
      <div class="card-title">
        🟢 WhatsApp Cloud API
        <?php if (!empty($waSettings['is_connected'])): ?>
          <span class="badge badge-green" style="margin-left:10px;">● Connected</span>
        <?php else: ?>
          <span class="badge badge-gray" style="margin-left:10px;">○ Not connected</span>
        <?php endif; ?>
      </div>
    </div>


    <?php if (defined('META_APP_ID') && defined('META_ES_CONFIG_ID') && META_APP_ID !== '' && META_ES_CONFIG_ID !== ''): ?>
    <div style="background:var(--accent-dim);border:1px solid var(--accent);border-radius:10px;padding:16px;margin-bottom:18px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
      <div style="flex:1;min-width:240px;">
        <div style="font-weight:800;color:#fff;margin-bottom:4px;">⚡ Connect in one click</div>
        <div style="font-size:12.5px;color:var(--text2);">Log in with Facebook, create or pick your WhatsApp Business Account and number — no tokens to copy.</div>
      </div>
      <button type="button" id="esBtn" class="btn btn-primary" onclick="startEmbeddedSignup()">Connect with Facebook</button>
      <div id="esStatus" style="width:100%;font-size:12.5px;color:var(--text2);display:none;"></div>
    </div>
    <script async defer crossorigin="anonymous" src="https://connect.facebook.net/en_US/sdk.js"></script>
    <script>
    window.fbAsyncInit = function(){ FB.init({appId:'<?= e(META_APP_ID) ?>',autoLogAppEvents:true,xfbml:false,version:'<?= e(WA_API_VERSION) ?>'}); };
    let ES_DATA = null, ES_CODE = null;
    window.addEventListener('message', function(ev){
      if (!ev.origin || !ev.origin.endsWith('facebook.com')) return;
      try { const d = JSON.parse(ev.data); if (d.type === 'WA_EMBEDDED_SIGNUP' && d.event === 'FINISH') { ES_DATA = d.data; finishES(); } } catch(e) {}
    });
    function esMsg(t, bad){ const s=document.getElementById('esStatus'); s.style.display='block'; s.style.color=bad?'var(--danger)':'var(--text2)'; s.textContent=t; }
    function startEmbeddedSignup(){
      if (typeof FB === 'undefined') { esMsg('Facebook SDK is still loading — try again in a moment.', true); return; }
      ES_DATA = null; ES_CODE = null; esMsg('Waiting for Facebook…');
      FB.login(function(r){ if (r.authResponse && r.authResponse.code) { ES_CODE = r.authResponse.code; finishES(); } else esMsg('Signup cancelled.', true); },
        {config_id:'<?= e(META_ES_CONFIG_ID) ?>', response_type:'code', override_default_response_type:true, extras:{setup:{}, featureType:'', sessionInfoVersion:'3'}});
    }
    async function finishES(){
      if (!ES_DATA || !ES_CODE) return;          // need both the session info and the code
      esMsg('Connecting your account…');
      const body = new URLSearchParams({csrf_token: window.CSRF_TOKEN, code: ES_CODE, waba_id: ES_DATA.waba_id, phone_number_id: ES_DATA.phone_number_id});
      try {
        const r = await (await fetch('/user/wa_embedded.php', {method:'POST', body})).json();
        if (r.success) { esMsg('✓ Connected ' + (r.phone||'') + ' — reloading…'); setTimeout(()=>location.reload(), 1200); }
        else esMsg('✗ ' + r.error, true);
      } catch(e) { esMsg('✗ ' + e.message, true); }
      ES_DATA = ES_CODE = null;
    }
    </script>
    <?php endif; ?>

    <div style="background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:14px;margin-bottom:18px;font-size:13px;color:var(--text2);line-height:1.7;">
      <strong style="color:var(--text);">Manual setup steps:</strong><br>
      1. Go to <a href="https://developers.facebook.com" target="_blank" style="color:var(--accent);">developers.facebook.com</a> → Create App → WhatsApp<br>
      2. Copy <strong>Phone Number ID</strong> and <strong>WhatsApp Business Account ID</strong><br>
      3. Generate a permanent <strong>System User Access Token</strong><br>
      4. Set Webhook URL in Meta: <code style="background:var(--surface3);padding:2px 7px;border-radius:4px;font-size:12px;"><?= APP_URL ?>/webhook/whatsapp.php</code><br>
      5. Use Verify Token: <code style="background:var(--surface3);padding:2px 7px;border-radius:4px;font-size:12px;"><?= e($waSettings['verify_token'] ?? '') ?></code>
    </div>

    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="section" value="whatsapp">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
        <div class="form-group">
          <label class="form-label">Phone Number ID *</label>
          <input type="text" name="phone_number_id" class="form-control" placeholder="1234567890" value="<?= e($waSettings['phone_number_id'] ?? '') ?>" required>
        </div>
        <div class="form-group">
          <label class="form-label">Business Account ID</label>
          <input type="text" name="business_account_id" class="form-control" placeholder="9876543210" value="<?= e($waSettings['business_account_id'] ?? '') ?>">
        </div>
        <div class="form-group" style="grid-column:1/-1;">
          <label class="form-label">Access Token *</label>
          <?php $hasToken = !empty($waSettings['access_token']); ?>
          <input type="password" name="access_token" class="form-control" autocomplete="off"
                 placeholder="<?= $hasToken ? '•••••••• saved — leave blank to keep the current token' : 'EAAxxxxxx…' ?>" <?= $hasToken ? '' : 'required' ?>>
        </div>
        <div class="form-group">
          <label class="form-label">WhatsApp Number (with country code)</label>
          <input type="text" name="phone_number" class="form-control" placeholder="+919876543210" value="<?= e($waSettings['phone_number'] ?? '') ?>">
        </div>
        <div class="form-group">
          <label class="form-label">Display Name</label>
          <input type="text" name="display_name" class="form-control" placeholder="Your Business Name" value="<?= e($waSettings['display_name'] ?? '') ?>">
        </div>
      </div>
      <button type="submit" class="btn btn-primary">Save & Test Connection</button>
      <?php if ($waSettings && $waSettings['last_verified']): ?>
        <span style="font-size:12px;color:var(--muted);margin-left:12px;">Last verified: <?= timeAgo($waSettings['last_verified']) ?></span>
      <?php endif; ?>
    </form>
  </div>

  <!-- Profile Card -->
  <div class="card">
    <div class="card-header"><div class="card-title">👤 Profile</div></div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="section" value="profile">
      <div class="form-group">
        <label class="form-label">Full Name</label>
        <input type="text" name="name" class="form-control" value="<?= e($user['name']) ?>" required>
      </div>
      <div class="form-group">
        <label class="form-label">Company Name</label>
        <input type="text" name="company_name" class="form-control" value="<?= e($profile['company_name'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Phone</label>
        <input type="text" name="phone" class="form-control" value="<?= e($profile['phone'] ?? '') ?>">
      </div>
      <div class="form-group">
        <label class="form-label">Timezone</label>
        <select name="timezone" class="form-control">
          <?php foreach ($timezones as $tz): ?>
          <option value="<?= $tz ?>" <?= ($profile['timezone'] ?? 'UTC') === $tz ? 'selected' : '' ?>><?= $tz ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary">Save Profile</button>
    </form>
  </div>

  <!-- Password Card -->
  <div class="card">
    <div class="card-header"><div class="card-title">🔐 Change Password</div></div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="section" value="password">
      <div class="form-group">
        <label class="form-label">Current Password</label>
        <input type="password" name="current_password" class="form-control" required>
      </div>
      <div class="form-group">
        <label class="form-label">New Password</label>
        <input type="password" name="new_password" class="form-control" required>
      </div>
      <div class="form-group">
        <label class="form-label">Confirm New Password</label>
        <input type="password" name="confirm_password" class="form-control" required>
      </div>
      <button type="submit" class="btn btn-primary">Update Password</button>
    </form>
  </div>

  <!-- Plan & API -->
  <div class="card" style="grid-column:1/-1;">
    <div class="card-header"><div class="card-title">💳 Subscription & API Access</div></div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
      <!-- Current plan -->
      <div style="background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:16px;">
        <div style="font-size:12px;color:var(--muted);margin-bottom:8px;">CURRENT PLAN</div>
        <div style="font-family:'Syne',sans-serif;font-size:24px;font-weight:800;color:var(--accent);margin-bottom:4px;"><?= ucfirst($plan['name'] ?? 'Free') ?></div>
        <div style="font-size:13px;color:var(--text2);line-height:1.7;">
          Contacts: <?= $plan['max_contacts'] === -1 ? 'Unlimited' : formatNumber($plan['max_contacts']) ?><br>
          Messages/mo: <?= $plan['max_messages'] === -1 ? 'Unlimited' : formatNumber($plan['max_messages']) ?><br>
          Team members: <?= $plan['max_team_members'] === -1 ? 'Unlimited' : $plan['max_team_members'] ?>
        </div>
        <a href="#billing" class="btn btn-primary" style="margin-top:12px;">Upgrade Plan →</a>
      </div>
      <!-- API -->
      <div style="background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:16px;">
        <div style="font-size:12px;color:var(--muted);margin-bottom:8px;">API ACCESS</div>
        <div style="font-size:13px;color:var(--text2);line-height:1.6;margin-bottom:12px;">Create API keys, see every message your software sent and its delivery status.</div>
        <a href="/user/api_keys.php" class="btn btn-secondary">🔑 Open Developer API</a>
      </div>
    </div>
  </div>

</div>

<?php include __DIR__ . '/../includes/user_footer.php'; ?>
