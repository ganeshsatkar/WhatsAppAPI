<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
if (currentUser()['role'] !== 'superadmin') { header('Location: /user/dashboard.php'); exit; }
$pageTitle = 'All Users';
$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'toggle_user') {
        $id = (int)$_POST['user_id'];
        $curr = db()->row('SELECT is_active FROM users WHERE id=?',[$id])['is_active'] ?? 0;
        db()->run('UPDATE users SET is_active=? WHERE id=?', [$curr?0:1, $id]);
        $msg = $curr ? 'User suspended.' : 'User activated.';
    }
    if ($action === 'activate_plan') {
        $id = (int)$_POST['user_id']; $planId = (int)$_POST['plan_id']; $days = (int)$_POST['days'];
        $paid = ($_POST['pay_kind'] ?? 'free') === 'paid';
        $amount = $paid ? round((float)($_POST['amount'] ?? 0), 2) : 0;
        $ref = $paid ? sanitize(($_POST['pay_mode'] ?? 'upi') . ' ' . ($_POST['pay_reference'] ?? '')) : '';
        [$ok, $m] = activatePlan($id, $planId, $days, ['source' => $paid ? 'admin_paid' : 'admin_free', 'amount' => $amount, 'reference' => trim($ref),
            'note' => sanitize($_POST['note'] ?? ''), 'by' => (int)currentUser()['id'], 'allow_repeat_trial' => isset($_POST['allow_repeat_trial'])]);
        if ($ok) $msg = $m; else $err = $m;
    }
    if ($action === 'cancel_plan') {
        $id = (int)$_POST['user_id'];
        $msg = cancelSubscription($id) ? 'Plan ended — the user is now locked until a plan is activated.' : 'That user has no active plan.';
    }
    if ($action === 'delete_user') {
        $id = (int)$_POST['user_id'];
        db()->run("DELETE FROM users WHERE id=? AND role!='superadmin'", [$id]);
        $msg = 'User deleted.';
    }
    if ($action === 'impersonate') {
        $targetId = (int)$_POST['user_id'];
        $target = db()->row('SELECT * FROM users WHERE id = ?', [$targetId]);
        if ($target) {
            $_SESSION['superadmin_orig_id'] = $_SESSION['user_id'];
            $_SESSION['user_id'] = $target['id'];
            $_SESSION['user_role'] = $target['role'];
            $_SESSION['user_name'] = $target['name'];
            header('Location: /user/inbox.php');
            exit;
        }
    }
}

