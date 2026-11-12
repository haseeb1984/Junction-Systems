'use strict';
async function boot(){
    const r=[];
    if(document.querySelector('[data-cod-section]'))r.push(import('./modules/checkout.mjs').then(m=>m.init()));
    if(document.querySelector('[data-tracking-widget]'))r.push(import('./modules/tracking.mjs').then(m=>m.init()));
    if(document.querySelector('[data-cart-widget]'))r.push(import('./modules/cart.mjs').then(m=>m.init()));
    if(document.querySelector('#pk-affiliate-app'))r.push(import('./modules/affiliate.mjs').then(m=>m.init()));
    const tog=document.querySelector('[data-chat-toggle]');
    if(tog){const obs=new IntersectionObserver(e=>{if(e[0].isIntersecting){obs.disconnect();import('./modules/ai-chat.mjs').then(m=>m.init());}},{rootMargin:'200px'});obs.observe(tog);}
    (await Promise.allSettled(r)).forEach(r=>{if(r.status==='rejected')console.error('[JunctionStore Boot]',r.reason);});
}
if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',boot);else boot();
