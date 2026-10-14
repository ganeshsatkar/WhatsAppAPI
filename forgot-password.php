<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

startSession();
$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $email = strtolower(sanitize($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $err = 'Please enter a valid email address.';
    } elseif (loginLocked('reset:' . $email)) {
        $err = 'Too many requests. Please try again in ' . LOGIN_WINDOW_MIN . ' minutes.';
    } else {
        loginFailed('reset:' . $email); // throttle reset mails per address
        $user = db()->row('SELECT * FROM users WHERE email = ? AND is_active = 1', [$email]);
        if ($user) {
            $token   = generateToken(64);
            $expires = gmdate('Y-m-d H:i:s', time() + 3600);
            db()->run('UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?', [hash('sha256', $token), $expires, $user['id']]);
            $link = APP_URL . '/reset-password.php?token=' . $token;
            sendMail($email, 'Reset your password — ' . APP_NAME, mailLayout('Password reset',
                '<p>Hi ' . e($user['name']) . ', we received a request to reset your password. This link is valid for 1 hour.</p><p>If you did not request this, you can ignore this email.</p>',
                'Reset Password', $link));
            logActivity('password_reset_requested', 'For: ' . $email, (int)$user['id']);
        }
        // Always show success (don't leak user existence)
        $msg = 'If that email exists, a reset link has been sent.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Forgot Password — <?= APP_NAME ?></title>
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
label { display:block;font-size:12px;font-weight:500;letter-spacing:0.8px;text-transform:uppercase;color:var(--muted);margin-bottom:8px; }
input { width:100%;background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:13px 15px;color:var(--text);font-family:'DM Sans',sans-serif;font-size:15px;outline:none;transition:border-color 0.2s; }
input:focus { border-color:var(--accent);box-shadow:0 0 0 3px var(--glow); }
.btn { width:100%;background:linear-gradient(135deg,var(--accent),#128c7e);border:none;border-radius:10px;padding:14px;color:#fff;font-family:'Syne',sans-serif;font-size:15px;font-weight:700;cursor:pointer;margin-top:18px; }
.alert-s { background:rgba(37,211,102,0.1);border:1px solid rgba(37,211,102,0.3);border-radius:8px;padding:11px;color:var(--accent);font-size:14px;margin-bottom:18px; }
.alert-e { background:rgba(255,77,109,0.1);border:1px solid rgba(255,77,109,0.3);border-radius:8px;padding:11px;color:var(--danger);font-size:14px;margin-bottom:18px; }
a { color:var(--accent);text-decoration:none;font-size:14px; }
</style>
</head>
<body>
<div class="card">
  <div class="logo">Whats<span>Flow</span> Pro</div>
  <h2>Reset Password</h2>
  <p class="sub">Enter your email and we'll send a reset link.</p>
  <?php if ($msg): ?><div class="alert-s">✓ <?= e($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="alert-e">⚠ <?= e($err) ?></div><?php endif; ?>
  <?php if (!$msg): ?>
  <form method="POST">
    <?= csrfField() ?>
    <label>Email Address</label>
    <input type="email" name="email" placeholder="you@company.com" required autofocus>
    <button type="submit" class="btn">Send Reset Link</button>
  </form>
  <?php endif; ?>
  <div style="text-align:center;margin-top:20px;"><a href="/login.php">← Back to Login</a></div>
</div>
</body>
</html>
