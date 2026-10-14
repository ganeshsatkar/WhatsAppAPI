<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/WhatsAppService.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/campaign_core.php';

requireLogin();
$pageTitle = 'Campaigns';
$uid = currentUser()['id'];
$msg = $err = '';

// ── Format phone ──────────────────────────────────────────
function fmtPhone(string $p): string {
    $p = preg_replace('/[\s\-\(\)\+]/', '', $p);
    $p = ltrim($p, '0');
    if (strlen($p) === 10) return '91' . $p;
    if (strlen($p) === 12 && str_starts_with($p, '91')) return $p;
    return '91' . $p;
}

// ── Build components ─────────────────────────────────────
function buildComponents(array $tpl, string $name, string $phone, array $custom = []): array {
    $body  = $tpl['body'] ?? '';
    $htype = $tpl['header_type'] ?? 'none';
    $hcont = $tpl['header_content'] ?? '';
    $out   = [];

    preg_match_all('/\{\{(\d+)\}\}/', $body, $nm);
    $numVars = array_values(array_unique($nm[1] ?? []));
    sort($numVars, SORT_NUMERIC);
    preg_match_all('/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/', $body, $namedM);
    $namedVars = array_values(array_unique($namedM[1] ?? []));

    $bParams = [];
    if (!empty($numVars)) {
        foreach ($numVars as $vn) {
            $i = (int)$vn;
            $val = $custom[$i] ?? match($i) { 1 => $name ?: 'Customer', 2 => $phone, default => $name ?: 'Customer' };
            $bParams[] = ['type' => 'text', 'text' => (string)$val];
        }
    } elseif (!empty($namedVars)) {
        foreach ($namedVars as $idx => $vname) {
            $i = $idx + 1;
            $low = strtolower($vname);
            $val = $custom[$i] ?? match(true) {
                str_contains($low,'name')||str_contains($low,'customer')||str_contains($low,'user') => $name ?: 'Customer',
                str_contains($low,'phone')||str_contains($low,'mobile') => $phone,
                default => $name ?: 'Customer'
            };
            $bParams[] = ['type' => 'text', 'text' => (string)$val];
        }
    }
    if (!empty($bParams)) $out[] = ['type' => 'body', 'parameters' => $bParams];

    if ($htype === 'text' && !empty($hcont)) {
        preg_match_all('/\{\{(\d+)\}\}/', $hcont, $hm);
        if (!empty($hm[1])) $out[] = ['type' => 'header', 'parameters' => [['type' => 'text', 'text' => $name ?: 'Customer']]];
    } elseif (in_array($htype, ['image','video','document']) && !empty($hcont)) {
        // Check if it's a media_id (numeric) or URL
        if (is_numeric($hcont) || strlen($hcont) > 30) {
            $key = is_numeric($hcont) ? 'id' : 'link';
            $out[] = ['type' => 'header', 'parameters' => [['type' => $htype, $htype => [$key => $hcont]]]];
        }
    }
    return $out;
}

// ── Send batch ────────────────────────────────────────────
function sendBatch(int $campId, int $userId, int $batchSize, array $custom = []): int {
    $wa   = WhatsAppService::forUser($userId);
    if (!$wa) return 0;
    $camp = db()->row('SELECT * FROM campaigns WHERE id=? AND user_id=?', [$campId, $userId]);
    $tpl  = $camp ? db()->row('SELECT * FROM message_templates WHERE id=?', [$camp['template_id']]) : null;
    if (!$camp || !$tpl) return 0;

    $category = strtoupper($tpl['category'] ?? 'MARKETING');

    $logs = db()->all(
        'SELECT cl.*, c.name as cname, c.opt_in as c_opt_in, c.is_blocked as c_blocked FROM campaign_logs cl LEFT JOIN contacts c ON c.id=cl.contact_id WHERE cl.campaign_id=? AND cl.status=? LIMIT ?',
        [$campId, 'pending', $batchSize]
    );

    // Stop early if the wallet can't cover this batch at all
    if (!canAffordMessages($userId, count($logs), $category)) {
        $affordable = affordableMessages($userId, $category);
        if ($affordable <= 0) {
            db()->run("UPDATE campaigns SET status='failed' WHERE id=?", [$campId]);
            return 0;
        }
        $logs = array_slice($logs, 0, $affordable);
    }

    $sent = 0;
    foreach ($logs as $log) {
        if ($skip = campaignSkipReason($log, $category)) { campaignLogFailed((int)$log['id'], $skip, -1); continue; }
        try {
            $phone = fmtPhone($log['phone']);
            $comps = buildComponents($tpl, $log['cname'] ?? 'Customer', $phone, $custom);
            $res   = $wa->sendTemplate($phone, $tpl['template_name'], $tpl['language'] ?? 'en', $comps);
            if ($res['success']) {
                campaignLogSent($log, $campId, $userId, $res['message_id'] ?? null, $category);
                $sent++;
            } else {
                campaignLogFailed((int)$log['id'], (string)($res['error'] ?? 'Unknown'), isset($res['error_code']) ? (int)$res['error_code'] : null);
            }
        } catch (\Throwable $e) {
            campaignLogFailed((int)$log['id'], $e->getMessage(), null);
        }
        usleep(200000);
    }

    $left = db()->count('SELECT COUNT(*) FROM campaign_logs WHERE campaign_id=? AND status=?', [$campId, 'pending']);
    if ($left == 0) db()->run("UPDATE campaigns SET status='completed',completed_at=NOW() WHERE id=?", [$campId]);
    return $sent;
}

