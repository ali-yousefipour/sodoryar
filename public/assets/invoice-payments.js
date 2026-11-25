// Multi-payment invoice editor; no external dependencies.
document.addEventListener('DOMContentLoaded',()=>{
  const form=document.getElementById('invoiceCreateForm');
  const host=document.getElementById('invoicePaymentRows');
  if(!form||!host)return;
  const add=document.getElementById('invoiceAddPayment');
  const error=document.getElementById('invoicePaymentError');
  const allowPartial=form.querySelector('#invoiceAllowPartial');
  const noInitial=form.querySelector('#invoiceNoInitialPayment');
  const format=new Intl.NumberFormat('fa-IR');
  const ascii=s=>String(s||'').replace(/[۰-۹]/g,c=>String(c.charCodeAt(0)-1776)).replace(/[٠-٩]/g,c=>String(c.charCodeAt(0)-1632));
  const normalized=s=>ascii(s).trim().replace(/[٬،]/g,',');
  const validGroup=s=>/^(?:\d+|\d{1,3}(?:,\d{3})+)$/.test(normalized(s));
  const digits=s=>normalized(s).replace(/,/g,'');
  const amount=s=>validGroup(s)?BigInt(digits(s)):0n;
  let serial=0,mode='cash',rows=[];
  const total=()=>{
    let sum=0n;
    form.querySelectorAll('[data-service-option] input:checked').forEach(input=>{sum+=amount(input.dataset.servicePrice);});
    form.querySelectorAll('input[name="extra_price[]"]').forEach(input=>{
      if(input.closest('.extra-invoice-row')?.querySelector('input[name="extra_title[]"]')?.value.trim())sum+=amount(input.value);
    });
    return sum;
  };
  const el=(tag,attrs={},value='')=>{
    const node=document.createElement(tag);
    Object.entries(attrs).forEach(([key,val])=>node.setAttribute(key,val));
    if(value)node.textContent=value;
    return node;
  };
  const branch=form.querySelector('#invoiceBranch');
  const filterTerminals=()=>{
    host.querySelectorAll('select[name$="[terminal]"]').forEach((select,index)=>{
      for(const option of select.options){
        const unavailable=!!option.value && !!branch && !option.dataset.branches?.split(',').includes(branch.value);
        option.disabled=unavailable;option.hidden=unavailable;
      }
      if(select.selectedOptions[0]?.disabled){select.value='';rows[index].terminal='';}
      select.required=!!branch && rows[index]?.method==='pos';
    });
  };
  branch?.addEventListener('change',()=>{
    filterTerminals();
    error.textContent='شعبه تغییر کرد؛ کارتخوان معتبر این شعبه را انتخاب کنید. صندوق نقدی هنگام ثبت برای همین شعبه بررسی می‌شود.';
    error.hidden=false;
  });
  const totalPaid=()=>rows.reduce((sum,r)=>sum+amount(r.amount),0n);
  const refresh=()=>{
    const due=total();
    if(rows.length===1 && !rows[0].manual){rows[0].amount=String(due);const first=host.querySelector('input[name$="[amount]"]');if(first&&document.activeElement!==first)first.value=due>0n?format.format(due):'';}
    if(noInitial?.checked && allowPartial)allowPartial.checked=true;
    host.querySelectorAll('input,select').forEach(field=>{field.disabled=!!noInitial?.checked;});
    const paid=noInitial?.checked?0n:totalPaid(), remaining=due-paid;
    host.querySelectorAll('.invoice-payment-row').forEach((card,index)=>{
      const row=rows[index];if(!row)return;
      const method=card.querySelector('[name$="[method]"]');if(method.value!==row.method)method.value=row.method;
      card.querySelector('.payment-pos-fields').hidden=row.method!=='pos';
      card.querySelector('input[name$="[tracking]"]').required=row.method==='pos';
      card.querySelector('[data-remove-payment]').hidden=mode!=='multi';
    });
    const set=(selector,n)=>{form.querySelector(selector).textContent=format.format(n);};
    set('[data-payable-total]',due);set('[data-payment-paid]',paid);
    set('[data-payment-remaining]',remaining>0n?remaining:0n);
    set('[data-payment-count]',BigInt(rows.length));
    filterTerminals();
    error.hidden=true;
  };
  const createRow=(method,proposed)=>{
    const row={id:++serial,method,amount:proposed>0n?String(proposed):'',manual:false,terminal:'',tracking:'',reference:''};
    rows.push(row);
    const index=rows.length-1;
    const card=el('div',{class:'invoice-payment-row','data-payment-id':String(row.id)});
    const methodLabel=el('label',{},'روش پرداخت');
    const select=el(mode==='multi'?'select':'input',{name:`payments[${index}][method]`,'aria-label':'روش پرداخت'});
    if(mode==='multi') [['cash','نقدی'],['pos','کارتخوان']].forEach(([v,label])=>select.append(el('option',{value:v},label)));
    else {select.type='hidden';methodLabel.append(el('input',{type:'text',readonly:'readonly',value:method==='pos'?'کارتخوان':'نقدی','aria-label':'روش پرداخت'}));}
    select.value=method;
    select.addEventListener('change',()=>{row.method=select.value;refresh();});
    methodLabel.append(select);
    const amountLabel=el('label',{},'مبلغ پرداخت (تومان)');
    const field=el('input',{type:'text',inputmode:'numeric',name:`payments[${index}][amount]`,required:'required',autocomplete:'off','aria-label':'مبلغ پرداخت'});
    field.value=row.amount?format.format(BigInt(row.amount)):'';
    field.addEventListener('input',()=>{row.manual=true;row.amount=validGroup(field.value)?digits(field.value):'INVALID';refresh();});
    field.addEventListener('blur',()=>{if(validGroup(field.value))field.value=format.format(BigInt(digits(field.value)));});
    amountLabel.append(field);
    const pos=el('div',{class:'payment-pos-fields'});
    const source=document.getElementById('invoiceTerminalOptions');
    if(source && source.options.length>1){
      const terminalLabel=el('label',{},'دستگاه کارتخوان');
      const terminal=source.cloneNode(true);
      terminal.id='';terminal.hidden=false;terminal.removeAttribute('aria-hidden');
      terminal.name=`payments[${index}][terminal]`;
      terminal.addEventListener('change',()=>{row.terminal=terminal.value;});
      terminalLabel.append(terminal);pos.append(terminalLabel);
    }
    const trackingLabel=el('label',{},'شماره پیگیری (الزامی)');
    const tracking=el('input',{type:'text',name:`payments[${index}][tracking]`,maxlength:'120'});
    tracking.addEventListener('input',()=>{row.tracking=tracking.value;});trackingLabel.append(tracking);
    const referenceLabel=el('label',{},'شماره مرجع (اختیاری)');
    const reference=el('input',{type:'text',name:`payments[${index}][reference]`,maxlength:'120'});
    reference.addEventListener('input',()=>{row.reference=reference.value;});referenceLabel.append(reference);
    pos.append(trackingLabel,referenceLabel);
    const remove=el('button',{type:'button',class:'secondary small','data-remove-payment':''},'حذف پرداخت');
    remove.addEventListener('click',()=>{
      rows=rows.filter(item=>item.id!==row.id);card.remove();
      host.querySelectorAll('.invoice-payment-row').forEach((node,i)=>{
        node.querySelectorAll('[name]').forEach(field=>field.name=field.name.replace(/payments\[\d+\]/,`payments[${i}]`));
      });
      refresh();
    });
    card.append(methodLabel,amountLabel,pos,remove);host.append(card);refresh();
  };
  const reset=(next)=>{
    mode=next;rows=[];host.replaceChildren();
    createRow(next==='pos'?'pos':'cash',total());
    form.querySelectorAll('[data-payment-mode]').forEach(btn=>btn.setAttribute('aria-pressed',String(btn.dataset.paymentMode===next)));
    add.hidden=next!=='multi';
  };
  form.querySelectorAll('[data-payment-mode]').forEach(btn=>btn.addEventListener('click',()=>reset(btn.dataset.paymentMode)));
  add.addEventListener('click',()=>{if(rows.length>=50)return;const rest=total()-totalPaid();createRow('pos',rest>0n?rest:0n);});
  form.addEventListener('input',event=>{
    if(event.target.closest('[data-service-option],.extra-invoice-row'))refresh();
  });
  form.addEventListener('change',event=>{
    if(event.target.closest('[data-service-option],.extra-invoice-row'))refresh();
  });
  allowPartial?.addEventListener('change',()=>{if(!allowPartial.checked && noInitial?.checked)noInitial.checked=false;refresh();});
  noInitial?.addEventListener('change',refresh);
  // Capture submit before the existing once-submit handler.
  form.addEventListener('submit',event=>{
    if(event.defaultPrevented)return;
    const due=total(),noInitialPayment=!!noInitial?.checked;
    const paid=noInitialPayment?0n:totalPaid();
    const invalid=!noInitialPayment && (rows.length===0||rows.some(r=>amount(r.amount)<=0n));
    if(!noInitialPayment && branch && rows.some(r=>r.method==='pos'&&!r.terminal)){
      event.preventDefault();error.textContent='کارتخوان معتبر شعبه را برای هر پرداخت انتخاب کنید.';error.hidden=false;return;
    }
    if(due<=0n||invalid||paid>due||(!allowPartial?.checked && due!==paid)){
      event.preventDefault();
      error.textContent=paid>due?'مبلغ دریافتی نمی‌تواند بیشتر از مبلغ فاکتور باشد.':(!allowPartial?.checked && due!==paid?'جمع پرداخت‌ها باید با مبلغ فاکتور برابر باشد.':'مبلغ هر تراکنش باید بیشتر از صفر باشد.');
      error.hidden=false;error.scrollIntoView({block:'nearest',behavior:'smooth'});
      return;
    }
    // Preserve raw ASCII integer strings for server-side validation.
    host.querySelectorAll('input[name$="[amount]"]').forEach((field,index)=>{field.value=rows[index].amount;});
  },true);
  const observer=new MutationObserver(()=>refresh());
  observer.observe(document.getElementById('extraInvoiceItems'),{childList:true});
  reset('cash');
});

