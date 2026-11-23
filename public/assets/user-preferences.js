/* User-and-company-scoped preferences; authoritative persistence is server-side. */
(()=>{
  const csrf=document.querySelector('meta[name="csrf-token"]')?.content||'';
  let loaded=null;
  const get=async()=>{
    if(loaded)return loaded;
    loaded=(async()=>{
      try{
        const response=await fetch('?page=user_preferences',{credentials:'same-origin',cache:'no-store'});
        if(!response.ok)return {};
        const json=await response.json();
        return json.ready && json.preferences && typeof json.preferences==='object'?json.preferences:{};
      }catch(_error){return {};}
    })();
    return loaded;
  };
  const save=async(key,value)=>{
    if(!csrf)return false;
    try{
      const body=new URLSearchParams({csrf,key,value:JSON.stringify(value)});
      const response=await fetch('?page=user_preferences',{
        method:'POST',body,credentials:'same-origin',
        headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8'}
      });
      if(!response.ok)return false;
      const old=await get();old[key]=value;
      return true;
    }catch(_error){return false;}
  };
  window.sodoryarPrefs={get,save};
  document.addEventListener('DOMContentLoaded',async()=>{
    const favorite=document.querySelector('[data-finance-favorite]');
    const report=favorite?.dataset.financeFavorite;
    if(favorite && report){
      let prefs=await get();
      const favorites=Array.isArray(prefs.report_favorites)?prefs.report_favorites:[];
      const render=()=>{const selected=favorites.includes(report);
        favorite.setAttribute('aria-pressed',String(selected));
        favorite.textContent=selected?'★ حذف از علاقه‌مندی‌ها':'☆ افزودن به علاقه‌مندی‌ها';
      };
      render();
      favorite.addEventListener('click',async()=>{
        const before=[...favorites];
        const next=favorites.includes(report)?favorites.filter(x=>x!==report):[...favorites,report].slice(0,20);
        favorite.disabled=true;
        const ok=await save('report_favorites',next);
        favorite.disabled=false;
        if(ok){favorites.splice(0,favorites.length,...next);render();}
        else {favorites.splice(0,favorites.length,...before);favorite.title='ذخیره تنظیم شخصی نیازمند مهاجرت ۰۲۱ است.';}
      });
    }
  });
})();
