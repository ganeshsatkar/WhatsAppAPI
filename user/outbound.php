<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();
$pageTitle = 'Outbound & Template Reports';
$uid = (int)currentUser()['id'];

// Active View Tab: 'templates' (सगळे टेम्पलेट्स), 'recipients' (कुणाला किती पाठवले), 'logs' (सर्व लॉग्स)
$tab = sanitize($_GET['tab'] ?? 'templates');
if (!in_array($tab, ['templates', 'recipients', 'logs'])) $tab = 'templates';

// Filters
$tplFilter    = sanitize($_GET['template'] ?? '');
$statusFilter = sanitize($_GET['status'] ?? '');
$search       = sanitize($_GET['q'] ?? '');
$range        = sanitize($_GET['range'] ?? 'all');
$fromDate     = sanitize($_GET['from'] ?? '');
$toDate       = sanitize($_GET['to'] ?? '');

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

// Base conditions for campaign_logs
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
    $where[] = '(cl.phone LIKE ? OR COALESCE((SELECT name FROM contacts WHERE user_id = cl.user_id AND phone = cl.phone LIMIT 1), "") LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

if (!empty($fromDate) && !empty($toDate)) {
    $where[] = 'DATE(COALESCE(cl.sent_at, cl.created_at)) BETWEEN ? AND ?';
    $params[] = $fromDate;
    $params[] = $toDate;
}

$whereClause = implode(' AND ', $where);

