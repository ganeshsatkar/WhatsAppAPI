<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();
$pageTitle = 'Outbound Messages';
$uid = (int)currentUser()['id'];

// --- Filters ---
$tplFilter  = sanitize($_GET['template'] ?? '');
$statusFilter = sanitize($_GET['status'] ?? '');
$search     = sanitize($_GET['q'] ?? '');
$range      = sanitize($_GET['range'] ?? 'all');
$fromDate   = sanitize($_GET['from'] ?? '');
$toDate     = sanitize($_GET['to'] ?? '');

$today = date('Y-m-d');
if ($range === 'today') {
    $fromDate = $today; $toDate = $today;
} elseif ($range === 'yesterday') {
    $fromDate = date('Y-m-d', strtotime('-1 day')); $toDate = $fromDate;
} elseif ($range === '7d') {
    $fromDate = date('Y-m-d', strtotime('-6 days')); $toDate = $today;
} elseif ($range === '30d') {
    $fromDate = date('Y-m-d', strtotime('-29 days')); $toDate = $today;
}

// Build query conditions for campaign_logs
$where = ['cl.user_id = ?'];
$params = [$uid];

if (!empty($tplFilter)) {
    $where[] = 'mt.template_name = ?';
    $params[] = $tplFilter;
}

if (!empty($statusFilter)) {
    if ($statusFilter === 'delivered') {
        $where[] = "cl.status IN ('delivered', 'read')";
    } else {
        $where[] = 'cl.status = ?';
        $params[] = $statusFilter;
    }
}

