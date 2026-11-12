'use strict';
import{find}from'../lib/dom.mjs';
import{bus}from'../lib/event-bus.mjs';
export function init(){document.querySelectorAll('[data-add-to-cart]').forEach(btn=>{btn.addEventListener('click',async()=>{const id=btn.dataset.productId;if(!id)return;btn.disabled=true;try{await fetch('/?wc-ajax=add_to_cart',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`product_id=${id}&quantity=1`});bus.emit('cart:item-added',{productId:id});const c=find('.pk-cart-count');if(c)c.textContent=String(parseInt(c.textContent||'0',10)+1);}catch(_){}finally{btn.disabled=false;}});});}
