document.addEventListener('DOMContentLoaded',()=>{
  const type=document.getElementById('paymentType');
  const ref=document.getElementById('posRef');
  if(type&&ref){const update=()=>{ref.required=type.value==='pos';ref.closest('label').hidden=type.value!=='pos';};type.addEventListener('change',update);update();}
  document.querySelectorAll('[data-print-receipt]').forEach(button=>button.addEventListener('click',()=>window.print()));
  document.querySelectorAll('[data-driver-lookup]').forEach(input=>{
    const result=input.closest('label').querySelector('[data-driver-result]');
    const manual=input.closest('form')?.querySelector('[data-driver-manual]');
    const toggleManual=show=>{
      if(!manual)return;
      manual.hidden=!show;
      manual.querySelectorAll('[data-driver-required]').forEach(field=>{field.required=show;});
    };
    let timer, controller;
    const normalized=s=>s.replace(/[۰-۹]/g,c=>String(c.charCodeAt(0)-1776)).replace(/[٠-٩]/g,c=>String(c.charCodeAt(0)-1632));
    input.addEventListener('input',()=>{
      input.value=normalized(input.value).replace(/[^0-9]/g,'').slice(0,10);
      clearTimeout(timer);controller?.abort();
      toggleManual(false);
      if(input.value.length!==10){result.textContent='کد ملی ده‌رقمی وارد کنید.';return;}
      result.textContent='در حال جستجو…';
      timer=setTimeout(async()=>{
        controller=new AbortController();
        try{
          const response=await fetch('?page=driver_lookup&national_code='+encodeURIComponent(input.value),{credentials:'same-origin',signal:controller.signal,headers:{Accept:'application/json'}});
          const data=await response.json();
          if(response.ok){
            result.textContent=`✓ ${data.driver.first_name} ${data.driver.last_name} — ${data.driver.line_area||'خط نامشخص'}`;
            result.dataset.status='found';
            toggleManual(false);
          }else if(response.status===404){
            result.textContent='این کد ملی در بانک مرکزی موجود نیست؛ مشخصات راننده جدید را ثبت کنید.';
            result.dataset.status='missing';
            toggleManual(true);
            manual?.querySelector('[data-driver-required]')?.focus();
          }else{
            result.textContent=data.error||'جستجو ناموفق بود.';
            result.dataset.status='error';
          }
        }catch(error){if(error.name!=='AbortError')result.textContent='اتصال برقرار نشد؛ دوباره تلاش کنید.';}
      },350);
    });
  });
});
