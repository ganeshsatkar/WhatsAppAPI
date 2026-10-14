<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/WhatsAppService.php';

requireLogin();
$pageTitle = 'Message Templates';
$uid = currentUser()['id'];
$msg = $err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'upload_header_media') {
        header('Content-Type: application/json');
        if (!isset($_FILES['file'])) { echo json_encode(['success'=>false,'error'=>'No file']); exit; }
        $wa = WhatsAppService::forUser($uid);
        if (!$wa) { echo json_encode(['success'=>false,'error'=>'WhatsApp API not connected']); exit; }
        $file    = $_FILES['file'];
        $allowed = ['image/jpeg','image/png','image/webp','video/mp4','video/3gp','application/pdf',
                    'application/msword','application/vnd.openxmlformats-officedocument.wordprocessingml.document'];
        if (!in_array($file['type'], $allowed)) {
            echo json_encode(['success'=>false,'error'=>'File type not allowed: '.$file['type']]); exit;
        }
        $maxSize = str_starts_with($file['type'],'video/') ? 16*1024*1024 : 5*1024*1024;
        if ($file['size'] > $maxSize) {
            echo json_encode(['success'=>false,'error'=>'File too large (max '.($maxSize/1024/1024).'MB)']); exit;
        }
        $res = $wa->uploadMedia($file['tmp_name'], $file['type']);
        echo json_encode($res);
        exit;
    }

    if ($action === 'save_template') {
        $name    = strtolower(preg_replace('/[^a-z0-9_]/','_',sanitize($_POST['template_name']??'')));
        $lang    = sanitize($_POST['language']??'en');
        $cat     = sanitize($_POST['category']??'MARKETING');
        $body    = trim($_POST['body']??'');
        $footer  = sanitize($_POST['footer']??'');
        $htype   = sanitize($_POST['header_type']??'none');
        $hcont   = sanitize($_POST['header_content']??'');

        // Buttons
        $buttons = [];
        $btypes  = $_POST['btn_type']  ?? [];
        $btexts  = $_POST['btn_text']  ?? [];
        $bvalues = $_POST['btn_value'] ?? [];
        foreach ($btypes as $i => $bt) {
            if (empty($btexts[$i])) continue;
            $b = ['type'=>$bt,'text'=>$btexts[$i]];
            if ($bt==='PHONE_NUMBER') $b['phone_number'] = preg_replace('/[^0-9+]/','',$bvalues[$i]??'');
            elseif ($bt==='URL')      $b['url'] = $bvalues[$i] ?? '';
            $buttons[] = $b;
        }

        // Variables detection
        preg_match_all('/\{\{(\d+)\}\}/', $body, $nm);
        preg_match_all('/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/', $body, $namedM);
        $vars = array_values(array_unique(array_merge($nm[1]??[], $namedM[1]??[])));

        if (!$name||!$body) { $err='Name and body required.'; }
        else {
            $id = (int)($_POST['template_id']??0);
            $bj = !empty($buttons) ? json_encode($buttons) : null;
            $vj = !empty($vars)    ? json_encode($vars)    : null;

            if ($id) {
                // UPDATE existing — just save locally
                db()->run('UPDATE message_templates SET template_name=?,language=?,category=?,header_type=?,header_content=?,body=?,footer=?,buttons=?,variables=?,updated_at=NOW() WHERE id=? AND user_id=?',
                    [$name,$lang,$cat,$htype,$hcont,$body,$footer,$bj,$vj,$id,$uid]);
                $msg = 'Template updated locally.';

            } else {
                // NEW template — save then auto-submit to Meta
                $newId = db()->insert(
                    'INSERT INTO message_templates (user_id,template_name,language,category,header_type,header_content,body,footer,buttons,variables,status) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                    [$uid,$name,$lang,$cat,$htype,$hcont,$body,$footer,$bj,$vj,'pending']
                );

                // Auto-submit to Meta
                $wa  = WhatsAppService::forUser($uid);
                $was = db()->row('SELECT * FROM user_whatsapp_settings WHERE user_id=?', [$uid]);

                if ($wa && $was && $was['business_account_id']) {
                    $components = [];

                    // Header component
                    if ($htype && $htype !== 'none') {
                        if ($htype === 'text' && !empty($hcont)) {
                            // Text header — include text
                            $components[] = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $hcont];
                        } elseif (in_array($htype, ['image','video','document'])) {
                            // Media header — DO NOT include example handle for new submissions
                            // Meta will assign a handle after approval
                            // Just declare the format, no example needed for basic approval
                            $components[] = ['type' => 'HEADER', 'format' => strtoupper($htype)];
                        }
                    }

                    // Body component
                    $bodyComp = ['type' => 'BODY', 'text' => $body];
                    preg_match_all('/\{\{(\d+)\}\}/', $body, $bvm);
                    $bvars = array_unique($bvm[1] ?? []);
                    sort($bvars, SORT_NUMERIC);
                    if (!empty($bvars)) {
                        $examples = array_map(fn($v) => match((int)$v) {
                            1 => 'Customer Name', 2 => '9876543210', 3 => 'Sample Value', default => 'Value'
                        }, $bvars);
                        $bodyComp['example'] = ['body_text' => [$examples]];
                    }
                    $components[] = $bodyComp;

                    // Footer component
                    if (!empty($footer)) {
                        $components[] = ['type' => 'FOOTER', 'text' => $footer];
                    }

                    // Buttons component
                    if (!empty($buttons)) {
                        $btnList = [];
                        foreach ($buttons as $btn) {
                            $btype = strtoupper($btn['type']);
                            $b     = ['type' => $btype, 'text' => $btn['text']];
                            if ($btype === 'PHONE_NUMBER') {
                                // Must be numeric only, with country code
                                $phone = preg_replace('/[^0-9]/', '', $btn['phone_number'] ?? '91');
                                $b['phone_number'] = $phone;
                            }
                            if ($btype === 'URL') {
                                $b['url'] = $btn['url'] ?? 'https://example.com';
                                // URL buttons can have a variable at the end
                                if (!str_contains($b['url'], '{{')) {
                                    // static URL — fine as is
                                }
                            }
                            // QUICK_REPLY needs no extra fields
                            $btnList[] = $b;
                        }
                        $components[] = ['type' => 'BUTTONS', 'buttons' => $btnList];
                    }

                    $payload = [
                        'name'       => $name,
                        'language'   => $lang,
                        'category'   => strtoupper($cat),
                        'components' => $components,
                    ];

                    // Log payload for debugging
                    error_log('WP API Template Submit: ' . json_encode($payload));

                    $apiUrl = WA_API_BASE . '/' . $was['business_account_id'] . '/message_templates';
                    $ch     = curl_init($apiUrl);
                    curl_setopt_array($ch, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => json_encode($payload),
                        CURLOPT_HTTPHEADER     => [
                            'Authorization: Bearer ' . decryptSecret($was['access_token']),
                            'Content-Type: application/json',
                        ],
                        CURLOPT_TIMEOUT => 30,
                    ]);
                    $rawRes  = curl_exec($ch);
                    $res     = json_decode($rawRes, true);
                    $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    curl_close($ch);

                    if ($code === 200 && isset($res['id'])) {
                        db()->run("UPDATE message_templates SET status='pending' WHERE id=?", [$newId]);
                        $msg = '✅ Template submitted to Meta! Approval takes 5–30 min. Click "Sync from Meta" after approval.';
                    } else {
                        $metaErr = $res['error']['error_user_msg'] ?? $res['error']['message'] ?? 'Unknown';
                        $metaCode= $res['error']['code'] ?? '';
                        $details = $res['error']['error_data']['details'] ?? '';
                        $msg = '⚠️ Template saved but Meta submission failed: ' . $metaErr
                            . ($details ? ' · Details: ' . $details : '')
                            . ($metaCode ? ' (Code: ' . $metaCode . ')' : '')
                            . ' — Use 📤 Submit button to retry after fixing.';
                    }
                } else {
                    $msg = 'Template saved locally. Connect WhatsApp API in Settings to submit to Meta.';
                }
            }
        }
    }

    if ($action==='delete_template') {
        $id  = (int)($_POST['template_id'] ?? 0);
        $tpl = db()->row('SELECT * FROM message_templates WHERE id=? AND user_id=?', [$id, $uid]);

        if (!$tpl) {
            $err = 'Template not found.';
        } else {
            // A campaign that still needs this template would silently stall if we removed it.
            $inUse = db()->count("SELECT COUNT(*) FROM campaigns WHERE template_id=? AND user_id=? AND status IN ('running','scheduled','paused')", [$id, $uid]);
            if ($inUse) {
                $err = "Can't delete — $inUse running, scheduled or paused campaign(s) still use this template. Finish or delete those campaigns first.";
            } else {
                $wa  = WhatsAppService::forUser($uid);
                $was = db()->row('SELECT * FROM user_whatsapp_settings WHERE user_id=?', [$uid]);

                if ($wa && $was && $was['business_account_id']) {
                    // Remove it at Meta first, otherwise "Sync from Meta" would bring it straight back.
                    $res = $wa->deleteTemplate($was['business_account_id'], $tpl['template_name']);
                    $errText  = strtolower($res['error'] ?? '');
                    $alreadyGone = !$res['success'] && (($res['code'] ?? 0) === 404
                        || str_contains($errText, 'does not exist') || str_contains($errText, 'not found'));

                    if ($res['success'] || $alreadyGone) {
                        db()->run('DELETE FROM message_templates WHERE id=? AND user_id=?', [$id, $uid]);
                        $msg = $alreadyGone
                            ? 'Template deleted (it was already removed at WhatsApp).'
                            : '✓ Template deleted from WhatsApp and from your account.';
                        logActivity('template_deleted', $tpl['template_name']);
                    } else {
                        $err = 'WhatsApp (Meta) refused to delete it: ' . ($res['error'] ?? 'unknown error') . ' — nothing was removed.';
                    }
                } else {
                    db()->run('DELETE FROM message_templates WHERE id=? AND user_id=?', [$id, $uid]);
                    $msg = 'Deleted from your account. WhatsApp is not connected, so any copy already at Meta was not touched.';
                    logActivity('template_deleted', $tpl['template_name']);
                }
            }
        }
    }

    if ($action==='submit_to_meta') {
        $id  = (int)$_POST['template_id'];
        $tpl = db()->row('SELECT * FROM message_templates WHERE id=? AND user_id=?', [$id, $uid]);
        $wa  = WhatsAppService::forUser($uid);
        $was = db()->row('SELECT * FROM user_whatsapp_settings WHERE user_id=?', [$uid]);

        if (!$tpl) { $err = 'Template not found.'; }
        elseif (!$wa || !$was) { $err = 'WhatsApp API not connected.'; }
        else {
            // Build components for Meta API
            $components = [];

            // Header
            if ($tpl['header_type'] && $tpl['header_type'] !== 'none') {
                $ht = $tpl['header_type'];
                if ($ht === 'text' && !empty($tpl['header_content'])) {
                    $components[] = ['type' => 'HEADER', 'format' => 'TEXT', 'text' => $tpl['header_content']];
                } elseif (in_array($ht, ['image','video','document'])) {
                    // Just declare format — no example handle needed
                    $components[] = ['type' => 'HEADER', 'format' => strtoupper($ht)];
                }
            }

            // Body
            $bodyComp = ['type' => 'BODY', 'text' => $tpl['body']];
            // Add example values for variables
            preg_match_all('/\{\{(\d+)\}\}/', $tpl['body'], $vm);
            $vars = array_unique($vm[1] ?? []);
            if (!empty($vars)) {
                $examples = array_map(fn($v) => match((int)$v) {
                    1 => 'Customer Name', 2 => '9876543210', default => 'Sample Value'
                }, $vars);
                $bodyComp['example'] = ['body_text' => [$examples]];
            }
            $components[] = $bodyComp;

            // Footer
            if (!empty($tpl['footer'])) {
                $components[] = ['type' => 'FOOTER', 'text' => $tpl['footer']];
            }

            // Buttons
            $buttons = json_decode($tpl['buttons'] ?? '[]', true) ?: [];
            if (!empty($buttons)) {
                $btnComp = ['type' => 'BUTTONS', 'buttons' => []];
                foreach ($buttons as $btn) {
                    $b = ['type' => $btn['type'], 'text' => $btn['text']];
                    if ($btn['type'] === 'PHONE_NUMBER') $b['phone_number'] = $btn['phone_number'] ?? '';
                    if ($btn['type'] === 'URL') { $b['url'] = $btn['url'] ?? ''; }
                    $btnComp['buttons'][] = $b;
                }
                $components[] = $btnComp;
            }

            // Submit to Meta
            $payload = [
                'name'       => $tpl['template_name'],
                'language'   => $tpl['language'] ?? 'en',
                'category'   => strtoupper($tpl['category'] ?? 'MARKETING'),
                'components' => $components,
            ];

            $wabaId = $was['business_account_id'];
            $url    = WA_API_BASE . '/' . $wabaId . '/message_templates';
            $ch     = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => json_encode($payload),
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . decryptSecret($was['access_token']),
                    'Content-Type: application/json',
                ],
                CURLOPT_TIMEOUT => 30,
            ]);
            $res  = json_decode(curl_exec($ch), true);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($code === 200 && isset($res['id'])) {
                db()->run("UPDATE message_templates SET status='pending' WHERE id=?", [$id]);
                $msg = '✓ Template submitted to Meta! ID: ' . $res['id'] . '. Status: ' . ($res['status'] ?? 'pending') . '. Approval usually takes 5–30 minutes.';
            } else {
                $errMsg = $res['error']['message'] ?? $res['error']['error_user_msg'] ?? 'Unknown error';
                $err = '❌ Meta rejected submission: ' . $errMsg;
            }
        }
    }

    if ($action==='sync_templates') {
        $wa  = WhatsAppService::forUser($uid);
        $was = db()->row('SELECT * FROM user_whatsapp_settings WHERE user_id=?', [$uid]);
        if ($wa && $was && $was['business_account_id']) {
            $r = $wa->getTemplates($was['business_account_id']);
            $synced=0; $updated=0;
            if (isset($r['error'])) {
                // Previously this was swallowed and reported as "0 new, 0 updated"
                $err = '✗ Meta rejected the sync: ' . ($r['error']['message'] ?? 'unknown error') . ' — check your access token in Settings.';
                $r['data'] = [];
            }
            foreach ($r['data']??[] as $t) {
                $body=''; $footer=''; $buttons=[];
                $htype='none'; $hcontent='';

                foreach ($t['components']??[] as $c) {
                    $ctype = strtoupper($c['type']??'');
                    if ($ctype==='BODY')   $body   = $c['text'] ?? '';
                    if ($ctype==='FOOTER') $footer = $c['text'] ?? '';
                    if ($ctype==='HEADER') {
                        $htype = strtolower($c['format'] ?? 'text');
                        $hcontent = $c['text'] ?? '';
                        // Media header — store example URL
                        if (in_array($htype,['image','video','document'])) {
                            $hcontent = $c['example']['header_handle'][0] ?? '';
                        }
                    }
                    if ($ctype==='BUTTONS') {
                        foreach ($c['buttons']??[] as $btn) {
                            $b=['type'=>$btn['type'],'text'=>$btn['text']??''];
                            if (isset($btn['phone_number'])) $b['phone_number']=$btn['phone_number'];
                            if (isset($btn['url']))          $b['url']=$btn['url'];
                            $buttons[]=$b;
                        }
                    }
                }

                // Map Meta status → our status
                $metaStatus = strtolower($t['status'] ?? 'pending');
                $ourStatus  = match($metaStatus) {
                    'approved', 'active' => 'approved',
                    'rejected', 'disabled' => 'rejected',
                    'paused'   => 'paused',
                    default    => 'pending',
                };

                $bj = !empty($buttons) ? json_encode($buttons) : null;
                $rr = ($ourStatus === 'approved') ? null : (($t['rejected_reason'] ?? '') && strtoupper($t['rejected_reason']) !== 'NONE' ? $t['rejected_reason'] : null);
                // Same name in another language is a different template
                $ex = db()->row('SELECT id FROM message_templates WHERE user_id=? AND template_name=? AND language=?', [$uid, $t['name'], $t['language'] ?? 'en']);

                if ($ex) {
                    db()->run('UPDATE message_templates SET status=?,rejection_reason=?,body=?,footer=?,buttons=?,header_type=?,header_content=?,category=?,language=?,updated_at=NOW() WHERE id=?',
                        [$ourStatus,$rr,$body,$footer,$bj,$htype,$hcontent,$t['category']??'MARKETING',$t['language']??'en',$ex['id']]);
                    $updated++;
                } else {
                    db()->insert('INSERT INTO message_templates (user_id,template_name,language,category,body,footer,header_type,header_content,buttons,status,rejection_reason) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                        [$uid,$t['name'],$t['language']??'en',$t['category']??'MARKETING',$body,$footer,$htype,$hcontent,$bj,$ourStatus,$rr]);
                    $synced++;
                }
            }
            if (!$err) $msg="✓ Synced from Meta — $synced new, $updated updated templates.";
        } else { $err='Connect WhatsApp API in Settings first.'; }
    }
}

