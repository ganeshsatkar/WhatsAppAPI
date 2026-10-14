<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/WhatsAppService.php';
require_once __DIR__ . '/../includes/wallet.php';
require_once __DIR__ . '/../includes/api_core.php';
requireLogin();
$pageTitle = 'API Messages';
$uid = (int)currentUser()['id'];
$msg = $err = '';

// ---- retry a failed / rejected message
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'retry') {
    verifyCsrf();
    $r = db()->row("SELECT * FROM api_messages WHERE id=? AND user_id=? AND status IN ('failed','rejected')", [(int)$_POST['id'], $uid]);
    if (!$r) $err = 'Only failed or rejected messages can be retried.';
    else {
        $in = json_decode((string)$r['request_json'], true) ?: [];
        unset($in['idempotency_key'], $in['_type']);
        $in['client_ref'] = $r['client_ref'];
        [$http, $row] = $r['message_type'] === 'text' ? apiSendText($uid, $r['api_key_id'] ? (int)$r['api_key_id'] : null, $in) : apiSendTemplate($uid, $r['api_key_id'] ? (int)$r['api_key_id'] : null, $in);
        db()->run('UPDATE api_messages SET retry_of=? WHERE id=?', [$r['id'], $row['id']]);
        if ($http === 200) $msg = "Retried — new message #{$row['id']} is {$row['status']}.";
        else $err = 'Retry failed: ' . $row['error_message'];
    }
}

// ---- filters
$range = $_GET['range'] ?? '7d';
$from = match ($range) { 'today' => gmdate('Y-m-d 00:00:00', strtotime('today')), '30d' => gmdate('Y-m-d H:i:s', time() - 30 * 86400), 'all' => '2000-01-01 00:00:00', default => gmdate('Y-m-d H:i:s', time() - 7 * 86400) };
if ($range === 'today') { $tz = new DateTimeZone('Asia/Kolkata'); $from = (new DateTime('today', $tz))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
$w = ['user_id=?', 'created_at >= ?']; $p = [$uid, $from];
$status = $_GET['status'] ?? '';
if (in_array($status, ['queued', 'sent', 'delivered', 'read', 'failed', 'rejected'], true)) { $w[] = 'status=?'; $p[] = $status; }
if (!empty($_GET['key']) && ctype_digit($_GET['key'])) { $w[] = 'api_key_id=?'; $p[] = (int)$_GET['key']; }
if (!empty($_GET['template'])) { $w[] = 'template_name=?'; $p[] = $_GET['template']; }
if (!empty($_GET['cat'])) { $w[] = 'error_category=?'; $p[] = $_GET['cat']; }
$q = trim($_GET['q'] ?? '');
if ($q !== '') { $w[] = '(to_phone LIKE ? OR client_ref LIKE ? OR wamid = ?)'; $like = '%' . preg_replace('/[^0-9a-zA-Z_\-]/', '', $q) . '%'; array_push($p, $like, '%' . $q . '%', $q); }
$where = implode(' AND ', $w);

// ---- CSV export
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="api-messages-' . date('Ymd-His') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['id', 'created_utc', 'to', 'template', 'client_ref', 'status', 'sent_utc', 'delivered_utc', 'read_utc', 'failed_utc', 'error_code', 'error_category', 'error_message', 'cost', 'refunded', 'wamid']);
    foreach (db()->all("SELECT * FROM api_messages WHERE $where ORDER BY id DESC LIMIT 50000", $p) as $r)
        fputcsv($o, [$r['id'], $r['created_at'], $r['to_phone'], $r['template_name'], $r['client_ref'], $r['status'], $r['sent_at'], $r['delivered_at'], $r['read_at'], $r['failed_at'], $r['error_code'], $r['error_category'], $r['error_message'], $r['cost'], $r['refunded'], $r['wamid']]);
    exit;
}

