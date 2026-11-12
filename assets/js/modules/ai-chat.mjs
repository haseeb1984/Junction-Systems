'use strict';
import{config,ApiError}from'../lib/api-client.mjs';
import{find,mustFind,el,disableButton,enableButton,showMessage}from'../lib/dom.mjs';
import{bus}from'../lib/event-bus.mjs';
const URDU=/[\u0600-\u06FF]/;
const UUID=(()=>{const k='pkstore_chat_session';const s=sessionStorage.getItem(k);if(s&&/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(s))return s;const u=([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g,c=>(c^(crypto.getRandomValues(new Uint8Array(1))[0]&(15>>(c/4)))).toString(16));sessionStorage.setItem(k,u);return u;})();
export function init(){const w=find('[data-chat-widget]');if(!w)return;const tog=mustFind('[data-chat-toggle]'),cls=mustFind('[data-chat-close]',w),msgs=mustFind('[data-chat-messages]',w),inp=mustFind('[data-chat-input]',w),sub=mustFind('[data-chat-submit]',w);
tog.addEventListener('click',()=>{const o=w.getAttribute('aria-hidden')==='false';open(w,tog,!o);if(!o){inp.focus();bus.emit('chat:opened');}});
cls.addEventListener('click',()=>{open(w,tog,false);bus.emit('chat:closed');});
inp.addEventListener('keydown',e=>{if(e.key==='Enter'&&!e.shiftKey){e.preventDefault();if(!sub.disabled)go();}});
inp.addEventListener('input',()=>{sub.disabled=inp.value.trim().length===0;});
sub.addEventListener('click',go);
append(msgs,'السلام علیکم! میں Dukaan AI ہوں۔\nHow can I help you shop today?');
async function go(){const t=inp.value.trim();if(!t)return;disableButton(sub,'بھیج رہے ہیں...');inp.value='';inp.disabled=true;user(msgs,t);
try{if(typeof EventSource!=='undefined')await stream(t,msgs);else await block(t,msgs);}
catch(e){showMessage(w,e instanceof ApiError&&e.isRateLimited?'Too many questions. Wait a few minutes.':'Error. Please try again.','error');}
finally{enableButton(sub);inp.disabled=false;inp.focus();}}
}
async function stream(t,msgs){const{bubble,node}=mkBubble(msgs);typing(bubble);let acc='',first=true;
const res=await fetch(`${config.restUrl}/chat/stream`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({session_uuid:UUID,message:t,_nonce:config.chatNonce})});
if(!res.ok||!res.body){bubble.remove();return;}
const reader=res.body.getReader(),dec=new TextDecoder();let buf='';
while(true){const{done,value}=await reader.read();if(done)break;buf+=dec.decode(value,{stream:true});const evts=buf.split('\n\n');buf=evts.pop()||'';
for(const evt of evts){if(!evt.startsWith('data: '))continue;const d=evt.slice(6).trim();if(d==='[DONE]'){fin(bubble,node,acc);scroll(msgs);return;}try{const p=JSON.parse(d);if(p.delta){if(first){hideTyping(bubble);first=false;}acc+=p.delta;node.textContent=acc;dir(bubble,acc);scroll(msgs);}}catch(_){}}}
if(acc)fin(bubble,node,acc);}
async function block(t,msgs){const{bubble,node}=mkBubble(msgs);typing(bubble);const r=await fetch(`${config.restUrl}/chat`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({session_uuid:UUID,message:t,_nonce:config.chatNonce})}).then(r=>r.json());hideTyping(bubble);node.textContent=r.reply||'';dir(bubble,r.reply||'');fin(bubble,node,r.reply||'');scroll(msgs);}
function user(l,t){const w=el('div',{class:'pk-chat__message pk-chat__message--user'});const b=el('p',{class:'pk-chat__bubble'});b.textContent=t;dir(b,t);w.appendChild(b);l.appendChild(w);scroll(l);}
function append(l,t){const{bubble,node}=mkBubble(l);node.textContent=t;dir(bubble,t);fin(bubble,node,t);}
function mkBubble(l){const w=el('div',{class:'pk-chat__message pk-chat__message--assistant'});const b=el('p',{class:'pk-chat__bubble pk-chat__bubble--streaming'});const n=document.createTextNode('');b.appendChild(n);w.appendChild(b);l.appendChild(w);scroll(l);return{bubble:b,node:n};}
function fin(b,n,t){b.classList.remove('pk-chat__bubble--streaming');n.textContent=t;}
function typing(b){const i=el('span',{class:'pk-chat__typing-indicator','aria-label':'Typing'});for(let j=0;j<3;j++)i.appendChild(el('span',{class:'pk-chat__typing-dot'}));b.appendChild(i);}
function hideTyping(b){b.querySelector('.pk-chat__typing-indicator')?.remove();}
function dir(e,t){e.dir=URDU.test(t)?'rtl':'ltr';e.setAttribute('lang',URDU.test(t)?'ur':'en');}
function scroll(l){requestAnimationFrame(()=>{l.scrollTop=l.scrollHeight;});}
function open(w,t,o){w.setAttribute('aria-hidden',String(!o));w.classList.toggle('pk-chat--open',o);t.setAttribute('aria-expanded',String(o));}
