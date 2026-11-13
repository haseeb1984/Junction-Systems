'use strict';
export function el(tag,attrs={},text=''){const e=document.createElement(tag);for(const[k,v]of Object.entries(attrs)){if(k==='class')e.className=v;else if(k.startsWith('data-'))e.dataset[k.slice(5)]=v;else if(k.startsWith('aria-'))e.setAttribute(k,v);else e[k]=v;}if(text)e.textContent=text;return e;}
export function find(sel,root=document){return root.querySelector(sel);}
export function mustFind(sel,root=document){const e=root.querySelector(sel);if(!e)throw new Error(`[PKStore] Required: "${sel}"`);return e;}
export function disableButton(btn,txt='Please wait...'){btn.disabled=true;btn.dataset.origText=btn.textContent;btn.textContent=txt;btn.setAttribute('aria-busy','true');}
export function enableButton(btn){btn.disabled=false;btn.textContent=btn.dataset.origText||btn.textContent;btn.removeAttribute('aria-busy');}
export function showMessage(container,msg,type='error'){container.querySelector('.pk-inline-message')?.remove();const p=el('p',{class:`pk-inline-message pk-inline-message--${type}`,role:'alert'},msg);container.appendChild(p);if(type==='success')setTimeout(()=>p.remove(),5000);}
export function clearMessages(container){container.querySelectorAll('.pk-inline-message').forEach(m=>m.remove());}
