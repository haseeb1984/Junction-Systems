'use strict';
class EventBus{#h=new Map();on(e,h){if(!this.#h.has(e))this.#h.set(e,new Set());this.#h.get(e).add(h);return()=>this.#h.get(e)?.delete(h);}once(e,h){const u=this.on(e,d=>{h(d);u();});}emit(e,d){this.#h.get(e)?.forEach(h=>{try{h(d);}catch(err){console.error(`[EventBus] ${e}:`,err);}});}}
export const bus=new EventBus();
