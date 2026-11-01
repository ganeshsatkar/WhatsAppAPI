<?php
// ============================================================
// WP API - Unified Dashboard Layout
// ============================================================
$user        = currentUser();
$plan        = currentPlan();
require_once __DIR__ . '/wallet.php';
$wBal        = walletBalance((int)$user['id']);
$unread      = db()->count('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND is_read = 0', [$user['id']]);
$openChats   = db()->count('SELECT COUNT(*) FROM conversations WHERE user_id = ? AND status = ?', [$user['id'], 'open']);
$currentPath = basename($_SERVER['PHP_SELF']);
$isSuperAdmin = ($user['role'] === 'superadmin');
$subState    = subscriptionState((int)$user['id']);

$nav = [
    ['icon'=>'⬡',  'label'=>'Dashboard',  'href'=>'/user/dashboard.php'],
    ['icon'=>'👥',  'label'=>'Contacts',   'href'=>'/user/contacts.php'],
    ['icon'=>'🗂️',  'label'=>'Lists & Tags', 'href'=>'/user/segments.php'],
    ['icon'=>'📋',  'label'=>'Templates',  'href'=>'/user/templates.php'],
    ['icon'=>'📢',  'label'=>'Campaigns',  'href'=>'/user/campaigns.php'],
    ['icon'=>'📤',  'label'=>'Outbound Logs', 'href'=>'/user/outbound.php'],
    ['icon'=>'💬',  'label'=>'Inbox',      'href'=>'/user/inbox.php', 'badge'=>$openChats],
    ['icon'=>'🤖',  'label'=>'Chatbots',   'href'=>'/user/chatbots.php'],
    ['icon'=>'⏰',  'label'=>'Auto-replies', 'href'=>'/user/auto_replies.php'],
    ['icon'=>'⚡',  'label'=>'Automation', 'href'=>'/user/automation.php'],
    ['icon'=>'📱',  'label'=>'QR Codes',   'href'=>'/user/qr.php'],
    ['icon'=>'📡',  'label'=>'API Messages', 'href'=>'/user/api_logs.php'],
    ['icon'=>'🔑',  'label'=>'Developer API','href'=>'/user/api_keys.php'],
    ['icon'=>'💰',  'label'=>'Wallet',      'href'=>'/user/wallet.php'],
    ['icon'=>'📊',  'label'=>'Analytics',  'href'=>'/user/analytics.php'],
    ['icon'=>'💳',  'label'=>'Billing & Plan', 'href'=>'/user/billing.php'],
    ['icon'=>'🔔',  'label'=>'Notifications', 'href'=>'/user/notifications.php', 'badge'=>$unread],
    ['icon'=>'⚙️',  'label'=>'Settings',   'href'=>'/user/settings.php'],
];

