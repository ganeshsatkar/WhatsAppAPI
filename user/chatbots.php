<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

requireLogin();
$pageTitle = 'Chatbot Builder';
requireFeature('chatbot_builder', 'Chatbot builder');
$uid = currentUser()['id'];

$flowId = (int)($_GET['flow'] ?? 0);
$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'create_flow') {
        $name    = sanitize($_POST['name'] ?? '');
        $keyword = sanitize($_POST['trigger_keyword'] ?? '');
        $type    = sanitize($_POST['trigger_type'] ?? 'keyword');
        if (!$name) { $err = 'Flow name is required.'; }
        else {
            $id = db()->insert('INSERT INTO bot_flows (user_id, name, trigger_keyword, trigger_type, flow_data) VALUES (?,?,?,?,?)',
                [$uid, $name, $keyword, $type, json_encode(['nodes' => [], 'edges' => []])]);
            header("Location: chatbots.php?flow=$id");
            exit;
        }
    }

    if ($action === 'save_flow' && $flowId) {
        $flowData = $_POST['flow_data'] ?? '{}';
        // Validate JSON
        json_decode($flowData);
        if (json_last_error() === JSON_ERROR_NONE) {
            db()->run('UPDATE bot_flows SET flow_data = ?, updated_at = NOW() WHERE id = ? AND user_id = ?', [$flowData, $flowId, $uid]);
            jsonResponse(['success' => true, 'message' => 'Flow saved!']);
        }
        jsonResponse(['success' => false, 'error' => 'Invalid flow data'], 400);
    }

    if ($action === 'toggle_flow' && $flowId) {
        $curr = db()->row('SELECT is_active FROM bot_flows WHERE id = ?', [$flowId])['is_active'] ?? 0;
        db()->run('UPDATE bot_flows SET is_active = ? WHERE id = ? AND user_id = ?', [$curr ? 0 : 1, $flowId, $uid]);
        $msg = $curr ? 'Flow deactivated.' : 'Flow activated!';
    }

    if ($action === 'delete_flow') {
        $id = (int)$_POST['flow_id'];
        db()->run('DELETE FROM bot_flows WHERE id = ? AND user_id = ?', [$id, $uid]);
        header('Location: chatbots.php');
        exit;
    }
}

$flows   = db()->all('SELECT * FROM bot_flows WHERE user_id = ? ORDER BY created_at DESC', [$uid]);
$active  = $flowId ? db()->row('SELECT * FROM bot_flows WHERE id = ? AND user_id = ?', [$flowId, $uid]) : null;

include __DIR__ . '/../includes/user_header.php';

