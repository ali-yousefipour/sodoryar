document.addEventListener('DOMContentLoaded',()=>{
  const sidebarToggle=document.querySelector('[data-sidebar-toggle]');
  const sidebarNav=document.getElementById('primaryNav');
  if(sidebarToggle&&sidebarNav){
    const mobileViewport=window.matchMedia('(max-width: 900px)');
    const toggleLabel=sidebarToggle.querySelector('[data-sidebar-toggle-label]');
    const toggleIcon=sidebarToggle.querySelector('use');
    const setMenuOpen=expanded=>{
      sidebarToggle.setAttribute('aria-expanded',String(expanded));
      sidebarNav.classList.toggle('nav-open',expanded);
      if(toggleLabel)toggleLabel.textContent=expanded?'بستن فهرست بخش‌ها':'نمایش فهرست بخش‌ها';
      if(toggleIcon)toggleIcon.setAttribute('href','assets/sidebar-icons.svg#'+(expanded?'close':'menu'));
    };
    sidebarToggle.addEventListener('click',()=>{
      const expanded=sidebarToggle.getAttribute('aria-expanded')!=='true';
      setMenuOpen(expanded);
      if(expanded)sidebarNav.querySelector('a')?.focus();
    });
    sidebarNav.addEventListener('keydown',event=>{
      if(event.key==='Escape'&&mobileViewport.matches){setMenuOpen(false);sidebarToggle.focus();}
    });
    sidebarNav.addEventListener('click',event=>{if(mobileViewport.matches&&event.target.closest('a'))setMenuOpen(false);});
    mobileViewport.addEventListener('change',()=>setMenuOpen(false));
    setMenuOpen(false);
  }
  const persianDigits=value=>String(value).replace(/[0-9]/g,d=>'۰۱۲۳۴۵۶۷۸۹'[Number(d)]);
  const persianCalendar=new Intl.DateTimeFormat('en-US-u-ca-persian',{year:'numeric',month:'numeric',day:'numeric',timeZone:'UTC'});
  const persianParts=date=>{
    const parts=Object.fromEntries(persianCalendar.formatToParts(date).filter(p=>['year','month','day'].includes(p.type)).map(p=>[p.type,Number(p.value)]));
    return {y:parts.year,m:parts.month,d:parts.day};
  };
  const gregorianOfPersian=(y,m,d)=>{
    const target=y*10000+m*100+d;
    let low=Math.floor(Date.UTC(y+620,0,1)/86400000),high=Math.floor(Date.UTC(y+623,0,1)/86400000);
    while(low<=high){
      const mid=Math.floor((low+high)/2),p=persianParts(new Date(mid*86400000));
      const value=p.y*10000+p.m*100+p.d;
      if(value===target)return new Date(mid*86400000).toISOString().slice(0,10);
      if(value<target)low=mid+1;else high=mid-1;
    }
    return null;
  };
  const monthNames=['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
  const formatPersianDate=iso=>{
    if(!/^\d{4}-\d{2}-\d{2}$/.test(iso))return 'انتخاب تاریخ';
    const p=persianParts(new Date(iso+'T00:00:00Z'));
    return persianDigits(`${p.y}/${String(p.m).padStart(2,'0')}/${String(p.d).padStart(2,'0')}`);
  };
  document.querySelectorAll('.jalali-field').forEach(field=>{
    const hidden=field.querySelector('[data-jalali-iso]');
    const open=field.querySelector('[data-jalali-open]');
    const clear=field.querySelector('[data-jalali-clear]');
    const jalaliOutput=hidden.dataset.jalaliOutput==='jalali';
    const periodType=jalaliOutput?field.closest('form')?.querySelector('[name=period_type]'):null;
    const update=()=>{open.textContent=jalaliOutput?(hidden.value?persianDigits(hidden.value):'انتخاب دوره شمسی'):formatPersianDate(hidden.value);};
    periodType?.addEventListener('change',()=>{
      if(!hidden.value)return;
      const parts=hidden.value.split('/');
      hidden.value=periodType.value==='monthly'?parts.slice(0,2).join('/'):parts.slice(0,2).join('/')+'/'+(parts[2]||'01');update();
    });
    clear.addEventListener('click',()=>{hidden.value='';update();});
    open.addEventListener('click',()=>{
      let source=new Date();
      if(jalaliOutput&&hidden.value){
        const [jy,jm,jd]=hidden.value.split('/').map(Number);
        const iso=gregorianOfPersian(jy,jm,jd||1);if(iso)source=new Date(iso+'T00:00:00Z');
      }else if(hidden.value&&/^\d{4}-\d{2}-\d{2}$/.test(hidden.value))source=new Date(hidden.value+'T00:00:00Z');
      let {y,m}=persianParts(source);
      const dialog=document.createElement('dialog');dialog.className='app-dialog jalali-dialog';
      const title=document.createElement('strong');title.className='calendar-title';
      const grid=document.createElement('div');grid.className='calendar-grid';
      const prev=document.createElement('button'),next=document.createElement('button'),close=document.createElement('button');
      prev.type=next.type=close.type='button';prev.textContent='ماه قبل';next.textContent='ماه بعد';close.textContent='بستن';close.className='secondary';
      const header=document.createElement('div');header.className='calendar-header';header.append(prev,title,next);
      const draw=()=>{
        title.textContent=`${monthNames[m-1]} ${persianDigits(y)}`;grid.replaceChildren();
        const iso=gregorianOfPersian(y,m,1);if(!iso)return;
        const first=new Date(iso+'T00:00:00Z');
        const start=(first.getUTCDay()+1)%7;
        ['ش','ی','د','س','چ','پ','ج'].forEach(day=>{const label=document.createElement('span');label.className='calendar-weekday';label.textContent=day;grid.append(label);});
        for(let i=0;i<start;i++)grid.append(document.createElement('span'));
        for(let d=1;d<=31;d++){
          const date=new Date(first.getTime()+(d-1)*86400000);const p=persianParts(date);
          if(p.y!==y||p.m!==m)break;
          const btn=document.createElement('button');btn.type='button';btn.className='calendar-day secondary';btn.textContent=persianDigits(d);
          const value=date.toISOString().slice(0,10);
          const jalaliValue=`${p.y}/${String(p.m).padStart(2,'0')}/${String(p.d).padStart(2,'0')}`;
          if(hidden.value===value||(jalaliOutput&&hidden.value===jalaliValue))btn.classList.add('selected');
          btn.addEventListener('click',()=>{hidden.value=jalaliOutput?(periodType?.value==='monthly'?jalaliValue.slice(0,7):jalaliValue):value;update();dialog.close();});grid.append(btn);
        }
      };
      prev.addEventListener('click',()=>{m--;if(m===0){m=12;y--;}draw();});
      next.addEventListener('click',()=>{m++;if(m===13){m=1;y++;}draw();});
      close.addEventListener('click',()=>dialog.close());
      const footer=document.createElement('div');footer.className='dialog-actions';footer.append(close);
      dialog.append(header,grid,footer);document.body.append(dialog);
      dialog.addEventListener('close',()=>dialog.remove(),{once:true});draw();dialog.showModal();
    });
    update();
  });
  const localizeText=root=>{
    const walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT);
    let current;
    while((current=walker.nextNode())){
      if(current.parentElement?.closest('script,style,code,pre,textarea,option'))continue;
      if(/[0-9]/.test(current.nodeValue)){
        const localized=current.nodeValue.replace(/\b(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2})(?::\d{2})?)?\b/g,(full,iso,time)=>{
          const rendered=formatPersianDate(iso);
          return rendered==='انتخاب تاریخ'?full:rendered+(time?' '+time:'');
        });
        current.nodeValue=persianDigits(localized);
      }
    }
  };
  localizeText(document.body);
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
    document.getElementById('editUserNational').value=choice.dataset.nationalCode||'';
    document.getElementById('editUserMobile').value=choice.dataset.mobile||'';
    document.getElementById('editUserAddress').value=choice.dataset.address||'';
    document.getElementById('editUserActive').checked=choice.dataset.active==='1';
  });}
  const extraItems=document.getElementById('extraInvoiceItems');
  const extraAdd=document.querySelector('[data-add-invoice-item]');
  if(extraItems&&extraAdd){
    extraAdd.addEventListener('click',()=>{
      if(extraItems.children.length>=10)return;
      const row=document.createElement('div');row.className='extra-invoice-row';
      const title=document.createElement('input');title.name='extra_title[]';title.maxLength=200;title.placeholder='عنوان خدمت موردی';title.setAttribute('aria-label','عنوان خدمت موردی');
      const price=document.createElement('input');price.name='extra_price[]';price.type='number';price.min='1';price.max='100000000000';price.placeholder='مبلغ به تومان';price.setAttribute('aria-label','مبلغ خدمت موردی');
      const remove=document.createElement('button');remove.type='button';remove.className='secondary small';remove.textContent='حذف ردیف';remove.addEventListener('click',()=>row.remove());
      row.append(title,price,remove);extraItems.append(row);title.focus();
    });
  }
  const templateForm=document.getElementById('templateEditor');
  const templatePreview=document.getElementById('templatePreview');
  if(templateForm&&templatePreview){
    const orderList=document.getElementById('templateOrderList');
    const orderInput=document.getElementById('templateBlockOrder');
    if(orderList&&orderInput){
      const syncOrder=()=>{
        const keys=[...orderList.querySelectorAll('[data-block]')].map(el=>el.dataset.block);
        orderInput.value=keys.join(',');
        keys.forEach(key=>{const part=templatePreview.querySelector(`[data-block="${key}"]`);if(part)templatePreview.append(part);});
      };
      orderList.addEventListener('click',event=>{
        const button=event.target.closest('button[data-move-up],button[data-move-down]');if(!button)return;
        const item=button.closest('[data-block]');
        if(button.hasAttribute('data-move-up')&&item.previousElementSibling)orderList.insertBefore(item,item.previousElementSibling);
        if(button.hasAttribute('data-move-down')&&item.nextElementSibling)orderList.insertBefore(item.nextElementSibling,item);
        syncOrder();
      });
      let dragged=null;
      orderList.addEventListener('dragstart',event=>{dragged=event.target.closest('[data-block]');});
      orderList.addEventListener('dragover',event=>{if(dragged&&event.target.closest('[data-block]'))event.preventDefault();});
      orderList.addEventListener('drop',event=>{const target=event.target.closest('[data-block]');if(dragged&&target&&target!==dragged){event.preventDefault();orderList.insertBefore(dragged,target);syncOrder();}dragged=null;});
      orderList.addEventListener('dragend',()=>{dragged=null;});
      syncOrder();
    }
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
