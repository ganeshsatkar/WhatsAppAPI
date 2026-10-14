<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
if (currentUser()['role'] !== 'superadmin') { header('Location: /user/dashboard.php'); exit; }
$pageTitle = 'Activity Logs';

$page   = max(1,(int)($_GET['page']??1));
$limit  = 30; $offset = ($page-1)*$limit;
$filter = sanitize($_GET['action']??'');

$where  = ''; $params = [];
if ($filter) { $where = 'WHERE al.action LIKE ?'; $params = ["%$filter%"]; }

$total = db()->count("SELECT COUNT(*) FROM activity_logs al $where", $params);
$logs  = db()->all("SELECT al.*,u.name as user_name,u.email FROM activity_logs al
    LEFT JOIN users u ON u.id=al.user_id
    $where ORDER BY al.created_at DESC LIMIT $limit OFFSET $offset", $params);
$totalPages = max(1,ceil($total/$limit));

$actionTypes = db()->all("SELECT DISTINCT action FROM activity_logs ORDER BY action ASC LIMIT 30");

include __DIR__ . '/../includes/user_header.php';
?>
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;flex-wrap:wrap;gap:10px;">
  <div style="font-size:13px;color:var(--text2);"><?=$total?> log entries</div>
  <form method="GET" style="display:flex;gap:8px;">
    <select name="action" class="form-control" style="width:200px;">
      <option value="">All actions</option>
      <?php foreach($actionTypes as $at):?>
      <option value="<?=e($at['action'])?>" <?=$filter===$at['action']?'selected':''?>><?=e($at['action'])?></option>
      <?php endforeach;?>
    </select>
    <button type="submit" class="btn btn-secondary btn-sm">Filter</button>
    <?php if($filter):?><a href="?" class="btn btn-secondary btn-sm">✕ Clear</a><?php endif;?>
  </form>
</div>

<div class="card mb2">
  <div class="table-wrap">
    <table>
      <thead><tr><th>User</th><th>Action</th><th>Description</th><th>IP Address</th><th>Time</th></tr></thead>
      <tbody>
        <?php if(empty($logs)):?>
        <tr><td colspan="5" style="text-align:center;padding:40px;color:var(--muted);">No logs found.</td></tr>
        <?php endif;?>
        <?php foreach($logs as $log):
          $icon = match(true){
            str_contains($log['action'],'login')     => '🔑',
            str_contains($log['action'],'register')  => '✅',
            str_contains($log['action'],'logout')    => '⏏',
            str_contains($log['action'],'delete')    => '🗑',
            str_contains($log['action'],'campaign')  => '📢',
            str_contains($log['action'],'contact')   => '👥',
            str_contains($log['action'],'password')  => '🔐',
            default => '📝'
          };
        ?>
        <tr>
          <td>
            <?php if($log['user_name']):?>
            <div class="td-bold"><?=e($log['user_name'])?></div>
            <div style="font-size:11px;color:var(--muted);"><?=e($log['email']??'')?></div>
            <?php else:?>
            <span style="color:var(--muted);">Guest</span>
            <?php endif;?>
          </td>
          <td>
            <div style="display:flex;align-items:center;gap:7px;">
              <span><?=$icon?></span>
              <span class="badge badge-gray"><?=e($log['action'])?></span>
            </div>
          </td>
          <td style="max-width:280px;font-size:12px;color:var(--text2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?=e($log['description']??'—')?></td>
          <td style="font-family:monospace;font-size:12px;color:var(--muted);"><?=e($log['ip_address']??'—')?></td>
          <td style="font-size:12px;color:var(--muted);white-space:nowrap;"><?=timeAgo($log['created_at'])?><br><span style="font-size:10px;"><?=date('M j H:i',strtotime($log['created_at']))?></span></td>
        </tr>
        <?php endforeach;?>
      </tbody>
    </table>
  </div>
  <?php if($totalPages>1):?>
  <div style="display:flex;justify-content:space-between;align-items:center;padding:12px 20px;border-top:1px solid var(--border);">
    <div style="font-size:12px;color:var(--muted);">Page <?=$page?> of <?=$totalPages?></div>
    <div style="display:flex;gap:6px;">
      <?php if($page>1):?><a href="?page=<?=$page-1?>&action=<?=urlencode($filter)?>" class="btn btn-secondary btn-sm">← Prev</a><?php endif;?>
      <?php if($page<$totalPages):?><a href="?page=<?=$page+1?>&action=<?=urlencode($filter)?>" class="btn btn-secondary btn-sm">Next →</a><?php endif;?>
    </div>
  </div>
  <?php endif;?>
</div>
<?php include __DIR__ . '/../includes/user_footer.php';?>
