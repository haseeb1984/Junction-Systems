'use strict';

const money = value => 'PKR ' + new Intl.NumberFormat('en-PK', { maximumFractionDigits: 0 }).format(Number(value) || 0);
function setText(element, text) { element.textContent=String(text); return element; }
const text = (tag, value, className = '') => {
    const element = document.createElement(tag);
    if (className) element.className = className;
    setText(element, value ?? '');
    return element;
};
const button = (label, className = 'pk-aff-btn', type = 'button') => {
    const element = text('button', label, className);
    element.type = type;
    return element;
};
const safeUrl = value => {
    try {
        const url = new URL(String(value || ''), window.location.href);
        return ['https:', 'http:'].includes(url.protocol) ? url.href : '';
    } catch (_) { return ''; }
};

let app;
let nonce = '';
let restUrl = '';

async function request(path, options = {}) {
    const method = options.method || 'GET';
    const headers = { Accept: 'application/json', 'X-WP-Nonce': nonce };
    const init = { method, headers, credentials: 'same-origin' };
    if (method !== 'GET') {
        headers['Content-Type'] = 'application/json';
        init.body = JSON.stringify(options.body || {});
    }
    const response = await fetch(restUrl + '/' + path.replace(/^\/+/, ''), init);
    const data = await response.json().catch(() => ({}));
    if (!response.ok || data.success === false) {
        const error = new Error(data.message || ('Request failed (' + response.status + ')'));
        error.status = response.status;
        error.code = data.code || '';
        throw error;
    }
    return data;
}

function notice(message, type = 'error') {
    const box = text('div', message, 'pk-aff-notice pk-aff-notice--' + type);
    box.setAttribute('role', 'status');
    app.prepend(box);
}

function field(labelText, name, type = 'text', required = true) {
    const wrap = document.createElement('label');
    wrap.appendChild(text('span', labelText));
    const input = document.createElement(type === 'textarea' ? 'textarea' : 'input');
    input.name = name;
    if (type !== 'textarea') input.type = type;
    input.required = required;
    if (name === 'payment_account') input.autocomplete = 'tel';
    wrap.appendChild(input);
    return wrap;
}

function selectField(labelText, name, options) {
    const wrap = document.createElement('label');
    wrap.appendChild(text('span', labelText));
    const select = document.createElement('select');
    select.name = name;
    select.required = true;
    options.forEach(([value, label]) => {
        const option = text('option', label);
        option.value = value;
        select.appendChild(option);
    });
    wrap.appendChild(select);
    return wrap;
}

function renderRegistration(message = '') {
    app.replaceChildren();
    app.appendChild(text('h2', '🤝 Join the Affiliate Program'));
    app.appendChild(text('p', 'Earn commission by sharing products with your audience.'));
    if (message) notice(message);
    const form = document.createElement('form');
    form.className = 'pk-aff-form pk-aff-register';
    form.append(
        field('Full name', 'name'),
        selectField('Payment method', 'payment_method', [['jazzcash', 'JazzCash'], ['easypaisa', 'Easypaisa']]),
        field('Mobile wallet number (03XXXXXXXXX)', 'payment_account', 'tel')
    );
    const submit = button('Create affiliate account', 'pk-aff-btn', 'submit');
    form.appendChild(submit);
    form.addEventListener('submit', async event => {
        event.preventDefault();
        submit.disabled = true;
        try {
            const body = Object.fromEntries(new FormData(form).entries());
            await request('affiliate/register', { method: 'POST', body });
            await loadDashboard();
        } catch (error) {
            notice(error.message || 'Registration could not be completed.');
        } finally { submit.disabled = false; }
    });
    app.appendChild(form);
}

function stat(label, value) {
    const card = text('div', '', 'pk-aff-stat');
    card.append(text('span', value, 'pk-aff-stat-number'), text('div', label, 'pk-aff-stat-label'));
    return card;
}

function table(headers, rows, emptyMessage) {
    const wrapper = document.createElement('div');
    wrapper.style.overflowX = 'auto';
    const element = text('table', '', 'pk-aff-table');
    const thead = document.createElement('thead');
    const headRow = document.createElement('tr');
    headers.forEach(header => headRow.appendChild(text('th', header)));
    thead.appendChild(headRow);
    element.appendChild(thead);
    const tbody = document.createElement('tbody');
    if (!rows.length) {
        const row = document.createElement('tr');
        const cell = text('td', emptyMessage);
        cell.colSpan = headers.length;
        row.appendChild(cell);
        tbody.appendChild(row);
    } else {
        rows.forEach(values => {
            const row = document.createElement('tr');
            values.forEach(value => row.appendChild(text('td', value)));
            tbody.appendChild(row);
        });
    }
    element.appendChild(tbody);
    wrapper.appendChild(element);
    return wrapper;
}

