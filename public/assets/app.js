document.addEventListener('DOMContentLoaded',()=>{
  const type=document.getElementById('paymentType');
  const ref=document.getElementById('posRef');
  if(type&&ref){const update=()=>{ref.required=type.value==='pos';ref.closest('label').hidden=type.value!=='pos';};type.addEventListener('change',update);update();}
  document.querySelectorAll('[data-print-receipt]').forEach(button=>button.addEventListener('click',()=>window.print()));
  document.querySelectorAll('[data-driver-lookup]').forEach(input=>{
    const result=input.closest('label').querySelector('[data-driver-result]');
    let timer, controller;
    const normalized=s=>s.replace(/[۰-۹]/g,c=>String(c.charCodeAt(0)-1776)).replace(/[٠-٩]/g,c=>String(c.charCodeAt(0)-1632));
    input.addEventListener('input',()=>{
      input.value=normalized(input.value).replace(/[^0-9]/g,'').slice(0,10);
      clearTimeout(timer);controller?.abort();
      if(input.value.length!==10){result.textContent='کد ملی ده‌رقمی وارد کنید.';return;}
      result.textContent='در حال جستجو…';
      timer=setTimeout(async()=>{
        controller=new AbortController();
        try{
          const response=await fetch('?page=driver_lookup&national_code='+encodeURIComponent(input.value),{credentials:'same-origin',signal:controller.signal,headers:{Accept:'application/json'}});
          const data=await response.json();
          result.textContent=response.ok?`${data.driver.first_name} ${data.driver.last_name} — ${data.driver.line_area||'خط نامشخص'}`:(data.error||'تاکسیران پیدا نشد.');
        }catch(error){if(error.name!=='AbortError')result.textContent='اتصال برقرار نشد؛ دوباره تلاش کنید.';}
      },350);
    });
  });
});
