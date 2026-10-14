<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();

$pageTitle = 'Dashboard';
$uid = currentUser()['id'];

// Stats
$totalContacts   = db()->count('SELECT COUNT(*) FROM contacts WHERE user_id = ?', [$uid]);
$totalCampaigns  = db()->count('SELECT COUNT(*) FROM campaigns WHERE user_id = ?', [$uid]);
$openConversations = db()->count('SELECT COUNT(*) FROM conversations WHERE user_id = ? AND status = ?', [$uid, 'open']);
$activeBots      = db()->count('SELECT COUNT(*) FROM bot_flows WHERE user_id = ? AND is_active = 1', [$uid]);
$msgToday        = db()->count('SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE c.user_id = ? AND m.direction = ? AND DATE(m.created_at) = CURDATE()', [$uid, 'outbound']);
$qrScansToday    = db()->count('SELECT COUNT(*) FROM qr_scans qs JOIN qr_codes qc ON qc.id = qs.qr_id WHERE qc.user_id = ? AND DATE(qs.scanned_at) = CURDATE()', [$uid]);

// Recent campaigns
$campaigns = db()->all('SELECT * FROM campaigns WHERE user_id = ? ORDER BY created_at DESC LIMIT 5', [$uid]);

// Recent conversations
$conversations = db()->all('
    SELECT cv.*, c.name as contact_name, c.phone 
    FROM conversations cv 
    JOIN contacts c ON c.id = cv.contact_id 
    WHERE cv.user_id = ? 
    ORDER BY cv.last_message_at DESC 
    LIMIT 8', [$uid]);

// Weekly messages chart data
$chartData = [];
for ($i = 6; $i >= 0; $i--) {
    $date  = date('Y-m-d', strtotime("-$i days"));
    $label = date('D', strtotime("-$i days"));
    $sent  = db()->count('SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE c.user_id = ? AND m.direction = ? AND DATE(m.created_at) = ?', [$uid, 'outbound', $date]);
    $recv  = db()->count('SELECT COUNT(*) FROM messages m JOIN conversations c ON c.id = m.conversation_id WHERE c.user_id = ? AND m.direction = ? AND DATE(m.created_at) = ?', [$uid, 'inbound', $date]);
    $chartData[] = ['label' => $label, 'sent' => $sent, 'recv' => $recv];
}

// ── Getting started checklist ─────────────────────────────
$isAdminUser = currentUser()['role'] === 'superadmin';
$sub         = subscriptionState((int)$uid);
$wa          = db()->row('SELECT is_connected FROM user_whatsapp_settings WHERE user_id = ?', [$uid]);
$tplApproved = db()->count("SELECT COUNT(*) FROM message_templates WHERE user_id = ? AND status = 'approved'", [$uid]);
$tplPending  = db()->count("SELECT COUNT(*) FROM message_templates WHERE user_id = ? AND status = 'pending'", [$uid]);
$walletBal   = (float)(db()->row('SELECT balance FROM wallets WHERE user_id = ?', [$uid])['balance'] ?? 0);
$sentAny     = db()->count('SELECT COUNT(*) FROM campaigns WHERE user_id = ? AND sent_count > 0', [$uid]);
$steps = [
    ['done' => $isAdminUser || $sub['active'], 'title' => 'Activate your plan',
     'text' => $sub['active'] ? ($sub['is_trial'] ? 'Free trial is running — ' . (int)$sub['days_left'] . ' day(s) left.' : 'Your plan is active.') : 'Start a plan to unlock campaigns, inbox and chatbots.',
     'href' => '/user/billing.php', 'cta' => 'Choose plan'],
    ['done' => !empty($wa['is_connected']), 'title' => 'Connect your WhatsApp number',
     'text' => 'Link your number through Meta’s official WhatsApp Cloud API (step-by-step in Settings).', 'href' => '/user/settings.php', 'cta' => 'Connect'],
    ['done' => $totalContacts > 0, 'title' => 'Add your customers',
     'text' => 'Add contacts one by one or upload a CSV / Excel export.', 'href' => '/user/contacts.php', 'cta' => 'Add contacts'],
    ['done' => $tplApproved > 0, 'title' => 'Get a message template approved',
     'text' => $tplPending ? "$tplPending template(s) waiting for Meta approval (usually minutes)." : 'Pick a ready-made template from the library — one click submits it to Meta.',
     'href' => '/user/templates.php#library', 'cta' => 'Open library'],
    ['done' => $walletBal > 0 || $sentAny > 0, 'title' => 'Add money to your wallet',
     'text' => 'WhatsApp charges per message are paid from your prepaid wallet (UPI, cards, net-banking).', 'href' => '/user/wallet.php', 'cta' => 'Recharge'],
    ['done' => $sentAny > 0, 'title' => 'Send your first campaign',
     'text' => 'Choose a template, pick your audience and launch — delivery and read reports update live.', 'href' => '/user/campaigns.php', 'cta' => 'New campaign'],
];
$doneCount = count(array_filter($steps, fn($s) => $s['done']));

include __DIR__ . '/../includes/user_header.php';
?>

<?php if ($doneCount < count($steps)): ?>
<style>
.gs{background:linear-gradient(135deg,rgba(37,211,102,0.08),rgba(37,211,102,0.02));border:1px solid rgba(37,211,102,0.25);border-radius:14px;padding:18px 20px;margin-bottom:22px;}
.gs-head{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:12px;}
.gs-title{font-size:16px;font-weight:900;color:#fff;}
.gs-sub{font-size:12.5px;color:var(--text2);margin-top:2px;}
.gs-bar{height:6px;background:var(--surface3);border-radius:4px;overflow:hidden;margin-bottom:14px;}
.gs-bar span{display:block;height:100%;background:var(--accent);border-radius:4px;transition:width .4s;}
.gs-step{display:flex;align-items:center;gap:12px;padding:11px 0;border-top:1px solid var(--border);}
.gs-dot{width:28px;height:28px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:900;border:2px solid var(--border2);color:var(--text2);}
.gs-step.done .gs-dot{background:var(--accent);border-color:var(--accent);color:#000;}
.gs-step.done .gs-st{color:var(--text2);text-decoration:line-through;}
.gs-st{font-size:14px;font-weight:800;color:var(--text);}
.gs-tx{font-size:12.5px;color:var(--text2);margin-top:2px;line-height:1.5;}
.gs-step .btn{margin-left:auto;flex-shrink:0;}
.gs-step.next{background:rgba(37,211,102,0.05);border-radius:10px;padding:11px 10px;margin:0 -10px;}
@media(max-width:768px){.gs{padding:14px;} .gs-step{flex-wrap:wrap;} .gs-step .btn{margin-left:40px;}}
</style>
<div class="gs" id="gsCard">
  <div class="gs-head">
    <div>
      <div class="gs-title">🚀 Getting started — <?= $doneCount ?> of <?= count($steps) ?> done</div>
      <div class="gs-sub">Finish these steps to send your first WhatsApp campaign.</div>
    </div>
    <button type="button" class="btn btn-secondary btn-sm" onclick="hideGs()">Hide</button>
  </div>
  <div class="gs-bar"><span style="width:<?= round($doneCount / count($steps) * 100) ?>%"></span></div>
  <?php $nextShown = false; foreach ($steps as $i => $s): $isNext = !$s['done'] && !$nextShown; if ($isNext) $nextShown = true; ?>
  <div class="gs-step <?= $s['done'] ? 'done' : '' ?> <?= $isNext ? 'next' : '' ?>">
    <div class="gs-dot"><?= $s['done'] ? '✓' : $i + 1 ?></div>
    <div style="min-width:0;flex:1;">
      <div class="gs-st"><?= e($s['title']) ?></div>
      <div class="gs-tx"><?= e($s['text']) ?></div>
    </div>
    <?php if (!$s['done']): ?><a href="<?= e($s['href']) ?>" class="btn <?= $isNext ? 'btn-primary' : 'btn-secondary' ?> btn-sm"><?= e($s['cta']) ?> →</a><?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<script>
// "Hide" remembers the progress level, so the card comes back when there is a new step to do
(function(){ try { if (localStorage.getItem('wpapi_gs_hidden') === '<?= $doneCount ?>') document.getElementById('gsCard').style.display = 'none'; } catch(e){} })();
function hideGs(){ try { localStorage.setItem('wpapi_gs_hidden', '<?= $doneCount ?>'); } catch(e){} document.getElementById('gsCard').style.display = 'none'; }
</script>
<?php endif; ?>

<!-- Stats Grid -->
<div class="stats-grid">
  <div class="stat-card" style="--c:var(--blue); --c-dim:var(--blue-dim)">
    <div class="stat-icon">👥</div>
    <div class="stat-value" style="color:var(--blue)"><?= formatNumber($totalContacts) ?></div>
    <div class="stat-label">Total Contacts</div>
  </div>
  <div class="stat-card" style="--c:var(--accent); --c-dim:var(--accent-dim)">
    <div class="stat-icon">💬</div>
    <div class="stat-value" style="color:var(--accent)"><?= formatNumber($msgToday) ?></div>
    <div class="stat-label">Messages Sent Today</div>
  </div>
  <div class="stat-card" style="--c:var(--orange); --c-dim:var(--orange-dim)">
    <div class="stat-icon">📢</div>
    <div class="stat-value" style="color:var(--orange)"><?= formatNumber($totalCampaigns) ?></div>
    <div class="stat-label">Total Campaigns</div>
  </div>
  <div class="stat-card" style="--c:var(--purple); --c-dim:var(--purple-dim)">
    <div class="stat-icon">💬</div>
    <div class="stat-value" style="color:var(--purple)"><?= formatNumber($openConversations) ?></div>
    <div class="stat-label">Open Chats</div>
  </div>
  <div class="stat-card" style="--c:var(--accent); --c-dim:var(--accent-dim)">
    <div class="stat-icon">🤖</div>
    <div class="stat-value" style="color:var(--accent)"><?= $activeBots ?></div>
    <div class="stat-label">Active Bots</div>
  </div>
  <div class="stat-card" style="--c:var(--blue); --c-dim:var(--blue-dim)">
    <div class="stat-icon">📱</div>
    <div class="stat-value" style="color:var(--blue)"><?= $qrScansToday ?></div>
    <div class="stat-label">QR Scans Today</div>
  </div>
</div>

<div style="display:grid; grid-template-columns: 1fr 1fr; gap:20px; margin-bottom:24px;">

  <!-- Message Chart -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">Messages This Week</div>
      <div style="display:flex;gap:12px;font-size:12px;color:var(--text2)">
        <span style="display:flex;align-items:center;gap:5px"><span style="width:10px;height:10px;border-radius:50%;background:var(--accent);display:inline-block"></span>Sent</span>
        <span style="display:flex;align-items:center;gap:5px"><span style="width:10px;height:10px;border-radius:50%;background:var(--blue);display:inline-block"></span>Received</span>
      </div>
    </div>
    <div style="height:160px; display:flex; align-items:flex-end; gap:8px; padding-bottom:24px; position:relative;">
      <?php
      $maxVal = max(1, ...array_merge(array_column($chartData, 'sent'), array_column($chartData, 'recv')));
      foreach ($chartData as $d):
        $sH = max(4, round(($d['sent'] / $maxVal) * 140));
        $rH = max(4, round(($d['recv'] / $maxVal) * 140));
      ?>
      <div style="flex:1; display:flex; flex-direction:column; align-items:center; gap:4px; justify-content:flex-end;">
        <div style="display:flex; gap:3px; align-items:flex-end; width:100%; justify-content:center;">
          <div style="height:<?= $sH ?>px; background:var(--accent); border-radius:3px 3px 0 0; flex:1; opacity:0.85; transition:height 0.3s"></div>
          <div style="height:<?= $rH ?>px; background:var(--blue); border-radius:3px 3px 0 0; flex:1; opacity:0.85; transition:height 0.3s"></div>
        </div>
        <div style="font-size:10px; color:var(--muted); position:absolute; bottom:0;"><?= $d['label'] ?></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- Recent Conversations -->
  <div class="card">
    <div class="card-header">
      <div class="card-title">Recent Conversations</div>
      <a href="/user/inbox.php" class="btn btn-secondary btn-sm">View all</a>
    </div>
    <?php if (empty($conversations)): ?>
      <div style="text-align:center; padding:30px 0; color:var(--muted); font-size:13px;">No conversations yet</div>
    <?php else: ?>
      <div style="display:flex; flex-direction:column; gap:2px;">
        <?php foreach ($conversations as $conv): ?>
        <a href="/user/inbox.php?conv=<?= $conv['id'] ?>" style="display:flex; align-items:center; gap:10px; padding:9px 0; border-bottom:1px solid var(--border); text-decoration:none; color:inherit;">
          <div style="width:34px; height:34px; border-radius:50%; background:linear-gradient(135deg,var(--accent2),var(--accent)); display:flex; align-items:center; justify-content:center; font-weight:700; font-size:12px; color:#fff; flex-shrink:0;">
            <?= strtoupper(substr($conv['contact_name'], 0, 2)) ?>
          </div>
          <div style="flex:1; min-width:0;">
            <div style="font-size:13px; font-weight:500; white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= e($conv['contact_name']) ?></div>
            <div style="font-size:12px; color:var(--muted); white-space:nowrap; overflow:hidden; text-overflow:ellipsis;"><?= e($conv['last_message_preview'] ?? '') ?></div>
          </div>
          <div style="font-size:11px; color:var(--muted); flex-shrink:0;"><?= timeAgo($conv['last_message_at'] ?? $conv['created_at']) ?></div>
          <?php if ($conv['unread_count'] > 0): ?>
          <div style="background:var(--accent); color:#000; font-size:10px; font-weight:700; width:18px; height:18px; border-radius:50%; display:flex; align-items:center; justify-content:center;"><?= $conv['unread_count'] ?></div>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>

<!-- Recent Campaigns -->
<div class="card">
  <div class="card-header">
    <div class="card-title">Recent Campaigns</div>
    <a href="/user/campaigns.php" class="btn btn-primary btn-sm">+ New Campaign</a>
  </div>
  <?php if (empty($campaigns)): ?>
  <div class="empty-state">
    <div class="empty-icon">📢</div>
    <div class="empty-title">No campaigns yet</div>
    <div class="empty-sub">Create your first broadcast campaign to reach all your contacts at once.</div>
    <a href="/user/campaigns.php" class="btn btn-primary">Create Campaign</a>
  </div>
  <?php else: ?>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Name</th>
          <th>Status</th>
          <th>Sent</th>
          <th>Delivered</th>
          <th>Read</th>
          <th>Failed</th>
          <th>Date</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($campaigns as $c):
          $statusClass = match($c['status']) {
            'completed' => 'badge-green', 'running' => 'badge-blue',
            'scheduled' => 'badge-orange', 'failed' => 'badge-red',
            default => 'badge-gray'
          };
        ?>
        <tr>
          <td style="color:var(--text); font-weight:500;"><?= e($c['name']) ?></td>
          <td><span class="badge <?= $statusClass ?>"><?= ucfirst($c['status']) ?></span></td>
          <td><?= formatNumber($c['sent_count']) ?></td>
          <td><?= formatNumber($c['delivered_count']) ?></td>
          <td><?= formatNumber($c['read_count']) ?></td>
          <td style="color:<?= $c['failed_count'] > 0 ? 'var(--danger)' : 'inherit' ?>"><?= $c['failed_count'] ?></td>
          <td><?= date('M j, Y', strtotime($c['created_at'])) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/user_footer.php'; ?>
