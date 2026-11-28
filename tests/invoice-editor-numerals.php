<?php
declare(strict_types=1);
/** Structural protection for the single compact invoice editor and numeral policy. */
$index=file_get_contents(__DIR__.'/../public/index.php');
$css=file_get_contents(__DIR__.'/../public/assets/invoice-redesign.css');
$tabScript=file_get_contents(__DIR__.'/../public/assets/invoice-editor-compact.js');
$digits=file_get_contents(__DIR__.'/../public/assets/persian-numerals.js');
$start=strpos($index,'function render_invoice_edit_dialog(');
$end=strpos($index,'function render_invoices(',$start===false?0:$start);
if($start===false||$end===false||$end<=$start)throw new RuntimeException('Invoice editor renderer missing.');
$renderer=substr($index,$start,$end-$start);
if(substr_count($renderer,"'<dialog")!==1
   ||!str_contains($renderer,'invoice-editor-compact')
   ||!str_contains($renderer,'data-invoice-tab="date"')
   ||!str_contains($renderer,'data-invoice-tab="payment"')
   ||!str_contains($renderer,'data-invoice-panel="date"')
   ||!str_contains($renderer,'data-invoice-panel="payment"')
   ||!str_contains($renderer,'render_invoice_payment_replace_dialog')
   ||!str_contains($css,'max-height:calc(100dvh - 30px)')
   ||!str_contains($css,'.invoice-editor-body{flex:1 1 auto')
   ||!str_contains($tabScript,"setAttribute('role','tablist')")
   ||!str_contains($tabScript,"panel.hidden=panel.dataset.invoicePanel!==type")) {
    throw new RuntimeException('Compact invoice tab/modal contract failed.');
}
if(!str_contains($digits,'MutationObserver')
   ||!str_contains($digits,"NodeFilter.SHOW_TEXT")
   ||!str_contains($digits,"element.hasAttribute('pattern')")
   ||!str_contains($digits,"element.dataset.moneyInput!==undefined")) {
    throw new RuntimeException('Numeral localization missed dynamic text or safe input boundaries.');
}
if(!str_contains($index,'assets/invoice-editor-compact.js')
   ||!str_contains($index,'assets/persian-numerals.js')) {
    throw new RuntimeException('Global numeral and editor assets not loaded.');
}
echo "Compact invoice editor and Persian numeral safety contracts passed.\n";
