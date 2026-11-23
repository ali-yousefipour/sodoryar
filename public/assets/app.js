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
  // Desktop mini-sidebar is independent of the existing mobile disclosure.
  const shell=document.querySelector('.app-shell');
  const collapseButton=document.querySelector('[data-sidebar-collapse]');
  const collapsedSearchButton=document.querySelector('[data-sidebar-search-expand]');
  const desktopSidebar=window.matchMedia('(min-width: 901px)');
  const sidebarPreferenceKey='sodoryar.sidebar.collapsed.v1';
  let sidebarCollapsed=false;
  try{sidebarCollapsed=window.localStorage.getItem(sidebarPreferenceKey)==='1';}catch(_error){}
  const setSidebarCollapsed=(collapsed,{persist=true}={})=>{
    sidebarCollapsed=Boolean(collapsed);
    if(sidebarCollapsed&&desktopSidebar.matches){
      const search=document.querySelector('[data-nav-search]');
      if(search?.value){search.value='';search.dispatchEvent(new Event('input',{bubbles:true}));}
    }
    shell?.classList.toggle('sidebar-is-collapsed',sidebarCollapsed&&desktopSidebar.matches);
    if(collapseButton){
      const label=sidebarCollapsed?'باز کردن منوی کناری':'جمع کردن منوی کناری';
      collapseButton.setAttribute('aria-label',label);
      collapseButton.setAttribute('title',label);
      collapseButton.setAttribute('aria-expanded',String(!sidebarCollapsed));
      const icon=collapseButton.querySelector('use');
      if(icon)icon.setAttribute('href','assets/sidebar-icons.svg#'+(sidebarCollapsed?'panel-expand':'panel-collapse'));
    }
    if(persist){try{window.localStorage.setItem(sidebarPreferenceKey,sidebarCollapsed?'1':'0');}catch(_error){}}
  };
  collapseButton?.addEventListener('click',()=>setSidebarCollapsed(!sidebarCollapsed));
  collapsedSearchButton?.addEventListener('click',()=>{
    setSidebarCollapsed(false);
    document.querySelector('[data-nav-search]')?.focus();
  });
  desktopSidebar.addEventListener('change',()=>setSidebarCollapsed(sidebarCollapsed,{persist:false}));
  setSidebarCollapsed(sidebarCollapsed,{persist:false});
  const navSearch=document.querySelector('[data-nav-search]');
  if(navSearch&&sidebarNav){
    const links=[...sidebarNav.querySelectorAll('.nav-link')];
    const sections=[...sidebarNav.querySelectorAll('.nav-section-label')];
    const result=sidebarNav.querySelector('[data-nav-empty]');
    const status=document.querySelector('[data-nav-search-status]');
    const clean=value=>String(value).normalize('NFKC').replace(/ي/g,'ی').replace(/ك/g,'ک').replace(/[\u064B-\u065F\u0670\u200C]/g,'').replace(/\s+/g,' ').trim().toLocaleLowerCase('fa');
    const refresh=()=>{
      const words=clean(navSearch.value).split(' ').filter(Boolean);
      let visible=0;
      links.forEach(link=>{const text=clean(link.textContent+' '+(link.dataset.navCategory||''));const match=words.every(word=>text.includes(word));link.hidden=!match;if(match)visible++;});
      sections.forEach(section=>{
        let next=section.nextElementSibling,found=false;
        while(next&&!next.classList.contains('nav-section-label')){if(next.classList.contains('nav-link')&&!next.hidden)found=true;next=next.nextElementSibling;}
        section.hidden=!found;
      });
      result.hidden=visible!==0;
      status.textContent=words.length?`${new Intl.NumberFormat('fa-IR').format(visible)} بخش پیدا شد`:'';
    };
    navSearch.addEventListener('input',refresh);
    navSearch.addEventListener('keydown',event=>{
      if(event.key==='Escape'&&navSearch.value){navSearch.value='';refresh();event.stopPropagation();event.preventDefault();}
      if(event.key==='ArrowDown'||event.key==='Enter'){
        const first=links.find(link=>!link.hidden);
        if(first){if(event.key==='Enter')first.click();else first.focus();event.preventDefault();}
      }
    });
    refresh();
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
    clear?.addEventListener('click',()=>{hidden.value='';update();});
    field.querySelector('[data-jalali-today]')?.addEventListener('click',()=>{
      // Use the timezone of the issuing company, not the computer's UTC day.
      const parts=new Intl.DateTimeFormat('en-US',{timeZone:'Asia/Tehran',year:'numeric',month:'2-digit',day:'2-digit'}).formatToParts(new Date());
      const date=Object.fromEntries(parts.filter(part=>['year','month','day'].includes(part.type)).map(part=>[part.type,part.value]));
      hidden.value=`${date.year}-${date.month}-${date.day}`;
      update();
    });
    open.addEventListener('click',()=>{
      let source=new Date();
      if(jalaliOutput&&hidden.value){
        const [jy,jm,jd]=hidden.value.split('/').map(Number);
        const iso=gregorianOfPersian(jy,jm,jd||1);if(iso)source=new Date(iso+'T00:00:00Z');
      }else if(hidden.value&&/^\d{4}-\d{2}-\d{2}$/.test(hidden.value))source=new Date(hidden.value+'T00:00:00Z');
      let {y,m}=persianParts(source);
      const dialog=document.createElement('dialog');dialog.className='app-dialog jalali-dialog';dialog.setAttribute('aria-label','انتخاب تاریخ شمسی');
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
      // Never change IDs, model names or reference codes containing Latin letters.
      // These identifiers must remain machine-readable and searchable.
      if(/[A-Za-z]/.test(current.nodeValue))continue;
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
  // Dialogs created in PHP may have headings but no accessible name.
  // Give each such dialog an aria-labelledby relationship to its visible title.
  const labelDialog=(dialog)=>{
    if(dialog.hasAttribute('aria-label')||dialog.hasAttribute('aria-labelledby'))return;
    const heading=dialog.querySelector('h1,h2,h3');
    if(!heading)return;
    if(!heading.id)heading.id='sodoryar-dialog-title-'+Math.random().toString(36).slice(2,12);
    dialog.setAttribute('aria-labelledby',heading.id);
  };
  document.querySelectorAll('[data-dialog-open]').forEach(button=>button.addEventListener('click',()=>{
    const dialog=document.getElementById(button.dataset.dialogOpen);
    if(dialog instanceof HTMLDialogElement){labelDialog(dialog);dialog.showModal();dialog.querySelector('input:not([type=hidden])')?.focus();}
  }));
  document.querySelectorAll('[data-dialog-close]').forEach(button=>button.addEventListener('click',()=>button.closest('dialog')?.close()));
  document.querySelectorAll('dialog.app-dialog').forEach(dialog=>{
    labelDialog(dialog);
    dialog.addEventListener('click',event=>{if(event.target===dialog)dialog.close();});
  });
  const editUser=document.getElementById('editUserSelect');
  if(editUser){
    const userSearch=document.getElementById('editUserSearch');
    const allUsers=[...editUser.options].filter(option=>option.value!=='');
    const foldUser=text=>String(text??'').normalize('NFKC').replace(/ي/g,'ی').replace(/ك/g,'ک').replace(/[۰-۹]/g,d=>String(d.charCodeAt(0)-1776)).replace(/[٠-٩]/g,d=>String(d.charCodeAt(0)-1632)).trim().toLocaleLowerCase('fa');
    const filterUsers=()=>{
      if(!userSearch)return;
      const words=foldUser(userSearch.value).split(/\s+/).filter(Boolean);
      const selectedId=editUser.value;
      allUsers.forEach(option=>{
        const haystack=foldUser([option.textContent,option.dataset.username,option.dataset.nationalCode].join(' '));
        option.hidden=words.length>0&&!words.every(word=>haystack.includes(word))&&option.value!==selectedId;
      });
      // Rebuild the select from the visible options, since some browsers ignore hidden <option>.
      allUsers.forEach(option=>{if(option.hidden)option.remove();else editUser.append(option);});
    };
    userSearch?.addEventListener('input',filterUsers);
    userSearch?.addEventListener('keydown',event=>{
      if(event.key==='Escape'&&userSearch.value){userSearch.value='';filterUsers();event.preventDefault();}
      if(event.key==='ArrowDown'){editUser.focus();event.preventDefault();}
    });
    editUser.addEventListener('change',()=>{
    const choice=editUser.selectedOptions[0];
    document.getElementById('editUserName').value=choice.dataset.fullName||'';
    document.getElementById('editUserUsername').value=choice.dataset.username||'';
    document.getElementById('editUserRole').value=choice.dataset.role||'operator';
    document.getElementById('editUserNational').value=choice.dataset.nationalCode||'';
    document.getElementById('editUserMobile').value=choice.dataset.mobile||'';
    document.getElementById('editUserAddress').value=choice.dataset.address||'';
    document.getElementById('editUserActive').checked=choice.dataset.active==='1';
  });
    document.querySelectorAll('[data-edit-user-id]').forEach(button=>button.addEventListener('click',()=>{
      if(userSearch){userSearch.value='';allUsers.forEach(option=>{option.hidden=false;if(!option.isConnected)editUser.append(option);});}
      editUser.value=button.dataset.editUserId;
      editUser.dispatchEvent(new Event('change'));
      document.getElementById('userEdit')?.showModal();
      document.getElementById('editUserName')?.focus();
    }));
  }
  const extraItems=document.getElementById('extraInvoiceItems');
  const servicePicker=document.querySelector('[data-invoice-services]');
  if(servicePicker){
    const search=servicePicker.querySelector('[data-service-search]');
    const clear=servicePicker.querySelector('[data-service-clear]');
    const options=[...servicePicker.querySelectorAll('[data-service-option]')];
    const count=servicePicker.querySelector('[data-service-count]');
    const selected=servicePicker.querySelector('[data-service-selected]');
    const total=servicePicker.querySelector('[data-service-total]');
    const empty=servicePicker.querySelector('[data-service-empty]');
    const normalize=value=>String(value).normalize('NFKC').replace(/[ي]/g,'ی').replace(/[ك]/g,'ک').replace(/[\u064B-\u065F\u0670\u200C]/g,'').replace(/\s+/g,' ').trim().toLocaleLowerCase('fa');
    const moneyFormat=new Intl.NumberFormat('fa-IR');
    const updateServices=()=>{
      const term=normalize(search.value);
      let visible=0,chosen=0,sum=0n;
      options.forEach(option=>{
        const checkbox=option.querySelector('input[type="checkbox"]');
        const matches=normalize(option.dataset.serviceTitle).includes(term);
        option.hidden=!matches;
        if(matches)visible++;
        option.classList.toggle('is-selected',checkbox.checked);
        if(checkbox.checked){chosen++;sum+=BigInt(checkbox.dataset.servicePrice||'0');}
      });
      count.textContent=`${moneyFormat.format(visible)} خدمت از ${moneyFormat.format(options.length)} خدمت`;
      selected.textContent=`${moneyFormat.format(chosen)} خدمت انتخاب شده`;
      // The preview must not interpret invalid "1.5" or "1,5" as 15 toman.
      // Invalid amounts stay visible as an error until the user corrects them.
      let hasInvalidAmount=false;
      extraItems?.querySelectorAll('input[name="extra_price[]"]').forEach(field=>{
        const raw=String(field.value).trim().replace(/[۰-۹]/g,d=>String(d.charCodeAt(0)-1776))
          .replace(/[٠-٩]/g,d=>String(d.charCodeAt(0)-1632));
        const valid=raw==='' || /^[0-9]+$/.test(raw)
          || /^[0-9]{1,3}(?:[,٬، ][0-9]{3})+$/.test(raw);
        const digits=raw.replace(/[,٬، ]/g,'');
        if(!valid || digits.length>15){hasInvalidAmount=true;return;}
        if(digits)sum+=BigInt(digits);
      });
      total.textContent=hasInvalidAmount?'مبلغ موردی نامعتبر':moneyFormat.format(sum);
      total.setAttribute('aria-invalid',String(hasInvalidAmount));
      empty.hidden=visible!==0;
      clear.hidden=!search.value;
    };
    search.addEventListener('input',updateServices);
    search.addEventListener('keydown',event=>{
      if(event.key==='Escape'&&search.value){search.value='';updateServices();event.preventDefault();}
      if(event.key==='ArrowDown'){
        const first=options.find(option=>!option.hidden);
        if(first){first.querySelector('input').focus();event.preventDefault();}
      }
    });
    clear.addEventListener('click',()=>{search.value='';updateServices();search.focus();});
    servicePicker.addEventListener('change',event=>{if(event.target.matches('input[type="checkbox"]'))updateServices();});
    // The delegated money formatter runs on document after this container
    // listener; recompute the total once the formatted value is canonical.
    extraItems?.addEventListener('input',()=>queueMicrotask(updateServices));
    updateServices();
  }
  // Toman inputs accept localized digits and display grouped Persian numbers.
  // The server parses the same representation, so no hidden alternate value is needed.
  const moneyDigits=text=>String(text??'').replace(/[۰-۹]/g,ch=>String(ch.charCodeAt(0)-1776))
    .replace(/[٠-٩]/g,ch=>String(ch.charCodeAt(0)-1632)).replace(/[^0-9]/g,'');
  const persianMoney=new Intl.NumberFormat('fa-IR',{maximumFractionDigits:0});
  const decorateMoneyInput=field=>{
    if(field.parentElement?.classList.contains('money-input-shell'))return;
    const shell=document.createElement('span');
    shell.className='money-input-shell';
    field.parentNode.insertBefore(shell,field);
    shell.append(field);
    const unit=document.createElement('span');
    unit.className='money-input-unit';
    unit.textContent=field.dataset.currency||'تومان';
    unit.setAttribute('aria-hidden','true');
    shell.append(unit);
  };
  const formatMoneyField=(field,keepCaret=false,inputEvent=null)=>{
    if(field.dataset.moneyComposing==='1')return;
    decorateMoneyInput(field);
    const previous=field.value;
    const normalized=previous.trim().replace(/[۰-۹]/g,ch=>String(ch.charCodeAt(0)-1776))
      .replace(/[٠-٩]/g,ch=>String(ch.charCodeAt(0)-1632));
    const acceptable=normalized==='' || /^[0-9]+$/.test(normalized)
      || /^[0-9]{1,3}(?:[,٬، ][0-9]{3})+$/.test(normalized);
    // Typing onto an already grouped value temporarily creates an incomplete
    // thousands group (e.g. ۱٬۲۳۴۵). Regroup digit keystrokes immediately.
    // Do NOT reinterpret manually pasted malformed grouping such as "1,5".
    const typedDigit=inputEvent?.inputType==='insertText'
      && /^[0-9۰-۹٠-٩]$/.test(inputEvent.data||'');
    const deleting=inputEvent?.inputType?.startsWith('delete');
    const autoGroup=(typedDigit||deleting) && field.getAttribute('aria-invalid')!=='true' && field.dataset.moneyLastValid!==undefined
      && /^[0-9,٬، ]*$/.test(normalized);
    const digits=moneyDigits(normalized);
    const inRange=digits.length<=15 && (digits==='' || BigInt(digits)<=100000000000n);
    if((!acceptable&&!autoGroup)||!inRange){
      field.setCustomValidity('مبلغ را با ارقام صحیح، حداکثر صد میلیارد تومان و بدون اعشار یا جداکننده نامعتبر وارد کنید.');
      field.setAttribute('aria-invalid','true');
      return;
    }
    field.setCustomValidity('');
    field.removeAttribute('aria-invalid');
    const before=keepCaret?moneyDigits(previous.slice(0,field.selectionStart??previous.length)).length:0;
    const formatted=digits?persianMoney.format(BigInt(digits)):'';
    if(previous!==formatted)field.value=formatted;
    field.dataset.moneyLastValid=formatted;
    if(keepCaret&&document.activeElement===field){
      let offset=0,seen=0;
      if(before){for(;offset<formatted.length;offset++){if(/[۰-۹]/.test(formatted[offset]))seen++;if(seen>=before){offset++;break;}}}
      field.setSelectionRange(offset,offset);
    }
  };
  document.querySelectorAll('[data-money-input]').forEach(field=>formatMoneyField(field));
  document.addEventListener('input',event=>{
    const field=event.target;
    if(field instanceof HTMLInputElement&&field.matches('[data-money-input]'))formatMoneyField(field,true,event);
  });
  document.addEventListener('compositionstart',event=>{if(event.target?.matches?.('[data-money-input]'))event.target.dataset.moneyComposing='1';});
  document.addEventListener('compositionend',event=>{if(event.target?.matches?.('[data-money-input]')){delete event.target.dataset.moneyComposing;formatMoneyField(event.target,true);}});
  const extraAdd=document.querySelector('[data-add-invoice-item]');
  if(extraItems&&extraAdd){
    extraAdd.addEventListener('click',()=>{
      if(extraItems.children.length>=10)return;
      const row=document.createElement('div');row.className='extra-invoice-row';
      const title=document.createElement('input');title.name='extra_title[]';title.maxLength=200;title.placeholder='عنوان خدمت موردی';title.setAttribute('aria-label','عنوان خدمت موردی');
      const price=document.createElement('input');price.name='extra_price[]';price.type='text';price.inputMode='numeric';price.maxLength=22;price.dataset.moneyInput='';price.dataset.currency='تومان';price.placeholder='مبلغ به تومان';price.setAttribute('aria-label','مبلغ خدمت موردی');
      const titleLabel=document.createElement('label');titleLabel.className='invoice-extra-label';titleLabel.textContent='عنوان خدمت موردی';titleLabel.append(title);
      const priceLabel=document.createElement('label');priceLabel.className='invoice-extra-label';priceLabel.textContent='مبلغ (تومان)';priceLabel.append(price);
      const remove=document.createElement('button');remove.type='button';remove.className='secondary small';remove.textContent='حذف ردیف';remove.setAttribute('aria-label','حذف این ردیف خدمت موردی');
      remove.addEventListener('click',()=>{row.remove();extraAdd.disabled=false;extraItems.dispatchEvent(new Event('input',{bubbles:true}));});
      row.append(titleLabel,priceLabel,remove);extraItems.append(row);decorateMoneyInput(price);
      extraAdd.disabled=extraItems.children.length>=10;
      title.focus();extraItems.dispatchEvent(new Event('input',{bubbles:true}));
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
      const paper=templateForm.elements.paper_type.value;
      templatePreview.classList.toggle('a5',paper==='a5');
      templatePreview.classList.toggle('a4',paper==='a4');
      const roll=Number(templateForm.elements.roll_width_mm?.value||72);
      templatePreview.style.setProperty('--template-roll-preview',Math.max(56,Math.min(76,roll))+'mm');
      templatePreview.dataset.paperLabel=paper==='a4'?'A4':(paper==='a5'?'A5':'رول حرارتی ۸۰ میلی‌متری');
    };
    const fileControl=templateForm.elements.logo;
    const logoPreview=document.getElementById('templateLogoPreview');
    const printLogo=document.getElementById('templatePreviewLogo');
    let previewUrl=null;
    fileControl?.addEventListener('change',()=>{
      if(previewUrl){URL.revokeObjectURL(previewUrl);previewUrl=null;}
      const file=fileControl.files?.[0];
      const supported=file && ['image/png','image/jpeg'].includes(file.type) && file.size<=1024*1024;
      if(supported) previewUrl=URL.createObjectURL(file);
      for(const target of [logoPreview,printLogo]){
        if(!target)continue;
        target.hidden=!supported;
        if(supported)target.src=previewUrl;
        else target.removeAttribute('src');
      }
    });
    templateForm.addEventListener('input',refreshPreview);templateForm.addEventListener('change',refreshPreview);refreshPreview();
  }
  const type=document.getElementById('paymentType');
  const ref=document.getElementById('posRef');
  if(type&&ref){
    const group=document.createElement('div');group.className='payment-switch';group.setAttribute('role','group');group.setAttribute('aria-label','انتخاب نوع پرداخت');
    const options=[['cash','نقدی'],['pos','کارتخوان']];
    const buttons=options.map(([value,label])=>{
      const button=document.createElement('button');button.type='button';button.className='payment-switch-button';button.textContent=label;button.dataset.payment=value;
      button.addEventListener('click',()=>{type.value=value;type.dispatchEvent(new Event('change',{bubbles:true}));});
      group.append(button);return button;
    });
    type.insertAdjacentElement('afterend',group);
    // Keep the native select for form submission and as a no-JavaScript fallback.
    type.classList.add('payment-native-select');type.tabIndex=-1;type.setAttribute('aria-hidden','true');
    group.addEventListener('keydown',event=>{
      if(!['ArrowLeft','ArrowRight'].includes(event.key))return;
      const next=type.value==='cash'?'pos':'cash';
      group.querySelector(`[data-payment="${next}"]`).click();group.querySelector(`[data-payment="${next}"]`).focus();event.preventDefault();
    });
    const update=()=>{
      ref.required=type.value==='pos';ref.closest('label').hidden=type.value!=='pos';
      buttons.forEach(button=>{const active=button.dataset.payment===type.value;button.classList.toggle('is-active',active);button.setAttribute('aria-pressed',String(active));});
    };
    type.addEventListener('change',update);update();
  }
  document.querySelectorAll('[data-print-receipt]').forEach(button=>button.addEventListener('click',()=>window.print()));
  document.querySelectorAll('[data-driver-lookup]:not([data-driver-autocomplete])').forEach(input=>{
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
  // Invoice-specific national-code autocomplete. Other driver screens retain exact lookup.
  const invoiceDriverInput=document.querySelector('#invoiceCreateForm [data-driver-autocomplete]');
  if(invoiceDriverInput){
    const form=invoiceDriverInput.closest('form');
    const status=form.querySelector('[data-driver-result]');
    const list=form.querySelector('[data-driver-suggestions]');
    const dialog=form.querySelector('dialog[data-driver-manual]');
    const confirm=dialog.querySelector('[data-driver-dialog-confirm]');
    const cancel=dialog.querySelector('[data-driver-dialog-cancel]');
    const required=[...dialog.querySelectorAll('[data-driver-required]')];
    const allFields=[...dialog.querySelectorAll('input,select')];
    const newNational=dialog.querySelector('[data-driver-new-national]');
    const mobileField=dialog.querySelector('[data-driver-mobile]');
    const lineSearch=dialog.querySelector('[data-driver-line-search]');
    const lineSelect=dialog.querySelector('[data-driver-line-select]');
    const lineOptions=[...lineSelect.options].slice(1).map(option=>({value:option.value,label:option.textContent}));
    const normalizeLine=value=>String(value??'').normalize('NFKC').replace(/ي/g,'ی').replace(/ك/g,'ک').trim().toLocaleLowerCase('fa');
    const updateLineOptions=()=>{
      const query=normalizeLine(lineSearch.value);
      const selected=lineSelect.value;
      lineSelect.replaceChildren(new Option('بدون انتخاب خط',''));
      lineOptions.filter(option=>normalizeLine(option.label).includes(query)).forEach(option=>lineSelect.add(new Option(option.label,option.value)));
      if([...lineSelect.options].some(option=>option.value===selected))lineSelect.value=selected;
      if(!lineOptions.length)lineSearch.placeholder='خطی در بانک مرکزی ثبت نشده است';
    };
    lineSearch.addEventListener('input',updateLineOptions);
    const normalizeMobile=value=>{
      let number=toAscii(String(value??'')).replace(/[\s()-]+/g,'');
      if(/^\+989[0-9]{9}$/.test(number))number='0'+number.slice(3);
      return number;
    };
    mobileField.addEventListener('input',()=>mobileField.setCustomValidity(''));
    const toAscii=s=>String(s??'').replace(/[۰-۹]/g,n=>String(n.charCodeAt(0)-1776)).replace(/[٠-٩]/g,n=>String(n.charCodeAt(0)-1632));
    const toPersian=s=>String(s??'').replace(/[0-9]/g,n=>'۰۱۲۳۴۵۶۷۸۹'[Number(n)]);
    let debounce=null,controller=null,requestNumber=0;
    let selected=null,confirmedNational='',lastPrompted='',matches=[],active=-1,lookupState='idle';
    const setStatus=(message,state='idle')=>{
      status.textContent=message;
      status.dataset.status=state;
      lookupState=state;
    };
    const hideList=()=>{
      list.hidden=true;list.replaceChildren();active=-1;
      invoiceDriverInput.setAttribute('aria-expanded','false');
      invoiceDriverInput.removeAttribute('aria-activedescendant');
    };
    const setActive=i=>{
      active=i;
      const options=[...list.querySelectorAll('[role="option"]')];
      options.forEach((item,index)=>item.setAttribute('aria-selected',String(index===active)));
      if(options[active]){
        invoiceDriverInput.setAttribute('aria-activedescendant',options[active].id);
        options[active].scrollIntoView({block:'nearest'});
      }else invoiceDriverInput.removeAttribute('aria-activedescendant');
    };
    const choose=driver=>{
      clearTimeout(debounce);controller?.abort();requestNumber++;
      selected=driver;confirmedNational='';lastPrompted='';
      allFields.forEach(field=>{field.value='';field.required=false;});
      mobileField.setCustomValidity('');lineSearch.value='';updateLineOptions();
      invoiceDriverInput.value=driver.national_code;
      setStatus('✓ '+driver.first_name+' '+driver.last_name+(driver.line_area?' — '+driver.line_area:''),'found');
      hideList();
      if(dialog.open)dialog.close();
    };
    const showMatches=drivers=>{
      matches=drivers;
      list.replaceChildren();active=-1;
      if(!drivers.length){hideList();return;}
      drivers.forEach((driver,index)=>{
        const option=document.createElement('button');
        option.type='button';option.className='invoice-driver-option';
        option.setAttribute('role','option');option.setAttribute('aria-selected','false');
        option.id='invoice-driver-choice-'+index;
        const name=document.createElement('span');
        name.className='invoice-driver-option-name';name.textContent=driver.first_name+' '+driver.last_name;
        const code=document.createElement('b');
        code.className='invoice-driver-option-code';code.textContent=toPersian(driver.national_code);
        const detail=document.createElement('small');
        detail.textContent=driver.line_area||'خط ثبت نشده';
        option.append(name,code,detail);
        option.addEventListener('click',()=>choose(driver));
        list.append(option);
      });
      list.hidden=false;
      invoiceDriverInput.setAttribute('aria-expanded','true');
    };
    const openRegistration=()=>{
      if(invoiceDriverInput.value.length!==10)return;
      selected=null;hideList();
      if(dialog.open)return;
      newNational.textContent=toPersian(invoiceDriverInput.value);
      lastPrompted=invoiceDriverInput.value;
      setStatus('راننده‌ای با این کد ملی یافت نشد. مشخصات او را ثبت کنید.','missing');
      dialog.showModal();
      required[0]?.focus();
    };
    cancel.addEventListener('click',()=>{
      allFields.forEach(field=>{field.required=false;field.value='';});
      mobileField.setCustomValidity('');lineSearch.value='';updateLineOptions();
      confirmedNational='';
      dialog.close();invoiceDriverInput.focus();
    });
    confirm.addEventListener('click',()=>{
      required.forEach(field=>field.required=true);
      mobileField.value=normalizeMobile(mobileField.value);
      mobileField.setCustomValidity(/^09[0-9]{9}$/.test(mobileField.value)?'':'شماره همراه معتبر ۱۱ رقمی با ۰۹ وارد کنید.');
      const firstInvalid=required.find(field=>!field.value.trim()||!field.checkValidity());
      if(firstInvalid){firstInvalid.reportValidity();firstInvalid.focus();return;}
      confirmedNational=invoiceDriverInput.value;
      setStatus('✓ مشخصات راننده جدید آماده ثبت همراه فاکتور است.','found');
      dialog.close();
      form.querySelector('[data-service-search]')?.focus();
    });
    dialog.addEventListener('cancel',()=>{
      if(confirmedNational!==invoiceDriverInput.value){
        required.forEach(field=>field.required=false);
      }
    });
    const fetchMatches=async code=>{
      controller?.abort();controller=new AbortController();
      const serial=++requestNumber;
      setStatus('در حال جستجوی رانندگان…','loading');
      try{
        const response=await fetch('?page=driver_lookup&prefix='+encodeURIComponent(code),{
          credentials:'same-origin',signal:controller.signal,headers:{Accept:'application/json'}
        });
        const data=await response.json();
        if(serial!==requestNumber||invoiceDriverInput.value!==code)return;
        if(!response.ok){
          hideList();
          setStatus(data.error||'جستجو ناموفق بود؛ دوباره تلاش کنید.','error');
          return;
        }
        const drivers=Array.isArray(data.drivers)?data.drivers:[];
        const exact=code.length===10?drivers.find(driver=>driver.national_code===code):null;
        if(exact){choose(exact);return;}
        if(code.length===10){
          hideList();
          if(lastPrompted!==code)openRegistration();
          else setStatus('برای این کد ملی، مشخصات راننده جدید را تکمیل کنید.','missing');
          return;
        }
        showMatches(drivers);
        setStatus(drivers.length?toPersian(drivers.length)+' راننده مشابه یافت شد؛ یک مورد را انتخاب کنید.':'راننده‌ای با این پیشوند پیدا نشد.','idle');
      }catch(error){
        if(error.name==='AbortError')return;
        if(serial!==requestNumber)return;
        hideList();setStatus('ارتباط با بانک رانندگان برقرار نشد؛ دوباره تلاش کنید.','error');
      }
    };
    invoiceDriverInput.addEventListener('input',()=>{
      const code=toAscii(invoiceDriverInput.value).replace(/[^0-9]/g,'').slice(0,10);
      invoiceDriverInput.value=code;
      clearTimeout(debounce);controller?.abort();requestNumber++;
      hideList();selected=null;confirmedNational='';
      allFields.forEach(field=>{field.required=false;field.value='';});
      mobileField.setCustomValidity('');lineSearch.value='';updateLineOptions();
      if(dialog.open)dialog.close();
      lastPrompted='';
      if(code.length<6){setStatus('از رقم ششم کد ملی، جستجو آغاز می‌شود.');return;}
      debounce=setTimeout(()=>fetchMatches(code),280);
    });
    invoiceDriverInput.addEventListener('keydown',event=>{
      if(event.key==='Escape'&&!list.hidden){hideList();event.preventDefault();return;}
      if(list.hidden)return;
      if(event.key==='ArrowDown'){setActive((active+1)%matches.length);event.preventDefault();}
      if(event.key==='ArrowUp'){setActive(active<=0?matches.length-1:active-1);event.preventDefault();}
      if(event.key==='Enter'&&active>=0){choose(matches[active]);event.preventDefault();}
    });
    document.addEventListener('pointerdown',event=>{
      if(!list.hidden&&!event.target.closest('.invoice-driver-wrap'))hideList();
    });
    form.addEventListener('submit',event=>{
      const code=invoiceDriverInput.value;
      if(code.length!==10)return;
      if(selected?.national_code===code||confirmedNational===code)return;
      event.preventDefault();
      if(lookupState==='loading'){setStatus('جستجوی کد ملی هنوز در حال انجام است.','loading');invoiceDriverInput.focus();return;}
      if(lookupState==='error'){setStatus('ابتدا اتصال را بررسی و کد ملی را دوباره جستجو کنید.','error');invoiceDriverInput.focus();return;}
      openRegistration();
    });
  }
  // A browser back/forward cache may restore a page with disabled submit buttons.
  // Reset UI-only busy state on pageshow; server-side nonces remain authoritative.
  const markFinancialSubmitting=(form,submit,label,event)=>{
    if(form.dataset.submitting==='1'){
      event.preventDefault();
      return;
    }
    form.dataset.submitting='1';
    form.setAttribute('aria-busy','true');
    if(submit){
      if(!submit.dataset.submitOriginalText)submit.dataset.submitOriginalText=submit.textContent;
      submit.disabled=true;
      submit.setAttribute('aria-busy','true');
      submit.textContent=label;
    }
  };
  const invoiceForm=document.getElementById('invoiceCreateForm');
  invoiceForm?.addEventListener('submit',event=>{
    if(event.defaultPrevented)return;
    const submit=invoiceForm.querySelector('button[type="submit"]');
    markFinancialSubmitting(invoiceForm,submit,'در حال ثبت فاکتور…',event);
  });
  document.querySelectorAll('[data-bill-payment-method]').forEach(method=>{
    const form=method.closest('form');
    const reference=form?.querySelector('[data-bill-payment-ref]');
    if(!reference)return;
    const update=()=>{
      reference.required=method.value==='pos';
      reference.setAttribute('aria-required',String(reference.required));
    };
    method.addEventListener('change',update);
    update();
  });
  document.querySelectorAll('form[data-once-submit]').forEach(form=>{
    form.addEventListener('submit',event=>{
      if(event.defaultPrevented)return;
      const submit=form.querySelector('button[type="submit"],button:not([type])');
      markFinancialSubmitting(form,submit,'در حال ثبت…',event);
    });
  });
  window.addEventListener('pageshow',()=>{
    document.querySelectorAll('form[data-submitting="1"]').forEach(form=>{
      delete form.dataset.submitting;
      form.removeAttribute('aria-busy');
      form.querySelectorAll('button[data-submit-original-text]').forEach(button=>{
        button.disabled=false;
        button.removeAttribute('aria-busy');
        button.textContent=button.dataset.submitOriginalText;
        delete button.dataset.submitOriginalText;
      });
    });
  });
  // Render-only Persian numerals for labels, counters and table content.
  // Inputs, URLs, machine identifiers and Latin technical strings are deliberately excluded.
  const localizeVisibleDigits=root=>{
    if(!root)return;
    const walker=document.createTreeWalker(root,NodeFilter.SHOW_TEXT);
    const nodes=[];
    while(walker.nextNode()){
      const node=walker.currentNode,raw=node.nodeValue||'';
      if(!/[0-9]/.test(raw)||/[A-Za-z]/.test(raw))continue;
      const parent=node.parentElement;
      if(parent?.closest('script,style,code,pre,textarea,select,option,[contenteditable="true"],[data-keep-latin]'))continue;
      nodes.push(node);
    }
    nodes.forEach(node=>{node.nodeValue=node.nodeValue.replace(/[0-9]/g,d=>'۰۱۲۳۴۵۶۷۸۹'[Number(d)]);});
  };
  document.querySelectorAll('.main-wrap>.content,.main-wrap>.topbar,.app-shell>.sidebar').forEach(localizeVisibleDigits);
});
