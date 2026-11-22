/* Progressive Excel download with accurate percentage when Content-Length is available. */
document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('[data-report-filter-form]').forEach(form=>{
    const status=form.querySelector('[data-report-progress]');
    if(!status)return;
    const show=(message,busy)=>{
      status.hidden=false;
      status.textContent=message;
      form.setAttribute('aria-busy',String(busy));
    };
    const reset=()=>{form.setAttribute('aria-busy','false');};
    form.addEventListener('submit',async event=>{
      const submitter=event.submitter;
      if(!submitter || submitter.name!=='export' || submitter.value!=='excel'){
        show('در حال پردازش گزارش…',true);
        return;
      }
      if(!window.fetch || !window.Blob || !window.URL || !window.URL.createObjectURL) return;
      event.preventDefault();
      if(form.dataset.exportBusy==='1') return;
      form.dataset.exportBusy='1';
      submitter.disabled=true;
      show('در حال آماده‌سازی فایل اکسل…',true);
      let temporaryUrl=null;
      try {
        const params=new URLSearchParams(new FormData(form));
        params.set('export','excel');
        const response=await fetch('?' + params.toString(),{
          credentials:'same-origin',
          headers:{'Accept':'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}
        });
        if(!response.ok) throw new Error('download-response');
        const type=response.headers.get('Content-Type')||'';
        if(!type.includes('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')) {
          throw new Error('download-type');
        }
        const total=Number(response.headers.get('Content-Length')||0);
        let loaded=0,blob;
        if(response.body && response.body.getReader){
          const reader=response.body.getReader();
          const chunks=[];
          while(true){
            const {done,value}=await reader.read();
            if(done)break;
            chunks.push(value);
            loaded+=value.byteLength;
            if(total>0) show('دریافت فایل اکسل: '+Math.min(99,Math.floor(loaded*100/total)).toLocaleString('fa-IR')+'٪',true);
            else show('در حال دریافت فایل اکسل…',true);
          }
          if(total>0 && loaded!==total) throw new Error('download-incomplete');
          blob=new Blob(chunks,{type});
        }else{
          blob=await response.blob();
        }
        if(!blob.size)throw new Error('download-empty');
        const disposition=response.headers.get('Content-Disposition')||'';
        const nameMatch=/filename="([^"\\/]+\.xlsx)"/i.exec(disposition);
        const fallback=form.querySelector('[name="page"]')?.value==='activity'?'sodoryar-activity.xlsx':'sodoryar-invoices.xlsx';
        temporaryUrl=URL.createObjectURL(blob);
        const link=document.createElement('a');
        link.href=temporaryUrl;
        link.download=nameMatch?nameMatch[1]:fallback;
        link.style.display='none';
        document.body.append(link);
        link.click();
        link.remove();
        show('فایل اکسل با موفقیت آماده شد.',false);
      }catch(error){
        show('دریافت فایل کامل نشد. فیلترها و دسترسی را بررسی کنید و دوباره تلاش کنید.',false);
      }finally{
        form.dataset.exportBusy='0';
        submitter.disabled=false;
        reset();
        if(temporaryUrl) setTimeout(()=>URL.revokeObjectURL(temporaryUrl),60000);
      }
    });
    window.addEventListener('pageshow',()=>{reset();});
  });
});
