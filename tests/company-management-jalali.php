<?php
declare(strict_types=1);
/** Company management UI and Jalali term safety regression. */
$index=(string)file_get_contents(__DIR__.'/../public/index.php');
$start=strpos($index,"<?php elseif(\$page==='companies'): ?>");
$end=strpos($index,"<?php elseif(\$page==='dashboard'): ?>",$start===false?0:$start);
if($start===false||$end===false||$end<=$start)throw new RuntimeException('Company page not found.');
$html=substr($index,$start,$end-$start);
if(!str_contains($html,'data-company-console')||
   !str_contains($html,'data-subscription-period')||
   !str_contains($html,'data-jalali-open')||
   !str_contains($html,'data-jalali-iso')||
   !str_contains($html,'data-subscription-end-preview')||
   !str_contains($html,'data-subscription-total')||
   !str_contains($html,'data-subscription-balance')||
   !str_contains($html,'data-company-search')||
   !str_contains($html,'data-company-status')||
   str_contains($html,'type="date"')||
   !str_contains($html,'jalali_datetime($sub[')||
   !str_contains($html,"jalali_datetime((string)(\$snapshot['start_date']")) {
    throw new RuntimeException('Companies page must render Jalali dates and accessible grouped controls.');
}
if(!str_contains($index,'sodoryar_subscription_jalali_end($start,(int)$months)')||
   !str_contains($index,'assets/company-management.css')||
   !str_contains($index,'assets/company-management.js')) {
    throw new RuntimeException('Jalali subscription server authority or page assets missing.');
}
$script=(string)file_get_contents(__DIR__.'/../public/assets/company-management.js');
if(!str_contains($script,'data-subscription-start')||
   !str_contains($script,'data-subscription-months')||
   !str_contains($script,'jalaliIso')||
   !str_contains($script,'showMoney')||
   !str_contains($script,"periodEnd(start?.value")) {
    throw new RuntimeException('Jalali term preview and field interaction missing.');
}
echo "Companies UI, Jalali picker and subscription term contracts passed.\n";