// ── Actions ───────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $act = $_POST['action'] ?? '';

    if ($act === 'create_campaign') {
        $name   = sanitize($_POST['name'] ?? '');
        $tplId  = (int)($_POST['template_id'] ?? 0);
        $listId = (int)($_POST['contact_list_id'] ?? 0);
        $sched  = $_POST['schedule_type'] ?? 'now';
        $sat    = sanitize($_POST['scheduled_at'] ?? '');
        $custom = [];
        foreach ($_POST as $k => $v) {
            if (preg_match('/^var_(\d+)$/', $k, $vm)) $custom[(int)$vm[1]] = sanitize($v);
        }

        // Template and list must belong to this account; schedule is entered in the user's time zone, stored as UTC
        $ownTpl  = $tplId  ? db()->row('SELECT id FROM message_templates WHERE id=? AND user_id=?', [$tplId, $uid]) : null;
        $ownList = $listId ? db()->row('SELECT id FROM contact_lists WHERE id=? AND user_id=?', [$listId, $uid]) : null;
        $schedUtc = $sched === 'schedule' ? localToUtc($sat) : null;

        if (!$name)   { $err = 'Campaign name required.'; }
        elseif (!$tplId || !$ownTpl) { $err = 'Select a template.'; }
        elseif ($listId && !$ownList) { $err = 'Contact list not found.'; }
        elseif ($sched === 'schedule' && (!$sat || !$schedUtc)) { $err = 'Choose a valid schedule date & time.'; }
        else {
            $total = $listId
                ? db()->count('SELECT COUNT(*) FROM contact_list_members clm JOIN contacts c ON c.id=clm.contact_id WHERE clm.list_id=? AND c.user_id=? AND c.opt_in=1 AND c.is_blocked=0', [$listId, $uid])
                : db()->count('SELECT COUNT(*) FROM contacts WHERE user_id=? AND opt_in=1 AND is_blocked=0', [$uid]);

            // Recalculate total with tag filter
            $tagFilter = sanitize($_POST['tag_filter'] ?? '');
            if ($tagFilter && !$listId) {
                $total = db()->count('SELECT COUNT(DISTINCT c.id) FROM contacts c JOIN contact_tags ct ON ct.contact_id=c.id WHERE c.user_id=? AND c.opt_in=1 AND c.is_blocked=0 AND ct.tag=?', [$uid,$tagFilter]);
            }
            if ($total == 0) { $err = 'No contacts found. Add contacts first.'; }
            else {
                $status = $sched === 'schedule' ? 'scheduled' : 'running';
                $st     = $sched === 'schedule' ? $schedUtc : date('Y-m-d H:i:s');
                $campId = db()->insert(
                    'INSERT INTO campaigns (user_id,name,template_id,contact_list_id,status,scheduled_at,total_contacts) VALUES (?,?,?,?,?,?,?)',
                    [$uid, $name, $tplId, $listId ?: null, $status, $st, $total]
                );
                $tagFilter = sanitize($_POST['tag_filter'] ?? '');
                $contacts = $listId
                    ? db()->all('SELECT c.* FROM contacts c JOIN contact_list_members m ON m.contact_id=c.id WHERE m.list_id=? AND c.user_id=? AND c.opt_in=1 AND c.is_blocked=0', [$listId, $uid])
                    : ($tagFilter
                        ? db()->all('SELECT c.* FROM contacts c JOIN contact_tags ct ON ct.contact_id=c.id WHERE c.user_id=? AND c.opt_in=1 AND c.is_blocked=0 AND ct.tag=?', [$uid,$tagFilter])
                        : db()->all('SELECT * FROM contacts WHERE user_id=? AND opt_in=1 AND is_blocked=0', [$uid]));
                foreach ($contacts as $ct) {
                    db()->run('INSERT IGNORE INTO campaign_logs (campaign_id,user_id,contact_id,phone,status) VALUES (?,?,?,?,?)',
                        [$campId, $uid, $ct['id'], fmtPhone($ct['phone']), 'pending']);
                }
                if ($sched === 'now') {
                    $sent = sendBatch($campId, $uid, 20, $custom);
                    $msg  = "🚀 Campaign launched! Sent to $sent / $total contacts.";
                } else {
                    $msg = "📅 Scheduled for " . toLocalTime($schedUtc) . " · $total contacts";
                }
            }
        }
    }

    if ($act === 'delete_campaign') { db()->run('DELETE FROM campaigns WHERE id=? AND user_id=?', [(int)$_POST['campaign_id'], $uid]); $msg = 'Deleted.'; }
    if ($act === 'pause_campaign')  { db()->run("UPDATE campaigns SET status='paused' WHERE id=? AND user_id=?", [(int)$_POST['campaign_id'], $uid]); $msg = 'Paused.'; }
    if ($act === 'resume_campaign') { $id=(int)$_POST['campaign_id']; db()->run("UPDATE campaigns SET status='running' WHERE id=? AND user_id=?", [$id,$uid]); $sent=sendBatch($id,$uid,20); $msg="Resumed. Sent $sent messages."; }
    if ($act === 'send_more')       { $id=(int)$_POST['campaign_id']; $sent=sendBatch($id,$uid,50); $msg=$sent>0?"Sent $sent more messages.":"All sent."; }
    if ($act === 'retry_failed')    {
        $id=(int)$_POST['campaign_id'];
        if (!db()->row('SELECT id FROM campaigns WHERE id=? AND user_id=?', [$id,$uid])) { $err='Campaign not found.'; }
        else {
            [$re, $skipped] = campaignRetryFailed($id, $uid, !empty($_POST['all']));
            if (!$re) { $err = $skipped ? "Nothing to retry: all $skipped failed number(s) have permanent problems (e.g. not on WhatsApp). Open the report for details." : 'No failed messages.'; }
            else {
                $sent = sendBatch($id, $uid, 20);
                $msg = "Retrying $re failed message(s) – sent $sent now, the rest continue automatically." . ($skipped ? " $skipped permanent failure(s) were skipped (not on WhatsApp / template issue)." : '');
            }
        }
    }
}

