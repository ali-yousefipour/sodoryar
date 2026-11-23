// Multi-payment invoice editor; no external dependencies.
document.addEventListener('DOMContentLoaded',()=>{
  const form=document.getElementById('invoiceCreateForm');
  const host=document.getElementById('invoicePaymentRows');
  if(!form||!host)return;
  const add=document.getElementById('invoiceAddPayment');
  const error=document.getElementById('invoicePaymentError');
  const format=new Intl.NumberFormat('fa-IR');
  const ascii=s=>String(s||'').replace(/[۰-۹]/g,c=>String(c.charCodeAt(0)-1776)).replace(/[٠-٩]/g,c=>String(c.charCodeAt(0)-1632));
  const digits=s=>ascii(s).replace(/[٬,،\s]/g,'');
  const amount=s=>/^\d+$/.test(digits(s))?BigInt(digits(s)):0n;
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
  const totalPaid=()=>rows.reduce((sum,r)=>sum+amount(r.amount),0n);
  const refresh=()=>{
    const due=total(), paid=totalPaid(), remaining=due-paid;
    host.querySelectorAll('.invoice-payment-row').forEach((card,index)=>{
      const row=rows[index];if(!row)return;
      const method=card.querySelector('select');if(method.value!==row.method)method.value=row.method;
      card.querySelector('.payment-pos-fields').hidden=row.method!=='pos';
      card.querySelector('[data-remove-payment]').hidden=mode!=='multi';
    });
    const set=(selector,n)=>{form.querySelector(selector).textContent=format.format(n);};
    set('[data-payable-total]',due);set('[data-payment-paid]',paid);
    set('[data-payment-remaining]',remaining>0n?remaining:0n);
    set('[data-payment-count]',BigInt(rows.length));
    error.hidden=true;
  };
  const createRow=(method,proposed)=>{
    const row={id:++serial,method,amount:proposed>0n?String(proposed):'',tracking:'',reference:''};
    rows.push(row);
    const index=rows.length-1;
    const card=el('div',{class:'invoice-payment-row','data-payment-id':String(row.id)});
    const methodLabel=el('label',{},'روش پرداخت');
    const select=el('select',{name:`payments[${index}][method]`,'aria-label':'روش پرداخت'});
    [['cash','نقدی'],['pos','کارتخوان']].forEach(([v,label])=>select.append(el('option',{value:v},label)));
    select.value=method;
    select.addEventListener('change',()=>{row.method=select.value;refresh();});
    methodLabel.append(select);
    const amountLabel=el('label',{},'مبلغ پرداخت (تومان)');
    const field=el('input',{type:'text',inputmode:'numeric',name:`payments[${index}][amount]`,required:'required',autocomplete:'off','aria-label':'مبلغ پرداخت'});
    field.value=row.amount?format.format(BigInt(row.amount)):'';
    field.addEventListener('input',()=>{row.amount=digits(field.value);if(!/^\d*$/.test(row.amount)){row.amount='';}refresh();});
    field.addEventListener('blur',()=>{field.value=row.amount?format.format(BigInt(row.amount)):'';});
    amountLabel.append(field);
    const pos=el('div',{class:'payment-pos-fields'});
    const trackingLabel=el('label',{},'شماره پیگیری (اختیاری)');
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
  // Capture submit before the existing once-submit handler.
  form.addEventListener('submit',event=>{
    if(event.defaultPrevented)return;
    const due=total(),paid=totalPaid();
    if(due<=0n||rows.length===0||rows.some(r=>amount(r.amount)<=0n)||due!==paid){
      event.preventDefault();
      error.textContent=due!==paid?'جمع پرداخت‌ها باید دقیقاً با مبلغ فاکتور برابر باشد.':'مبلغ هر تراکنش باید بیشتر از صفر باشد.';
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
