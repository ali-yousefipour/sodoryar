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
