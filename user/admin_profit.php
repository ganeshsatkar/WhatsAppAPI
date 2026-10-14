<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/wallet.php';
requireLogin();
if (currentUser()['role'] !== 'superadmin') { header('Location: /user/dashboard.php'); exit; }
$pageTitle = 'Profit & Meta Cost';

// ---- period (boundaries in IST, database is UTC) ----
$tz  = new DateTimeZone('Asia/Kolkata'); $utc = new DateTimeZone('UTC');
$range = $_GET['range'] ?? '30d';
$now = new DateTime('now', $tz);
$today0 = (clone $now)->setTime(0, 0, 0);
switch ($range) {
    case 'today': $from = clone $today0; $to = (clone $today0)->modify('+1 day'); break;
    case '7d':    $from = (clone $today0)->modify('-6 days'); $to = (clone $today0)->modify('+1 day'); break;
    case 'month': $from = (clone $today0)->modify('first day of this month'); $to = (clone $today0)->modify('+1 day'); break;
    case 'lastmonth': $from = (clone $today0)->modify('first day of last month'); $to = (clone $today0)->modify('first day of this month'); break;
    case 'all':   $from = new DateTime('2020-01-01', $tz); $to = (clone $today0)->modify('+1 day'); break;
    case 'custom':
        $f = DateTime::createFromFormat('Y-m-d', $_GET['from'] ?? '', $tz); $t = DateTime::createFromFormat('Y-m-d', $_GET['to'] ?? '', $tz);
        if ($f && $t && $f <= $t) { $from = $f->setTime(0, 0, 0); $to = $t->setTime(0, 0, 0)->modify('+1 day'); break; }
        $range = '30d'; // fall through to default
    default:      $range = '30d'; $from = (clone $today0)->modify('-29 days'); $to = (clone $today0)->modify('+1 day');
}
$f = (clone $from)->setTimezone($utc)->format('Y-m-d H:i:s');
$t = (clone $to)->setTimezone($utc)->format('Y-m-d H:i:s');
$label = $range === 'all' ? 'All time' : $from->format('d M Y') . ' – ' . (clone $to)->modify('-1 day')->format('d M Y');

// Message rows: sends are debits that carry a Meta cost; failed-API-message refunds are credits that carry one. Refunds count negative.
const PROFIT_WHERE = "wt.status='success' AND wt.meta_cost IS NOT NULL AND (wt.type='debit' OR wt.ref_type IN ('api_refund','campaign_refund')) AND wt.created_at >= ? AND wt.created_at < ?";
const SIGN = "(CASE WHEN wt.type='debit' THEN 1 ELSE -1 END)";
$sel = "COALESCE(SUM(" . SIGN . "*wt.msg_count),0) AS msgs, COALESCE(SUM(" . SIGN . "*wt.amount),0) AS billed, COALESCE(SUM(" . SIGN . "*wt.meta_cost),0) AS meta";

$tot = db()->row("SELECT $sel FROM wallet_transactions wt WHERE " . PROFIT_WHERE, [$f, $t]);
$unknownCnt = db()->count("SELECT COUNT(*) FROM wallet_transactions wt WHERE " . PROFIT_WHERE . " AND wt.msg_count IS NULL", [$f, $t]);
$billed = (float)$tot['billed']; $meta = (float)$tot['meta']; $margin = $billed - $meta;
$mpct = $meta > 0 ? round($margin / $meta * 100, 1) : 0;

