<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
requireLogin();
if (currentUser()['role'] !== 'superadmin') { header('Location: /user/dashboard.php'); exit; }
$pageTitle = 'Plans Manager';
$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';
    if ($action === 'save_plan') {
        $id   = (int)($_POST['plan_id']??0);
        $name = sanitize($_POST['name']??'');
        $slug = strtolower(preg_replace('/[^a-z0-9_]/','',sanitize($_POST['slug']??'')));
        $price= max(0, (float)($_POST['price']??0));
        $dur  = max(1, min(3660, (int)($_POST['duration_days']??30)));
        $pub  = isset($_POST['is_public']) ? 1 : 0;
        $act  = isset($_POST['is_active']) ? 1 : 0;
        $mc   = (int)($_POST['max_contacts']??500);
        $mm   = (int)($_POST['max_messages']??1000);
        $mt   = (int)($_POST['max_team']??1);
        $cb   = isset($_POST['chatbot'])?1:0;
        $aw   = isset($_POST['automation'])?1:0;
        $aa   = isset($_POST['analytics'])?1:0;
        $api  = isset($_POST['api_access'])?1:0;
        if(!$name||!$slug){$err='Name and slug required.';}
        elseif($id){
            db()->run('UPDATE plans SET name=?,slug=?,price=?,duration_days=?,is_public=?,is_active=?,max_contacts=?,max_messages=?,max_team_members=?,chatbot_builder=?,automation_workflows=?,advanced_analytics=?,api_access=? WHERE id=?',
                [$name,$slug,$price,$dur,$pub,$act,$mc,$mm,$mt,$cb,$aw,$aa,$api,$id]);
            $msg='Plan updated.';
        } else {
            db()->insert('INSERT INTO plans (name,slug,price,duration_days,is_public,is_active,max_contacts,max_messages,max_team_members,chatbot_builder,automation_workflows,advanced_analytics,api_access) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)',
                [$name,$slug,$price,$dur,$pub,$act,$mc,$mm,$mt,$cb,$aw,$aa,$api]);
            $msg='Plan created.';
        }
    }
    if ($action==='delete_plan'){
        $id=(int)$_POST['plan_id'];
        $inUse=db()->count('SELECT COUNT(*) FROM users WHERE plan_id=?',[$id])
              +db()->count('SELECT COUNT(*) FROM subscriptions WHERE plan_id=?',[$id])+db()->count('SELECT COUNT(*) FROM payments WHERE plan_id=?',[$id]);
        if($inUse>0){$err="Cannot delete — this plan is used by users / history. Untick Active instead to retire it.";}
        else{db()->run('DELETE FROM plans WHERE id=?',[$id]);$msg='Plan deleted.';}
    }
}

$plans = db()->all('SELECT p.*,(SELECT COUNT(DISTINCT user_id) FROM subscriptions s WHERE s.plan_id=p.id AND s.status=\'active\' AND (s.expires_at IS NULL OR s.expires_at>NOW())) as user_count FROM plans p ORDER BY is_active DESC, price ASC');
include __DIR__ . '/../includes/user_header.php';
?>
<?php if($msg):?><div class="alert alert-success">✓ <?=e($msg)?></div><?php endif;?>
<?php if($err):?><div class="alert alert-danger">⚠ <?=e($err)?></div><?php endif;?>

<div style="display:flex;justify-content:flex-end;margin-bottom:18px;">
  <button class="btn btn-primary" onclick="openModal('planModal');resetForm()">+ New Plan</button>
</div>

<div class="g2 mb2">
<?php foreach($plans as $p):
  $color = match($p['slug']??''){
    'free'=>'var(--accent)','pro'=>'var(--blue)',
    'growth'=>'var(--gold)','enterprise','ultimate'=>'var(--purple)',
    default=>'var(--accent)'
  };
?>
<div class="card" style="border-top:3px solid <?=$color?>;">
  <div class="card-header">
    <div>
      <div class="card-title"><?=e($p['name'])?></div>
      <div style="font-size:11px;color:var(--muted);margin-top:2px;">slug: <?=e($p['slug'])?></div>
    </div>
    <span class="badge badge-green"><?=$p['user_count']?> users</span>
  </div>
  <div class="card-body">
    <div style="font-family:'Instrument Serif',serif;font-size:2rem;font-style:italic;color:#fff;margin-bottom:14px;">
      ₹<?=number_format($p['price'])?><small style="font-size:13px;color:var(--text2);font-style:normal;"> / <?=(int)$p['duration_days']?> days</small>
      <?php if(!$p['is_active']):?><span class="badge badge-red" style="font-size:10px;">Retired</span><?php elseif(!$p['is_public']):?><span class="badge badge-orange" style="font-size:10px;">Admin-activated only</span><?php endif;?>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;font-size:12px;margin-bottom:14px;">
      <div style="color:var(--text2);">👥 Contacts: <strong style="color:#fff;"><?=$p['max_contacts']==-1?'∞':formatNumber($p['max_contacts'])?></strong></div>
      <div style="color:var(--text2);">💬 Messages: <strong style="color:#fff;"><?=$p['max_messages']==-1?'∞':formatNumber($p['max_messages'])?></strong></div>
      <div style="color:var(--text2);">👤 Team: <strong style="color:#fff;"><?=$p['max_team_members']==-1?'∞':$p['max_team_members']?></strong></div>
    </div>
    <div style="display:flex;flex-wrap:wrap;gap:5px;margin-bottom:14px;">
      <?php if($p['chatbot_builder']):?><span class="badge badge-green">🤖 Chatbot</span><?php endif;?>
      <?php if($p['automation_workflows']):?><span class="badge badge-blue">⚡ Automation</span><?php endif;?>
      <?php if($p['advanced_analytics']):?><span class="badge badge-gold">📊 Analytics</span><?php endif;?>
      <?php if($p['api_access']):?><span class="badge badge-purple">🔑 API</span><?php endif;?>
    </div>
    <div style="display:flex;gap:8px;">
      <button class="btn btn-secondary btn-sm" onclick='editPlan(<?=json_encode($p)?>)'>✏ Edit</button>
      <?php if($p['user_count']==0 && $p['slug']!=='trial' && $p['slug']!=='basic' && $p['slug']!=='platform'):?>
      <form method="POST" onsubmit="return confirm('Delete?')" style="display:inline;">
        <?=csrfField()?><input type="hidden" name="action" value="delete_plan"><input type="hidden" name="plan_id" value="<?=$p['id']?>">
        <button type="submit" class="btn btn-danger btn-sm">🗑</button>
      </form>
      <?php endif;?>
    </div>
  </div>
