/* Progressive enhancement for all data tables: searchable, sortable and paginated.
   No API, mutations or permission changes; original rows remain in the DOM. */
document.addEventListener('DOMContentLoaded', () => {
  const persianNumber = new Intl.NumberFormat('fa-IR');
  const normalize = text => String(text ?? '').normalize('NFKC')
    .replace(/[ي]/g,'ی').replace(/[ك]/g,'ک')
    .replace(/[۰-۹]/g,ch=>String(ch.charCodeAt(0)-1776))
    .replace(/[٠-٩]/g,ch=>String(ch.charCodeAt(0)-1632))
    .replace(/[\u064B-\u065F\u0670\u200C]/g,'')
    .replace(/\s+/g,' ').trim().toLocaleLowerCase('fa');
  const numeric = text => {
    const s = normalize(text).replace(/[٬,،\s]/g,'').replace(/(تومان|ریال|%|٪)/g,'');
    return /^[-+]?\d+(?:\.\d+)?$/.test(s) ? Number(s) : null;
  };
  const dateKey = text => {
    const match = normalize(text).match(/(?:^|\s)(1[34]\d{2})[\/-](\d{1,2})[\/-](\d{1,2})/);
    return match ? Number(match[1])*10000+Number(match[2])*100+Number(match[3]):null;
  };
  const cellText = cell => {
    if(!cell)return '';
    const clone = cell.cloneNode(true);
    clone.querySelectorAll('dialog,button,form,input,select,textarea,script,.dialog-actions').forEach(el=>el.remove());
    return normalize(clone.textContent);
  };
  document.querySelectorAll('.main-wrap>.content .table-wrap').forEach((wrap,index)=>{
    if(wrap.closest('.receipt,.invoice-paper,.template-designer,dialog'))return;
    const table=wrap.querySelector(':scope > table');
    if(!table || table.dataset.tableEnhanced)return;
    const head=table.tHead?.rows[0],body=table.tBodies[0];
    if(!head||!body)return;
    const rows=[...body.rows].filter(row=>!row.querySelector('.empty-state')&&row.cells.length>1);
    if(!rows.length)return;
    table.dataset.tableEnhanced='true';
    const id='sd-table-'+(index+1);
    if(!wrap.id)wrap.id=id;
    wrap.tabIndex=0;
    wrap.setAttribute('role','region');
    const context=wrap.closest('.card')?.querySelector('h2')?.textContent
      ||document.querySelector('.main-wrap>.content h1')?.textContent||'جدول اطلاعات';
    wrap.setAttribute('aria-label',context.trim());
    const toolbar=document.createElement('div');
    toolbar.className='sd-table-toolbar';
    const searchBox=document.createElement('label');
    searchBox.className='sd-table-search';
    const searchTitle=document.createElement('span');
    searchTitle.textContent='جستجو در '+context.trim();
    const search=document.createElement('input');
    search.type='search';
    search.placeholder='جستجو در ردیف‌ها…';
    search.autocomplete='off';
    search.setAttribute('aria-controls',wrap.id);
    search.setAttribute('aria-label','جستجو در '+context.trim());
    searchBox.append(searchTitle,search);
    const count=document.createElement('span');
    count.className='sd-table-count';
    count.setAttribute('role','status');
    count.setAttribute('aria-live','polite');
    const limitBox=document.createElement('label');
    limitBox.className='sd-table-limit';
    limitBox.append(document.createTextNode('نمایش'));
    const limit=document.createElement('select');
    [['15','۱۵'],['30','۳۰'],['50','۵۰'],['all','همه']].forEach(([value,label])=>{
      const option=document.createElement('option');option.value=value;option.textContent=label;limit.append(option);
    });
    limit.value=rows.length<=15?'all':'15';
    limit.setAttribute('aria-label','تعداد ردیف‌های قابل مشاهده');
    limitBox.append(limit);
    toolbar.append(searchBox,count,limitBox);
    wrap.parentNode.insertBefore(toolbar,wrap);
    const pager=document.createElement('div');
    pager.className='sd-table-pager';
    const prev=document.createElement('button');prev.type='button';prev.className='secondary small';prev.textContent='قبلی';
    const next=document.createElement('button');next.type='button';next.className='secondary small';next.textContent='بعدی';
    const pages=document.createElement('span');pages.className='sd-page-count';pages.setAttribute('aria-live','polite');
    pager.append(prev,pages,next);
    wrap.insertAdjacentElement('afterend',pager);
    const notFound=document.createElement('div');
    notFound.className='sd-table-empty';notFound.hidden=true;
    notFound.textContent='ردیفی مطابق عبارت جستجو یافت نشد.';
    pager.insertAdjacentElement('afterend',notFound);
    const records=rows.map((row,original)=>({
      row,original,cells:[...row.cells].map(cellText),
      searchable:[...row.cells].map((cell,i)=>i===row.cells.length-1&&/(عملیات|جزئیات|دکمه)/.test(head.cells[i]?.textContent||'')?'':cellText(cell)).join(' ')
    }));
    let page=0,sortIndex=-1,direction=1;
    const update=()=>{
      const words=normalize(search.value).split(' ').filter(Boolean);
      const filtered=records.filter(record=>words.every(word=>record.searchable.includes(word)));
      if(sortIndex>=0)filtered.sort((a,b)=>{
        const av=a.cells[sortIndex]||'',bv=b.cells[sortIndex]||'';
        const an=numeric(av),bn=numeric(bv),ad=dateKey(av),bd=dateKey(bv);
        const comparison=an!==null&&bn!==null?an-bn:
          ad!==null&&bd!==null?ad-bd:av.localeCompare(bv,'fa',{numeric:true,sensitivity:'base'});
        return (comparison||a.original-b.original)*direction;
      });
      const size=limit.value==='all'?Math.max(filtered.length,1):Number(limit.value);
      const totalPages=Math.max(1,Math.ceil(filtered.length/size));
      page=Math.min(page,totalPages-1);
      records.forEach(({row})=>row.hidden=true);
      filtered.forEach(({row},i)=>{row.hidden=i<page*size||i>=(page+1)*size;body.append(row);});
      count.textContent=persianNumber.format(filtered.length)+' مورد از '+persianNumber.format(records.length);
      pages.textContent='صفحه '+persianNumber.format(page+1)+' از '+persianNumber.format(totalPages);
      prev.disabled=page<=0;next.disabled=page>=totalPages-1;
      pager.hidden=limit.value==='all'||totalPages<=1;
      notFound.hidden=filtered.length!==0;
    };
    [...head.cells].forEach((th,i)=>{
      if(th.colSpan>1||/(عملیات|جزئیات|ویرایش|حذف|اکشن)/.test(th.textContent||''))return;
      const text=th.textContent.trim();
      if(!text)return;
      th.textContent='';
      const button=document.createElement('button');
      button.type='button';button.className='sd-sort-button';button.textContent=text;
      const indicator=document.createElement('span');indicator.className='sd-sort-indicator';indicator.setAttribute('aria-hidden','true');indicator.textContent='↕';
      button.append(indicator);
      button.setAttribute('aria-label','مرتب‌سازی بر اساس '+text);
      th.append(button);
      button.addEventListener('click',()=>{
        direction=sortIndex===i?-direction:1;sortIndex=i;page=0;
        [...head.cells].forEach(cell=>cell.setAttribute('aria-sort','none'));
        th.setAttribute('aria-sort',direction===1?'ascending':'descending');
        [...head.querySelectorAll('.sd-sort-indicator')].forEach(el=>el.textContent='↕');
        indicator.textContent=direction===1?'↑':'↓';update();
      });
    });
    search.addEventListener('input',()=>{page=0;update();});
    search.addEventListener('keydown',event=>{if(event.key==='Escape'&&search.value){search.value='';page=0;update();}});
    limit.addEventListener('change',()=>{page=0;update();});
    prev.addEventListener('click',()=>{page--;update();});
    next.addEventListener('click',()=>{page++;update();});
    update();
  });
});
