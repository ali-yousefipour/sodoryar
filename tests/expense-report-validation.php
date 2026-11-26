<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/localization.php';
require dirname(__DIR__).'/src/invoice_payments.php';
require dirname(__DIR__).'/src/cashbox.php';
require dirname(__DIR__).'/src/expenses_page.php';
require dirname(__DIR__).'/src/expense_report_center.php';

if(count(expense_report_catalog())!==19)throw new RuntimeException('Missing required expense report');
$filter=expense_center_filters(['report'=>'monthly','from'=>'۱۴۰۵/۰۹/۰۱','to'=>'۱۴۰۵/۰۹/۳۰','minimum'=>'۱٬۰۰۰','maximum'=>'۵٬۰۰۰','columns'=>['date','amount']]);
if($filter['from_iso']!=='2026-11-22'||$filter['minimum']!=='1000'||count(expense_center_selected_columns($filter))!==2)throw new RuntimeException('Expense report filter normalization failed');
foreach([['report'=>'unknown'],['from'=>'۱۴۰۵/۱۳/۰۱'],['minimum'=>'100','maximum'=>'99'],['columns'=>['amount; DROP TABLE expenses']],['q'=>['bad']]] as $invalid){
    $blocked=false;try{$f=expense_center_filters($invalid);expense_center_selected_columns($f);}catch(RuntimeException $ex){$blocked=true;}
    if(!$blocked)throw new RuntimeException('Invalid expense report filter accepted');
}
if(expense_report_add(200000,'-50000')!==150000||expense_report_add(0,'60784000')!==60784000)throw new RuntimeException('Exact report arithmetic failed');
$blocked=false;try{expense_report_add(PHP_INT_MAX,'1');}catch(RuntimeException $ex){$blocked=true;}
if(!$blocked)throw new RuntimeException('Report sum overflow accepted');
echo "PASS: 19 expense reports, Jalali filters, column selection, signed exact totals and invalid/overflow rejection\n";