$search = sanitize($_GET['q'] ?? '');
$page   = max(1,(int)($_GET['page']??1));
$limit  = 20; $offset = ($page-1)*$limit;
$where  = "WHERE u.role != 'superadmin'";
$params = [];
if ($search) { $where .= ' AND (u.name LIKE ? OR u.email LIKE ?)'; $s="%$search%"; $params=[$s,$s]; }
$total = db()->count("SELECT COUNT(*) FROM users u $where", $params);
$users = db()->all("SELECT u.*,up.company_name,p.name as plan_name,p.slug as plan_slug,
    (SELECT COUNT(*) FROM contacts WHERE user_id=u.id) as contacts,
    (SELECT COUNT(*) FROM campaigns WHERE user_id=u.id) as campaigns
    FROM users u LEFT JOIN user_profiles up ON up.user_id=u.id LEFT JOIN plans p ON p.id=u.plan_id
    $where ORDER BY u.created_at DESC LIMIT $limit OFFSET $offset", $params);
$plans = db()->all('SELECT * FROM plans WHERE is_active=1 ORDER BY price ASC');
$totalPages = max(1, ceil($total/$limit));

include __DIR__ . '/../includes/user_header.php';
?>
<?php if($msg):?><div class="alert alert-success">✓ <?=e($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger">⚠ <?=e($err)?></div><?php endif;?>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px;">
  <div style="font-size:13px;color:var(--text2);"><?=$total?> total users</div>
  <form method="GET" style="display:flex;gap:8px;">
    <div class="search-box"><span>🔍</span><input type="search" name="q" placeholder="Search users…" value="<?=e($search)?>"></div>
    <button type="submit" class="btn btn-secondary btn-sm">Search</button>
  </form>
</div>

<div class="card mb2">
  <div class="table-wrap">
    <table>
      <thead><tr><th>User</th><th>Company</th><th>Plan</th><th>Contacts</th><th>Campaigns</th><th>Status</th><th>Joined</th><th>Actions</th></tr></thead>
      <tbody>
        <?php if(empty($users)):?>
        <tr><td colspan="8" style="text-align:center;padding:40px;color:var(--muted);">No users found.</td></tr>
        <?php endif;?>
        <?php foreach($users as $u):
          $ss = subscriptionState((int)$u['id']);
          $sc = !$ss['active'] ? 'badge-red' : ($ss['is_trial'] ? 'badge-gold' : 'badge-green');
        ?>
        <tr>
          <td><div style="display:flex;align-items:center;gap:9px;">
            <div style="width:30px;height:30px;border-radius:8px;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:800;color:#000;"><?=strtoupper(substr($u['name'],0,2))?></div>
            <div><div class="td-bold"><?=e($u['name'])?></div><div style="font-size:11px;color:var(--muted);"><?=e($u['email'])?></div></div>
          </div></td>
          <td><?=e($u['company_name']??'—')?></td>
          <td><span class="badge <?=$sc?>"><?= $ss['active'] ? e($ss['plan']['name']) : 'Not active' ?></span>
            <div style="font-size:11px;color:var(--muted);margin-top:3px;"><?php if($ss['active']): ?><?= $ss['days_left']===null ? 'No expiry' : (int)$ss['days_left'].' day(s) left · '.e(fmtIst($ss['expires_at'],'d M')) ?><?php else: ?><?= $ss['last'] ? 'Ended '.e(fmtIst($ss['expires_at'],'d M Y')) : 'Never activated' ?><?php endif; ?></div></td>
          <td><?=$u['contacts']?></td>
          <td><?=$u['campaigns']?></td>
          <td><span class="badge <?=$u['is_active']?'badge-green':'badge-red'?>"><?=$u['is_active']?'● Active':'● Suspended'?></span></td>
          <td style="font-size:11px;color:var(--muted);"><?=date('M j, Y',strtotime($u['created_at']))?></td>
          <td>
            <div style="display:flex;gap:5px;flex-wrap:wrap;">
              <form method="POST" style="display:inline;">
                <?=csrfField()?><input type="hidden" name="action" value="impersonate"><input type="hidden" name="user_id" value="<?=$u['id']?>">
                <button type="submit" class="btn btn-primary btn-sm" title="View portal as this user (see all inboxes, incoming chats, and reports)">👁️ View Portal</button>
              </form>
              <button class="btn btn-secondary btn-sm" onclick="openPlanModal(<?=$u['id']?>,'<?=e(addslashes($u['name']))?>')">⬆ Plan</button>
              <?php if($ss['active']): ?><form method="POST" style="display:inline;" onsubmit="return confirm('End this user\'s plan now? They will be locked out.')"><?=csrfField()?><input type="hidden" name="action" value="cancel_plan"><input type="hidden" name="user_id" value="<?=$u['id']?>"><button type="submit" class="btn btn-secondary btn-sm" title="End plan now">⛔</button></form><?php endif; ?>
              <form method="POST" style="display:inline;">
                <?=csrfField()?><input type="hidden" name="action" value="toggle_user"><input type="hidden" name="user_id" value="<?=$u['id']?>">
                <button type="submit" class="btn btn-secondary btn-sm"><?=$u['is_active']?'⏸':'▶'?></button>
              </form>
              <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this user?')">
                <?=csrfField()?><input type="hidden" name="action" value="delete_user"><input type="hidden" name="user_id" value="<?=$u['id']?>">
                <button type="submit" class="btn btn-danger btn-sm">🗑</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach;?>
      </tbody>
    </table>
  </div>
  <?php if($totalPages>1):?>
  <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 20px;border-top:1px solid var(--border);">
    <div style="font-size:12px;color:var(--muted);">Page <?=$page?> of <?=$totalPages?></div>
    <div style="display:flex;gap:6px;">
      <?php if($page>1):?><a href="?page=<?=$page-1?>&q=<?=urlencode($search)?>" class="btn btn-secondary btn-sm">← Prev</a><?php endif;?>
      <?php if($page<$totalPages):?><a href="?page=<?=$page+1?>&q=<?=urlencode($search)?>" class="btn btn-secondary btn-sm">Next →</a><?php endif;?>
    </div>
  </div>
  <?php endif;?>
</div>

<!-- Activate plan modal -->
<div class="modal-overlay" id="planModal">
  <div class="modal">
    <div class="modal-title">Activate / extend plan</div>
    <div class="modal-sub" id="pmUserName"></div>
    <form method="POST">
      <?=csrfField()?><input type="hidden" name="action" value="activate_plan">
      <input type="hidden" name="user_id" id="pmUserId" value="">
      <div class="g2">
        <div class="form-group">
          <label class="form-label">Plan</label>
          <select name="plan_id" id="pmPlanId" class="form-control" onchange="pmSync()">
            <?php foreach($plans as $p):?>
            <option value="<?=$p['id']?>" data-days="<?=(int)$p['duration_days']?>" data-price="<?=(float)$p['price']?>"><?=e($p['name'])?> — ₹<?=number_format($p['price'])?> / <?=(int)$p['duration_days']?>d</option>
            <?php endforeach;?>
          </select>
        </div>
        <div class="form-group"><label class="form-label">Days (trial: 1–7)</label><input type="number" name="days" id="pmDays" class="form-control" min="1" max="3660" value="30" required></div>
      </div>
      <div class="form-group">
        <label class="form-label">Payment</label>
        <select name="pay_kind" id="pmKind" class="form-control" onchange="pmSync()">
          <option value="free">🎁 Free (trial / gift / already settled)</option>
          <option value="paid">💵 Paid to you offline (UPI / bank / cash) — record as income</option>
        </select>
      </div>
      <div id="pmPaidRow" style="display:none;">
        <div class="g2">
          <div class="form-group"><label class="form-label">Amount received ₹</label><input type="number" name="amount" id="pmAmount" class="form-control" min="0" step="0.01"></div>
          <div class="form-group"><label class="form-label">Via</label><select name="pay_mode" class="form-control"><option value="upi">UPI</option><option value="bank">Bank</option><option value="cash">Cash</option><option value="cheque">Cheque</option></select></div>
        </div>
        <div class="form-group"><label class="form-label">UTR / receipt no.</label><input type="text" name="pay_reference" class="form-control" maxlength="80"></div>
      </div>
      <div class="form-group"><label class="form-label">Note (optional)</label><input type="text" name="note" class="form-control" maxlength="150"></div>
      <label style="font-size:12px;color:var(--text2);display:block;margin-bottom:6px;"><input type="checkbox" name="allow_repeat_trial" style="accent-color:var(--accent)"> Allow a repeat trial for this user</label>
      <p style="font-size:11.5px;color:var(--muted);">Same plan still running → days are added to its end date. Different plan → the new plan starts now and the old one ends now.</p>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('planModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Activate</button>
      </div>
    </form>
  </div>
</div>
<script>
function pmSync(){
  var o=document.getElementById('pmPlanId').selectedOptions[0];
  document.getElementById('pmDays').value=o.dataset.days;
  var paid=document.getElementById('pmKind').value==='paid';
  document.getElementById('pmPaidRow').style.display=paid?'block':'none';
  if(paid) document.getElementById('pmAmount').value=o.dataset.price;
}
function openPlanModal(uid,name){
  document.getElementById('pmUserId').value=uid;
  document.getElementById('pmUserName').textContent='For: '+name;
  pmSync(); openModal('planModal');
}
</script>
<?php include __DIR__ . '/../includes/user_footer.php';?>
