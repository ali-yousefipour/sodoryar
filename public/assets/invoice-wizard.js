document.addEventListener('DOMContentLoaded',()=>{
 const form=document.getElementById('invoiceCreateForm'), dialog=document.getElementById('invoiceWizard');
 if(!form||!dialog)return;
 const stages=[...form.querySelectorAll('[data-wizard-stage]')], footer=form.querySelector('.invoice-form-footer');
 const prev=footer.querySelector('[data-wizard-prev]'),next=footer.querySelector('[data-wizard-next]'),cancel=footer.querySelector('[data-wizard-cancel]'), saves=[...footer.querySelectorAll('[type=submit]')];
 const status=form.querySelector('[data-wizard-status]'), error=form.querySelector('[data-wizard-error]');
 let step=0,dirty=false,busy=false;
 const ascii=s=>String(s??'').replace(/[۰-۹]/g,c=>c.charCodeAt(0)-1776).replace(/[٠-٩]/g,c=>c.charCodeAt(0)-1632);
 const money=s=>{const v=ascii(s).replace(/[٬،]/g,',').trim();return /^(?:\d+|\d{1,3}(?:,\d{3})+)$/.test(v)?BigInt(v.replace(/,/g,'')):0n;};
 const show=(n,focus=true)=>{
  step=n;stages.forEach(e=>e.hidden=Number(e.dataset.wizardStage)!==n+1);
  prev.hidden=n===0;next.hidden=n===3;saves.forEach(b=>b.hidden=n!==3);
  status.textContent=['۱ از ۴ — انتخاب تاکسیران','۲ از ۴ — خدمات و اقلام','۳ از ۴ — پرداخت','۴ از ۴ — بررسی نهایی'][n];
  error.textContent='';
  if(n===3){
   const list=form.querySelector('[data-wizard-review]');list.replaceChildren();
   const add=(k,v)=>{const p=document.createElement('p');p.textContent=k+' : '+v;list.append(p);};
   add('کد ملی',form.elements.driver_national_code.value);
   add('تاکسیران',form.querySelector('[data-driver-result]').textContent);
   form.querySelectorAll('[data-service-option] input:checked').forEach(e=>add('خدمت',e.closest('label').innerText));
   form.querySelectorAll('.extra-invoice-row').forEach(e=>add('خدمت موردی',e.querySelector('[name="extra_title[]"]').value+' — '+e.querySelector('[name="extra_price[]"]').value));
   ['payable-total','payment-paid','payment-remaining','payment-count'].forEach((k,i)=>add(['مبلغ فاکتور','دریافتی','مانده','تعداد تراکنش'][i],form.querySelector('[data-'+k+']').textContent));
  }
  if(focus)status.focus();
 };
 const fail=(message,field)=>{error.textContent=message;field?.focus();return false;};
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
 const close=()=>{if(busy||form.dataset.submitting==='1')return;if(dirty&&!window.confirm('اطلاعات وارد شده حفظ می‌شوند. از ویزارد خارج می‌شوید؟'))return;dialog.close();};
 cancel.addEventListener('click',close);dialog.addEventListener('cancel',e=>{e.preventDefault();close();});
 prev.addEventListener('click',()=>{if(!busy)show(step-1);});
 next.addEventListener('click',()=>{if(!busy&&validate(step))show(step+1);});
 form.addEventListener('submit',e=>{
  if(step!==3){e.preventDefault();return;}
  for(let n=0;n<3;n++){show(n,false);if(!validate(n)){e.preventDefault();return;}}
  show(3,false);
  form.elements.invoice_after_save.value=e.submitter?.dataset.print==='1'?'print':'list';
 },true);
 form.addEventListener('submit',e=>{if(e.defaultPrevented)return;busy=true;[prev,next,cancel,...saves].forEach(b=>b.disabled=true);});
 window.addEventListener('pageshow',()=>{busy=false;[prev,next,cancel,...saves].forEach(b=>b.disabled=false);});
 show(0,false);
});