document.addEventListener('DOMContentLoaded',()=>{
  const type=document.getElementById('paymentType');
  const ref=document.getElementById('posRef');
  if(type&&ref){const update=()=>{ref.required=type.value==='pos';ref.closest('label').hidden=type.value!=='pos';};type.addEventListener('change',update);update();}
  document.querySelectorAll('[data-print-receipt]').forEach(button=>button.addEventListener('click',()=>window.print()));
});
