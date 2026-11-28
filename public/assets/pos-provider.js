document.addEventListener('DOMContentLoaded',()=>{
  const root=document.querySelector('[data-pos-provider]');
  if(!root)return;
  const search=root.querySelector('#posProviderSearch'),select=root.querySelector('#posProviderSelect'),other=root.querySelector('[data-pos-other]'),custom=other.querySelector('input'),status=root.querySelector('[data-pos-provider-status]');
  const normalize=s=>s.toLowerCase().replace(/ي/g,'ی').replace(/ك/g,'ک').replace(/[\s\u200c\u200d\-]/g,'');
  const options=[...select.options].filter(o=>o.value&&o.value!=='other').map(o=>({value:o.value,label:o.textContent}));
  const sync=()=>{const active=select.value==='other';other.hidden=!active;custom.disabled=!active;custom.required=active;};
  search.addEventListener('input',()=>{
    const term=normalize(search.value),previous=select.value, matches=options.filter(o=>normalize(o.label).includes(term));
    select.replaceChildren(new Option('انتخاب شرکت',''),...matches.map(o=>new Option(o.label,o.value)),new Option('سایر','other'));
    select.value=[...select.options].some(o=>o.value===previous)?previous:'';
    status.textContent=matches.length?new Intl.NumberFormat('fa-IR').format(matches.length)+' شرکت یافت شد':'نتیجه‌ای یافت نشد؛ گزینه سایر در دسترس است.';
    sync();
  });
  search.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();select.focus();}});
  select.addEventListener('change',sync);sync();
});
