/* Persian digits in user-facing numbers, without changing machine identifiers,
   numerical HTML input validity, stored values, or network serialization.
   Covers server-rendered and dynamically appended text. */
document.addEventListener('DOMContentLoaded',()=>{
  const fa='۰۱۲۳۴۵۶۷۸۹';
  const digits=value=>String(value).replace(/[0-9]/g,c=>fa[c.charCodeAt(0)-48])
    .replace(/[٠-٩]/g,c=>fa[c.charCodeAt(0)-1632]);
  const excluded='script,style,code,pre,[contenteditable],[data-keep-ascii],[data-no-persian-digits]';
  const updateText=node=>{
    if(!node||node.nodeType!==Node.TEXT_NODE || !/[0-9٠-٩]/.test(node.nodeValue||''))return;
    if(node.parentElement?.closest(excluded))return;
    node.nodeValue=digits(node.nodeValue);
  };
  const scan=node=>{
    if(!node)return;
    if(node.nodeType===Node.TEXT_NODE){updateText(node);return;}
    if(node.nodeType!==Node.ELEMENT_NODE||node.matches(excluded))return;
    const walker=document.createTreeWalker(node,NodeFilter.SHOW_TEXT);
    let text;
    while((text=walker.nextNode()))updateText(text);
    if(node.matches('input[placeholder],textarea[placeholder]') && node.placeholder){
      node.placeholder=digits(node.placeholder);
    }
    node.querySelectorAll?.('input[placeholder],textarea[placeholder]').forEach(el=>{
      if(el.placeholder)el.placeholder=digits(el.placeholder);
    });
  };
  scan(document.body);
  // Added DOM fragments may include financial tables, notifications and modals.
  const observer=new MutationObserver(records=>{
    for(const record of records){
      if(record.type==='characterData')updateText(record.target);
      else record.addedNodes.forEach(scan);
    }
  });
  observer.observe(document.body,{subtree:true,childList:true,characterData:true});

  // Only digit-oriented text fields are changed in-place. Native type=number,
  // password, email, slug/username fields and ASCII-validated patterns remain
  // untouched to avoid corrupting application validation and login credentials.
  const isSafeInput=element=>{
    if(!(element instanceof HTMLInputElement))return false;
    if(!['text','tel','search'].includes(element.type))return false;
    if(element.matches('[data-keep-ascii],[data-no-persian-digits],[readonly]'))return false;
    if(element.hasAttribute('pattern') && /0-9|\\\\d|a-z|A-Z/.test(element.pattern))return false;
    if(element.name && /(?:username|password|slug|csrf|token|nonce|api_key|email|url)/i.test(element.name))return false;
    if(element.dataset.moneyInput!==undefined)return false; // existing money formatter is authoritative
    const mode=element.inputMode;
    const name=element.name||'';
    return /^(numeric|decimal|tel)$/.test(mode)||
      /(?:amount|price|national|mobile|phone|postal|tracking|reference|plate|card_number|terminal_number)/i.test(name);
  };
  const localizeInput=element=>{
    if(!isSafeInput(element)||element.dataset.persianComposing==='1')return;
    const before=element.value,after=digits(before);
    if(after===before)return;
    const start=element.selectionStart,end=element.selectionEnd;
    element.value=after;
    if(start!==null&&end!==null)element.setSelectionRange(start,end);
  };
  document.querySelectorAll('input').forEach(localizeInput);
  document.addEventListener('compositionstart',event=>{
    if(isSafeInput(event.target))event.target.dataset.persianComposing='1';
  },true);
  document.addEventListener('compositionend',event=>{
    if(isSafeInput(event.target)){delete event.target.dataset.persianComposing;localizeInput(event.target);}
  },true);
  document.addEventListener('input',event=>localizeInput(event.target),true);
});
