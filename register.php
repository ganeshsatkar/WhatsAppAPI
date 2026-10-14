<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

startSession();
if (isLoggedIn()) redirect('/user/dashboard.php');

$error = $success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $name         = mb_substr(sanitize($_POST['name'] ?? ''), 0, 150);
    $email        = strtolower(sanitize($_POST['email'] ?? ''));
    $password     = (string)($_POST['password'] ?? '');
    $company_name = mb_substr(sanitize($_POST['company_name'] ?? ''), 0, 200);
    $phone        = normalizePhone($_POST['phone'] ?? '');

    if (!$name || !$email || !$password || !$company_name) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif (strlen($password) < 8 || !preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) {
        $error = 'Password must be at least 8 characters and contain letters and numbers.';
    } elseif (loginLocked('register:' . clientIp())) {
        $error = 'Too many sign-ups from your network. Please try again later.';
    } else {
        $exists = db()->row('SELECT id FROM users WHERE email = ?', [$email]);
        if ($exists) {
            $error = 'An account with this email already exists.';
        } else {
            $hashed     = hashPassword($password);
            $apiToken   = generateToken(64);
            $verifyTok  = generateToken(32);

            // New accounts start WITHOUT an active plan: the owner activates a trial / plan from the admin panel (or the user buys one from wallet).
            $trialId = (int)(db()->row("SELECT id FROM plans WHERE slug='trial'")['id'] ?? 1);
            $userId = db()->insert(
                'INSERT INTO users (name, email, password, role, plan_id, api_token, verify_token) VALUES (?,?,?,?,?,?,?)',
                [$name, $email, $hashed, 'user', $trialId, $apiToken, $verifyTok]
            );

            db()->run('INSERT INTO user_profiles (user_id, company_name, phone, timezone) VALUES (?,?,?,?)', [$userId, $company_name, $phone ?: null, APP_TIMEZONE]);
            db()->run('INSERT INTO user_whatsapp_settings (user_id, verify_token) VALUES (?,?)', [$userId, generateToken(32)]);
            db()->run('INSERT IGNORE INTO wallets (user_id, balance) VALUES (?,0)', [$userId]);
            loginFailed('register:' . clientIp());   // counts sign-ups per IP for throttling

            $link = APP_URL . '/verify-email.php?token=' . $verifyTok;
            sendMail($email, 'Verify your email — ' . APP_NAME, mailLayout('Welcome, ' . $name . '!',
                '<p>Thanks for signing up. Please confirm your email address.</p>', 'Verify Email', $link));

            logActivity('register', 'New user registered: ' . $email, (int)$userId);
            $success = 'Account created! We sent a verification link to ' . e($email) . '. You can <a href="/login.php">sign in</a> now.';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Register — <?= APP_NAME ?></title>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg: #080b12; --surface: #0e1420; --surface2: #151d2e;
    --border: rgba(255,255,255,0.07); --accent: #25d366; --accent2: #128c7e;
    --text: #f0f4ff; --muted: #6b7a99; --danger: #ff4d6d;
    --success: #25d366; --glow: rgba(37,211,102,0.15);
  }
  body { min-height: 100vh; background: var(--bg); color: var(--text); font-family: 'DM Sans', sans-serif; display: flex; align-items: center; justify-content: center; padding: 40px 20px; }
  .card { width: 100%; max-width: 520px; background: var(--surface); border: 1px solid var(--border); border-radius: 20px; padding: 48px 48px; }
  .logo { display: flex; align-items: center; gap: 10px; margin-bottom: 32px; }
  .logo-icon { width: 38px; height: 38px; background: linear-gradient(135deg, var(--accent), var(--accent2)); border-radius: 10px; display: flex; align-items: center; justify-content: center; font-size: 18px; }
  .logo-text { font-family: 'Syne', sans-serif; font-size: 18px; font-weight: 800; }
  .logo-text span { color: var(--accent); }
  h2 { font-family: 'Syne', sans-serif; font-size: 26px; font-weight: 700; margin-bottom: 4px; }
  .subtitle { color: var(--muted); font-size: 14px; margin-bottom: 32px; }
  .grid2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
  .form-group { margin-bottom: 18px; }
  label { display: block; font-size: 12px; font-weight: 500; letter-spacing: 0.8px; text-transform: uppercase; color: var(--muted); margin-bottom: 7px; }
  input { width: 100%; background: var(--surface2); border: 1px solid var(--border); border-radius: 10px; padding: 13px 15px; color: var(--text); font-family: 'DM Sans', sans-serif; font-size: 15px; outline: none; transition: border-color 0.2s, box-shadow 0.2s; }
  input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px var(--glow); }
  input::placeholder { color: var(--muted); }
  .btn { width: 100%; background: linear-gradient(135deg, var(--accent), var(--accent2)); border: none; border-radius: 10px; padding: 15px; color: #fff; font-family: 'Syne', sans-serif; font-size: 15px; font-weight: 700; cursor: pointer; transition: opacity 0.2s; margin-top: 8px; }
  .btn:hover { opacity: 0.9; }
  .error-box { background: rgba(255,77,109,0.1); border: 1px solid rgba(255,77,109,0.3); border-radius: 10px; padding: 12px 16px; color: var(--danger); font-size: 14px; margin-bottom: 20px; }
  .success-box { background: rgba(37,211,102,0.1); border: 1px solid rgba(37,211,102,0.3); border-radius: 10px; padding: 12px 16px; color: var(--success); font-size: 14px; margin-bottom: 20px; }
  .success-box a { color: var(--accent); }
  .login-row { text-align: center; margin-top: 24px; color: var(--muted); font-size: 14px; }
  .link { color: var(--accent); text-decoration: none; }
  .link:hover { text-decoration: underline; }
  .plan-badge { display: inline-block; background: rgba(37,211,102,0.1); border: 1px solid rgba(37,211,102,0.3); color: var(--accent); font-size: 12px; padding: 3px 10px; border-radius: 20px; margin-bottom: 24px; }
  @media (max-width: 480px) { .card { padding: 32px 24px; } .grid2 { grid-template-columns: 1fr; } }
</style>
</head>
<body>
<div class="card">
  <div class="logo">
    <div class="logo-icon">💬</div>
    <div class="logo-text">Whats<span>Flow</span> Pro</div>
  </div>

  <?php if ($success): ?>
    <div class="success-box">✓ <?= $success ?></div>
  <?php else: ?>

  <h2>Create your account</h2>
  <p class="subtitle">Start free — no credit card required</p>
  <span class="plan-badge">✦ Plans from ₹599/month · plan activated after sign-up</span>

  <?php if ($error): ?>
  <div class="error-box">⚠ <?= e($error) ?></div>
  <?php endif; ?>

  <form method="POST">
    <?= csrfField() ?>
    <div class="grid2">
      <div class="form-group">
        <label>Full Name *</label>
        <input type="text" name="name" placeholder="John Doe" value="<?= e($_POST['name'] ?? '') ?>" required>
      </div>
      <div class="form-group">
        <label>Company Name *</label>
        <input type="text" name="company_name" placeholder="Acme Corp" value="<?= e($_POST['company_name'] ?? '') ?>" required>
      </div>
    </div>
    <div class="form-group">
      <label>Email Address *</label>
      <input type="email" name="email" placeholder="you@company.com" value="<?= e($_POST['email'] ?? '') ?>" required>
    </div>
    <div class="form-group">
      <label>Phone Number</label>
      <input type="tel" name="phone" placeholder="+91 98765 43210" value="<?= e($_POST['phone'] ?? '') ?>">
    </div>
    <div class="form-group">
      <label>Password *</label>
      <input type="password" name="password" placeholder="Min. 8 characters" required>
    </div>
    <button type="submit" class="btn">Create Free Account →</button>
  </form>

  <?php endif; ?>

  <div class="login-row">
    Already have an account? <a href="/login.php" class="link">Sign in</a>
  </div>
</div>
</body>
</html>