$byCat = db()->all("SELECT COALESCE(wt.category,'(older)') AS k, $sel FROM wallet_transactions wt WHERE " . PROFIT_WHERE . " GROUP BY k ORDER BY billed DESC", [$f, $t]);
$bySrc = db()->all("SELECT wt.ref_type AS k, $sel FROM wallet_transactions wt WHERE " . PROFIT_WHERE . " GROUP BY wt.ref_type ORDER BY billed DESC", [$f, $t]);
$byUser = db()->all("SELECT u.id, u.name, u.email, COALESCE(w.balance,0) AS balance, $sel
    FROM wallet_transactions wt JOIN users u ON u.id=wt.user_id LEFT JOIN wallets w ON w.user_id=u.id
    WHERE " . PROFIT_WHERE . " GROUP BY u.id ORDER BY (SUM(" . SIGN . "*wt.amount) - SUM(" . SIGN . "*wt.meta_cost)) DESC", [$f, $t]);
$daily = db()->all("SELECT DATE(CONVERT_TZ(wt.created_at,'+00:00','+05:30')) AS d, $sel FROM wallet_transactions wt WHERE " . PROFIT_WHERE . " GROUP BY d ORDER BY d DESC LIMIT 31", [$f, $t]);

// Platform fee (plan payments) – separate from message margin
$fees = (float)(db()->row("SELECT COALESCE(SUM(amount),0) s FROM payments WHERE status='success' AND created_at >= ? AND created_at < ?", [$f, $t])['s'] ?? 0);
// Cash in / bonuses / liability
$cash = db()->row("SELECT COALESCE(SUM(CASE WHEN ref_type IN ('recharge','admin_credit') THEN amount END),0) AS paid,
                          COALESCE(SUM(CASE WHEN ref_type='admin_bonus' THEN amount END),0) AS bonus
                   FROM wallet_transactions WHERE type='credit' AND status='success' AND created_at >= ? AND created_at < ?", [$f, $t]);
$liability = (float)(db()->row('SELECT COALESCE(SUM(balance),0) s FROM wallets')['s'] ?? 0);
$totalIncome = $margin + $fees;

// ---- CSV ----
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="profit_' . date('Ymd') . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['period', $label]);
    fputcsv($o, ['user', 'email', 'messages', 'meta_cost', 'billed_to_user', 'margin', 'margin_pct_on_meta', 'wallet_balance']);
    foreach ($byUser as $r) {
        $mg = (float)$r['billed'] - (float)$r['meta'];
        fputcsv($o, [$r['name'], $r['email'], (int)$r['msgs'], round($r['meta'], 4), round($r['billed'], 4), round($mg, 4), $r['meta'] > 0 ? round($mg / $r['meta'] * 100, 1) : 0, $r['balance']]);
    }
    fputcsv($o, ['TOTAL', '', (int)$tot['msgs'], round($meta, 4), round($billed, 4), round($margin, 4), $mpct, '']);
    exit;
}

$srcName = ['campaign' => '📢 Campaigns', 'inbox_template' => '💬 Inbox templates', 'api_message' => '🔌 Public API', 'api_refund' => '↩ API refunds', 'campaign_refund' => '↩ Campaign refunds'];
$fmt = fn($v, $d = 2) => '₹' . number_format((float)$v, $d);
$qs = fn(array $o) => '?' . http_build_query(array_merge(['range' => $range, 'from' => $_GET['from'] ?? '', 'to' => $_GET['to'] ?? ''], $o));
include __DIR__ . '/../includes/user_header.php';
?>
<form method="GET" class="card mb2"><div class="card-body" style="display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;">
  <div><label class="form-label">Period</label>
    <select name="range" class="form-control" onchange="this.form.submit()">
      <?php foreach (['today'=>'Today','7d'=>'Last 7 days','30d'=>'Last 30 days','month'=>'This month','lastmonth'=>'Last month','all'=>'All time','custom'=>'Custom…'] as $k=>$v): ?>
      <option value="<?= $k ?>" <?= $range===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?>
    </select></div>
  <div><label class="form-label">From</label><input type="date" name="from" class="form-control" value="<?= e($_GET['from'] ?? '') ?>"></div>
  <div><label class="form-label">To</label><input type="date" name="to" class="form-control" value="<?= e($_GET['to'] ?? '') ?>"></div>
  <button class="btn btn-primary" onclick="this.form.range.value='custom'">Apply dates</button>
  <a class="btn btn-secondary" href="<?= e($qs(['export'=>'csv'])) ?>">⬇ CSV</a>
  <span style="margin-left:auto;font-size:12px;color:var(--muted);">📅 <?= e($label) ?> (IST)</span>
</div></form>

<div class="stats-grid mb2" style="grid-template-columns:repeat(4,1fr);">
  <div class="stat-card" style="--c:var(--blue);--c-dim:var(--blue-dim)"><div class="stat-icon">💬</div><div class="stat-value"><?= number_format((int)$tot['msgs']) ?></div><div class="stat-label">Messages billed (net of refunds)</div></div>
  <div class="stat-card" style="--c:var(--danger);--c-dim:var(--danger-dim)"><div class="stat-icon">🧾</div><div class="stat-value"><?= $fmt($meta) ?></div><div class="stat-label">Meta cost (you pay Meta)</div></div>
  <div class="stat-card" style="--c:var(--accent);--c-dim:var(--accent-dim)"><div class="stat-icon">📤</div><div class="stat-value"><?= $fmt($billed) ?></div><div class="stat-label">Charged to users</div></div>
  <div class="stat-card" style="--c:var(--gold);--c-dim:var(--gold-dim)"><div class="stat-icon">📈</div><div class="stat-value"><?= $fmt($margin) ?></div><div class="stat-label">Message margin (<?= $mpct ?>% on Meta cost)</div></div>
</div>
<div class="stats-grid mb2" style="grid-template-columns:repeat(4,1fr);">
  <div class="stat-card" style="--c:var(--purple);--c-dim:var(--purple-dim)"><div class="stat-icon">🏷</div><div class="stat-value"><?= $fmt($fees) ?></div><div class="stat-label">Platform fees (plan payments)</div></div>
  <div class="stat-card" style="--c:var(--accent);--c-dim:var(--accent-dim)"><div class="stat-icon">💰</div><div class="stat-value"><?= $fmt($totalIncome) ?></div><div class="stat-label">Total income = margin + platform fees</div></div>
  <div class="stat-card" style="--c:var(--blue);--c-dim:var(--blue-dim)"><div class="stat-icon">💵</div><div class="stat-value"><?= $fmt($cash['paid']) ?></div><div class="stat-label">Recharges received (online + offline)</div></div>
  <div class="stat-card" style="--c:var(--orange);--c-dim:var(--orange-dim)"><div class="stat-icon">🎁</div><div class="stat-value"><?= $fmt($cash['bonus']) ?></div><div class="stat-label">Free bonus credit given</div></div>
</div>
<div class="alert alert-info mb2" style="font-size:12.5px;">
  💡 <strong>Example:</strong> users sent 100 messages — Meta cost ₹100, users were charged ₹120 → margin ₹20 (20%).
  Wallet money still held for users (you owe them service): <strong><?= $fmt($liability) ?></strong>. Meta cost is calculated from the rates set in Wallet Mgmt at the time of each send — compare it with Meta's real invoice monthly.
  <?php if ($unknownCnt): ?><br>⚠ <?= $unknownCnt ?> older transaction(s) have no message count — their money is included, their count is not.<?php endif; ?>
</div>

<div class="g2 mb2">
  <div class="card"><div class="card-header"><div class="card-title">By message category</div></div>
    <div class="table-wrap"><table><thead><tr><th>Category</th><th>Msgs</th><th>Meta cost</th><th>Charged</th><th>Margin</th></tr></thead><tbody>
    <?php foreach ($byCat as $r): $mg=(float)$r['billed']-(float)$r['meta']; ?>
      <tr><td class="td-bold"><?= e(ucfirst(strtolower($r['k']))) ?></td><td><?= number_format((int)$r['msgs']) ?></td><td><?= $fmt($r['meta']) ?></td><td><?= $fmt($r['billed']) ?></td><td style="color:var(--accent);font-weight:700;"><?= $fmt($mg) ?></td></tr>
    <?php endforeach; if (!$byCat): ?><tr><td colspan="5" style="text-align:center;color:var(--muted);padding:20px;">No billed messages in this period</td></tr><?php endif; ?>
    </tbody></table></div></div>
  <div class="card"><div class="card-header"><div class="card-title">By source</div></div>
    <div class="table-wrap"><table><thead><tr><th>Source</th><th>Msgs</th><th>Meta cost</th><th>Charged</th><th>Margin</th></tr></thead><tbody>
    <?php foreach ($bySrc as $r): $mg=(float)$r['billed']-(float)$r['meta']; ?>
      <tr><td class="td-bold"><?= $srcName[$r['k']] ?? e($r['k']) ?></td><td><?= number_format((int)$r['msgs']) ?></td><td><?= $fmt($r['meta']) ?></td><td><?= $fmt($r['billed']) ?></td><td style="color:var(--accent);font-weight:700;"><?= $fmt($mg) ?></td></tr>
    <?php endforeach; if (!$bySrc): ?><tr><td colspan="5" style="text-align:center;color:var(--muted);padding:20px;">—</td></tr><?php endif; ?>
    </tbody></table></div></div>
</div>

<div class="card mb2"><div class="card-header"><div class="card-title">👥 Per user — Meta cost vs what the user paid</div><span class="badge badge-green"><?= count($byUser) ?></span></div>
  <div class="table-wrap"><table><thead><tr><th>User</th><th>Msgs</th><th>Meta cost</th><th>Charged to user</th><th>Your margin</th><th>Margin %</th><th>Wallet now</th></tr></thead><tbody>
  <?php foreach ($byUser as $r): $mg=(float)$r['billed']-(float)$r['meta']; ?>
    <tr><td><div class="td-bold"><?= e($r['name']) ?></div><div style="font-size:11px;color:var(--muted);"><?= e($r['email']) ?></div></td>
      <td><?= number_format((int)$r['msgs']) ?></td><td style="color:var(--danger);"><?= $fmt($r['meta']) ?></td><td><?= $fmt($r['billed']) ?></td>
      <td style="color:var(--accent);font-weight:700;"><?= $fmt($mg) ?></td><td><?= $r['meta']>0 ? round($mg/$r['meta']*100,1).'%' : '—' ?></td><td>₹<?= number_format((float)$r['balance'],2) ?></td></tr>
  <?php endforeach; if (!$byUser): ?><tr><td colspan="7" style="text-align:center;color:var(--muted);padding:24px;">No billed messages in this period</td></tr><?php endif; ?>
  <?php if ($byUser): ?><tr style="font-weight:800;border-top:2px solid var(--border);"><td>TOTAL</td><td><?= number_format((int)$tot['msgs']) ?></td><td><?= $fmt($meta) ?></td><td><?= $fmt($billed) ?></td><td><?= $fmt($margin) ?></td><td><?= $mpct ?>%</td><td></td></tr><?php endif; ?>
  </tbody></table></div></div>

<div class="card"><div class="card-header"><div class="card-title">📅 Day by day (IST)</div></div>
  <div class="table-wrap"><table><thead><tr><th>Date</th><th>Msgs</th><th>Meta cost</th><th>Charged</th><th>Margin</th><th style="width:30%"></th></tr></thead><tbody>
  <?php $maxM = max(array_map(fn($r) => (float)$r['billed'], $daily) ?: [1]); foreach ($daily as $r): $mg=(float)$r['billed']-(float)$r['meta']; ?>
    <tr><td><?= e(date('D d M', strtotime($r['d']))) ?></td><td><?= number_format((int)$r['msgs']) ?></td><td><?= $fmt($r['meta']) ?></td><td><?= $fmt($r['billed']) ?></td><td style="color:var(--accent);font-weight:700;"><?= $fmt($mg) ?></td>
      <td><div style="background:var(--border);border-radius:4px;height:8px;"><div style="background:var(--accent);height:8px;border-radius:4px;width:<?= $maxM>0 ? max(2, round(max(0,(float)$r['billed'])/$maxM*100)) : 2 ?>%"></div></div></td></tr>
  <?php endforeach; if (!$daily): ?><tr><td colspan="6" style="text-align:center;color:var(--muted);padding:20px;">—</td></tr><?php endif; ?>
  </tbody></table></div></div>
<?php include __DIR__ . '/../includes/user_footer.php'; ?>
