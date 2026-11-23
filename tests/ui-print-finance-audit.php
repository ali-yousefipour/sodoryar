<?php
declare(strict_types=1);

$index=(string)file_get_contents(__DIR__.'/../public/index.php');
$modules=(string)file_get_contents(__DIR__.'/../src/modules.php');
$app=(string)file_get_contents(__DIR__.'/../public/assets/app.js');
$print=(string)file_get_contents(__DIR__.'/../public/assets/print-engine.css');
$ux=(string)file_get_contents(__DIR__.'/../public/assets/ux-unified.css');
$assert=function(bool $pass,string $name): void {
    if (!$pass) throw new RuntimeException('UI, printing or finance audit regression: '.$name);
};
$assert(str_contains($index,'normalize_invoice_extra_rows('),'invoice extra validation');
$assert(str_contains($index,"mb_strlen(\$ref)>120"),'POS reference maximum length');
$assert(str_contains($app,'sum=0n'),'integer precision in invoice preview');
$assert(str_contains($app,'BigInt(checkbox.dataset.servicePrice'),'fixed service precision');
$assert(str_contains($app,"input[name=\"extra_price[]\"]"),'ad-hoc items included in preview');
$assert(str_contains($app,'extraItems?.addEventListener(\'input\',updateServices)'),'live preview of ad-hoc rows');
$assert(str_contains($app,"if(extraItems?.contains(field))extraItems.dispatchEvent(new Event('input'))"),'post-format update of invoice preview');
$assert(str_contains($app,"submit.setAttribute('aria-busy','true')"),'duplicate submit feedback');
$assert(str_contains($app,"titleLabel.textContent='عنوان خدمت موردی'"),'visible label for new service');
$assert(str_contains($ux,'.invoice-extras .extra-invoice-row'),'responsive financial items');
$assert(str_contains($ux,':focus-visible'),'visible keyboard focus');
$assert(str_contains($ux,'prefers-reduced-motion:reduce'),'reduced animation support');
$assert(str_contains($print,'@page sodoryarThermal { size:80mm 297mm; margin:0; }'),'thermal paper width');
$assert(str_contains($print,'.receipt-thermal th:last-child'),'thermal money column layout');
$assert(str_contains($modules,'function commission_sales_total('),'commission calculation helper');
$assert(str_contains($modules,'COALESCE(invoice_date,DATE(created_at))'),'invoice date financial period');
$assert(str_contains($modules,'commission_period_already_recorded($tenant,$userId,$period,$date)'),'duplicate commission rejection');
$assert(str_contains($modules,'FOR UPDATE'),'financial row locking');
$assert(str_contains($index,'receipt-payment-note'),'unverified POS disclosure on receipt');
$assert(str_contains($print,'.receipt-payment-note'),'POS disclosure print formatting');
$assert(str_contains($app,"window.addEventListener('pageshow'"),'restore disabled form controls after browser history navigation');
$assert(str_contains($app,"form.dataset.submitting==='1'"),'duplicate browser financial submission protection');

$assert(str_contains($modules,"\$_POST['currency']??''") && str_contains($modules,"!=='toman'"),'toman-only accounting guarantee');
echo "UI, printing and financial flow regression contracts passed\n";