// Audit-preserving correction of an existing invoice's full payment allocation.
document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('[data-collect-method]').forEach(method=>{
    const tracking=method.closest('form').querySelector('[data-collect-tracking]');
    const update=()=>{tracking.required=method.value==='pos';};
    method.addEventListener('change',update);update();
  });
  const ascii=s=>String(s??'').replace(/[۰-۹]/g,c=>String(c.charCodeAt(0)-1776)).replace(/[٠-٩]/g,c=>String(c.charCodeAt(0)-1632));
  const normalized=s=>ascii(s).trim().replace(/[٬،]/g,',');
  const raw=s=>normalized(s).replace(/,/g,'');
  const valid=s=>/^(?:\d+|\d{1,3}(?:,\d{3})+)$/.test(normalized(s)) && BigInt(raw(s))>0n;
  const display=n=>new Intl.NumberFormat('fa-IR').format(n);
  document.querySelectorAll('form.invoice-payment-replace').forEach(form=>{
    const list=form.querySelector('[data-edit-payment-list]');
    const prototype=list.querySelector('[data-edit-payment-row]')?.cloneNode(true);
    const note=form.querySelector('[data-edit-summary]');
    const error=form.querySelector('[data-edit-error]');
    const expected=BigInt(form.dataset.paymentTotal||'0');
    const renumber=()=>{
      list.querySelectorAll('[data-edit-payment-row]').forEach((row,i)=>{
        row.querySelectorAll('[name]').forEach(input=>{input.name=input.name.replace(/payments\[\d+\]/,`payments[${i}]`);});
      });
    };
    const recalc=()=>{
      let sum=0n,invalid=false;
      list.querySelectorAll('[data-edit-payment-row]').forEach(row=>{
        const input=row.querySelector('input[name$="[amount]"]');
        const number=raw(input.value);
        if(valid(input.value))sum+=BigInt(number);
        else invalid=true;
        const isPos=row.querySelector('select[name$="[method]"]').value==='pos';
        row.querySelector('.payment-pos-fields').hidden=!isPos;
        row.querySelector('input[name$="[tracking]"]').required=isPos;
      });
      note.textContent=`جمع پرداخت‌ها: ${display(sum)} تومان | مانده: ${display(expected-sum)} تومان`;
      return !invalid && sum===expected && list.children.length>0;
    };
    form.querySelector('[data-edit-add]')?.addEventListener('click',()=>{
      if(!prototype||list.children.length>=50)return;
      const clone=prototype.cloneNode(true);
      clone.querySelectorAll('input').forEach(input=>input.value='');
      clone.querySelectorAll('select').forEach(select=>select.selectedIndex=0);
      const difference=expected-[...list.querySelectorAll('input[name$="[amount]"]')].reduce((sum,input)=>sum+(valid(input.value)?BigInt(raw(input.value)):0n),0n);
      clone.querySelector('input[name$="[amount]"]').value=difference>0n?display(difference):'';
      list.append(clone);renumber();recalc();
    });
    list.addEventListener('click',event=>{
      if(!event.target.closest('[data-edit-remove]'))return;
      event.target.closest('[data-edit-payment-row]').remove();renumber();recalc();
    });
    list.addEventListener('input',()=>{error.hidden=true;recalc();});
    list.addEventListener('change',recalc);
    list.addEventListener('focusout',event=>{
      if(!event.target.matches('input[name$="[amount]"]'))return;
      if(valid(event.target.value))event.target.value=display(BigInt(raw(event.target.value)));
    });
    form.addEventListener('submit',event=>{
      if(!recalc()){
        event.preventDefault();error.textContent='جمع پرداخت‌ها باید با مبلغ دریافت‌شده قبلی برابر باشد.';error.hidden=false;
        return;
      }
      list.querySelectorAll('input[name$="[amount]"]').forEach(input=>input.value=raw(input.value));
      const submit=form.querySelector('button[type="submit"]');if(submit)submit.disabled=true;
    },true);
    recalc();
  });
});
