    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>
<script src="<?= APP_URL ?>/assets/js/dashboard.js"></script>
<?php if (isset($extraScripts)) echo $extraScripts; ?>
<script>
/* ============================================================
   ChamaLedger — Theme Toggle + Notification Polling
   (dashboard.js handles: sidebar, notifications dropdown, 
    confirm dialogs, alerts, formatMoney, loan calculator)
   ============================================================ */

// ── Apply saved theme icon on load ──────────────────────────
(function(){
    var saved = localStorage.getItem('cl_theme') || 'dark';
    var icon = document.getElementById('themeIcon');
    if (icon) icon.className = saved === 'dark' ? 'bi bi-moon-stars-fill' : 'bi bi-sun-fill';
})();

document.addEventListener('DOMContentLoaded', function(){

    // NOTE: Theme toggle click handler is registered in header.php — do not re-register here.

    // ── Background Notification Polling (every 30s) ────────────
    var appUrl = (document.body && document.body.dataset.appUrl) || '';
    if (!appUrl) return;

    var lastSeen = new Date(Date.now() - 60000).toISOString().slice(0,19).replace('T',' ');

    function pollNotifs(){
        fetch(appUrl + '/api/notifications_poll.php?since=' + encodeURIComponent(lastSeen),
              {credentials:'same-origin'})
        .then(function(r){ return r.ok ? r.json() : null; })
        .then(function(d){
            if (!d || !d.ok) return;
            lastSeen = d.server_time || lastSeen;
            // Update bell badge count
            var badge = document.querySelector('.badge-dot');
            if (badge) {
                if (d.unread > 0) {
                    badge.textContent = d.unread > 9 ? '9+' : d.unread;
                    badge.style.display = 'inline-flex';
                } else {
                    badge.style.display = 'none';
                }
            }
            // Show toast for brand-new notifications
            if (d.notifications && d.notifications.length) {
                d.notifications.forEach(function(n, i){
                    setTimeout(function(){ showNotifToast(n); }, i * 700);
                });
            }
        })
        .catch(function(){});
    }

    function showNotifToast(n){
        var colors = {success:'#00c471', warning:'#f59e0b', danger:'#ef4444', info:'#3b82f6'};
        var c = colors[n.type] || colors.info;
        var el = document.createElement('div');
        el.style.cssText = 'position:fixed;top:70px;right:1rem;z-index:9998;'
            + 'background:var(--card-bg,#0d1f38);border:1px solid ' + c + '33;'
            + 'border-left:4px solid ' + c + ';border-radius:10px;padding:.75rem 1rem;'
            + 'max-width:300px;box-shadow:0 4px 24px rgba(0,0,0,.35);cursor:pointer;'
            + 'color:var(--text,#e2eaf4);font-size:.82rem;transition:opacity .4s ease;';
        el.innerHTML = '<div style="font-weight:700;margin-bottom:.2rem">'
            + esc(n.title) + '</div><div style="color:var(--text-muted,#6b87a8)">'
            + esc(n.message) + '</div>';
        el.onclick = function(){
            el.remove();
            if (n.link && n.link !== '#') window.location.href = n.link;
        };
        document.body.appendChild(el);
        setTimeout(function(){ el.style.opacity = '0'; }, 5000);
        setTimeout(function(){ if (el.parentNode) el.remove(); }, 5500);
    }

    function esc(s){
        return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    setTimeout(pollNotifs, 5000);
    setInterval(pollNotifs, 30000);

}); // end DOMContentLoaded
</script>

<?php if (isLoggedIn()): ?>
<!-- Help Bot -->
<div id="helpBot" style="position:fixed;bottom:1.5rem;right:1.5rem;z-index:9999">

  <button id="botToggle" onclick="toggleBot()" title="Help Assistant"
    style="width:56px;height:56px;border-radius:50%;background:var(--green,#00c471);border:none;
           cursor:pointer;box-shadow:0 4px 20px rgba(0,196,113,.45);display:flex;
           align-items:center;justify-content:center;position:relative">
    <i class="bi bi-robot" style="font-size:1.5rem;color:#060e1a"></i>
  </button>

  <div id="botWindow" style="display:none;position:absolute;bottom:70px;right:0;width:320px;
       border-radius:16px;overflow:hidden;background:var(--card-bg,#0d1f38);
       border:1px solid rgba(0,196,113,.2);box-shadow:0 12px 48px rgba(0,0,0,.5);
       flex-direction:column">

    <div style="background:linear-gradient(135deg,#060e1a,#0a1f35);padding:.85rem 1rem;
                display:flex;align-items:center;gap:.65rem;border-bottom:1px solid rgba(0,196,113,.15)">
      <div style="width:36px;height:36px;background:rgba(0,196,113,.15);border-radius:50%;
                  display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="bi bi-robot" style="color:#00c471;font-size:1.1rem"></i>
      </div>
      <div style="flex:1">
        <div style="color:#e8f0f8;font-weight:700;font-size:.88rem">ChamaBot</div>
        <div style="color:#00c471;font-size:.7rem">Always here to help</div>
      </div>
      <button onclick="toggleBot()" style="background:none;border:none;color:#7a94b0;cursor:pointer;font-size:1.1rem;padding:0">
        <i class="bi bi-x-lg"></i>
      </button>
    </div>

    <div id="botMessages" style="overflow-y:auto;padding:.85rem;display:flex;flex-direction:column;
         gap:.6rem;max-height:320px;min-height:180px">
      <div style="align-self:flex-start;max-width:90%">
        <div style="background:rgba(0,196,113,.12);border:1px solid rgba(0,196,113,.2);color:#e8f0f8;
                    padding:.6rem .9rem;border-radius:12px 12px 12px 3px;font-size:.82rem;line-height:1.55">
          👋 Hi! I'm ChamaBot. Ask me anything — contributions, loans, balance, fines, or how to use any feature!
        </div>
      </div>
    </div>

    <div id="quickBtns" style="padding:.5rem .85rem;display:flex;flex-wrap:wrap;gap:.4rem;border-top:1px solid rgba(255,255,255,.05)">
      <button onclick="quickAsk('How do I pay my contribution?')" class="qbtn">💰 Pay</button>
      <button onclick="quickAsk('How do I apply for a loan?')" class="qbtn">📋 Loan</button>
      <button onclick="quickAsk('What is my savings balance?')" class="qbtn">💳 Balance</button>
      <button onclick="quickAsk('How do I update my profile?')" class="qbtn">👤 Profile</button>
    </div>

    <div style="padding:.65rem .85rem;border-top:1px solid rgba(255,255,255,.05);display:flex;gap:.5rem">
      <input id="botInput" type="text" placeholder="Ask me anything…" maxlength="300"
        style="flex:1;background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);
               border-radius:8px;padding:.55rem .75rem;color:#e8f0f8;font-size:.82rem;outline:none">
      <button onclick="sendBotMsg()" id="botSendBtn"
        style="width:36px;height:36px;border-radius:8px;background:#00c471;border:none;
               cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0">
        <i class="bi bi-send-fill" style="color:#060e1a;font-size:.85rem"></i>
      </button>
    </div>
  </div>
</div>

<style>
.qbtn{background:rgba(255,255,255,.06);border:1px solid rgba(255,255,255,.1);color:#7a94b0;
      border-radius:99px;padding:.3rem .7rem;font-size:.72rem;cursor:pointer}
.qbtn:hover{background:rgba(0,196,113,.12);border-color:rgba(0,196,113,.3);color:#00c471}
.bot-dot{display:inline-block;width:6px;height:6px;background:#00c471;border-radius:50%;
         margin:0 2px;animation:bd .9s ease-in-out infinite}
.bot-dot:nth-child(2){animation-delay:.15s}.bot-dot:nth-child(3){animation-delay:.3s}
@keyframes bd{0%,80%,100%{transform:scale(.6);opacity:.4}40%{transform:scale(1);opacity:1}}
</style>

<script>
(function(){
var open=false, loading=false;
var BOT_URL='<?= APP_URL ?>/api/helpbot.php';

window.toggleBot=function(){
  open=!open;
  var w=document.getElementById('botWindow');
  w.style.display=open?'flex':'none';
  w.style.flexDirection='column';
  if(open) setTimeout(function(){document.getElementById('botInput').focus();},80);
};

window.quickAsk=function(q){
  document.getElementById('quickBtns').style.display='none';
  sendMsg(q);
};

window.sendBotMsg=function(){
  var inp=document.getElementById('botInput');
  sendMsg(inp.value.trim());
  inp.value='';
};

document.addEventListener('DOMContentLoaded',function(){
  var inp=document.getElementById('botInput');
  if(inp) inp.addEventListener('keydown',function(e){
    if(e.key==='Enter'){e.preventDefault();sendBotMsg();}
  });
});

function sendMsg(msg){
  if(loading||!msg) return;
  addBubble(msg,'user');

  var tid=document.getElementById('botMessages');
  var tw=document.createElement('div');
  tw.style.cssText='align-self:flex-start';
  tw.innerHTML='<div style="padding:.5rem .8rem;background:rgba(0,196,113,.08);border-radius:12px 12px 12px 3px">'
    +'<span class="bot-dot"></span><span class="bot-dot"></span><span class="bot-dot"></span></div>';
  tid.appendChild(tw);
  scrollB();

  loading=true;
  document.getElementById('botSendBtn').disabled=true;

  fetch(BOT_URL,{
    method:'POST',
    credentials:'same-origin',
    headers:{'Content-Type':'application/json'},
    body:JSON.stringify({message:msg})
  })
  .then(function(r){return r.json();})
  .then(function(d){
    tw.remove();
    addBubble(d.reply||'Sorry, something went wrong.','bot');
  })
  .catch(function(){
    tw.remove();
    addBubble('Connection error. Please try again.','bot');
  })
  .finally(function(){
    loading=false;
    document.getElementById('botSendBtn').disabled=false;
  });
}

function addBubble(text,who){
  var box=document.getElementById('botMessages');
  var w=document.createElement('div');
  w.style.cssText='display:flex;flex-direction:column;align-items:'+(who==='user'?'flex-end':'flex-start');
  var b=document.createElement('div');
  b.style.cssText='max-width:88%;padding:.6rem .9rem;font-size:.82rem;line-height:1.6;'
    +(who==='user'
      ?'background:#00c471;color:#060e1a;border-radius:12px 12px 3px 12px;font-weight:500'
      :'background:rgba(0,196,113,.1);border:1px solid rgba(0,196,113,.18);color:#e8f0f8;border-radius:12px 12px 12px 3px');
  // Safe text rendering with basic bold support
  var safe=text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  safe=safe.replace(/\*\*([\s\S]*?)\*\*/g,'<strong>$1</strong>');
  safe=safe.replace(/\n/g,'<br>');
  b.innerHTML=safe;
  w.appendChild(b);
  box.appendChild(w);
  scrollB();
}

function scrollB(){
  var b=document.getElementById('botMessages');
  setTimeout(function(){b.scrollTop=b.scrollHeight;},50);
}
})();
</script>
<?php endif; ?>

</body>
</html>
