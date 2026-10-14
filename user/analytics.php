<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();
$pageTitle = 'Analytics';
requireFeature('advanced_analytics', 'Analytics');
$uid = currentUser()['id'];

$range = sanitize($_GET['range'] ?? '30');
$range = in_array($range, ['7','30','90']) ? $range : '30';
$since = date('Y-m-d', strtotime("-$range days"));

// Overview stats
$totalSent      = db()->count("SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE c.user_id = ? AND m.direction = 'outbound' AND DATE(m.created_at) >= ?", [$uid, $since]);
$totalReceived  = db()->count("SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE c.user_id = ? AND m.direction = 'inbound' AND DATE(m.created_at) >= ?", [$uid, $since]);
$newContacts    = db()->count('SELECT COUNT(*) FROM contacts WHERE user_id = ? AND DATE(created_at) >= ?', [$uid, $since]);
$qrScans        = db()->count('SELECT COUNT(*) FROM qr_scans qs JOIN qr_codes qc ON qc.id = qs.qr_id WHERE qc.user_id = ? AND DATE(qs.scanned_at) >= ?', [$uid, $since]);
$campaignsSent  = db()->count("SELECT COUNT(*) FROM campaigns WHERE user_id = ? AND status = 'completed' AND DATE(created_at) >= ?", [$uid, $since]);

// Delivery stats
$totalDelivered = db()->count("SELECT COUNT(*) FROM campaign_logs WHERE user_id = ? AND status IN ('delivered','read') AND DATE(created_at) >= ?", [$uid, $since]);
$totalRead      = db()->count("SELECT COUNT(*) FROM campaign_logs WHERE user_id = ? AND status = 'read' AND DATE(created_at) >= ?", [$uid, $since]);
$totalFailed    = db()->count("SELECT COUNT(*) FROM campaign_logs WHERE user_id = ? AND status = 'failed' AND DATE(created_at) >= ?", [$uid, $since]);
$totalCampaignSent = db()->count("SELECT COUNT(*) FROM campaign_logs WHERE user_id = ? AND DATE(created_at) >= ?", [$uid, $since]);
$deliveryRate = $totalCampaignSent ? round($totalDelivered / $totalCampaignSent * 100, 1) : 0;
$readRate     = $totalCampaignSent ? round($totalRead / $totalCampaignSent * 100, 1) : 0;

// Daily message chart
$days = [];
for ($i = min((int)$range, 30) - 1; $i >= 0; $i--) {
    $date  = date('Y-m-d', strtotime("-$i days"));
    $label = date('M j', strtotime("-$i days"));
    $s = db()->count("SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE c.user_id = ? AND m.direction = 'outbound' AND DATE(m.created_at) = ?", [$uid, $date]);
    $r = db()->count("SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE c.user_id = ? AND m.direction = 'inbound' AND DATE(m.created_at) = ?", [$uid, $date]);
    $days[] = ['label' => $label, 'sent' => $s, 'recv' => $r, 'date' => $date];
}

