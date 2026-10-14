<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/functions.php';

startSession();
if (isLoggedIn() && currentUser()) {
    redirect('/user/dashboard.php');
}

$csrf    = csrfToken();
$db_error = '';
$error   = '';
$notice  = isset($_GET['disabled']) ? 'Your account is disabled. Please contact support.' : (isset($_GET['reset']) ? 'Password updated — please sign in.' : (isset($_GET['verified']) ? 'Email verified — please sign in.' : ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pw    = (string)($_POST['password'] ?? '');

    if (!is_string($token) || !hash_equals($csrf, $token)) {
        $error = 'Invalid request. Please refresh and try again.';
    } elseif (!$email || !$pw) {
        $error = 'Please enter your email and password.';
    } elseif (loginLocked($email)) {
        $error = 'Too many failed attempts. Please wait ' . LOGIN_WINDOW_MIN . ' minutes or reset your password.';
    } else {
        $user = db()->row('SELECT * FROM users WHERE email = ? LIMIT 1', [$email]);
        if ($user && password_verify($pw, $user['password'])) {
            if (!(int)$user['is_active']) {
                $error = 'Your account is disabled. Please contact support.';
            } else {
                if (password_needs_rehash($user['password'], PASSWORD_BCRYPT, ['cost' => 12])) {
                    db()->run('UPDATE users SET password=? WHERE id=?', [hashPassword($pw), $user['id']]);
                }
                loginSucceeded($email);
                login($user);
                logActivity('login', 'Signed in', (int)$user['id']);
                redirect('/user/dashboard.php');
            }
        } else {
            loginFailed($email);
            $error = 'Invalid email or password. Please try again.';
            usleep(400000);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — WP API Pro</title>
<link href="https://fonts.googleapis.com/css2?family=Cabinet+Grotesk:wght@400;500;700;800;900&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --ink:#05080d;--ink2:#080d14;--card:#101820;--card2:#141f2c;
  --border:rgba(255,255,255,0.06);--border2:rgba(255,255,255,0.12);
  --g:#25d366;--g2:#1db954;--gdim:rgba(37,211,102,0.1);--gborder:rgba(37,211,102,0.25);
  --gold:#f5c842;--red:#ff4757;--reddim:rgba(255,71,87,0.1);
  --text:#e2eaf5;--text2:#8a9ab8;--muted:#4a5a74;
}
html,body{height:100%;}
body{font-family:'Cabinet Grotesk',sans-serif;background:var(--ink);color:var(--text);display:grid;grid-template-columns:1fr 1fr;min-height:100vh;}
.left{background:var(--ink2);border-right:1px solid var(--border);display:flex;flex-direction:column;padding:48px 52px;position:relative;overflow:hidden;gap:40px;}
.left-glow{position:absolute;top:-15%;left:-10%;width:550px;height:550px;background:radial-gradient(ellipse,rgba(37,211,102,0.08),transparent 65%);pointer-events:none;}
.left-grid{position:absolute;inset:0;background-image:linear-gradient(rgba(37,211,102,0.025) 1px,transparent 1px),linear-gradient(90deg,rgba(37,211,102,0.025) 1px,transparent 1px);background-size:52px 52px;pointer-events:none;}
.lc{position:relative;z-index:1;}
.logo{display:flex;align-items:center;gap:13px;margin-bottom:52px;}
.logo-mark{width:48px;height:48px;background:linear-gradient(135deg,var(--g),var(--g2));border-radius:14px;display:flex;align-items:center;justify-content:center;font-size:26px;box-shadow:0 0 28px rgba(37,211,102,0.3);flex-shrink:0;}
.logo-name{font-size:24px;font-weight:900;color:#fff;letter-spacing:-0.5px;}
.logo-name span{color:var(--g);}
.logo-tag{font-size:11px;color:var(--text2);margin-top:2px;}
.left h2{font-family:'Instrument Serif',serif;font-size:2.7rem;font-style:italic;color:#fff;line-height:1.1;margin-bottom:16px;letter-spacing:-0.5px;}
.left h2 span{color:var(--g);}
.left-p{font-size:15px;color:var(--text2);line-height:1.75;max-width:380px;margin-bottom:36px;}
.feats{display:flex;flex-direction:column;gap:11px;}
.feat{display:flex;align-items:center;gap:13px;background:rgba(255,255,255,0.025);border:1px solid var(--border);border-radius:12px;padding:12px 15px;}
.fi{width:33px;height:33px;border-radius:9px;background:var(--gdim);border:1px solid var(--gborder);display:flex;align-items:center;justify-content:center;font-size:15px;flex-shrink:0;}
.fb strong{display:block;font-size:13px;font-weight:700;color:#fff;margin-bottom:1px;}
.fb span{font-size:11px;color:var(--text2);}
.stats{display:flex;gap:22px;padding-top:28px;border-top:1px solid var(--border);margin-top:8px;}
.sn{font-family:'Instrument Serif',serif;font-size:1.5rem;font-style:italic;background:linear-gradient(135deg,var(--g),#7fffd4);-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;line-height:1;}
.sl{font-size:11px;color:var(--text2);margin-top:3px;}
.support{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:14px 16px;display:flex;align-items:center;gap:12px;margin-top:auto;}
.sup-ic{width:36px;height:36px;border-radius:9px;background:var(--gdim);border:1px solid var(--gborder);display:flex;align-items:center;justify-content:center;font-size:17px;flex-shrink:0;}
.sup-txt{font-size:12px;color:var(--text2);line-height:1.7;}
.sup-txt strong{color:#fff;display:block;margin-bottom:3px;font-size:13px;}
.sup-txt a{color:var(--g);text-decoration:none;}
.right{display:flex;align-items:center;justify-content:center;padding:60px 50px;}
.fb-box{width:100%;max-width:400px;}
.back{display:flex;align-items:center;gap:6px;font-size:13px;color:var(--text2);text-decoration:none;margin-bottom:28px;transition:color .2s;}
.back:hover{color:var(--g);}
.fh h3{font-family:'Instrument Serif',serif;font-size:2.2rem;font-style:italic;color:#fff;margin-bottom:6px;letter-spacing:-0.3px;}
.fh p{font-size:14px;color:var(--text2);margin-bottom:28px;}
.pulse{display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--g);animation:blink 2s infinite;margin-right:5px;vertical-align:middle;}
@keyframes blink{0%,100%{opacity:1;}50%{opacity:0.3;}}
.dberr{background:var(--reddim);border:1px solid rgba(255,71,87,0.3);border-radius:10px;padding:12px 16px;font-size:13px;color:var(--red);margin-bottom:18px;}
.ferr{background:var(--reddim);border:1px solid rgba(255,71,87,0.3);border-radius:10px;padding:12px 16px;font-size:13px;color:var(--red);margin-bottom:18px;}
.fg{margin-bottom:18px;}
.fg label{display:block;font-size:11px;font-weight:700;color:var(--text2);margin-bottom:7px;letter-spacing:.8px;text-transform:uppercase;}
.iw{position:relative;}
.ii{position:absolute;left:14px;top:50%;transform:translateY(-50%);font-size:14px;pointer-events:none;}
.fg input{width:100%;background:var(--card);border:1.5px solid var(--border2);border-radius:10px;padding:13px 44px 13px 42px;color:var(--text);font-family:'Cabinet Grotesk',sans-serif;font-size:14px;outline:none;transition:border-color .2s,box-shadow .2s;}
.fg input:focus{border-color:var(--g);box-shadow:0 0 0 3px rgba(37,211,102,0.1);}
.fg input::placeholder{color:var(--muted);}
.pw-toggle{position:absolute;right:14px;top:50%;transform:translateY(-50%);cursor:pointer;font-size:16px;color:var(--muted);background:none;border:none;padding:0;}
.opts{display:flex;justify-content:space-between;align-items:center;margin-bottom:22px;}
.rem{display:flex;align-items:center;gap:7px;font-size:13px;color:var(--text2);cursor:pointer;}
.rem input{accent-color:var(--g);width:14px;height:14px;}
.fl{font-size:13px;color:var(--g);text-decoration:none;font-weight:600;}
.fl:hover{text-decoration:underline;}
.btn-login{width:100%;background:linear-gradient(135deg,var(--g),var(--g2));border:none;border-radius:11px;padding:15px;color:#000;font-family:'Cabinet Grotesk',sans-serif;font-size:15px;font-weight:900;cursor:pointer;transition:all .2s;box-shadow:0 0 24px rgba(37,211,102,0.2);}
.btn-login:hover{transform:translateY(-1px);box-shadow:0 0 40px rgba(37,211,102,0.35);}
.div{display:flex;align-items:center;gap:12px;margin:22px 0;color:var(--muted);font-size:12px;}
.div::before,.div::after{content:'';flex:1;height:1px;background:var(--border);}
.reg{text-align:center;font-size:13.5px;color:var(--text2);}
.reg a{color:var(--g);text-decoration:none;font-weight:700;}
.reset-box{margin-top:20px;background:var(--card);border:1px solid var(--border);border-radius:10px;padding:14px 16px;font-size:12.5px;color:var(--text2);line-height:1.75;}
.reset-box strong{color:#fff;display:block;margin-bottom:4px;font-size:13px;}
.reset-box a{color:var(--g);text-decoration:none;}
.plans{display:flex;gap:6px;flex-wrap:wrap;justify-content:center;margin-top:16px;}
.pc{background:var(--card);border:1px solid var(--border);border-radius:20px;padding:4px 12px;font-size:11px;color:var(--text2);}
.pc b{color:var(--g);}
@media(max-width:768px){body{grid-template-columns:1fr;}.left{display:none;}.right{padding:40px 24px;}}
</style>
<?php include __DIR__ . '/includes/pwa_head.php'; ?>
</head>
<body>

<div class="left">
  <div class="left-glow"></div><div class="left-grid"></div>
  <div class="lc">
    <div class="logo">
      <div class="logo-mark">💬</div>
      <div><div class="logo-name">WP API <span>Pro</span></div><div class="logo-tag">WhatsApp Business Platform</div></div>
    </div>
    <h2>Automate Your<br><span>WhatsApp</span><br>Business Today</h2>
    <p class="left-p">Send bulk campaigns, build chatbots, manage your shared inbox, and track every lead — all in one platform.</p>
    <div class="feats">
      <div class="feat"><div class="fi">📢</div><div class="fb"><strong>Bulk Broadcast Campaigns</strong><span>Reach thousands in one click with personalised messages</span></div></div>
      <div class="feat"><div class="fi">🤖</div><div class="fb"><strong>Visual Chatbot Builder</strong><span>Drag-and-drop flows, automate replies 24/7, no code</span></div></div>
      <div class="feat"><div class="fi">📱</div><div class="fb"><strong>QR Lead Generation</strong><span>Track scans, assign to salespersons, measure ROI</span></div></div>
      <div class="feat"><div class="fi">📊</div><div class="fb"><strong>Real-time Analytics</strong><span>Delivery rates, read rates, CTR in one dashboard</span></div></div>
    </div>
    <div class="stats">
      <div><div class="sn">7 days</div><div class="sl">Free trial</div></div>
      <div><div class="sn">₹0</div><div class="sl">Setup fee</div></div>
      <div><div class="sn">24/7</div><div class="sl">Chatbots</div></div>
      <div><div class="sn">Official</div><div class="sl">Meta Cloud API</div></div>
    </div>
  </div>
  <div class="support lc">
    <div class="sup-ic">🎧</div>
    <div class="sup-txt">
      <strong>24/7 Support — GRDP InfoTech</strong>
      📧 <a href="mailto:<?= e(SUPPORT_EMAIL) ?>"><?= e(SUPPORT_EMAIL) ?></a> &nbsp;·&nbsp; 📞 <a href="tel:<?= e(SUPPORT_PHONE) ?>"><?= e(SUPPORT_PHONE) ?></a>
    </div>
  </div>
</div>

<div class="right">
  <div class="fb-box">
    <a href="/" class="back">← Back to Home</a>
    <div class="fh">
      <h3>Welcome Back</h3>
      <p><span class="pulse"></span>Sign in to your WP API Pro account</p>
    </div>

    <?php if ($notice): ?><div class="dberr" style="color:var(--g);background:var(--gdim);border-color:var(--gborder);">✓ <?= e($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="ferr">⚠ <?= htmlspecialchars($error) ?></div><?php endif; ?>

    <form method="POST" id="lf">
      <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf) ?>">
      <div class="fg">
        <label>Email Address</label>
        <div class="iw">
          <span class="ii">✉️</span>
          <input type="email" name="email" placeholder="you@company.com"
                 value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required autofocus autocomplete="email">
        </div>
      </div>
      <div class="fg">
        <label>Password</label>
        <div class="iw">
          <span class="ii">🔐</span>
          <input type="password" name="password" id="pwf" placeholder="••••••••" required autocomplete="current-password">
          <button type="button" class="pw-toggle" id="ptb" onclick="togglePw()">👁</button>
        </div>
      </div>
      <div class="opts">
        <span></span>
        <a href="/forgot-password.php" class="fl">Forgot password?</a>
      </div>
      <button type="submit" class="btn-login" id="lb">Sign In →</button>
    </form>

    <div class="div">or</div>
    <div class="reg">New to WP API Pro? <a href="/register.php">Create free account →</a></div>

    <div class="reset-box">
      <strong>🔑 Password Reset / Support</strong>
      Forgot your password? <a href="/forgot-password.php">Reset it here</a>, or email <a href="mailto:<?= e(SUPPORT_EMAIL) ?>"><?= e(SUPPORT_EMAIL) ?></a>
      or call <a href="tel:<?= e(SUPPORT_PHONE) ?>">📞 <?= e(SUPPORT_PHONE) ?></a><br>
    </div>

    <div class="plans">
      <div class="pc">7-day Trial <b>Free</b></div>
      <div class="pc">Basic <b>₹599/mo</b></div>
      <div class="pc">Platform <b>₹1,199/mo</b></div>
    </div>
    <div style="text-align:center;margin-top:18px;font-size:12px;">
      <a href="/privacy.php" style="color:var(--text2);text-decoration:none;margin:0 6px;">Privacy</a>·
      <a href="/terms.php" style="color:var(--text2);text-decoration:none;margin:0 6px;">Terms</a>·
      <a href="/refund.php" style="color:var(--text2);text-decoration:none;margin:0 6px;">Refunds</a>·
      <a href="/contact.php" style="color:var(--text2);text-decoration:none;margin:0 6px;">Contact</a>
    </div>
  </div>
</div>

<script>
function togglePw(){
  const f=document.getElementById('pwf'),b=document.getElementById('ptb');
  f.type=f.type==='password'?'text':'password';
  b.textContent=f.type==='password'?'👁':'🙈';
}
document.getElementById('lf').addEventListener('submit',function(){
  const b=document.getElementById('lb');
  b.textContent='Signing in…';b.style.opacity='.8';b.disabled=true;
});
</script>
<?php include __DIR__ . '/includes/pwa_footer.php'; ?>
</body>
</html>