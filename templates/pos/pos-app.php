<?php
/**
 * PK Store POS — Point of Sale Interface
 * Offline-first PWA. Works on any device browser.
 * Access: yourstore.pk/wp-admin/admin.php?page=pkstore-pos
 */
defined('ABSPATH') || exit;
if (!current_user_can('use_junction_pos')) wp_die('Unauthorized.');
$restUrl   = rest_url('pkstore/v1');
$nonce     = wp_create_nonce('wp_rest');
$storeName = get_bloginfo('name');
$locations = []; // Fetched by JS on load
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title><?= esc_html($storeName) ?> — POS</title>
<style>
:root{--blue:#0073aa;--dark:#1d2327;--gray:#646970;--green:#00a32a;--red:#d63638;--orange:#f39c12;--bg:#f0f2f5;--white:#fff;--border:#ddd;--radius:8px}
*{box-sizing:border-box;margin:0;padding:0;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif}
body{background:var(--bg);color:var(--dark);height:100vh;overflow:hidden}
#pos-root{display:grid;grid-template-rows:56px 1fr;grid-template-columns:1fr 380px;height:100vh;gap:0}
/* Header */
#pos-header{grid-column:1/-1;background:var(--blue);color:#fff;display:flex;align-items:center;padding:0 16px;gap:16px;box-shadow:0 2px 8px rgba(0,0,0,.2)}
.pos-header-brand{font-size:18px;font-weight:700}
.pos-header-session{font-size:13px;opacity:.8;margin-left:8px}
.pos-sync-badge{margin-left:auto;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700}
.pos-sync-badge.online{background:rgba(0,163,42,.3)}
.pos-sync-badge.offline{background:rgba(214,54,56,.4)}
.pos-header-btn{background:rgba(255,255,255,.2);border:none;color:#fff;padding:6px 14px;border-radius:20px;cursor:pointer;font-size:13px;font-weight:600}
.pos-header-btn:hover{background:rgba(255,255,255,.3)}
/* Left panel */
#pos-left{background:var(--white);display:flex;flex-direction:column;border-right:1px solid var(--border);overflow:hidden}
.pos-search-bar{padding:12px;border-bottom:1px solid var(--border);display:flex;gap:8px}
.pos-search-input{flex:1;padding:10px 14px;border:2px solid var(--border);border-radius:var(--radius);font-size:15px}
.pos-search-input:focus{outline:none;border-color:var(--blue)}
.pos-scan-btn{background:var(--blue);color:#fff;border:none;padding:10px 16px;border-radius:var(--radius);cursor:pointer;font-size:18px}
.pos-product-grid{flex:1;overflow-y:auto;padding:12px;display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;align-content:start}
.pos-product-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:12px;cursor:pointer;transition:all .15s}
.pos-product-card:hover{border-color:var(--blue);box-shadow:0 2px 8px rgba(0,115,170,.15)}
.pos-product-card:active{transform:scale(.97)}
.pos-product-name{font-size:13px;font-weight:600;margin-bottom:4px;line-height:1.3}
.pos-product-sku{font-size:11px;color:var(--gray)}
.pos-product-price{font-size:16px;font-weight:700;color:var(--blue);margin-top:6px}
.pos-product-stock{font-size:11px;margin-top:2px}
.pos-product-stock.low{color:var(--red)}
.pos-product-stock.ok{color:var(--green)}
/* Right panel — Cart */
#pos-right{background:var(--white);display:flex;flex-direction:column;overflow:hidden}
.pos-cart-header{padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between}
.pos-cart-title{font-size:16px;font-weight:700}
.pos-clear-btn{background:none;border:1px solid var(--red);color:var(--red);padding:4px 12px;border-radius:4px;cursor:pointer;font-size:12px}
.pos-cart-items{flex:1;overflow-y:auto;padding:8px}
.pos-cart-item{display:grid;grid-template-columns:1fr auto auto auto;align-items:center;gap:8px;padding:10px 8px;border-bottom:1px solid #f5f5f5}
.pos-cart-item-name{font-size:13px;font-weight:600}
.pos-cart-item-price{font-size:12px;color:var(--gray)}
.pos-qty-btn{width:28px;height:28px;border:1px solid var(--border);background:var(--bg);border-radius:4px;cursor:pointer;font-size:16px;font-weight:600;display:flex;align-items:center;justify-content:center}
.pos-qty-btn:hover{background:var(--blue);color:#fff;border-color:var(--blue)}
.pos-qty-val{min-width:32px;text-align:center;font-weight:700;font-size:14px}
.pos-remove-btn{background:none;border:none;color:var(--red);cursor:pointer;font-size:16px;padding:0 4px}
.pos-cart-footer{border-top:1px solid var(--border);padding:12px 16px}
.pos-total-row{display:flex;justify-content:space-between;margin-bottom:6px;font-size:14px}
.pos-total-row.grand{font-size:18px;font-weight:700;color:var(--dark)}
.pos-discount-row{display:flex;gap:8px;margin-bottom:12px}
.pos-discount-input{flex:1;padding:8px;border:1px solid var(--border);border-radius:var(--radius);font-size:14px}
.pos-customer-row{display:flex;gap:8px;margin-bottom:10px}
.pos-customer-input{flex:1;padding:8px;border:1px solid var(--border);border-radius:var(--radius);font-size:13px}
/* Payment methods */
.pos-payment-methods{display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:10px}
.pos-pm-btn{padding:10px;border:2px solid var(--border);border-radius:var(--radius);background:var(--white);cursor:pointer;font-size:13px;font-weight:600;text-align:center;transition:all .15s}
.pos-pm-btn.active{border-color:var(--blue);background:#f0f8ff;color:var(--blue)}
.pos-charge-btn{width:100%;padding:14px;background:var(--green);color:#fff;border:none;border-radius:var(--radius);font-size:16px;font-weight:700;cursor:pointer;letter-spacing:.02em}
.pos-charge-btn:hover{background:#00852a}
.pos-charge-btn:disabled{opacity:.5;cursor:not-allowed}
/* Camera scanner overlay */
#pos-scanner-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.8);z-index:1000;flex-direction:column;align-items:center;justify-content:center}
#pos-scanner-overlay.active{display:flex}
#pos-scanner-video{width:min(400px,90vw);border-radius:var(--radius);border:3px solid var(--green)}
.pos-scanner-close{margin-top:16px;background:var(--white);border:none;padding:10px 28px;border-radius:var(--radius);font-size:15px;cursor:pointer;font-weight:600}
/* Success toast */
#pos-toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:var(--dark);color:#fff;padding:12px 24px;border-radius:30px;font-size:14px;font-weight:600;opacity:0;transition:opacity .3s;pointer-events:none;z-index:999}
#pos-toast.show{opacity:1}
/* Modal */
.pos-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:900;align-items:center;justify-content:center}
.pos-modal-overlay.active{display:flex}
.pos-modal{background:#fff;border-radius:var(--radius);padding:24px;min-width:320px;max-width:480px;width:90%}
.pos-modal h2{margin-bottom:16px;font-size:18px}
.pos-modal input{width:100%;padding:10px;border:1px solid var(--border);border-radius:var(--radius);font-size:15px;margin-bottom:12px}
.pos-modal-btns{display:flex;gap:8px;justify-content:flex-end}
.pos-modal-btn{padding:10px 20px;border-radius:var(--radius);border:none;cursor:pointer;font-size:14px;font-weight:600}
.pos-modal-btn.primary{background:var(--blue);color:#fff}
.pos-modal-btn.secondary{background:var(--bg);color:var(--dark)}
/* Empty cart */
.pos-cart-empty{flex:1;display:flex;align-items:center;justify-content:center;color:var(--gray);font-size:14px;flex-direction:column;gap:8px}
@media(max-width:700px){
  #pos-root{grid-template-columns:1fr;grid-template-rows:56px auto 1fr}
  #pos-left{max-height:45vh}
  #pos-right{max-height:55vh}
}
</style>
</head>
<body>
<div id="pos-root">
  <header id="pos-header">
    <span class="pos-header-brand">🛒 <?= esc_html($storeName) ?> POS</span>
    <span class="pos-header-session" id="pos-session-label">Loading...</span>
    <span class="pos-sync-badge online" id="pos-sync-badge">● Online</span>
    <button class="pos-header-btn" onclick="POS.openEndDayModal()">End Day</button>
    <button class="pos-header-btn" onclick="window.close()">✕ Exit</button>
  </header>
  <div id="pos-left">
    <div class="pos-search-bar">
      <input type="text" class="pos-search-input" id="pos-search" placeholder="Search product or scan barcode..." autocomplete="off">
      <button class="pos-scan-btn" onclick="POS.openScanner()" title="Scan barcode with camera">📷</button>
    </div>
    <div class="pos-product-grid" id="pos-product-grid"></div>
  </div>
  <div id="pos-right">
    <div class="pos-cart-header">
      <span class="pos-cart-title">🧾 Current Sale</span>
      <button class="pos-clear-btn" onclick="POS.clearCart()">Clear</button>
    </div>
    <div class="pos-cart-items" id="pos-cart-items">
      <div class="pos-cart-empty"><span style="font-size:32px">🛍️</span><span>Scan or search to add items</span></div>
    </div>
    <div class="pos-cart-footer">
      <div class="pos-total-row"><span>Subtotal</span><span id="pos-subtotal">PKR 0</span></div>
      <div class="pos-discount-row">
        <input type="number" class="pos-discount-input" id="pos-discount" placeholder="Discount (PKR)" min="0" oninput="POS.updateTotals()">
      </div>
      <div class="pos-total-row grand"><span>TOTAL</span><span id="pos-total">PKR 0</span></div>
      <div class="pos-customer-row">
        <input type="text" class="pos-customer-input" id="pos-cname" placeholder="Customer name (optional)">
        <input type="tel" class="pos-customer-input" id="pos-cphone" placeholder="03XXXXXXXXX">
      </div>
      <div class="pos-payment-methods">
        <button class="pos-pm-btn active" data-method="cash" onclick="POS.selectPayment(this)">💵 Cash</button>
        <button class="pos-pm-btn" data-method="jazzcash" onclick="POS.selectPayment(this)">💚 JazzCash</button>
        <button class="pos-pm-btn" data-method="easypaisa" onclick="POS.selectPayment(this)">🟠 EasyPaisa</button>
        <button class="pos-pm-btn" data-method="card" onclick="POS.selectPayment(this)">💳 Card</button>
      </div>
      <label style="display:flex;align-items:center;gap:8px;font-size:13px;margin:4px 0 8px;">
        <input type="checkbox" id="pos-split-toggle" onchange="POS.toggleSplitPayment()" style="width:auto;margin:0;"> Split payment across methods
      </label>
      <div id="pos-split-payment" style="display:none;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:10px;">
        <label style="font-size:12px;">Cash (PKR)<input type="number" min="0" step="1" data-split-method="cash" value="0" style="width:100%;padding:7px;border:1px solid var(--border);border-radius:5px;"></label>
        <label style="font-size:12px;">Card (PKR)<input type="number" min="0" step="1" data-split-method="card" value="0" style="width:100%;padding:7px;border:1px solid var(--border);border-radius:5px;"></label>
        <label style="font-size:12px;">JazzCash (PKR)<input type="number" min="0" step="1" data-split-method="jazzcash" value="0" style="width:100%;padding:7px;border:1px solid var(--border);border-radius:5px;"></label>
        <label style="font-size:12px;">EasyPaisa (PKR)<input type="number" min="0" step="1" data-split-method="easypaisa" value="0" style="width:100%;padding:7px;border:1px solid var(--border);border-radius:5px;"></label>
      </div>
      <button class="pos-charge-btn" id="pos-charge-btn" onclick="POS.processSale()">CHARGE — PKR 0</button>
    </div>
  </div>
</div>

<!-- Camera Scanner Overlay -->
<div id="pos-scanner-overlay">
  <video id="pos-scanner-video" autoplay playsinline></video>
  <button class="pos-scanner-close" onclick="POS.closeScanner()">Cancel</button>
</div>

<!-- Open Session Modal -->
<div class="pos-modal-overlay active" id="pos-open-modal">
  <div class="pos-modal">
    <h2>Open POS Session</h2>
    <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px;">Opening Cash in Drawer (PKR)</label>
    <input type="number" id="pos-opening-cash" value="0" min="0" placeholder="e.g. 5000">
    <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px;margin-top:8px;">Location</label>
    <select id="pos-location-select" style="width:100%;padding:10px;border:1px solid #ddd;border-radius:8px;font-size:15px;margin-bottom:12px;"></select>
    <div class="pos-modal-btns">
      <button class="pos-modal-btn primary" onclick="POS.openSession()">Open Register</button>
    </div>
  </div>
</div>

<!-- End Day Modal -->
<div class="pos-modal-overlay" id="pos-endday-modal">
  <div class="pos-modal">
    <h2>Close POS Session</h2>
    <p style="font-size:13px;color:#646970;margin-bottom:12px;">Count the physical cash in your drawer and enter the amount below.</p>
    <label style="font-size:13px;font-weight:600;display:block;margin-bottom:6px;">Closing Cash Count (PKR)</label>
    <input type="number" id="pos-closing-cash" value="0" min="0">
    <div id="pos-session-summary" style="background:#f6f7f7;border-radius:8px;padding:12px;margin:12px 0;font-size:13px;"></div>
    <div class="pos-modal-btns">
      <button class="pos-modal-btn secondary" onclick="document.getElementById('pos-endday-modal').classList.remove('active')">Cancel</button>
      <button class="pos-modal-btn primary" onclick="POS.closeSession()">Close Register</button>
    </div>
  </div>
</div>

<div id="pos-toast"></div>

<script>
const POS_CONFIG = {
    restUrl: '<?= esc_js($restUrl) ?>',
    nonce:   '<?= esc_js($nonce) ?>',
    storeName: '<?= esc_js($storeName) ?>'
};

// IndexedDB for offline queue
const DB = {
    db: null,
    async open() {
        return new Promise((res, rej) => {
            const req = indexedDB.open('pkstore_pos', 2);
            req.onupgradeneeded = e => {
                const db = e.target.result;
                if (!db.objectStoreNames.contains('queue')) db.createObjectStore('queue', {keyPath:'ref'});
                if (!db.objectStoreNames.contains('products')) db.createObjectStore('products', {keyPath:'id'});
            };
            req.onsuccess = e => { this.db = e.target.result; res(this.db); };
            req.onerror   = e => rej(e);
        });
    },
    async put(store, val) { return new Promise((res,rej)=>{const t=this.db.transaction(store,'readwrite');const r=t.objectStore(store).put(val);r.onsuccess=()=>res(r.result);r.onerror=rej;}); },
    async getAll(store) { return new Promise((res,rej)=>{const t=this.db.transaction(store,'readonly');const r=t.objectStore(store).getAll();r.onsuccess=()=>res(r.result);r.onerror=rej;}); },
    async delete(store, key) { return new Promise((res,rej)=>{const t=this.db.transaction(store,'readwrite');const r=t.objectStore(store).delete(key);r.onsuccess=()=>res();r.onerror=rej;}); },
};

const POS = {
    cart: [], products: [], sessionId: null, locationId: 1,
    paymentMethod: 'cash', isOnline: navigator.onLine, scannerStream: null,
    isFlushingQueue: false,

    async init() {
        await DB.open();
        await this.loadLocations();
        window.addEventListener('online',  () => this.setOnline(true));
        window.addEventListener('offline', () => this.setOnline(false));
        if (navigator.onLine) this.flushOfflineQueue(true);
        window.setInterval(() => {
            if (navigator.onLine) this.flushOfflineQueue(true);
        }, 30000);
        document.getElementById('pos-search').addEventListener('input', e => this.searchProducts(e.target.value));
        document.getElementById('pos-search').addEventListener('keydown', e => { if(e.key==='Enter') this.handleBarcodeInput(e.target.value.trim()); });
    },

    async loadLocations() {
        try {
            const r = await this.api('/inventory/locations');
            const sel = document.getElementById('pos-location-select');
            sel.innerHTML = r.locations.filter(l=>l.is_pos).map(l=>`<option value="${l.id}">${l.name}</option>`).join('');
        } catch(_) {}
    },

    async openSession() {
        const openingCash  = parseInt(document.getElementById('pos-opening-cash').value)||0;
        const locationId   = parseInt(document.getElementById('pos-location-select').value)||1;
        try {
            const r = await this.api('/pos/session/open', 'POST', {location_id: locationId, opening_cash: openingCash});
            this.sessionId  = r.session_id;
            this.locationId = locationId;
            document.getElementById('pos-open-modal').classList.remove('active');
            document.getElementById('pos-session-label').textContent = `Session #${r.session_id}`;
            await this.loadProducts();
            await this.flushOfflineQueue(true);
            this.toast(r.resumed ? 'Session resumed.' : `Register opened with PKR ${openingCash.toLocaleString()} cash.`);
        } catch(e) { alert('Failed to open session: ' + e.message); }
    },

    async loadProducts(search = '') {
        try {
            const r = await this.api(`/inventory/products?limit=80&location_id=${this.locationId}&search=${encodeURIComponent(search)}`,'GET');
            this.products = r.products;
            await Promise.all(this.products.map(p => DB.put('products', p)));
            this.renderProducts(this.products);
        } catch(_) {
            // Offline: load from IndexedDB cache
            const cached = await DB.getAll('products');
            this.products = cached.filter(p => p.location_id === this.locationId && (!search || p.name.toLowerCase().includes(search.toLowerCase()) || p.sku.toLowerCase().includes(search.toLowerCase())));
            this.renderProducts(this.products);
        }
    },

    renderProducts(products) {
        const grid = document.getElementById('pos-product-grid');
        if (!products.length) { grid.innerHTML = '<div style="grid-column:1/-1;text-align:center;color:#646970;padding:40px;">No products found.</div>'; return; }
        grid.innerHTML = products.map(p => `
            <div class="pos-product-card" onclick="POS.addToCart(${p.id})">
                <div class="pos-product-name">${this.esc(p.name)}</div>
                <div class="pos-product-sku">${this.esc(p.sku)}</div>
                <div class="pos-product-price">PKR ${p.sale_price.toLocaleString()}</div>
                <div class="pos-product-stock ${p.total_stock<=p.reorder_point?'low':'ok'}">${p.total_stock} ${this.esc(p.unit)} ${p.total_stock<=p.reorder_point?'⚠ Low':''}</div>
            </div>`).join('');
    },

    async searchProducts(q) {
        if (q.length < 2) { this.renderProducts(this.products); return; }
        await this.loadProducts(q);
    },

    async handleBarcodeInput(barcode) {
        if (!barcode) return;
        try {
            const r = await this.api(`/inventory/barcode/${encodeURIComponent(barcode)}?location_id=${this.locationId}`,'GET');
            if (r.success) { this.addToCartByProduct(r.product); document.getElementById('pos-search').value = ''; }
        } catch(_) {
            const local = this.products.find(p => p.barcode === barcode || p.sku === barcode);
            if (local) { this.addToCartByProduct(local); document.getElementById('pos-search').value = ''; }
            else this.toast('Product not found for: ' + barcode);
        }
    },

    addToCart(productId) {
        const p = this.products.find(p => p.id === productId);
        if (p) this.addToCartByProduct(p);
    },

    addToCartByProduct(p) {
        const available = Number(p.total_stock ?? 0);
        const existing = this.cart.find(i => i.id === p.id);
        if (available < 1 || (existing && existing.qty >= available)) {
            this.toast('Insufficient stock at this POS location.');
            return;
        }
        if (existing) existing.qty++;
        else this.cart.push({id:p.id, sku:p.sku, name:p.name, price:p.sale_price, qty:1});
        this.renderCart();
        this.toast(`+ ${p.name}`);
    },

    renderCart() {
        const el = document.getElementById('pos-cart-items');
        if (!this.cart.length) { el.innerHTML = '<div class="pos-cart-empty"><span style="font-size:32px">🛍️</span><span>Scan or search to add items</span></div>'; this.updateTotals(); return; }
        el.innerHTML = this.cart.map((item,i) => `
            <div class="pos-cart-item">
                <div><div class="pos-cart-item-name">${this.esc(item.name)}</div><div class="pos-cart-item-price">PKR ${item.price.toLocaleString()} each</div></div>
                <button class="pos-qty-btn" onclick="POS.changeQty(${i},-1)">−</button>
                <span class="pos-qty-val">${item.qty}</span>
                <button class="pos-qty-btn" onclick="POS.changeQty(${i},1)">+</button>
                <button class="pos-remove-btn" onclick="POS.removeItem(${i})">✕</button>
            </div>`).join('');
        this.updateTotals();
    },

    changeQty(idx, delta) {
        this.cart[idx].qty += delta;
        if (this.cart[idx].qty <= 0) this.cart.splice(idx,1);
        this.renderCart();
    },

    removeItem(idx) { this.cart.splice(idx,1); this.renderCart(); },
    clearCart()     { this.cart=[]; document.getElementById('pos-discount').value=''; this.renderCart(); },

    updateTotals() {
        const subtotal = this.cart.reduce((s,i)=>s+(i.price*i.qty),0);
        const discount = parseInt(document.getElementById('pos-discount').value)||0;
        const total    = Math.max(0, subtotal - discount);
        document.getElementById('pos-subtotal').textContent  = 'PKR ' + subtotal.toLocaleString();
        document.getElementById('pos-total').textContent     = 'PKR ' + total.toLocaleString();
        document.getElementById('pos-charge-btn').textContent= `CHARGE — PKR ${total.toLocaleString()}`;
        document.getElementById('pos-charge-btn').disabled   = this.cart.length === 0;
    },

    selectPayment(btn) {
        document.querySelectorAll('.pos-pm-btn').forEach(b=>b.classList.remove('active'));
        btn.classList.add('active');
        this.paymentMethod = btn.dataset.method;
    },

    toggleSplitPayment() {
        const enabled = document.getElementById('pos-split-toggle').checked;
        document.getElementById('pos-split-payment').style.display = enabled ? 'grid' : 'none';
    },

    async processSale() {
        if (!this.cart.length) return;
        if (!this.sessionId) { alert('Please open a session first.'); return; }
        const btn  = document.getElementById('pos-charge-btn');
        btn.disabled = true; btn.textContent = 'Processing...';
        const discount = parseInt(document.getElementById('pos-discount').value)||0;
        const cname    = document.getElementById('pos-cname').value.trim()||'Walk-in';
        const cphone   = document.getElementById('pos-cphone').value.trim();
        const items    = this.cart.map(i=>({product_id:i.id,sku:i.sku,name:i.name,quantity:i.qty,unit_price_pkr:i.price,discount_pkr:0}));
        const total    = Math.max(0, this.cart.reduce((s,i)=>s+(i.price*i.qty),0) - discount);
        const orderRef = 'P-' + Array.from(crypto.getRandomValues(new Uint8Array(13))).map(b=>b.toString(16).padStart(2,'0')).join('');
        let paymentSplit = null;
        if (document.getElementById('pos-split-toggle').checked) {
            paymentSplit = {};
            let splitTotal = 0;
            document.querySelectorAll('[data-split-method]').forEach(input => {
                const raw = input.value.trim();
                const amount = raw === '' ? 0 : Number(raw);
                if (!Number.isSafeInteger(amount) || amount < 0) paymentSplit = false;
                else if (amount > 0) {
                    paymentSplit[input.dataset.splitMethod] = amount;
                    splitTotal += amount;
                }
            });
            if (paymentSplit === false || splitTotal !== total || Object.keys(paymentSplit).length === 0) {
                this.toast('Split payment amounts must be whole, non-negative values equal to the sale total.');
                btn.disabled = false; this.updateTotals();
                return;
            }
        }
        const payload  = {order_ref:orderRef, session_id:this.sessionId, location_id:this.locationId, items, payment_method:this.paymentMethod, customer_name:cname, customer_phone:cphone, discount_pkr:discount, ...(paymentSplit ? {payment_split:paymentSplit} : {})};

        let result;
        try {
            await this.applyLocalStockDelta(items, -1);
        } catch(_) {
            this.toast('Local POS stock cache is unavailable. Sale was not submitted.');
            btn.disabled = false; this.updateTotals();
            return;
        }
        try {
            result = await this.api('/pos/sale','POST',payload);
        } catch(e) {
            const definitiveClientError = Number.isInteger(e.status)
                && e.status >= 400 && e.status < 500
                && ![408, 425, 429].includes(e.status);
            if (definitiveClientError) {
                // A 4xx validation/authorization response means the server
                // rejected this request; restore only the local optimistic cache.
                await this.applyLocalStockDelta(items, 1);
                this.toast('Sale rejected: ' + e.message);
            } else {
                // A timeout, network error, or 5xx may happen after the server
                // committed the sale. Preserve the same idempotency reference and
                // retry it; never present an ambiguous result as a rejected sale.
                await DB.put('queue', {...payload, ref:orderRef, total, offline:true, status:'pending', queued_at:Date.now(), local_stock_decremented:true});
                this.clearCart();
                this.toast(`⚡ Sale confirmation unavailable — safely queued (${orderRef})`);
            }
            btn.disabled = false; this.updateTotals();
            return;
        }

        // The sale is committed now. Failures in receipt/WhatsApp delivery must
        // never turn a successful sale into an offline retry or restore stock.
        await this.loadProducts();
        this.printReceipt({ref:result.order_ref, total, items, method:this.paymentMethod, cname, cphone, discount});
        this.clearCart();
        this.toast(`✓ Sale ${result.order_ref} — PKR ${total.toLocaleString()}`);
        if (cphone) {
            try {
                const receipt = await this.api('/pos/receipt/whatsapp','POST',{
                    phone:cphone,
                    order_ref:result.order_ref,
                    total,
                    items:items.map(i=>({...i,total_pkr:i.unit_price_pkr*i.quantity}))
                });
                if (receipt.whatsapp_url) window.open(receipt.whatsapp_url,'_blank');
            } catch(_) {
                this.toast('Sale saved. WhatsApp receipt could not be generated.');
            }
        }
        btn.disabled = false; this.updateTotals();
    },

    printReceipt({ref, total, items, method, cname, cphone, discount}) {
        const win = window.open('','_blank','width=320,height=600');
        if (!win) return;
        win.document.write(`<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Receipt</title>
        <style>body{font-family:'Courier New',monospace;font-size:12px;width:280px;margin:0 auto;padding:10px}
        .c{text-align:center}.b{font-weight:bold}.hr{border-top:1px dashed #000;margin:6px 0}
        .row{display:flex;justify-content:space-between}</style></head><body>
        <div class="c b">${this.esc(POS_CONFIG.storeName)}</div>
        <div class="c">${new Date().toLocaleString()}</div>
        <div class="c">Order: ${ref}</div>
        <div class="c">Customer: ${this.esc(cname)}${cphone?' | '+cphone:''}</div>
        <div class="hr"></div>
        ${items.map(i=>`<div class="row"><span>${this.esc(i.name)}</span></div><div class="row"><span>  ${i.quantity} x PKR ${i.unit_price_pkr.toLocaleString()}</span><span>PKR ${(i.unit_price_pkr*i.quantity).toLocaleString()}</span></div>`).join('')}
        <div class="hr"></div>
        ${discount?`<div class="row"><span>Discount</span><span>-PKR ${discount.toLocaleString()}</span></div>`:''}
        <div class="row b"><span>TOTAL</span><span>PKR ${total.toLocaleString()}</span></div>
        <div class="row"><span>Payment</span><span>${method.toUpperCase()}</span></div>
        <div class="hr"></div>
        <div class="c">Shukriya! Thank you for shopping.</div>
        <script>window.onload=()=>{window.print();setTimeout(()=>window.close(),1000);}<\/script>
        </body></html>`);
        win.document.close();
    },

    openEndDayModal() {
        if (!this.sessionId) { alert('No open session.'); return; }
        document.getElementById('pos-endday-modal').classList.add('active');
        document.getElementById('pos-session-summary').innerHTML = '<div>Loading summary...</div>';
        this.api('/pos/orders?session_id='+this.sessionId,'GET').then(r=>{
            const count = r.orders?.length||0;
            const total = r.orders?.reduce((s,o)=>s+o.total,0)||0;
            document.getElementById('pos-session-summary').innerHTML = `<div>Orders: <b>${count}</b></div><div>Total Sales: <b>PKR ${total.toLocaleString()}</b></div>`;
        }).catch(()=>{});
    },

    async closeSession() {
        const closingCash = parseInt(document.getElementById('pos-closing-cash').value)||0;
        try {
            const queuedBeforeClose = (await DB.getAll('queue')).filter(item => item.status !== 'needs_review');
            if (queuedBeforeClose.length) {
                await this.flushOfflineQueue();
                const stillPending = (await DB.getAll('queue')).filter(item => item.status !== 'needs_review');
                if (stillPending.length) {
                    this.toast('Cannot close this register yet: sync the pending offline sales first.');
                    return;
                }
            }
            const r = await this.api('/pos/session/close','POST',{session_id:this.sessionId,closing_cash:closingCash});
            const variance = r.cash_variance;
            document.getElementById('pos-endday-modal').classList.remove('active');
            alert(`Session closed.\nTotal Sales: PKR ${r.total_sales.toLocaleString()}\nCash Variance: PKR ${variance.toLocaleString()}${variance<0?' (SHORT)':variance>0?' (OVER)':' (EXACT)'}`);
            this.sessionId = null;
            document.getElementById('pos-session-label').textContent = 'Session closed';
            document.getElementById('pos-open-modal').classList.add('active');
        } catch(e) { alert('Error: '+e.message); }
    },

    async openScanner() {
        try {
            const stream = await navigator.mediaDevices.getUserMedia({video:{facingMode:'environment'}});
            this.scannerStream = stream;
            const video = document.getElementById('pos-scanner-video');
            video.srcObject = stream;
            document.getElementById('pos-scanner-overlay').classList.add('active');
            // Use ZXing via CDN if available, otherwise manual input fallback
            if (window.ZXing) {
                const hints = new Map([[window.ZXing.DecodeHintType.POSSIBLE_FORMATS,[window.ZXing.BarcodeFormat.CODE_128,window.ZXing.BarcodeFormat.EAN_13,window.ZXing.BarcodeFormat.QR_CODE]]]);
                const reader = new window.ZXing.BrowserMultiFormatReader(hints);
                reader.decodeFromVideoDevice(null,'pos-scanner-video',async(result,err)=>{if(result){this.closeScanner();await this.handleBarcodeInput(result.getText());}});
            }
        } catch(e) { alert('Camera not available. Use USB scanner or type barcode.'); }
    },

    closeScanner() {
        this.scannerStream?.getTracks().forEach(t=>t.stop());
        this.scannerStream = null;
        document.getElementById('pos-scanner-overlay').classList.remove('active');
    },

    setOnline(online) {
        this.isOnline = online;
        const badge = document.getElementById('pos-sync-badge');
        badge.textContent = online ? '● Online' : '⚡ Offline';
        badge.className   = 'pos-sync-badge ' + (online ? 'online' : 'offline');
        if (online) this.flushOfflineQueue();
    },

    async flushOfflineQueue(silent = false) {
        if (this.isFlushingQueue || !navigator.onLine || !this.sessionId) return;
        this.isFlushingQueue = true;
        try {
            const queue = await DB.getAll('queue');
            const pending = queue.filter(item => item.status !== 'needs_review');
            if (!pending.length) {
                if (queue.length && !silent) this.toast(`${queue.length} offline sale(s) need operator review.`);
                return;
            }
            if (!silent) this.toast(`Syncing ${pending.length} offline sale(s)...`);
            for (const item of pending) {
                try {
                    const {ref, offline, total, status, last_error, queued_at, local_stock_decremented, ...payload} = item;
                    await this.api('/pos/sale', 'POST', payload);
                    await DB.delete('queue', ref);
                } catch(e) {
                    const definitiveClientError = Number.isInteger(e.status)
                        && e.status >= 400 && e.status < 500
                        && ![408, 425, 429].includes(e.status);
                    if (definitiveClientError) {
                        item.status = 'needs_review';
                        item.last_error = String(e.message || 'Sale rejected by server').slice(0, 240);
                        await DB.put('queue', item);
                        if (item.local_stock_decremented) {
                            await this.applyLocalStockDelta(item.items || [], 1);
                            // The optimistic decrement has now been rolled back.
                            // Persist this fact so a later operator retry cannot
                            // add the same quantity back into the local cache twice.
                            item.local_stock_decremented = false;
                            await DB.put('queue', item);
                        }
                    }
                    // Network, timeout, throttling and 5xx failures remain pending
                    // and retry with the same idempotency reference.
                }
            }
            const remaining = await DB.getAll('queue');
            if (this.sessionId) await this.loadProducts();
            const review = remaining.filter(item => item.status === 'needs_review').length;
            const stillPending = remaining.length - review;
            if (!silent) {
                if (review) this.toast(`${review} offline sale(s) need operator review; ${stillPending} pending.`);
                else if (!stillPending) this.toast('✓ All offline sales synced!');
            }
        } finally {
            this.isFlushingQueue = false;
        }
    },

    async applyLocalStockDelta(items, direction) {
        for (const line of items) {
            const product = this.products.find(p => p.id === Number(line.product_id));
            if (!product || product.location_id !== this.locationId) continue;
            product.total_stock = Math.max(0, Number(product.total_stock || 0) + (Number(line.quantity || 0) * direction));
            await DB.put('products', product);
        }
        this.renderProducts(this.products);
    },

    toast(msg, duration=3000) {
        const t = document.getElementById('pos-toast');
        t.textContent = msg; t.classList.add('show');
        setTimeout(()=>t.classList.remove('show'), duration);
    },

    esc(str) { return String(str||'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); },

    async api(endpoint, method='GET', body=null) {
        const opts = {method, headers:{'Content-Type':'application/json','X-WP-Nonce':POS_CONFIG.nonce}};
        if (body) opts.body = JSON.stringify(body);
        const r = await fetch(POS_CONFIG.restUrl + endpoint, opts);
        const data = await r.json();
        if (!r.ok) { const error = new Error(data.message||`HTTP ${r.status}`); error.status = r.status; throw error; }
        return data;
    }
};

// ZXing barcode library (loaded async for camera scanning)
const zxingScript = document.createElement('script');
zxingScript.src = 'https://cdnjs.cloudflare.com/ajax/libs/zxing-js/0.20.0/zxing.min.js';
document.head.appendChild(zxingScript);

POS.init();
</script>
</body>
</html>