// Top campaigns
$topCampaigns = db()->all("SELECT name, sent_count, delivered_count, read_count, failed_count,
    CASE WHEN sent_count > 0 THEN ROUND(read_count/sent_count*100,1) ELSE 0 END as read_rate
    FROM campaigns WHERE user_id = ? AND status = 'completed' ORDER BY read_rate DESC LIMIT 5", [$uid]);

// Contact sources
$contactSources = db()->all("SELECT source, COUNT(*) as cnt FROM contacts WHERE user_id = ? GROUP BY source ORDER BY cnt DESC", [$uid]);

include __DIR__ . '/../includes/user_header.php';
?>

<!-- Range selector -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;">
  <div style="font-size:13px;color:var(--muted);">Showing last <?= $range ?> days</div>
  <div style="display:flex;gap:6px;">
    <?php foreach (['7'=>'7d','30'=>'30d','90'=>'90d'] as $val => $lbl): ?>
    <a href="?range=<?= $val ?>" class="btn btn-sm <?= $range === $val ? 'btn-primary' : 'btn-secondary' ?>"><?= $lbl ?></a>
    <?php endforeach; ?>
  </div>
</div>

<!-- Stats row -->
<div class="stats-grid" style="grid-template-columns:repeat(5,1fr);">
  <div class="stat-card" style="--c:var(--accent);--c-dim:var(--accent-dim)">
    <div class="stat-icon">📤</div>
    <div class="stat-value" style="color:var(--accent)"><?= formatNumber($totalSent) ?></div>
    <div class="stat-label">Messages Sent</div>
  </div>
  <div class="stat-card" style="--c:var(--blue);--c-dim:var(--blue-dim)">
    <div class="stat-icon">📥</div>
    <div class="stat-value" style="color:var(--blue)"><?= formatNumber($totalReceived) ?></div>
    <div class="stat-label">Messages Received</div>
  </div>
  <div class="stat-card" style="--c:var(--orange);--c-dim:var(--orange-dim)">
    <div class="stat-icon">👥</div>
    <div class="stat-value" style="color:var(--orange)"><?= formatNumber($newContacts) ?></div>
    <div class="stat-label">New Contacts</div>
  </div>
  <div class="stat-card" style="--c:var(--purple);--c-dim:var(--purple-dim)">
    <div class="stat-icon">📱</div>
    <div class="stat-value" style="color:var(--purple)"><?= formatNumber($qrScans) ?></div>
    <div class="stat-label">QR Scans</div>
  </div>
  <div class="stat-card" style="--c:var(--accent);--c-dim:var(--accent-dim)">
    <div class="stat-icon">📢</div>
    <div class="stat-value" style="color:var(--accent)"><?= formatNumber($campaignsSent) ?></div>
    <div class="stat-label">Campaigns Sent</div>
  </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:20px;">
  <!-- Message chart -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">Message Volume</div>
      <div style="display:flex;gap:14px;font-size:12px;color:var(--text2);">
        <span>■ <span style="color:var(--accent)">Sent</span></span>
        <span>■ <span style="color:var(--blue)">Received</span></span>
      </div>
    </div>
    <?php
    $maxV = max(1, ...array_merge(array_column($days, 'sent'), array_column($days, 'recv')));
    $step = max(1, ceil($maxV / 4));
    ?>
    <div style="position:relative;height:200px;display:flex;align-items:flex-end;gap:3px;padding-bottom:24px;padding-left:30px;">
      <!-- Y axis labels -->
      <div style="position:absolute;left:0;top:0;bottom:24px;display:flex;flex-direction:column;justify-content:space-between;pointer-events:none;">
        <?php for ($i = 4; $i >= 0; $i--): ?>
        <div style="font-size:9px;color:var(--muted);text-align:right;width:24px;"><?= $i * $step ?></div>
        <?php endfor; ?>
      </div>
      <?php foreach ($days as $d):
        $sH = round(($d['sent'] / $maxV) * 160);
        $rH = round(($d['recv'] / $maxV) * 160);
      ?>
      <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:2px;position:relative;">
        <div style="display:flex;gap:1px;align-items:flex-end;width:100%;justify-content:center;">
          <div title="Sent: <?= $d['sent'] ?>" style="height:<?= max(2,$sH) ?>px;background:var(--accent);border-radius:2px 2px 0 0;flex:1;opacity:0.8;transition:height 0.3s;"></div>
          <div title="Recv: <?= $d['recv'] ?>" style="height:<?= max(2,$rH) ?>px;background:var(--blue);border-radius:2px 2px 0 0;flex:1;opacity:0.8;transition:height 0.3s;"></div>
        </div>
        <div style="position:absolute;bottom:-20px;font-size:8.5px;color:var(--muted);white-space:nowrap;"><?= $d['label'] ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Delivery rates -->
  <div class="card">
    <div class="card-header"><div class="card-title">Campaign Performance</div></div>
    <div style="display:flex;flex-direction:column;gap:16px;">
      <?php
      $metrics = [
        ['label' => 'Delivery Rate', 'value' => $deliveryRate, 'color' => 'var(--accent)', 'count' => $totalDelivered],
        ['label' => 'Read Rate',     'value' => $readRate,     'color' => 'var(--blue)',   'count' => $totalRead],
        ['label' => 'Failure Rate',  'value' => $totalCampaignSent ? round($totalFailed/$totalCampaignSent*100,1) : 0, 'color' => 'var(--danger)', 'count' => $totalFailed],
      ];
      foreach ($metrics as $m): ?>
      <div>
        <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:6px;">
          <span style="color:var(--text2);"><?= $m['label'] ?></span>
          <span style="font-weight:700;color:<?= $m['color'] ?>;"><?= $m['value'] ?>%</span>
        </div>
        <div style="height:6px;background:var(--surface3);border-radius:3px;overflow:hidden;">
          <div style="height:100%;width:<?= $m['value'] ?>%;background:<?= $m['color'] ?>;border-radius:3px;transition:width 0.5s;"></div>
        </div>
        <div style="font-size:11px;color:var(--muted);margin-top:3px;"><?= formatNumber($m['count']) ?> messages</div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
  <!-- Top campaigns -->
  <div class="card">
    <div class="card-header"><div class="card-title">Top Campaigns by Read Rate</div></div>
    <?php if (empty($topCampaigns)): ?>
      <div style="text-align:center;padding:30px;color:var(--muted);font-size:13px;">No completed campaigns yet</div>
    <?php else: ?>
      <div style="display:flex;flex-direction:column;gap:10px;">
        <?php foreach ($topCampaigns as $c): ?>
        <div style="display:flex;align-items:center;gap:12px;">
          <div style="flex:1;min-width:0;">
            <div style="font-size:13px;font-weight:500;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= e($c['name']) ?></div>
            <div style="font-size:11px;color:var(--muted);">Sent: <?= $c['sent_count'] ?> · Read: <?= $c['read_count'] ?></div>
          </div>
          <div style="font-family:'Syne',sans-serif;font-size:18px;font-weight:800;color:var(--accent);min-width:50px;text-align:right;"><?= $c['read_rate'] ?>%</div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <!-- Contact sources -->
  <div class="card">
    <div class="card-header"><div class="card-title">Contact Sources</div></div>
    <?php if (empty($contactSources)): ?>
      <div style="text-align:center;padding:30px;color:var(--muted);font-size:13px;">No contacts yet</div>
    <?php else: ?>
      <?php $totalConts = array_sum(array_column($contactSources, 'cnt')); ?>
      <div style="display:flex;flex-direction:column;gap:10px;">
        <?php foreach ($contactSources as $s):
          $pct = $totalConts ? round($s['cnt'] / $totalConts * 100) : 0;
          $color = match($s['source']) { 'manual'=>'var(--blue)', 'csv'=>'var(--accent)', 'qr'=>'var(--purple)', 'webhook'=>'var(--orange)', default=>'var(--muted)' };
        ?>
        <div>
          <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:5px;">
            <span style="color:var(--text2);text-transform:capitalize;"><?= e($s['source']) ?></span>
            <span style="font-weight:600;color:<?= $color ?>;"><?= $s['cnt'] ?> (<?= $pct ?>%)</span>
          </div>
          <div style="height:5px;background:var(--surface3);border-radius:3px;">
            <div style="height:100%;width:<?= $pct ?>%;background:<?= $color ?>;border-radius:3px;"></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<?php include __DIR__ . '/../includes/user_footer.php'; ?>
