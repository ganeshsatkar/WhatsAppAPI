<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

startSession();
$token = preg_replace('/[^a-f0-9]/', '', (string)($_GET['token'] ?? $_POST['token'] ?? ''));
$user  = strlen($token) === 64
    ? db()->row('SELECT id, email FROM users WHERE reset_token = ? AND reset_expires > UTC_TIMESTAMP() AND is_active = 1', [hash('sha256', $token)])
    : null;
$err = '';

if ($user && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $pw  = (string)($_POST['password'] ?? '');
    $pw2 = (string)($_POST['password_confirm'] ?? '');
    if (strlen($pw) < 8 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
        $err = 'Password must be at least 8 characters and contain letters and numbers.';
    } elseif ($pw !== $pw2) {
        $err = 'Passwords do not match.';
    } else {
        db()->run('UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?', [hashPassword($pw), $user['id']]);
        loginSucceeded($user['email']);
        logActivity('password_reset', 'Password changed via reset link', (int)$user['id']);
        redirect('/login.php?reset=1');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Set New Password — <?= e(APP_NAME) ?></title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@700;800&family=DM+Sans:wght@400;500&display=swap" rel="stylesheet">
<style>
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root { --bg:#080b12;--surface:#0e1420;--surface2:#151d2e;--border:rgba(255,255,255,0.07);--accent:#25d366;--text:#f0f4ff;--muted:#6b7a99;--danger:#ff4d6d;--glow:rgba(37,211,102,0.15); }
body { min-height:100vh;background:var(--bg);color:var(--text);font-family:'DM Sans',sans-serif;display:flex;align-items:center;justify-content:center;padding:20px; }
.card { width:100%;max-width:400px;background:var(--surface);border:1px solid var(--border);border-radius:20px;padding:44px; }
.logo { font-family:'Syne',sans-serif;font-weight:800;font-size:18px;margin-bottom:28px;text-align:center; }
.logo span { color:var(--accent); }
h2 { font-family:'Syne',sans-serif;font-size:24px;font-weight:700;margin-bottom:6px; }
.sub { color:var(--muted);font-size:14px;margin-bottom:28px; }
label { display:block;font-size:12px;font-weight:500;letter-spacing:0.8px;text-transform:uppercase;color:var(--muted);margin:14px 0 8px; }
input { width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:13px 15px;color:var(--text);font-family:'DM Sans',sans-serif;font-size:15px;outline:none; }
input:focus { border-color:var(--accent);box-shadow:0 0 0 3px var(--glow); }
.btn { width:100%;background:linear-gradient(135deg,var(--accent),#128c7e);border:none;border-radius:10px;padding:14px;color:#fff;font-family:'Syne',sans-serif;font-size:15px;font-weight:700;cursor:pointer;margin-top:20px; }
.alert-e { background:rgba(255,77,109,0.1);border:1px solid rgba(255,77,109,0.3);border-radius:8px;padding:11px;color:var(--danger);font-size:14px;margin-bottom:18px; }
a { color:var(--accent);text-decoration:none;font-size:14px; }
</style>
<?php include __DIR__ . '/includes/pwa_head.php'; ?>
</head>
<body>
<div class="card">
  <div class="logo">WP API <span>Pro</span></div>
  <?php if (!$user): ?>
    <h2>Link expired</h2>
    <p class="sub">This reset link is invalid or has expired. Please request a new one.</p>
    <a href="/forgot-password.php">Request new link →</a>
  <?php else: ?>
    <h2>Set new password</h2>
    <p class="sub">For <?= e($user['email']) ?></p>
    <?php if ($err): ?><div class="alert-e">⚠ <?= e($err) ?></div><?php endif; ?>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="token" value="<?= e($token) ?>">
      <label>New Password</label>
      <input type="password" name="password" required minlength="8" autocomplete="new-password" autofocus>
      <label>Confirm Password</label>
      <input type="password" name="password_confirm" required minlength="8" autocomplete="new-password">
      <button type="submit" class="btn">Update Password</button>
    </form>
  <?php endif; ?>
  <div style="text-align:center;margin-top:20px;"><a href="/login.php">← Back to Login</a></div>
</div>
<?php include __DIR__ . '/includes/pwa_footer.php'; ?>
</body>
</html>
