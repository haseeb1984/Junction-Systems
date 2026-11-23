
(function(){
'use strict';
if(typeof WooAI==='undefined') return;

var isOpen=false, isLoading=false, unread=0;

var GREETING='Assalam o Alaikum! 👋 Main '+WooAI.title+' hoon. Kuch bhi poochhein — Urdu ya English mein!\n\n💬 Kisi bhi waqt human agent se baat karna ho to upar WhatsApp button dabayein.';

function g(id){return document.getElementById(id);}

function init(){
  var widget=g('woo-ai-widget');
  if(!widget) return;
  widget.style.display='block';

  g('woo-ai-launcher').addEventListener('click', togglePanel);
  g('woo-ai-minimize').addEventListener('click', closePanel);

  // Update human button URL dynamically with conversation context
  updateHumanBtn('');

  g('woo-ai-input').addEventListener('input', function(){
    g('woo-ai-send').disabled = !this.value.trim();
    this.style.height='auto';
    this.style.height=Math.min(this.scrollHeight,90)+'px';
  });
  g('woo-ai-input').addEventListener('keydown', function(e){
    if(e.key==='Enter'&&!e.shiftKey){e.preventDefault(); if(!isLoading) sendMessage();}
  });
  g('woo-ai-send').addEventListener('click', function(){ if(!isLoading) sendMessage(); });

  // Suggestion chips
  document.querySelectorAll('.woo-ai-sugg').forEach(function(btn){
    btn.addEventListener('click', function(){
      if(this.getAttribute('data-human')==='1'){
        openWhatsApp('Hi! I need help from a human agent.', '');
      } else {
        askSuggestion(this.getAttribute('data-msg'));
      }
    });
  });

  setTimeout(function(){
    appendBot(GREETING);
    setTimeout(function(){ if(!isOpen){ showBadge(1); } }, 3000);
  }, 800);
}

// ── Panel ──────────────────────────────────────────────────────
function togglePanel(){ isOpen ? closePanel() : openPanel(); }

function openPanel(){
  isOpen=true;
  g('woo-ai-panel').classList.add('open');
  g('woo-ai-panel').removeAttribute('aria-hidden');
  g('woo-ai-launcher').setAttribute('aria-expanded','true');
  g('woo-ai-icon-chat').style.display='none';
  g('woo-ai-icon-close').style.display='block';
  hideBadge();
  setTimeout(function(){ g('woo-ai-input').focus(); }, 250);
  scroll();
}

function closePanel(){
  isOpen=false;
  g('woo-ai-panel').classList.remove('open');
  g('woo-ai-panel').setAttribute('aria-hidden','true');
  g('woo-ai-launcher').setAttribute('aria-expanded','false');
  g('woo-ai-icon-chat').style.display='block';
  g('woo-ai-icon-close').style.display='none';
}

// ── Badge ──────────────────────────────────────────────────────
function showBadge(n){ unread=n; var b=g('woo-ai-badge'); b.textContent=n; b.style.display='flex'; }
function hideBadge(){ unread=0; g('woo-ai-badge').style.display='none'; }

// ── WhatsApp handoff ───────────────────────────────────────────
function buildWaUrl(lastMsg, context){
  var wa = WooAI.whatsapp || '923001234567';
  var text = 'Hi! I was chatting with '+WooAI.storeName+' Dukaan AI and need help from a human agent.';
  if(lastMsg) text += '\n\nMy question: '+lastMsg;
  if(context) text += '\n\nChat summary: '+context;
  return 'https://wa.me/'+wa+'?text='+encodeURIComponent(text);
}

function openWhatsApp(lastMsg, context){
  window.open(buildWaUrl(lastMsg, context), '_blank', 'noopener,noreferrer');
}

function updateHumanBtn(context){
  var btn = g('woo-ai-human-btn');
  if(!btn) return;
  // Get last user message from chat
  var msgs = document.querySelectorAll('.wai-msg.user');
  var lastMsg = msgs.length ? msgs[msgs.length-1].textContent : '';
  btn.href = buildWaUrl(lastMsg, context);
}

// ── Messages ───────────────────────────────────────────────────
function sendMessage(){
  var text=g('woo-ai-input').value.trim();
  if(!text||isLoading) return;
  var sugg=g('woo-ai-suggestions');
  if(sugg) sugg.style.display='none';
  appendUser(text);
  g('woo-ai-input').value='';
  g('woo-ai-input').style.height='auto';
  g('woo-ai-send').disabled=true;
  callAjax(text);
}

function askSuggestion(text){
  if(isLoading) return;
  var sugg=g('woo-ai-suggestions');
  if(sugg) sugg.style.display='none';
  appendUser(text);
  callAjax(text);
}

function callAjax(message){
  isLoading=true;
  var typing=appendTyping();
  var fd=new FormData();
  fd.append('action','woo_ai_chat');
  fd.append('nonce', WooAI.nonce);
  fd.append('message', message);

  fetch(WooAI.ajaxUrl,{method:'POST',credentials:'same-origin',body:fd})
  .then(function(r){ if(!r.ok) throw new Error('HTTP '+r.status); return r.json(); })
  .then(function(data){
    removeTyping(typing);
    isLoading=false;
    var d=data.data||{};
    var reply=d.reply||'Kuch masla hua. Dobara try karein.';

    // Update human button with latest context
    updateHumanBtn(reply.substring(0,100));

    if(d.handoff){
      // Auto WhatsApp handoff card
      appendHandoffCard(reply, d.whatsapp||WooAI.whatsapp, message);
    } else {
      appendBot(reply, !isOpen);
    }
  })
  .catch(function(err){
    removeTyping(typing);
    isLoading=false;
    appendBot('Network error. Please check your connection and try again. 🔄', false, true);
  });
}

function appendUser(text){
  var d=mk('wai-msg user'); d.textContent=text;
  g('woo-ai-messages').appendChild(d); scroll();
}

function appendBot(text, badge, isErr){
  var d=mk('wai-msg bot'+(isErr?' error':''));
  d.innerHTML=fmt(text);
  g('woo-ai-messages').appendChild(d); scroll();
  if(badge&&!isOpen){ unread++; showBadge(unread); }
}

function appendHandoffCard(botReply, wa, userQuestion){
  // First show the bot's message
  var msg=mk('wai-msg bot');
  msg.innerHTML=fmt(botReply);
  g('woo-ai-messages').appendChild(msg);

  // Then show WhatsApp CTA card
  var card=document.createElement('div');
  card.className='wai-handoff-card';
  var url=buildWaUrl(userQuestion,'');
  card.innerHTML='<p>Humara team WhatsApp pe ready hai — conversation context bhi share ho jayega.</p>'
    +'<a href="'+url+'" target="_blank" rel="noopener noreferrer" class="wai-handoff-btn">'
    +'<svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>'
    +' Connect on WhatsApp</a>';
  g('woo-ai-messages').appendChild(card);
  scroll();
  if(!isOpen){ unread++; showBadge(unread); }
}

function appendTyping(){
  var d=mk('wai-typing'); d.innerHTML='<span></span><span></span><span></span>';
  d.setAttribute('aria-label','Assistant is typing...');
  g('woo-ai-messages').appendChild(d); scroll(); return d;
}
function removeTyping(el){ if(el&&el.parentNode) el.parentNode.removeChild(el); }

function fmt(text){
  var s=text.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\"/g,'&quot;').replace(/'/g,'&#39;');
  s=s.replace(/\n/g,'<br>');
  s=s.replace(/(https?:\/\/[^\s<]+)/g,'<a href="$1" target="_blank" rel="noopener noreferrer" style="color:#34D399;text-decoration:underline">$1</a>');
  return s;
}
function mk(cls){ var d=document.createElement('div'); d.className=cls; return d; }
function scroll(){ setTimeout(function(){ var m=g('woo-ai-messages'); if(m) m.scrollTop=m.scrollHeight; },50); }

if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init);
else init();
})();