$adminNav = [
    ['icon'=>'🏢',  'label'=>'All Users',       'href'=>'/user/admin_users.php'],
    ['icon'=>'💳',  'label'=>'Plans',            'href'=>'/user/admin_plans.php'],
    ['icon'=>'📋',  'label'=>'Subscriptions',    'href'=>'/user/admin_subscriptions.php'],
    ['icon'=>'💰',  'label'=>'Payments',         'href'=>'/user/admin_payments.php'],
    ['icon'=>'📊',  'label'=>'Platform Stats',   'href'=>'/user/admin_analytics.php'],
    ['icon'=>'💰',  'label'=>'Wallet Mgmt',      'href'=>'/user/admin_wallet.php'],
    ['icon'=>'📈',  'label'=>'Profit & Meta Cost','href'=>'/user/admin_profit.php'],
    ['icon'=>'📝',  'label'=>'Activity Logs',    'href'=>'/user/admin_logs.php'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
<title><?= e($pageTitle ?? 'Dashboard') ?> — <?= e(APP_NAME) ?></title>
<?php include __DIR__ . '/pwa_head.php'; ?>
<link href="https://fonts.googleapis.com/css2?family=Cabinet+Grotesk:wght@400;500;700;800;900&family=Instrument+Serif:ital@0;1&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --bg:#060810;--sidebar:#080d14;--surface:#0e1220;--surface2:#141928;--surface3:#1c2538;
  --border:rgba(255,255,255,0.06);--border2:rgba(255,255,255,0.1);
  --accent:#25d366;--accent2:#1db954;--accent-dim:rgba(37,211,102,0.1);
  --blue:#4f8ef7;--blue-dim:rgba(79,142,247,0.12);
  --orange:#ff8c42;--orange-dim:rgba(255,140,66,0.12);
  --purple:#9b7cf4;--purple-dim:rgba(155,124,244,0.12);
  --gold:#f5c842;--gold-dim:rgba(245,200,66,0.1);
  --text:#e8edf8;--text2:#9ba8c0;--muted:#5a6480;
  --danger:#ff4d6d;--danger-dim:rgba(255,77,109,0.12);
  --radius:12px;--sw:230px;
}
body{min-height:100vh;background:var(--bg);color:var(--text);font-family:'Cabinet Grotesk',sans-serif;display:flex;font-size:14px;}
::-webkit-scrollbar{width:5px;}::-webkit-scrollbar-thumb{background:var(--surface3);border-radius:3px;}

/* SIDEBAR */
.sidebar{width:var(--sw);background:var(--sidebar);border-right:1px solid var(--border);display:flex;flex-direction:column;position:fixed;left:0;top:0;bottom:0;z-index:100;overflow-y:auto;}
.sb-logo{padding:18px 16px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;text-decoration:none;}
.sb-logo-mark{width:32px;height:32px;background:linear-gradient(135deg,var(--accent),var(--accent2));border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0;box-shadow:0 0 16px rgba(37,211,102,0.2);}
.sb-logo-text{font-size:16px;font-weight:900;color:#fff;letter-spacing:-0.3px;}
.sb-logo-text span{color:var(--accent);}
.sb-user{padding:12px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:9px;}
.sb-av{width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#000;flex-shrink:0;}
.sb-name{font-size:12.5px;font-weight:700;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.sb-role{font-size:10.5px;color:var(--muted);}
.sb-nav{flex:1;padding:10px 8px;}
.sb-section{font-size:10px;font-weight:800;color:var(--muted);letter-spacing:1.2px;text-transform:uppercase;padding:12px 10px 5px;}
.sb-item{display:flex;align-items:center;gap:9px;padding:8px 10px;border-radius:9px;color:var(--text2);text-decoration:none;font-size:13px;font-weight:500;transition:all .15s;margin-bottom:1px;}
.sb-item:hover{background:var(--surface);color:var(--text);}
.sb-item.active{background:var(--accent-dim);color:var(--accent);font-weight:700;}
.sb-item.admin-link{border-left:2px solid rgba(155,124,244,0.35);margin-left:4px;}
.sb-item.admin-link:hover{background:var(--purple-dim);color:var(--purple);}
.sb-item.admin-link.active{background:var(--purple-dim);color:var(--purple);}
.sb-icon{font-size:14px;width:18px;text-align:center;flex-shrink:0;}
.sb-badge{margin-left:auto;background:var(--danger);color:#fff;font-size:10px;font-weight:700;padding:2px 6px;border-radius:20px;line-height:1.4;}
.sb-footer{border-top:1px solid var(--border);padding:12px 12px;}
.plan-pill{display:flex;align-items:center;gap:8px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:7px 10px;margin-bottom:8px;}
.plan-dot{width:6px;height:6px;border-radius:50%;flex-shrink:0;}
.plan-name{font-size:11.5px;color:var(--text2);}
.plan-name strong{color:var(--accent);}
.logout-a{display:flex;align-items:center;justify-content:center;gap:6px;width:100%;background:var(--danger-dim);border:1px solid rgba(255,77,109,0.2);color:var(--danger);border-radius:8px;padding:8px;font-size:12.5px;font-weight:700;text-decoration:none;transition:all .2s;cursor:pointer;font-family:'Cabinet Grotesk',sans-serif;}
.logout-a:hover{background:var(--danger);color:#fff;}

/* MAIN */
.main-wrap{margin-left:var(--sw);flex:1;display:flex;flex-direction:column;min-height:100vh;}
.topbar{background:var(--sidebar);border-bottom:1px solid var(--border);padding:0 26px;height:56px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;}
.topbar-title{font-family:'Instrument Serif',serif;font-size:1.4rem;font-style:italic;color:#fff;letter-spacing:-0.2px;}
.topbar-right{display:flex;align-items:center;gap:10px;}
.tb-btn{background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:7px 9px;cursor:pointer;color:var(--text2);font-size:14px;text-decoration:none;transition:all .15s;position:relative;}
.tb-btn:hover{border-color:var(--accent);color:var(--accent);}
.tb-count{position:absolute;top:-6px;right:-6px;background:var(--danger);color:#fff;font-size:9.5px;font-weight:800;min-width:17px;height:17px;border-radius:9px;display:flex;align-items:center;justify-content:center;padding:0 4px;border:2px solid var(--sidebar);}
.tb-dot{position:absolute;top:-3px;right:-3px;background:var(--danger);width:8px;height:8px;border-radius:50%;border:2px solid var(--sidebar);}
.admin-badge{background:var(--purple-dim);border:1px solid rgba(155,124,244,0.3);color:var(--purple);font-size:11px;font-weight:700;padding:4px 10px;border-radius:20px;}

/* CONTENT */
.page-content{padding:26px 28px;flex:1;}

/* ADMIN BANNER */
.admin-banner{background:linear-gradient(135deg,rgba(155,124,244,0.07),rgba(155,124,244,0.02));border:1px solid rgba(155,124,244,0.2);border-radius:12px;padding:14px 20px;display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;}
.ab-left{display:flex;align-items:center;gap:12px;}
.ab-icon{font-size:20px;}
.ab-title{font-size:13.5px;font-weight:800;color:#fff;}
.ab-sub{font-size:11.5px;color:var(--text2);margin-top:2px;}
.ab-btns{display:flex;gap:8px;flex-wrap:wrap;}
.ab-btn{display:inline-flex;align-items:center;gap:5px;padding:6px 13px;border-radius:7px;font-size:12px;font-weight:700;text-decoration:none;transition:all .15s;border:none;cursor:pointer;font-family:'Cabinet Grotesk',sans-serif;}
.ab-purple{background:var(--purple-dim);border:1px solid rgba(155,124,244,0.3);color:var(--purple);}
.ab-purple:hover{background:rgba(155,124,244,0.2);}
.ab-green{background:var(--accent-dim);border:1px solid rgba(37,211,102,0.3);color:var(--accent);}
.ab-green:hover{background:rgba(37,211,102,0.18);}

/* CARDS */
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);}
.card-header{display:flex;align-items:center;justify-content:space-between;padding:15px 20px;border-bottom:1px solid var(--border);}
.card-title{font-size:14px;font-weight:800;color:var(--text);}
.card-body{padding:20px;}

/* STATS */
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:14px;margin-bottom:22px;}
.stat-card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:18px;position:relative;overflow:hidden;transition:transform .2s;}
.stat-card:hover{transform:translateY(-2px);}
.stat-card::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:var(--c,var(--accent));}
.stat-icon{width:36px;height:36px;border-radius:9px;background:var(--c-dim,var(--accent-dim));display:flex;align-items:center;justify-content:center;font-size:17px;margin-bottom:12px;}
.stat-value{font-size:26px;font-weight:900;color:var(--c,var(--text));letter-spacing:-1px;margin-bottom:3px;line-height:1;}
.stat-label{color:var(--text2);font-size:12px;}

/* BUTTONS */
.btn{display:inline-flex;align-items:center;gap:6px;padding:8px 16px;border-radius:8px;font-family:'Cabinet Grotesk',sans-serif;font-size:13px;font-weight:700;cursor:pointer;border:none;transition:all .15s;text-decoration:none;}
.btn:active{transform:translateY(1px);}
.btn-primary{background:var(--accent);color:#000;}
.btn-primary:hover{opacity:0.88;}
.btn-secondary{background:var(--surface2);border:1px solid var(--border2);color:var(--text);}
.btn-secondary:hover{background:var(--surface3);}
.btn-danger{background:var(--danger);color:#fff;}
.btn-sm{padding:5px 11px;font-size:12px;}

/* TABLE */
.table-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;}
th{text-align:left;font-size:11px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--muted);padding:0 16px 10px;border-bottom:1px solid var(--border);}
td{padding:12px 16px;border-top:1px solid var(--border);font-size:13px;color:var(--text2);}
tr:hover td{background:rgba(255,255,255,0.01);}
.td-bold{font-weight:700;color:var(--text);}

/* BADGES */
.badge{display:inline-flex;align-items:center;gap:3px;padding:3px 8px;border-radius:20px;font-size:11px;font-weight:700;}
.badge-green{background:var(--accent-dim);color:var(--accent);}
.badge-blue{background:var(--blue-dim);color:var(--blue);}
.badge-orange{background:var(--orange-dim);color:var(--orange);}
.badge-purple{background:var(--purple-dim);color:var(--purple);}
.badge-red{background:var(--danger-dim);color:var(--danger);}
.badge-gray{background:var(--surface3);color:var(--muted);}
.badge-gold{background:var(--gold-dim);color:var(--gold);}

/* FORM */
.form-group{margin-bottom:16px;}
.form-label{display:block;font-size:11px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:var(--muted);margin-bottom:6px;}
.form-control{width:100%;background:var(--surface2);border:1px solid var(--border2);border-radius:8px;padding:10px 13px;color:var(--text);font-family:'Cabinet Grotesk',sans-serif;font-size:14px;outline:none;transition:border-color .2s;}
.form-control:focus{border-color:var(--accent);box-shadow:0 0 0 3px rgba(37,211,102,0.08);}
.form-control::placeholder{color:var(--muted);}
select.form-control{cursor:pointer;}
textarea.form-control{resize:vertical;min-height:90px;}

/* MODAL */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.72);z-index:999;display:none;align-items:center;justify-content:center;padding:20px;}
.modal-overlay.open{display:flex;}
.modal{background:var(--surface);border:1px solid var(--border2);border-radius:16px;padding:30px;max-width:500px;width:100%;max-height:90vh;overflow-y:auto;}
.modal-title{font-family:'Instrument Serif',serif;font-size:1.5rem;font-style:italic;color:#fff;margin-bottom:5px;}
.modal-sub{color:var(--muted);font-size:13px;margin-bottom:22px;}
.modal-footer{display:flex;gap:10px;justify-content:flex-end;margin-top:20px;}

/* ALERT */
.alert{padding:11px 15px;border-radius:9px;font-size:13px;margin-bottom:14px;}
.alert-success{background:var(--accent-dim);border:1px solid rgba(37,211,102,0.3);color:var(--accent);}
.alert-danger{background:var(--danger-dim);border:1px solid rgba(255,77,109,0.3);color:var(--danger);}
.alert-warning{background:rgba(255,170,0,0.10);border:1px solid rgba(255,170,0,0.35);color:#ffb300;}
.alert-info{background:rgba(80,140,255,0.10);border:1px solid rgba(80,140,255,0.3);color:var(--text2);}

/* EMPTY */
.empty-state{text-align:center;padding:50px 20px;}
.empty-icon{font-size:44px;margin-bottom:14px;opacity:0.4;}
.empty-title{font-size:17px;font-weight:800;color:var(--text);margin-bottom:7px;}
.empty-sub{color:var(--text2);font-size:13px;margin-bottom:22px;}

/* GRID */
.g2{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
.g3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;}
.g21{display:grid;grid-template-columns:2fr 1fr;gap:16px;}
.mb{margin-bottom:18px;}
.mb2{margin-bottom:24px;}

/* SEARCH */
.search-box{display:flex;align-items:center;gap:8px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:7px 12px;}
.search-box input{background:none;border:none;outline:none;color:var(--text);font-family:'Cabinet Grotesk',sans-serif;font-size:13px;width:160px;}
.search-box input::placeholder{color:var(--muted);}

/* MOBILE CHROME (hidden on desktop) */
.tb-menu{display:none;background:none;border:none;color:var(--text);font-size:22px;line-height:1;cursor:pointer;padding:6px 8px 6px 0;}
.sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:99;}
.bnav{display:none;}
.install-btn[hidden]{display:none !important;}

@media(max-width:768px){
  body{display:block;}
  .sidebar{transform:translateX(-100%);transition:transform .25s ease;width:min(280px,84vw);z-index:200;box-shadow:8px 0 30px rgba(0,0,0,0.5);padding-bottom:env(safe-area-inset-bottom);}
  body.menu-open .sidebar{transform:translateX(0);}
  body.menu-open .sb-overlay{display:block;z-index:150;}
  body.menu-open{overflow:hidden;}
  .sb-item{padding:12px 12px;font-size:14px;}
  .main-wrap{margin-left:0;min-height:100dvh;}
  .topbar{padding:0 12px;padding-top:env(safe-area-inset-top);height:calc(56px + env(safe-area-inset-top));}
  .tb-menu{display:block;}
  .topbar-title{font-size:1.2rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;min-width:0;}
  .topbar-right{gap:6px;}
  .admin-badge{display:none;}
  .tb-btn{padding:8px 10px;}
  .page-content{padding:12px 12px calc(84px + env(safe-area-inset-bottom));min-width:0;}
  .admin-banner{display:none;}                 /* admin tools are in the ☰ menu */

  /* Bottom navigation */
  .bnav{display:flex;position:fixed;left:0;right:0;bottom:0;z-index:120;background:rgba(8,13,20,0.97);backdrop-filter:blur(10px);border-top:1px solid var(--border2);padding-bottom:env(safe-area-inset-bottom);}
  .bnav a,.bnav button{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;min-height:60px;color:var(--text2);text-decoration:none;font-size:10.5px;font-weight:700;background:none;border:none;font-family:inherit;cursor:pointer;position:relative;}
  .bnav .bn-ic{font-size:20px;line-height:1;}
  .bnav .on{color:var(--accent);}
  .bnav .bn-badge{position:absolute;top:7px;left:calc(50% + 6px);background:var(--danger);color:#fff;font-size:9.5px;font-weight:800;min-width:17px;height:17px;border-radius:9px;display:flex;align-items:center;justify-content:center;padding:0 4px;}

  /* Layout: nothing wider than the screen */
  .g2,.g21,.g3{grid-template-columns:minmax(0,1fr);}
  .stats-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;}
  .stat-card{padding:14px;}
  .stat-value{font-size:22px;}
  .page-content [style*="grid-template-columns"]:not([style*="repeat("]){grid-template-columns:minmax(0,1fr) !important;}
  .page-content [style*="justify-content:space-between"]{flex-wrap:wrap;gap:8px;}
  .page-content > *, .card, .g2 > *, .g3 > *, .g21 > *{min-width:0;}
  .card-header{flex-wrap:wrap;gap:8px;padding:12px 14px;}
  .card-body{padding:14px;}
  .table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;max-width:100%;}
  th,td{padding:10px 12px;white-space:nowrap;}
  img,video,iframe,canvas,svg{max-width:100%;}
  .search-box{width:100%;}
  .search-box input{width:100%;}

  /* Touch: bigger targets, no iOS zoom on focus */
  .btn{min-height:42px;padding:10px 16px;}
  .btn-sm{min-height:36px;padding:7px 12px;}
  .form-control,select,textarea,input[type=text],input[type=email],input[type=tel],input[type=number],input[type=password],input[type=search],input[type=url]{font-size:16px;}

  /* Modals become bottom sheets */
  .modal-overlay{align-items:flex-end;padding:0;}
  .modal{max-width:100% !important;width:100%;border-radius:18px 18px 0 0;padding:22px 16px calc(18px + env(safe-area-inset-bottom));max-height:92dvh;}
  .modal-footer{flex-wrap:wrap;}
  .modal-footer .btn{flex:1;justify-content:center;}

}
</style>
</head>
<body>

<div class="sb-overlay" onclick="toggleMenu(false)"></div>
<aside class="sidebar" id="sidebar" aria-label="Main menu">
  <a href="/user/dashboard.php" class="sb-logo">
    <div class="sb-logo-mark">💬</div>
    <div class="sb-logo-text">WP API <span>Pro</span></div>
  </a>
  <div class="sb-user">
    <div class="sb-av" style="background:linear-gradient(135deg,<?= $isSuperAdmin ? '#9b7cf4,#7c3aed' : 'var(--accent),var(--accent2)' ?>);">
      <?= strtoupper(substr($user['name'], 0, 2)) ?>
    </div>
    <div style="flex:1;min-width:0;">
      <div class="sb-name"><?= e($user['name']) ?></div>
      <div class="sb-role"><?= $isSuperAdmin ? '🛡️ Super Admin' : e($user['company_name'] ?? 'User') ?></div>
    </div>
  </div>
  <nav class="sb-nav">
    <div class="sb-section">Main Menu</div>
    <?php foreach ($nav as $item): ?>
    <a href="<?= $item['href'] ?>" class="sb-item <?= $currentPath === basename($item['href']) ? 'active' : '' ?>">
      <span class="sb-icon"><?= $item['icon'] ?></span>
      <?= e($item['label']) ?>
      <?php if (!empty($item['badge']) && $item['badge'] > 0): ?>
        <span class="sb-badge"><?= min($item['badge'], 9) ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>

    <?php if ($isSuperAdmin): ?>
    <div class="sb-section" style="color:var(--purple);">🛡️ Admin Tools</div>
    <?php foreach ($adminNav as $item): ?>
    <a href="<?= $item['href'] ?>" class="sb-item admin-link <?= $currentPath === basename($item['href']) ? 'active' : '' ?>">
      <span class="sb-icon"><?= $item['icon'] ?></span>
      <?= e($item['label']) ?>
    </a>
    <?php endforeach; ?>
    <?php endif; ?>
  </nav>
  <div class="sb-footer">
    <div class="plan-pill">
      <div class="plan-dot" style="background:<?= $isSuperAdmin ? 'var(--purple)' : 'var(--accent)' ?>;"></div>
      <div class="plan-name">
        <?php if ($isSuperAdmin): ?>
          <strong style="color:var(--purple);">Super Admin</strong>
        <?php else: ?>
          Plan: <strong><?= e($subState['active'] ? ($plan['name'] ?? '—') : 'Not active') ?></strong>
          <?php if ($subState['active'] && $subState['days_left'] !== null): ?><span style="font-size:10px;color:var(--muted);"> · <?= (int)$subState['days_left'] ?>d left</span><?php endif; ?>
        <?php endif; ?>
      </div>
      <?php if (!$isSuperAdmin): ?>
        <a href="/user/billing.php" style="margin-left:auto;font-size:11px;color:var(--accent);text-decoration:none;">↑ Upgrade</a>
      <?php endif; ?>
    </div>
    <button type="button" class="logout-a install-btn" id="installBtn" style="background:var(--accent-dim);border-color:rgba(37,211,102,0.3);color:var(--accent);margin-bottom:8px;" hidden>📲 Install app</button>
    <a href="/logout.php" class="logout-a">⏏ Logout</a>
  </div>
</aside>

<div class="main-wrap">
  <div class="topbar">
    <button type="button" class="tb-menu" onclick="toggleMenu()" aria-label="Open menu" aria-controls="sidebar">☰</button>
    <div class="topbar-title"><?= e($pageTitle ?? 'Dashboard') ?></div>
    <div class="topbar-right">
      <?php if ($isSuperAdmin): ?>
        <span class="admin-badge">🛡️ Super Admin</span>
      <?php endif; ?>
      <a href="/user/wallet.php" class="tb-wallet-pill" title="Wallet Balance — Click to recharge" style="display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:20px;background:var(--surface2);border:1px solid <?= $wBal < 50 ? 'rgba(239,68,68,0.4)' : 'var(--border)' ?>;text-decoration:none;font-size:12px;font-weight:600;color:var(--text);transition:all 0.2s;">
        <span>💰</span>
        <span style="color:<?= $wBal < 50 ? 'var(--danger)' : 'var(--accent)' ?>;"><?= inr($wBal) ?></span>
      </a>
      <a href="/user/inbox.php" class="tb-btn" title="Inbox">
        💬<?php if ($openChats > 0): ?><span class="tb-dot"></span><?php endif; ?>
      </a>
      <a href="/user/notifications.php" class="tb-btn" title="Notifications" aria-label="Notifications<?= $unread ? ' (' . (int)$unread . ' unread)' : '' ?>">
        🔔<?php if ($unread > 0): ?><span class="tb-count"><?= $unread > 99 ? '99+' : (int)$unread ?></span><?php endif; ?>
      </a>
    </div>
  </div>
  <div class="page-content">
    <?php if (!$isSuperAdmin && $user['role'] === 'user' && $wBal < 50 && $currentPath !== 'wallet.php'): ?>
      <div class="alert alert-warning" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:16px;border-left:4px solid var(--danger);">
        <span>⚠️ <strong>Low Wallet Balance:</strong> Your balance is <strong><?= inr($wBal) ?></strong>. Messages & campaigns will pause when balance reaches ₹0.</span>
        <a href="/user/wallet.php" class="btn btn-primary btn-sm" style="font-size:12px;padding:5px 14px;">Recharge Wallet →</a>
      </div>
    <?php endif; ?>
    <?php if (!$isSuperAdmin && $user['role'] === 'user'): ?>
      <?php if (!$subState['active']): ?>
      <div class="alert alert-danger" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
        <span>🔒 <strong>No active plan.</strong> <?= $subState['last'] ? 'Your plan has ended' : 'Your account is created but not activated yet' ?> — campaigns, inbox, chatbots and API are paused.</span>
        <a href="/user/billing.php" class="btn btn-primary btn-sm">Activate / renew →</a>
      </div>
      <?php elseif ($subState['days_left'] !== null && $subState['days_left'] <= 3): ?>
      <div class="alert alert-warning" style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap;">
        <span>⏳ <strong><?= e($plan['name'] ?? 'Your plan') ?></strong> ends in <?= (int)$subState['days_left'] ?> day(s) (<?= e(fmtIst($subState['expires_at'], 'd M Y')) ?>).</span>
        <a href="/user/billing.php" class="btn btn-primary btn-sm">Renew →</a>
      </div>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($isSuperAdmin): ?>
    <div class="admin-banner">
      <div class="ab-left">
        <div class="ab-icon">🛡️</div>
        <div>
          <div class="ab-title">Super Admin Mode — Full Platform Access</div>
          <div class="ab-sub">Manage all users, plans, subscriptions, payments, and view platform-wide analytics.</div>
        </div>
      </div>
      <div class="ab-btns">
        <a href="/user/admin_users.php" class="ab-btn ab-purple">👥 All Users</a>
        <a href="/user/admin_plans.php" class="ab-btn ab-purple">💳 Plans</a>
        <a href="/user/admin_subscriptions.php" class="ab-btn ab-green">📋 Subscriptions</a>
        <a href="/user/admin_payments.php" class="ab-btn ab-green">💰 Payments</a>
        <a href="/user/admin_analytics.php" class="ab-btn ab-green">📊 Stats</a>
        <a href="/user/admin_logs.php" class="ab-btn ab-purple">📝 Logs</a>
      </div>
    </div>
    <?php endif; ?>