// ── Load data ─────────────────────────────────────────────
$campaigns    = db()->all('SELECT c.*,mt.template_name,mt.body,mt.category FROM campaigns c LEFT JOIN message_templates mt ON mt.id=c.template_id WHERE c.user_id=? ORDER BY c.created_at DESC', [$uid]);
$allTemplates = db()->all('SELECT id,template_name,category,language,body,header_type,header_content FROM message_templates WHERE user_id=? ORDER BY template_name ASC', [$uid]);
$lists        = db()->all('SELECT cl.*,(SELECT COUNT(*) FROM contact_list_members WHERE list_id=cl.id) as cnt FROM contact_lists cl WHERE cl.user_id=? ORDER BY cl.name ASC', [$uid]);
$tags         = db()->all('SELECT ct.tag, COUNT(DISTINCT ct.contact_id) as cnt FROM contact_tags ct JOIN contacts c ON c.id=ct.contact_id WHERE c.user_id=? AND c.opt_in=1 GROUP BY ct.tag ORDER BY cnt DESC', [$uid]);
$totalCtc     = db()->count('SELECT COUNT(*) FROM contacts WHERE user_id=? AND opt_in=1', [$uid]);
$waOk         = db()->row('SELECT id FROM user_whatsapp_settings WHERE user_id=? AND is_connected=1', [$uid]);

// Stats
$statTotal    = count($campaigns);
$statSent     = array_sum(array_column($campaigns, 'sent_count'));
$statDelivered= array_sum(array_column($campaigns, 'delivered_count'));
$statRead     = array_sum(array_column($campaigns, 'read_count'));

include __DIR__ . '/../includes/user_header.php';
?>

