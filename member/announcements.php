<?php
if (!defined('ROOT')) define('ROOT', dirname(__DIR__));
$pageTitle = 'Announcements — ChamaLedger';
require_once ROOT . '/includes/functions.php';
requireMember();
require_once ROOT . '/includes/header.php';

$pdo     = getDB();
$userId  = (int)$_SESSION['user_id'];
$openId  = (int)($_GET['id'] ?? 0);

$announcements = $pdo->query("
    SELECT a.*, u.full_name AS posted_by_name,
           (SELECT COUNT(*) FROM announcement_replies r WHERE r.announcement_id=a.id) AS reply_count
    FROM announcements a JOIN users u ON a.posted_by=u.id
    WHERE a.is_active=1 ORDER BY a.priority DESC, a.created_at DESC
")->fetchAll();
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h4 class="fw-bold mb-0"><?= t('ann_title') ?></h4>
        <small class="text-muted"><?= t('ann_subtitle') ?></small>
    </div>
</div>

<div class="row g-3">
<!-- Announcement list -->
<div class="col-md-4" id="annList">
<?php if (empty($announcements)): ?>
    <div class="card border-0 shadow-sm p-4 text-center">
        <i class="bi bi-megaphone" style="font-size:2rem;color:var(--text-muted)"></i>
        <p class="mt-2 text-muted"><?= t('announce_none') ?> yet.</p>
    </div>
<?php else: foreach ($announcements as $ann): ?>
    <div class="ann-card card border-0 shadow-sm mb-2 <?= $ann['priority']==='urgent'?'border-danger':'' ?> <?= $openId===$ann['id']?'active':'' ?>"
         data-id="<?= $ann['id'] ?>" style="cursor:pointer;transition:all .2s"
         onclick="openChat(<?= $ann['id'] ?>)">
        <div class="card-body py-3 px-3">
            <?php if ($ann['priority']==='urgent'): ?>
            <span class="badge bg-danger mb-1" style="font-size:.65rem"><?= t('ann_urgent') ?></span>
            <?php endif; ?>
            <div class="fw-bold" style="font-size:.9rem;color:var(--text)"><?= htmlspecialchars($ann['title']) ?></div>
            <div style="font-size:.75rem;color:var(--text-muted);margin-top:.2rem">
                <i class="bi bi-person-circle me-1"></i><?= htmlspecialchars($ann['posted_by_name']) ?>
                &nbsp;·&nbsp; <i class="bi bi-clock me-1"></i><?= formatDate($ann['created_at'], 'd M') ?>
                &nbsp;·&nbsp; <i class="bi bi-chat me-1"></i><?= $ann['reply_count'] ?>
            </div>
        </div>
    </div>
<?php endforeach; endif; ?>
</div>

<!-- Chat panel -->
<div class="col-md-8">
    <div class="card border-0 shadow-sm" id="chatPanel" style="min-height:500px;display:flex;flex-direction:column">
        <!-- Empty state -->
        <div id="chatEmpty" class="card-body d-flex flex-column align-items-center justify-content-center" style="flex:1;color:var(--text-muted)">
            <i class="bi bi-chat-square-text" style="font-size:3rem;opacity:.3"></i>
            <p class="mt-3"><?= t('ann_select') ?></p>
        </div>
        <!-- Chat view -->
        <div id="chatView" style="display:none;flex:1;flex-direction:column">
            <div class="card-header border-0 pb-2" style="background:var(--card-bg)">
                <div class="fw-bold" id="chatTitle" style="color:var(--text)"></div>
                <div id="chatBody" style="font-size:.85rem;color:var(--text-muted);margin-top:.35rem;line-height:1.6"></div>
                <div id="chatMeta" style="font-size:.75rem;color:var(--text-muted);margin-top:.5rem"></div>
            </div>
            <div id="chatMessages" style="flex:1;overflow-y:auto;padding:1rem;display:flex;flex-direction:column;gap:.65rem;max-height:340px;min-height:200px">
                <div class="text-center text-muted" style="font-size:.8rem" id="chatLoading"><i class="bi bi-arrow-repeat"></i> Loading…</div>
            </div>
            <div class="card-footer border-0 pt-0" style="background:var(--card-bg)">
                <div class="d-flex gap-2">
                    <input type="text" id="replyInput" class="form-control" placeholder="Write a reply…" maxlength="1000" style="font-size:.88rem">
                    <button class="btn btn-success btn-sm px-3 fw-bold" onclick="postReply()" id="replyBtn">
                        <i class="bi bi-send-fill"></i>
                    </button>
                </div>
                <div style="font-size:.7rem;color:var(--text-muted);margin-top:.35rem" id="replyCount"></div>
            </div>
        </div>
    </div>
</div>
</div>

<style>
.ann-card:hover { transform:translateX(3px); }
.ann-card.active { border-left:3px solid var(--green) !important; }
.chat-bubble { padding:.6rem .9rem; border-radius:14px; max-width:80%; font-size:.85rem; line-height:1.5; }
.chat-bubble.mine { background:var(--green); color:#060e1a; align-self:flex-end; border-bottom-right-radius:4px; }
.chat-bubble.theirs { background:var(--input-bg,rgba(15,45,74,.5)); color:var(--text); align-self:flex-start; border-bottom-left-radius:4px; }
.chat-bubble.admin-bubble { background:rgba(59,130,246,.15); color:var(--text); border:1px solid rgba(59,130,246,.3); }
.chat-sender { font-size:.7rem; margin-bottom:.2rem; color:var(--text-muted); }
.chat-time { font-size:.68rem; opacity:.6; margin-top:.25rem; }
</style>

<script>
var currentAnnId  = <?= $openId ?: 0 ?>;
var lastMsgTime   = '1970-01-01 00:00:00';
var myUserId      = <?= $userId ?>;
var csrfToken     = '<?= csrfToken() ?>';
var urgentLabel   = '<?= addslashes(t("ann_urgent")) ?>';
var pollTimer     = null;

var announcements = <?= json_encode(array_map(fn($a) => [
    'id'       => $a['id'],
    'title'    => $a['title'],
    'body'     => $a['body'],
    'priority' => $a['priority'],
    'posted_by'=> $a['posted_by_name'],
    'created_at'=> $a['created_at'],
], $announcements)) ?>;

function openChat(id) {
    currentAnnId = id;
    lastMsgTime  = '1970-01-01 00:00:00';

    // Highlight active card
    document.querySelectorAll('.ann-card').forEach(c => c.classList.remove('active'));
    var card = document.querySelector('[data-id="'+id+'"]');
    if (card) card.classList.add('active');

    // Load announcement content
    var ann = announcements.find(a => a.id == id);
    if (ann) {
        document.getElementById('chatTitle').textContent = ann.title;
        document.getElementById('chatBody').innerHTML    = ann.body.replace(/\n/g,'<br>');
        document.getElementById('chatMeta').innerHTML    = '<i class="bi bi-person-circle me-1"></i>' + ann.posted_by + ' &nbsp;·&nbsp; <i class="bi bi-clock me-1"></i>' + ann.created_at.slice(0,10);
        if (ann.priority === 'urgent') {
            document.getElementById('chatTitle').innerHTML = '<span class="badge bg-danger me-2" style="font-size:.65rem">' + urgentLabel + '</span>' + ann.title;
        }
    }

    document.getElementById('chatEmpty').style.display = 'none';
    document.getElementById('chatView').style.display  = 'flex';
    document.getElementById('chatMessages').innerHTML  = '<div class="text-center text-muted" style="font-size:.8rem"><i class="bi bi-arrow-repeat"></i> Loading…</div>';

    loadReplies(true);
    clearInterval(pollTimer);
    pollTimer = setInterval(function(){ loadReplies(false); }, 8000);
}

function loadReplies(initial) {
    fetch('<?= APP_URL ?>/api/chat_reply.php?action=load&ann_id=' + currentAnnId + '&since=' + encodeURIComponent(lastMsgTime) + '&_=' + Date.now())
    .then(r => r.json()).then(data => {
        if (!data.ok) return;
        var box = document.getElementById('chatMessages');
        if (initial) box.innerHTML = '';
        data.replies.forEach(r => {
            if (r.created_at > lastMsgTime) lastMsgTime = r.created_at;
            box.appendChild(buildBubble(r));
        });
        if (initial && data.replies.length === 0) {
            box.innerHTML = '<div class="text-center text-muted" style="font-size:.8rem;padding:2rem 0"><i class="bi bi-chat"></i> No replies yet — be the first!</div>';
        } else if (data.replies.length > 0) {
            box.scrollTop = box.scrollHeight;
        }
        document.getElementById('replyCount').textContent = box.querySelectorAll('.chat-bubble').length + ' replies';
    }).catch(()=>{});
}

function buildBubble(r) {
    var isMe    = parseInt(r.user_id) === myUserId;
    var isAdmin = r.role === 'admin';
    var wrap    = document.createElement('div');
    wrap.style.display = 'flex';
    wrap.style.flexDirection = 'column';
    wrap.style.alignItems = isMe ? 'flex-end' : 'flex-start';

    var sender = '';
    if (!isMe) sender = '<div class="chat-sender">' + escHtml(r.full_name) + (isAdmin ? ' <span class="badge bg-primary" style="font-size:.6rem">Admin</span>' : '') + '</div>';

    var bubble = document.createElement('div');
    bubble.className = 'chat-bubble ' + (isMe ? 'mine' : (isAdmin ? 'admin-bubble' : 'theirs'));
    bubble.innerHTML = sender + escHtml(r.message) + '<div class="chat-time">' + r.created_at.slice(11,16) + ' · ' + r.created_at.slice(5,10) + '</div>';
    wrap.appendChild(bubble);
    return wrap;
}

function postReply() {
    var input = document.getElementById('replyInput');
    var msg   = input.value.trim();
    if (!msg || !currentAnnId) return;
    var btn = document.getElementById('replyBtn');
    btn.disabled = true;

    var fd = new FormData();
    fd.append('action', 'post');
    fd.append('ann_id', currentAnnId);
    fd.append('message', msg);
    fd.append('csrf_token', csrfToken);

    fetch('<?= APP_URL ?>/api/chat_reply.php', { method:'POST', body:fd, credentials:'same-origin' })
    .then(r => r.json()).then(data => {
        if (data.ok) {
            input.value = '';
            var box = document.getElementById('chatMessages');
            var empty = box.querySelector('.text-center');
            if (empty) empty.remove();
            if (data.reply.created_at > lastMsgTime) lastMsgTime = data.reply.created_at;
            box.appendChild(buildBubble(data.reply));
            box.scrollTop = box.scrollHeight;
        } else {
            alert(data.msg || 'Failed to send');
        }
    }).catch(()=>{ alert('Network error'); })
    .finally(()=>{ btn.disabled = false; });
}

function escHtml(str) {
    return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

document.getElementById('replyInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); postReply(); }
});

<?php if ($openId): ?>
window.addEventListener('load', function(){ openChat(<?= $openId ?>); });
<?php endif; ?>
</script>
<?php require_once ROOT . '/includes/footer.php'; ?>
