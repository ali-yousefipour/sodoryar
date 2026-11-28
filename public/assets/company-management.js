/* Company management: Solar Hijri subscription terms and accessible filters.
   ISO dates remain transport-only; the backend recalculates expiry authoritatively. */
document.addEventListener('DOMContentLoaded',()=>{
  const root=document.querySelector('[data-company-console]');
  if(!root)return;
  const digits='۰۱۲۳۴۵۶۷۸۹';
  const fa=value=>String(value).replace(/[0-9]/g,d=>digits[Number(d)]);
  const calendar=new Intl.DateTimeFormat('en-US-u-ca-persian',{
    timeZone:'UTC',year:'numeric',month:'2-digit',day:'2-digit'
  });
  const parts=iso=>{
    if(!/^\d{4}-\d{2}-\d{2}$/.test(String(iso)))return null;
    const date=new Date(iso+'T12:00:00Z');
    if(!Number.isFinite(date.getTime())||date.toISOString().slice(0,10)!==iso)return null;
    const values=Object.fromEntries(calendar.formatToParts(date)
      .filter(x=>['year','month','day'].includes(x.type))
      .map(x=>[x.type,Number(x.value)]));
    return [values.year,values.month,values.day];
  };
  const jalaliIso=(year,month,day)=>{
    const goal=year*10000+month*100+day;
    let low=Math.floor(Date.UTC(year+620,0,1)/86400000);
    let high=Math.floor(Date.UTC(year+623,0,1)/86400000);
    while(low<=high){
      const mid=Math.floor((low+high)/2),iso=new Date(mid*86400000).toISOString().slice(0,10);
      const p=parts(iso),v=p[0]*10000+p[1]*100+p[2];
      if(v===goal)return iso;
      if(v<goal)low=mid+1;else high=mid-1;
    }
    return null;
  };
  const periodEnd=(iso,months)=>{
    const p=parts(iso);
    if(!p||!Number.isInteger(months)||months<1||months>120)return null;
    const idx=p[0]*12+p[1]-1+months;
    const year=Math.floor(idx/12),month=idx%12+1;
    let next=null;
    for(let d=p[2];d>=1;d--){
      next=jalaliIso(year,month,d);
      if(next)break;
    }
    if(!next)return null;
    return new Date(Date.parse(next+'T00:00:00Z')-86400000).toISOString().slice(0,10);
  };
  const format=iso=>{
    const p=parts(iso);
    return p?fa(p[0]+'/'+String(p[1]).padStart(2,'0')+'/'+String(p[2]).padStart(2,'0')):'—';
  };
  root.querySelectorAll('form[data-subscription-period]').forEach(form=>{
    const start=form.querySelector('[data-subscription-start]');
    const months=form.querySelector('[data-subscription-months]');
    const end=form.querySelector('[data-subscription-end-iso]');
    const output=form.querySelector('[data-subscription-end-preview]');
    const validate=()=>{
      const val=periodEnd(start?.value,Number(months?.value));
      if(end)end.value=val||'';
      if(output)output.textContent=val?format(val):'تاریخ شروع و تعداد ماه را بررسی کنید';
      return !!val;
    };
    start?.addEventListener('change',validate);
    months?.addEventListener('input',validate);
    form.addEventListener('submit',event=>{
      if(validate())return;
      event.preventDefault();
      output?.setAttribute('role','alert');
      months?.focus();
    });
    validate();
  });
  const search=root.querySelector('[data-company-search]');
  const status=root.querySelector('[data-company-status]');
  const counter=root.querySelector('[data-company-result-count]');
  const rows=[...root.querySelectorAll('[data-company-row]')];
  const apply=()=>{
    const query=(search?.value||'').trim().toLocaleLowerCase('fa-IR');
    const filter=status?.value||'all';
    let visible=0;
    rows.forEach(row=>{
      const cells=row.querySelectorAll('td');
      const matchText=([cells[0]?.textContent,cells[1]?.textContent].join(' '))
        .toLocaleLowerCase('fa-IR').includes(query);
      const show=matchText&&(filter==='all'||row.dataset.companyState===filter);
      row.hidden=!show;
      if(show)visible++;
    });
    if(counter)counter.textContent=fa(visible)+' شرکت نمایش داده می‌شود';
  };
  search?.addEventListener('input',apply);
  status?.addEventListener('change',apply);
  apply();
});