$templates = db()->all('SELECT * FROM message_templates WHERE user_id=? ORDER BY created_at DESC', [$uid]);
include __DIR__ . '/../includes/user_header.php';
?>

<?php if ($msg): ?><div class="alert alert-success">✓ <?= e($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger">⚠ <?= e($err) ?></div><?php endif; ?>

<div style="display:flex;justify-content:flex-end;gap:10px;margin-bottom:20px;">
  <form method="POST" style="display:inline;"><?= csrfField() ?><input type="hidden" name="action" value="sync_templates"><button type="submit" class="btn btn-secondary">🔄 Sync from Meta</button></form>
  <button class="btn btn-primary" onclick="openTplModal()">+ New Template</button>
</div>

<?php $cnt = array_count_values(array_column($templates, 'status')); ?>
<div style="display:flex;gap:10px;flex-wrap:wrap;margin-bottom:14px;font-size:12.5px;">
  <span class="badge badge-green">✓ Approved <?= $cnt['approved'] ?? 0 ?></span>
  <span class="badge badge-gray">⏳ Pending <?= $cnt['pending'] ?? 0 ?></span>
  <span class="badge badge-red">✗ Rejected <?= $cnt['rejected'] ?? 0 ?></span>
  <span class="badge badge-orange">⏸ Paused <?= $cnt['paused'] ?? 0 ?></span>
</div>
<?php if (empty($templates)): ?>
<div class="card"><div class="empty-state"><div class="empty-icon">📋</div><div class="empty-title">No templates yet</div><div class="empty-sub">Create message templates for your campaigns. Templates need Meta approval.</div><button class="btn btn-primary" onclick="openTplModal()">Create Template</button></div></div>
<?php else: ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:16px;">
<?php foreach ($templates as $t):
  $sc=match($t['status']){'approved'=>'badge-green','rejected'=>'badge-red','paused'=>'badge-orange','pending'=>'badge-gray',default=>'badge-gray'};
  $statusLabel=match($t['status']){'approved'=>'✓ Approved','rejected'=>'✗ Rejected','paused'=>'⏸ Paused',default=>'⏳ '.ucfirst($t['status']??'Pending')};
  $btns=json_decode($t['buttons']??'[]',true)?:[];
  $vars=json_decode($t['variables']??'[]',true)?:[];
?>
<div class="card">
  <div class="card-header">
    <div>
      <div style="font-weight:800;font-size:14px;color:#fff;margin-bottom:5px;"><?= e($t['template_name']) ?></div>
      <div style="display:flex;gap:5px;flex-wrap:wrap;">
        <span class="badge <?= $sc ?>"><?= $statusLabel ?></span>
        <?php if (!empty($t['rejection_reason']) && $t['status'] !== 'approved'): ?>
        <div style="font-size:11.5px;color:var(--danger);margin-top:6px;line-height:1.4;">⚠ <?= e($t['rejection_reason']) ?></div>
        <?php endif; ?>
        <span class="badge badge-gray"><?= e($t['category']) ?></span>
        <span class="badge badge-blue"><?= strtoupper($t['language']) ?></span>
      </div>
    </div>
    <div style="display:flex;gap:5px;">
      <button class="btn btn-secondary btn-sm" onclick='editTpl(<?= json_encode($t) ?>)'>✏</button>
      <?php
      // Only show Submit for templates created here (status=pending)
      // Synced templates from Meta always get approved/rejected/paused — never pending
      // So pending = created locally, not yet submitted to Meta
      if ($t['status'] === 'pending'): ?>
      <form method="POST" style="display:inline;" onsubmit="return confirm('Submit this template to Meta for approval?')">
        <?= csrfField() ?><input type="hidden" name="action" value="submit_to_meta"><input type="hidden" name="template_id" value="<?= $t['id'] ?>">
        <button type="submit" class="btn btn-primary btn-sm" title="Submit to Meta for approval">📤 Submit to Meta</button>
      </form>
      <?php endif; ?>
      <form method="POST" onsubmit="return confirm('Delete?')" style="display:inline;"><?= csrfField() ?><input type="hidden" name="action" value="delete_template"><input type="hidden" name="template_id" value="<?= $t['id'] ?>"><button type="submit" class="btn btn-danger btn-sm">🗑</button></form>
    </div>
  </div>
  <div class="card-body">
    <!-- WhatsApp preview -->
    <div style="background:#071510;border-radius:12px;padding:14px;margin-bottom:10px;">
      <?php if ($t['header_type']!=='none' && $t['header_content']): ?>
        <?php if ($t['header_type']==='text'): ?>
          <div style="font-weight:700;font-size:13px;color:#e2ffd9;margin-bottom:7px;"><?= e($t['header_content']) ?></div>
        <?php else: ?>
          <div style="font-size:11px;color:#7ab59a;margin-bottom:7px;">[<?= strtoupper($t['header_type']) ?>]</div>
        <?php endif; ?>
      <?php endif; ?>
      <div style="font-size:13px;color:#e2ffd9;line-height:1.6;white-space:pre-wrap;word-break:break-word;"><?php
        $pb = htmlspecialchars($t['body']??'');
        $pb = preg_replace('/\{\{([^}]+)\}\}/','<span style="background:rgba(37,211,102,0.2);color:#7fffd4;padding:1px 4px;border-radius:3px;font-family:monospace;font-size:11px;">{{$1}}</span>',$pb);
        echo $pb;
      ?></div>
      <?php if ($t['footer']): ?><div style="font-size:11px;color:#7ab59a;margin-top:7px;border-top:1px solid rgba(255,255,255,0.08);padding-top:6px;"><?= e($t['footer']) ?></div><?php endif; ?>
      <?php if (!empty($btns)): ?>
      <div style="display:flex;flex-direction:column;gap:5px;margin-top:9px;border-top:1px solid rgba(255,255,255,0.08);padding-top:7px;">
        <?php foreach ($btns as $btn):
          $bi=match($btn['type']??''){'PHONE_NUMBER'=>'📞','URL'=>'🔗','QUICK_REPLY'=>'↩',default=>'•'};
        ?>
        <div style="background:rgba(255,255,255,0.06);border-radius:6px;padding:7px 12px;font-size:12px;color:#7dd9b5;text-align:center;">
          <?= $bi ?> <?= e($btn['text']??'') ?>
          <?php if (!empty($btn['phone_number'])): ?><span style="font-size:10px;color:#7ab59a;"> · <?= e($btn['phone_number']) ?></span><?php endif; ?>
          <?php if (!empty($btn['url'])): ?><span style="font-size:10px;color:#7ab59a;"> · <?= e(substr($btn['url'],0,30)) ?>…</span><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <?php if (!empty($vars)): ?>
    <div style="font-size:11px;color:var(--text2);margin-bottom:6px;">Variables: <?php foreach ($vars as $v): ?><span style="background:var(--accent-dim);color:var(--accent);padding:1px 6px;border-radius:4px;font-family:monospace;margin-right:3px;font-size:10px;">{{<?= e($v) ?>}}</span><?php endforeach; ?></div>
    <?php endif; ?>
    <div style="font-size:11px;color:var(--muted);"><?= timeAgo($t['created_at']) ?></div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Template Modal -->
<div class="modal-overlay" id="tplModal">
  <div class="modal" style="max-width:680px;width:100%;">
    <div class="modal-title" id="tplModalTitle">New Template</div>
    <div class="modal-sub">Templates need Meta approval before use in campaigns (usually 1-24 hrs).</div>
    <form method="POST" id="tplForm">
      <?= csrfField() ?>
      <input type="hidden" name="action" value="save_template">
      <input type="hidden" name="template_id" id="tplId" value="0">

      <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;">
        <div class="form-group">
          <label class="form-label">Name * <span style="font-weight:400;color:var(--muted);font-size:10px;">(lowercase_underscore)</span></label>
          <input type="text" name="template_name" id="tplName" class="form-control" placeholder="welcome_message" required oninput="this.value=this.value.toLowerCase().replace(/[^a-z0-9_]/g,'_')">
        </div>
        <div class="form-group">
          <label class="form-label">Category</label>
          <select name="category" id="tplCat" class="form-control">
            <option value="MARKETING">📢 Marketing</option>
            <option value="UTILITY">⚙️ Utility</option>
            <option value="AUTHENTICATION">🔐 Authentication</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Language</label>
          <select name="language" id="tplLang" class="form-control">
            <option value="en">English</option>
            <option value="en_US">English (US)</option>
            <option value="hi">Hindi</option>
            <option value="mr">Marathi</option>
            <option value="ar">Arabic</option>
            <option value="es">Spanish</option>
          </select>
        </div>
      </div>

      <!-- Header -->
      <div style="display:grid;grid-template-columns:150px 1fr;gap:12px;align-items:start;">
        <div class="form-group">
          <label class="form-label">Header</label>
          <select name="header_type" id="tplHtype" class="form-control" onchange="showHeader(this.value)">
            <option value="none">None</option>
            <option value="text">Text</option>
            <option value="image">Image</option>
            <option value="video">Video</option>
            <option value="document">Document</option>
          </select>
        </div>
        <div class="form-group" id="headerBox" style="display:none;">
          <label class="form-label" id="headerLabel">Header Content</label>

          <!-- Text header -->
          <input type="text" name="header_content" id="tplHcont" class="form-control" placeholder="Header text" oninput="renderPreview()" style="display:none;">

          <!-- Media upload -->
          <div id="headerUploadBox" style="display:none;">
            <div id="headerDropzone"
              style="border:2px dashed var(--border2);border-radius:10px;padding:20px;text-align:center;cursor:pointer;transition:all .2s;"
              onclick="document.getElementById('headerFileInput').click()"
              ondragover="event.preventDefault();this.style.borderColor='var(--accent)'"
              ondragleave="this.style.borderColor='var(--border2)'"
              ondrop="handleHeaderDrop(event)">
              <div id="headerDropContent">
                <div style="font-size:28px;margin-bottom:6px;" id="headerDropIcon">📁</div>
                <div style="font-size:13px;color:var(--text2);" id="headerDropText">Click or drag file here</div>
                <div style="font-size:11px;color:var(--muted);margin-top:4px;" id="headerDropHint">Image: JPG/PNG · Video: MP4 · Doc: PDF</div>
              </div>
              <div id="headerPreviewBox" style="display:none;">
                <img id="headerImgPreview" style="max-width:200px;max-height:120px;border-radius:8px;display:none;">
                <div id="headerFileInfo" style="font-size:12px;color:var(--accent);margin-top:6px;"></div>
                <button type="button" onclick="event.stopPropagation();clearHeaderMedia()" style="background:var(--danger-dim);border:1px solid rgba(255,77,109,0.3);color:var(--danger);border-radius:6px;padding:4px 10px;font-size:11px;cursor:pointer;margin-top:6px;">✕ Remove</button>
              </div>
            </div>
            <input type="file" id="headerFileInput" style="display:none;" onchange="handleHeaderFile(this.files[0])">
            <input type="hidden" name="header_content" id="tplHcontHidden">
            <div id="headerUploadStatus" style="font-size:12px;margin-top:6px;display:none;"></div>
          </div>
        </div>
      </div>

      <!-- Body -->
      <div class="form-group">
        <label class="form-label">
          Body * &nbsp;
          <span style="font-weight:400;color:var(--muted);font-size:11px;">Use {{1}}, {{2}} for variables. {{1}}=Name, {{2}}=Phone by default when sending.</span>
        </label>
        <textarea name="body" id="tplBody" class="form-control" rows="5"
          placeholder="Hello {{1}}, thank you for visiting *Bluee Ocean Restaurant*! &#10;&#10;We look forward to seeing you again. 😊"
          required oninput="renderPreview();detectVars(this.value)"></textarea>
        <div style="display:flex;justify-content:space-between;margin-top:4px;">
          <div style="font-size:11px;color:var(--text2);">
            Quick insert: <span onclick="insertVar('{{1}}')" style="cursor:pointer;color:var(--accent);font-family:monospace;background:var(--accent-dim);padding:1px 6px;border-radius:4px;margin-right:4px;">{{1}}</span>
            <span onclick="insertVar('{{2}}')" style="cursor:pointer;color:var(--accent);font-family:monospace;background:var(--accent-dim);padding:1px 6px;border-radius:4px;margin-right:4px;">{{2}}</span>
            <span onclick="insertVar('*bold*')" style="cursor:pointer;color:var(--text2);background:var(--surface3);padding:1px 6px;border-radius:4px;margin-right:4px;font-size:10px;">*bold*</span>
            <span onclick="insertVar('_italic_')" style="cursor:pointer;color:var(--text2);background:var(--surface3);padding:1px 6px;border-radius:4px;font-size:10px;font-style:italic;">_italic_</span>
          </div>
          <span id="charCount" style="font-size:11px;color:var(--muted);">0/1024</span>
        </div>
      </div>

      <!-- Variables info box -->
      <div id="varBox" style="display:none;background:rgba(37,211,102,0.04);border:1px solid rgba(37,211,102,0.2);border-radius:10px;padding:13px;margin-bottom:14px;">
        <div style="font-size:12px;font-weight:700;color:var(--accent);margin-bottom:8px;">⚡ Variables in this template</div>
        <div id="varList" style="display:flex;flex-direction:column;gap:6px;"></div>
        <div style="font-size:11px;color:var(--muted);margin-top:8px;">Auto-filled when sending: {{1}}=Contact Name · {{2}}=Phone Number</div>
      </div>

      <!-- Footer -->
      <div class="form-group">
        <label class="form-label">Footer <span style="font-weight:400;color:var(--muted);font-size:11px;">(optional)</span></label>
        <input type="text" name="footer" id="tplFooter" class="form-control" placeholder="Reply STOP to unsubscribe · Bluee Ocean Restaurant" oninput="renderPreview()">
      </div>

      <!-- Buttons -->
      <div style="margin-bottom:16px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:8px;">
          <label class="form-label" style="margin:0;">Buttons <span style="font-weight:400;color:var(--muted);font-size:11px;">(max 3) · Quick Reply · Call Phone · Visit URL</span></label>
          <button type="button" class="btn btn-secondary btn-sm" onclick="addBtn()">+ Add Button</button>
        </div>
        <div id="btnContainer" style="display:flex;flex-direction:column;gap:8px;"></div>
      </div>

      <!-- Live Preview -->
      <div style="margin-bottom:16px;">
        <label class="form-label">Live Preview</label>
        <div style="background:#071510;border:1px solid rgba(37,211,102,0.1);border-radius:12px;padding:14px;max-height:220px;overflow-y:auto;">
          <div id="pvHead" style="display:none;font-weight:700;font-size:13px;color:#e2ffd9;margin-bottom:8px;"></div>
          <div id="pvBody" style="font-size:13px;color:#e2ffd9;line-height:1.65;white-space:pre-wrap;word-break:break-word;">Your message will appear here…</div>
          <div id="pvFoot" style="display:none;font-size:11px;color:#7ab59a;margin-top:7px;border-top:1px solid rgba(255,255,255,0.08);padding-top:6px;"></div>
          <div id="pvBtns" style="display:none;margin-top:9px;border-top:1px solid rgba(255,255,255,0.08);padding-top:8px;display:flex;flex-direction:column;gap:5px;"></div>
        </div>
      </div>

      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('tplModal')">Cancel</button>
        <button type="submit" id="tplSaveBtn" class="btn btn-primary">💾 Save & Submit to Meta</button>
      </div>
    </form>
  </div>
</div>

<script>
function openTplModal() {
  document.getElementById('tplModalTitle').textContent='New Template';
  document.getElementById('tplId').value='0';
  ['tplName','tplBody','tplFooter','tplHcont'].forEach(id=>document.getElementById(id).value='');
  document.getElementById('tplHtype').value='none';
  document.getElementById('headerBox').style.display='none';
  document.getElementById('btnContainer').innerHTML='';
  document.getElementById('varBox').style.display='none';
  document.getElementById('tplSaveBtn').textContent='🚀 Save & Submit to Meta';
  renderPreview();
  openModal('tplModal');
}

function editTpl(t) {
  document.getElementById('tplModalTitle').textContent='Edit Template';
  document.getElementById('tplId').value=t.id;
  document.getElementById('tplName').value=t.template_name;
  document.getElementById('tplBody').value=t.body||'';
  document.getElementById('tplFooter').value=t.footer||'';
  document.getElementById('tplCat').value=t.category||'MARKETING';
  document.getElementById('tplLang').value=t.language||'en';
  document.getElementById('tplHtype').value=t.header_type||'none';
  document.getElementById('tplHcont').value=t.header_content||'';
  showHeader(t.header_type||'none');
  document.getElementById('btnContainer').innerHTML='';
  const btns=t.buttons?JSON.parse(t.buttons):[];
  btns.forEach(b=>addBtn(b.type,b.text,b.phone_number||b.url||''));
  detectVars(t.body||'');
  document.getElementById('tplSaveBtn').textContent='💾 Save Changes';
  renderPreview();
  openModal('tplModal');
}

function showHeader(v) {
  const box  = document.getElementById('headerBox');
  const txt  = document.getElementById('tplHcont');
  const upld = document.getElementById('headerUploadBox');
  const lbl  = document.getElementById('headerLabel');

  box.style.display = v !== 'none' ? 'block' : 'none';

  if (v === 'text') {
    txt.style.display  = 'block';
    upld.style.display = 'none';
    lbl.textContent    = 'Header Text';
    document.getElementById('headerDropIcon').textContent = '✏️';
    document.getElementById('headerDropText').textContent = 'Enter header text above';
  } else if (v === 'image') {
    txt.style.display  = 'none';
    upld.style.display = 'block';
    lbl.textContent    = 'Header Image';
    document.getElementById('headerDropIcon').textContent = '📷';
    document.getElementById('headerDropText').textContent = 'Click to upload image';
    document.getElementById('headerDropHint').textContent = 'JPG, PNG, WEBP — Max 5MB';
    document.getElementById('headerFileInput').accept = 'image/*';
  } else if (v === 'video') {
    txt.style.display  = 'none';
    upld.style.display = 'block';
    lbl.textContent    = 'Header Video';
    document.getElementById('headerDropIcon').textContent = '🎥';
    document.getElementById('headerDropText').textContent = 'Click to upload video';
    document.getElementById('headerDropHint').textContent = 'MP4, 3GP — Max 16MB';
    document.getElementById('headerFileInput').accept = 'video/*';
  } else if (v === 'document') {
    txt.style.display  = 'none';
    upld.style.display = 'block';
    lbl.textContent    = 'Header Document';
    document.getElementById('headerDropIcon').textContent = '📄';
    document.getElementById('headerDropText').textContent = 'Click to upload document';
    document.getElementById('headerDropHint').textContent = 'PDF, DOC, DOCX — Max 100MB';
    document.getElementById('headerFileInput').accept = '.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx';
  }
  renderPreview();
}

function handleHeaderDrop(e) {
  e.preventDefault();
  document.getElementById('headerDropzone').style.borderColor = 'var(--border2)';
  const file = e.dataTransfer.files[0];
  if (file) handleHeaderFile(file);
}

function handleHeaderFile(file) {
  if (!file) return;
  const status = document.getElementById('headerUploadStatus');
  const previewBox = document.getElementById('headerPreviewBox');
  const dropContent = document.getElementById('headerDropContent');
  const fileInfo = document.getElementById('headerFileInfo');

  // Show preview immediately for images
  if (file.type.startsWith('image/')) {
    const reader = new FileReader();
    reader.onload = e => {
      const img = document.getElementById('headerImgPreview');
      img.src = e.target.result;
      img.style.display = 'block';
    };
    reader.readAsDataURL(file);
  }

  fileInfo.textContent = file.name + ' (' + (file.size/1024/1024).toFixed(2) + 'MB)';
  dropContent.style.display = 'none';
  previewBox.style.display = 'block';

  // Upload to WhatsApp
  status.style.display = 'block';
  status.style.color = 'var(--accent)';
  status.textContent = '⏳ Uploading to WhatsApp...';

  const fd = new FormData();
  fd.append('action', 'upload_header_media');
  fd.append('file', file);
  fd.append('csrf_token', window.CSRF_TOKEN);

  fetch(location.href, { method: 'POST', body: fd })
    .then(r => r.json())
    .then(data => {
      if (data.success) {
        document.getElementById('tplHcontHidden').value = data.media_id;
        document.getElementById('tplHcont').value = data.media_id;
        status.style.color = 'var(--accent)';
        status.textContent = '✅ Uploaded! Media ID: ' + data.media_id.substring(0,20) + '...';
      } else {
        status.style.color = 'var(--danger)';
        status.textContent = '❌ Upload failed: ' + (data.error || 'Unknown error');
      }
    })
    .catch(e => {
      status.style.color = 'var(--danger)';
      status.textContent = '❌ Error: ' + e.message;
    });
}

function clearHeaderMedia() {
  document.getElementById('headerPreviewBox').style.display = 'none';
  document.getElementById('headerDropContent').style.display = 'block';
  document.getElementById('tplHcontHidden').value = '';
  document.getElementById('tplHcont').value = '';
  document.getElementById('headerImgPreview').src = '';
  document.getElementById('headerUploadStatus').style.display = 'none';
  document.getElementById('headerFileInput').value = '';
}

function addBtn(type='QUICK_REPLY',text='',value='') {
  if (document.querySelectorAll('.btn-row').length>=3){alert('Max 3 buttons');return;}
  const d=document.createElement('div');
  d.className='btn-row';
  d.style.cssText='display:flex;gap:8px;align-items:center;background:var(--surface2);border:1px solid var(--border);border-radius:9px;padding:10px;';
  d.innerHTML=`
    <select name="btn_type[]" class="form-control" style="width:145px;" onchange="toggleBtnVal(this)">
      <option value="QUICK_REPLY" ${type==='QUICK_REPLY'?'selected':''}>↩ Quick Reply</option>
      <option value="PHONE_NUMBER" ${type==='PHONE_NUMBER'?'selected':''}>📞 Call Phone</option>
      <option value="URL" ${type==='URL'?'selected':''}>🔗 Visit URL</option>
    </select>
    <input type="text" name="btn_text[]" class="form-control" placeholder="Button text" value="${text}" oninput="renderPreview()">
    <input type="text" name="btn_value[]" class="btn-val form-control"
      placeholder="${type==='URL'?'https://...':type==='PHONE_NUMBER'?'91XXXXXXXXXX':''}"
      value="${value}" style="display:${type==='QUICK_REPLY'?'none':'block'};" oninput="renderPreview()">
    <button type="button" onclick="this.closest('.btn-row').remove();renderPreview();"
      style="background:var(--danger-dim);border:1px solid rgba(255,77,109,0.3);color:var(--danger);border-radius:7px;padding:7px 11px;cursor:pointer;font-size:13px;flex-shrink:0;">✕</button>`;
  document.getElementById('btnContainer').appendChild(d);
  renderPreview();
}

function toggleBtnVal(sel) {
  const row=sel.closest('.btn-row');
  const vi=row.querySelector('.btn-val');
  vi.style.display=sel.value==='QUICK_REPLY'?'none':'block';
  vi.placeholder=sel.value==='URL'?'https://...':sel.value==='PHONE_NUMBER'?'91XXXXXXXXXX':'';
  renderPreview();
}

function detectVars(body) {
  const nv=[...new Set([...body.matchAll(/\{\{(\d+)\}\}/g)].map(m=>parseInt(m[1])))].sort((a,b)=>a-b);
  const namedV=[...new Set([...body.matchAll(/\{\{([a-zA-Z_][a-zA-Z0-9_]*)\}\}/g)].map(m=>m[1]))];
  const vars=nv.length>0?nv.map(String):namedV;
  const vb=document.getElementById('varBox');
  const vl=document.getElementById('varList');
  if (!vars.length){vb.style.display='none';return;}
  vb.style.display='block';
  const defs={'1':'Contact Name (auto)','2':'Phone Number (auto)','3':'Custom value','4':'Custom value'};
  vl.innerHTML=vars.map((v,i)=>`
    <div style="display:flex;align-items:center;gap:10px;">
      <span style="background:var(--accent-dim);color:var(--accent);padding:3px 9px;border-radius:6px;font-family:monospace;font-size:12px;font-weight:700;white-space:nowrap;">{{${v}}}</span>
      <span style="font-size:12px;color:var(--text2);">${defs[v]||'Custom — set when creating campaign'}</span>
    </div>`).join('');
}

function insertVar(v) {
  const ta=document.getElementById('tplBody');
  const s=ta.selectionStart, e=ta.selectionEnd;
  ta.value=ta.value.substring(0,s)+v+ta.value.substring(e);
  ta.selectionStart=ta.selectionEnd=s+v.length;
  ta.focus();
  renderPreview();detectVars(ta.value);
}

function renderPreview() {
  const body=document.getElementById('tplBody').value;
  const footer=document.getElementById('tplFooter').value;
  const htype=document.getElementById('tplHtype').value;
  const hcont=document.getElementById('tplHcont').value;

  document.getElementById('charCount').textContent=body.length+'/1024';

  const ph=document.getElementById('pvHead');
  if (htype==='text'&&hcont){ph.textContent=hcont;ph.style.display='block';}
  else if (htype!=='none'&&hcont){ph.textContent='['+htype.toUpperCase()+' Attachment]';ph.style.display='block';}
  else ph.style.display='none';

  document.getElementById('pvBody').innerHTML=body.replace(/\{\{([^}]+)\}\}/g,'<span style="background:rgba(37,211,102,0.2);color:#7fffd4;padding:1px 4px;border-radius:3px;font-family:monospace;font-size:11px;">{{$1}}</span>').replace(/\*(.*?)\*/g,'<strong>$1</strong>').replace(/_(.*?)_/g,'<em>$1</em>')||'<span style="color:#7ab59a;">Your message will appear here…</span>';

  const pf=document.getElementById('pvFoot');
  if (footer){pf.textContent=footer;pf.style.display='block';}else pf.style.display='none';

  const pb2=document.getElementById('pvBtns');
  const rows=document.querySelectorAll('.btn-row');
  if (rows.length>0){
    pb2.innerHTML='';pb2.style.display='flex';
    const icons={QUICK_REPLY:'↩',PHONE_NUMBER:'📞',URL:'🔗'};
    rows.forEach(r=>{
      const type=r.querySelector('select').value;
      const text=r.querySelector('[name="btn_text[]"]').value||'Button';
      const val=r.querySelector('.btn-val').value;
      const d=document.createElement('div');
      d.style.cssText='background:rgba(255,255,255,0.06);border-radius:6px;padding:7px 12px;font-size:12px;color:#7dd9b5;text-align:center;';
      d.textContent=(icons[type]||'•')+' '+text+(val?' · '+val.substring(0,25):'');
      pb2.appendChild(d);
    });
  } else pb2.style.display='none';
}

renderPreview();
</script>

<?php include __DIR__ . '/../includes/user_footer.php'; ?>