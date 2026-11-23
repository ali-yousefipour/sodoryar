// Effective-date service price quotes; the PHP transaction remains authoritative.
document.addEventListener('DOMContentLoaded',()=>{
  const form=document.getElementById('invoiceCreateForm');
  if(!form)return;
  const date=form.querySelector('[name="invoice_date"]');
  const options=[...form.querySelectorAll('[data-service-option]')];
  if(!date||!options.length)return;
  const status=document.getElementById('invoiceRateStatus');
  const submit=form.querySelector('.invoice-submit');
  const fmt=new Intl.NumberFormat('fa-IR');
  let seq=0,ctrl=null,ready=false;
  const mark=(value,message)=>{
    ready=value;
    if(submit)submit.disabled=!value;
    if(status){status.textContent=message;status.hidden=!message;status.setAttribute('aria-invalid',String(!value));}
  };
  const update=async()=>{
    const current=++seq;
    ctrl?.abort();
    if(!/^\d{4}-\d{2}-\d{2}$/.test(date.value)){
      mark(false,'تاریخ فاکتور معتبر نیست.');return;
    }
    ctrl=new AbortController();
    mark(false,'در حال دریافت تعرفه معتبر تاریخ فاکتور…');
    try{
      const response=await fetch('?page=service_rate_quotes&date='+encodeURIComponent(date.value),{
        credentials:'same-origin',cache:'no-store',signal:ctrl.signal
      });
      if(!response.ok)throw new Error('quote unavailable');
      const json=await response.json();
      if(current!==seq || !Array.isArray(json.services))return;
      const rates=new Map(json.services.map(item=>[String(item.id),item]));
      for(const option of options){
        const input=option.querySelector('input[type="checkbox"]');
        if(!input)continue;
        const row=rates.get(input.value);
        if(!row){
          input.checked=false;
          input.disabled=true;
          option.hidden=true;
          continue;
        }
        input.disabled=false;
        option.hidden=false;
        const amount=String(row.price);
        if(!/^\d+$/.test(amount))throw new Error('invalid amount');
        input.dataset.servicePrice=amount;
        option.dataset.serviceTitle=String(row.title);
        const title=option.querySelector('.invoice-service-title');
        if(title)title.textContent=String(row.title);
        const price=option.querySelector('.invoice-service-price');
        if(price){
          const unit=document.createElement('small');unit.textContent='تومان';
          price.replaceChildren(document.createTextNode(fmt.format(BigInt(amount))+' '),unit);
        }
      }
      // Delegate a synthetic selection update to the existing amount/payment calculators.
      const first=options[0].querySelector('input[type="checkbox"]');
      first?.dispatchEvent(new Event('change',{bubbles:true}));
      mark(true,'تعرفه‌ها مطابق تاریخ انتخابی بررسی شدند.');
    }catch(error){
      if(error?.name==='AbortError')return;
      if(current===seq)mark(false,'بروزرسانی تعرفه انجام نشد. تاریخ را دوباره انتخاب کنید.');
    }
  };
  date.addEventListener('change',update);
  form.addEventListener('submit',event=>{
    if(!ready){
      event.preventDefault();
      mark(false,'برای ثبت، ابتدا تعرفه‌های این تاریخ باید تأیید شوند.');
    }
  },true);
  update();
});