$st = db()->row("SELECT COUNT(*) total,
    SUM(status IN ('delivered','read')) delivered, SUM(status='read') readc, SUM(status='failed') failed, SUM(status='rejected') rejected,
    SUM(status IN ('queued','sent')) inflight, COALESCE(SUM(CASE WHEN refunded=0 THEN cost ELSE 0 END),0) spend
    FROM api_messages WHERE $where", $p);
$total = (int)$st['total']; $delivered = (int)$st['delivered'];
$accepted = $total - (int)$st['rejected'];
$rate = $accepted > 0 ? round($delivered / $accepted * 100, 1) : 0;

$reasons = db()->all("SELECT error_category c, MIN(error_code) code, COUNT(*) n, COUNT(DISTINCT to_phone) nums FROM api_messages WHERE $where AND status IN ('failed','rejected') AND error_category IS NOT NULL GROUP BY error_category ORDER BY n DESC LIMIT 8", $p);
$badNums = db()->all("SELECT to_phone, COUNT(*) n, MAX(error_category) c FROM api_messages WHERE $where AND status='failed' AND error_category IN ('not_on_whatsapp','ecosystem_limit') GROUP BY to_phone HAVING n>=2 ORDER BY n DESC LIMIT 6", $p);

$page = max(1, (int)($_GET['page'] ?? 1)); $per = 50;
$rows = db()->all("SELECT * FROM api_messages WHERE $where ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per), $p);
$pages = max(1, (int)ceil($total / $per));
$keys = db()->all('SELECT id,name FROM api_keys WHERE user_id=?', [$uid]);
$tpls = db()->all('SELECT DISTINCT template_name FROM api_messages WHERE user_id=? AND template_name IS NOT NULL ORDER BY 1', [$uid]);
$qs = fn(array $o) => '?' . http_build_query(array_merge($_GET, $o));

$badge = ['queued' => 'badge-gray', 'sent' => 'badge-gray', 'delivered' => 'badge-green', 'read' => 'badge-green', 'failed' => 'badge-red', 'rejected' => 'badge-orange'];
$label = ['queued' => '⏳ Queued', 'sent' => '✓ Sent', 'delivered' => '✓✓ Delivered', 'read' => '✓✓ Read', 'failed' => '✗ Failed', 'rejected' => '⛔ Rejected'];
include __DIR__ . '/../includes/user_header.php';
?>
<?php if ($msg): ?><div class="alert alert-success">✓ <?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠ <?= e($err) ?></div><?php endif; ?>

<div class="stats-grid mb2" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr));">
  <div class="stat-card"><div class="stat-value"><?= number_format($total) ?></div><div class="stat-label">Total</div></div>
  <div class="stat-card"><div class="stat-value" style="color:var(--accent)"><?= number_format($delivered) ?></div><div class="stat-label">Delivered (<?= $rate ?>%)</div></div>
  <div class="stat-card"><div class="stat-value"><?= number_format((int)$st['readc']) ?></div><div class="stat-label">Read</div></div>
  <div class="stat-card"><div class="stat-value"><?= number_format((int)$st['inflight']) ?></div><div class="stat-label">In flight</div></div>
  <div class="stat-card"><div class="stat-value" style="color:var(--danger)"><?= number_format((int)$st['failed']) ?></div><div class="stat-label">Failed at WhatsApp</div></div>
  <div class="stat-card"><div class="stat-value" style="color:var(--gold)"><?= number_format((int)$st['rejected']) ?></div><div class="stat-label">Rejected (not sent)</div></div>
  <div class="stat-card"><div class="stat-value">₹<?= number_format((float)$st['spend'], 2) ?></div><div class="stat-label">Spend (net of refunds)</div></div>
</div>

