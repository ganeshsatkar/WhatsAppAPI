<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

requireAdmin(); // raw API tester: sends unbilled messages, super admin only
$uid = currentUser()['id'];

$waSettings = db()->row('SELECT * FROM user_whatsapp_settings WHERE user_id=?', [$uid]);
$templates  = db()->all('SELECT * FROM message_templates WHERE user_id=?', [$uid]);

$result = null;
$sentPayload = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $phone    = preg_replace('/[\s\-\(\)\+]/', '', $_POST['phone'] ?? '');
    $phone    = ltrim($phone, '0');
    if (strlen($phone) === 10) $phone = '91' . $phone;

    $tplName  = $_POST['template_name'] ?? '';
    $lang     = $_POST['language'] ?? 'en';
    $sendMode = $_POST['send_mode'] ?? 'no_components';

    $token    = (string)decryptSecret($waSettings['access_token'] ?? '');
    $phoneId  = $waSettings['phone_number_id'] ?? '';
    $url      = WA_API_BASE . '/' . $phoneId . '/messages';

    // Build payload based on mode
    $payload = [
        'messaging_product' => 'whatsapp',
        'to'                => $phone,
        'type'              => 'template',
        'template'          => [
            'name'     => $tplName,
            'language' => ['code' => $lang],
        ]
    ];

    if ($sendMode === 'with_name') {
        $payload['template']['components'] = [
            ['type' => 'body', 'parameters' => [
                ['type' => 'text', 'text' => $_POST['param1'] ?? 'Customer']
            ]]
        ];
    } elseif ($sendMode === 'no_components') {
        // Send completely empty components
        // Do nothing - no components key at all
    } elseif ($sendMode === 'empty_array') {
        $payload['template']['components'] = [];
    }

    $sentPayload = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    // Send to Meta
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $result = [
        'http_code' => $httpCode,
        'curl_error'=> $curlErr,
        'response'  => json_decode($response, true),
        'raw'       => $response,
    ];

    // If success — save to campaign log
    if ($httpCode >= 200 && $httpCode < 300 && isset($result['response']['messages'][0]['id'])) {
        $result['success'] = true;
        $result['message_id'] = $result['response']['messages'][0]['id'];
    } else {
        $result['success'] = false;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>API Direct Test — WP API</title>
<link href="https://fonts.googleapis.com/css2?family=Cabinet+Grotesk:wght@400;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{--ink:#05080d;--card:#101820;--card2:#141f2c;--border:rgba(255,255,255,0.07);--g:#25d366;--gdim:rgba(37,211,102,0.1);--red:#ff4757;--reddim:rgba(255,71,87,0.1);--blue:#4f8ef7;--text:#e2eaf5;--text2:#8a9ab8;--muted:#4a5a74;}
body{background:var(--ink);color:var(--text);font-family:'Cabinet Grotesk',sans-serif;padding:28px;font-size:14px;}
.wrap{max-width:860px;margin:0 auto;}
h1{font-size:20px;font-weight:900;color:#fff;margin-bottom:4px;}
.sub{font-size:13px;color:var(--text2);margin-bottom:24px;}
.card{background:var(--card);border:1px solid var(--border);border-radius:12px;margin-bottom:18px;}
.ch{padding:13px 18px;border-bottom:1px solid var(--border);font-weight:800;font-size:14px;color:#fff;}
.cb{padding:18px;}
label{display:block;font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.7px;margin-bottom:6px;}
input,select{width:100%;background:var(--card2);border:1px solid var(--border);border-radius:8px;padding:10px 13px;color:var(--text);font-family:'Cabinet Grotesk',sans-serif;font-size:13px;outline:none;margin-bottom:14px;}
input:focus,select:focus{border-color:var(--g);}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.btn{display:inline-flex;align-items:center;gap:7px;padding:11px 22px;border-radius:9px;font-family:'Cabinet Grotesk',sans-serif;font-size:14px;font-weight:800;cursor:pointer;border:none;transition:opacity .2s;}
.btn-g{background:var(--g);color:#000;}.btn-g:hover{opacity:.88;}
.btn-b{background:var(--blue);color:#fff;}.btn-b:hover{opacity:.88;}
.code{font-family:'JetBrains Mono',monospace;font-size:12px;background:var(--card2);border:1px solid var(--border);border-radius:8px;padding:14px;color:#7fffd4;white-space:pre-wrap;word-break:break-all;line-height:1.7;max-height:300px;overflow-y:auto;}
.success{background:rgba(37,211,102,0.08);border:1px solid rgba(37,211,102,0.3);border-radius:10px;padding:14px;color:var(--g);margin-bottom:16px;font-size:14px;}
.fail{background:var(--reddim);border:1px solid rgba(255,71,87,0.3);border-radius:10px;padding:14px;color:var(--red);margin-bottom:16px;font-size:14px;}
.mode-btn{display:flex;flex-direction:column;background:var(--card2);border:2px solid var(--border);border-radius:10px;padding:12px 14px;cursor:pointer;transition:all .2s;}
.mode-btn:hover{border-color:var(--g);}
.mode-btn input{width:auto;margin:0;}
.mode-btn.sel{border-color:var(--g);background:rgba(37,211,102,0.05);}
.mode-label{font-size:13px;font-weight:700;color:#fff;margin-bottom:3px;}
.mode-desc{font-size:11px;color:var(--text2);}
a{color:var(--g);text-decoration:none;}
</style>
</head>
<body>
<div class="wrap">
  <div style="margin-bottom:18px;"><a href="/user/campaigns.php">← Back to Campaigns</a></div>
  <h1>🔬 Direct API Test</h1>
  <p class="sub">Test exactly what gets sent to Meta WhatsApp API — see live response</p>

  <?php if ($result): ?>
  <?php if ($result['success']): ?>
  <div class="success">
    ✅ <strong>MESSAGE SENT SUCCESSFULLY!</strong><br>
    Message ID: <code style="background:rgba(37,211,102,0.1);padding:2px 8px;border-radius:4px;"><?= e($result['message_id']) ?></code><br>
    HTTP Code: <?= $result['http_code'] ?>
  </div>
  <?php else: ?>
  <div class="fail">
    ❌ <strong>FAILED</strong> — HTTP <?= $result['http_code'] ?><br>
    Error: <strong><?= e($result['response']['error']['message'] ?? 'Unknown') ?></strong><br>
    Code: <?= e($result['response']['error']['code'] ?? '—') ?><br>
    Sub: <?= e($result['response']['error']['error_subcode'] ?? '—') ?><br>
    <?php if ($result['curl_error']): ?>CURL Error: <?= e($result['curl_error']) ?><?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="ch">📤 Payload Sent to Meta</div>
    <div class="cb"><div class="code"><?= e($sentPayload) ?></div></div>
  </div>

  <div class="card">
    <div class="ch">📥 Meta API Response</div>
    <div class="cb"><div class="code"><?= e(json_encode($result['response'], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></div></div>
  </div>
  <?php endif; ?>

  <div class="card">
    <div class="ch">Send Test Message</div>
    <div class="cb">
      <form method="POST"><?= csrfField() ?>
        <div class="grid2">
          <div>
            <label>Phone Number *</label>
            <input type="text" name="phone" placeholder="9876543210 or 919876543210" value="<?= e($_POST['phone'] ?? '') ?>" required>
          </div>
          <div>
            <label>Template Name *</label>
            <select name="template_name">
              <?php foreach ($templates as $t): ?>
              <option value="<?= e($t['template_name']) ?>" <?= ($_POST['template_name']??'')===$t['template_name']?'selected':'' ?>>
                <?= e($t['template_name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="grid2">
          <div>
            <label>Language</label>
            <input type="text" name="language" value="<?= e($_POST['language'] ?? 'en') ?>" placeholder="en">
          </div>
          <div>
            <label>Parameter {{1}} value (if template has variables)</label>
            <input type="text" name="param1" placeholder="Customer name" value="<?= e($_POST['param1'] ?? 'Test Customer') ?>">
          </div>
        </div>

        <label>Send Mode — Try each one until it works</label>
        <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:18px;">
          <label class="mode-btn <?= ($_POST['send_mode']??'no_components')==='no_components'?'sel':'' ?>">
            <input type="radio" name="send_mode" value="no_components" <?= ($_POST['send_mode']??'no_components')==='no_components'?'checked':'' ?> style="display:none;">
            <div class="mode-label">Mode 1 ⭐</div>
            <div class="mode-desc">No components at all — best for templates with no variables</div>
          </label>
          <label class="mode-btn <?= ($_POST['send_mode']??'')==='with_name'?'sel':'' ?>">
            <input type="radio" name="send_mode" value="with_name" <?= ($_POST['send_mode']??'')==='with_name'?'checked':'' ?> style="display:none;">
            <div class="mode-label">Mode 2 ⭐</div>
            <div class="mode-desc">Body component with {{1}} = name — for templates with one variable</div>
          </label>
          <label class="mode-btn <?= ($_POST['send_mode']??'')==='empty_array'?'sel':'' ?>">
            <input type="radio" name="send_mode" value="empty_array" <?= ($_POST['send_mode']??'')==='empty_array'?'checked':'' ?> style="display:none;">
            <div class="mode-label">Mode 3</div>
            <div class="mode-desc">Empty components array []</div>
          </label>
        </div>

        <button type="submit" class="btn btn-g">🚀 Send Test Now</button>
      </form>
    </div>
  </div>

  <!-- Template list -->
  <div class="card">
    <div class="ch">Your Templates</div>
    <div class="cb" style="display:flex;flex-direction:column;gap:10px;">
      <?php foreach ($templates as $t): ?>
      <div style="background:var(--card2);border-radius:8px;padding:12px;">
        <div style="font-weight:700;color:#fff;margin-bottom:4px;"><?= e($t['template_name']) ?> <span style="font-size:11px;background:rgba(37,211,102,0.1);color:var(--g);padding:2px 8px;border-radius:10px;"><?= e($t['status']) ?></span></div>
        <div style="font-size:12px;color:var(--text2);line-height:1.6;font-family:'JetBrains Mono',monospace;background:var(--card);padding:8px;border-radius:6px;margin-top:6px;">
          <?php
          // Highlight variables
          $body = htmlspecialchars($t['body'] ?? '');
          $body = preg_replace('/\{\{([^}]+)\}\}/', '<span style="color:#25d366;font-weight:700;">{{$1}}</span>', $body);
          echo $body;
          ?>
        </div>
        <?php
        // Check for named variables
        preg_match_all('/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/', $t['body'] ?? '', $nm);
        if (!empty($nm[1])):
        ?>
        <div style="font-size:11px;color:#ff8c42;margin-top:6px;">
          ⚠ Has named variables: <?= implode(', ', array_map(fn($v)=>"{{$v}}", $nm[1])) ?>
          — These MUST be changed to {{1}}, {{2}} in Meta dashboard
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

</div>
<script>
// Highlight selected mode
document.querySelectorAll('.mode-btn input').forEach(r => {
  r.addEventListener('change', () => {
    document.querySelectorAll('.mode-btn').forEach(b => b.classList.remove('sel'));
    r.closest('.mode-btn').classList.add('sel');
  });
});
</script>
</body>
</html>