</div>
<?php endforeach;?>
</div>

<div class="modal-overlay" id="planModal">
  <div class="modal">
    <div class="modal-title" id="pmTitle">New Plan</div>
    <div class="modal-sub">Configure plan features and limits</div>
    <form method="POST">
      <?=csrfField()?><input type="hidden" name="action" value="save_plan"><input type="hidden" name="plan_id" id="pmId" value="0">
      <div class="g2">
        <div class="form-group"><label class="form-label">Name *</label><input type="text" name="name" id="pmName" class="form-control" required></div>
        <div class="form-group"><label class="form-label">Slug *</label><input type="text" name="slug" id="pmSlug" class="form-control" required></div>
      </div>
      <div class="g2">
        <div class="form-group"><label class="form-label">Price ₹ (0 = trial)</label><input type="number" name="price" id="pmPrice" class="form-control" value="0" min="0" step="1"></div>
        <div class="form-group"><label class="form-label">Valid for (days)</label><input type="number" name="duration_days" id="pmDur" class="form-control" value="30" min="1" max="3660"></div>
      </div>
      <div style="display:flex;gap:18px;margin-bottom:14px;flex-wrap:wrap;">
        <label style="font-size:13px;color:var(--text2);"><input type="checkbox" name="is_public" id="pmPub" style="accent-color:var(--accent)"> Users can buy it from wallet</label>
        <label style="font-size:13px;color:var(--text2);"><input type="checkbox" name="is_active" id="pmAct" style="accent-color:var(--accent)"> Active</label>
      </div>
      <div class="g2">
        <div class="form-group"><label class="form-label">Max Contacts (-1=∞)</label><input type="number" name="max_contacts" id="pmMc" class="form-control" value="500"></div>
        <div class="form-group"><label class="form-label">Max Messages (-1=∞)</label><input type="number" name="max_messages" id="pmMm" class="form-control" value="1000"></div>
      </div>
      <div class="form-group"><label class="form-label">Max Team Members (-1=∞)</label><input type="number" name="max_team" id="pmMt" class="form-control" value="1"></div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:18px;">
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text2);cursor:pointer;"><input type="checkbox" name="chatbot" id="pmCb" style="accent-color:var(--accent)"> 🤖 Chatbot Builder</label>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text2);cursor:pointer;"><input type="checkbox" name="automation" id="pmAw" style="accent-color:var(--accent)"> ⚡ Automation</label>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text2);cursor:pointer;"><input type="checkbox" name="analytics" id="pmAa" style="accent-color:var(--accent)"> 📊 Advanced Analytics</label>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text2);cursor:pointer;"><input type="checkbox" name="api_access" id="pmApi" style="accent-color:var(--accent)"> 🔑 API Access</label>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('planModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Plan</button>
      </div>
    </form>
  </div>
</div>
<script>
function resetForm(){
  document.getElementById('pmTitle').textContent='New Plan';
  ['pmId','pmName','pmSlug','pmPrice'].forEach(id=>document.getElementById(id).value='');
  document.getElementById('pmId').value='0';
  document.getElementById('pmMc').value='500';
  document.getElementById('pmMm').value='-1';
  document.getElementById('pmMt').value='1';
  document.getElementById('pmDur').value='30';
  document.getElementById('pmPub').checked=true; document.getElementById('pmAct').checked=true;
  ['pmCb','pmAw','pmAa','pmApi'].forEach(id=>document.getElementById(id).checked=false);
}
function editPlan(p){
  document.getElementById('pmTitle').textContent='Edit Plan';
  document.getElementById('pmId').value=p.id;
  document.getElementById('pmName').value=p.name;
  document.getElementById('pmSlug').value=p.slug;
  document.getElementById('pmPrice').value=p.price;
  document.getElementById('pmDur').value=p.duration_days;
  document.getElementById('pmPub').checked=p.is_public==1; document.getElementById('pmAct').checked=p.is_active==1;
  document.getElementById('pmMc').value=p.max_contacts;
  document.getElementById('pmMm').value=p.max_messages;
  document.getElementById('pmMt').value=p.max_team_members;
  document.getElementById('pmCb').checked=p.chatbot_builder==1;
  document.getElementById('pmAw').checked=p.automation_workflows==1;
  document.getElementById('pmAa').checked=p.advanced_analytics==1;
  document.getElementById('pmApi').checked=p.api_access==1;
  openModal('planModal');
}
document.getElementById('pmName').addEventListener('input',function(){
  if(document.getElementById('pmId').value=='0')
    document.getElementById('pmSlug').value=this.value.toLowerCase().replace(/[^a-z0-9]+/g,'_');
});
</script>
<?php include __DIR__ . '/../includes/user_footer.php';?>
