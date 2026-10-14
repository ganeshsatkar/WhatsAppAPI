<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/WhatsAppService.php';
require_once __DIR__ . '/../includes/campaign_core.php';
requireLogin();
$uid = (int)currentUser()['id'];
$pageTitle = 'Campaign Report';
$id = (int)($_GET['id'] ?? $_POST['campaign_id'] ?? 0);
$camp = db()->row('SELECT c.*, mt.template_name, mt.category FROM campaigns c LEFT JOIN message_templates mt ON mt.id=c.template_id WHERE c.id=? AND c.user_id=?', [$id, $uid]);
if (!$camp) { header('Location: /user/campaigns.php'); exit; }
$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'retry') {
    verifyCsrf();
    [$re, $skipped] = campaignRetryFailed($id, $uid, !empty($_POST['all']));
    if ($re) $msg = "$re failed message(s) are queued again" . ($skipped ? " ($skipped permanent failure(s) skipped)" : '') . '. Go to Campaigns and press “Send More”, or the scheduler sends them within a minute.';
    else $err = $skipped ? "Nothing to retry – all $skipped failure(s) are permanent (e.g. number not on WhatsApp)." : 'No failed messages.';
}

$counts = [];
foreach (db()->all('SELECT status, COUNT(*) n FROM campaign_logs WHERE campaign_id=? GROUP BY status', [$id]) as $r) $counts[$r['status']] = (int)$r['n'];
$failedRows = db()->all("SELECT cl.*, c.name AS cname FROM campaign_logs cl LEFT JOIN contacts c ON c.id=cl.contact_id WHERE cl.campaign_id=? AND cl.status='failed' ORDER BY cl.failed_at DESC, cl.id DESC LIMIT 1000", [$id]);

$groups = []; $retryable = 0; $permanent = 0;
foreach ($failedRows as &$r) {
    $code = $r['error_code'] !== null ? (int)$r['error_code'] : null;
    if ($code === -1) $info = ['category' => 'skipped_opt_out', 'advice' => 'This person replied STOP (or is blocked), so the marketing message was not sent. Not charged.', 'retryable' => false];
    else $info = apiErrorInfo($code, (string)$r['error_message']);
    if ($code === null && stripos((string)$r['error_message'], 'curl') === false && $r['error_message'] !== '') $info['advice'] = $r['error_message'];
    $r['_info'] = $info; $r['_retry'] = campaignFailureRetryable($code);
    $r['_retry'] ? $retryable++ : $permanent++;
    $k = $info['category'];
    $groups[$k] ??= ['n' => 0, 'advice' => $info['advice'], 'retryable' => $r['_retry'], 'code' => $code];
    $groups[$k]['n']++;
}
unset($r);
$catLabel = ['not_on_whatsapp' => 'Number not on WhatsApp', 'ecosystem_limit' => 'Withheld by WhatsApp (healthy-ecosystem limit)', 'rate_limit' => 'Rate limit', 'pair_rate_limit' => 'Too many messages to same person',
             'template_missing' => 'Template missing at WhatsApp', 'params_mismatch' => 'Variable count mismatch', 'param_format' => 'Variable format problem', 'template_paused' => 'Template paused', 'template_disabled' => 'Template disabled',
             'token_invalid' => 'WhatsApp token expired', 'payment_issue' => 'Meta payment problem', 'skipped_opt_out' => 'Skipped – opted out / blocked', 'other' => 'Other', 'window_closed' => '24h window closed'];

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="campaign_' . $id . '_failed.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['name', 'phone', 'reason', 'meta_code', 'details', 'retryable', 'attempts', 'refunded', 'time_utc']);
    foreach ($failedRows as $r) fputcsv($o, [$r['cname'], $r['phone'], $r['_info']['category'], $r['error_code'], $r['error_message'], $r['_retry'] ? 'yes' : 'no', $r['attempts'], $r['refunded'] ? 'yes' : 'no', $r['failed_at']]);
    exit;
}
include __DIR__ . '/../includes/user_header.php';
$total = array_sum($counts);
?>
<?php if ($msg): ?><div class="alert alert-success">✓ <?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠ <?= e($err) ?></div><?php endif; ?>
<div style="margin-bottom:12px;"><a href="/user/campaigns.php" class="btn btn-secondary btn-sm">← Campaigns</a></div>
<div class="card mb2"><div class="card-body">
  <div style="font-size:18px;font-weight:700;"><?= e($camp['name']) ?> <span class="badge badge-gray"><?= e(ucfirst($camp['status'])) ?></span></div>
  <div style="color:var(--text2);font-size:13px;margin:4px 0 14px;">Template <strong><?= e($camp['template_name'] ?? '—') ?></strong> · <?= e(ucfirst(strtolower($camp['category'] ?? ''))) ?> · <?= $total ?> recipients</div>
  <div class="stats-grid" style="grid-template-columns:repeat(6,1fr);">
    <?php foreach (['pending' => ['⏳', 'Pending'], 'sent' => ['📤', 'Sent'], 'delivered' => ['📬', 'Delivered'], 'read' => ['👁', 'Read'], 'replied' => ['💬', 'Replied'], 'failed' => ['❌', 'Failed']] as $k => [$ic, $lb]): ?>
    <div class="stat-card"><div class="stat-icon"><?= $ic ?></div><div class="stat-value"><?= number_format($counts[$k] ?? 0) ?></div><div class="stat-label"><?= $lb ?></div></div>
    <?php endforeach; ?>
  </div>
