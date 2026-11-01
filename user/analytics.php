<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();
$pageTitle = 'Analytics';
requireFeature('advanced_analytics', 'Analytics');
$uid = (int)currentUser()['id'];

// --- Date Range Handling ---
$preset = sanitize($_GET['preset'] ?? '');
$fromDate = sanitize($_GET['from'] ?? '');
$toDate = sanitize($_GET['to'] ?? '');

$today = date('Y-m-d');
if ($preset === 'today') {
    $fromDate = $today;
    $toDate = $today;
} elseif ($preset === 'yesterday') {
    $fromDate = date('Y-m-d', strtotime('-1 day'));
    $toDate = date('Y-m-d', strtotime('-1 day'));
} elseif ($preset === '7d') {
    $fromDate = date('Y-m-d', strtotime('-6 days'));
    $toDate = $today;
} elseif ($preset === '30d') {
    $fromDate = date('Y-m-d', strtotime('-29 days'));
    $toDate = $today;
} elseif ($preset === '90d') {
    $fromDate = date('Y-m-d', strtotime('-89 days'));
    $toDate = $today;
} elseif ($preset === 'this_month') {
    $fromDate = date('Y-m-01');
    $toDate = $today;
} elseif ($preset === 'last_month') {
    $fromDate = date('Y-m-01', strtotime('first day of last month'));
    $toDate = date('Y-m-t', strtotime('last day of last month'));
} else {
    // If explicit from/to not given, default to 30 days
    if (empty($fromDate) || empty($toDate)) {
        $preset = '30d';
        $fromDate = date('Y-m-d', strtotime('-29 days'));
        $toDate = $today;
    }
}

// Ensure valid dates
if (!strtotime($fromDate)) $fromDate = date('Y-m-d', strtotime('-29 days'));
if (!strtotime($toDate)) $toDate = $today;
if ($fromDate > $toDate) {
    $tmp = $fromDate; $fromDate = $toDate; $toDate = $tmp;
}

// Calculate days difference
$dateDiff = (int)ceil((strtotime($toDate) - strtotime($fromDate)) / 86400) + 1;

