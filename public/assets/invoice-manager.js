document.addEventListener('DOMContentLoaded',()=>{
  const form=document.querySelector('[data-manager-export-form]');
  if(!form)return;
  const grid=form.querySelector('[data-manager-export-columns]');
  const key='sodoryar.invoice-manager.columns.'+form.dataset.tenant+'.v1';
  const items=()=>[...grid.querySelectorAll('[data-export-column]')];
  const save=()=>{
    try{localStorage.setItem(key,JSON.stringify(items().map(el=>({key:el.dataset.exportColumn,checked:el.querySelector('input').checked}))));}catch(_){}
  };
  let saved=[];
  try{const value=JSON.parse(localStorage.getItem(key)||'[]');if(Array.isArray(value))saved=value;}catch(_){}
  if(saved.length){
    const byKey=new Map(items().map(el=>[el.dataset.exportColumn,el]));
    saved.forEach(entry=>{
      if(!entry||!byKey.has(entry.key))return;
      const el=byKey.get(entry.key);el.querySelector('input').checked=Boolean(entry.checked);grid.append(el);byKey.delete(entry.key);
    });
    byKey.forEach(el=>grid.append(el));
  }
  grid.addEventListener('change',save);
  grid.addEventListener('click',event=>{
    const up=event.target.closest('[data-column-up]');
    const down=event.target.closest('[data-column-down]');
    if(!up&&!down)return;
    const row=event.target.closest('[data-export-column]');
    if(up&&row.previousElementSibling)grid.insertBefore(row,row.previousElementSibling);
    if(down&&row.nextElementSibling)grid.insertBefore(row.nextElementSibling,row);
    save();
  });
  form.querySelector('[data-columns-all]')?.addEventListener('click',()=>{
    items().forEach(el=>{el.querySelector('input').checked=true;});save();
  });
  form.querySelector('[data-columns-reset]')?.addEventListener('click',()=>{
    items().sort((a,b)=>{
      const order=['row','number','date','customer','national','mobile','services','amount','paid','remaining','method','operator','status'];
      return order.indexOf(a.dataset.exportColumn)-order.indexOf(b.dataset.exportColumn);
    }).forEach(el=>{el.querySelector('input').checked=true;grid.append(el);});
    save();
  });
  form.addEventListener('submit',event=>{
    if(!items().some(el=>el.querySelector('input').checked)){
      event.preventDefault();const first=items()[0]?.querySelector('input');first?.focus();first?.setCustomValidity('حداقل یک ستون را انتخاب کنید.');first?.reportValidity();return;
    }
    items().forEach(el=>el.querySelector('input').setCustomValidity(''));
    save();
  });
  grid.addEventListener('change',()=>{
    items().forEach(el=>el.querySelector('input').setCustomValidity(''));
  });
});

// Display-column preferences are separate from the Excel export layout.
document.addEventListener('DOMContentLoaded',()=>{
  const section=document.querySelector('.manager-results');
  const table=section?.querySelector('.table-wrap table');
  if(!table)return;
  const headings=[...table.querySelectorAll('thead th')];
  const key='sodoryar.invoice-manager.table-columns.v1';
  const control=document.createElement('details');
  control.className='manager-table-columns';
  const summary=document.createElement('summary');summary.textContent='تنظیم ستون‌های جدول';
  const fields=document.createElement('div');fields.className='manager-table-column-options';
  const store=[];
  let saved=null;try{saved=JSON.parse(localStorage.getItem(key)||'null');}catch(_){}
  const apply=()=>{
    const visible=store.map(x=>x.checked);
    const count=visible.filter(Boolean).length;
    if(count===0){store[0].checked=true;visible[0]=true;}
    headings.forEach((th,index)=>{th.hidden=!visible[index];});
    table.querySelectorAll('tbody tr').forEach(tr=>{
      const tds=[...tr.children];
      if(tds.length!==headings.length)return;
      tds.forEach((td,index)=>td.hidden=!visible[index]);
    });
    try{localStorage.setItem(key,JSON.stringify(visible));}catch(_){}
  };
  headings.forEach((th,index)=>{
    const label=document.createElement('label');
    const checkbox=document.createElement('input');checkbox.type='checkbox';
    checkbox.checked=Array.isArray(saved)&&typeof saved[index]==='boolean'?saved[index]:true;
    checkbox.addEventListener('change',apply);
    store.push(checkbox);label.append(checkbox,document.createTextNode(' '+th.textContent.trim()));fields.append(label);
  });
  const reset=document.createElement('button');reset.type='button';reset.className='secondary small';reset.textContent='نمایش همه ستون‌ها';
  reset.addEventListener('click',()=>{store.forEach(c=>c.checked=true);apply();});
  control.append(summary,fields,reset);
  section.querySelector('.table-wrap').before(control);
  apply();
});
