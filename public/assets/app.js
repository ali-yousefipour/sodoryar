document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('[data-dialog-open]').forEach(button=>button.addEventListener('click',()=>{
    const dialog=document.getElementById(button.dataset.dialogOpen);
    if(dialog instanceof HTMLDialogElement){dialog.showModal();dialog.querySelector('input:not([type=hidden])')?.focus();}
  }));
  document.querySelectorAll('[data-dialog-close]').forEach(button=>button.addEventListener('click',()=>button.closest('dialog')?.close()));
  document.querySelectorAll('dialog.app-dialog').forEach(dialog=>dialog.addEventListener('click',event=>{if(event.target===dialog)dialog.close();}));
  const editUser=document.getElementById('editUserSelect');
  if(editUser){editUser.addEventListener('change',()=>{
    const choice=editUser.selectedOptions[0];
    document.getElementById('editUserName').value=choice.dataset.fullName||'';
    document.getElementById('editUserUsername').value=choice.dataset.username||'';
    document.getElementById('editUserActive').checked=choice.dataset.active==='1';
  });}
  const templateForm=document.getElementById('templateEditor');
  const templatePreview=document.getElementById('templatePreview');
  if(templateForm&&templatePreview){
    const refreshPreview=()=>{
      templatePreview.querySelector('[data-preview-header]').textContent=templateForm.elements.header_text.value.trim()||'فاکتور خدمات';
      templatePreview.querySelector('[data-preview-footer]').textContent=templateForm.elements.footer_text.value.trim()||'با تشکر از مراجعه شما';
      templatePreview.style.fontFamily=templateForm.elements.font_family.value==='Tahoma'?'Tahoma,sans-serif':'Vazirmatn,Tahoma,sans-serif';
      templatePreview.classList.toggle('a5',templateForm.elements.paper_type.value==='a5');
    };
    templateForm.addEventListener('input',refreshPreview);templateForm.addEventListener('change',refreshPreview);refreshPreview();
  }
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
