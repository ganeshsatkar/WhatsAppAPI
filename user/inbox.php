<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/WhatsAppService.php';
require_once __DIR__ . '/../includes/wallet.php';

requireLogin();
$pageTitle = 'Inbox';
$uid  = currentUser()['id'];
$user = currentUser();

// Message query shared by every action: sender name + the text of the message being quoted (if any).
const MSG_SELECT = 'SELECT m.*,u.name AS sender_name,(SELECT q.content FROM messages q WHERE q.whatsapp_message_id=m.reply_to_wamid AND q.conversation_id=m.conversation_id LIMIT 1) AS quoted_text
                    FROM messages m LEFT JOIN users u ON u.id=m.sent_by';

function ownConv(int $id, int $uid): ?array {
    return db()->row('SELECT * FROM conversations WHERE id=? AND user_id=?', [$id, $uid]);
}
function windowInfo(int $convId): array {
    $left = conversationWindowSeconds($convId);
    return ['open' => $left > 0, 'seconds_left' => $left];
}

// ── AJAX handlers ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';

    if ($action === 'send_message') {
        $convId = (int)$_POST['conv_id'];
        $text   = trim($_POST['message'] ?? '');
        $conv   = db()->row('SELECT * FROM conversations WHERE id=? AND user_id=?', [$convId, $uid]);
        if (!$conv || !$text) { echo json_encode(['success'=>false,'error'=>'Invalid conv or empty message']); exit; }

        // WhatsApp only allows free-form replies within 24h of the customer's last message.
        if (!conversationWindowOpen($convId)) {
            echo json_encode(['success'=>false,'window_closed'=>true,'error'=>'24-hour window closed — the customer has not messaged in the last 24h. Send an approved template instead.']);
            exit;
        }

        $phone = normalizePhone($conv['phone']);

        // Optional quoted reply (WhatsApp "reply to this message")
        $replyWamid = null;
        if (!empty($_POST['reply_to'])) {
            $q = db()->row('SELECT whatsapp_message_id FROM messages WHERE id=? AND conversation_id=?', [(int)$_POST['reply_to'], $convId]);
            $replyWamid = $q['whatsapp_message_id'] ?? null;
        }

        $wa  = WhatsAppService::forUser($uid);
        $res = ['success'=>false,'error'=>'WhatsApp API not connected. Go to Settings and connect your API.','message_id'=>null];
        if ($wa) $res = $wa->sendText($phone, $text, $replyWamid);

        $status = $res['success'] ? 'sent' : 'failed';
        $mid = db()->insert(
            'INSERT INTO messages (conversation_id,user_id,direction,message_type,content,whatsapp_message_id,reply_to_wamid,status,error_message,sent_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())',
            [$convId,$uid,'outbound','text',$text,$res['message_id']??null,$replyWamid,$status,$res['success']?null:mb_substr((string)($res['error']??''),0,500),$uid]
        );
        db()->run('UPDATE conversations SET last_message_at=NOW(),last_message_preview=? WHERE id=?',[mb_substr($text,0,100),$convId]);
        $msg = db()->row(MSG_SELECT.' WHERE m.id=?',[$mid]);
        echo json_encode(['success'=>true,'message'=>$msg,'wa_ok'=>$res['success'],'wa_err'=>$res['error']??null]);
        exit;
    }

    if ($action === 'send_template') {
        $convId = (int)$_POST['conv_id'];
        $tplId  = (int)$_POST['tpl_id'];
        $conv   = db()->row('SELECT * FROM conversations WHERE id=? AND user_id=?',[$convId,$uid]);
        $tpl    = db()->row('SELECT * FROM message_templates WHERE id=? AND user_id=?',[$tplId,$uid]);
        if (!$conv||!$tpl){echo json_encode(['success'=>false]);exit;}
        if (($tpl['status'] ?? '') !== 'approved') {
            echo json_encode(['success'=>false,'error'=>'This template is not approved by WhatsApp yet.']); exit;
        }
        $wa = WhatsAppService::forUser($uid);
        if (!$wa) { echo json_encode(['success'=>false,'error'=>'WhatsApp API not connected. Go to Settings and connect your API.']); exit; }

        // Template messages are billed per message from the wallet.
        $category = $tpl['category'] ?: 'UTILITY';
        if (!canAffordMessages($uid, 1, $category)) {
            echo json_encode(['success'=>false,'error'=>'Insufficient wallet balance for a '.strtolower($category).' message ('.inr(msgPrice($uid,$category)).'). Recharge your wallet first.']); exit;
        }
        $contact = db()->row('SELECT name FROM contacts WHERE id=?', [$conv['contact_id']]);
        $phone   = normalizePhone($conv['phone']);
        $comps   = buildTemplateComponents($tpl, $contact['name'] ?? '', $phone);
        $res     = $wa->sendTemplate($phone, $tpl['template_name'], $tpl['language'] ?? 'en', $comps);
        if ($res['success']) debitWallet($uid, 1, $category, (int)$convId, 'inbox_template');
        $mid=db()->insert('INSERT INTO messages (conversation_id,user_id,direction,message_type,content,whatsapp_message_id,status,error_message,sent_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())',
            [$convId,$uid,'outbound','template','[Template: '.$tpl['template_name'].']',$res['message_id']??null,$res['success']?'sent':'failed',$res['success']?null:mb_substr((string)($res['error']??''),0,500),$uid]);
        db()->run('UPDATE conversations SET last_message_at=NOW(),last_message_preview=? WHERE id=?',['[Template sent]',$convId]);
        $msg=db()->row('SELECT * FROM messages WHERE id=?',[$mid]);
        echo json_encode(['success'=>true,'wa_ok'=>$res['success'],'wa_err'=>$res['error']??null,'message'=>$msg]);
        exit;
    }

    if ($action === 'send_media') {
        $convId  = (int)$_POST['conv_id'];
        $mediaType = in_array($_POST['media_type']??'', ['image','video','document','audio']) ? $_POST['media_type'] : 'image';
        $mediaUrl  = sanitize($_POST['media_url'] ?? '');
        $caption   = sanitize($_POST['caption'] ?? '');
        $conv = db()->row('SELECT * FROM conversations WHERE id=? AND user_id=?', [$convId, $uid]);
        if (!$conv || !$mediaUrl) { echo json_encode(['success'=>false,'error'=>'Invalid']); exit; }
        if (!filter_var($mediaUrl, FILTER_VALIDATE_URL) || !preg_match('#^https://#i', $mediaUrl)) { echo json_encode(['success'=>false,'error'=>'Enter a valid https:// media URL']); exit; }

        if (!conversationWindowOpen($convId)) {
            echo json_encode(['success'=>false,'window_closed'=>true,'error'=>'24-hour window closed — send an approved template instead.']); exit;
        }
        $phone = normalizePhone($conv['phone']);

        $wa  = WhatsAppService::forUser($uid);
        $res = ['success'=>false,'error'=>'API not connected'];
        if ($wa) $res = $wa->sendMediaUrl($phone, $mediaType, $mediaUrl, $caption);

        $icons = ['image'=>'📷','video'=>'🎥','document'=>'📄','audio'=>'🎵'];
        $label = ($icons[$mediaType]??'📎') . ' ' . ucfirst($mediaType) . ($caption?" — $caption":'');
        $mid = db()->insert('INSERT INTO messages (conversation_id,user_id,direction,message_type,content,media_url,whatsapp_message_id,status,error_message,sent_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())',
            [$convId,$uid,'outbound',$mediaType,$label,$mediaUrl,$res['message_id']??null,$res['success']?'sent':'failed',$res['success']?null:mb_substr((string)($res['error']??''),0,500),$uid]);
        db()->run('UPDATE conversations SET last_message_at=NOW(),last_message_preview=? WHERE id=?',[$label,$convId]);
        $msg = db()->row('SELECT m.*,u.name as sender_name FROM messages m LEFT JOIN users u ON u.id=m.sent_by WHERE m.id=?',[$mid]);
        echo json_encode(['success'=>true,'message'=>$msg,'wa_ok'=>$res['success'],'wa_err'=>$res['error']??null]);
        exit;
    }

    if ($action === 'upload_media') {
        // Upload file to WhatsApp and return media_id
        $file = $_FILES['file'] ?? null;
        if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) { echo json_encode(['success'=>false,'error'=>'Upload failed']); exit; }
        // Detect the real type on the server (the browser-sent type can be faked); WhatsApp limits in MB
        $mime   = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
        $limits = ['image/jpeg'=>5,'image/png'=>5,'image/webp'=>5,'video/mp4'=>16,'video/3gpp'=>16,'audio/aac'=>16,'audio/mp4'=>16,'audio/mpeg'=>16,'audio/amr'=>16,'audio/ogg'=>16,
            'application/pdf'=>100,'application/msword'=>100,'application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>100,
            'application/vnd.ms-excel'=>100,'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'=>100,
            'application/vnd.ms-powerpoint'=>100,'application/vnd.openxmlformats-officedocument.presentationml.presentation'=>100,'text/plain'=>100];
        if (!isset($limits[$mime])) { echo json_encode(['success'=>false,'error'=>'File type not supported by WhatsApp ('.$mime.')']); exit; }
        if ($file['size'] > $limits[$mime] * 1024 * 1024) { echo json_encode(['success'=>false,'error'=>'File too large (max '.$limits[$mime].' MB for this type)']); exit; }
        $wa = WhatsAppService::forUser($uid);
        if (!$wa) { echo json_encode(['success'=>false,'error'=>'API not connected']); exit; }
        $res      = $wa->uploadMedia($file['tmp_name'], $mime);
        echo json_encode($res);
        exit;
    }

    if ($action === 'send_media_id') {
        $convId   = (int)$_POST['conv_id'];
        $mediaType= in_array($_POST['media_type']??'',['image','video','document','audio'])?$_POST['media_type']:'image';
        $mediaId  = sanitize($_POST['media_id'] ?? '');
        $caption  = sanitize($_POST['caption'] ?? '');
        $filename = sanitize($_POST['filename'] ?? '');
        $conv     = db()->row('SELECT * FROM conversations WHERE id=? AND user_id=?', [$convId, $uid]);
        if (!$conv || !$mediaId) { echo json_encode(['success'=>false,'error'=>'Invalid']); exit; }
        if (!conversationWindowOpen($convId)) {
            echo json_encode(['success'=>false,'window_closed'=>true,'error'=>'24-hour window closed — send an approved template instead.']); exit;
        }
        $phone = normalizePhone($conv['phone']);
        $wa  = WhatsAppService::forUser($uid);
        $res = ['success'=>false,'error'=>'API not connected'];
        if ($wa) $res = $wa->sendMediaId($phone, $mediaType, $mediaId, $caption);
        $icons=['image'=>'📷','video'=>'🎥','document'=>'📄','audio'=>'🎵'];
        $label=($icons[$mediaType]??'📎').' '.ucfirst($mediaType).($filename?" ($filename)":'').($caption?" — $caption":'');
        $mid=db()->insert('INSERT INTO messages (conversation_id,user_id,direction,message_type,content,whatsapp_message_id,media_id,status,error_message,sent_by,created_at) VALUES (?,?,?,?,?,?,?,?,?,?,NOW())',
            [$convId,$uid,'outbound',$mediaType,$label,$res['message_id']??null,$mediaId,$res['success']?'sent':'failed',$res['success']?null:mb_substr((string)($res['error']??''),0,500),$uid]);
        db()->run('UPDATE conversations SET last_message_at=NOW(),last_message_preview=? WHERE id=?',[$label,$convId]);
        $msg=db()->row('SELECT m.*,u.name as sender_name FROM messages m LEFT JOIN users u ON u.id=m.sent_by WHERE m.id=?',[$mid]);
        echo json_encode(['success'=>true,'message'=>$msg,'wa_ok'=>$res['success'],'wa_err'=>$res['error']??null]);
        exit;
    }

    if ($action === 'send_note') {
        $convId=(int)$_POST['conv_id'];
        $note=trim($_POST['note']??'');
        if($note&&$convId&&ownConv($convId,$uid)){
            $mid=db()->insert('INSERT INTO messages (conversation_id,user_id,direction,message_type,content,is_note,status,sent_by,created_at) VALUES (?,?,?,?,?,1,?,?,NOW())',
                [$convId,$uid,'outbound','text',$note,'sent',$uid]);
            $msg=db()->row('SELECT m.*,u.name as sender_name FROM messages m LEFT JOIN users u ON u.id=m.sent_by WHERE m.id=?',[$mid]);
            echo json_encode(['success'=>true,'message'=>$msg]);
        } else echo json_encode(['success'=>false]);
        exit;
    }

    if ($action === 'new_chat') {
        $phone=normalizePhone($_POST['phone']??'');
        $name=mb_substr(sanitize($_POST['name']??''),0,150)?:'Customer';
        if(!isValidPhone($phone)){echo json_encode(['success'=>false,'error'=>'Invalid phone number (add country code for non-Indian numbers)']);exit;}
        $vars=phoneVariants($phone);
        $ct=db()->row('SELECT * FROM contacts WHERE user_id=? AND phone IN ('.implode(',',array_fill(0,count($vars),'?')).') LIMIT 1',array_merge([$uid],$vars));
        if(!$ct){
            $max=(int)(currentPlan()['max_contacts'] ?? -1);
            if(!isSuperAdmin() && $max>=0 && db()->count('SELECT COUNT(*) FROM contacts WHERE user_id=?',[$uid])>=$max){echo json_encode(['success'=>false,'error'=>'Contact limit of your plan reached. Upgrade to add more.']);exit;}
            $cid=db()->insert('INSERT INTO contacts (user_id,name,phone,opt_in,source) VALUES (?,?,?,1,?)',[$uid,$name,$phone,'manual']);$ct=db()->row('SELECT * FROM contacts WHERE id=?',[$cid]);
        }
        $phone=$ct['phone']; $name=$ct['name'];
        $cv=db()->row('SELECT * FROM conversations WHERE user_id=? AND contact_id=?',[$uid,$ct['id']]);
        if(!$cv){$cvid=db()->insert('INSERT INTO conversations (user_id,contact_id,phone,status,is_bot_active,created_at) VALUES (?,?,?,?,0,NOW())',[$uid,$ct['id'],$phone,'open']);} else $cvid=$cv['id'];
        echo json_encode(['success'=>true,'conv_id'=>$cvid,'name'=>$name,'phone'=>$phone]);
        exit;
    }

    if ($action === 'load_conv') {
        $convId=(int)$_POST['conv_id'];
        $conv=db()->row('SELECT cv.*,c.name as contact_name,c.phone,c.email,c.opt_in FROM conversations cv JOIN contacts c ON c.id=cv.contact_id WHERE cv.id=? AND cv.user_id=?',[$convId,$uid]);
        if(!$conv){echo json_encode(['success'=>false]);exit;}
        // Newest 100 messages (oldest first for display); older ones load on demand.
        $msgs=db()->all('SELECT * FROM ('.MSG_SELECT.' WHERE m.conversation_id=? ORDER BY m.id DESC LIMIT 100) t ORDER BY t.id ASC',[$convId]);
        $total=db()->count('SELECT COUNT(*) FROM messages WHERE conversation_id=?',[$convId]);
        $first=db()->row("SELECT direction FROM messages WHERE conversation_id=? AND is_note=0 AND message_type<>'call' ORDER BY id ASC LIMIT 1",[$convId]);
        $tags=db()->all('SELECT tag FROM conversation_tags WHERE conversation_id=?',[$convId]);
        db()->run('UPDATE conversations SET unread_count=0 WHERE id=?',[$convId]);
        echo json_encode(['success'=>true,'conv'=>$conv,'messages'=>$msgs,'tags'=>array_column($tags,'tag'),
            'has_more'=>$total>count($msgs),'window'=>windowInfo($convId),'initiated_by'=>($first['direction']??'inbound')==='inbound'?'customer':'business']);
        exit;
    }

    if ($action === 'load_older') {
        $convId=(int)$_POST['conv_id']; $before=(int)$_POST['before_id'];
        if(!ownConv($convId,$uid)){echo json_encode(['messages'=>[],'has_more'=>false]);exit;}
        $msgs=db()->all('SELECT * FROM ('.MSG_SELECT.' WHERE m.conversation_id=? AND m.id<? ORDER BY m.id DESC LIMIT 50) t ORDER BY t.id ASC',[$convId,$before]);
        $more=$msgs?db()->count('SELECT COUNT(*) FROM messages WHERE conversation_id=? AND id<?',[$convId,(int)$msgs[0]['id']])>0:false;
        echo json_encode(['messages'=>$msgs,'has_more'=>$more]);
        exit;
    }

    if ($action === 'poll') {
        $convId=(int)$_POST['conv_id'];
        $after=(int)$_POST['after_id'];
        if(!ownConv($convId,$uid)){echo json_encode(['messages'=>[]]);exit;}     // was readable by any logged-in user
        $msgs=db()->all(MSG_SELECT.' WHERE m.conversation_id=? AND m.id>? ORDER BY m.id ASC LIMIT 100',[$convId,$after]);
        if($msgs){ db()->run('UPDATE conversations SET unread_count=0 WHERE id=?',[$convId]); }   // agent is looking at it
        // Live ticks / reactions / failures for recent bubbles that are already on screen
        $upd=db()->all('SELECT id,status,reaction,error_message,content FROM messages WHERE conversation_id=? AND id<=? ORDER BY id DESC LIMIT 60',[$convId,$after]);
        echo json_encode(['messages'=>$msgs,'updates'=>$upd,'window'=>windowInfo($convId)]);
        exit;
    }

    if ($action === 'poll_conv_list') {
        $convs = db()->all('SELECT cv.id, cv.contact_id, cv.status, cv.unread_count, cv.last_message_at,
            c.name as contact_name, c.phone,
            (SELECT content FROM messages WHERE conversation_id=cv.id ORDER BY id DESC LIMIT 1) as last_content,
            (SELECT created_at FROM messages WHERE conversation_id=cv.id ORDER BY id DESC LIMIT 1) as last_msg_time,
            (SELECT direction FROM messages WHERE conversation_id=cv.id ORDER BY id DESC LIMIT 1) as last_dir,
            (SELECT message_type FROM messages WHERE conversation_id=cv.id ORDER BY id DESC LIMIT 1) as last_type
            FROM conversations cv
            JOIN contacts c ON c.id=cv.contact_id
            WHERE cv.user_id=?
            ORDER BY cv.last_message_at DESC LIMIT 50', [$uid]);
        echo json_encode(['success'=>true, 'conversations'=>$convs]);
        exit;
    }

    if ($action === 'set_status') {
        $convId=(int)$_POST['conv_id'];
        $s=in_array($_POST['status']??'',['open','resolved','pending','snoozed'],true)?$_POST['status']:'open';
        db()->run('UPDATE conversations SET status=? WHERE id=? AND user_id=?',[$s,$convId,$uid]);
        echo json_encode(['success'=>true]);
        exit;
    }

    if ($action === 'toggle_bot') {
        $convId=(int)$_POST['conv_id'];
        $c=ownConv($convId,$uid);
        if(!$c){echo json_encode(['success'=>false]);exit;}
        $curr=(int)$c['is_bot_active'];
        db()->run('UPDATE conversations SET is_bot_active=? WHERE id=? AND user_id=?',[$curr?0:1,$convId,$uid]);
        echo json_encode(['success'=>true,'bot_active'=>!$curr]);
        exit;
    }

    if ($action === 'add_tag') {
        $convId=(int)$_POST['conv_id'];
        $tag=trim(sanitize($_POST['tag']??''));
        if($tag&&ownConv($convId,$uid)&&!db()->count('SELECT COUNT(*) FROM conversation_tags WHERE conversation_id=? AND tag=?',[$convId,$tag]))
            db()->run('INSERT INTO conversation_tags (conversation_id,tag) VALUES (?,?)',[$convId,$tag]);
        echo json_encode(['success'=>true]);
        exit;
    }

    if ($action === 'assign') {
        $convId=(int)$_POST['conv_id'];
        $to=(int)$_POST['assign_to'];
        // only yourself or one of your active team members
        if($to && $to!==$uid && !db()->count('SELECT COUNT(*) FROM team_members WHERE user_id=? AND member_user_id=? AND is_active=1',[$uid,$to])) $to=0;
        db()->run('UPDATE conversations SET assigned_to=? WHERE id=? AND user_id=?',[$to?:null,$convId,$uid]);
        echo json_encode(['success'=>true]);
        exit;
    }

    echo json_encode(['success'=>false]);
    exit;
}

