<?php
// Shared layout for public legal pages. Usage:
//   legalStart('Privacy Policy'); ...html... legalEnd();
function legalStart(string $title): void {
    $t = e($title); $app = e(APP_NAME);
    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title>{$t} — {$app}</title>
<meta name="description" content="{$t} of {$app}, a WhatsApp Business messaging platform by GRDP InfoTech.">
HTML;
    include __DIR__ . '/pwa_head.php';
    echo <<<HTML
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{--bg:#060810;--card:#0e1220;--border:rgba(255,255,255,0.08);--g:#25d366;--text:#e8edf8;--text2:#a7b3c9;--muted:#6b7a99}
body{background:var(--bg);color:var(--text);font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;line-height:1.7;font-size:15px}
a{color:var(--g)}
.top{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 5%;border-bottom:1px solid var(--border);position:sticky;top:0;background:rgba(6,8,16,0.95);backdrop-filter:blur(8px);z-index:5}
.logo{display:flex;align-items:center;gap:10px;color:#fff;text-decoration:none;font-weight:900;font-size:17px}
.logo img{width:30px;height:30px;border-radius:8px}
.logo span{color:var(--g)}
.top nav a{color:var(--text2);text-decoration:none;font-size:14px;margin-left:16px}
.wrap{max-width:820px;margin:0 auto;padding:36px 5% 60px}
h1{font-size:30px;line-height:1.25;margin-bottom:6px}
.upd{color:var(--muted);font-size:13px;margin-bottom:28px}
h2{font-size:19px;margin:30px 0 10px;color:#fff}
h3{font-size:16px;margin:18px 0 6px;color:#fff}
p,li{color:var(--text2)}
p{margin-bottom:12px}
ul{margin:0 0 14px 20px}
li{margin-bottom:6px}
.box{background:var(--card);border:1px solid var(--border);border-radius:12px;padding:18px 20px;margin:16px 0}
.box strong{color:#fff}
footer{border-top:1px solid var(--border);padding:22px 5%;text-align:center;color:var(--muted);font-size:13px}
footer a{color:var(--text2);margin:0 8px;text-decoration:none;display:inline-block;padding:4px 0}
@media(max-width:600px){h1{font-size:24px}.top nav a.hide-sm{display:none}}
</style>
</head>
<body>
<header class="top">
  <a class="logo" href="/"><img src="/assets/icons/icon-192.png" alt="">WP API <span>Pro</span></a>
  <nav><a href="/" class="hide-sm">Home</a><a href="/login.php">Login</a></nav>
</header>
<main class="wrap">
<h1>{$t}</h1>
HTML;
    echo '<div class="upd">Last updated: ' . e(LEGAL_UPDATED) . '</div>';
}

function legalEnd(): void {
    $y = date('Y'); $co = e(LEGAL_COMPANY);
    echo <<<HTML
</main>
<footer>
  <div><a href="/privacy.php">Privacy Policy</a><a href="/terms.php">Terms of Service</a><a href="/refund.php">Refund &amp; Cancellation</a><a href="/shipping.php">Service Delivery</a><a href="/contact.php">Contact</a></div>
  <div style="margin-top:8px;">© {$y} WP API Pro by {$co}</div>
</footer>
HTML;
    include __DIR__ . '/pwa_footer.php';
    echo "</body>\n</html>\n";
}

/** Company identity block used on several pages. */
function legalCompanyBlock(): string {
    $h = '<strong>' . e(LEGAL_COMPANY) . '</strong> (operator of WP API Pro)<br>';
    if (LEGAL_ADDRESS !== '') $h .= nl2br(e(LEGAL_ADDRESS)) . '<br>';
    if (LEGAL_GSTIN !== '')   $h .= 'GSTIN: ' . e(LEGAL_GSTIN) . '<br>';
    $h .= 'Email: <a href="mailto:' . e(SUPPORT_EMAIL) . '">' . e(SUPPORT_EMAIL) . '</a>';
    if (SUPPORT_PHONE !== '') $h .= '<br>Phone / WhatsApp: <a href="tel:+91' . e(preg_replace('/\D/', '', SUPPORT_PHONE)) . '">+91 ' . e(SUPPORT_PHONE) . '</a>';
    return $h;
}
