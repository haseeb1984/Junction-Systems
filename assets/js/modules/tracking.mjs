'use strict';
import{apiRequest,ApiError}from'../lib/api-client.mjs';
import{find,mustFind,el,showMessage,clearMessages}from'../lib/dom.mjs';
const PFX='pkstore_track_';const TTL=5*60*1000;
const LABELS={booked:{en:'Order Booked',ur:'آرڈر بک'},in_transit:{en:'In Transit',ur:'راستے میں'},out_for_delivery:{en:'Out for Delivery',ur:'ڈیلیوری کے لیے نکل گیا'},delivered:{en:'Delivered',ur:'پہنچا دیا گیا'},failed_attempt:{en:'Delivery Attempted',ur:'کوشش ہوئی'},returned:{en:'Returned',ur:'واپس آ گیا'},unknown:{en:'Unknown',ur:'معلوم نہیں'}};
export function init(){const w=find('[data-tracking-widget]');if(!w)return;const inp=mustFind('[data-tracking-input]',w),btn=mustFind('[data-tracking-submit]',w),res=mustFind('[data-tracking-results]',w);
btn.addEventListener('click',()=>track(inp,res,w));inp.addEventListener('keydown',e=>{if(e.key==='Enter')track(inp,res,w);});
const url=new URLSearchParams(window.location.search).get('tracking');if(url){inp.value=url;track(inp,res,w);}
}
async function track(inp,res,w){clearMessages(w);res.textContent='';const tn=inp.value.trim().toUpperCase().replace(/\s+/g,'');if(!tn){showMessage(w,'ٹریکنگ نمبر درج کریں۔','error');inp.focus();return;}
const c=getC(tn);if(c){render(res,c);return;}
const btn=mustFind('[data-tracking-submit]',w);btn.disabled=true;btn.textContent='تلاش...';
try{const d=await apiRequest('/tracking/status',{body:{tracking_number:tn}});setC(tn,d);render(res,d);}
catch(e){if(e instanceof ApiError&&e.status===404)showMessage(w,`"${tn}" نہیں ملا۔`,'error');else showMessage(w,'سروس وقتی طور پر غیر دستیاب۔','error');}
finally{btn.disabled=false;btn.textContent='ٹریک کریں';}
}
function render(res,d){const hdr=el('div',{class:'pk-tracking__header'});const s=d.currentStatus||'unknown';const lb=LABELS[s]||LABELS.unknown;hdr.appendChild(el('strong',{},'Tracking: '));hdr.appendChild(el('code',{},d.trackingNumber||''));const badge=el('span',{style:'margin-left:8px;padding:2px 8px;background:#f6f7f7;border-radius:3px;font-size:12px;'});badge.appendChild(el('span',{},lb.en));badge.appendChild(el('span',{dir:'rtl',lang:'ur',style:'margin-left:6px;'},lb.ur));hdr.appendChild(badge);if(d.courier)hdr.appendChild(el('span',{style:'margin-left:8px;color:#646970;font-size:12px;'},d.courier));
const tl=el('ol',{class:'pk-tracking__timeline',style:'margin-top:16px;list-style:none;'});const evts=d.events||[];if(!evts.length){tl.appendChild(el('li',{},'ابھی کوئی اپڈیٹ نہیں۔'));}else{evts.forEach((evt,i)=>{const li=el('li',{style:'padding:8px 0;border-bottom:1px solid #f0f0f0;'+(i===0?'font-weight:600;':'')}); li.appendChild(el('span',{style:'color:#646970;font-size:12px;margin-right:8px;'},evt.timestamp?new Date(evt.timestamp).toLocaleString('ur-PK'):''));li.appendChild(el('span',{},evt.description||evt.status||''));if(evt.location)li.appendChild(el('span',{style:'color:#646970;font-size:12px;margin-left:8px;'},evt.location));tl.appendChild(li);});}
res.textContent='';res.append(hdr,tl);}
function getC(tn){try{const r=sessionStorage.getItem(PFX+tn);if(!r)return null;const p=JSON.parse(r);if(Date.now()-p._t>TTL){sessionStorage.removeItem(PFX+tn);return null;}return p.d;}catch{return null;}}
function setC(tn,d){try{sessionStorage.setItem(PFX+tn,JSON.stringify({_t:Date.now(),d}));}catch{}}