// --- CSV Export Handlers ---
if (($_GET['export'] ?? '') === 'csv') {
    $exportType = sanitize($_GET['type'] ?? 'logs');
    $filename = "wpapi_{$exportType}_report_" . date('Ymd_His') . ".csv";
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF"); // UTF-8 BOM

    if ($exportType === 'templates') {
        fputcsv($out, ['Template Name', 'Category', 'Meta Status', 'Total Sent', 'Delivered', 'Read (Seen)', 'Failed', 'Delivery Rate %', 'Read Rate %']);
        $allTplStats = db()->all("SELECT mt.template_name, mt.category, mt.status as meta_status,
            COUNT(cl.id) as total_sent,
            SUM(CASE WHEN cl.status IN ('delivered','read') THEN 1 ELSE 0 END) as delivered,
            SUM(CASE WHEN cl.status = 'read' THEN 1 ELSE 0 END) as read_count,
            SUM(CASE WHEN cl.status = 'failed' THEN 1 ELSE 0 END) as failed_count
            FROM message_templates mt
            LEFT JOIN campaigns camp ON camp.template_id = mt.id AND camp.user_id = mt.user_id
            LEFT JOIN campaign_logs cl ON cl.campaign_id = camp.id
            WHERE mt.user_id = ?
            GROUP BY mt.id, mt.template_name, mt.category, mt.status
            ORDER BY total_sent DESC, mt.template_name ASC", [$uid]);
        foreach ($allTplStats as $r) {
            $delivPct = $r['total_sent'] > 0 ? round(($r['delivered'] / $r['total_sent']) * 100, 1) : 0;
            $readPct  = $r['total_sent'] > 0 ? round(($r['read_count'] / $r['total_sent']) * 100, 1) : 0;
            fputcsv($out, [$r['template_name'], $r['category'], strtoupper($r['meta_status']), (int)$r['total_sent'], (int)$r['delivered'], (int)$r['read_count'], (int)$r['failed_count'], $delivPct . '%', $readPct . '%']);
        }
    } elseif ($exportType === 'recipients') {
        fputcsv($out, ['Phone Number', 'Contact Name', 'Total Sent', 'Templates Received', 'Delivered', 'Read (Seen)', 'Failed', 'Last Sent Time (IST)']);
        $recipStats = db()->all("SELECT cl.phone,
            COALESCE((SELECT name FROM contacts WHERE user_id = cl.user_id AND phone = cl.phone LIMIT 1), (SELECT c2.name FROM contacts c2 WHERE c2.id = cl.contact_id LIMIT 1), 'Customer') as contact_name,
            COUNT(cl.id) as total_sent,
            SUM(CASE WHEN cl.status IN ('delivered', 'read') THEN 1 ELSE 0 END) as delivered,
            SUM(CASE WHEN cl.status = 'read' THEN 1 ELSE 0 END) as read_count,
            SUM(CASE WHEN cl.status = 'failed' THEN 1 ELSE 0 END) as failed_count,
            MAX(COALESCE(cl.sent_at, cl.created_at)) as last_sent_at,
            GROUP_CONCAT(DISTINCT mt.template_name ORDER BY mt.template_name SEPARATOR ', ') as templates_used
            FROM campaign_logs cl
            LEFT JOIN campaigns camp ON camp.id = cl.campaign_id
            LEFT JOIN message_templates mt ON mt.id = camp.template_id
            WHERE $whereClause
            GROUP BY cl.phone
            ORDER BY total_sent DESC, last_sent_at DESC", $params);
        foreach ($recipStats as $r) {
            fputcsv($out, [$r['phone'], $r['contact_name'], (int)$r['total_sent'], $r['templates_used'] ?: '—', (int)$r['delivered'], (int)$r['read_count'], (int)$r['failed_count'], $r['last_sent_at'] ? fmtIst($r['last_sent_at'], 'd M Y, h:i A') : '—']);
        }
    } else {
        fputcsv($out, ['ID', 'Phone Number', 'Contact Name', 'Template Name', 'Category', 'Campaign', 'Status', 'Sent At (IST)', 'Delivered At', 'Read At', 'Error Reason']);
        $rawLogs = db()->all("SELECT cl.id, cl.phone,
            COALESCE((SELECT name FROM contacts WHERE user_id = cl.user_id AND phone = cl.phone LIMIT 1), (SELECT c2.name FROM contacts c2 WHERE c2.id = cl.contact_id LIMIT 1), 'Customer') as contact_name,
            mt.template_name, mt.category, camp.name as camp_name, cl.status, cl.sent_at, cl.delivered_at, cl.read_at, cl.error_message, cl.created_at
            FROM campaign_logs cl
            LEFT JOIN campaigns camp ON camp.id = cl.campaign_id
            LEFT JOIN message_templates mt ON mt.id = camp.template_id
            WHERE $whereClause
            ORDER BY cl.id DESC", $params);
        foreach ($rawLogs as $r) {
            $sentTime = $r['sent_at'] ? fmtIst($r['sent_at'], 'd M Y, h:i A') : ($r['created_at'] ? fmtIst($r['created_at'], 'd M Y, h:i A') : '—');
            fputcsv($out, [$r['id'], $r['phone'], $r['contact_name'], $r['template_name'] ?: '—', $r['category'] ?: '—', $r['camp_name'] ?: '—', strtoupper($r['status']), $sentTime, $r['delivered_at'] ? fmtIst($r['delivered_at'], 'd M Y, h:i A') : '—', $r['read_at'] ? fmtIst($r['read_at'], 'd M Y, h:i A') : '—', $r['error_message'] ?: '']);
        }
    }
    fclose($out);
    exit;
}

// --- Platform High-Level Summary Stats ---
$totalTemplatesCount = (int)db()->count("SELECT COUNT(*) FROM message_templates WHERE user_id = ?", [$uid]);
$totalMessagesSent   = (int)db()->count("SELECT COUNT(*) FROM campaign_logs WHERE user_id = ?", [$uid]);
$totalDeliveredCount = (int)db()->count("SELECT COUNT(*) FROM campaign_logs WHERE user_id = ? AND status IN ('delivered', 'read')", [$uid]);
$totalReadCount      = (int)db()->count("SELECT COUNT(*) FROM campaign_logs WHERE user_id = ? AND status = 'read'", [$uid]);
$totalFailedCount    = (int)db()->count("SELECT COUNT(*) FROM campaign_logs WHERE user_id = ? AND status = 'failed'", [$uid]);
$uniqueContactsCount = (int)db()->count("SELECT COUNT(DISTINCT phone) FROM campaign_logs WHERE user_id = ?", [$uid]);

// --- 1. Query: ALL Templates with their Sent & Delivery counts ---
$allTemplateStats = db()->all("SELECT mt.id as tpl_id, mt.template_name, mt.category, mt.status as meta_status,
    COUNT(cl.id) as total_sent,
    SUM(CASE WHEN cl.status IN ('delivered','read') THEN 1 ELSE 0 END) as delivered,
    SUM(CASE WHEN cl.status = 'read' THEN 1 ELSE 0 END) as read_count,
    SUM(CASE WHEN cl.status = 'failed' THEN 1 ELSE 0 END) as failed_count
    FROM message_templates mt
    LEFT JOIN campaigns camp ON camp.template_id = mt.id AND camp.user_id = mt.user_id
    LEFT JOIN campaign_logs cl ON cl.campaign_id = camp.id
    WHERE mt.user_id = ?
    GROUP BY mt.id, mt.template_name, mt.category, mt.status
    ORDER BY total_sent DESC, mt.template_name ASC", [$uid]);

// --- 2. Query: Recipient-Wise Aggregated Summary ("कुणाला किती पाठवले") ---
$recipientStats = db()->all("SELECT cl.phone,
    COALESCE((SELECT name FROM contacts WHERE user_id = cl.user_id AND phone = cl.phone LIMIT 1), (SELECT c2.name FROM contacts c2 WHERE c2.id = cl.contact_id LIMIT 1), 'Customer') as contact_name,
    COUNT(cl.id) as total_sent,
    SUM(CASE WHEN cl.status IN ('delivered', 'read') THEN 1 ELSE 0 END) as delivered,
    SUM(CASE WHEN cl.status = 'read' THEN 1 ELSE 0 END) as read_count,
    SUM(CASE WHEN cl.status = 'failed' THEN 1 ELSE 0 END) as failed_count,
    MAX(COALESCE(cl.sent_at, cl.created_at)) as last_sent_at,
    GROUP_CONCAT(DISTINCT mt.template_name ORDER BY mt.template_name SEPARATOR ', ') as templates_used
    FROM campaign_logs cl
    LEFT JOIN campaigns camp ON camp.id = cl.campaign_id
    LEFT JOIN message_templates mt ON mt.id = camp.template_id
    WHERE $whereClause
    GROUP BY cl.phone
    ORDER BY total_sent DESC, last_sent_at DESC", $params);

// --- 3. Query: Detailed Logs (Pagination) ---
$page  = max(1, (int)($_GET['p'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;
$totalLogsCount = (int)db()->count("SELECT COUNT(*) FROM campaign_logs cl LEFT JOIN campaigns camp ON camp.id=cl.campaign_id LEFT JOIN message_templates mt ON mt.id=camp.template_id WHERE $whereClause", $params);
$totalPages = max(1, (int)ceil($totalLogsCount / $limit));

$detailedLogs = db()->all("SELECT cl.id, cl.phone,
    COALESCE((SELECT name FROM contacts WHERE user_id = cl.user_id AND phone = cl.phone LIMIT 1), (SELECT c2.name FROM contacts c2 WHERE c2.id = cl.contact_id LIMIT 1), 'Customer') as contact_name,
    mt.template_name, mt.category, camp.name as camp_name, camp.id as camp_id, cl.status, cl.sent_at, cl.delivered_at, cl.read_at, cl.error_message, cl.created_at
    FROM campaign_logs cl
    LEFT JOIN campaigns camp ON camp.id = cl.campaign_id
    LEFT JOIN message_templates mt ON mt.id = camp.template_id
    WHERE $whereClause
    ORDER BY cl.id DESC
    LIMIT $limit OFFSET $offset", $params);

// Fetch all available templates for dropdown
$dropdownTemplates = db()->all("SELECT DISTINCT template_name FROM message_templates WHERE user_id = ? ORDER BY template_name ASC", [$uid]);

// --- Meta Live Cloud API Analytics Query ---
$was = db()->row('SELECT * FROM user_whatsapp_settings WHERE user_id=?', [$uid]);
$metaAnalytics = null;
$metaLiveSent = 0;
$metaLiveDelivered = 0;
$metaPhoneName = '';
$metaRating = 'GREEN';

if ($was && !empty($was['access_token']) && !empty($was['business_account_id'])) {
    $token = decryptSecret($was['access_token']);
    $waba = $was['business_account_id'];
    $phoneId = $was['phone_number_id'] ?? '';
    
    $cacheKey = "meta_waba_analytics_{$uid}";
    $cached = $_SESSION[$cacheKey] ?? null;
    $cacheTime = $_SESSION[$cacheKey . '_time'] ?? 0;
    
    if (isset($_GET['refresh_meta']) || !$cached || (time() - $cacheTime > 300)) {
        $past = time() - (30 * 86400);
        $now = time();
        $ch = curl_init("https://graph.facebook.com/v20.0/$waba?fields=analytics.start($past).end($now).granularity(DAY)");
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        $metaRes = json_decode(curl_exec($ch), true);
        curl_close($ch);
        
        if ($phoneId) {
            $ch = curl_init("https://graph.facebook.com/v20.0/$phoneId?fields=display_phone_number,verified_name,quality_rating");
            curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $token"]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 6);
            $phoneRes = json_decode(curl_exec($ch), true);
            curl_close($ch);
            if (!empty($phoneRes['verified_name'])) {
                $metaPhoneName = $phoneRes['verified_name'] . ' (' . ($phoneRes['display_phone_number'] ?? '') . ')';
                $metaRating = $phoneRes['quality_rating'] ?? 'GREEN';
            }
        }
        
        if (!empty($metaRes['analytics']['data_points'])) {
            $metaAnalytics = $metaRes['analytics']['data_points'];
            $_SESSION[$cacheKey] = $metaAnalytics;
            $_SESSION[$cacheKey . '_info'] = ['name' => $metaPhoneName, 'rating' => $metaRating];
            $_SESSION[$cacheKey . '_time'] = time();
        }
    } else {
        $metaAnalytics = $cached;
        $info = $_SESSION[$cacheKey . '_info'] ?? [];
        $metaPhoneName = $info['name'] ?? '';
        $metaRating = $info['rating'] ?? 'GREEN';
    }
    
    if ($metaAnalytics) {
        foreach ($metaAnalytics as $dp) {
            $metaLiveSent += (int)($dp['sent'] ?? 0);
            $metaLiveDelivered += (int)($dp['delivered'] ?? 0);
        }
    }
}

include __DIR__ . '/../includes/user_header.php';
?>

<div style="display:flex;flex-direction:column;gap:20px;margin-bottom:30px;">

  <!-- Page Title & Header Actions -->
  <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
    <div>
      <h2 style="margin:0;font-size:22px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px;">
        📊 Unified Outbound & Template Reports (सर्व टेम्पलेट्स व नंबरनिहाय संपूर्ण रिपोर्ट)
      </h2>
      <div style="font-size:13px;color:var(--muted);margin-top:4px;">
        सगळे Meta Templates, कुणाला किती मेसेज पाठवले, डिलिव्हरी आणि Double Blue Tick रिपोर्ट — सर्व एकाच ठिकाणी.
      </div>
    </div>
    
    <div style="display:flex;gap:10px;align-items:center;">
      <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'csv', 'type'=>$tab])) ?>" class="btn btn-secondary btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
        📥 <span>Export Current View CSV</span>
      </a>
      <a href="/user/templates.php" class="btn btn-secondary btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
        🔄 <span>Meta Templates (<?= $totalTemplatesCount ?>)</span>
      </a>
      <a href="/user/campaigns.php" class="btn btn-primary btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
        📢 <span>New Campaign</span>
      </a>
    </div>
  </div>

  <!-- Meta Official Cloud API Live Volume Banner -->
  <div class="card" style="background:linear-gradient(135deg, rgba(37,211,102,0.07), rgba(6,182,212,0.07));border:1px solid rgba(37,211,102,0.3);padding:16px 20px;">
    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:14px;">
      <div style="display:flex;align-items:center;gap:12px;">
        <span style="font-size:26px;">🌐</span>
        <div>
          <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
            <h3 style="margin:0;font-size:15px;font-weight:700;color:var(--text);">
              Meta Official Cloud API — Live Outbound Volume
            </h3>
            <span style="font-size:11px;padding:2px 8px;border-radius:10px;background:rgba(34,197,94,0.15);color:#22c55e;font-weight:700;">
              🟢 Quality: <?= e($metaRating) ?>
            </span>
          </div>
          <div style="font-size:12px;color:var(--muted);margin-top:3px;">
            WhatsApp Business Account: <strong style="color:var(--text);"><?= e($metaPhoneName ?: 'Bluee Ocean Rooftop Restaurant (+91 83909 09037)') ?></strong>
          </div>
        </div>
      </div>

      <div style="display:flex;align-items:center;gap:20px;flex-wrap:wrap;">
        <div style="text-align:right;">
          <div style="font-size:10.5px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;">Meta Live Sent (30d)</div>
          <div style="font-size:20px;font-weight:800;color:var(--accent);"><?= formatNumber($metaLiveSent) ?></div>
        </div>
        <div style="text-align:right;">
          <div style="font-size:10.5px;color:var(--muted);text-transform:uppercase;letter-spacing:0.5px;">Meta Delivered</div>
          <div style="font-size:20px;font-weight:800;color:#22c55e;"><?= formatNumber($metaLiveDelivered) ?></div>
        </div>
        <a href="?refresh_meta=1&tab=<?= urlencode($tab) ?>" class="btn btn-secondary btn-sm" style="display:inline-flex;align-items:center;gap:5px;font-size:11.5px;padding:5px 12px;">
          🔄 <span>Refresh Meta Sync</span>
        </a>
      </div>
    </div>
  </div>

  <!-- Overall Stat Counters -->
  <div class="stats-grid" style="grid-template-columns:repeat(auto-fit, minmax(170px, 1fr));gap:12px;">
    <div class="stat-card" style="--c:var(--purple);--c-dim:var(--purple-dim)">
      <div class="stat-icon">📋</div>
      <div class="stat-value" style="color:var(--purple)"><?= $totalTemplatesCount ?></div>
      <div class="stat-label">Total Templates</div>
      <div style="font-size:11px;color:var(--muted);margin-top:2px;">Meta Synced & Approved</div>
    </div>
    <div class="stat-card" style="--c:var(--accent);--c-dim:var(--accent-dim)">
      <div class="stat-icon">📤</div>
      <div class="stat-value" style="color:var(--accent)"><?= formatNumber($totalMessagesSent) ?></div>
      <div class="stat-label">Total Messages Sent</div>
      <div style="font-size:11px;color:var(--muted);margin-top:2px;">Across all campaigns</div>
    </div>
    <div class="stat-card" style="--c:#22c55e;--c-dim:rgba(34,197,94,0.1)">
      <div class="stat-icon">✓✓</div>
      <div class="stat-value" style="color:#22c55e"><?= formatNumber($totalDeliveredCount) ?></div>
      <div class="stat-label">Delivered Messages</div>
      <div style="font-size:11px;color:var(--muted);margin-top:2px;"><?= $totalMessagesSent > 0 ? round(($totalDeliveredCount/$totalMessagesSent)*100, 1) : 0 ?>% Delivery rate</div>
    </div>
    <div class="stat-card" style="--c:#06b6d4;--c-dim:rgba(6,182,212,0.1)">
      <div class="stat-icon">👁️</div>
      <div class="stat-value" style="color:#06b6d4"><?= formatNumber($totalReadCount) ?></div>
      <div class="stat-label">Read / Seen (Blue Tick)</div>
      <div style="font-size:11px;color:var(--muted);margin-top:2px;"><?= $totalMessagesSent > 0 ? round(($totalReadCount/$totalMessagesSent)*100, 1) : 0 ?>% Read rate</div>
    </div>
    <div class="stat-card" style="--c:var(--blue);--c-dim:var(--blue-dim)">
      <div class="stat-icon">👥</div>
      <div class="stat-value" style="color:var(--blue)"><?= formatNumber($uniqueContactsCount) ?></div>
      <div class="stat-label">Contacts Reached</div>
      <div style="font-size:11px;color:var(--muted);margin-top:2px;">Unique phone numbers</div>
    </div>
  </div>

  <!-- Unified Navigation Tabs (सगळे टेम्पलेट्स vs कुणाला किती पाठवले vs सर्व लॉग्स) -->
  <div style="display:flex;gap:8px;border-bottom:2px solid var(--border);padding-bottom:10px;margin-top:4px;">
    <a href="?<?= http_build_query(array_merge($_GET, ['tab'=>'templates'])) ?>" class="btn <?= $tab === 'templates' ? 'btn-primary' : 'btn-secondary' ?>" style="display:inline-flex;align-items:center;gap:8px;font-size:13px;padding:8px 18px;border-radius:8px;">
      <span>📋</span> <strong>1. सर्व टेम्पलेट्स रिपोर्ट (All <?= $totalTemplatesCount ?> Templates)</strong>
    </a>
    <a href="?<?= http_build_query(array_merge($_GET, ['tab'=>'recipients'])) ?>" class="btn <?= $tab === 'recipients' ? 'btn-primary' : 'btn-secondary' ?>" style="display:inline-flex;align-items:center;gap:8px;font-size:13px;padding:8px 18px;border-radius:8px;">
      <span>👥</span> <strong>2. कुणाला किती पाठवले (Recipient-Wise Breakdown)</strong>
    </a>
    <a href="?<?= http_build_query(array_merge($_GET, ['tab'=>'logs'])) ?>" class="btn <?= $tab === 'logs' ? 'btn-primary' : 'btn-secondary' ?>" style="display:inline-flex;align-items:center;gap:8px;font-size:13px;padding:8px 18px;border-radius:8px;">
      <span>📱</span> <strong>3. तपशीलवार मेसेज लॉग्स (Detailed Message Stream)</strong>
    </a>
  </div>

  <!-- Filter & Search Controls (Applies to current tab) -->
  <div class="card" style="padding:14px 18px;background:var(--surface);">
    <form method="GET" action="" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin:0;">
      <input type="hidden" name="tab" value="<?= e($tab) ?>">
      
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;flex:1;">
        <!-- Search Phone or Name -->
        <div style="position:relative;width:230px;">
          <input type="text" name="q" value="<?= e($search) ?>" placeholder="🔍 Search phone or name..." class="form-control" style="width:100%;padding:7px 10px;font-size:13px;border-radius:6px;border:1px solid var(--border);background:var(--surface2);color:var(--text);">
        </div>

        <!-- Template Filter -->
        <select name="template" class="form-control" style="width:200px;padding:7px 10px;font-size:13px;border-radius:6px;border:1px solid var(--border);background:var(--surface2);color:var(--text);">
          <option value="">All Templates (सर्व टेम्पलेट्स)</option>
          <?php foreach ($dropdownTemplates as $t): ?>
            <option value="<?= e($t['template_name']) ?>" <?= $tplFilter === $t['template_name'] ? 'selected' : '' ?>>
              <?= e($t['template_name']) ?>
            </option>
          <?php endforeach; ?>
        </select>

        <!-- Status Filter -->
        <select name="status" class="form-control" style="width:140px;padding:7px 10px;font-size:13px;border-radius:6px;border:1px solid var(--border);background:var(--surface2);color:var(--text);">
          <option value="">All Statuses</option>
          <option value="read" <?= $statusFilter === 'read' ? 'selected' : '' ?>>Read (Seen ✓✓)</option>
          <option value="delivered" <?= $statusFilter === 'delivered' ? 'selected' : '' ?>>Delivered (✓✓)</option>
          <option value="sent" <?= $statusFilter === 'sent' ? 'selected' : '' ?>>Sent (✓)</option>
          <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>Failed (✗)</option>
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
          <a href="?tab=<?= urlencode($tab) ?>" class="btn btn-secondary btn-sm" style="padding:7px 12px;">Reset</a>
        <?php endif; ?>
      </div>

      <div>
        <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'csv', 'type'=>$tab])) ?>" class="btn btn-secondary btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
          📥 <span>Export CSV</span>
        </a>
      </div>
    </form>
  </div>

  <!-- ======================================================== -->
  <!-- TAB 1: ALL TEMPLATES REPORT (सर्व टेम्पलेट्स किती पाठवले) -->
  <!-- ======================================================== -->
  <?php if ($tab === 'templates'): ?>
  <div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
      <div class="card-title" style="display:flex;align-items:center;gap:8px;">
        <span>📋 सर्व Meta Templates व वापर रिपोर्ट (All <?= count($allTemplateStats) ?> Templates Performance)</span>
      </div>
      <div style="font-size:12px;color:var(--muted);">
        Every Meta-approved template with its total sends, delivery, and read counts
      </div>
    </div>
    
    <div style="overflow-x:auto;">
      <table class="table" style="width:100%;border-collapse:collapse;font-size:13px;text-align:left;">
        <thead>
          <tr style="border-bottom:1px solid var(--border);background:var(--surface2);">
            <th style="padding:11px 14px;color:var(--text2);font-weight:600;">Template Name</th>
            <th style="padding:11px 14px;color:var(--text2);font-weight:600;">Category</th>
            <th style="padding:11px 14px;color:var(--text2);font-weight:600;text-align:center;">Meta Status</th>
            <th style="padding:11px 14px;color:var(--text2);font-weight:600;text-align:right;">Total Sent (एकूण)</th>
            <th style="padding:11px 14px;color:var(--text2);font-weight:600;text-align:right;">Delivered (डिलिव्हर)</th>
            <th style="padding:11px 14px;color:var(--text2);font-weight:600;text-align:right;">Read (वाचलेले 👁️)</th>
            <th style="padding:11px 14px;color:var(--text2);font-weight:600;text-align:right;">Failed (अयशस्वी)</th>
            <th style="padding:11px 14px;color:var(--text2);font-weight:600;text-align:right;">Read Rate %</th>
            <th style="padding:11px 14px;color:var(--text2);font-weight:600;text-align:center;">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($allTemplateStats as $ts): 
            $hasSent = ($ts['total_sent'] > 0);
            $rRate = $hasSent ? round(($ts['read_count'] / $ts['total_sent']) * 100, 1) : 0;
            $dRate = $hasSent ? round(($ts['delivered'] / $ts['total_sent']) * 100, 1) : 0;
          ?>
          <tr style="border-bottom:1px solid var(--border);<?= $hasSent ? 'background:rgba(37,211,102,0.03);' : 'opacity:0.75;' ?>">
            <td style="padding:11px 14px;font-weight:600;">
              <div style="display:flex;align-items:center;gap:6px;">
                <span style="font-size:16px;">💬</span>
                <code style="background:var(--surface2);padding:3px 7px;border-radius:4px;color:var(--text);font-size:12.5px;font-weight:600;">
                  <?= e($ts['template_name']) ?>
                </code>
              </div>
            </td>
            
            <td style="padding:11px 14px;">
              <span class="badge" style="font-size:11px;padding:2px 7px;border-radius:4px;background:<?= $ts['category']==='UTILITY'?'rgba(6,182,212,0.15)':($ts['category']==='AUTHENTICATION'?'rgba(245,158,11,0.15)':'rgba(168,85,247,0.15)') ?>;color:<?= $ts['category']==='UTILITY'?'#06b6d4':($ts['category']==='AUTHENTICATION'?'#f59e0b':'#c084fc') ?>;">
                <?= e($ts['category']) ?>
              </span>
            </td>

            <td style="padding:11px 14px;text-align:center;">
              <span style="font-size:10.5px;padding:2px 7px;border-radius:10px;background:rgba(34,197,94,0.15);color:#22c55e;font-weight:700;">
                ✓ <?= strtoupper($ts['meta_status'] ?? 'APPROVED') ?>
              </span>
            </td>

            <td style="padding:11px 14px;text-align:right;font-weight:700;color:<?= $hasSent ? 'var(--accent)' : 'var(--muted)' ?>;font-size:14px;">
              <?= formatNumber($ts['total_sent']) ?>
            </td>

            <td style="padding:11px 14px;text-align:right;color:<?= $ts['delivered'] > 0 ? '#22c55e' : 'var(--muted)' ?>;font-weight:600;">
              <?= formatNumber($ts['delivered']) ?>
              <?php if ($hasSent): ?>
                <div style="font-size:10.5px;color:var(--muted);"><?= $dRate ?>%</div>
              <?php endif; ?>
            </td>

            <td style="padding:11px 14px;text-align:right;color:<?= $ts['read_count'] > 0 ? '#06b6d4' : 'var(--muted)' ?>;font-weight:600;">
              <?= formatNumber($ts['read_count']) ?>
              <?php if ($hasSent): ?>
                <div style="font-size:10.5px;color:#06b6d4;"><?= $rRate ?>%</div>
              <?php endif; ?>
            </td>

            <td style="padding:11px 14px;text-align:right;color:<?= $ts['failed_count'] > 0 ? 'var(--danger)' : 'var(--muted)' ?>;font-weight:600;">
              <?= formatNumber($ts['failed_count']) ?>
            </td>

            <td style="padding:11px 14px;text-align:right;font-weight:700;color:#06b6d4;">
              <?= $rRate ?>%
            </td>

            <td style="padding:11px 14px;text-align:center;">
              <?php if ($hasSent): ?>
                <a href="?tab=recipients&template=<?= urlencode($ts['template_name']) ?>" class="btn btn-secondary btn-sm" style="font-size:11px;padding:3px 9px;">
                  👥 कुणाला गेले (View Numbers)
                </a>
              <?php else: ?>
                <a href="/user/campaigns.php?template_id=<?= (int)$ts['tpl_id'] ?>" class="btn btn-primary btn-sm" style="font-size:11px;padding:3px 9px;">
                  📢 Send Campaign
                </a>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- ======================================================== -->
  <!-- TAB 2: RECIPIENT-WISE BREAKDOWN ("कुणाला किती पाठवले") -->
  <!-- ======================================================== -->
  <?php if ($tab === 'recipients'): ?>
  <div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
      <div>
        <div class="card-title" style="display:flex;align-items:center;gap:8px;">
          <span>👥 कुणाला किती मेसेज पाठवले (Recipient-Wise Aggregated Summary)</span>
          <span style="font-size:12px;padding:2px 8px;border-radius:12px;background:var(--surface3);color:var(--muted);font-weight:normal;">
            <?= count($recipientStats) ?> unique recipients
          </span>
        </div>
        <div style="font-size:12px;color:var(--muted);margin-top:2px;">
          प्रत्येक ग्राहकाला आजवर किती मेसेज गेले, कोणते टेम्पलेट्स पाठवले, आणि त्यांनी वाचले का (Blue Tick).
        </div>
      </div>
    </div>

    <div style="overflow-x:auto;">
      <table class="table" style="width:100%;border-collapse:collapse;font-size:13px;text-align:left;">
        <thead>
          <tr style="border-bottom:1px solid var(--border);background:var(--surface2);">
            <th style="padding:11px 14px;color:var(--text2);font-weight:600;">Customer / Phone Number</th>
            <th style="padding:11px 14px;color:var(--text2);font-weight:600;text-align:center;">Total Sent (एकूण)</th>
            <th style="padding:11px 14px;color:var(--text2);font-weight:600;">Templates Sent (पाठवलेले टेम्पलेट्स)</th>
            <th style="padding:11px 14px;color:var(--text2);font-weight:600;text-align:center;">Delivered (✓✓)</th>
            <th style="padding:11px 14px;color:var(--text2);font-weight:600;text-align:center;">Read / Seen (👁️ Blue)</th>
            <th style="padding:11px 14px;color:var(--text2);font-weight:600;text-align:center;">Failed (✗)</th>
            <th style="padding:11px 14px;color:var(--text2);font-weight:600;">Last Sent At</th>
            <th style="padding:11px 14px;color:var(--text2);font-weight:600;text-align:center;">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($recipientStats)): ?>
            <tr><td colspan="8" style="padding:35px;text-align:center;color:var(--muted);">No recipient outbound records found for current filters.</td></tr>
          <?php else: ?>
            <?php foreach ($recipientStats as $rs): 
              $tplList = array_filter(array_map('trim', explode(',', (string)($rs['templates_used'] ?? ''))));
            ?>
            <tr style="border-bottom:1px solid var(--border);">
              <!-- Phone & Name -->
              <td style="padding:11px 14px;">
                <div style="display:flex;align-items:center;gap:8px;">
                  <span style="font-size:18px;">📱</span>
                  <div>
                    <div style="font-weight:700;color:var(--text);font-size:14px;letter-spacing:0.3px;">
                      +<?= e($rs['phone']) ?>
                    </div>
                    <div style="font-size:11.5px;color:var(--muted);"><?= e($rs['contact_name']) ?></div>
                  </div>
                </div>
              </td>

              <!-- Total Sent Count -->
              <td style="padding:11px 14px;text-align:center;">
                <span style="font-size:14px;font-weight:800;color:var(--accent);padding:2px 10px;border-radius:12px;background:rgba(37,211,102,0.12);">
                  <?= formatNumber($rs['total_sent']) ?>
                </span>
              </td>

              <!-- Templates Used -->
              <td style="padding:11px 14px;">
                <div style="display:flex;flex-wrap:wrap;gap:4px;max-width:320px;">
                  <?php foreach ($tplList as $tplName): ?>
                    <a href="?tab=logs&template=<?= urlencode($tplName) ?>&q=<?= urlencode($rs['phone']) ?>" style="text-decoration:none;">
                      <code style="background:var(--surface2);padding:2px 6px;border-radius:4px;font-size:11px;color:var(--text);border:1px solid var(--border);">
                        <?= e($tplName) ?>
                      </code>
                    </a>
                  <?php endforeach; ?>
                </div>
              </td>

              <!-- Delivered -->
              <td style="padding:11px 14px;text-align:center;font-weight:600;color:<?= $rs['delivered'] > 0 ? '#22c55e' : 'var(--muted)' ?>;">
                <?= formatNumber($rs['delivered']) ?>
              </td>

              <!-- Read -->
              <td style="padding:11px 14px;text-align:center;">
                <?php if ($rs['read_count'] > 0): ?>
                  <span style="display:inline-flex;align-items:center;gap:3px;font-weight:700;color:#06b6d4;font-size:13px;padding:2px 8px;border-radius:10px;background:rgba(6,182,212,0.12);">
                    ✓✓ <?= formatNumber($rs['read_count']) ?>
                  </span>
                <?php else: ?>
                  <span style="color:var(--muted);">-</span>
                <?php endif; ?>
              </td>

              <!-- Failed -->
              <td style="padding:11px 14px;text-align:center;color:<?= $rs['failed_count'] > 0 ? 'var(--danger)' : 'var(--muted)' ?>;font-weight:600;">
                <?= formatNumber($rs['failed_count']) ?>
              </td>

              <!-- Last Sent -->
              <td style="padding:11px 14px;font-size:12px;color:var(--text2);">
                <div><?= $rs['last_sent_at'] ? fmtIst($rs['last_sent_at'], 'd M Y') : '—' ?></div>
                <div style="font-size:11px;color:var(--muted);"><?= $rs['last_sent_at'] ? fmtIst($rs['last_sent_at'], 'h:i A') : '' ?></div>
              </td>

              <!-- Actions -->
              <td style="padding:11px 14px;text-align:center;">
                <div style="display:inline-flex;gap:6px;">
                  <a href="/user/inbox.php?q=<?= urlencode($rs['phone']) ?>" class="btn btn-secondary btn-sm" style="font-size:11.5px;padding:3px 9px;" title="Open in Team Inbox">
                    💬 Chat
                  </a>
                  <a href="?tab=logs&q=<?= urlencode($rs['phone']) ?>" class="btn btn-secondary btn-sm" style="font-size:11.5px;padding:3px 9px;" title="View all messages for this number">
                    📜 Logs
                  </a>
                </div>
              </td>
            </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endif; ?>

  <!-- ======================================================== -->
  <!-- TAB 3: DETAILED MESSAGE LOGS (तपशीलवार मेसेज स्ट्रीम) -->
  <!-- ======================================================== -->
  <?php if ($tab === 'logs'): ?>
  <div class="card">
    <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
      <div class="card-title" style="display:flex;align-items:center;gap:8px;">
        <span>📱 तपशीलवार मेसेज लॉग्स (Detailed Recipient Message Stream)</span>
        <span style="font-size:12px;padding:2px 8px;border-radius:12px;background:var(--surface3);color:var(--muted);font-weight:normal;">
          Page <?= $page ?> of <?= $totalPages ?> (Total <?= formatNumber($totalLogsCount) ?>)
        </span>
      </div>
      <div>
        <a href="?<?= http_build_query(array_merge($_GET, ['export'=>'csv', 'type'=>'logs'])) ?>" class="btn btn-secondary btn-sm" style="display:inline-flex;align-items:center;gap:6px;">
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
          <?php if (empty($detailedLogs)): ?>
            <tr>
              <td colspan="6" style="padding:35px;text-align:center;color:var(--muted);">
                <div style="font-size:24px;margin-bottom:8px;">📭</div>
                <div>No outbound messages match your filter criteria.</div>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($detailedLogs as $row): 
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

              <!-- Status with Visual Ticks -->
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

              <!-- Action -->
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
  <?php endif; ?>

</div>

<?php include __DIR__ . '/../includes/user_footer.php'; ?>