// ── Load sidebar convs ────────────────────────────────────
$filter = sanitize($_GET['f'] ?? 'all');
$search = sanitize($_GET['q'] ?? '');
$where  = 'WHERE cv.user_id=?'; $params=[$uid];
if ($filter==='open')     $where.=" AND cv.status='open'";
if ($filter==='resolved') $where.=" AND cv.status='resolved'";
if ($filter==='pending')  $where.=" AND cv.status='pending'";
if ($filter==='unread')   $where.=" AND cv.unread_count>0";
if ($filter==='bot_off')  $where.=" AND cv.is_bot_active=0";
if ($search){$where.=" AND (c.name LIKE ? OR c.phone LIKE ?)";$s="%$search%";$params[]=$s;$params[]=$s;}

$convs = db()->all("SELECT cv.*,c.name as contact_name,c.phone FROM conversations cv JOIN contacts c ON c.id=cv.contact_id $where ORDER BY cv.last_message_at DESC LIMIT 80",$params);
$counts = [
    'all'      => db()->count("SELECT COUNT(*) FROM conversations WHERE user_id=?",[$uid]),
    'open'     => db()->count("SELECT COUNT(*) FROM conversations WHERE user_id=? AND status='open'",[$uid]),
    'unread'   => db()->count("SELECT COUNT(*) FROM conversations WHERE user_id=? AND unread_count>0",[$uid]),
    'pending'  => db()->count("SELECT COUNT(*) FROM conversations WHERE user_id=? AND status='pending'",[$uid]),
    'resolved' => db()->count("SELECT COUNT(*) FROM conversations WHERE user_id=? AND status='resolved'",[$uid]),
];
$templates  = db()->all("SELECT id,template_name,category,body FROM message_templates WHERE user_id=? AND status='approved' ORDER BY template_name ASC",[$uid]);
$teamMembers= db()->all("SELECT u.id,u.name FROM team_members tm JOIN users u ON u.id=tm.member_user_id WHERE tm.user_id=? AND tm.is_active=1",[$uid]);
$waOk       = db()->row('SELECT is_connected FROM user_whatsapp_settings WHERE user_id=? AND is_connected=1',[$uid]);

