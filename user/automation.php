<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();
$pageTitle = 'Automation';
requireFeature('automation_workflows', 'Automation');
$uid = currentUser()['id'];
$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_rule') {
        $name         = sanitize($_POST['name'] ?? '');
        $trigger      = sanitize($_POST['trigger_event'] ?? 'new_contact');
        $delayMin     = (int)($_POST['delay_minutes'] ?? 0);
        $runOnce      = isset($_POST['run_once']) ? 1 : 0;

        // Build actions array
        $actions = [];
        $actionTypes = (array)($_POST['action_type'] ?? []);
        $actionMsgs  = (array)($_POST['action_message'] ?? []);
        $actionTags  = (array)($_POST['action_tag'] ?? []);
        $actionTpls  = (array)($_POST['action_template'] ?? []);
        foreach ($actionTypes as $i => $type) {
            $act = ['type' => $type];
            if ($type === 'send_message') $act['message'] = trim((string)($actionMsgs[$i] ?? ''));
            if ($type === 'add_tag')      $act['tag']     = mb_substr(sanitize($actionTags[$i] ?? ''), 0, 100);
            if ($type === 'send_template') {
                $tpl = db()->row("SELECT id, template_name, language FROM message_templates WHERE id=? AND user_id=? AND status='approved'", [(int)($actionTpls[$i] ?? 0), $uid]);
                if (!$tpl) continue;
                $act += ['template_id' => (int)$tpl['id'], 'template_name' => $tpl['template_name'], 'language' => $tpl['language']];
            }
            if (($type === 'send_message' && $act['message'] === '') || ($type === 'add_tag' && $act['tag'] === '')) continue;
            if (!in_array($type, ['send_message', 'add_tag', 'send_template'], true)) continue;
            $actions[] = $act;
        }
        $trigger = in_array($trigger, ['new_contact', 'message_received', 'contact_tagged', 'campaign_sent'], true) ? $trigger : 'new_contact';
        $delayMin = max(0, min(43200, $delayMin));

        if (!$name || empty($actions)) { $err = 'Name and at least one complete action are required (Send Template needs an approved template).'; }
        else {
            db()->insert('INSERT INTO automation_rules (user_id, name, trigger_event, actions, delay_minutes, run_once, is_active) VALUES (?,?,?,?,?,?,1)',
                [$uid, $name, $trigger, json_encode($actions), $delayMin, $runOnce]);
            $msg = 'Automation rule created.';
        }
    }

    if ($action === 'toggle_rule') {
        $id   = (int)$_POST['rule_id'];
        $curr = db()->row('SELECT is_active FROM automation_rules WHERE id = ? AND user_id = ?', [$id, $uid])['is_active'] ?? 0;
        db()->run('UPDATE automation_rules SET is_active = ? WHERE id = ? AND user_id = ?', [$curr ? 0 : 1, $id, $uid]);
        $msg = 'Rule ' . ($curr ? 'disabled.' : 'enabled.');
    }

    if ($action === 'delete_rule') {
        $id = (int)$_POST['rule_id'];
        db()->run('DELETE FROM automation_rules WHERE id = ? AND user_id = ?', [$id, $uid]);
        $msg = 'Rule deleted.';
    }
}

$rules = db()->all('SELECT ar.*, COUNT(al.id) as total_runs FROM automation_rules ar LEFT JOIN automation_logs al ON al.rule_id = ar.id WHERE ar.user_id = ? GROUP BY ar.id ORDER BY ar.created_at DESC', [$uid]);

$triggerLabels = [
    'new_contact'    => '🆕 New Contact Added',
    'contact_tagged' => '🏷 Contact Tagged',
    'message_received' => '💬 Message Received',
    'campaign_sent'  => '📢 Campaign Sent',
    'scheduled'      => '⏰ Scheduled',
];

include __DIR__ . '/../includes/user_header.php';
?>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠ <?= e($err) ?></div><?php endif; ?>

<div style="display:flex;justify-content:flex-end;margin-bottom:20px;">
  <button class="btn btn-primary" onclick="openModal('createModal')">+ Create Automation</button>
</div>

<?php if (empty($rules)): ?>
<div class="card">
  <div class="empty-state">
    <div class="empty-icon">⚡</div>
    <div class="empty-title">No automation rules yet</div>
    <div class="empty-sub">Create rules to automatically send messages, add tags, or follow up with contacts.</div>
    <button class="btn btn-primary" onclick="openModal('createModal')">Create First Rule</button>
  </div>
