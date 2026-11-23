// Permission-filtered global search: content is always inserted as text, never HTML.
document.addEventListener('DOMContentLoaded',()=>{
  const dialog=document.getElementById('globalSearchDialog');
  const input=document.getElementById('globalSearchInput');
  const results=document.getElementById('globalSearchResults');
  if(!dialog||!input||!results)return;
  const names={customers:'تاکسیرانان',invoices:'فاکتورها',payments:'دریافت‌ها',vehicles:'خودروها و پلاک',services:'خدمات'};
  const normalize=s=>String(s).replace(/[۰-۹]/g,c=>String(c.charCodeAt(0)-1776))
    .replace(/[٠-٩]/g,c=>String(c.charCodeAt(0)-1632)).trim();
  let timer=null,controller=null;
  const showMessage=message=>{results.replaceChildren();const p=document.createElement('p');p.textContent=message;results.append(p);};
  const open=()=>{dialog.showModal();input.focus();};
  document.querySelector('[data-global-search-open]')?.addEventListener('click',open);
  dialog.querySelector('[data-global-search-close]')?.addEventListener('click',()=>dialog.close());
  dialog.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();event.stopPropagation();dialog.close();}},true);
  document.addEventListener('keydown',event=>{
    const tag=document.activeElement?.tagName?.toLowerCase();
    const editable=Boolean(document.activeElement?.isContentEditable || ['input','textarea','select'].includes(tag));
    if(event.altKey&&!event.ctrlKey&&!event.metaKey&&!event.shiftKey&&!event.isComposing&&!editable){
      const destination=event.code==='KeyN'?document.querySelector('#primaryNav a[href="?page=invoices"]'):
        event.code==='KeyF'?document.querySelector('#primaryNav a[href="?page=invoice_manager"]'):null;
      if(destination){event.preventDefault();destination.click();return;}
    }
    if(event.key==='Escape' && dialog.open){event.preventDefault();dialog.close();return;}
    if((event.ctrlKey||event.metaKey)&&event.key.toLowerCase()==='k'&&!event.altKey&&!event.isComposing){
      event.preventDefault();if(!dialog.open)open();else input.focus();
    }
  });
  input.addEventListener('input',()=>{
    clearTimeout(timer);controller?.abort();
    const q=normalize(input.value);
    if(q.length<3||(/^\d+$/.test(q)&&q.length<6)){
      showMessage('برای جستجو حداقل ۳ حرف یا ۶ رقم وارد کنید.');return;
    }
    showMessage('در حال جستجو…');
    timer=setTimeout(async()=>{
      controller=new AbortController();
      try{
        const response=await fetch('?page=global_search&q='+encodeURIComponent(q),{
          credentials:'same-origin',cache:'no-store',signal:controller.signal
        });
        if(!response.ok)throw new Error('search unavailable');
        const data=await response.json();
        if(normalize(input.value)!==q)return;
        results.replaceChildren();let found=0;
        for(const [key,label] of Object.entries(names)){
          const list=Array.isArray(data[key])?data[key]:[];
          if(list.length===0)continue;
          const section=document.createElement('section');
          const heading=document.createElement('h3');heading.textContent=label;section.append(heading);
          for(const item of list){
            const link=document.createElement('a');
            if(typeof item.url!=='string'||!item.url.startsWith('?page='))continue;
            link.href=item.url;link.className='global-search-result';
            const title=document.createElement('strong');title.textContent=String(item.title||'');
            const detail=document.createElement('small');detail.textContent=String(item.detail||'');
            link.append(title,detail);section.append(link);found++;
          }
          results.append(section);
        }
        if(!found)showMessage('نتیجه‌ای پیدا نشد.');
      }catch(error){
        if(error?.name!=='AbortError')showMessage('جستجو در حال حاضر در دسترس نیست.');
      }
    },300);
  });
  dialog.addEventListener('close',()=>{clearTimeout(timer);controller?.abort();});
});