// Quick replies are managed per account in Auto-replies; these defaults show until the account adds its own.
$quickReplies = db()->all('SELECT title, body FROM quick_replies WHERE user_id=? ORDER BY sort_order, id', [$uid]);
if (!$quickReplies) {
    foreach (['Hi {name}! How can I help you today? 😊', 'Thank you for reaching out to us!', 'We will get back to you shortly.', 'Please share more details so we can assist you better.', 'Your query has been noted. We\'ll follow up soon.'] as $d)
        $quickReplies[] = ['title' => mb_substr($d, 0, 40), 'body' => $d];
}

include __DIR__ . '/../includes/user_header.php';
?>
<style>
@media(min-width: 769px) {
  html, body { height: 100%; overflow: hidden; }
  .main-wrap { height: 100vh; overflow: hidden; display: flex; flex-direction: column; }
  .topbar { flex-shrink: 0; }
  .page-content { flex: 1; min-height: 0; padding: 10px 18px 12px !important; overflow: hidden !important; display: flex !important; flex-direction: column !important; }
  .ib { flex: 1; min-height: 0; height: 100% !important; margin: 0 !important; }
}

.ib{display:grid;grid-template-columns:296px 1fr 268px;height:calc(100vh - 100px);min-height:500px;border:1px solid var(--border);border-radius:14px;overflow:hidden;margin-top:-4px;background:var(--bg);}

