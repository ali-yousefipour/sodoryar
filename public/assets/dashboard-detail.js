(()=>{
  'use strict';
  function init(){
    const dialog=document.getElementById('dashboardDetail');
    if(!dialog||dialog.dataset.ready)return;
    dialog.dataset.ready='1';
    const title=document.getElementById('dashboardDetailTitle'),status=document.getElementById('dashboardDetailStatus'),
      head=document.getElementById('dashboardDetailHead'),body=document.getElementById('dashboardDetailBody'),search=document.getElementById('dashboardDetailSearch');
    let controller=null,allRows=[],lastTrigger=null;
    const digits=s=>String(s).replace(/[۰-۹]/g,c=>String('۰۱۲۳۴۵۶۷۸۹'.indexOf(c))).replace(/[٠-٩]/g,c=>String('٠١٢٣٤٥٦٧٨٩'.indexOf(c)));
    function draw(){
      const term=digits(search.value.trim()).toLocaleLowerCase('fa');
      const rows=allRows.filter(r=>r.some(v=>digits(v??'').toLocaleLowerCase('fa').includes(term)));
      const frag=document.createDocumentFragment();
      for(const row of rows){const tr=document.createElement('tr');for(const value of row){const td=document.createElement('td');td.textContent=value??'—';tr.append(td);}frag.append(tr);}
      body.replaceChildren(frag);
      status.textContent=rows.length?rows.length.toLocaleString('fa-IR')+' ردیف از '+allRows.length.toLocaleString('fa-IR')+' ردیف':'اطلاعاتی برای نمایش وجود ندارد.';
    }
    async function open(card){
      controller?.abort();const request=new AbortController();controller=request;lastTrigger=card;
      title.textContent=(card.querySelector('span')?.textContent||card.closest('dd')?.previousElementSibling?.textContent||'جزئیات شاخص')+' — '+(card.querySelector('b')?.textContent||card.textContent).trim();
      allRows=[];search.value='';head.replaceChildren();body.replaceChildren();status.textContent='در حال دریافت اطلاعات…';search.disabled=true;
      if(!dialog.open)dialog.showModal();
      try{
        const url=new URL(location.href);url.search='';url.searchParams.set('page','dashboard_detail');url.searchParams.set('key',card.dataset.dashboardDetail);
        const response=await fetch(url,{signal:request.signal,credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
        if(!response.ok)throw Error('HTTP '+response.status);
        const data=await response.json();if(request.signal.aborted)return;
        if(!Array.isArray(data.headers)||!Array.isArray(data.rows))throw Error('Invalid response');
        const tr=document.createElement('tr');for(const label of data.headers){const th=document.createElement('th');th.scope='col';th.textContent=label;tr.append(th);}head.append(tr);
        allRows=data.rows;search.disabled=false;draw();
      }catch(error){if(!request.signal.aborted)status.textContent='دریافت جزئیات امکان‌پذیر نیست؛ دوباره تلاش کنید.';}
    }
    document.addEventListener('click',e=>{const card=e.target.closest('[data-dashboard-detail]');if(card)open(card);});
    document.addEventListener('keydown',e=>{const card=e.target.closest('article[data-dashboard-detail]');if(card&&(e.key==='Enter'||e.key===' ')){e.preventDefault();open(card);}});
    search.addEventListener('input',draw);
    dialog.querySelectorAll('[data-dialog-close]').forEach(btn=>btn.addEventListener('click',()=>dialog.close()));
    dialog.addEventListener('close',()=>{controller?.abort();lastTrigger?.focus();});
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init,{once:true});else init();
})();
