<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
if (currentUser()['role'] !== 'superadmin') { header('Location: /user/dashboard.php'); exit; }
$pageTitle = 'Subscriptions';
$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    $sub = db()->row('SELECT * FROM subscriptions WHERE id=?', [(int)($_POST['sub_id'] ?? 0)]);
    if (!$sub) { $err = 'Subscription not found.'; }
    elseif ($action === 'extend') {
        [$ok, $m] = activatePlan((int)$sub['user_id'], (int)$sub['plan_id'], (int)($_POST['days'] ?? 0), ['source' => 'admin_free', 'by' => (int)currentUser()['id'], 'allow_repeat_trial' => true, 'note' => 'extended from Subscriptions']);
        if ($ok) $msg = $m; else $err = $m;
    } elseif ($action === 'cancel') {
        $msg = cancelSubscription((int)$sub['user_id']) ? 'Plan ended.' : 'No active plan to end.';
    }
}

$filter = $_GET['f'] ?? 'all';
$where = match ($filter) {
    'active'  => "WHERE s.status='active' AND (s.expires_at IS NULL OR s.expires_at > NOW())",
    'expiring'=> "WHERE s.status='active' AND s.expires_at > NOW() AND s.expires_at <= DATE_ADD(NOW(), INTERVAL 7 DAY)",
    'ended'   => "WHERE NOT (s.status='active' AND (s.expires_at IS NULL OR s.expires_at > NOW()))",
    default   => ''
};
$subs = db()->all("SELECT s.*,u.name as user_name,u.email,p.name as plan_name,p.slug,p.price,
      (s.status='active' AND (s.expires_at IS NULL OR s.expires_at > NOW())) AS is_live,
      CEIL(TIMESTAMPDIFF(SECOND, NOW(), s.expires_at)/86400) AS days_left
    FROM subscriptions s JOIN users u ON u.id=s.user_id JOIN plans p ON p.id=s.plan_id
    $where ORDER BY s.id DESC LIMIT 200");
$noPlan = db()->all("SELECT u.id,u.name,u.email,u.created_at FROM users u WHERE u.role='user' AND NOT EXISTS (SELECT 1 FROM subscriptions s WHERE s.user_id=u.id AND s.status='active' AND (s.expires_at IS NULL OR s.expires_at > NOW())) ORDER BY u.created_at DESC LIMIT 50");

include __DIR__ . '/../includes/user_header.php';
?>
<?php if($msg):?><div class="alert alert-success">✓ <?=e($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger">⚠ <?=e($err)?></div><?php endif;?>

<div style="display:flex;gap:8px;margin-bottom:14px;flex-wrap:wrap;">
  <?php foreach(['all'=>'All','active'=>'Active','expiring'=>'Expiring in 7 days','ended'=>'Ended / expired'] as $k=>$v):?>
  <a class="btn <?=$filter===$k?'btn-primary':'btn-secondary'?> btn-sm" href="?f=<?=$k?>"><?=$v?></a><?php endforeach;?>
</div>

<?php if($noPlan && $filter==='all'):?>
<div class="card mb2" style="border-left:3px solid var(--danger);">
  <div class="card-header"><div class="card-title">🔒 Users without an active plan</div><span class="badge badge-red"><?=count($noPlan)?></span></div>
  <div class="card-body" style="font-size:13px;color:var(--text2);">
    <?php foreach($noPlan as $n):?><div style="padding:3px 0;"><strong><?=e($n['name'])?></strong> · <?=e($n['email'])?> · joined <?=e(fmtIst($n['created_at'],'d M'))?> — <a href="/user/admin_users.php?q=<?=urlencode($n['email'])?>">activate →</a></div><?php endforeach;?>
  </div>
</div>
<?php endif;?>

<div class="card mb2">
  <div class="card-header"><div class="card-title">Subscriptions</div><span class="badge badge-green"><?=count($subs)?></span></div>
  <div class="table-wrap">
    <table>
      <thead><tr><th>User</th><th>Plan</th><th>Price</th><th>Status</th><th>Started</th><th>Expires</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if(empty($subs)):?><tr><td colspan="7" style="text-align:center;padding:40px;color:var(--muted);">No subscriptions.</td></tr><?php endif;?>
        <?php foreach($subs as $s):
          $live=(bool)$s['is_live'];
          $planColor=((float)$s['price']<=0)?'badge-gold':(($s['slug']==='platform')?'badge-purple':'badge-blue');
        ?>
        <tr>
          <td><div class="td-bold"><?=e($s['user_name'])?></div><div style="font-size:11px;color:var(--muted);"><?=e($s['email'])?></div></td>
          <td><span class="badge <?=$planColor?>"><?=e($s['plan_name'])?></span></td>
          <td class="td-bold">₹<?=number_format($s['price'])?></td>
          <td><?php if($live):?><span class="badge badge-green">Active</span><?php if($s['days_left']!==null):?><div style="font-size:11px;color:var(--muted);"><?=(int)$s['days_left']?> day(s) left</div><?php endif;?>
              <?php else:?><span class="badge <?=$s['status']==='cancelled'?'badge-orange':'badge-red'?>"><?=$s['status']==='cancelled'?'Cancelled':'Expired'?></span><?php endif;?></td>
          <td style="font-size:12px;color:var(--muted);"><?=e(fmtIst($s['started_at'],'d M Y'))?></td>
          <td style="font-size:12px;color:var(--muted);"><?=$s['expires_at']?e(fmtIst($s['expires_at'],'d M Y, h:i A')):'No expiry'?></td>
          <td>
            <?php if($live):?>
            <div style="display:flex;gap:6px;">
              <form method="POST" style="display:flex;gap:4px;"><?=csrfField()?><input type="hidden" name="action" value="extend"><input type="hidden" name="sub_id" value="<?=$s['id']?>">
                <input type="number" name="days" value="7" min="1" max="3660" class="form-control" style="width:64px;padding:4px 6px;"><button class="btn btn-secondary btn-sm">+ days</button></form>
              <form method="POST" onsubmit="return confirm('End this plan now?')"><?=csrfField()?><input type="hidden" name="action" value="cancel"><input type="hidden" name="sub_id" value="<?=$s['id']?>"><button class="btn btn-danger btn-sm">End</button></form>
            </div>
            <?php else:?><span style="font-size:11px;color:var(--muted);">Renew from All Users</span><?php endif;?>
          </td>
        </tr>
        <?php endforeach;?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/../includes/user_footer.php';?>