function renderProducts(products) {
    const section = document.createElement('section');
    section.appendChild(text('h3', 'Your affiliate products'));
    const form = document.createElement('form');
    form.className = 'pk-aff-form';
    form.append(
        field('Product title', 'title'),
        field('External product URL', 'external_url', 'url'),
        field('Description', 'description', 'textarea', false),
        field('Price (PKR)', 'price', 'number', false),
        field('Image URL', 'image_url', 'url', false),
        selectField('Platform', 'platform', [['other', 'Other'], ['daraz', 'Daraz'], ['woocommerce', 'Online store'], ['amazon', 'Amazon']]),
        field('Category', 'category', 'text', false)
    );
    const submit = button('Submit product', 'pk-aff-btn', 'submit');
    form.appendChild(submit);
    form.addEventListener('submit', async event => {
        event.preventDefault();
        submit.disabled = true;
        try {
            const body = Object.fromEntries(new FormData(form).entries());
            await request('affiliate/products', { method: 'POST', body });
            notice('Product submitted successfully.', 'success');
            await loadDashboard();
        } catch (error) {
            notice(error.message || 'Product could not be submitted.');
            submit.disabled = false;
        }
    });
    section.appendChild(form);
    const productTable = table(['Product', 'Platform', 'Price', 'Clicks', 'Status', 'Link'], products.map(product => [
        product.title, product.platform, money(product.price), String(product.clicks || 0), product.status,
        safeUrl(product.redirect_url) ? 'Open tracked link' : 'Unavailable'
    ]), 'You have not submitted any products yet.');
    section.appendChild(productTable);
    products.forEach((product, index) => {
        const url = safeUrl(product.redirect_url);
        const cell = productTable.querySelectorAll('tbody tr')[index]?.lastElementChild;
        if (!url || !cell) return;
        const link = document.createElement('a');
        link.href = url;
        link.target = '_blank';
        link.rel = 'noopener sponsored';
        link.textContent = 'Open tracked link';
        cell.textContent = '';
        cell.appendChild(link);
    });
    return section;
}

function renderDashboard(data) {
    const affiliate = data.affiliate || {};
    app.replaceChildren();
    app.appendChild(text('h2', 'Welcome, ' + (affiliate.name || 'Affiliate')));

    const stats = document.createElement('div');
    stats.className = 'pk-aff-stats';
    stats.append(
        stat('Total earned', money(affiliate.total_earned)),
        stat('Total paid', money(affiliate.total_paid)),
        stat('Pending payout', money(affiliate.pending_payout)),
        stat('Commission rate', (Number(affiliate.commission_rate) || 0) + '%')
    );
    app.appendChild(stats);

    const codeBox = text('section', '', 'pk-aff-code-box');
    codeBox.appendChild(text('div', 'Your referral code'));
    codeBox.appendChild(text('div', affiliate.code || '—', 'pk-aff-code'));
    const trackingUrl = safeUrl(affiliate.tracking_url);
    if (trackingUrl) {
        codeBox.appendChild(text('div', trackingUrl, 'pk-aff-url'));
        const copy = button('Copy referral link');
        copy.addEventListener('click', async () => {
            try {
                await navigator.clipboard.writeText(trackingUrl);
                notice('Referral link copied.', 'success');
            } catch (_) {
                notice('Copy is unavailable in this browser. Select the referral link to copy it.');
            }
        });
        codeBox.appendChild(copy);
    }
    codeBox.appendChild(text('p', 'Payment: ' + (affiliate.payment_method || '—') + ' · ' + (affiliate.payment_account || '—')));
    app.appendChild(codeBox);

    const tabs = document.createElement('div');
    tabs.className = 'pk-aff-tabs';
    const panels = document.createElement('div');
    const commissions = (data.commissions || []).map(item => [
        '#' + item.order_id, money(item.order_total), money(item.commission), item.rate + '%', item.status, item.date
    ]);
    const definitions = [
        ['overview', 'Overview', () => {
            const section = document.createElement('section');
            section.appendChild(text('h3', 'Recent activity'));
            section.appendChild(table(['Order', 'Order total', 'Commission', 'Rate', 'Status', 'Date'], commissions, 'No commissions recorded yet.'));
            return section;
        }],
        ['commissions', 'Commissions', () => table(['Order', 'Order total', 'Commission', 'Rate', 'Status', 'Date'], commissions, 'No commissions recorded yet.')],
        ['payouts', 'Payouts', () => table(['Amount', 'Method', 'Status', 'Transaction', 'Date'], (data.payouts || []).map(item => [
            money(item.amount), item.method, item.status, item.tx_id || '—', item.date
        ]), 'No payouts recorded yet.')],
        ['products', 'Products', () => renderProducts(data.products || [])]
    ];
    definitions.forEach((definition, index) => {
        const tab = button(definition[1], 'pk-aff-tab' + (index === 0 ? ' active' : ''));
        tab.addEventListener('click', () => {
            tabs.querySelectorAll('.pk-aff-tab').forEach(item => item.classList.remove('active'));
            tab.classList.add('active');
            panels.replaceChildren(definition[2]());
        });
        tabs.appendChild(tab);
    });
    app.append(tabs, panels);
    panels.appendChild(definitions[0][2]());
}

async function loadDashboard() {
    try {
        const data = await request('affiliate/dashboard');
        if (!data.affiliate) throw new Error('Affiliate dashboard data is unavailable.');
        renderDashboard(data);
    } catch (error) {
        if (error.status === 403 || error.code === 'already_registered') {
            renderRegistration();
            return;
        }
        app.replaceChildren();
        notice(error.message || 'Unable to load your affiliate dashboard.');
    }
}

export function init() {
    app = document.querySelector('#pk-affiliate-app');
    if (!app) return;
    restUrl = (app.dataset.restUrl || '').replace(/\/+$/, '');
    nonce = app.dataset.nonce || '';
    if (!restUrl) {
        app.replaceChildren();
        notice('Affiliate API configuration is missing.');
        return;
    }
    loadDashboard();
}
