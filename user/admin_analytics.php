<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
if (currentUser()['role'] !== 'superadmin') { header('Location: /user/dashboard.php'); exit; }
$pageTitle = 'Platform Stats';

$stats = [
  'total_users'     => db()->count("SELECT COUNT(*) FROM users WHERE role='user'"),
  'active_users'    => db()->count("SELECT COUNT(*) FROM users WHERE role='user' AND is_active=1"),
  'total_contacts'  => db()->count('SELECT COUNT(*) FROM contacts'),
  'total_messages'  => db()->count('SELECT COUNT(*) FROM messages'),
  'total_campaigns' => db()->count('SELECT COUNT(*) FROM campaigns'),
  'total_bots'      => db()->count('SELECT COUNT(*) FROM bot_flows'),
  'today_users'     => db()->count("SELECT COUNT(*) FROM users WHERE DATE(created_at)=CURDATE() AND role='user'"),
  'today_messages'  => db()->count('SELECT COUNT(*) FROM messages WHERE DATE(created_at)=CURDATE()'),
  'total_qr_scans'  => db()->count('SELECT COUNT(*) FROM qr_scans'),
  'total_revenue'   => db()->row("SELECT COALESCE(SUM(amount),0) as t FROM payments WHERE status='success'")['t'] ?? 0,
];

// Top users by messages
$topUsers = db()->all("SELECT u.name,u.email,p.name as plan_name,
    COUNT(m.id) as msg_count
    FROM users u
    LEFT JOIN plans p ON p.id=u.plan_id
    LEFT JOIN conversations cv ON cv.user_id=u.id
    LEFT JOIN messages m ON m.conversation_id=cv.id
    WHERE u.role='user'
    GROUP BY u.id ORDER BY msg_count DESC LIMIT 10");

// Plan distribution
$planDist = db()->all("SELECT p.name,p.slug,COUNT(u.id) as count FROM plans p LEFT JOIN users u ON u.plan_id=p.id AND u.role='user' GROUP BY p.id ORDER BY p.price ASC");

include __DIR__ . '/../includes/user_header.php';
?>
<div class="stats-grid mb2">
  <div class="stat-card" style="--c:var(--blue);--c-dim:var(--blue-dim)"><div class="stat-icon">👥</div><div class="stat-value"><?=formatNumber($stats['total_users'])?></div><div class="stat-label">Total Users</div></div>
  <div class="stat-card" style="--c:var(--accent);--c-dim:var(--accent-dim)"><div class="stat-icon">✅</div><div class="stat-value"><?=formatNumber($stats['active_users'])?></div><div class="stat-label">Active Users</div></div>
  <div class="stat-card" style="--c:var(--orange);--c-dim:var(--orange-dim)"><div class="stat-icon">💬</div><div class="stat-value"><?=formatNumber($stats['total_messages'])?></div><div class="stat-label">Total Messages</div></div>
  <div class="stat-card" style="--c:var(--gold);--c-dim:var(--gold-dim)"><div class="stat-icon">💰</div><div class="stat-value">₹<?=number_format($stats['total_revenue'])?></div><div class="stat-label">Total Revenue</div></div>
  <div class="stat-card" style="--c:var(--purple);--c-dim:var(--purple-dim)"><div class="stat-icon">📢</div><div class="stat-value"><?=formatNumber($stats['total_campaigns'])?></div><div class="stat-label">Campaigns</div></div>
  <div class="stat-card" style="--c:var(--accent);--c-dim:var(--accent-dim)"><div class="stat-icon">🤖</div><div class="stat-value"><?=formatNumber($stats['total_bots'])?></div><div class="stat-label">Bot Flows</div></div>
  <div class="stat-card" style="--c:var(--blue);--c-dim:var(--blue-dim)"><div class="stat-icon">👤</div><div class="stat-value"><?=$stats['today_users']?></div><div class="stat-label">New Users Today</div></div>
  <div class="stat-card" style="--c:var(--orange);--c-dim:var(--orange-dim)"><div class="stat-icon">📱</div><div class="stat-value"><?=formatNumber($stats['total_qr_scans'])?></div><div class="stat-label">QR Scans</div></div>
</div>

<div class="g2">
  <!-- Plan Distribution -->
  <div class="card">
    <div class="card-header"><div class="card-title">Plan Distribution</div></div>
    <div class="card-body">
      <?php foreach($planDist as $pd):
        $total = max(1, $stats['total_users']);
        $pct   = round($pd['count']/$total*100);
        $color = match($pd['slug']??'free'){'pro'=>'var(--blue)','growth'=>'var(--gold)','enterprise','ultimate'=>'var(--purple)',default=>'var(--accent)'};
      ?>
      <div style="margin-bottom:14px;">
        <div style="display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;">
          <span style="color:var(--text2);"><?=e($pd['name'])?></span>
          <span style="font-weight:700;color:#fff;"><?=$pd['count']?> users (<?=$pct?>%)</span>
        </div>
        <div style="height:6px;background:var(--surface3);border-radius:10px;overflow:hidden;">
          <div style="height:100%;width:<?=$pct?>%;background:<?=$color?>;border-radius:10px;transition:width .5s;"></div>
        </div>
      </div>
      <?php endforeach;?>
    </div>
  </div>

  <!-- Top Users -->
  <div class="card">
    <div class="card-header"><div class="card-title">Top Users by Messages</div></div>
    <div class="card-body">
      <?php if(empty($topUsers)):?>
      <div style="text-align:center;color:var(--muted);padding:20px;">No data yet.</div>
      <?php else: ?>
      <?php foreach($topUsers as $u):?>
      <div style="display:flex;align-items:center;gap:10px;padding:9px 0;border-bottom:1px solid var(--border);">
        <div style="width:30px;height:30px;border-radius:8px;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#000;flex-shrink:0;"><?=strtoupper(substr($u['name'],0,2))?></div>
        <div style="flex:1;min-width:0;">
          <div style="font-size:13px;font-weight:700;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?=e($u['name'])?></div>
          <div style="font-size:11px;color:var(--muted);"><?=e($u['plan_name']??'Free')?></div>
        </div>
        <div style="font-size:13px;font-weight:700;color:var(--accent);"><?=formatNumber($u['msg_count'])?></div>
      </div>
      <?php endforeach;?>
      <?php endif;?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/../includes/user_footer.php';?>