/* LEFT */
.il{display:flex;flex-direction:column;background:var(--sidebar);border-right:1px solid var(--border);}
.il-top{padding:12px;border-bottom:1px solid var(--border);flex-shrink:0;}
.il-search{display:flex;align-items:center;gap:7px;background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:7px 11px;margin-bottom:8px;}
.il-search input{background:none;border:none;outline:none;color:var(--text);font-family:'Cabinet Grotesk',sans-serif;font-size:13px;flex:1;min-width:0;}
.il-search input::placeholder{color:var(--muted);}
.ftabs{display:flex;gap:3px;overflow-x:auto;scrollbar-width:none;}
.ftabs::-webkit-scrollbar{display:none;}
.ft{padding:5px 10px;border-radius:20px;font-size:11px;font-weight:700;cursor:pointer;white-space:nowrap;border:1px solid transparent;color:var(--muted);background:none;font-family:'Cabinet Grotesk',sans-serif;transition:all .15s;}
.ft:hover{color:var(--text2);}
.ft.on{background:var(--accent-dim);color:var(--accent);border-color:rgba(37,211,102,0.3);}
.il-list{flex:1;overflow-y:auto;scrollbar-width:thin;scrollbar-color:var(--surface3) transparent;}
.ci{display:flex;align-items:center;gap:9px;padding:11px 13px;border-bottom:1px solid var(--border);cursor:pointer;transition:background .1s;position:relative;}
.ci:hover{background:var(--surface2);}
.ci.sel{background:rgba(37,211,102,0.07);border-left:2px solid var(--accent);}
.ci-av{width:38px;height:38px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:800;color:#000;flex-shrink:0;}
.ci-body{flex:1;min-width:0;}
.ci-name{font-size:13px;font-weight:600;color:var(--text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.ci.unread .ci-name{font-weight:800;color:#fff;}
.ci-prev{font-size:11.5px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:1px;}
.ci-right{display:flex;flex-direction:column;align-items:flex-end;gap:3px;flex-shrink:0;}
.ci-time{font-size:10px;color:var(--muted);}
.ci-badge{background:var(--accent);color:#000;font-size:9px;font-weight:800;min-width:17px;height:17px;border-radius:10px;display:flex;align-items:center;justify-content:center;padding:0 4px;}
.ci-bot-off{width:6px;height:6px;border-radius:50%;background:var(--orange);position:absolute;top:11px;right:10px;}
.ci-status-dot{width:7px;height:7px;border-radius:50%;flex-shrink:0;}
.new-btn{display:flex;align-items:center;justify-content:center;gap:6px;width:100%;padding:9px;background:var(--accent);border:none;border-radius:8px;color:#000;font-family:'Cabinet Grotesk',sans-serif;font-size:13px;font-weight:800;cursor:pointer;margin-bottom:9px;transition:opacity .15s;}
.new-btn:hover{opacity:.88;}

/* MIDDLE */
.im{display:flex;flex-direction:column;background:var(--bg);overflow:hidden;min-height:0;}
.im-header{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid var(--border);background:var(--sidebar);flex-shrink:0;}
.im-hinfo{flex:1 1 170px;min-width:150px;overflow:hidden;}
.im-hname{font-weight:800;font-size:14px;color:#fff;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.im-hmeta{font-size:11px;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.im-hbtns{display:flex;gap:5px;flex-wrap:wrap;justify-content:flex-end;flex:0 1 auto;max-width:68%;}
.hb{padding:5px 11px;border-radius:7px;font-size:12px;font-weight:700;cursor:pointer;border:1px solid var(--border);color:var(--text2);background:var(--surface2);font-family:'Cabinet Grotesk',sans-serif;transition:all .15s;white-space:nowrap;}
.hb:hover{border-color:var(--accent);color:var(--accent);}
.hb.on{background:var(--accent-dim);border-color:rgba(37,211,102,0.3);color:var(--accent);}
.hb.res{color:var(--danger);}
.hb.res:hover{border-color:var(--danger);}
.im-msgs{flex:1;min-height:0;overflow-y:auto;padding:14px 16px;display:flex;flex-direction:column;gap:4px;scrollbar-width:thin;scrollbar-color:var(--surface3) transparent;}
/* window bar, quotes, reactions, calls */
.winbar{display:none;align-items:center;gap:10px;padding:8px 14px;font-size:12.5px;border-bottom:1px solid var(--border);}
.winbar.open{display:flex;background:rgba(37,211,102,.08);color:var(--accent);}
.winbar.closed{display:flex;background:rgba(255,77,109,.10);color:var(--danger);}
.winbar button{margin-left:auto;background:var(--accent);border:none;border-radius:6px;padding:4px 10px;font-size:11.5px;font-weight:700;cursor:pointer;color:#000;}
.mq{border-left:3px solid var(--accent);background:rgba(255,255,255,.06);border-radius:6px;padding:4px 8px;margin-bottom:5px;font-size:12px;color:var(--text2);max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.rx{position:relative;top:-6px;margin-left:8px;background:var(--surface2);border:1px solid var(--border);border-radius:12px;padding:0 6px;font-size:13px;width:fit-content;}
.merr{color:var(--danger);font-size:11px;margin-top:3px;max-width:260px;}
.mw.call{align-self:center;max-width:90%;}
.mw.call .mb{background:var(--surface2);border:1px solid var(--border);color:var(--text2);font-size:12.5px;border-radius:20px;padding:6px 14px;}
.mw.call.missed .mb{color:var(--danger);border-color:rgba(255,77,109,.35);}
.rpl{opacity:0;cursor:pointer;background:none;border:none;color:var(--muted);font-size:14px;padding:0 4px;transition:opacity .15s;}
.mw:hover .rpl{opacity:1;}
.replybar{display:none;align-items:center;gap:8px;padding:6px 14px;font-size:12px;color:var(--text2);border-left:3px solid var(--accent);background:var(--surface2);}
.replybar span{flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.older{text-align:center;margin:6px 0;}
.older button{background:var(--surface2);border:1px solid var(--border);border-radius:20px;padding:4px 14px;font-size:11.5px;color:var(--text2);cursor:pointer;}
.ds{text-align:center;margin:10px 0;}
.ds span{background:var(--surface2);border:1px solid var(--border);border-radius:20px;padding:3px 12px;font-size:10.5px;color:var(--muted);}
.mw{display:flex;align-items:flex-end;gap:7px;max-width:72%;}
.mw.out{align-self:flex-end;flex-direction:row-reverse;}
.mw.in{align-self:flex-start;}
.mw.note{align-self:center;max-width:88%;}
.mw.tpl{align-self:flex-end;}
.m-av{width:24px;height:24px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-size:9px;font-weight:800;color:#000;flex-shrink:0;}
.mb{padding:9px 13px;border-radius:14px;font-size:13.5px;line-height:1.55;word-break:break-word;max-width:100%;}
.mw.in .mb{background:var(--surface2);color:var(--text);border-bottom-left-radius:3px;}
.mw.out .mb{background:#1a5c36;color:#e2ffd9;border-bottom-right-radius:3px;}
.mw.note .mb{background:rgba(245,200,66,0.07);border:1px dashed rgba(245,200,66,0.3);color:var(--gold);border-radius:9px;font-size:12.5px;font-style:italic;text-align:center;}
.mw.tpl .mb{background:var(--surface);border:1px solid rgba(37,211,102,0.2);color:var(--text2);border-bottom-right-radius:3px;}
.mt{font-size:10px;margin-top:3px;display:flex;align-items:center;gap:3px;}
.mw.out .mt{justify-content:flex-end;color:rgba(255,255,255,0.4);}
.mw.in .mt{color:var(--muted);}
.tick-sent{color:rgba(255,255,255,0.4);}
.tick-delivered{color:rgba(255,255,255,0.65);}
.tick-read{color:#7fffd4;}
.im-bottom{border-top:1px solid var(--border);background:var(--sidebar);flex-shrink:0;}
.tpl-bar{padding:10px 14px;border-bottom:1px solid var(--border);display:none;}
.tpl-bar.show{display:block;}
.tpl-bar-title{font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:7px;}
.tpl-grid{display:flex;flex-direction:column;gap:4px;max-height:160px;overflow-y:auto;}
.tpl-row{display:flex;align-items:center;justify-content:space-between;padding:7px 10px;border-radius:7px;background:var(--surface2);border:1px solid var(--border);cursor:pointer;transition:all .15s;}
.tpl-row:hover{border-color:var(--accent);}
.tpl-row-name{font-size:12.5px;color:var(--text);font-weight:600;}
.tpl-row-cat{font-size:10px;color:var(--muted);}
.note-bar{padding:7px 14px;background:rgba(245,200,66,0.05);border-bottom:1px solid rgba(245,200,66,0.2);font-size:12px;color:var(--gold);display:none;align-items:center;gap:8px;}
.note-bar.show{display:flex;}
.input-tools{display:flex;gap:5px;padding:8px 14px 0;flex-wrap:wrap;}
.it{padding:5px 10px;border-radius:6px;font-size:11.5px;font-weight:700;cursor:pointer;border:1px solid var(--border);color:var(--muted);background:none;font-family:'Cabinet Grotesk',sans-serif;transition:all .15s;}
.it:hover{border-color:var(--accent);color:var(--accent);}
.it.on{background:var(--accent-dim);border-color:rgba(37,211,102,0.3);color:var(--accent);}
.input-row{display:flex;gap:8px;align-items:flex-end;padding:8px 14px 12px;}
.mi{flex:1;background:var(--surface2);border:1.5px solid var(--border2);border-radius:10px;padding:10px 13px;color:var(--text);font-family:'Cabinet Grotesk',sans-serif;font-size:13.5px;outline:none;resize:none;max-height:120px;line-height:1.5;transition:border-color .2s;}
.mi:focus{border-color:var(--accent);}
.mi::placeholder{color:var(--muted);}
.mi.note-mode{border-color:rgba(245,200,66,0.3);}
.sb{background:var(--accent);border:none;border-radius:10px;width:42px;height:42px;display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:18px;color:#000;flex-shrink:0;transition:opacity .15s;}
.sb:hover{opacity:.85;}
.sb:disabled{opacity:.35;cursor:not-allowed;}
.no-sel{display:flex;flex-direction:column;align-items:center;justify-content:center;flex:1;color:var(--muted);gap:10px;}

/* RIGHT */
.ir{display:flex;flex-direction:column;background:var(--sidebar);border-left:1px solid var(--border);overflow-y:auto;scrollbar-width:thin;scrollbar-color:var(--surface3) transparent;}
.is{padding:13px 14px;border-bottom:1px solid var(--border);}
.is-label{font-size:10px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px;}
.qr-item{padding:7px 10px;border-radius:7px;background:var(--surface2);border:1px solid var(--border);cursor:pointer;font-size:12px;color:var(--text2);margin-bottom:5px;transition:all .15s;line-height:1.4;}
.qr-item:hover{border-color:var(--accent);color:var(--text);}
.tag-chip{display:inline-flex;align-items:center;background:var(--surface2);border:1px solid var(--border);border-radius:20px;padding:3px 9px;font-size:11px;color:var(--text2);margin:2px;}
@media(max-width:1000px){.ir{display:none;}.ib{grid-template-columns:260px 1fr;}}
/* Mobile: WhatsApp-style — conversation list first, tap a chat to open it full-screen, ← to go back */
.im-back{display:none;background:none;border:none;color:var(--text);font-size:22px;cursor:pointer;padding:4px 6px 4px 0;line-height:1;flex-shrink:0;}
@media(max-width:768px){
  /* list mode: normal page flow; chat mode: full screen under the top bar, like WhatsApp */
  .ib{grid-template-columns:minmax(0,1fr);height:auto;min-height:60dvh;margin-top:0;border-radius:12px;}
  .ib:not(.chat-open) .im{display:none;}
  .ib.chat-open .il{display:none;}
  .ib.chat-open{position:fixed;left:0;right:0;top:calc(56px + env(safe-area-inset-top));bottom:0;height:auto;min-height:0;z-index:110;border:none;border-radius:0;padding-bottom:env(safe-area-inset-bottom);}
  body.inbox-chat .bnav{display:none;}
  body.inbox-chat{overflow:hidden;}
  .im-back{display:block;}
  .im-header{flex-wrap:wrap;padding:8px 10px;}
  .im-hinfo{min-width:0;flex:1;}
  .im-hname,.im-hmeta{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .im-hbtns{max-width:100%;width:100%;flex-wrap:nowrap;overflow-x:auto;justify-content:flex-start;scrollbar-width:none;}
  .im-hbtns::-webkit-scrollbar{display:none;}
  .hb{flex-shrink:0;padding:8px 12px;}
  .mw{max-width:88%;}
  .ci{padding:13px 12px;}
  .input-row .mi{font-size:16px;}
  #mediaPnl > div[style*="grid-template-columns"]{grid-template-columns:1fr !important;}
}
</style>

<?php if (!$waOk): ?>
<div style="background:rgba(255,140,66,0.08);border:1px solid rgba(255,140,66,0.3);border-radius:10px;padding:11px 16px;margin-bottom:14px;font-size:13px;display:flex;align-items:center;gap:10px;">
  ⚠️ <span style="color:#ff8c42;font-weight:700;">WhatsApp not connected</span> — messages won't send. <a href="/user/settings.php" style="color:var(--accent);margin-left:4px;">Connect →</a>
</div>
<?php endif; ?>

<div class="ib" id="ib">

  <!-- ── LEFT PANEL ── -->
  <div class="il">
    <div class="il-top">
      <button class="new-btn" onclick="openModal('newChatM')">＋ New Chat</button>
      <div class="il-search">
        <span style="font-size:13px;color:var(--muted);">⌕</span>
        <input type="text" placeholder="Search…" oninput="filterList(this.value)" id="srch">
      </div>
      <div class="ftabs">
        <?php
        $tabs=[['all','All',$counts['all']],['open','Open',$counts['open']],['unread','Unread',$counts['unread']],['pending','Pending',$counts['pending']],['resolved','Done',$counts['resolved']],['bot_off','Bot Off',0]];
        foreach($tabs as [$k,$l,$c]):
        ?>
        <button class="ft <?= $filter===$k?'on':'' ?>" onclick="setFilter('<?= $k ?>')"><?= $l ?><?= $c>0?" ($c)":'' ?></button>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="il-list" id="clist">
      <?php if(empty($convs)):?>
      <div style="text-align:center;padding:40px 14px;color:var(--muted);font-size:13px;">No conversations.<br>Click <strong>+ New Chat</strong> to start.</div>
      <?php endif;?>
      <?php foreach($convs as $cv):
        $diff = $cv['last_message_at'] ? time()-strtotime($cv['last_message_at']) : PHP_INT_MAX;
        $timeLabel = $diff<3600 ? floor($diff/60).'m' : ($diff<86400 ? date('H:i',strtotime($cv['last_message_at'])) : date('M j',strtotime($cv['last_message_at'])));
        $dotColor = match($cv['status']??'open'){'open'=>'var(--accent)','pending'=>'var(--orange)','resolved'=>'var(--blue)',default=>'var(--muted)'};
      ?>
      <div class="ci <?= $cv['unread_count']>0?'unread':'' ?>" id="ci-<?= $cv['id'] ?>"
           data-id="<?= $cv['id'] ?>" data-name="<?= e($cv['contact_name']) ?>" data-phone="<?= e($cv['phone']) ?>"
           onclick="loadConv(<?= $cv['id'] ?>)">
        <div class="ci-av"><?= strtoupper(substr($cv['contact_name'],0,2)) ?></div>
        <div class="ci-body">
          <div class="ci-name"><?= e($cv['contact_name']) ?></div>
          <div class="ci-prev"><?= e($cv['last_message_preview']??'No messages yet') ?></div>
        </div>
        <div class="ci-right">
          <div class="ci-time" data-ts="<?= e($cv['last_message_at'] ?? '') ?>"><?= $timeLabel ?></div>
          <?php if($cv['unread_count']>0):?><div class="ci-badge"><?= min($cv['unread_count'],9) ?></div><?php endif;?>
          <div class="ci-status-dot" style="background:<?= $dotColor ?>;"></div>
        </div>
        <?php if(!$cv['is_bot_active']): ?><div class="ci-bot-off" title="Bot off"></div><?php endif; ?>
      </div>
      <?php endforeach;?>
    </div>
  </div>

  <!-- ── MIDDLE PANEL ── -->
  <div class="im" id="im">
    <div class="no-sel" id="noSel" style="flex:1;">
      <div style="font-size:48px;opacity:.15;">💬</div>
      <div style="font-weight:700;font-size:16px;color:var(--text);">Select a conversation</div>
      <div style="font-size:13px;">or start a new chat below</div>
      <button class="btn btn-primary" onclick="openModal('newChatM')" style="margin-top:6px;">＋ New Chat</button>
    </div>

    <div id="chatArea" style="display:none;flex-direction:column;height:100%;overflow:hidden;">
      <!-- Header -->
      <div class="im-header">
        <button class="im-back" onclick="closeConvMobile()" aria-label="Back to chats">←</button>
        <div class="ci-av" id="cHdr-av" style="width:36px;height:36px;font-size:12px;"></div>
        <div class="im-hinfo">
          <div class="im-hname" id="cHdr-name"></div>
          <div class="im-hmeta" id="cHdr-meta"></div>
        </div>
        <div class="im-hbtns">
          <button class="hb" id="botBtn" onclick="toggleBot()">🤖 Bot</button>
          <button class="hb" onclick="toggleTplBar()">📋 Template</button>
          <button class="hb" onclick="toggleNoteMode()" id="noteBtn">📝 Note</button>
          <button class="hb" onclick="resolveConv()">✓ Resolve</button>
          <button class="hb" onclick="openModal('assignM')">👤 Assign</button>
          <button class="hb res" onclick="setPending()">⏸ Pending</button>
        </div>
      </div>

      <div class="winbar" id="winBar"></div>
      <!-- Messages -->
      <div class="im-msgs" id="msgs"></div>

      <!-- Bottom -->
      <div class="im-bottom">
        <!-- Template picker -->
        <div class="tpl-bar" id="tplBar">
          <div class="tpl-bar-title">📋 Approved Templates — click to send</div>
          <div class="tpl-grid">
            <?php if(empty($templates)):?>
            <div style="font-size:12px;color:var(--muted);padding:8px;">No templates yet. <a href="/user/templates.php" style="color:var(--accent);">Create one →</a></div>
            <?php else:?>
            <?php foreach($templates as $t):?>
            <div class="tpl-row" onclick="sendTemplate(<?= $t['id'] ?>,'<?= e(addslashes($t['template_name'])) ?>')">
              <div><div class="tpl-row-name"><?= e($t['template_name']) ?></div><div class="tpl-row-cat"><?= e($t['category']) ?></div></div>
              <button style="background:var(--accent);border:none;border-radius:6px;padding:4px 10px;font-size:11px;font-weight:700;cursor:pointer;color:#000;font-family:'Cabinet Grotesk',sans-serif;">Send</button>
            </div>
            <?php endforeach;?>
            <?php endif;?>
          </div>
        </div>
        <!-- Note mode bar -->
        <div class="note-bar" id="noteBar">
          📝 <strong>Internal note</strong> — only your team sees this
          <button onclick="toggleNoteMode()" style="background:none;border:none;color:var(--gold);cursor:pointer;margin-left:auto;font-size:16px;line-height:1;">✕</button>
        </div>
        <!-- Input tools -->
        <div class="input-tools">
          <button class="it" onclick="insertEmoji('😊')">😊</button>
          <button class="it" onclick="insertEmoji('👍')">👍</button>
          <button class="it" onclick="insertEmoji('🙏')">🙏</button>
          <button class="it" onclick="insertEmoji('✅')">✅</button>
          <button class="it" onclick="insertEmoji('❤️')">❤️</button>
          <button class="it" id="boldBtn" onclick="wrapSel('*')"><strong>B</strong></button>
          <button class="it" id="italicBtn" onclick="wrapSel('_')"><em>I</em></button>
          <button class="it" onclick="openMediaPanel()" title="Send image/video/document">📎</button>
        </div>
        <!-- Media send panel -->
        <div id="mediaPnl" style="display:none;padding:10px 14px;border-top:1px solid var(--border);background:var(--surface2);">
          <div style="font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.7px;margin-bottom:8px;">Send Media</div>
          <div style="display:grid;grid-template-columns:120px 1fr 80px;gap:8px;align-items:end;">
            <div>
              <div style="font-size:11px;color:var(--muted);margin-bottom:4px;">Type</div>
              <select id="mediaType" class="form-control" style="font-size:12px;padding:7px 10px;">
                <option value="image">📷 Image</option>
                <option value="video">🎥 Video</option>
                <option value="document">📄 Document</option>
                <option value="audio">🎵 Audio</option>
              </select>
            </div>
            <div>
              <div style="font-size:11px;color:var(--muted);margin-bottom:4px;">Media URL <span style="color:var(--accent);">*</span></div>
              <input type="url" id="mediaUrl" class="form-control" placeholder="https://example.com/image.jpg" style="font-size:12px;padding:7px 10px;">
            </div>
            <button class="btn btn-primary btn-sm" onclick="sendMedia()" style="padding:8px 12px;">Send</button>
          </div>
          <div style="margin-top:7px;display:flex;gap:8px;align-items:center;">
            <input type="text" id="mediaCaption" class="form-control" placeholder="Caption (optional)" style="font-size:12px;padding:7px 10px;flex:1;">
            <button class="it" onclick="document.getElementById('fileUploadInput').click()">⬆ Upload File</button>
            <input type="file" id="fileUploadInput" style="display:none;" accept="image/*,video/*,.pdf,.doc,.docx" onchange="uploadAndSend(this)">
          </div>
          <div id="uploadProgress" style="display:none;font-size:12px;color:var(--accent);margin-top:6px;">⏳ Uploading...</div>
        </div>
        <div class="replybar" id="replyBar"><span id="replyTxt"></span><button class="rpl" style="opacity:1" onclick="clearReply()">✕</button></div>
        <div class="input-row">
          <textarea class="mi" id="mi" placeholder="Type a message… (Enter send · Shift+Enter newline)" rows="1"
            onkeydown="onKey(event)" oninput="grow(this)"></textarea>
          <button class="sb" id="sb" onclick="doSend()">➤</button>
        </div>
      </div>
    </div>
  </div>

  <!-- ── RIGHT PANEL ── -->
  <div class="ir" id="ir">
    <div style="padding:16px;color:var(--muted);font-size:13px;text-align:center;" id="irEmpty">
      Select a conversation
    </div>
    <div id="irContent" style="display:none;"></div>
  </div>
</div>

<!-- New Chat Modal -->
<div class="modal-overlay" id="newChatM">
  <div class="modal" style="max-width:440px;">
    <div class="modal-title">＋ New Chat</div>
    <div class="modal-sub">Start a WhatsApp conversation with any contact</div>
    <div class="form-group">
      <label class="form-label">Phone Number *</label>
      <input type="text" id="nc-phone" class="form-control" placeholder="9876543210" autofocus>
      <div style="font-size:11px;color:var(--muted);margin-top:4px;">🇮🇳 +91 added automatically for 10-digit numbers</div>
    </div>
    <div class="form-group">
      <label class="form-label">Contact Name</label>
      <input type="text" id="nc-name" class="form-control" placeholder="Customer name">
    </div>
    <div style="background:rgba(37,211,102,0.04);border:1px solid rgba(37,211,102,0.2);border-radius:9px;padding:11px 13px;font-size:12px;color:var(--text2);margin-bottom:16px;line-height:1.7;">
      💡 <strong style="color:var(--accent);">24-hour rule:</strong> You can only send free-text if the customer messaged you within 24 hours. Otherwise use a <strong style="color:#fff;">template</strong> to initiate.
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('newChatM')">Cancel</button>
      <button class="btn btn-primary" onclick="doNewChat()">Open Chat →</button>
    </div>
  </div>
</div>

<!-- Assign Modal -->
<div class="modal-overlay" id="assignM">
  <div class="modal" style="max-width:340px;">
    <div class="modal-title">Assign Conversation</div>
    <div class="form-group">
      <label class="form-label">Assign to team member</label>
      <select id="assignSel" class="form-control">
        <option value="0">— Unassigned —</option>
        <?php foreach($teamMembers as $tm):?>
        <option value="<?= $tm['id'] ?>"><?= e($tm['name']) ?></option>
        <?php endforeach;?>
      </select>
      <?php if(empty($teamMembers)):?>
      <div style="font-size:11px;color:var(--muted);margin-top:5px;">No team members added yet.</div>
      <?php endif;?>
    </div>
    <div class="modal-footer">
      <button class="btn btn-secondary" onclick="closeModal('assignM')">Cancel</button>
      <button class="btn btn-primary" onclick="doAssign()">Assign</button>
    </div>
  </div>
</div>

<script>
const CSRF='<?= csrfToken() ?>';
const ME_ID=<?= $uid ?>;
const ME_NAME='<?= e($user['name']) ?>';
const QUICK_REPLIES=<?= json_encode($quickReplies) ?>;
let ACV=null; // active conv id
let ACONV=null; // active conv object
let LAST_MSG_ID=0;
let LAST_DATE='';
let REPLY_TO=null;
let WIN={open:true,left:0,by:'customer'};
let BOT_ACTIVE=false;
let NOTE_MODE=false;
let POLL=null;

// ── FILTER / SEARCH ──────────────────────────────────────
function setFilter(f){location.href='?f='+f;}
function filterList(q){
  const lq=q.toLowerCase();
  document.querySelectorAll('.ci').forEach(el=>{
    const n=(el.dataset.name||'').toLowerCase();
    const p=(el.dataset.phone||'').toLowerCase();
    const prev=el.querySelector('.ci-prev')?.textContent.toLowerCase()||'';
    el.style.display=(!q||n.includes(lq)||p.includes(lq)||prev.includes(lq))?'':'none';
  });
}

// ── MOBILE: back from a chat to the list (also the phone's back button) ──
function closeConvMobile(fromPop){
  document.getElementById('ib').classList.remove('chat-open');
  document.body.classList.remove('inbox-chat');
  if(!fromPop && location.hash==='#chat') history.back();
}
window.addEventListener('popstate',()=>{ if(document.getElementById('ib').classList.contains('chat-open')) closeConvMobile(true); });
if(window.innerWidth<=768){ const mi=document.getElementById('mi'); if(mi) mi.placeholder='Message'; }

// ── LOAD CONVERSATION ────────────────────────────────────
async function loadConv(id) {
  ACV=id;
  document.querySelectorAll('.ci').forEach(e=>e.classList.remove('sel'));
  const el=document.getElementById('ci-'+id);
  if(el)el.classList.add('sel');

  const data=await post({action:'load_conv',conv_id:id});
  if(!data.success)return;

  ACONV=data.conv;
  BOT_ACTIVE=data.conv.is_bot_active==1;
  LAST_MSG_ID=0;

  // Show chat area (on phones the chat replaces the list)
  document.getElementById('noSel').style.display='none';
  const ca=document.getElementById('chatArea');
  ca.style.display='flex';
  ca.style.flexDirection='column';
  document.getElementById('ib').classList.add('chat-open');
  document.body.classList.add('inbox-chat');
  if(window.innerWidth<=768 && location.hash!=='#chat') history.pushState({chat:id},'','#chat');

  // Header
  const av=document.getElementById('cHdr-av');
  av.textContent=data.conv.contact_name.substring(0,2).toUpperCase();
  document.getElementById('cHdr-name').textContent=data.conv.contact_name;
  document.getElementById('cHdr-meta').textContent=data.conv.phone+(data.conv.email?' · '+data.conv.email:'')+' · '+ucfirst(data.conv.status||'open');
  updateBotBtn();

  // Render messages
  const mc=document.getElementById('msgs');
  mc.innerHTML='';
  LAST_DATE='';clearReply();
  if(data.has_more)mc.appendChild(olderBtn());
  data.messages.forEach(m=>{
    const d=P(m.created_at).toDateString();
    if(d!==LAST_DATE){mc.appendChild(dateSep(d));LAST_DATE=d;}
    mc.appendChild(buildMsg(m));
  });
  mc.scrollTop=mc.scrollHeight;
  setWindow(data.window, data.initiated_by);
  LAST_MSG_ID=data.messages.length>0?Math.max(...data.messages.map(m=>parseInt(m.id))):0;

  // Info panel
  renderInfo(data.conv,data.tags);

  // Poll
  clearInterval(POLL);
  POLL=setInterval(doPoll,3500);
}

// ── BUILD MESSAGE BUBBLE ─────────────────────────────────
function buildMsg(m) {
  const isOut=m.direction==='outbound';
  const isNote=m.is_note==1;
  const isTpl=m.message_type==='template';
  const mediaTypes=['image','video','document','audio','voice','sticker'];
  const isMedia=mediaTypes.includes(m.message_type);
  const isCall=m.message_type==='call';
  const cls=isCall?('call'+(/Missed|not answered/i.test(m.content||'')?' missed':'')):(isNote?'note':(isTpl?'tpl':(isOut?'out':'in')));
  const wrap=document.createElement('div');
  wrap.className='mw '+cls;
  wrap.dataset.id=m.id;

  // Customer text is untrusted: escape first, then apply WhatsApp-style *bold* / _italic_ and links.
  let text=esc(m.content||'').replace(/\n/g,'<br>');
  text=text.replace(/(^|\s)\*(\S[^*]*?)\*(?=\s|$|[.,!?])/g,'$1<strong>$2</strong>').replace(/(^|\s)_(\S[^_]*?)_(?=\s|$|[.,!?])/g,'$1<em>$2</em>');
  text=linkify(text);

  const t=fmtTime(m.created_at);
  const sender=esc(m.sender_name||'Team');

  // Build media bubble — proxy handles auth token for WhatsApp media
  let mediaBubble='';
  if(isMedia){
    // Use proxy: /media.php?id=MSG_DB_ID  (server fetches with auth token + caches)
    const proxyUrl=`<?= APP_URL ?>/media.php?id=${parseInt(m.id)}`;
    // For outbound media sent via URL, we stored the URL directly (https only)
    const directUrl=/^https:\/\//i.test(m.media_url||'')?m.media_url:'';
    const displayUrl=esc(((m.direction==='inbound'||m.media_id)&&m.id)?proxyUrl:directUrl);

    if(m.message_type==='image'){
      mediaBubble=`<div style="margin-bottom:6px;cursor:pointer;" onclick="openImg('${displayUrl}')">
        <img src="${displayUrl}" style="max-width:240px;max-height:200px;border-radius:10px;display:block;"
          onerror="this.outerHTML='<div style=\'background:rgba(255,255,255,0.06);border-radius:9px;padding:12px 14px;\' ><span style=\'font-size:22px;\'>📷</span> <span style=\'font-size:12px;color:#7ab59a;\'>Image received · <a href=\'${displayUrl}\' target=\'_blank\' style=\'color:var(--accent);\'>View</a></span></div>'">
      </div>`;
    } else if(m.message_type==='video'){
      mediaBubble=`<video src="${displayUrl}" controls style="max-width:240px;border-radius:10px;display:block;margin-bottom:6px;" preload="metadata"></video>`;
    } else if(m.message_type==='audio'||m.message_type==='voice'){
      mediaBubble=`<audio src="${displayUrl}" controls style="width:220px;display:block;margin-bottom:6px;"></audio>`;
    } else if(m.message_type==='document'){
      const fname=text.replace(/📄\s*Document[:\s]*/i,'').replace(/—.*/,'').trim()||'Document';
      mediaBubble=`<a href="${displayUrl}" target="_blank" style="display:flex;align-items:center;gap:10px;background:rgba(255,255,255,0.06);border-radius:9px;padding:10px 13px;text-decoration:none;margin-bottom:6px;max-width:240px;">
        <span style="font-size:28px;flex-shrink:0;">📄</span>
        <div style="min-width:0;"><div style="color:#e2ffd9;font-size:13px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">${fname}</div>
        <div style="color:rgba(255,255,255,0.4);font-size:11px;margin-top:2px;">Tap to open</div></div></a>`;
    }
  }

  const ticks={'sent':'<span class="tick-sent">✓</span>','delivered':'<span class="tick-delivered">✓✓</span>','read':'<span class="tick-read">✓✓</span>','failed':'<span style="color:var(--danger);">✗ failed</span>'};
  const quote=m.quoted_text?`<div class="mq">↩ ${esc(m.quoted_text.substring(0,90))}</div>`:'';
  const rx=m.reaction?`<div class="rx" data-rx>${esc(m.reaction)}</div>`:'<div class="rx" data-rx style="display:none"></div>';
  const err=(m.status==='failed'&&m.error_message)?`<div class="merr" data-err>⚠ ${esc(m.error_message)}</div>`:'<div class="merr" data-err style="display:none"></div>';
  const rbtn=`<button class="rpl" title="Reply" onclick="setReply(${m.id})">↩</button>`;
  wrap.dataset.t=t;

  if(isCall){
    wrap.innerHTML=`<div class="mb">${text} <span style="opacity:.6;font-size:11px;margin-left:6px;">${t}</span></div>`;
  } else if(isNote){
    wrap.innerHTML=`<div class="mb">📝 <em>${sender}: ${text||'[note]'}</em></div>`;
  } else if(isTpl){
    wrap.innerHTML=`<div><div class="mb">📋 ${text}</div><div class="mt">${t} <span data-tk>${ticks[m.status]||''}</span></div>${err}</div>${rbtn}`;
  } else if(isOut){
    wrap.innerHTML=`<div><div class="mb">${quote}${mediaBubble}${text}</div>${rx}<div class="mt">${t} <span data-tk>${ticks[m.status]||''}</span></div>${err}</div>${rbtn}`;
  } else {
    const av=`<div class="m-av">${esc((ACONV?.contact_name||'?').substring(0,2).toUpperCase())}</div>`;
    wrap.innerHTML=`${av}<div><div class="mb">${quote}${mediaBubble}${text}</div>${rx}<div class="mt">${t}</div></div>${rbtn}`;
  }
  return wrap;
}

function dateSep(d) {
  const e=document.createElement('div');e.className='ds';
  const today=new Date().toDateString(),yest=new Date(Date.now()-86400000).toDateString();
  const label=d===today?'Today':d===yest?'Yesterday':d;
  e.innerHTML=`<span>${label}</span>`;return e;
}


// ── 24h WINDOW (WhatsApp customer-service window) ─────────
let WIN_TIMER=null;
function fmtLeft(sec){const h=Math.floor(sec/3600),m=Math.floor((sec%3600)/60);return h>0?h+'h '+m+'m':m+'m';}
function setWindow(w,by){
  if(!w)return;
  if(by)WIN.by=by;
  WIN.open=w.open;WIN.left=w.seconds_left;WIN.at=Date.now();
  renderWindow();
  clearInterval(WIN_TIMER);WIN_TIMER=setInterval(()=>{if(WIN.open){const l=WIN.left-Math.floor((Date.now()-WIN.at)/1000);if(l<=0){WIN.open=false;}renderWindow();}},30000);
}
function renderWindow(){
  const b=document.getElementById('winBar'),inp=document.getElementById('mi'),sb=document.getElementById('sb');
  if(!b)return;
  const left=Math.max(0,WIN.left-Math.floor((Date.now()-(WIN.at||Date.now()))/1000));
  const who=WIN.by==='customer'?'Customer-initiated chat':'Business-initiated chat';
  if(WIN.open&&left>0){
    b.className='winbar open';b.innerHTML=`🟢 <span><strong>${fmtLeft(left)}</strong> left to reply freely · ${who}</span>`;
    if(!NOTE_MODE){inp.disabled=false;sb.disabled=false;inp.placeholder='Type a message… (Enter send · Shift+Enter newline)';}
  } else {
    b.className='winbar closed';b.innerHTML=`🔒 <span>24-hour window closed — you can only send an approved template. ${who}</span><button onclick="toggleTplBar()">📋 Send template</button>`;
    if(!NOTE_MODE){inp.disabled=true;sb.disabled=true;inp.placeholder='Window closed — send a template to restart the chat (or switch to Note)';}
  }
}

// ── LOAD OLDER ───────────────────────────────────────────
function olderBtn(){const d=document.createElement('div');d.className='older';d.innerHTML='<button onclick="loadOlder(this)">⬆ Load older messages</button>';return d;}
async function loadOlder(btn){
  const mc=document.getElementById('msgs');
  const first=mc.querySelector('.mw[data-id]');if(!first)return;
  btn.textContent='Loading…';
  const data=await post({action:'load_older',conv_id:ACV,before_id:first.dataset.id});
  const holder=btn.parentElement;
  const prevH=mc.scrollHeight;
  const frag=document.createDocumentFragment();
  let last='';
  (data.messages||[]).forEach(m=>{const d=P(m.created_at).toDateString();if(d!==last){frag.appendChild(dateSep(d));last=d;}frag.appendChild(buildMsg(m));});
  // drop the day separator that now sits directly above same-day older messages
  holder.after(frag);
  if(!data.has_more)holder.remove();else btn.textContent='⬆ Load older messages';
  mc.scrollTop=mc.scrollHeight-prevH;
}

// ── LIVE TICKS / REACTIONS / FAILURES ─────────────────────
function applyUpdates(list){
  const ticks={'sent':'<span class="tick-sent">✓</span>','delivered':'<span class="tick-delivered">✓✓</span>','read':'<span class="tick-read">✓✓</span>','failed':'<span style="color:var(--danger);">✗ failed</span>'};
  list.forEach(u=>{
    const w=document.querySelector(`#msgs .mw[data-id="${u.id}"]`);if(!w)return;
    const tk=w.querySelector('[data-tk]');if(tk&&ticks[u.status])tk.innerHTML=ticks[u.status];
    const rx=w.querySelector('[data-rx]');if(rx){rx.textContent=u.reaction||'';rx.style.display=u.reaction?'':'none';}
    const er=w.querySelector('[data-err]');if(er){const show=u.status==='failed'&&u.error_message;er.textContent=show?'⚠ '+u.error_message:'';er.style.display=show?'':'none';}
    if(w.classList.contains('call')){const mb=w.querySelector('.mb');if(mb&&u.content&&!mb.textContent.includes(u.content)){mb.innerHTML=esc(u.content)+` <span style="opacity:.6;font-size:11px;margin-left:6px;">${w.dataset.t||''}</span>`;w.classList.toggle('missed',/Missed|not answered/i.test(u.content));}}
  });
}

// ── QUOTED REPLY ─────────────────────────────────────────
function setReply(id){
  const w=document.querySelector(`#msgs .mw[data-id="${id}"] .mb`);if(!w)return;
  REPLY_TO=id;
  document.getElementById('replyTxt').textContent='↩ '+w.textContent.trim().substring(0,100);
  document.getElementById('replyBar').style.display='flex';
  document.getElementById('mi').focus();
}
function clearReply(){REPLY_TO=null;const b=document.getElementById('replyBar');if(b)b.style.display='none';}

// ── POLL ─────────────────────────────────────────────────
async function doPoll() {
  if(!ACV)return;
  const data=await post({action:'poll',conv_id:ACV,after_id:LAST_MSG_ID});
  if(data.window)setWindow(data.window);
  applyUpdates(data.updates||[]);
  if(!data.messages||!data.messages.length)return;
  const mc=document.getElementById('msgs');
  const atBot=mc.scrollHeight-mc.clientHeight-mc.scrollTop<80;
  let hasInbound = false;
  data.messages.forEach(m=>{
    if(mc.querySelector(`[data-id="${m.id}"]`)){LAST_MSG_ID=Math.max(LAST_MSG_ID,parseInt(m.id));return;}   // already shown (we sent it)
    const d=P(m.created_at).toDateString();
    if(d!==LAST_DATE){mc.appendChild(dateSep(d));LAST_DATE=d;}
    mc.appendChild(buildMsg(m));
    LAST_MSG_ID=Math.max(LAST_MSG_ID,parseInt(m.id));
    if(m.direction==='inbound') hasInbound=true;
  });
  if(atBot)mc.scrollTop=mc.scrollHeight;
  // Update conv list preview + move to top
  const last=data.messages[data.messages.length-1];
  const ci=document.getElementById('ci-'+ACV);
  if(ci&&last){
    const p=ci.querySelector('.ci-prev');
    if(p) p.textContent=last.content?.substring(0,50)||'';
    const t=ci.querySelector('.ci-time');
    if(t) t.textContent=fmtTime(last.created_at);
    const list=document.getElementById('clist');
    list.insertBefore(ci, list.firstChild);
    // Flash notification and sound for inbound
    if(last.direction==='inbound' || hasInbound){
      playChatSound();
      const cName = document.getElementById('cHdr-name')?.textContent || 'Customer';
      notifyNewMsg(cName, last.content || 'New message');
      ci.style.background='rgba(37,211,102,0.08)';
      setTimeout(()=>ci.style.background='',2000);
    }
  }
}

// ── SEND TEXT / NOTE ─────────────────────────────────────
async function doSend() {
  const inp=document.getElementById('mi');
  const text=inp.value.trim();
  if(!text||!ACV)return;
  const sb=document.getElementById('sb');
  sb.disabled=true;
  inp.value='';grow(inp);

  let data;
  if(NOTE_MODE){
    data=await post({action:'send_note',conv_id:ACV,note:text});
  } else {
    data=await post({action:'send_message',conv_id:ACV,message:text,reply_to:REPLY_TO||''});if(data.success)clearReply();
  }
  if(!data.success){
    toast(data.error||'Could not send','error');
    inp.value=text;grow(inp);renderWindow();if(NOTE_MODE)sb.disabled=false;
    if(data.window_closed&&typeof toggleTplBar==='function')toggleTplBar();
    return;
  }

  if(data.success&&data.message){
    const mc=document.getElementById('msgs');
    mc.appendChild(buildMsg(data.message));
    mc.scrollTop=mc.scrollHeight;
    LAST_MSG_ID=Math.max(LAST_MSG_ID,parseInt(data.message.id));
    // Update conv list preview
    const ci=document.getElementById('ci-'+ACV);
    if(ci){
      ci.querySelector('.ci-prev').textContent=text.substring(0,50);
      ci.querySelector('.ci-time').textContent='now';
      // Move to top
      const list=document.getElementById('clist');
      list.insertBefore(ci, list.firstChild);
    }
    if(data.wa_ok){
      toast('Sent ✓');
    } else {
      toast('⚠ Saved but API error: '+data.wa_err,'err');
    }
  }
  renderWindow();if(NOTE_MODE)sb.disabled=false;
  if(NOTE_MODE)toggleNoteMode();
  inp.focus();
}

// ── SEND TEMPLATE ─────────────────────────────────────────
async function sendTemplate(id,name) {
  if(!ACV)return;
  toggleTplBar();
  const data=await post({action:'send_template',conv_id:ACV,tpl_id:id});
  if(data.success){
    if(data.message){
      const mc=document.getElementById('msgs');
      mc.appendChild(buildMsg(data.message));
      mc.scrollTop=mc.scrollHeight;
      LAST_MSG_ID=Math.max(LAST_MSG_ID,parseInt(data.message.id));
    }
    toast(data.wa_ok?'Template sent!':'Template failed: '+data.wa_err,data.wa_ok?'success':'error');
  } else {
    toast(data.error||'Could not send template','error');
  }
}

// ── ACTIONS ──────────────────────────────────────────────
async function toggleBot() {
  if(!ACV)return;
  const data=await post({action:'toggle_bot',conv_id:ACV});
  if(data.success){BOT_ACTIVE=data.bot_active;updateBotBtn();toast(data.bot_active?'Bot enabled':'Bot disabled');}
}
function updateBotBtn(){
  const b=document.getElementById('botBtn');
  b.classList.toggle('on',BOT_ACTIVE);
  b.textContent=BOT_ACTIVE?'🤖 Bot ON':'🤖 Bot OFF';
}
async function resolveConv(){if(!ACV)return;await post({action:'set_status',conv_id:ACV,status:'resolved'});toast('Marked resolved ✓');document.getElementById('cHdr-meta').textContent=(ACONV?.phone||'')+' · resolved';}
async function setPending(){if(!ACV)return;await post({action:'set_status',conv_id:ACV,status:'pending'});toast('Marked pending');}
async function doAssign(){
  const to=document.getElementById('assignSel').value;
  if(!ACV)return;
  await post({action:'assign',conv_id:ACV,assign_to:to});
  closeModal('assignM');toast('Assigned');
}
async function doAddTag(tag){
  if(!tag||!ACV)return;
  await post({action:'add_tag',conv_id:ACV,tag});
  return true;
}

// ── NOTE MODE ────────────────────────────────────────────
function toggleNoteMode(){
  NOTE_MODE=!NOTE_MODE;
  const nb=document.getElementById('noteBar');
  const btn=document.getElementById('noteBtn');
  const inp=document.getElementById('mi');
  nb.classList.toggle('show',NOTE_MODE);
  btn.classList.toggle('on',NOTE_MODE);
  inp.className='mi'+(NOTE_MODE?' note-mode':'');
  inp.placeholder=NOTE_MODE?'📝 Internal note (team only — customer won\'t see this)…':'Type a message… (Enter send · Shift+Enter newline)';
  if(NOTE_MODE){inp.disabled=false;document.getElementById('sb').disabled=false;}   // notes are internal: allowed even when the 24h window is closed
  else renderWindow();
  inp.focus();
}

// ── TEMPLATE BAR ─────────────────────────────────────────
function toggleTplBar(){document.getElementById('tplBar').classList.toggle('show');}

// ── NEW CHAT ─────────────────────────────────────────────
async function doNewChat(){
  const phone=document.getElementById('nc-phone').value.trim();
  const name=document.getElementById('nc-name').value.trim()||'Customer';
  if(!phone){alert('Phone number required');return;}
  const data=await post({action:'new_chat',phone,name});
  if(data.success){
    closeModal('newChatM');
    document.getElementById('nc-phone').value='';
    document.getElementById('nc-name').value='';
    // Add to conv list and open immediately
    addConvToList(data.conv_id, data.name, data.phone);
    loadConv(data.conv_id);
  } else toast(data.error||'Failed','err');
}

function addConvToList(id, name, phone) {
  // Remove if already exists
  const existing = document.getElementById('ci-'+id);
  if (existing) return;
  const list = document.getElementById('clist');
  const div  = document.createElement('div');
  div.className = 'ci';
  div.id = 'ci-'+id;
  div.dataset.id   = id;
  div.dataset.name = name;
  div.dataset.phone= phone;
  div.onclick = () => loadConv(id);
  div.innerHTML = `
    <div class="ci-av">${esc(String(name).substring(0,2).toUpperCase())}</div>
    <div class="ci-body">
      <div class="ci-name">${esc(name)}</div>
      <div class="ci-prev">${esc(phone)}</div>
    </div>
    <div class="ci-right">
      <div class="ci-time">now</div>
      <div class="ci-status-dot" style="background:var(--accent);"></div>
    </div>`;
  list.insertBefore(div, list.firstChild);
}

// ── INFO PANEL ───────────────────────────────────────────
function renderInfo(conv,tags){
  document.getElementById('irEmpty').style.display='none';
  const ic=document.getElementById('irContent');
  ic.style.display='block';
  const statusColors={'open':'var(--accent)','pending':'var(--orange)','resolved':'var(--blue)','closed':'var(--muted)'};
  const sc=statusColors[conv.status]||'var(--muted)';
  ic.innerHTML=`
    <div class="is" style="text-align:center;padding:18px 14px;">
      <div style="width:52px;height:52px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent2));display:flex;align-items:center;justify-content:center;font-size:18px;font-weight:800;color:#000;margin:0 auto 10px;">${conv.contact_name.substring(0,2).toUpperCase()}</div>
      <div style="font-weight:800;font-size:15px;color:#fff;margin-bottom:3px;">${esc(conv.contact_name)}</div>
      <div style="font-size:12px;color:var(--muted);margin-bottom:6px;">${esc(conv.phone)}</div>
      <span style="background:${sc}22;border:1px solid ${sc}55;color:${sc};padding:3px 10px;border-radius:20px;font-size:11px;font-weight:700;">${ucfirst(conv.status||'open')}</span>
    </div>
    <div class="is">
      <div class="is-label">Contact Details</div>
      <div style="font-size:12px;color:var(--text2);margin-bottom:4px;">${conv.email?'✉ '+esc(conv.email):'No email'}</div>
      <div style="font-size:12px;color:var(--text2);margin-bottom:4px;">📞 ${esc(conv.phone)}</div>
      <div style="font-size:12px;color:var(--text2);">Bot: <strong style="color:${conv.is_bot_active?'var(--accent)':'var(--orange)'};">${conv.is_bot_active?'Active':'Off'}</strong></div>
    </div>
    <div class="is">
      <div class="is-label">Actions</div>
      <div style="display:flex;gap:6px;flex-wrap:wrap;">
        <button class="hb" onclick="resolveConv()" style="font-size:11px;padding:4px 9px;">✓ Resolve</button>
        <button class="hb" onclick="setPending()" style="font-size:11px;padding:4px 9px;">⏸ Pending</button>
        <button class="hb" onclick="openModal('assignM')" style="font-size:11px;padding:4px 9px;">👤 Assign</button>
      </div>
    </div>
    <div class="is">
      <div class="is-label">Tags</div>
      <div id="ir-tags">${(tags||[]).map(t=>`<span class="tag-chip">${esc(t)}</span>`).join('')||'<span style="font-size:12px;color:var(--muted);">No tags yet</span>'}</div>
      <div style="display:flex;gap:6px;margin-top:8px;">
        <input type="text" id="tagInp" placeholder="Add tag…" style="flex:1;background:var(--surface2);border:1px solid var(--border);border-radius:7px;padding:5px 9px;color:var(--text);font-size:12px;outline:none;font-family:'Cabinet Grotesk',sans-serif;" onkeydown="if(event.key==='Enter')addTag()">
        <button class="hb" onclick="addTag()" style="font-size:11px;padding:5px 9px;">Add</button>
      </div>
    </div>
    <div class="is">
      <div class="is-label">Quick Replies</div>
      ${QUICK_REPLIES.map((r,i)=>`<div class="qr-item" title="${esc(r.body)}" onclick="insertQR(${i})">${esc(r.title)}</div>`).join('')}
      <a href="/user/auto_replies.php#quick" style="font-size:11px;color:var(--accent);text-decoration:none;display:block;margin-top:6px;">✎ Manage quick replies</a>
    </div>
    <div class="is">
      <div class="is-label">Send Template</div>
      <button class="hb" onclick="toggleTplBar()" style="width:100%;justify-content:center;display:flex;align-items:center;gap:6px;font-size:12px;">📋 Choose Template</button>
    </div>`;
}

async function addTag(){
  const inp=document.getElementById('tagInp');
  const tag=inp.value.trim();
  if(!tag)return;
  const ok=await doAddTag(tag);
  if(ok){
    inp.value='';
    const tc=document.getElementById('ir-tags');
    if(tc)tc.innerHTML+=`<span class="tag-chip">${esc(tag)}</span>`;
  }
}

function insertQR(i){
  const q=QUICK_REPLIES[i]; if(!q) return;
  const nm=(ACONV&&ACONV.contact_name)?String(ACONV.contact_name).split(' ')[0]:'there';
  const inp=document.getElementById('mi');
  inp.value=q.body.replace(/\{name\}/gi,nm);inp.focus();grow(inp);
}

// ── HELPERS ───────────────────────────────────────────────
async function post(data){
  data.csrf_token=CSRF;
  const r=await fetch(location.href,{method:'POST',body:new URLSearchParams(data)});
  return r.json();
}
// DB timestamps are UTC ("YYYY-MM-DD HH:MM:SS"); show them in the viewer's own timezone.
function P(dt){return new Date(String(dt).replace(' ','T')+'Z');}
function fmtTime(dt){return P(dt).toLocaleTimeString('en-IN',{hour:'2-digit',minute:'2-digit'});}
function linkify(t){return t.replace(/(https?:\/\/[^\s<]+)/g,'<a href="$1" target="_blank" rel="noopener" style="color:var(--accent);">$1</a>');}
function sideTime(dt){const d=P(dt),n=new Date(),diff=(n-d)/1000;if(diff<3600)return Math.max(1,Math.floor(diff/60))+'m';return d.toDateString()===n.toDateString()?fmtTime(dt):d.toLocaleDateString('en-IN',{day:'numeric',month:'short'});}
document.querySelectorAll('.ci-time[data-ts]').forEach(e=>{if(e.dataset.ts)e.textContent=sideTime(e.dataset.ts);});
function ucfirst(s){return s?s.charAt(0).toUpperCase()+s.slice(1):'';}
// Escapes for both text and attribute context (quotes too — linkify() puts URLs inside href="…")
function esc(s){const d=document.createElement('div');d.textContent=(s===null||s===undefined)?'':String(s);return d.innerHTML.replace(/"/g,'&quot;').replace(/'/g,'&#39;');}
function grow(el){el.style.height='auto';el.style.height=Math.min(el.scrollHeight,120)+'px';}
function onKey(e){if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();doSend();}}
function insertEmoji(e){const inp=document.getElementById('mi');const s=inp.selectionStart,en=inp.selectionEnd;inp.value=inp.value.substring(0,s)+e+inp.value.substring(en);inp.selectionStart=inp.selectionEnd=s+e.length;inp.focus();grow(inp);}
function wrapSel(ch){const inp=document.getElementById('mi');const s=inp.selectionStart,e=inp.selectionEnd;const sel=inp.value.substring(s,e);inp.value=inp.value.substring(0,s)+ch+sel+ch+inp.value.substring(e);inp.selectionStart=s;inp.selectionEnd=e+ch.length*2;inp.focus();}
function openImg(url){
  if(!url)return;
  const ov=document.createElement('div');
  ov.style.cssText='position:fixed;inset:0;background:rgba(0,0,0,0.9);z-index:9999;display:flex;align-items:center;justify-content:center;cursor:zoom-out;';
  ov.onclick=()=>ov.remove();
  ov.innerHTML=`<img src="${url}" style="max-width:90vw;max-height:90vh;border-radius:12px;object-fit:contain;">`;
  document.body.appendChild(ov);
}

function openMediaPanel(){
  const p=document.getElementById('mediaPnl');
  p.style.display=p.style.display==='none'?'block':'none';
}

async function sendMedia(){
  const url=document.getElementById('mediaUrl').value.trim();
  const type=document.getElementById('mediaType').value;
  const caption=document.getElementById('mediaCaption').value.trim();
  if(!url||!ACV){toast('Enter a media URL','err');return;}
  const data=await post({action:'send_media',conv_id:ACV,media_type:type,media_url:url,caption});
  if(data.success){
    document.getElementById('mediaPnl').style.display='none';
    document.getElementById('mediaUrl').value='';
    document.getElementById('mediaCaption').value='';
    const mc=document.getElementById('msgs');
    mc.appendChild(buildMsg(data.message));
    mc.scrollTop=mc.scrollHeight;
    LAST_MSG_ID=Math.max(LAST_MSG_ID,parseInt(data.message.id));
    toast(data.wa_ok?'Media sent ✓':'Saved (API: '+data.wa_err+')','ok');
  } else toast('Failed: '+data.error,'err');
}

async function uploadAndSend(input){
  if(!input.files[0]||!ACV)return;
  const file=input.files[0];
  const progress=document.getElementById('uploadProgress');
  progress.style.display='block';
  progress.textContent='⏳ Uploading '+file.name+'...';

  const fd=new FormData();
  fd.append('action','upload_media');
  fd.append('file',file);
  fd.append('csrf_token',CSRF);

  try{
    const res=await fetch(location.href,{method:'POST',body:fd});
    const data=await res.json();
    progress.style.display='none';
    input.value='';

    if(data.success&&data.media_id){
      // Send using media_id directly
      const type=file.type.startsWith('image/')?'image':file.type.startsWith('video/')?'video':file.type.startsWith('audio/')?'audio':'document';
      const caption=document.getElementById('mediaCaption').value.trim();
      // Use a placeholder URL since we have media_id — send via separate action
      const r=await post({action:'send_media_id',conv_id:ACV,media_type:type,media_id:data.media_id,caption,filename:file.name});
      if(r.success){
        document.getElementById('mediaPnl').style.display='none';
        const mc=document.getElementById('msgs');
        mc.appendChild(buildMsg(r.message));
        mc.scrollTop=mc.scrollHeight;
        toast('File sent ✓');
      } else toast('Upload OK but send failed: '+r.wa_err,'err');
    } else {
      toast('Upload failed: '+(data.error||'Unknown'),'err');
    }
  }catch(e){
    progress.style.display='none';
    toast('Upload error: '+e.message,'err');
  }
}

function toast(msg,type='ok'){
  const el=document.createElement('div');
  const colors={'ok':'#25d366','err':'#ff4757','info':'#4f8ef7'};
  const tc={'ok':'#000','err':'#fff','info':'#fff'};
  el.style.cssText=`position:fixed;bottom:24px;right:24px;z-index:9999;background:${colors[type]||colors.ok};color:${tc[type]||tc.ok};padding:10px 18px;border-radius:9px;font-size:13px;font-weight:700;font-family:'Cabinet Grotesk',sans-serif;`;
  el.textContent=msg;document.body.appendChild(el);setTimeout(()=>el.remove(),3000);
}

// ── NOTIFICATIONS & AUDIO CHIME ──────────────────────────
function playChatSound() {
  try {
    const ctx = new (window.AudioContext || window.webkitAudioContext)();
    if (ctx.state === 'suspended') ctx.resume();
    const osc = ctx.createOscillator();
    const gain = ctx.createGain();
    osc.type = 'sine';
    osc.frequency.setValueAtTime(587.33, ctx.currentTime);
    osc.frequency.setValueAtTime(880, ctx.currentTime + 0.08);
    gain.gain.setValueAtTime(0.2, ctx.currentTime);
    gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.35);
    osc.connect(gain);
    gain.connect(ctx.destination);
    osc.start();
    osc.stop(ctx.currentTime + 0.36);
  } catch(e){}
}

function notifyNewMsg(sender, text) {
  if ('Notification' in window && Notification.permission === 'granted' && document.hidden) {
    try {
      new Notification('WhatsApp from ' + sender, {
        body: (text || 'New message').substring(0, 100),
        icon: '/assets/icon-192.png'
      });
    } catch(e){}
  }
}

// Request permission once on interaction
document.addEventListener('click', function reqNotifOnce() {
  if ('Notification' in window && Notification.permission === 'default') {
    Notification.requestPermission();
  }
  document.removeEventListener('click', reqNotifOnce);
}, { once: true });

// Background poll for all conversations (updates sidebar & unread counts)
async function doPollList() {
  try {
    const res = await post({action:'poll_conv_list'});
    if (!res || !res.conversations) return;
    const list = document.getElementById('clist');
    if (!list) return;
    let anyNewInbound = false;
    res.conversations.forEach(c => {
      let ci = document.getElementById('ci-' + c.id);
      if (ci) {
        const prev = ci.querySelector('.ci-prev');
        const oldTxt = prev ? prev.textContent.trim() : '';
        const newTxt = (c.last_content || (c.last_type === 'image' ? '📷 Image' : (c.last_type === 'template' ? '📋 Template' : ''))).substring(0, 50).trim();
        if (prev && newTxt) prev.textContent = newTxt;
        const timeEl = ci.querySelector('.ci-time');
        if (timeEl && c.last_msg_time) timeEl.textContent = fmtTime(c.last_msg_time);

        let unreadBadge = ci.querySelector('.ci-unread');
        const unreadCount = parseInt(c.unread_count || 0);
        if (unreadCount > 0 && c.id != ACV) {
          if (!unreadBadge) {
            unreadBadge = document.createElement('span');
            unreadBadge.className = 'ci-unread';
            ci.querySelector('.ci-meta')?.appendChild(unreadBadge);
          }
          if (unreadBadge) unreadBadge.textContent = unreadCount;
          if (c.last_dir === 'inbound' && oldTxt !== newTxt) anyNewInbound = true;
        } else if (unreadBadge && c.id == ACV) {
          unreadBadge.remove();
        }
      }
    });
    if (anyNewInbound && !ACV) {
      playChatSound();
      notifyNewMsg('WhatsApp Customer', 'New incoming message');
    }
  } catch(e){}
}
setInterval(doPollList, 7000);

// Auto-open first conv on desktop
<?php if(!empty($convs)):?>
if(window.innerWidth>900) loadConv(<?= $convs[0]['id'] ?>);
<?php endif;?>
</script>

<?php include __DIR__ . '/../includes/user_footer.php'; ?>

