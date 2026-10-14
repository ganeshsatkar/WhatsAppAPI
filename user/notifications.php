<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();
$pageTitle = 'Notifications';
$uid = (int)currentUser()['id'];

// Only same-site paths are followed (never an open redirect)
$safeLink = fn(?string $l) => ($l && preg_match('#^/(?!/)[\w\-./?=&%\#]*$#', $l)) ? $l : '';

// Open one: mark read, then go to its page
if (isset($_GET['open'])) {
    $n = db()->row('SELECT * FROM notifications WHERE id=? AND user_id=?', [(int)$_GET['open'], $uid]);
    if ($n) {
        db()->run('UPDATE notifications SET is_read=1 WHERE id=?', [$n['id']]);
        if ($to = $safeLink($n['link'])) { header('Location: ' . APP_URL . $to); exit; }
    }
    header('Location: ' . APP_URL . '/user/notifications.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $a = $_POST['action'] ?? '';
    if ($a === 'read_all') db()->run('UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0', [$uid]);
    if ($a === 'clear_read') db()->run('DELETE FROM notifications WHERE user_id=? AND is_read=1', [$uid]);
    header('Location: ' . APP_URL . '/user/notifications.php');
    exit;
}

$filter = ($_GET['f'] ?? '') === 'unread' ? 'unread' : 'all';
$items  = db()->all('SELECT * FROM notifications WHERE user_id=?' . ($filter === 'unread' ? ' AND is_read=0' : '') . ' ORDER BY id DESC LIMIT 100', [$uid]);
$unreadCount = db()->count('SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0', [$uid]);
$icons = ['success' => '✅', 'warning' => '⚠️', 'error' => '❌', 'info' => '🔔'];

include __DIR__ . '/../includes/user_header.php';
?>
<style>
.nt-bar{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:14px;}
.nt-tabs{display:flex;gap:6px;}
.nt-tab{padding:7px 14px;border-radius:20px;font-size:12.5px;font-weight:700;text-decoration:none;color:var(--text2);border:1px solid var(--border);}
.nt-tab.on{background:var(--accent-dim);color:var(--accent);border-color:rgba(37,211,102,0.3);}
.nt-item{display:flex;gap:12px;padding:14px 16px;border-bottom:1px solid var(--border);text-decoration:none;color:inherit;align-items:flex-start;}
.nt-item:last-child{border-bottom:none;}
.nt-item:hover{background:rgba(255,255,255,0.02);}
.nt-item.unread{background:rgba(37,211,102,0.04);}
.nt-ic{font-size:20px;line-height:1.2;flex-shrink:0;}
.nt-body{flex:1;min-width:0;}
.nt-title{font-weight:700;color:var(--text);font-size:14px;margin-bottom:2px;}
.nt-item.unread .nt-title::after{content:'';display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--accent);margin-left:8px;vertical-align:middle;}
.nt-text{color:var(--text2);font-size:13px;line-height:1.5;word-break:break-word;}
.nt-time{color:var(--muted);font-size:11.5px;margin-top:4px;}
</style>

<div class="nt-bar">
  <div class="nt-tabs">
    <a href="?f=all" class="nt-tab <?= $filter === 'all' ? 'on' : '' ?>">All</a>
    <a href="?f=unread" class="nt-tab <?= $filter === 'unread' ? 'on' : '' ?>">Unread<?= $unreadCount ? " ($unreadCount)" : '' ?></a>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <?php if ($unreadCount): ?>
    <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="read_all"><button class="btn btn-secondary btn-sm">✓ Mark all read</button></form>
    <?php endif; ?>
    <form method="POST" onsubmit="return confirm('Delete all read notifications?')"><?= csrfField() ?><input type="hidden" name="action" value="clear_read"><button class="btn btn-secondary btn-sm">🗑 Clear read</button></form>
  </div>
</div>

<div class="card">
  <?php if (!$items): ?>
    <div class="empty-state">
      <div class="empty-icon">🔔</div>
      <div class="empty-title"><?= $filter === 'unread' ? 'You are all caught up' : 'No notifications yet' ?></div>
      <div class="empty-sub">Wallet recharges, campaign results, template approvals and plan reminders will appear here.</div>
    </div>
  <?php else: foreach ($items as $n): ?>
    <a class="nt-item <?= $n['is_read'] ? '' : 'unread' ?>" href="?open=<?= (int)$n['id'] ?>">
      <div class="nt-ic"><?= $icons[$n['type']] ?? '🔔' ?></div>
      <div class="nt-body">
        <div class="nt-title"><?= e($n['title']) ?></div>
        <?php if ($n['body'] !== null && $n['body'] !== ''): ?><div class="nt-text"><?= e($n['body']) ?></div><?php endif; ?>
        <div class="nt-time"><?= e(timeAgo($n['created_at'])) ?> · <?= e(toLocalTime($n['created_at'], 'd M Y, h:i A')) ?></div>
      </div>
    </a>
  <?php endforeach; endif; ?>
</div>

<?php include __DIR__ . '/../includes/user_footer.php'; ?>