</div>
<?php else: ?>

<!-- Rule examples / presets -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;margin-bottom:24px;">
  <?php
  $presets = [
    ['name'=>'Welcome Message', 'icon'=>'👋', 'desc'=>'Auto-greet new contacts', 'trigger'=>'new_contact'],
    ['name'=>'Lead Follow-up', 'icon'=>'🎯', 'desc'=>'Follow up after 24h', 'trigger'=>'new_contact'],
    ['name'=>'Order Confirmation', 'icon'=>'✅', 'desc'=>'Confirm on campaign sent', 'trigger'=>'campaign_sent'],
    ['name'=>'Re-engagement', 'icon'=>'💌', 'desc'=>'Re-engage inactive contacts', 'trigger'=>'scheduled'],
  ];
  foreach ($presets as $p): ?>
  <div style="background:var(--surface2);border:1px solid var(--border);border-radius:10px;padding:14px;cursor:pointer;" onclick="fillPreset('<?= $p['trigger'] ?>', '<?= e($p['name']) ?>')">
    <div style="font-size:24px;margin-bottom:6px;"><?= $p['icon'] ?></div>
    <div style="font-weight:600;font-size:13px;margin-bottom:3px;"><?= $p['name'] ?></div>
    <div style="font-size:12px;color:var(--muted);"><?= $p['desc'] ?></div>
  </div>
  <?php endforeach; ?>
</div>

<div style="display:flex;flex-direction:column;gap:12px;">
  <?php foreach ($rules as $rule):
    $actions = json_decode($rule['actions'], true) ?: [];
  ?>
  <div class="card" style="padding:18px;">
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;">
      <div style="flex:1;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:8px;">
          <div style="font-family:'Syne',sans-serif;font-weight:700;font-size:15px;"><?= e($rule['name']) ?></div>
          <span class="badge <?= $rule['is_active'] ? 'badge-green' : 'badge-gray' ?>"><?= $rule['is_active'] ? '● Active' : '○ Paused' ?></span>
        </div>
        <div style="font-size:13px;color:var(--text2);margin-bottom:10px;">
          <span style="background:var(--surface2);padding:4px 10px;border-radius:6px;font-size:12px;">
            Trigger: <?= $triggerLabels[$rule['trigger_event']] ?? $rule['trigger_event'] ?>
          </span>
          <?php if ($rule['delay_minutes'] > 0): ?>
            <span style="background:var(--surface2);padding:4px 10px;border-radius:6px;font-size:12px;margin-left:6px;">⏱ After <?= $rule['delay_minutes'] ?>min</span>
          <?php endif; ?>
        </div>
        <div style="display:flex;flex-direction:column;gap:5px;">
          <?php foreach ($actions as $act): ?>
          <div style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--text2);">
            <span style="color:var(--accent);">→</span>
            <?php if ($act['type'] === 'send_message'): ?>
              Send: <em style="color:var(--text);">"<?= e(substr($act['message'] ?? '', 0, 60)) ?><?= strlen($act['message'] ?? '') > 60 ? '…' : '' ?>"</em>
            <?php elseif ($act['type'] === 'add_tag'): ?>
              Add tag: <span class="badge badge-blue"><?= e($act['tag'] ?? '') ?></span>
            <?php elseif ($act['type'] === 'send_template'): ?>
              Send template: <span class="badge badge-purple"><?= e($act['template_name'] ?? '') ?></span>
            <?php else: ?>
              <?= ucfirst($act['type'] ?? '') ?>
            <?php endif; ?>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <div style="text-align:right;flex-shrink:0;">
        <div style="font-size:12px;color:var(--muted);margin-bottom:8px;"><?= $rule['total_runs'] ?> runs</div>
        <div style="display:flex;gap:6px;justify-content:flex-end;">
          <form method="POST">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="toggle_rule">
            <input type="hidden" name="rule_id" value="<?= $rule['id'] ?>">
            <button type="submit" class="btn btn-secondary btn-sm"><?= $rule['is_active'] ? '⏸' : '▶' ?></button>
          </form>
          <form method="POST" onsubmit="return confirm('Delete rule?')">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="delete_rule">
            <input type="hidden" name="rule_id" value="<?= $rule['id'] ?>">
            <button type="submit" class="btn btn-danger btn-sm">🗑</button>
          </form>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<?php endif; ?>

