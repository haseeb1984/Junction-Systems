const el=(tag,className='',text='')=>{
  const node=document.createElement(tag);
  if(className)node.className=className;
  if(text!==''&&text!==null&&text!==undefined)node.textContent=String(text);
  return node;
};
const money=value=>'PKR '+Math.round(Number(value)||0).toLocaleString('en-PK');
const safeUrl=value=>{
  try{const url=new URL(String(value),window.location.origin);return ['http:','https:'].includes(url.protocol)?url.href:'';}catch{return '';}
};
const button=(text,className='pk-aff-btn',type='button')=>{
  const node=el('button',className,text);node.type=type;return node;
};
const notice=(message,type='error')=>el('div','pk-aff-notice pk-aff-notice--'+type,message);
const table=(headers,rows)=>{
  const node=el('table','pk-aff-table');
  const thead=el('thead'),headRow=el('tr');
  headers.forEach(label=>headRow.append(el('th','',label)));
  thead.append(headRow);node.append(thead);
  const tbody=el('tbody');
  if(!rows.length){const tr=el('tr'),td=el('td');td.colSpan=headers.length;td.textContent='No records yet.';tr.append(td);tbody.append(tr);}
  rows.forEach(values=>{const tr=el('tr');values.forEach(value=>{const td=el('td');if(value instanceof Node)td.append(value);else td.textContent=String(value??'—');tr.append(td);});tbody.append(tr);});
  node.append(tbody);return node;
};