</div></div>

<?php if ($failedRows): ?>
<div class="card mb2"><div class="card-header"><div class="card-title">Why messages failed</div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <?php if ($retryable): ?>
    <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="retry"><input type="hidden" name="campaign_id" value="<?= $id ?>"><button class="btn btn-primary btn-sm">🔄 Retry <?= $retryable ?> retryable</button></form>
    <?php endif; ?>
    <?php if ($permanent): ?>
    <form method="POST" onsubmit="return confirm('Retry the <?= $permanent ?> permanent failures as well? They will most likely fail again (you are never charged for undelivered messages).')"><?= csrfField() ?><input type="hidden" name="action" value="retry"><input type="hidden" name="all" value="1"><input type="hidden" name="campaign_id" value="<?= $id ?>"><button class="btn btn-secondary btn-sm">Retry all <?= count($failedRows) ?> anyway</button></form>
    <?php endif; ?>
    <a class="btn btn-secondary btn-sm" href="?id=<?= $id ?>&export=csv">⬇ CSV</a>
  </div></div>
  <div class="card-body">
    <?php foreach ($groups as $k => $g): ?>
    <div style="padding:10px 0;border-bottom:1px solid var(--border);">
      <div style="display:flex;justify-content:space-between;gap:10px;"><strong><?= e($catLabel[$k] ?? ucfirst(str_replace('_', ' ', $k))) ?></strong>
        <span><span class="badge badge-red"><?= $g['n'] ?></span> <?= $g['retryable'] ? '<span class="badge badge-blue">can retry</span>' : '<span class="badge badge-gray">permanent</span>' ?></span></div>
      <div style="font-size:12.5px;color:var(--text2);margin-top:3px;"><?= e($g['advice']) ?></div>
    </div>
    <?php endforeach; ?>
    <p style="font-size:12px;color:var(--muted);margin-top:12px;">Undelivered messages are refunded to your wallet automatically – WhatsApp does not bill them, and neither do we.</p>
  </div>
</div>

<div class="card"><div class="card-header"><div class="card-title">Failed recipients</div><span class="badge badge-red"><?= count($failedRows) ?></span></div>
  <div class="table-wrap"><table><thead><tr><th>Name</th><th>Number</th><th>Reason</th><th>Code</th><th>Tries</th><th>Money</th></tr></thead><tbody>
  <?php foreach ($failedRows as $r): ?>
    <tr><td class="td-bold"><?= e($r['cname'] ?? '—') ?></td><td><code><?= e($r['phone']) ?></code></td>
      <td><?= e($catLabel[$r['_info']['category']] ?? $r['_info']['category']) ?><div style="font-size:11px;color:var(--muted);"><?= e(mb_substr((string)$r['error_message'], 0, 120)) ?></div></td>
      <td><?= $r['error_code'] !== null && (int)$r['error_code'] > 0 ? (int)$r['error_code'] : '—' ?></td><td><?= (int)$r['attempts'] ?></td>
      <td><?= $r['refunded'] ? '<span class="badge badge-green">Refunded</span>' : '<span class="badge badge-gray">Not charged</span>' ?></td></tr>
  <?php endforeach; ?></tbody></table></div></div>
<?php else: ?>
<div class="card"><div class="empty-state"><div class="empty-icon">✅</div><div class="empty-title">No failed messages</div></div></div>
<?php endif; ?>
<?php include __DIR__ . '/../includes/user_footer.php'; ?>