<style>
/* ── Wizard ── */
.wiz-steps{display:flex;align-items:center;margin-bottom:28px;background:var(--surface);border:1px solid var(--border);border-radius:12px;padding:16px 24px;}
.wiz-step{display:flex;align-items:center;gap:10px;flex:1;cursor:pointer;}
.wiz-step:not(:last-child)::after{content:'';flex:1;height:2px;background:var(--border);margin:0 12px;}
.wiz-num{width:32px;height:32px;border-radius:50%;background:var(--surface2);border:2px solid var(--border2);display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:800;color:var(--muted);flex-shrink:0;transition:all .3s;}
.wiz-label{font-size:13px;font-weight:700;color:var(--muted);transition:all .3s;white-space:nowrap;}
.wiz-step.done .wiz-num{background:var(--accent);border-color:var(--accent);color:#000;}
.wiz-step.done .wiz-label{color:var(--accent);}
.wiz-step.active .wiz-num{background:var(--accent-dim);border-color:var(--accent);color:var(--accent);}
.wiz-step.active .wiz-label{color:#fff;}
.wiz-step.done::after{background:var(--accent);}

.wiz-panel{display:none;}
.wiz-panel.active{display:block;}

/* ── Template card picker ── */
.tpl-grid-pick{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px;max-height:400px;overflow-y:auto;padding:2px;}
.tpl-card{background:var(--surface2);border:2px solid var(--border);border-radius:12px;padding:14px;cursor:pointer;transition:all .2s;position:relative;}
.tpl-card:hover{border-color:var(--accent);transform:translateY(-1px);}
.tpl-card.selected{border-color:var(--accent);background:var(--accent-dim);}
.tpl-card-check{position:absolute;top:10px;right:10px;width:22px;height:22px;border-radius:50%;background:var(--accent);color:#000;display:none;align-items:center;justify-content:center;font-size:12px;font-weight:800;}
.tpl-card.selected .tpl-card-check{display:flex;}
.tpl-preview{background:#071510;border-radius:9px;padding:10px 12px;font-size:12px;color:#e2ffd9;line-height:1.55;margin-top:10px;max-height:80px;overflow:hidden;white-space:pre-wrap;word-break:break-word;}

/* ── Audience selector ── */
.aud-option{background:var(--surface2);border:2px solid var(--border);border-radius:12px;padding:14px 16px;cursor:pointer;transition:all .2s;display:flex;align-items:center;gap:12px;margin-bottom:8px;}
.aud-option:hover{border-color:var(--accent);}
.aud-option.selected{border-color:var(--accent);background:var(--accent-dim);}
.aud-icon{width:38px;height:38px;border-radius:9px;background:var(--surface3);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0;}
.aud-info{flex:1;}
.aud-name{font-weight:700;color:#fff;font-size:14px;}
.aud-count{font-size:12px;color:var(--muted);margin-top:2px;}

/* ── Summary card ── */
.sum-row{display:flex;align-items:center;justify-content:space-between;padding:12px 0;border-bottom:1px solid var(--border);font-size:13px;}
.sum-label{color:var(--text2);}
.sum-val{font-weight:700;color:#fff;}

/* ── Campaign list ── */
.camp-card{background:var(--surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;margin-bottom:12px;transition:border-color .2s;}
.camp-card:hover{border-color:var(--border2);}
.camp-header{display:flex;align-items:center;justify-content:space-between;padding:14px 18px;border-bottom:1px solid var(--border);flex-wrap:wrap;gap:8px;}
.camp-name{font-weight:800;font-size:15px;color:#fff;}
.camp-body{padding:16px 18px;}
.camp-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;}
.camp-stat{background:var(--surface2);border:1px solid var(--border);border-radius:9px;padding:11px;text-align:center;}
.camp-stat-val{font-size:20px;font-weight:900;line-height:1;}
.camp-stat-label{font-size:10px;color:var(--text2);margin-top:4px;}
.progress-bar{height:6px;background:var(--surface3);border-radius:10px;overflow:hidden;margin-bottom:8px;}
.progress-fill{height:100%;background:linear-gradient(90deg,var(--accent),#7fffd4);border-radius:10px;transition:width .5s;}
</style>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠ <?= e($err) ?></div><?php endif; ?>

<?php if (!$waOk): ?>
<div style="background:rgba(255,140,66,0.08);border:1px solid rgba(255,140,66,0.3);border-radius:10px;padding:12px 16px;margin-bottom:18px;font-size:13px;display:flex;align-items:center;gap:10px;">
  ⚠️ <span style="color:#ff8c42;font-weight:700;">WhatsApp API not connected</span> — campaigns will fail. <a href="/user/settings.php" style="color:var(--accent);">Connect in Settings →</a>
</div>
<?php endif; ?>

<!-- Stats row -->
<div class="stats-grid mb2" style="grid-template-columns:repeat(4,1fr);">
  <div class="stat-card" style="--c:var(--blue);--c-dim:var(--blue-dim)"><div class="stat-icon">📢</div><div class="stat-value"><?= $statTotal ?></div><div class="stat-label">Total Campaigns</div></div>
  <div class="stat-card" style="--c:var(--accent);--c-dim:var(--accent-dim)"><div class="stat-icon">✅</div><div class="stat-value"><?= number_format($statSent) ?></div><div class="stat-label">Messages Sent</div></div>
  <div class="stat-card" style="--c:var(--blue);--c-dim:var(--blue-dim)"><div class="stat-icon">📬</div><div class="stat-value"><?= number_format($statDelivered) ?></div><div class="stat-label">Delivered</div></div>
  <div class="stat-card" style="--c:var(--gold);--c-dim:var(--gold-dim)"><div class="stat-icon">👁</div><div class="stat-value"><?= number_format($statRead) ?></div><div class="stat-label">Read</div></div>
</div>

<!-- Top bar -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px;">
  <div style="font-size:13px;color:var(--text2);"><?= count($campaigns) ?> campaigns · <span style="color:var(--accent);"><?= $totalCtc ?> contacts</span> · 🇮🇳 Auto +91</div>
  <button class="btn btn-primary" style="padding:10px 22px;font-size:14px;" onclick="openWizard()">+ New Campaign</button>
</div>

<!-- Campaign List -->
<?php if (empty($campaigns)): ?>
<div class="card"><div class="empty-state"><div class="empty-icon">📢</div><div class="empty-title">No campaigns yet</div><div class="empty-sub">Create your first broadcast campaign in 3 easy steps.</div><button class="btn btn-primary" onclick="openWizard()">Create Campaign</button></div></div>
<?php else: ?>
<?php foreach ($campaigns as $c):
  $total   = max(1, $c['total_contacts']);
  $sentPct = round($c['sent_count'] / $total * 100);
  $delPct  = $c['sent_count'] > 0 ? round($c['delivered_count'] / $c['sent_count'] * 100) : 0;
  $readPct = $c['sent_count'] > 0 ? round($c['read_count'] / $c['sent_count'] * 100) : 0;
  $pending = db()->count('SELECT COUNT(*) FROM campaign_logs WHERE campaign_id=? AND status=?', [$c['id'], 'pending']);
  $failed  = db()->count('SELECT COUNT(*) FROM campaign_logs WHERE campaign_id=? AND status=?', [$c['id'], 'failed']);
  $sc = match($c['status']) {'completed'=>'badge-green','running'=>'badge-blue','scheduled'=>'badge-orange','paused'=>'badge-gray','failed'=>'badge-red',default=>'badge-gray'};
  $catColors=['MARKETING'=>'var(--orange)','UTILITY'=>'var(--blue)','AUTHENTICATION'=>'var(--purple)'];
  $catColor=$catColors[$c['category']??'MARKETING']??'var(--orange)';
?>
<div class="camp-card">
  <div class="camp-header">
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
      <div style="width:36px;height:36px;border-radius:9px;background:<?= $catColor ?>22;border:1px solid <?= $catColor ?>44;display:flex;align-items:center;justify-content:center;font-size:16px;">📢</div>
      <div>
        <div class="camp-name"><?= e($c['name']) ?></div>
        <div style="font-size:12px;color:var(--muted);margin-top:1px;">
          📋 <?= e($c['template_name'] ?? '—') ?>
          <?php if ($c['category']): ?> · <span style="color:<?= $catColor ?>;"><?= ucfirst(strtolower($c['category'])) ?></span><?php endif; ?>
        </div>
      </div>
      <span class="badge <?= $sc ?>"><?= ucfirst($c['status']) ?></span>
      <?php if ($pending > 0): ?><span class="badge badge-orange">⏳ <?= $pending ?> pending</span><?php endif; ?>
      <?php if ($failed > 0): ?><span class="badge badge-red">❌ <?= $failed ?> failed</span><?php endif; ?>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;">
      <?php if ($pending > 0): ?>
      <form method="POST" style="display:inline;"><?= csrfField() ?><input type="hidden" name="action" value="send_more"><input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
        <button type="submit" class="btn btn-primary btn-sm">▶ Send <?= min($pending,50) ?> More</button></form>
      <?php endif; ?>
      <?php if ($failed > 0): ?>
      <form method="POST" style="display:inline;"><?= csrfField() ?><input type="hidden" name="action" value="retry_failed"><input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
        <button type="submit" class="btn btn-secondary btn-sm">🔄 Retry</button></form>
      <?php endif; ?>
      <?php if ($c['status'] === 'running'): ?>
      <form method="POST" style="display:inline;"><?= csrfField() ?><input type="hidden" name="action" value="pause_campaign"><input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
        <button type="submit" class="btn btn-secondary btn-sm">⏸</button></form>
      <?php endif; ?>
      <?php if ($c['status'] === 'paused'): ?>
      <form method="POST" style="display:inline;"><?= csrfField() ?><input type="hidden" name="action" value="resume_campaign"><input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
        <button type="submit" class="btn btn-secondary btn-sm">▶</button></form>
      <?php endif; ?>
      <a href="campaign_report.php?id=<?= $c['id'] ?>" class="btn btn-secondary btn-sm" title="Delivery & failure report">📋 Report</a>
      <a href="campaign_report.php?id=<?= $c['id'] ?>" class="btn btn-secondary btn-sm" title="Delivery report">🔍</a>
      <form method="POST" style="display:inline;" onsubmit="return confirm('Delete?')"><?= csrfField() ?><input type="hidden" name="action" value="delete_campaign"><input type="hidden" name="campaign_id" value="<?= $c['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm">🗑</button></form>
    </div>
  </div>
  <div class="camp-body">
    <div style="margin-bottom:12px;">
      <div style="display:flex;justify-content:space-between;font-size:12px;margin-bottom:5px;">
        <span style="color:var(--text2);">Progress</span>
        <span style="font-weight:700;color:#fff;"><?= $c['sent_count'] ?> / <?= $c['total_contacts'] ?> sent (<?= $sentPct ?>%)</span>
      </div>
      <div class="progress-bar"><div class="progress-fill" style="width:<?= $sentPct ?>%;"></div></div>
    </div>
    <div class="camp-stats">
      <div class="camp-stat"><div class="camp-stat-val" style="color:var(--accent);"><?= number_format($c['total_contacts']) ?></div><div class="camp-stat-label">Total</div></div>
      <div class="camp-stat"><div class="camp-stat-val" style="color:var(--blue);"><?= number_format($c['sent_count']) ?></div><div class="camp-stat-label">Sent</div></div>
      <div class="camp-stat"><div class="camp-stat-val" style="color:#7fffd4;"><?= number_format($c['delivered_count']) ?><?= $delPct>0?" <small style='font-size:10px;'>($delPct%)</small>":'' ?></div><div class="camp-stat-label">Delivered</div></div>
      <div class="camp-stat"><div class="camp-stat-val" style="color:var(--gold);"><?= number_format($c['read_count']) ?><?= $readPct>0?" <small style='font-size:10px;'>($readPct%)</small>":'' ?></div><div class="camp-stat-label">Read</div></div>
      <div class="camp-stat"><div class="camp-stat-val" style="color:<?= $failed>0?'var(--danger)':'var(--muted)' ?>;"><?= number_format($failed) ?></div><div class="camp-stat-label">Failed</div></div>
    </div>
    <div style="margin-top:10px;font-size:11px;color:var(--muted);">
      Created <?= date('M j, Y H:i', strtotime($c['created_at'])) ?>
      <?php if ($c['completed_at']): ?> · Completed <?= date('M j, Y H:i', strtotime($c['completed_at'])) ?><?php endif; ?>
    </div>
  </div>
</div>
<?php endforeach; ?>
<?php endif; ?>

<!-- ═══════════════════════════════════════════════════════
     3-STEP CAMPAIGN WIZARD MODAL
     ══════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="wizModal" style="align-items:flex-start;padding:20px;overflow-y:auto;">
  <div class="modal" style="max-width:720px;width:100%;margin:auto;margin-top:20px;max-height:none;">

    <!-- Wizard steps header -->
    <div class="wiz-steps" id="wizSteps">
      <div class="wiz-step active" id="ws1" onclick="goStep(1)">
        <div class="wiz-num">1</div>
        <div class="wiz-label">Choose Template</div>
      </div>
      <div class="wiz-step" id="ws2" onclick="goStep(2)">
        <div class="wiz-num">2</div>
        <div class="wiz-label">Select Audience</div>
      </div>
      <div class="wiz-step" id="ws3" onclick="goStep(3)">
        <div class="wiz-num">3</div>
        <div class="wiz-label">Review & Send</div>
      </div>
    </div>

    <form method="POST" id="wizForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="create_campaign">
      <input type="hidden" name="template_id" id="wTplId" value="">
      <input type="hidden" name="contact_list_id" id="wListId" value="">

      <!-- ── STEP 1: Template ── -->
      <div class="wiz-panel active" id="step1">
        <div style="margin-bottom:16px;">
          <div style="font-size:18px;font-weight:800;color:#fff;margin-bottom:4px;">Choose a Template</div>
          <div style="font-size:13px;color:var(--text2);">Select the WhatsApp message template to send</div>
        </div>

        <?php if (empty($allTemplates)): ?>
        <div style="text-align:center;padding:40px;color:var(--muted);">
          No templates yet. <a href="/user/templates.php" style="color:var(--accent);">Create templates first →</a>
        </div>
        <?php else: ?>
        <!-- Search -->
        <div class="il-search" style="margin-bottom:14px;max-width:320px;">
          <span>🔍</span>
          <input type="text" id="tplSearch" placeholder="Search templates…" oninput="filterTpls(this.value)" style="background:none;border:none;outline:none;color:var(--text);font-family:'Cabinet Grotesk',sans-serif;font-size:13px;flex:1;">
        </div>

        <div class="tpl-grid-pick" id="tplGridPick">
          <?php foreach ($allTemplates as $t):
            preg_match_all('/\{\{([^}]+)\}\}/', $t['body'] ?? '', $vm);
            $varCount = count(array_unique($vm[1] ?? []));
            $catColors = ['MARKETING'=>'var(--orange)','UTILITY'=>'var(--blue)','AUTHENTICATION'=>'var(--purple)'];
            $cc = $catColors[$t['category']] ?? 'var(--accent)';
          ?>
          <div class="tpl-card" id="tc-<?= $t['id'] ?>"
               data-id="<?= $t['id'] ?>"
               data-name="<?= e($t['template_name']) ?>"
               data-body="<?= e($t['body'] ?? '') ?>"
               data-vars="<?= $varCount ?>"
               data-cat="<?= e($t['category']) ?>"
               data-lang="<?= e($t['language']) ?>"
               onclick="selectTemplate(this)">
            <div class="tpl-card-check">✓</div>
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
              <div style="font-weight:800;font-size:13px;color:#fff;"><?= e($t['template_name']) ?></div>
              <span style="font-size:10px;font-weight:700;padding:2px 8px;border-radius:10px;background:<?= $cc ?>22;color:<?= $cc ?>;"><?= e($t['category']) ?></span>
            </div>
            <div style="font-size:11px;color:var(--muted);margin-bottom:6px;"><?= strtoupper($t['language']) ?> · <?= $varCount ?> variable<?= $varCount!=1?'s':'' ?></div>
            <div class="tpl-preview"><?= e(substr($t['body'] ?? '', 0, 120)) ?><?= strlen($t['body'] ?? '')>120?'…':'' ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Variable inputs (shown when template selected) -->
        <div id="varSection" style="display:none;margin-top:16px;background:rgba(37,211,102,0.04);border:1px solid rgba(37,211,102,0.2);border-radius:10px;padding:14px;">
          <div style="font-size:12px;font-weight:700;color:var(--accent);margin-bottom:10px;">⚡ Template Variables — auto-filled from contact data</div>
          <div id="varInputs" style="display:flex;flex-direction:column;gap:8px;"></div>
          <div style="font-size:11px;color:var(--muted);margin-top:8px;">Leave blank: {{1}}=Contact Name · {{2}}=Phone Number</div>
        </div>

        <div style="display:flex;justify-content:flex-end;margin-top:20px;">
          <button type="button" class="btn btn-primary" onclick="nextStep(1)" id="step1Next" disabled style="padding:10px 28px;">Next: Select Audience →</button>
        </div>
      </div>

      <!-- ── STEP 2: Audience ── -->
      <div class="wiz-panel" id="step2">
        <div style="margin-bottom:16px;">
          <div style="font-size:18px;font-weight:800;color:#fff;margin-bottom:4px;">Select Audience</div>
          <div style="font-size:13px;color:var(--text2);">Choose who will receive this campaign</div>
        </div>
        <input type="hidden" name="tag_filter" id="wTagFilter" value="">

        <!-- Audience tabs -->
        <div style="display:flex;gap:6px;margin-bottom:14px;border-bottom:1px solid var(--border);padding-bottom:10px;">
          <button type="button" class="aud-tab active" id="atab-all"   onclick="switchAudTab('all')"  style="padding:6px 14px;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;border:1px solid var(--accent);background:var(--accent-dim);color:var(--accent);font-family:'Cabinet Grotesk',sans-serif;">👥 All</button>
          <button type="button" class="aud-tab"        id="atab-lists" onclick="switchAudTab('lists')" style="padding:6px 14px;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;border:1px solid var(--border);background:none;color:var(--text2);font-family:'Cabinet Grotesk',sans-serif;">📋 Lists</button>
          <button type="button" class="aud-tab"        id="atab-tags"  onclick="switchAudTab('tags')"  style="padding:6px 14px;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;border:1px solid var(--border);background:none;color:var(--text2);font-family:'Cabinet Grotesk',sans-serif;">🏷 Tags</button>
        </div>

        <!-- ALL CONTACTS -->
        <div id="audPanel-all">
          <div class="aud-option selected" id="aud-all" onclick="selectAudience('all',0,'All Contacts',<?= $totalCtc ?>)">
            <div class="aud-icon">👥</div>
            <div class="aud-info">
              <div class="aud-name">All Contacts</div>
              <div class="aud-count"><?= $totalCtc ?> opted-in contacts</div>
            </div>
            <div style="font-family:'Instrument Serif',serif;font-size:1.8rem;font-style:italic;color:var(--accent);"><?= $totalCtc ?></div>
          </div>
        </div>

        <!-- LISTS -->
        <div id="audPanel-lists" style="display:none;">
          <?php if (empty($lists)): ?>
          <div style="text-align:center;padding:30px;font-size:13px;color:var(--muted);">
            No lists yet. <a href="/user/segments.php" style="color:var(--accent);">Create a contact list →</a>
          </div>
          <?php else: ?>
          <?php foreach ($lists as $l): ?>
          <div class="aud-option" id="aud-list-<?= $l['id'] ?>" onclick="selectAudience('list',<?= $l['id'] ?>,'<?= e(addslashes($l['name'])) ?>',<?= $l['cnt'] ?>)">
            <div class="aud-icon" style="background:<?= e($l['color']) ?>22;border:1px solid <?= e($l['color']) ?>44;">📋</div>
            <div class="aud-info">
              <div class="aud-name"><?= e($l['name']) ?></div>
              <div class="aud-count"><?= $l['cnt'] ?> contacts</div>
            </div>
            <div style="font-family:'Instrument Serif',serif;font-size:1.8rem;font-style:italic;color:<?= e($l['color']) ?>;"><?= $l['cnt'] ?></div>
          </div>
          <?php endforeach; ?>
          <?php endif; ?>
        </div>

        <!-- TAGS -->
        <div id="audPanel-tags" style="display:none;">
          <?php if (empty($tags)): ?>
          <div style="text-align:center;padding:30px;font-size:13px;color:var(--muted);">
            No tags yet. <a href="/user/segments.php" style="color:var(--accent);">Tag your contacts first →</a>
          </div>
          <?php else: ?>
          <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:10px;">
            <?php foreach ($tags as $t): ?>
            <div class="aud-option" id="aud-tag-<?= e(urlencode($t['tag'])) ?>"
                 onclick="selectAudience('tag',0,'Tag: <?= e(addslashes($t['tag'])) ?>',<?= $t['cnt'] ?>,'<?= e(addslashes($t['tag'])) ?>')"
                 style="flex-direction:column;align-items:flex-start;gap:6px;padding:12px 14px;">
              <div style="display:flex;align-items:center;gap:8px;width:100%;">
                <span style="font-size:16px;">🏷</span>
                <div style="flex:1;">
                  <div style="font-weight:700;color:#fff;font-size:13px;"><?= e($t['tag']) ?></div>
                  <div style="font-size:11px;color:var(--muted);"><?= $t['cnt'] ?> contacts</div>
                </div>
                <div style="font-family:'Instrument Serif',serif;font-size:1.4rem;font-style:italic;color:var(--purple);"><?= $t['cnt'] ?></div>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
        </div>

        <!-- Schedule -->
        <div style="margin-top:20px;background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:14px;">
          <div style="font-size:13px;font-weight:700;color:#fff;margin-bottom:10px;">📅 When to send?</div>
          <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--text2);">
              <input type="radio" name="schedule_type" value="now" checked onchange="toggleSched(this.value)" style="accent-color:var(--accent);"> 🚀 Send Now
            </label>
            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--text2);">
              <input type="radio" name="schedule_type" value="schedule" onchange="toggleSched(this.value)" style="accent-color:var(--accent);"> 📅 Schedule Later
            </label>
          </div>
          <div id="schedField" style="display:none;margin-top:10px;">
            <input type="datetime-local" name="scheduled_at" class="form-control">
          </div>
        </div>

        <div style="display:flex;justify-content:space-between;margin-top:20px;">
          <button type="button" class="btn btn-secondary" onclick="goStep(1)">← Back</button>
          <button type="button" class="btn btn-primary" onclick="nextStep(2)" style="padding:10px 28px;">Next: Review →</button>
        </div>
      </div>

      <!-- ── STEP 3: Review & Send ── -->
      <div class="wiz-panel" id="step3">
        <div style="margin-bottom:16px;">
          <div style="font-size:18px;font-weight:800;color:#fff;margin-bottom:4px;">Review & Launch</div>
          <div style="font-size:13px;color:var(--text2);">Confirm your campaign before sending</div>
        </div>

        <!-- Campaign name -->
        <div class="form-group">
          <label class="form-label">Campaign Name *</label>
          <input type="text" name="name" id="wCampName" class="form-control" placeholder="e.g. Diwali Offer 2025" required>
        </div>

        <!-- Summary -->
        <div style="background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:16px;">
          <div class="sum-row"><div class="sum-label">Template</div><div class="sum-val" id="sumTpl">—</div></div>
          <div class="sum-row"><div class="sum-label">Category</div><div class="sum-val" id="sumCat">—</div></div>
          <div class="sum-row"><div class="sum-label">Audience</div><div class="sum-val" id="sumAud">—</div></div>
          <div class="sum-row"><div class="sum-label">Total Contacts</div><div class="sum-val" id="sumCount">—</div></div>
          <div class="sum-row"><div class="sum-label">Schedule</div><div class="sum-val" id="sumSched">Send Now</div></div>
          <div class="sum-row" style="border:none;padding-bottom:0;"><div class="sum-label">Est. Cost</div><div class="sum-val" style="color:var(--orange);" id="sumCost">—</div></div>
        </div>

        <!-- Template preview -->
        <div style="margin-bottom:16px;">
          <div style="font-size:12px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.7px;margin-bottom:8px;">Message Preview</div>
          <div style="background:#071510;border-radius:12px;padding:14px;max-height:160px;overflow-y:auto;">
            <div id="sumPreview" style="font-size:13px;color:#e2ffd9;line-height:1.65;white-space:pre-wrap;word-break:break-word;"></div>
          </div>
        </div>

        <?php if (!$waOk): ?>
        <div style="background:var(--danger-dim);border:1px solid rgba(255,77,109,0.3);border-radius:9px;padding:12px;font-size:13px;color:var(--danger);margin-bottom:14px;">
          ⚠ WhatsApp API not connected — messages will fail. <a href="/user/settings.php" style="color:var(--accent);">Connect first →</a>
        </div>
        <?php endif; ?>

        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:4px;">
          <button type="button" class="btn btn-secondary" onclick="goStep(2)">← Back</button>
          <button type="submit" class="btn btn-primary" style="padding:12px 32px;font-size:15px;">🚀 Launch Campaign</button>
        </div>
      </div>

    </form>
  </div>
</div>

<script>
const CSRF_TOKEN = '<?= csrfToken() ?>';
const TEMPLATES  = <?= json_encode(array_column($allTemplates, null, 'id')) ?>;
const LISTS      = <?= json_encode(array_column($lists, null, 'id')) ?>;
const TOTAL_CTCS = <?= $totalCtc ?>;
const MKTG_PRICE = <?= (float)(db()->row('SELECT COALESCE(up.price_per_msg,mp.price_per_msg) as p FROM message_pricing mp LEFT JOIN user_pricing up ON up.category=mp.category AND up.user_id=? WHERE mp.category=?',[$uid,'MARKETING'])['p'] ?? 0.75) ?>;

let selectedTplId = null;
let selectedAudId = 0; // 0 = all
let selectedAudCount = <?= $totalCtc ?>;
let selectedAudName  = 'All Contacts';
let currentStep = 1;

function openWizard() {
  currentStep = 1;
  selectedTplId = null;
  selectedAudId = 0;
  selectedAudCount = TOTAL_CTCS;
  selectedAudName = 'All Contacts';
  document.getElementById('wTplId').value = '';
  document.getElementById('wListId').value = '';
  document.getElementById('wCampName').value = '';
  document.querySelectorAll('.tpl-card').forEach(c=>c.classList.remove('selected'));
  document.querySelectorAll('.aud-option').forEach(a=>a.classList.remove('selected'));
  document.getElementById('aud-all')?.classList.add('selected');
  document.getElementById('step1Next').disabled = true;
  document.getElementById('varSection').style.display = 'none';
  goStep(1);
  openModal('wizModal');
}

function goStep(n) {
  currentStep = n;
  for (let i=1;i<=3;i++) {
    document.getElementById('step'+i).classList.toggle('active', i===n);
    const ws = document.getElementById('ws'+i);
    ws.classList.remove('active','done');
    if (i < n) ws.classList.add('done');
    else if (i === n) ws.classList.add('active');
  }
  if (n===3) updateSummary();
}

function nextStep(from) {
  if (from===1 && !selectedTplId) { alert('Please select a template'); return; }
  goStep(from+1);
}

function selectTemplate(el) {
  document.querySelectorAll('.tpl-card').forEach(c=>c.classList.remove('selected'));
  el.classList.add('selected');
  selectedTplId = el.dataset.id;
  document.getElementById('wTplId').value = selectedTplId;
  document.getElementById('step1Next').disabled = false;

  // Show variable fields
  const tpl = TEMPLATES[selectedTplId];
  const body = tpl?.body || '';
  const numVars = [...new Set([...body.matchAll(/\{\{(\d+)\}\}/g)].map(m=>parseInt(m[1])))].sort((a,b)=>a-b);
  const namedVars = [...new Set([...body.matchAll(/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/g)].map(m=>m[1]))];
  const vars = numVars.length > 0 ? numVars.map(String) : namedVars;

  const sec = document.getElementById('varSection');
  const inp = document.getElementById('varInputs');

  if (vars.length > 0) {
    sec.style.display = 'block';
    const defs = {'1':'Contact Name (auto)','2':'Phone Number (auto)','3':'Custom value'};
    inp.innerHTML = vars.map((v,i) => `
      <div style="display:flex;align-items:center;gap:10px;">
        <span style="background:var(--accent-dim);color:var(--accent);padding:3px 9px;border-radius:6px;font-family:monospace;font-size:12px;font-weight:700;white-space:nowrap;min-width:50px;text-align:center;">{{${v}}}</span>
        <input type="text" name="var_${numVars.length>0?v:i+1}" class="form-control" placeholder="${defs[v]||'Custom value'}" style="flex:1;font-size:13px;">
      </div>`).join('');
  } else {
    sec.style.display = 'none';
  }
}

function filterTpls(q) {
  const lq = q.toLowerCase();
  document.querySelectorAll('.tpl-card').forEach(c => {
    c.style.display = (!q || c.dataset.name.toLowerCase().includes(lq)) ? '' : 'none';
  });
}

function switchAudTab(tab) {
  ['all','lists','tags'].forEach(t => {
    document.getElementById('audPanel-'+t).style.display = t===tab ? 'block' : 'none';
    const btn = document.getElementById('atab-'+t);
    if (t===tab) {
      btn.style.background='var(--accent-dim)';btn.style.color='var(--accent)';btn.style.borderColor='var(--accent)';
    } else {
      btn.style.background='none';btn.style.color='var(--text2)';btn.style.borderColor='var(--border)';
    }
  });
  // Auto-select all contacts when switching to 'all' tab
  if (tab==='all') selectAudience('all',0,'All Contacts',TOTAL_CTCS);
}

function selectAudience(type, id, name, count, tag='') {
  // Deselect all
  document.querySelectorAll('.aud-option').forEach(a=>a.classList.remove('selected'));
  // Select correct one
  const elId = type==='all'?'aud-all':(type==='list'?'aud-list-'+id:'aud-tag-'+encodeURIComponent(tag));
  document.getElementById(elId)?.classList.add('selected');
  selectedAudId    = type==='list' ? id : 0;
  selectedAudCount = count;
  selectedAudName  = name;
  document.getElementById('wListId').value    = type==='list' ? id : 0;
  document.getElementById('wTagFilter').value = type==='tag' ? tag : '';
}

function toggleSched(v) {
  document.getElementById('schedField').style.display = v==='schedule' ? 'block' : 'none';
  document.getElementById('sumSched').textContent = v==='schedule' ? 'Scheduled' : 'Send Now';
}

function updateSummary() {
  const tpl = selectedTplId ? TEMPLATES[selectedTplId] : null;
  document.getElementById('sumTpl').textContent    = tpl ? tpl.template_name : '—';
  document.getElementById('sumCat').textContent    = tpl ? tpl.category : '—';
  document.getElementById('sumAud').textContent    = selectedAudName;
  document.getElementById('sumCount').textContent  = selectedAudCount.toLocaleString('en-IN') + ' contacts';
  document.getElementById('sumCost').textContent   = '₹' + (selectedAudCount * MKTG_PRICE).toFixed(2) + ' est.';

  // Preview body
  let body = tpl?.body || '';
  body = body.replace(/\{\{([^}]+)\}\}/g, '[[$1]]');
  document.getElementById('sumPreview').textContent = body;

  // Auto-fill campaign name if empty
  const nameEl = document.getElementById('wCampName');
  if (!nameEl.value && tpl) {
    nameEl.value = tpl.template_name + ' — ' + new Date().toLocaleDateString('en-IN', {day:'numeric',month:'short'});
  }
}

// Prevent accidental close
document.getElementById('wizModal').addEventListener('click', function(e) {
  if (e.target === this) {
    if (confirm('Close wizard? Your selections will be lost.')) closeModal('wizModal');
  }
});
</script>

<?php include __DIR__ . '/../includes/user_footer.php'; ?>