if ($active): // Builder mode
?>
<style>
.builder-layout { display:grid; grid-template-columns:240px 1fr 260px; height:calc(100vh - 120px); gap:0; background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; }
.panel { display:flex; flex-direction:column; overflow:hidden; }
.panel-header { padding:14px 16px; border-bottom:1px solid var(--border); font-family:'Syne',sans-serif; font-weight:700; font-size:13.5px; display:flex; align-items:center; justify-content:space-between; flex-shrink:0; }
.canvas-area { background:var(--bg); position:relative; overflow:auto; flex:1; cursor:grab; }
.canvas-area:active { cursor:grabbing; }
.canvas { position:relative; min-width:2000px; min-height:1500px; }
.node { position:absolute; background:var(--surface); border:2px solid var(--border2); border-radius:12px; min-width:180px; max-width:220px; cursor:move; user-select:none; transition:border-color 0.15s, box-shadow 0.15s; }
.node:hover { box-shadow:0 4px 20px rgba(0,0,0,0.4); }
.node.selected { border-color:var(--accent); box-shadow:0 0 0 3px var(--accent-dim); }
.node-header { padding:10px 14px; border-bottom:1px solid var(--border); border-radius:10px 10px 0 0; display:flex; align-items:center; gap:8px; font-size:12px; font-weight:600; }
.node-body { padding:10px 14px; font-size:12px; color:var(--text2); }
.node-port { width:12px; height:12px; border-radius:50%; background:var(--surface3); border:2px solid var(--border2); position:absolute; cursor:crosshair; transition:background 0.15s; }
.node-port:hover { background:var(--accent); }
.node-port.input  { left:-7px; top:50%; transform:translateY(-50%); }
.node-port.output { right:-7px; top:50%; transform:translateY(-50%); }
.node-type-trigger  { --nc:#f59e0b; } .node-type-trigger  .node-header { background:rgba(245,158,11,0.1); color:#f59e0b; }
.node-type-message  { --nc:var(--accent); } .node-type-message  .node-header { background:var(--accent-dim); color:var(--accent); }
.node-type-condition{ --nc:var(--blue); }  .node-type-condition .node-header { background:var(--blue-dim); color:var(--blue); }
.node-type-delay    { --nc:var(--purple); }.node-type-delay    .node-header { background:var(--purple-dim); color:var(--purple); }
.node-type-action   { --nc:var(--orange); }.node-type-action   .node-header { background:var(--orange-dim); color:var(--orange); }
.node-type-end      { --nc:var(--danger); }.node-type-end      .node-header { background:var(--danger-dim); color:var(--danger); }
.conn-line { position:absolute; pointer-events:none; overflow:visible; top:0; left:0; width:100%; height:100%; }
.sidebar-nodes { flex:1; overflow-y:auto; padding:12px; }
.node-palette-item { background:var(--surface2); border:1px solid var(--border); border-radius:8px; padding:10px 12px; margin-bottom:8px; cursor:grab; display:flex; align-items:center; gap:10px; font-size:13px; transition:background 0.15s; }
.node-palette-item:hover { background:var(--surface3); }
.props-panel { flex:1; overflow-y:auto; padding:14px; }
</style>

<div style="margin-bottom:14px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;">
  <a href="chatbots.php" class="btn btn-secondary btn-sm">← All Flows</a>
  <span style="font-family:'Syne',sans-serif; font-weight:700;"><?= e($active['name']) ?></span>
  <span class="badge <?= $active['is_active'] ? 'badge-green' : 'badge-gray' ?>"><?= $active['is_active'] ? '● Active' : '○ Draft' ?></span>
  <div style="margin-left:auto; display:flex; gap:8px;">
    <form method="POST" style="display:inline">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="toggle_flow">
      <button type="submit" class="btn btn-secondary btn-sm"><?= $active['is_active'] ? 'Deactivate' : 'Activate' ?></button>
    </form>
    <button class="btn btn-primary btn-sm" onclick="saveFlow()">💾 Save</button>
  </div>
</div>

<div class="builder-layout">
  <!-- Node Palette -->
  <div class="panel" style="border-right:1px solid var(--border);">
    <div class="panel-header">Node Types</div>
    <div class="sidebar-nodes">
      <?php
      $nodeDefs = [
        ['type'=>'trigger',  'icon'=>'⚡','label'=>'Trigger',     'desc'=>'Start of flow'],
        ['type'=>'message',  'icon'=>'💬','label'=>'Send Message','desc'=>'Text or buttons'],
        ['type'=>'condition','icon'=>'🔀','label'=>'Condition',   'desc'=>'Branch on input'],
        ['type'=>'delay',    'icon'=>'⏱','label'=>'Delay',       'desc'=>'Wait before next'],
        ['type'=>'action',   'icon'=>'⚙️','label'=>'Action',      'desc'=>'Tag, update CRM'],
        ['type'=>'end',      'icon'=>'🔚','label'=>'End Flow',    'desc'=>'Terminate flow'],
      ];
      foreach ($nodeDefs as $nd): ?>
      <div class="node-palette-item" draggable="true" data-type="<?= $nd['type'] ?>" ondragstart="startDrag(event)">
        <span style="font-size:18px;"><?= $nd['icon'] ?></span>
        <div>
          <div style="font-weight:600;"><?= $nd['label'] ?></div>
          <div style="font-size:11px; color:var(--muted);"><?= $nd['desc'] ?></div>
        </div>
      </div>
      <?php endforeach; ?>
      <div style="margin-top:20px; font-size:11px; color:var(--muted); line-height:1.6;">
        <strong style="color:var(--text2);">How to use:</strong><br>
        1. Drag nodes onto canvas<br>
        2. Connect ports by dragging<br>
        3. Click node to edit<br>
        4. Save when done
      </div>
    </div>
  </div>

  <!-- Canvas -->
  <div class="canvas-area" id="canvasArea" ondrop="dropNode(event)" ondragover="event.preventDefault()">
    <svg class="conn-line" id="connSvg"></svg>
    <div class="canvas" id="canvas"></div>
  </div>

  <!-- Properties -->
  <div class="panel" style="border-left:1px solid var(--border);">
    <div class="panel-header">Properties</div>
    <div class="props-panel" id="propsPanel">
      <div style="color:var(--muted); font-size:13px; text-align:center; padding:40px 0;">Click a node to edit its properties</div>
    </div>
  </div>
</div>

<script>
const FLOW_ID = <?= $flowId ?>;
let flow = <?= $active['flow_data'] ?? '{"nodes":[],"edges":[]}' ?>;
let selectedNode = null;
let draggingFrom = null; // port drag
let canvasOffset = { x: 0, y: 0 };
let nodeIdCounter = 1000;

const canvas     = document.getElementById('canvas');
const canvasArea = document.getElementById('canvasArea');
const connSvg    = document.getElementById('connSvg');
const propsPanel = document.getElementById('propsPanel');

const NODE_CONFIGS = {
  trigger:   { icon: '⚡', label: 'Trigger',      fields: [{ key: 'keyword', label: 'Trigger Keyword', type: 'text', placeholder: 'e.g. HI' }] },
  message:   { icon: '💬', label: 'Send Message', fields: [{ key: 'text', label: 'Message Text', type: 'textarea' }, { key: 'buttons', label: 'Buttons (one per line)', type: 'textarea', placeholder: 'Option 1\nOption 2' }] },
  condition: { icon: '🔀', label: 'Condition',    fields: [{ key: 'variable', label: 'Matches Input', type: 'text', placeholder: 'Option 1' }] },
  delay:     { icon: '⏱', label: 'Delay',        fields: [{ key: 'minutes', label: 'Delay (minutes)', type: 'number' }] },
  action:    { icon: '⚙️', label: 'Action',       fields: [{ key: 'tag', label: 'Add Tag', type: 'text' }, { key: 'note', label: 'Set Note', type: 'text' }] },
  end:       { icon: '🔚', label: 'End Flow',     fields: [{ key: 'message', label: 'Final Message (optional)', type: 'text' }] },
};

function renderAll() {
  canvas.innerHTML = '';
  flow.nodes.forEach(renderNode);
  renderEdges();
}

function renderNode(node) {
  const cfg = NODE_CONFIGS[node.type] || {};
  const el  = document.createElement('div');
  el.className = `node node-type-${node.type}`;
  el.id        = 'node-' + node.id;
  el.style.cssText = `left:${node.x}px; top:${node.y}px;`;

  const preview = node.data?.text ? node.data.text.substring(0, 40) + (node.data.text.length > 40 ? '…' : '') : node.data?.keyword || node.type;

  el.innerHTML = `
    <div class="node-port input" data-node="${node.id}" data-port="input"></div>
    <div class="node-header">${cfg.icon || '⬡'} ${cfg.label || node.type}</div>
    <div class="node-body">${preview}</div>
    <div class="node-port output" data-node="${node.id}" data-port="output"></div>
    <div style="position:absolute;top:4px;right:6px;font-size:10px;color:var(--muted);cursor:pointer;" onclick="deleteNode('${node.id}')">✕</div>
  `;

  el.addEventListener('mousedown', (e) => {
    if (e.target.classList.contains('node-port')) return;
    startNodeDrag(e, node.id, el);
  });

  el.addEventListener('click', (e) => {
    if (e.target.classList.contains('node-port')) return;
    selectNode(node.id);
  });

  // Port drag for connections
  el.querySelectorAll('.node-port').forEach(port => {
    port.addEventListener('mousedown', (e) => {
      e.stopPropagation();
      if (port.dataset.port === 'output') {
        draggingFrom = node.id;
      }
    });
    port.addEventListener('mouseup', (e) => {
      if (draggingFrom && draggingFrom !== node.id && port.dataset.port === 'input') {
        addEdge(draggingFrom, node.id);
      }
      draggingFrom = null;
    });
  });

  canvas.appendChild(el);
}

function selectNode(id) {
  document.querySelectorAll('.node').forEach(n => n.classList.remove('selected'));
  document.getElementById('node-' + id)?.classList.add('selected');
  selectedNode = id;
  renderProps(id);
}

function renderProps(id) {
  const node = flow.nodes.find(n => n.id === id);
  if (!node) return;
  const cfg = NODE_CONFIGS[node.type];
  if (!cfg) return;

  let html = `<div style="font-family:'Syne',sans-serif;font-weight:700;font-size:14px;margin-bottom:14px;">${cfg.icon} ${cfg.label}</div>`;

  cfg.fields.forEach(f => {
    const val = node.data?.[f.key] || '';
    html += `<div class="form-group">
      <label class="form-label">${f.label}</label>
      ${f.type === 'textarea'
        ? `<textarea class="form-control" style="min-height:70px;" onchange="updateNodeProp('${id}','${f.key}',this.value)" placeholder="${f.placeholder || ''}">${val}</textarea>`
        : `<input type="${f.type || 'text'}" class="form-control" value="${val}" onchange="updateNodeProp('${id}','${f.key}',this.value)" placeholder="${f.placeholder || ''}">`
      }
    </div>`;
  });

  html += `<button class="btn btn-danger btn-sm" style="width:100%;margin-top:8px;" onclick="deleteNode('${id}')">Delete Node</button>`;
  propsPanel.innerHTML = html;
}

function updateNodeProp(id, key, val) {
  const node = flow.nodes.find(n => n.id === id);
  if (!node) return;
  if (!node.data) node.data = {};
  node.data[key] = val;
  // Re-render just the body
  const bodyEl = document.querySelector(`#node-${id} .node-body`);
  if (bodyEl) bodyEl.textContent = val.substring(0, 40) + (val.length > 40 ? '…' : '');
}

function deleteNode(id) {
  flow.nodes = flow.nodes.filter(n => n.id !== id);
  flow.edges = (flow.edges || []).filter(e => e.source !== id && e.target !== id);
  document.getElementById('node-' + id)?.remove();
  renderEdges();
  propsPanel.innerHTML = '<div style="color:var(--muted);font-size:13px;text-align:center;padding:40px 0;">Click a node to edit</div>';
}

function addEdge(source, target) {
  flow.edges = flow.edges || [];
  // Remove existing edges from source
  flow.edges = flow.edges.filter(e => e.source !== source);
  flow.edges.push({ id: 'e' + Date.now(), source, target });
  renderEdges();
}

function renderEdges() {
  connSvg.innerHTML = '';
  (flow.edges || []).forEach(edge => {
    const srcEl  = document.getElementById('node-' + edge.source);
    const tgtEl  = document.getElementById('node-' + edge.target);
    if (!srcEl || !tgtEl) return;
    const srcPort = srcEl.querySelector('.output');
    const tgtPort = tgtEl.querySelector('.input');
    if (!srcPort || !tgtPort) return;
    const sr = srcPort.getBoundingClientRect();
    const tr = tgtPort.getBoundingClientRect();
    const cr = canvas.getBoundingClientRect();

    const x1 = sr.left + sr.width/2 - cr.left + canvasArea.scrollLeft;
    const y1 = sr.top  + sr.height/2 - cr.top  + canvasArea.scrollTop;
    const x2 = tr.left + tr.width/2 - cr.left + canvasArea.scrollLeft;
    const y2 = tr.top  + tr.height/2 - cr.top  + canvasArea.scrollTop;
    const cx = (x1 + x2) / 2;

    const path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
    path.setAttribute('d', `M${x1},${y1} C${cx},${y1} ${cx},${y2} ${x2},${y2}`);
    path.setAttribute('stroke', 'var(--accent)');
    path.setAttribute('stroke-width', '2');
    path.setAttribute('fill', 'none');
    path.setAttribute('stroke-opacity', '0.6');
    connSvg.appendChild(path);
  });
}

// Drag node from canvas
function startNodeDrag(e, id, el) {
  e.preventDefault();
  const startX = e.clientX;
  const startY = e.clientY;
  const node   = flow.nodes.find(n => n.id === id);
  const ox = node.x, oy = node.y;

  const move = (ev) => {
    const dx = ev.clientX - startX;
    const dy = ev.clientY - startY;
    node.x = Math.max(0, ox + dx);
    node.y = Math.max(0, oy + dy);
    el.style.left = node.x + 'px';
    el.style.top  = node.y + 'px';
    renderEdges();
  };

  const up = () => {
    document.removeEventListener('mousemove', move);
    document.removeEventListener('mouseup', up);
  };

  document.addEventListener('mousemove', move);
  document.addEventListener('mouseup', up);
}

// Drag from palette onto canvas
function startDrag(e) {
  e.dataTransfer.setData('nodeType', e.currentTarget.dataset.type);
}

function dropNode(e) {
  e.preventDefault();
  const type = e.dataTransfer.getData('nodeType');
  if (!type) return;
  const rect = canvas.getBoundingClientRect();
  const x    = e.clientX - rect.left + canvasArea.scrollLeft;
  const y    = e.clientY - rect.top  + canvasArea.scrollTop;

  const node = {
    id:   'node_' + (nodeIdCounter++),
    type: type,
    x:    Math.max(0, x - 90),
    y:    Math.max(0, y - 40),
    data: {},
  };

  flow.nodes = flow.nodes || [];
  flow.nodes.push(node);
  renderNode(node);
}

async function saveFlow() {
  const res = await fetch(window.location.href, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-TOKEN': window.CSRF_TOKEN },
    body: new URLSearchParams({
      action: 'save_flow',
      flow_data: JSON.stringify(flow),
      csrf_token: window.CSRF_TOKEN,
    }),
  });
  const data = await res.json();
  toast(data.message || data.error || 'Saved!', data.success ? 'success' : 'error');
}