<?php if ($reasons): ?>
<div class="card mb2">
  <div class="card-header"><div class="card-title">⚠ Why messages are not delivered</div></div>
  <div class="card-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:12px;">
    <?php foreach ($reasons as $r): $info = apiErrorInfo($r['code'] !== null ? (int)$r['code'] : null, ''); ?>
    <a href="<?= e($qs(['cat' => $r['c'], 'page' => 1])) ?>" style="text-decoration:none;background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:12px;display:block;">
      <div style="font-weight:800;color:#fff;"><?= e(str_replace('_', ' ', $r['c'])) ?> <span class="badge badge-red"><?= (int)$r['n'] ?> msgs · <?= (int)$r['nums'] ?> numbers</span></div>
      <div style="font-size:12px;color:var(--text2);margin-top:6px;line-height:1.5;"><?= e($info['category'] === 'other' ? 'See the message error for details.' : $info['advice']) ?></div>
    </a>
    <?php endforeach; ?>
  </div>
  <?php if ($badNums): ?>
  <div style="padding:0 16px 14px;font-size:12.5px;color:var(--text2);">🚩 <strong>Repeat problem numbers</strong> (failed 2+ times): <?php foreach ($badNums as $b): ?><code style="margin-right:8px;"><?= e($b['to_phone']) ?> ×<?= (int)$b['n'] ?></code><?php endforeach; ?></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card mb2">
  <div class="card-body">
    <form method="GET" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      <select name="range" class="form-control" style="width:auto;"><?php foreach (['today' => 'Today', '7d' => 'Last 7 days', '30d' => 'Last 30 days', 'all' => 'All time'] as $k => $v): ?><option value="<?= $k ?>" <?= $range === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
      <select name="status" class="form-control" style="width:auto;"><option value="">All statuses</option><?php foreach ($label as $k => $v): ?><option value="<?= $k ?>" <?= $status === $k ? 'selected' : '' ?>><?= $v ?></option><?php endforeach; ?></select>
      <select name="template" class="form-control" style="width:auto;"><option value="">All templates</option><?php foreach ($tpls as $t): ?><option <?= ($_GET['template'] ?? '') === $t['template_name'] ? 'selected' : '' ?>><?= e($t['template_name']) ?></option><?php endforeach; ?></select>
      <?php if (count($keys) > 1): ?><select name="key" class="form-control" style="width:auto;"><option value="">All keys</option><?php foreach ($keys as $k): ?><option value="<?= $k['id'] ?>" <?= ($_GET['key'] ?? '') == $k['id'] ? 'selected' : '' ?>><?= e($k['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
      <input type="text" name="q" class="form-control" style="flex:1;min-width:180px;" placeholder="Search number, your reference or message id" value="<?= e($q) ?>">
      <button class="btn btn-primary">Filter</button>
      <a class="btn btn-secondary" href="<?= e($qs(['export' => 'csv'])) ?>">⬇ CSV</a>
      <label style="font-size:12px;color:var(--muted);display:flex;gap:5px;align-items:center;"><input type="checkbox" id="live"> Auto-refresh</label>
    </form>
  </div>
</div>

<div class="card">
  <div class="table-wrap"><table>
    <thead><tr><th>Time</th><th>Number</th><th>Template</th><th>Your ref</th><th>Status</th><th>Details</th><th></th></tr></thead>
    <tbody>
    <?php if (!$rows): ?><tr><td colspan="7" style="text-align:center;color:var(--muted);padding:30px;">No API messages in this range yet. Create a key in <a href="/user/api_keys.php" style="color:var(--accent)">Developer API</a> and send your first message.</td></tr><?php endif; ?>
    <?php foreach ($rows as $r): ?>
    <tr>
      <td style="white-space:nowrap;"><time data-ts="<?= e($r['created_at']) ?>"></time></td>
      <td><code><?= e($r['to_phone']) ?></code></td>
      <td><?= e($r['template_name'] ?? ($r['message_type'] === 'text' ? '(text)' : '')) ?></td>
      <td><?= e($r['client_ref'] ?? '—') ?></td>
      <td><span class="badge <?= $badge[$r['status']] ?>"><?= $label[$r['status']] ?></span>
        <?php if ($r['retry_of']): ?><div style="font-size:10.5px;color:var(--muted);">retry of #<?= (int)$r['retry_of'] ?></div><?php endif; ?></td>
      <td style="font-size:12px;line-height:1.5;max-width:380px;">
        <?php if (in_array($r['status'], ['failed', 'rejected'], true)): $info = apiErrorInfo($r['error_code'] !== null ? (int)$r['error_code'] : null, (string)$r['error_message']); ?>
          <div style="color:var(--danger);"><?= $r['error_code'] ? '#' . (int)$r['error_code'] . ' · ' : '' ?><?= e($r['error_category'] ? str_replace('_', ' ', $r['error_category']) : 'error') ?></div>
          <div style="color:var(--text2);"><?= e($r['error_message']) ?></div>
          <?php if ($info['category'] !== 'other'): ?><div style="color:var(--muted);"><?= e($info['advice']) ?></div><?php endif; ?>
          <?= $r['refunded'] ? '<div style="color:var(--accent);">↩ Refunded</div>' : '' ?>
        <?php else: ?>
          <?php foreach (['sent_at' => 'Sent', 'delivered_at' => 'Delivered', 'read_at' => 'Read'] as $col => $lb): if ($r[$col]): ?><div style="color:var(--text2);"><?= $lb ?> <time data-ts="<?= e($r[$col]) ?>"></time></div><?php endif; endforeach; ?>
          <?php if ($r['status'] === 'sent' && strtotime($r['created_at'] . ' UTC') < time() - 86400): ?><div style="color:var(--gold);">No delivery report after 24h — phone may be off / not on WhatsApp.</div><?php endif; ?>
        <?php endif; ?>
        <?php if ($r['callback_state'] !== 'none'): ?><div style="color:var(--muted);font-size:11px;">↗ your app: <?= $r['callback_state'] === 'done' ? 'notified ✓' : ($r['callback_state'] === 'pending' ? 'pending…' : 'failed — ' . e($r['last_callback_error'])) ?></div><?php endif; ?>
      </td>
      <td>
        <?php if (in_array($r['status'], ['failed', 'rejected'], true)): ?>
        <form method="POST"><?= csrfField() ?><input type="hidden" name="action" value="retry"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-secondary" style="padding:4px 10px;font-size:12px;">↻ Retry</button></form>
        <?php endif; ?>
      </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php if ($pages > 1): ?><div style="display:flex;gap:6px;justify-content:center;padding:14px;flex-wrap:wrap;"><?php for ($i = max(1, $page - 3); $i <= min($pages, $page + 3); $i++): ?><a class="btn <?= $i === $page ? 'btn-primary' : 'btn-secondary' ?>" href="<?= e($qs(['page' => $i])) ?>"><?= $i ?></a><?php endfor; ?></div><?php endif; ?>
</div>
<script>
document.querySelectorAll('time[data-ts]').forEach(t=>{t.textContent=new Date(t.dataset.ts.replace(' ','T')+'Z').toLocaleString('en-IN',{day:'numeric',month:'short',hour:'2-digit',minute:'2-digit',second:'2-digit'});});
const lv=document.getElementById('live');try{lv.checked=localStorage.getItem('apiLive')==='1';}catch(e){}
lv.onchange=()=>{try{localStorage.setItem('apiLive',lv.checked?'1':'0');}catch(e){}};
setInterval(()=>{if(lv.checked&&!document.hidden)location.reload();},20000);
</script>
<?php include __DIR__ . '/../includes/user_footer.php'; ?>
