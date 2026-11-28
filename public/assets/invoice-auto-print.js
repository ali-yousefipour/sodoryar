window.addEventListener('load',()=>{if(new URLSearchParams(location.search).get('auto_print')==='1'&&document.querySelector('[data-print-receipt]'))window.print();});