<!-- Create Automation Modal -->
<div class="modal-overlay" id="createModal">
  <div class="modal" style="max-width:560px;">
    <div class="modal-title">Create Automation Rule</div>
    <form method="POST" id="ruleForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="create_rule">
      <div class="form-group">
        <label class="form-label">Rule Name *</label>
        <input type="text" name="name" id="ruleName" class="form-control" placeholder="e.g. Welcome new contact" required>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
        <div class="form-group">
          <label class="form-label">Trigger Event</label>
          <select name="trigger_event" id="triggerSelect" class="form-control">
            <?php foreach ($triggerLabels as $val => $lbl): ?>
            <option value="<?= $val ?>"><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Delay (minutes)</label>
          <input type="number" name="delay_minutes" id="delayMin" class="form-control" value="0" min="0">
        </div>
      </div>
      <div class="form-group">
        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:13px;color:var(--text2);">
          <input type="checkbox" name="run_once"> Run only once per contact
        </label>
      </div>

      <div style="font-size:12px;font-weight:600;letter-spacing:0.8px;text-transform:uppercase;color:var(--muted);margin-bottom:10px;">Actions</div>
      <div id="actionsContainer"></div>
      <button type="button" class="btn btn-secondary btn-sm" onclick="addAction()">+ Add Action</button>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('createModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Rule</button>
      </div>
    </form>
  </div>
</div>

<script>
let actionIdx = 0;
<?php $tplList = db()->all("SELECT id, template_name, category FROM message_templates WHERE user_id = ? AND status = 'approved' ORDER BY template_name", [$uid]); ?>
const TPL_OPTIONS = <?= json_encode(implode('', array_map(fn($t) => '<option value="' . (int)$t['id'] . '">' . e($t['template_name'] . ' (' . $t['category'] . ')') . '</option>', $tplList)) ?: '<option value="0">No approved templates yet</option>', JSON_HEX_TAG) ?>;
function escHtml(s){const d=document.createElement('div');d.textContent=s||'';return d.innerHTML;}

function addAction(type = 'send_message', msg = '') {
  const container = document.getElementById('actionsContainer');
  const div = document.createElement('div');
  div.style.cssText = 'background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:12px;margin-bottom:10px;';
  div.innerHTML = `
    <div style="display:flex;gap:10px;align-items:flex-start;">
      <div style="flex:1;">
        <select name="action_type[]" class="form-control" style="margin-bottom:8px;" onchange="toggleActionFields(this)">
          <option value="send_message" ${type==='send_message'?'selected':''}>Send Message</option>
          <option value="add_tag" ${type==='add_tag'?'selected':''}>Add Tag</option>
          <option value="send_template" ${type==='send_template'?'selected':''}>Send Template</option>
        </select>
        <div class="msg-field">
          <textarea name="action_message[]" class="form-control" rows="2" placeholder="Message text… use {{name}} for contact name">${escHtml(msg)}</textarea>
          <div style="font-size:11px;color:var(--muted);margin-top:4px;">Free text reaches the customer only within 24h of their last message.</div>
        </div>
        <div class="tag-field" style="display:none;">
          <input type="text" name="action_tag[]" class="form-control" placeholder="Tag name">
        </div>
        <div class="tpl-field" style="display:none;">
          <select name="action_template[]" class="form-control">${TPL_OPTIONS}</select>
          <div style="font-size:11px;color:var(--muted);margin-top:4px;">Charged from wallet at the template category price.</div>
        </div>
      </div>
      <button type="button" onclick="this.closest('div').parentElement.remove()" style="background:none;border:none;color:var(--muted);cursor:pointer;font-size:16px;padding:4px;">✕</button>
    </div>`;
  container.appendChild(div);
  if (type !== 'send_message') toggleActionFields(div.querySelector('select'));
  actionIdx++;
}

function toggleActionFields(sel) {
  const wrap = sel.closest('div');
  wrap.querySelector('.msg-field').style.display = sel.value === 'send_message' ? 'block' : 'none';
  wrap.querySelector('.tag-field').style.display  = sel.value === 'add_tag'      ? 'block' : 'none';
  wrap.querySelector('.tpl-field').style.display  = sel.value === 'send_template' ? 'block' : 'none';
}

function fillPreset(trigger, name) {
  document.getElementById('ruleName').value    = name;
  document.getElementById('triggerSelect').value = trigger;
  document.getElementById('actionsContainer').innerHTML = '';
  addAction('send_message', 'Hello {{name}}, welcome to our service!');
  openModal('createModal');
}

// Add one default action on load
addAction();
</script>

<?php include __DIR__ . '/../includes/user_footer.php'; ?>
