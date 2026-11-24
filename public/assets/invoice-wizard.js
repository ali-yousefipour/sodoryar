document.addEventListener('DOMContentLoaded',()=>{
 const form=document.getElementById('invoiceCreateForm'), dialog=document.getElementById('invoiceWizard');
 if(!form||!dialog)return;
 const stages=[...form.querySelectorAll('[data-wizard-stage]')], footer=form.querySelector('.invoice-form-footer');
 const prev=footer.querySelector('[data-wizard-prev]'),next=footer.querySelector('[data-wizard-next]'),cancel=footer.querySelector('[data-wizard-cancel]'), saves=[...footer.querySelectorAll('[type=submit]')];
 const status=form.querySelector('[data-wizard-status]'), error=form.querySelector('[data-wizard-error]');
 const stepItems=[...form.querySelectorAll('.invoice-wizard-steps li')];
 const body=form.querySelector('.invoice-wizard-body');
 const total=form.querySelector('[data-wizard-total]');
 const payable=form.querySelector('[data-payable-total]');
 const updateTotal=()=>{total.textContent=payable.textContent;};
 new MutationObserver(updateTotal).observe(payable,{childList:true,characterData:true,subtree:true});
 updateTotal();
 let step=0,dirty=false,busy=false;
 const ascii=s=>String(s??'').replace(/[۰-۹]/g,c=>c.charCodeAt(0)-1776).replace(/[٠-٩]/g,c=>c.charCodeAt(0)-1632);
 const money=s=>{const v=ascii(s).replace(/[٬،]/g,',').trim();return /^(?:\d+|\d{1,3}(?:,\d{3})+)$/.test(v)?BigInt(v.replace(/,/g,'')):0n;};
 const show=(n,focus=true)=>{
  step=n;stages.forEach(e=>e.hidden=Number(e.dataset.wizardStage)!==n+1);
  prev.hidden=n===0;next.hidden=n===3;saves.forEach(b=>b.hidden=n!==3);
  status.textContent=['۱ از ۴ — انتخاب تاکسیران','۲ از ۴ — خدمات و اقلام','۳ از ۴ — پرداخت','۴ از ۴ — بررسی نهایی'][n];
  stepItems.forEach((item,i)=>{item.classList.toggle('is-complete',i<n);if(i===n)item.setAttribute('aria-current','step');else item.removeAttribute('aria-current');});
  body.scrollTop=0;
  error.textContent='';
  if(n===3){
   const list=form.querySelector('[data-wizard-review]');list.replaceChildren();
   const add=(k,v)=>{const p=document.createElement('p'),label=document.createElement('b'),value=document.createElement('span');label.textContent=k;value.textContent=v;p.append(label,value);if(k==='مبلغ فاکتور'||k==='مانده')p.classList.add('is-total');list.append(p);};
   add('کد ملی',form.elements.driver_national_code.value.replace(/[0-9]/g,d=>'۰۱۲۳۴۵۶۷۸۹'[Number(d)]));
   add('تاریخ فاکتور',form.querySelector('[data-jalali-open]').textContent.trim());
   add('تاکسیران',form.querySelector('[data-driver-result]').textContent);
   form.querySelectorAll('[data-service-option] input:checked').forEach(e=>add('خدمت',e.closest('label').textContent.trim()));
   form.querySelectorAll('.extra-invoice-row').forEach(e=>add('خدمت موردی',e.querySelector('[name="extra_title[]"]').value+' — '+e.querySelector('[name="extra_price[]"]').value));
   if(!form.querySelector('#invoiceNoInitialPayment').checked){
    form.querySelectorAll('#invoicePaymentRows .invoice-payment-row').forEach((row,i)=>{const method=row.querySelector('[name$="[method]"]')?.value;const amount=row.querySelector('[name$="[amount]"]')?.value||'۰';add('پرداخت '+new Intl.NumberFormat('fa-IR').format(i+1),(method==='pos'?'کارتخوان':'نقدی')+' — '+new Intl.NumberFormat('fa-IR').format(money(amount))+' تومان');});
   }else add('روش دریافت','بدون دریافت اولیه');
   ['payable-total','payment-paid','payment-remaining','payment-count'].forEach((k,i)=>add(['مبلغ فاکتور','دریافتی','مانده','تعداد تراکنش'][i],form.querySelector('[data-'+k+']').textContent+(i<3?' تومان':'')));
  }
  if(focus)status.focus();
 };
 const fail=(message,field)=>{error.textContent=message;if(field){field.focus();field.scrollIntoView({block:'nearest'});}return false;};
 const validate=n=>{
  const fields=stages.filter(e=>Number(e.dataset.wizardStage)===n+1).flatMap(e=>[...e.querySelectorAll('input,select,textarea')]);
  const invalid=fields.find(e=>!e.disabled&&e.willValidate&&!e.checkValidity());
  if(invalid){invalid.reportValidity();return fail('اطلاعات این مرحله را اصلاح کنید.',invalid);}
  if(n===0&&form.querySelector('[data-driver-result]').dataset.status!=='found')return fail('ابتدا تاکسیران را شناسایی یا مشخصات او را تأیید کنید.',form.elements.driver_national_code);
  if(n===1){
   const extras=[...form.querySelectorAll('.extra-invoice-row')];
   if(extras.some(e=>{const t=e.querySelector('[name="extra_title[]"]').value.trim(),p=e.querySelector('[name="extra_price[]"]').value;return !t||money(p)<=0n;}))return fail('عنوان و مبلغ معتبر همه خدمات موردی را وارد کنید.');
   if(!form.querySelector('[data-service-option] input:checked')&&!extras.length)return fail('حداقل یک خدمت انتخاب کنید.');
  }
  if(n===2){
   const due=money(form.querySelector('[data-payable-total]').textContent),paid=money(form.querySelector('[data-payment-paid]').textContent);
   if(due<=0n||paid>due||(!form.querySelector('#invoiceAllowPartial').checked&&paid!==due))return fail('جمع پرداخت و مانده را اصلاح کنید.');
   if(!form.querySelector('#invoiceNoInitialPayment').checked){const amounts=[...form.querySelectorAll('#invoicePaymentRows input[name$="[amount]"]')];if(!amounts.length||amounts.some(e=>money(e.value)<=0n))return fail('مبلغ هر پرداخت باید بیشتر از صفر باشد.');}
  }return true;
 };
 document.getElementById('invoiceWizardOpen').addEventListener('click',()=>{show(0,false);dialog.showModal();status.focus();});
 form.addEventListener('input',()=>dirty=true);form.addEventListener('change',()=>dirty=true);
 const close=()=>{if(busy||form.dataset.submitting==='1')return;if(dirty&&!window.confirm('اطلاعات وارد شده حفظ می‌شوند. از ویزارد خارج می‌شوید؟'))return;dialog.close();document.getElementById('invoiceWizardOpen').focus();};
 cancel.addEventListener('click',close);dialog.addEventListener('cancel',e=>{if(e.target!==dialog)return;e.preventDefault();close();});
 prev.addEventListener('click',()=>{if(!busy)show(step-1);});
 next.addEventListener('click',()=>{if(!busy&&validate(step))show(step+1);});
 form.addEventListener('submit',e=>{
  if(e.defaultPrevented)return;
  if(busy||step!==3){e.preventDefault();return;}
  for(let n=0;n<3;n++){show(n,false);if(!validate(n)){e.preventDefault();return;}}
  show(3,false);
  form.elements.invoice_after_save.value=e.submitter?.dataset.print==='1'?'print':'list';
 },true);
 form.addEventListener('submit',e=>{if(e.defaultPrevented)return;busy=true;form.setAttribute('aria-busy','true');status.textContent='در حال ثبت فاکتور…';[prev,next,cancel,...saves].forEach(b=>b.disabled=true);});
 window.addEventListener('pageshow',()=>{busy=false;form.removeAttribute('aria-busy');[prev,next,cancel,...saves].forEach(b=>b.disabled=false);if(dialog.open)show(step,false);});
 show(0,false);
});