// --- CSV Export Handler ---
if (($_GET['export'] ?? '') === 'csv') {
    $exportFilename = "wpapi_analytics_{$fromDate}_to_{$toDate}.csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $exportFilename . '"');
    
    $out = fopen('php://output', 'w');
    // BOM for Excel compatibility
    fputs($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Date', 'Outbound Chats Sent', 'Outbound Delivered', 'Outbound Read (Seen)', 'Inbound Received', 'Campaign Messages Sent', 'Campaign Delivered', 'Campaign Read', 'Campaign Failed', 'Total Sent', 'Total Delivered', 'Total Read', 'Delivery Rate %', 'Read Rate %']);
    
    // Fetch aggregated data
    $chatDaily = db()->all("SELECT DATE(m.created_at) as msg_date,
        SUM(CASE WHEN m.direction = 'outbound' THEN 1 ELSE 0 END) as sent_cnt,
        SUM(CASE WHEN m.direction = 'outbound' AND m.status IN ('delivered','read') THEN 1 ELSE 0 END) as deliv_cnt,
        SUM(CASE WHEN m.direction = 'outbound' AND m.status = 'read' THEN 1 ELSE 0 END) as read_cnt,
        SUM(CASE WHEN m.direction = 'inbound' THEN 1 ELSE 0 END) as recv_cnt
        FROM messages m
        JOIN conversations c ON c.id = m.conversation_id
        WHERE c.user_id = ? AND DATE(m.created_at) BETWEEN ? AND ?
        GROUP BY DATE(m.created_at)
        ORDER BY msg_date DESC", [$uid, $fromDate, $toDate]);
    
    $campDaily = db()->all("SELECT DATE(created_at) as camp_date,
        COUNT(*) as total_cnt,
        SUM(CASE WHEN status IN ('delivered','read') THEN 1 ELSE 0 END) as deliv_cnt,
        SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as read_cnt,
        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as fail_cnt
        FROM campaign_logs
        WHERE user_id = ? AND DATE(created_at) BETWEEN ? AND ?
        GROUP BY DATE(created_at)
        ORDER BY camp_date DESC", [$uid, $fromDate, $toDate]);
    
    $campMap = [];
    foreach ($campDaily as $c) $campMap[$c['camp_date']] = $c;
    
    $chatMap = [];
    foreach ($chatDaily as $c) $chatMap[$c['msg_date']] = $c;

    // Build full date sequence
    $cur = strtotime($toDate);
    $start = strtotime($fromDate);
    while ($cur >= $start) {
        $d = date('Y-m-d', $cur);
        $ch = $chatMap[$d] ?? ['sent_cnt'=>0, 'deliv_cnt'=>0, 'read_cnt'=>0, 'recv_cnt'=>0];
        $cp = $campMap[$d] ?? ['total_cnt'=>0, 'deliv_cnt'=>0, 'read_cnt'=>0, 'fail_cnt'=>0];
        
        $totSent = (int)$ch['sent_cnt'] + (int)$cp['total_cnt'];
        $totDeliv = (int)$ch['deliv_cnt'] + (int)$cp['deliv_cnt'];
        $totRead = (int)$ch['read_cnt'] + (int)$cp['read_cnt'];
        $delivRate = $totSent > 0 ? round(($totDeliv / $totSent) * 100, 1) : 0;
        $readRate = $totSent > 0 ? round(($totRead / $totSent) * 100, 1) : 0;
        
        fputcsv($out, [
            $d,
            (int)$ch['sent_cnt'],
            (int)$ch['deliv_cnt'],
            (int)$ch['read_cnt'],
            (int)$ch['recv_cnt'],
            (int)$cp['total_cnt'],
            (int)$cp['deliv_cnt'],
            (int)$cp['read_cnt'],
            (int)$cp['fail_cnt'],
            $totSent,
            $totDeliv,
            $totRead,
            $delivRate . '%',
            $readRate . '%'
        ]);
        $cur = strtotime('-1 day', $cur);
    }
    fclose($out);
    exit;
}

// --- Overview Aggregations for Selected Date Range ---
$totalSentChat     = (int)db()->count("SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE c.user_id = ? AND m.direction = 'outbound' AND DATE(m.created_at) BETWEEN ? AND ?", [$uid, $fromDate, $toDate]);
$totalReceivedChat = (int)db()->count("SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE c.user_id = ? AND m.direction = 'inbound' AND DATE(m.created_at) BETWEEN ? AND ?", [$uid, $fromDate, $toDate]);
$newContacts       = (int)db()->count("SELECT COUNT(*) FROM contacts WHERE user_id = ? AND DATE(created_at) BETWEEN ? AND ?", [$uid, $fromDate, $toDate]);
$qrScans           = (int)db()->count("SELECT COUNT(*) FROM qr_scans qs JOIN qr_codes qc ON qc.id = qs.qr_id WHERE qc.user_id = ? AND DATE(qs.scanned_at) BETWEEN ? AND ?", [$uid, $fromDate, $toDate]);
$campaignsSent     = (int)db()->count("SELECT COUNT(*) FROM campaigns WHERE user_id = ? AND status = 'completed' AND DATE(created_at) BETWEEN ? AND ?", [$uid, $fromDate, $toDate]);

// Campaign delivery stats
$totalCampaignSent = (int)db()->count("SELECT COUNT(*) FROM campaign_logs WHERE user_id = ? AND DATE(created_at) BETWEEN ? AND ?", [$uid, $fromDate, $toDate]);
$totalCampDelivered = (int)db()->count("SELECT COUNT(*) FROM campaign_logs WHERE user_id = ? AND status IN ('delivered','read') AND DATE(created_at) BETWEEN ? AND ?", [$uid, $fromDate, $toDate]);
$totalCampRead      = (int)db()->count("SELECT COUNT(*) FROM campaign_logs WHERE user_id = ? AND status = 'read' AND DATE(created_at) BETWEEN ? AND ?", [$uid, $fromDate, $toDate]);
$totalCampFailed    = (int)db()->count("SELECT COUNT(*) FROM campaign_logs WHERE user_id = ? AND status = 'failed' AND DATE(created_at) BETWEEN ? AND ?", [$uid, $fromDate, $toDate]);

// Combined outbound sent
$grandTotalSent = $totalSentChat + $totalCampaignSent;
$deliveryRate = $totalCampaignSent ? round($totalCampDelivered / $totalCampaignSent * 100, 1) : 0;
$readRate     = $totalCampaignSent ? round($totalCampRead / $totalCampaignSent * 100, 1) : 0;

// --- Day-wise Combined Breakdown Query ---
$chatDaily = db()->all("SELECT DATE(m.created_at) as msg_date,
    SUM(CASE WHEN m.direction = 'outbound' THEN 1 ELSE 0 END) as sent_cnt,
    SUM(CASE WHEN m.direction = 'outbound' AND m.status IN ('delivered','read') THEN 1 ELSE 0 END) as deliv_cnt,
    SUM(CASE WHEN m.direction = 'outbound' AND m.status = 'read' THEN 1 ELSE 0 END) as read_cnt,
    SUM(CASE WHEN m.direction = 'inbound' THEN 1 ELSE 0 END) as recv_cnt
    FROM messages m
    JOIN conversations c ON c.id = m.conversation_id
    WHERE c.user_id = ? AND DATE(m.created_at) BETWEEN ? AND ?
    GROUP BY DATE(m.created_at)
    ORDER BY msg_date DESC", [$uid, $fromDate, $toDate]);

$campDaily = db()->all("SELECT DATE(created_at) as camp_date,
    COUNT(*) as total_cnt,
    SUM(CASE WHEN status IN ('delivered','read') THEN 1 ELSE 0 END) as deliv_cnt,
    SUM(CASE WHEN status = 'read' THEN 1 ELSE 0 END) as read_cnt,
    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as fail_cnt
    FROM campaign_logs
    WHERE user_id = ? AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at)
    ORDER BY camp_date DESC", [$uid, $fromDate, $toDate]);

$campMap = [];
foreach ($campDaily as $c) $campMap[$c['camp_date']] = $c;
$chatMap = [];
foreach ($chatDaily as $c) $chatMap[$c['msg_date']] = $c;

// Build continuous daily rows array
$daysBreakdown = [];
$chartDays = [];
$cur = strtotime($toDate);
$start = strtotime($fromDate);
$maxChartDays = min($dateDiff, 30); // show up to last 30 days of period on bar chart
$chartIndex = 0;

while ($cur >= $start) {
    $d = date('Y-m-d', $cur);
    $ch = $chatMap[$d] ?? ['sent_cnt'=>0, 'deliv_cnt'=>0, 'read_cnt'=>0, 'recv_cnt'=>0];
    $cp = $campMap[$d] ?? ['total_cnt'=>0, 'deliv_cnt'=>0, 'read_cnt'=>0, 'fail_cnt'=>0];
    
    $outboundSent = (int)$ch['sent_cnt'] + (int)$cp['total_cnt'];
    $outboundDeliv = (int)$ch['deliv_cnt'] + (int)$cp['deliv_cnt'];
    $outboundRead = (int)$ch['read_cnt'] + (int)$cp['read_cnt'];
    $inboundRecv = (int)$ch['recv_cnt'];
    $campFail = (int)$cp['fail_cnt'];
    
    $delivPct = $outboundSent > 0 ? round(($outboundDeliv / $outboundSent) * 100, 1) : 0;
    $readPct = $outboundSent > 0 ? round(($outboundRead / $outboundSent) * 100, 1) : 0;
    
    $row = [
        'date' => $d,
        'label' => date('d M Y, D', $cur),
        'chart_label' => date('M j', $cur),
        'total_sent' => $outboundSent,
        'chat_sent' => (int)$ch['sent_cnt'],
        'camp_sent' => (int)$cp['total_cnt'],
        'delivered' => $outboundDeliv,
        'read' => $outboundRead,
        'received' => $inboundRecv,
        'failed' => $campFail,
        'deliv_pct' => $delivPct,
        'read_pct' => $readPct,
        'has_activity' => ($outboundSent > 0 || $inboundRecv > 0)
    ];
    $daysBreakdown[] = $row;
    
    if ($chartIndex < $maxChartDays) {
        $chartDays[] = [
            'label' => date('M j', $cur),
            'sent' => $outboundSent,
            'recv' => $inboundRecv,
            'date' => $d
        ];
        $chartIndex++;
    }
    
    $cur = strtotime('-1 day', $cur);
}
$chartDays = array_reverse($chartDays); // chronological order for bar chart

// Top campaigns in date range
$topCampaigns = db()->all("SELECT name, sent_count, delivered_count, read_count, failed_count,
    CASE WHEN sent_count > 0 THEN ROUND(read_count/sent_count*100,1) ELSE 0 END as read_rate
    FROM campaigns WHERE user_id = ? AND status = 'completed' AND DATE(created_at) BETWEEN ? AND ?
    ORDER BY read_rate DESC LIMIT 5", [$uid, $fromDate, $toDate]);

// Contact sources
$contactSources = db()->all("SELECT source, COUNT(*) as cnt FROM contacts WHERE user_id = ? AND DATE(created_at) BETWEEN ? AND ? GROUP BY source ORDER BY cnt DESC", [$uid, $fromDate, $toDate]);

include __DIR__ . '/../includes/user_header.php';
?>

<div style="display:flex;flex-direction:column;gap:18px;margin-bottom:24px;">

  <!-- Page Header & Actions -->
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
      <h2 style="margin:0;font-size:22px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px;">
        📊 Analytics & Day-Wise Reports
      </h2>
      <div style="font-size:13px;color:var(--muted);margin-top:4px;">
        Showing data from <strong style="color:var(--text);"><?= date('d M Y', strtotime($fromDate)) ?></strong> to <strong style="color:var(--text);"><?= date('d M Y', strtotime($toDate)) ?></strong> (<?= $dateDiff ?> days)
      </div>
    </div>
    
    <!-- Action buttons (Export CSV & Outbound Logs) -->
    <div style="display:flex;gap:10px;align-items:center;">
      <a href="/user/outbound.php" class="btn btn-primary btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
        📤 <span>Number-Wise Outbound Logs</span>
      </a>
      <a href="?export=csv&from=<?= urlencode($fromDate) ?>&to=<?= urlencode($toDate) ?>" class="btn btn-secondary btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
        📥 <span>Export CSV</span>
      </a>
    </div>
  </div>

  <!-- Date Range Filter Card -->
  <div class="card" style="padding:14px 18px;background:var(--surface);">
    <form method="GET" action="" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;margin:0;">
      <!-- Quick preset buttons -->
      <div style="display:flex;align-items:center;gap:6px;flex-wrap:wrap;">
        <span style="font-size:12px;font-weight:600;color:var(--muted);margin-right:4px;">Presets:</span>
        <a href="?preset=today" class="btn btn-sm <?= $preset === 'today' ? 'btn-primary' : 'btn-secondary' ?>">Today</a>
        <a href="?preset=yesterday" class="btn btn-sm <?= $preset === 'yesterday' ? 'btn-primary' : 'btn-secondary' ?>">Yesterday</a>
        <a href="?preset=7d" class="btn btn-sm <?= $preset === '7d' ? 'btn-primary' : 'btn-secondary' ?>">7 Days</a>
        <a href="?preset=30d" class="btn btn-sm <?= $preset === '30d' ? 'btn-primary' : 'btn-secondary' ?>">30 Days</a>
        <a href="?preset=this_month" class="btn btn-sm <?= $preset === 'this_month' ? 'btn-primary' : 'btn-secondary' ?>">This Month</a>
        <a href="?preset=last_month" class="btn btn-sm <?= $preset === 'last_month' ? 'btn-primary' : 'btn-secondary' ?>">Last Month</a>
      </div>

      <!-- Custom Date Picker Form -->
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
        <span style="font-size:12px;font-weight:600;color:var(--muted);">Custom Range:</span>
        <input type="date" name="from" value="<?= e($fromDate) ?>" class="form-control" style="width:145px;padding:6px 10px;font-size:13px;border-radius:6px;border:1px solid var(--border);background:var(--surface2);color:var(--text);">
        <span style="color:var(--muted);font-size:12px;">to</span>
        <input type="date" name="to" value="<?= e($toDate) ?>" class="form-control" style="width:145px;padding:6px 10px;font-size:13px;border-radius:6px;border:1px solid var(--border);background:var(--surface2);color:var(--text);">
        <button type="submit" class="btn btn-primary btn-sm" style="padding:6px 14px;">Apply</button>
      </div>
    </form>
  </div>

</div>

<!-- Stats row -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fit, minmax(180px, 1fr));gap:14px;margin-bottom:20px;">
  <div class="stat-card" style="--c:var(--accent);--c-dim:var(--accent-dim)">
    <div class="stat-icon">📤</div>
    <div class="stat-value" style="color:var(--accent)"><?= formatNumber($grandTotalSent) ?></div>
    <div class="stat-label">Total Outbound Sent</div>
    <div style="font-size:11px;color:var(--muted);margin-top:4px;">(<?= $totalSentChat ?> direct + <?= $totalCampaignSent ?> broadcast)</div>
  </div>
  <div class="stat-card" style="--c:var(--blue);--c-dim:var(--blue-dim)">
    <div class="stat-icon">📥</div>
    <div class="stat-value" style="color:var(--blue)"><?= formatNumber($totalReceivedChat) ?></div>
    <div class="stat-label">Inbound Received</div>
    <div style="font-size:11px;color:var(--muted);margin-top:4px;">Customer replies</div>
  </div>
  <div class="stat-card" style="--c:#22c55e;--c-dim:rgba(34,197,94,0.1)">
    <div class="stat-icon">✅</div>
    <div class="stat-value" style="color:#22c55e"><?= $deliveryRate ?>%</div>
    <div class="stat-label">Delivery Rate</div>
    <div style="font-size:11px;color:var(--muted);margin-top:4px;"><?= formatNumber($totalCampDelivered) ?> delivered</div>
  </div>
  <div class="stat-card" style="--c:#06b6d4;--c-dim:rgba(6,182,212,0.1)">
    <div class="stat-icon">👁️</div>
    <div class="stat-value" style="color:#06b6d4"><?= $readRate ?>%</div>
    <div class="stat-label">Read / Seen Rate</div>
    <div style="font-size:11px;color:var(--muted);margin-top:4px;"><?= formatNumber($totalCampRead) ?> read receipts</div>
  </div>
  <div class="stat-card" style="--c:var(--orange);--c-dim:var(--orange-dim)">
    <div class="stat-icon">👥</div>
    <div class="stat-value" style="color:var(--orange)"><?= formatNumber($newContacts) ?></div>
    <div class="stat-label">New Contacts</div>
    <div style="font-size:11px;color:var(--muted);margin-top:4px;">Added in range</div>
  </div>
  <div class="stat-card" style="--c:var(--purple);--c-dim:var(--purple-dim)">
    <div class="stat-icon">📢</div>
    <div class="stat-value" style="color:var(--purple)"><?= formatNumber($campaignsSent) ?></div>
    <div class="stat-label">Campaigns Run</div>
    <div style="font-size:11px;color:var(--muted);margin-top:4px;">Completed broadcasts</div>
  </div>
</div>

<div style="display:grid;grid-template-columns:2fr 1fr;gap:20px;margin-bottom:24px;">
  <!-- Message chart -->
  <div class="card">
    <div class="card-header">
      <div>
        <div class="card-title">Message Volume Trend</div>
        <div style="font-size:11px;color:var(--muted);">Daily volume (showing <?= count($chartDays) ?> days)</div>
      </div>
      <div style="display:flex;gap:14px;font-size:12px;color:var(--text2);">
        <span>■ <span style="color:var(--accent)">Sent</span></span>
        <span>■ <span style="color:var(--blue)">Received</span></span>
      </div>
    </div>
    <?php
    $maxV = max(1, ...array_merge(array_column($chartDays, 'sent'), array_column($chartDays, 'recv')));
    $step = max(1, ceil($maxV / 4));
    ?>
    <div style="position:relative;height:200px;display:flex;align-items:flex-end;gap:3px;padding-bottom:24px;padding-left:34px;">
      <!-- Y axis labels -->
      <div style="position:absolute;left:0;top:0;bottom:24px;display:flex;flex-direction:column;justify-content:space-between;pointer-events:none;">
        <?php for ($i = 4; $i >= 0; $i--): ?>
        <div style="font-size:9px;color:var(--muted);text-align:right;width:28px;"><?= $i * $step ?></div>
        <?php endfor; ?>
      </div>
      <?php if (empty($chartDays)): ?>
        <div style="display:flex;align-items:center;justify-content:center;width:100%;height:100%;color:var(--muted);font-size:13px;">No data for selected dates</div>
      <?php else: ?>
        <?php foreach ($chartDays as $d):
          $sH = round(($d['sent'] / $maxV) * 160);
          $rH = round(($d['recv'] / $maxV) * 160);
        ?>
        <div style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:flex-end;gap:2px;position:relative;">
          <div style="display:flex;gap:1px;align-items:flex-end;width:100%;justify-content:center;">
            <div title="<?= $d['date'] ?>: Sent <?= $d['sent'] ?>" style="height:<?= max(2,$sH) ?>px;background:var(--accent);border-radius:2px 2px 0 0;flex:1;opacity:0.85;transition:height 0.3s;cursor:pointer;"></div>
            <div title="<?= $d['date'] ?>: Recv <?= $d['recv'] ?>" style="height:<?= max(2,$rH) ?>px;background:var(--blue);border-radius:2px 2px 0 0;flex:1;opacity:0.85;transition:height 0.3s;cursor:pointer;"></div>
          </div>
          <div style="position:absolute;bottom:-20px;font-size:8.5px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%;text-align:center;"><?= $d['label'] ?></div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Delivery rates summary -->
  <div class="card">
    <div class="card-header"><div class="card-title">Campaign Delivery Performance</div></div>
    <div style="display:flex;flex-direction:column;gap:16px;">
      <?php
      $metrics = [
        ['label' => 'Delivery Rate', 'value' => $deliveryRate, 'color' => '#22c55e', 'count' => $totalCampDelivered],
        ['label' => 'Read (Seen) Rate', 'value' => $readRate, 'color' => '#06b6d4', 'count' => $totalCampRead],
        ['label' => 'Failure Rate', 'value' => $totalCampaignSent ? round($totalCampFailed/$totalCampaignSent*100,1) : 0, 'color' => 'var(--danger)', 'count' => $totalCampFailed],
      ];
      foreach ($metrics as $m): ?>
      <div>
        <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:6px;">
          <span style="color:var(--text2);"><?= $m['label'] ?></span>
          <span style="font-weight:700;color:<?= $m['color'] ?>;"><?= $m['value'] ?>%</span>
        </div>
        <div style="height:6px;background:var(--surface3);border-radius:3px;overflow:hidden;">
          <div style="height:100%;width:<?= min(100, $m['value']) ?>%;background:<?= $m['color'] ?>;border-radius:3px;transition:width 0.5s;"></div>
        </div>
        <div style="font-size:11px;color:var(--muted);margin-top:3px;"><?= formatNumber($m['count']) ?> messages</div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>

<!-- ======================================================== -->
<!-- Day-Wise Breakdown Table (दिवसनिहाय मेसेज तपशील) -->
<!-- ======================================================== -->
<div class="card" style="margin-bottom:24px;">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
    <div>
      <div class="card-title" style="display:flex;align-items:center;gap:8px;">
        <span>📅 Day-Wise Message Volume Breakdown</span>
        <span style="font-size:12px;padding:2px 8px;border-radius:12px;background:var(--surface3);color:var(--muted);font-weight:normal;">
          <?= count($daysBreakdown) ?> days
        </span>
      </div>
      <div style="font-size:12px;color:var(--muted);margin-top:2px;">
        Detailed count of outbound sent, delivered, read (seen), and incoming replies for each day
      </div>
    </div>
    <div>
      <a href="?export=csv&from=<?= urlencode($fromDate) ?>&to=<?= urlencode($toDate) ?>" class="btn btn-secondary btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
        📥 <span>Export CSV</span>
      </a>
    </div>
  </div>

  <div style="overflow-x:auto;">
    <table class="table" style="width:100%;border-collapse:collapse;font-size:13px;text-align:left;">
      <thead>
        <tr style="border-bottom:1px solid var(--border);background:var(--surface2);">
          <th style="padding:10px 14px;color:var(--text2);font-weight:600;">Date</th>
          <th style="padding:10px 14px;color:var(--text2);font-weight:600;text-align:right;">Total Sent (Outbound)</th>
          <th style="padding:10px 14px;color:var(--text2);font-weight:600;text-align:right;">Delivered</th>
          <th style="padding:10px 14px;color:var(--text2);font-weight:600;text-align:right;">Read (Seen)</th>
          <th style="padding:10px 14px;color:var(--text2);font-weight:600;text-align:right;">Inbound Received</th>
          <th style="padding:10px 14px;color:var(--text2);font-weight:600;text-align:right;">Delivery %</th>
          <th style="padding:10px 14px;color:var(--text2);font-weight:600;text-align:right;">Read %</th>
          <th style="padding:10px 14px;color:var(--text2);font-weight:600;text-align:center;">Type Breakdown</th>
        </tr>
      </thead>
      <tbody>
        <?php 
        $grandTableSent = 0;
        $grandTableDeliv = 0;
        $grandTableRead = 0;
        $grandTableRecv = 0;
        $activeDaysCount = 0;
        
        foreach ($daysBreakdown as $row): 
          $grandTableSent += $row['total_sent'];
          $grandTableDeliv += $row['delivered'];
          $grandTableRead += $row['read'];
          $grandTableRecv += $row['received'];
          if ($row['has_activity']) $activeDaysCount++;
          $isToday = ($row['date'] === date('Y-m-d'));
        ?>
        <tr style="border-bottom:1px solid var(--border);<?= $isToday ? 'background:rgba(37,211,102,0.05);' : '' ?><?= !$row['has_activity'] ? 'opacity:0.6;' : '' ?>">
          <td style="padding:10px 14px;font-weight:500;">
            <div style="display:flex;align-items:center;gap:6px;">
              <span><?= e($row['label']) ?></span>
              <?php if ($isToday): ?>
                <span style="font-size:10px;padding:1px 6px;border-radius:4px;background:var(--accent);color:#000;font-weight:700;">TODAY</span>
              <?php endif; ?>
            </div>
            <div style="font-size:11px;color:var(--muted);"><?= $row['date'] ?></div>
          </td>
          
          <td style="padding:10px 14px;text-align:right;font-weight:700;color:<?= $row['total_sent'] > 0 ? 'var(--accent)' : 'var(--text2)' ?>;">
            <?= formatNumber($row['total_sent']) ?>
          </td>
          
          <td style="padding:10px 14px;text-align:right;color:<?= $row['delivered'] > 0 ? '#22c55e' : 'var(--muted)' ?>;font-weight:600;">
            <?= formatNumber($row['delivered']) ?>
          </td>
          
          <td style="padding:10px 14px;text-align:right;color:<?= $row['read'] > 0 ? '#06b6d4' : 'var(--muted)' ?>;font-weight:600;">
            <?= formatNumber($row['read']) ?>
          </td>
          
          <td style="padding:10px 14px;text-align:right;font-weight:600;color:<?= $row['received'] > 0 ? 'var(--blue)' : 'var(--muted)' ?>;">
            <?= formatNumber($row['received']) ?>
          </td>
          
          <td style="padding:10px 14px;text-align:right;">
            <?php if ($row['total_sent'] > 0): ?>
              <span style="font-size:12px;font-weight:600;color:#22c55e;"><?= $row['deliv_pct'] ?>%</span>
            <?php else: ?>
              <span style="color:var(--muted);">-</span>
            <?php endif; ?>
          </td>
          
          <td style="padding:10px 14px;text-align:right;">
            <?php if ($row['total_sent'] > 0): ?>
              <span style="font-size:12px;font-weight:600;color:#06b6d4;"><?= $row['read_pct'] ?>%</span>
            <?php else: ?>
              <span style="color:var(--muted);">-</span>
            <?php endif; ?>
          </td>
          
          <td style="padding:10px 14px;text-align:center;">
            <?php if ($row['chat_sent'] > 0 || $row['camp_sent'] > 0): ?>
              <div style="display:inline-flex;gap:4px;font-size:11px;">
                <?php if ($row['camp_sent'] > 0): ?>
                  <span style="padding:2px 6px;border-radius:4px;background:rgba(168,85,247,0.15);color:#c084fc;">📢 <?= $row['camp_sent'] ?></span>
                <?php endif; ?>
                <?php if ($row['chat_sent'] > 0): ?>
                  <span style="padding:2px 6px;border-radius:4px;background:rgba(37,211,102,0.15);color:var(--accent);">💬 <?= $row['chat_sent'] ?></span>
                <?php endif; ?>
              </div>
            <?php else: ?>
              <span style="font-size:11px;color:var(--muted);">-</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      
      <!-- Footer summary -->
      <tfoot>
        <tr style="border-top:2px solid var(--border);background:var(--surface2);font-weight:700;">
          <td style="padding:12px 14px;color:var(--text);">
            Total (<?= count($daysBreakdown) ?> days, <?= $activeDaysCount ?> active)
          </td>
          <td style="padding:12px 14px;text-align:right;color:var(--accent);font-size:14px;">
            <?= formatNumber($grandTableSent) ?>
          </td>
          <td style="padding:12px 14px;text-align:right;color:#22c55e;">
            <?= formatNumber($grandTableDeliv) ?>
          </td>
          <td style="padding:12px 14px;text-align:right;color:#06b6d4;">
            <?= formatNumber($grandTableRead) ?>
          </td>
          <td style="padding:12px 14px;text-align:right;color:var(--blue);font-size:14px;">
            <?= formatNumber($grandTableRecv) ?>
          </td>
          <td style="padding:12px 14px;text-align:right;color:#22c55e;">
            <?= $grandTableSent > 0 ? round(($grandTableDeliv / $grandTableSent) * 100, 1) : 0 ?>%
          </td>
          <td style="padding:12px 14px;text-align:right;color:#06b6d4;">
            <?= $grandTableSent > 0 ? round(($grandTableRead / $grandTableSent) * 100, 1) : 0 ?>%
          </td>
          <td style="padding:12px 14px;text-align:center;font-size:11px;color:var(--muted);">
            Summary
          </td>
        </tr>
      </tfoot>
    </table>
  </div>
</div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">
  <!-- Top campaigns in period -->
  <div class="card">
    <div class="card-header"><div class="card-title">Top Campaigns in Selected Period</div></div>
    <?php if (empty($topCampaigns)): ?>
      <div style="text-align:center;padding:30px;color:var(--muted);font-size:13px;">No completed campaigns in this period</div>
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

  <!-- Contact sources in period -->
  <div class="card">
    <div class="card-header"><div class="card-title">Contact Sources in Selected Period</div></div>
    <?php if (empty($contactSources)): ?>
      <div style="text-align:center;padding:30px;color:var(--muted);font-size:13px;">No new contacts added in this period</div>
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
