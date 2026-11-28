/* Focused, accessible tabs for the unified invoice editor (date/payment).
   The dialog remains one modal: changing tabs never submits financial data. */
document.addEventListener('DOMContentLoaded',()=>{
  document.querySelectorAll('[data-invoice-editor]').forEach(dialog=>{
    const tabs=[...dialog.querySelectorAll('[data-invoice-tab]')];
    const panels=[...dialog.querySelectorAll('[data-invoice-panel]')];
    const tablist=dialog.querySelector('.invoice-editor-tabs');
    if(!tabs.length||!panels.length)return;
    tablist?.setAttribute('role','tablist');
    tabs.forEach(tab=>{
      const type=tab.dataset.invoiceTab;
      const panel=panels.find(p=>p.dataset.invoicePanel===type);
      tab.setAttribute('role','tab');
      if(!panel){tab.hidden=true;return;}
      const panelId=dialog.id+'-panel-'+type;
      const tabId=dialog.id+'-tab-'+type;
      tab.id=tabId;panel.id=panelId;
      tab.setAttribute('aria-controls',panelId);
      panel.setAttribute('role','tabpanel');
      panel.setAttribute('aria-labelledby',tabId);
    });
    const visibleTabs=tabs.filter(t=>!t.hidden);
    const activate=(type,focus=false)=>{
      const selected=visibleTabs.find(t=>t.dataset.invoiceTab===type);
      if(!selected)return;
      tabs.forEach(tab=>{
        const active=tab===selected;
        tab.setAttribute('aria-selected',String(active));
        tab.tabIndex=active?0:-1;
      });
      panels.forEach(panel=>{panel.hidden=panel.dataset.invoicePanel!==type;});
      if(focus)selected.focus({preventScroll:true});
      dialog.querySelector('.invoice-editor-body')?.scrollTo({top:0,behavior:'instant'});
    };
    visibleTabs.forEach((tab,index)=>{
      tab.addEventListener('click',()=>activate(tab.dataset.invoiceTab));
      tab.addEventListener('keydown',event=>{
        let next;
        if(event.key==='ArrowRight')next=(index+visibleTabs.length-1)%visibleTabs.length;
        else if(event.key==='ArrowLeft')next=(index+1)%visibleTabs.length;
        else if(event.key==='Home')next=0;
        else if(event.key==='End')next=visibleTabs.length-1;
        else return;
        event.preventDefault();
        activate(visibleTabs[next].dataset.invoiceTab,true);
      });
    });
    dialog.addEventListener('close',()=>activate('date'));
    activate('date');
  });
});