// Initial render
renderAll();
window.addEventListener('resize', renderEdges);
</script>

<?php else: // Flow list mode ?>

<div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px;">
  <div>
    <h2 style="font-family:'Syne',sans-serif; font-size:20px; font-weight:700;">Chatbot Flows</h2>
    <p style="color:var(--muted); font-size:13px; margin-top:4px;">Build automated conversation flows for WhatsApp</p>
  </div>
  <button class="btn btn-primary" onclick="openModal('createFlowModal')">+ Create Flow</button>
</div>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= e($msg) ?></div><?php endif; ?>

<?php if (empty($flows)): ?>
<div class="card">
  <div class="empty-state">
    <div class="empty-icon">🤖</div>
    <div class="empty-title">No chatbot flows yet</div>
    <div class="empty-sub">Create your first flow to automate customer conversations on WhatsApp.</div>
    <button class="btn btn-primary" onclick="openModal('createFlowModal')">Create Your First Flow</button>
  </div>
</div>
<?php else: ?>
<div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(280px, 1fr)); gap:16px;">
  <?php foreach ($flows as $f): ?>
  <div class="card" style="position:relative;">
    <div style="display:flex; align-items:flex-start; justify-content:space-between; margin-bottom:12px;">
      <div>
        <div style="font-family:'Syne',sans-serif; font-weight:700; font-size:15px; margin-bottom:4px;"><?= e($f['name']) ?></div>
        <span class="badge <?= $f['is_active'] ? 'badge-green' : 'badge-gray' ?>"><?= $f['is_active'] ? '● Active' : '○ Draft' ?></span>
      </div>
      <div style="font-size:24px;">🤖</div>
    </div>
    <div style="font-size:12px; color:var(--muted); margin-bottom:12px;">
      Trigger: <strong style="color:var(--text2);"><?= $f['trigger_type'] === 'any' ? 'Any message' : e($f['trigger_keyword'] ?? 'Any') ?></strong>
    </div>
    <div style="font-size:11px; color:var(--muted); margin-bottom:14px;">Updated <?= timeAgo($f['updated_at'] ?? $f['created_at']) ?></div>
    <div style="display:flex; gap:8px;">
      <a href="?flow=<?= $f['id'] ?>" class="btn btn-primary btn-sm">✏ Edit Flow</a>
      <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this flow?')">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete_flow">
        <input type="hidden" name="flow_id" value="<?= $f['id'] ?>">
        <button type="submit" class="btn btn-danger btn-sm">🗑</button>
      </form>
    </div>
  </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Create Flow Modal -->
<div class="modal-overlay" id="createFlowModal">
  <div class="modal">
    <div class="modal-title">Create New Flow</div>
    <div class="modal-sub">Set the trigger that activates this chatbot flow</div>
    <form method="POST">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="create_flow">
      <div class="form-group">
        <label class="form-label">Flow Name *</label>
        <input type="text" name="name" class="form-control" placeholder="e.g. Product Enquiry Bot" required>
      </div>
      <div class="form-group">
        <label class="form-label">Trigger Type</label>
        <select name="trigger_type" class="form-control" onchange="document.getElementById('kwField').style.display=this.value==='keyword'?'block':'none'">
          <option value="keyword">Specific keyword</option>
          <option value="any">Any incoming message</option>
          <option value="welcome">Welcome / first message</option>
        </select>
      </div>
      <div class="form-group" id="kwField">
        <label class="form-label">Keyword (e.g. "HI", "START")</label>
        <input type="text" name="trigger_keyword" class="form-control" placeholder="HI">
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('createFlowModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create & Edit Flow →</button>
      </div>
    </form>
  </div>
</div>

<?php endif; ?>

<?php include __DIR__ . '/../includes/user_footer.php'; ?>