if (!empty($search)) {
    $where[] = '(cl.phone LIKE ? OR c.name LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

if (!empty($fromDate) && !empty($toDate)) {
    $where[] = 'DATE(COALESCE(cl.sent_at, cl.created_at)) BETWEEN ? AND ?';
    $params[] = $fromDate;
    $params[] = $toDate;
}

$whereClause = implode(' AND ', $where);

// --- CSV Export ---
if (($_GET['export'] ?? '') === 'csv') {
    $filename = "outbound_messages_" . date('Ymd_His') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM
    fputcsv($out, ['ID', 'Phone Number', 'Contact Name', 'Template Name', 'Category', 'Campaign', 'Status', 'Sent At (IST)', 'Delivered At', 'Read At', 'Error Reason']);

    $exportRows = db()->all("SELECT cl.id, cl.phone, c.name as contact_name, mt.template_name, mt.category, 
        camp.name as camp_name, cl.status, cl.sent_at, cl.delivered_at, cl.read_at, cl.error_message, cl.created_at
        FROM campaign_logs cl
        LEFT JOIN campaigns camp ON camp.id = cl.campaign_id
        LEFT JOIN message_templates mt ON mt.id = camp.template_id
        LEFT JOIN contacts c ON c.id = cl.contact_id
        WHERE $whereClause
        ORDER BY cl.id DESC", $params);

    foreach ($exportRows as $r) {
        $sentTime = $r['sent_at'] ? fmtIst($r['sent_at'], 'd M Y, h:i A') : ($r['created_at'] ? fmtIst($r['created_at'], 'd M Y, h:i A') : '—');
        fputcsv($out, [
            $r['id'],
            $r['phone'],
            $r['contact_name'] ?: '—',
            $r['template_name'] ?: '—',
            $r['category'] ?: '—',
            $r['camp_name'] ?: '—',
            strtoupper($r['status']),
            $sentTime,
            $r['delivered_at'] ? fmtIst($r['delivered_at'], 'd M Y, h:i A') : '—',
            $r['read_at'] ? fmtIst($r['read_at'], 'd M Y, h:i A') : '—',
            $r['error_message'] ?: ''
        ]);
    }
    fclose($out);
    exit;
}

// --- Overview Counters ---
$totalCount = (int)db()->count("SELECT COUNT(*) FROM campaign_logs cl LEFT JOIN campaigns camp ON camp.id=cl.campaign_id LEFT JOIN message_templates mt ON mt.id=camp.template_id LEFT JOIN contacts c ON c.id=cl.contact_id WHERE $whereClause", $params);

$statusCounts = db()->all("SELECT cl.status, COUNT(*) as cnt 
    FROM campaign_logs cl 
    LEFT JOIN campaigns camp ON camp.id=cl.campaign_id 
    LEFT JOIN message_templates mt ON mt.id=camp.template_id 
    LEFT JOIN contacts c ON c.id=cl.contact_id 
    WHERE $whereClause 
    GROUP BY cl.status", $params);

$cntMap = ['sent' => 0, 'delivered' => 0, 'read' => 0, 'failed' => 0, 'pending' => 0];
foreach ($statusCounts as $sc) {
    if (isset($cntMap[$sc['status']])) $cntMap[$sc['status']] = (int)$sc['cnt'];
}
$deliveredOrRead = $cntMap['delivered'] + $cntMap['read'];
$delivRate = $totalCount > 0 ? round(($deliveredOrRead / $totalCount) * 100, 1) : 0;
$readRate  = $totalCount > 0 ? round(($cntMap['read'] / $totalCount) * 100, 1) : 0;

// --- Template-Wise Aggregated Summary ---
$templateStats = db()->all("SELECT 
    COALESCE(mt.template_name, 'Unknown') as tpl_name,
    COALESCE(mt.category, 'MARKETING') as category,
    COUNT(*) as total_sent,
    SUM(CASE WHEN cl.status IN ('delivered', 'read') THEN 1 ELSE 0 END) as delivered,
    SUM(CASE WHEN cl.status = 'read' THEN 1 ELSE 0 END) as read_count,
    SUM(CASE WHEN cl.status = 'failed' THEN 1 ELSE 0 END) as failed_count
    FROM campaign_logs cl
    LEFT JOIN campaigns camp ON camp.id = cl.campaign_id
    LEFT JOIN message_templates mt ON mt.id = camp.template_id
    WHERE cl.user_id = ?
    GROUP BY tpl_name, category
    ORDER BY total_sent DESC", [$uid]);

// --- Pagination & List Query ---
$page  = max(1, (int)($_GET['p'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;
$totalPages = max(1, (int)ceil($totalCount / $limit));

$listRows = db()->all("SELECT cl.id, cl.phone, c.name as contact_name, c.id as cid, mt.template_name, mt.category, 
    camp.name as camp_name, camp.id as camp_id, cl.status, cl.sent_at, cl.delivered_at, cl.read_at, cl.error_message, cl.created_at
    FROM campaign_logs cl
    LEFT JOIN campaigns camp ON camp.id = cl.campaign_id
    LEFT JOIN message_templates mt ON mt.id = camp.template_id
    LEFT JOIN contacts c ON c.id = cl.contact_id
    WHERE $whereClause
    ORDER BY cl.id DESC
    LIMIT $limit OFFSET $offset", $params);

// Fetch all available templates for dropdown
$allTemplates = db()->all("SELECT DISTINCT template_name FROM message_templates WHERE user_id = ? ORDER BY template_name ASC", [$uid]);

include __DIR__ . '/../includes/user_header.php';
?>

<div style="display:flex;flex-direction:column;gap:20px;margin-bottom:30px;">

  <!-- Header -->
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
      <h2 style="margin:0;font-size:22px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px;">
        📤 Outbound Messages (टेंपलेट व नंबरनिहाय रिपोर्ट)
      </h2>
      <div style="font-size:13px;color:var(--muted);margin-top:4px;">
        Track sent, delivered, read (blue tick), and failed messages by template and recipient number — exactly like AiSensy.
      </div>
    </div>
    
    <div style="display:flex;gap:10px;align-items:center;">
      <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>" class="btn btn-secondary btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
        📥 <span>Export CSV</span>
      </a>
      <a href="/user/campaigns.php" class="btn btn-primary btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
        📢 <span>New Campaign</span>
      </a>
    </div>
  </div>

  <!-- Stats Grid -->
  <div class="stats-grid" style="grid-template-columns:repeat(auto-fit, minmax(170px, 1fr));gap:12px;">
    <div class="stat-card" style="--c:var(--accent);--c-dim:var(--accent-dim)">
      <div class="stat-icon">📤</div>
      <div class="stat-value" style="color:var(--accent)"><?= formatNumber($totalCount) ?></div>
      <div class="stat-label">Total Outbound Sent</div>
    </div>
    <div class="stat-card" style="--c:#22c55e;--c-dim:rgba(34,197,94,0.1)">
      <div class="stat-icon">✓✓</div>
      <div class="stat-value" style="color:#22c55e"><?= formatNumber($deliveredOrRead) ?></div>
      <div class="stat-label">Delivered (<?= $delivRate ?>%)</div>
    </div>
    <div class="stat-card" style="--c:#06b6d4;--c-dim:rgba(6,182,212,0.1)">
      <div class="stat-icon">👁️</div>
      <div class="stat-value" style="color:#06b6d4"><?= formatNumber($cntMap['read']) ?></div>
      <div class="stat-label">Read / Seen (<?= $readRate ?>%)</div>
    </div>
    <div class="stat-card" style="--c:var(--danger);--c-dim:rgba(239,68,68,0.1)">
      <div class="stat-icon">✗</div>
      <div class="stat-value" style="color:var(--danger)"><?= formatNumber($cntMap['failed']) ?></div>
      <div class="stat-label">Failed</div>
    </div>
  </div>

  <!-- Template-Wise Aggregated Summary (AiSensy style) -->
  <div class="card" style="margin-bottom:6px;">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
      <div class="card-title" style="display:flex;align-items:center;gap:8px;">
        <span>📋 Template-Wise Performance Breakdown</span>
        <span style="font-size:11px;padding:2px 8px;border-radius:12px;background:var(--surface3);color:var(--muted);">
          <?= count($templateStats) ?> templates used
        </span>
      </div>
      <?php if (!empty($tplFilter)): ?>
        <a href="?<?= http_build_query(array_merge($_GET, ['template'=>''])) ?>" style="font-size:12px;color:var(--accent);text-decoration:none;">✕ Clear Template Filter</a>
      <?php endif; ?>
    </div>
    <div style="overflow-x:auto;">
      <table class="table" style="width:100%;border-collapse:collapse;font-size:13px;text-align:left;">
        <thead>
          <tr style="border-bottom:1px solid var(--border);background:var(--surface2);">
            <th style="padding:10px 14px;color:var(--text2);font-weight:600;">Template Name</th>
            <th style="padding:10px 14px;color:var(--text2);font-weight:600;">Category</th>
            <th style="padding:10px 14px;color:var(--text2);font-weight:600;text-align:right;">Sent</th>
            <th style="padding:10px 14px;color:var(--text2);font-weight:600;text-align:right;">Delivered</th>
            <th style="padding:10px 14px;color:var(--text2);font-weight:600;text-align:right;">Read (Seen)</th>
            <th style="padding:10px 14px;color:var(--text2);font-weight:600;text-align:right;">Failed</th>
            <th style="padding:10px 14px;color:var(--text2);font-weight:600;text-align:right;">Read Rate %</th>
            <th style="padding:10px 14px;color:var(--text2);font-weight:600;text-align:center;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($templateStats)): ?>
            <tr><td colspan="8" style="padding:24px;text-align:center;color:var(--muted);">No outbound campaign templates sent yet.</td></tr>
          <?php else: ?>
            <?php foreach ($templateStats as $ts): 
              $isSel = ($tplFilter === $ts['tpl_name']);
              $rRate = $ts['total_sent'] > 0 ? round(($ts['read_count'] / $ts['total_sent']) * 100, 1) : 0;
            ?>
            <tr style="border-bottom:1px solid var(--border);<?= $isSel ? 'background:rgba(37,211,102,0.08);' : '' ?>">
              <td style="padding:10px 14px;font-weight:600;">
                <code style="background:var(--surface2);padding:2px 6px;border-radius:4px;color:var(--text);font-size:12px;"><?= e($ts['tpl_name']) ?></code>
              </td>
              <td style="padding:10px 14px;">
                <span class="badge" style="font-size:11px;padding:2px 6px;border-radius:4px;background:<?= $ts['category']==='UTILITY'?'rgba(6,182,212,0.15)':'rgba(168,85,247,0.15)' ?>;color:<?= $ts['category']==='UTILITY'?'#06b6d4':'#c084fc' ?>;">
                  <?= e($ts['category']) ?>
                </span>
              </td>
              <td style="padding:10px 14px;text-align:right;font-weight:700;color:var(--accent);"><?= formatNumber($ts['total_sent']) ?></td>
              <td style="padding:10px 14px;text-align:right;color:#22c55e;font-weight:600;"><?= formatNumber($ts['delivered']) ?></td>
              <td style="padding:10px 14px;text-align:right;color:#06b6d4;font-weight:600;"><?= formatNumber($ts['read_count']) ?></td>
              <td style="padding:10px 14px;text-align:right;color:<?= $ts['failed_count'] > 0 ? 'var(--danger)' : 'var(--muted)' ?>;font-weight:600;">
                <?= formatNumber($ts['failed_count']) ?>
              </td>
              <td style="padding:10px 14px;text-align:right;font-weight:700;color:#06b6d4;"><?= $rRate ?>%</td>
              <td style="padding:10px 14px;text-align:center;">
                <a href="?template=<?= urlencode($ts['tpl_name']) ?>" class="btn btn-sm <?= $isSel ? 'btn-primary' : 'btn-secondary' ?>" style="font-size:11px;padding:3px 8px;">
                  <?= $isSel ? '✓ Filtering' : 'Filter by this' ?>
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Filter & Search Controls -->
  <div class="card" style="padding:14px 18px;background:var(--surface);">
    <form method="GET" action="" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin:0;">
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;flex:1;">
        <!-- Search Phone or Name -->
        <div style="position:relative;width:220px;">
          <input type="text" name="q" value="<?= e($search) ?>" placeholder="🔍 Search phone or name..." class="form-control" style="width:100%;padding:7px 10px;font-size:13px;border-radius:6px;border:1px solid var(--border);background:var(--surface2);color:var(--text);">
        </div>

        <!-- Template Filter -->
        <select name="template" class="form-control" style="width:190px;padding:7px 10px;font-size:13px;border-radius:6px;border:1px solid var(--border);background:var(--surface2);color:var(--text);">
          <option value="">All Templates</option>
          <?php foreach ($allTemplates as $t): ?>
            <option value="<?= e($t['template_name']) ?>" <?= $tplFilter === $t['template_name'] ? 'selected' : '' ?>>
              <?= e($t['template_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <!-- Status Filter -->
        <select name="status" class="form-control" style="width:140px;padding:7px 10px;font-size:13px;border-radius:6px;border:1px solid var(--border);background:var(--surface2);color:var(--text);">
          <option value="">All Statuses</option>
          <option value="read" <?= $statusFilter === 'read' ? 'selected' : '' ?>>Read (Seen)</option>
          <option value="delivered" <?= $statusFilter === 'delivered' ? 'selected' : '' ?>>Delivered</option>
          <option value="sent" <?= $statusFilter === 'sent' ? 'selected' : '' ?>>Sent</option>
          <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>Failed</option>
        </select>

        <!-- Quick Range -->
        <select name="range" class="form-control" style="width:130px;padding:7px 10px;font-size:13px;border-radius:6px;border:1px solid var(--border);background:var(--surface2);color:var(--text);">
          <option value="all" <?= $range === 'all' ? 'selected' : '' ?>>All Time</option>
          <option value="today" <?= $range === 'today' ? 'selected' : '' ?>>Today</option>
          <option value="yesterday" <?= $range === 'yesterday' ? 'selected' : '' ?>>Yesterday</option>
          <option value="7d" <?= $range === '7d' ? 'selected' : '' ?>>Last 7 Days</option>
          <option value="30d" <?= $range === '30d' ? 'selected' : '' ?>>Last 30 Days</option>
        </select>

        <button type="submit" class="btn btn-primary btn-sm" style="padding:7px 16px;">Filter</button>
        <?php if (!empty($search) || !empty($tplFilter) || !empty($statusFilter) || $range !== 'all'): ?>
          <a href="/user/outbound.php" class="btn btn-secondary btn-sm" style="padding:7px 12px;">Reset</a>
        <?php endif; ?>
      </div>

      <div style="font-size:12px;color:var(--muted);">
        Showing <strong><?= count($listRows) ?></strong> of <strong><?= formatNumber($totalCount) ?></strong> outbound records
      </div>
    </form>
  </div>

  <!-- Number-Wise Outbound Messages Table -->
  <div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
      <div class="card-title" style="display:flex;align-items:center;gap:8px;">
        <span>📱 Number-Wise Recipient Log</span>
        <span style="font-size:12px;padding:2px 8px;border-radius:12px;background:var(--surface3);color:var(--muted);font-weight:normal;">
          Page <?= $page ?> of <?= $totalPages ?>
        </span>
      </div>
      <div>
        <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'csv'])) ?>" class="btn btn-secondary btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
          📥 <span>Export CSV</span>
        </a>
      </div>
    </div>

    <div style="overflow-x:auto;">
      <table class="table" style="width:100%;border-collapse:collapse;font-size:13px;text-align:left;">
        <thead>
          <tr style="border-bottom:1px solid var(--border);background:var(--surface2);">
            <th style="padding:11px 14px;color:var(--text2);font-weight:600;">Recipient / Phone</th>
            <th style="padding:11px 14px;color:var(--text2);font-weight:600;">Template Name</th>
            <th style="padding:11px 14px;color:var(--text2);font-weight:600;">Campaign</th>
            <th style="padding:11px 14px;color:var(--text2);font-weight:600;">Status & Lifecycle</th>
            <th style="padding:11px 14px;color:var(--text2);font-weight:600;">Sent Time (IST)</th>
            <th style="padding:11px 14px;color:var(--text2);font-weight:600;text-align:center;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($listRows)): ?>
            <tr>
              <td colspan="6" style="padding:35px;text-align:center;color:var(--muted);">
                <div style="font-size:24px;margin-bottom:8px;">📭</div>
                <div>No outbound messages match your filter criteria.</div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($listRows as $row): 
              $phone = $row['phone'];
              $isRead = ($row['status'] === 'read');
              $isDelivered = in_array($row['status'], ['delivered', 'read']);
              $isFailed = ($row['status'] === 'failed');
            ?>
            <tr style="border-bottom:1px solid var(--border);">
              <!-- Recipient & Phone -->
              <td style="padding:11px 14px;">
                <div style="display:flex;align-items:center;gap:8px;">
                  <span style="font-size:16px;">📱</span>
                  <div>
                    <div style="font-weight:600;color:var(--text);letter-spacing:0.3px;">
                      +<?= e($phone) ?>
                    </div>
                    <?php if (!empty($row['contact_name'])): ?>
                      <div style="font-size:11px;color:var(--muted);"><?= e($row['contact_name']) ?></div>
                    <?php endif; ?>
                  </div>
                </div>
              </td>

              <!-- Template -->
              <td style="padding:11px 14px;">
                <div>
                  <code style="background:var(--surface2);padding:3px 7px;border-radius:4px;font-size:12px;color:var(--text);font-weight:600;">
                    <?= e($row['template_name'] ?: 'Direct Message') ?>
                  </code>
                </div>
                <?php if (!empty($row['category'])): ?>
                  <div style="margin-top:3px;">
                    <span style="font-size:10px;text-transform:uppercase;color:var(--muted);letter-spacing:0.5px;">
                      <?= e($row['category']) ?>
                    </span>
                  </div>
                <?php endif; ?>
              </td>

              <!-- Campaign -->
              <td style="padding:11px 14px;">
                <?php if (!empty($row['camp_name'])): ?>
                  <a href="/user/campaign_report.php?id=<?= (int)$row['camp_id'] ?>" style="color:var(--accent);text-decoration:none;font-weight:500;">
                    <?= e($row['camp_name']) ?>
                  </a>
                <?php else: ?>
                  <span style="color:var(--muted);">Direct Outbound</span>
                <?php endif; ?>
              </td>

              <!-- Status with Visual Ticks (Sent, Delivered, Read, Failed) -->
              <td style="padding:11px 14px;">
                <?php if ($isRead): ?>
                  <div style="display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:12px;background:rgba(6,182,212,0.12);color:#06b6d4;font-weight:700;font-size:12px;">
                    <span>✓✓ Read (Seen)</span>
                  </div>
                  <?php if (!empty($row['read_at'])): ?>
                    <div style="font-size:10.5px;color:var(--muted);margin-top:2px;"><?= fmtIst($row['read_at'], 'd M, h:i A') ?></div>
                  <?php endif; ?>

                <?php elseif ($row['status'] === 'delivered'): ?>
                  <div style="display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:12px;background:rgba(34,197,94,0.12);color:#22c55e;font-weight:700;font-size:12px;">
                    <span>✓✓ Delivered</span>
                  </div>
                  <?php if (!empty($row['delivered_at'])): ?>
                    <div style="font-size:10.5px;color:var(--muted);margin-top:2px;"><?= fmtIst($row['delivered_at'], 'd M, h:i A') ?></div>
                  <?php endif; ?>

                <?php elseif ($row['status'] === 'sent'): ?>
                  <div style="display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:12px;background:var(--surface3);color:var(--text2);font-weight:600;font-size:12px;">
                    <span>✓ Sent to Meta</span>
                  </div>

                <?php elseif ($isFailed): ?>
                  <div style="display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:12px;background:rgba(239,68,68,0.12);color:var(--danger);font-weight:700;font-size:12px;">
                    <span>✗ Failed</span>
                  </div>
                  <?php if (!empty($row['error_message'])): ?>
                    <div style="font-size:11px;color:var(--danger);margin-top:3px;max-width:240px;word-break:break-word;">
                      <?= e($row['error_message']) ?>
                    </div>
                  <?php endif; ?>

                <?php else: ?>
                  <span style="color:var(--muted);font-size:12px;text-transform:capitalize;"><?= e($row['status']) ?></span>
                <?php endif; ?>
              </td>

              <!-- Sent Time -->
              <td style="padding:11px 14px;color:var(--text2);font-size:12px;">
                <?php $t = $row['sent_at'] ?: $row['created_at']; ?>
                <div><?= $t ? fmtIst($t, 'd M Y') : '—' ?></div>
                <div style="font-size:11px;color:var(--muted);"><?= $t ? fmtIst($t, 'h:i A') : '' ?></div>
              </td>

              <!-- Action (Chat Button) -->
              <td style="padding:11px 14px;text-align:center;">
                <a href="/user/inbox.php?q=<?= urlencode($phone) ?>" class="btn btn-secondary btn-sm" style="font-size:11.5px;padding:4px 10px;display:inline-flex;align-items:center;gap:4px;" title="Open conversation in Team Inbox">
                  💬 <span>Chat</span>
                </a>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($totalPages > 1): ?>
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-top:1px solid var(--border);flex-wrap:wrap;gap:10px;">
      <div style="font-size:12px;color:var(--muted);">
        Page <strong><?= $page ?></strong> of <strong><?= $totalPages ?></strong>
      </div>
      <div style="display:flex;gap:6px;">
        <?php if ($page > 1): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['p'=>$page-1])) ?>" class="btn btn-secondary btn-sm">← Prev</a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
          <a href="?<?= http_build_query(array_merge($_GET, ['p'=>$page+1])) ?>" class="btn btn-secondary btn-sm">Next →</a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
  </div>

</div>

<?php include __DIR__ . '/../includes/user_footer.php'; ?>