export function init(){
  const root=document.querySelector('#pk-affiliate-app');
  if(!root)return;
  const base=String(root.dataset.restUrl||'').replace(/\/+$/,'');
  const nonce=String(root.dataset.nonce||'');
  const request=async(path,options={})=>{
    const response=await fetch(base+'/'+path.replace(/^\/+/,''),{
      method:options.method||'GET',
      credentials:'same-origin',
      headers:{'X-WP-Nonce':nonce,'Accept':'application/json',...(options.body?{'Content-Type':'application/json'}:{}),...(options.headers||{})},
      ...(options.body?{body:JSON.stringify(options.body)}:{})
    });
    const data=await response.json().catch(()=>({}));
    if(!response.ok||data.success===false){
      const error=new Error(data.message||('Request failed ('+response.status+')'));
      error.status=response.status;error.data=data;throw error;
    }
    return data;
  };
  const showError=(container,error)=>container.append(notice(error.message||'Something went wrong.'));

  const renderRegistration=()=>{
    root.replaceChildren();
    const card=el('section','pk-aff-register');
    card.append(el('h2','','Join the Affiliate Program'));
    card.append(el('p','','Share Junction store products and earn commission on eligible orders.'));
    const form=el('form','pk-aff-form');
    const nameLabel=el('label','','Full name');
    const name=el('input');name.name='name';name.required=true;name.maxLength=120;name.autocomplete='name';
    nameLabel.append(name);
    const methodLabel=el('label','','Preferred payout method');
    const method=el('select');method.name='payment_method';
    [['jazzcash','JazzCash'],['easypaisa','Easypaisa']].forEach(([value,label])=>{const option=el('option','',label);option.value=value;method.append(option);});
    methodLabel.append(method);
    const accountLabel=el('label','','Mobile account number');
    const account=el('input');account.name='payment_account';account.required=true;account.maxLength=32;account.autocomplete='tel';account.placeholder='03XXXXXXXXX';
    accountLabel.append(account);
    const submit=button('Create affiliate account','pk-aff-btn','submit');
    form.append(nameLabel,methodLabel,accountLabel,submit);
    form.addEventListener('submit',async event=>{
      event.preventDefault();submit.disabled=true;submit.textContent='Submitting…';
      card.querySelector('.pk-aff-notice')?.remove();
      try{
        await request('affiliate/register',{method:'POST',body:{name:name.value.trim(),payment_method:method.value,payment_account:account.value.trim()}});
        await loadDashboard();
      }catch(error){showError(card,error);submit.disabled=false;submit.textContent='Create affiliate account';}
    });
    card.append(form);root.append(card);
  };

  const loadDashboard=async()=>{
    root.replaceChildren(el('div','','Loading your affiliate dashboard…'));
    try{
      const data=await request('affiliate/dashboard');
      renderDashboard(data);
    }catch(error){
      if(error.status===403){renderRegistration();return;}
      root.replaceChildren(notice(error.message||'Unable to load your dashboard.'));
    }
  };

  const renderDashboard=data=>{
    const affiliate=data.affiliate||{};
    root.replaceChildren();
    const codeBox=el('section','pk-aff-code-box');
    codeBox.append(el('div','','Your affiliate code'));
    codeBox.append(el('div','pk-aff-code',affiliate.code||'—'));
    codeBox.append(el('div','','Share this tracking link with your audience:'));
    codeBox.append(el('div','pk-aff-url',affiliate.tracking_url||''));
    const copy=button('Copy tracking link');
    copy.addEventListener('click',async()=>{
      try{await navigator.clipboard.writeText(String(affiliate.tracking_url||''));copy.textContent='Copied';}
      catch{copy.textContent='Copy unavailable';}
    });
    codeBox.append(copy);
    codeBox.append(el('p','', 'Account status: '+(affiliate.status||'unknown')+' · Commission rate: '+(Number(affiliate.commission_rate)||0)+'%'));
    root.append(codeBox);

    const stats=el('section','pk-aff-stats');
    [['Total earned',money(affiliate.total_earned)],['Total paid',money(affiliate.total_paid)],['Pending payout',money(affiliate.pending_payout)]]
      .forEach(([label,value])=>{const card=el('div','pk-aff-stat');card.append(el('span','pk-aff-stat-number',value),el('div','pk-aff-stat-label',label));stats.append(card);});
    root.append(stats);

    const tabs=el('nav','pk-aff-tabs');
    const panels=el('div');
    const definitions=[['overview','Overview'],['commissions','Commissions'],['payouts','Payouts'],['products','My products'],['submit','Submit a product']];
    const panelMap={};
    const activate=name=>{
      tabs.querySelectorAll('button').forEach(item=>item.classList.toggle('active',item.dataset.tab===name));
      Object.entries(panelMap).forEach(([key,panel])=>panel.classList.toggle('active',key===name));
    };
    definitions.forEach(([key,label])=>{
      const tab=el('button','pk-aff-tab',label);tab.type='button';tab.dataset.tab=key;tab.addEventListener('click',()=>activate(key));tabs.append(tab);
      const panel=el('section','pk-aff-panel');panelMap[key]=panel;panels.append(panel);
    });
    panelMap.overview.append(el('h3','','Your affiliate activity'));
    panelMap.overview.append(el('p','','Share your tracking link. Eligible orders are recorded here after the store confirms them.'));
    panelMap.overview.append(el('p','','Payouts are reviewed and processed by the store team.'));
    panelMap.commissions.append(el('h3','','Recent commissions'));
    panelMap.commissions.append(table(['Date','Order','Order total','Commission','Rate','Status'],(data.commissions||[]).map(item=>[
      item.date,'#'+item.order_id,money(item.order_total),money(item.commission),(Number(item.rate)||0)+'%',item.status
    ])));
    panelMap.payouts.append(el('h3','','Payout history'));
    panelMap.payouts.append(table(['Date','Amount','Method','Status','Transaction'],(data.payouts||[]).map(item=>[
      item.date,money(item.amount),item.method,item.status,item.tx_id||'—'
    ])));
    panelMap.payouts.append(el('p','','Payout account: '+(affiliate.payment_method||'—')+' · '+(affiliate.payment_account||'—')));
    panelMap.products.append(el('h3','','Your submitted products'));
    panelMap.products.append(table(['Product','Platform','Price','Clicks','Status','Tracking link'],(data.products||[]).map(item=>{
      const link=el('a','','Open link');const href=safeUrl(item.redirect_url);
      if(href){link.href=href;link.target='_blank';link.rel='noopener noreferrer';}else link.textContent='Unavailable';
      return [item.title,item.platform,money(item.price),item.clicks,item.status,link];
    })));

    const form=el('form','pk-aff-form');
    form.append(el('h3','','Submit a product'));
    const addField=(label,name,type='text',required=false)=>{
      const wrap=el('label','',label);const input=el(type==='textarea'?'textarea':'input');
      if(type!=='textarea')input.type=type;input.name=name;input.required=required;
      if(name==='price'){input.min='0';input.step='1';}
      wrap.append(input);form.append(wrap);return input;
    };
    const title=addField('Product title','title','text',true);
    const externalUrl=addField('Product URL','external_url','url',true);
    const price=addField('Display price (PKR)','price','number',false);
    const description=addField('Description','description','textarea',false);
    const platformLabel=el('label','','Platform');const platform=el('select');platform.name='platform';
    [['daraz','Daraz'],['shopify','Shopify'],['other','Other']].forEach(([value,label])=>{const option=el('option','',label);option.value=value;platform.append(option);});
    platformLabel.append(platform);form.append(platformLabel);
    addField('Category','category','text',false);
    addField('Image URL (optional)','image_url','url',false);
    const submit=button('Submit product','pk-aff-btn','submit');form.append(submit);
    form.addEventListener('submit',async event=>{
      event.preventDefault();submit.disabled=true;submit.textContent='Submitting…';
      form.querySelector('.pk-aff-notice')?.remove();
      const payload={title:title.value.trim(),external_url:externalUrl.value.trim(),price:price.value===''?0:Number(price.value),description:description.value,platform:platform.value,category:form.elements.category.value.trim(),image_url:form.elements.image_url.value.trim()};
      if(!Number.isInteger(payload.price)||payload.price<0){showError(form,new Error('Enter a whole-number PKR price.'));submit.disabled=false;submit.textContent='Submit product';return;}
      try{await request('affiliate/products',{method:'POST',body:payload});await loadDashboard();}
      catch(error){showError(form,error);submit.disabled=false;submit.textContent='Submit product';}
    });
    panelMap.submit.append(form);
    root.append(tabs,panels);
    activate('overview');
  };

  loadDashboard();
}
