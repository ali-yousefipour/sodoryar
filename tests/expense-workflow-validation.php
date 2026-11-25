<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/localization.php';
require dirname(__DIR__).'/src/invoice_payments.php';
require dirname(__DIR__).'/src/expense_workflow.php';

foreach([
    ['none',0,500000,'submit','approved'],['all',0,500000,'submit','pending'],
    ['threshold',100000,100000,'submit','approved'],['threshold',100000,100001,'submit','pending'],
    ['branch',0,500000,'submit','pending'],['none',0,500000,'draft','draft'],
] as [$mode,$limit,$amount,$save,$expected]){
    if(expense_approval_status(['approval_mode'=>$mode,'approval_threshold'=>$limit],$amount,$save)!==$expected)throw new RuntimeException('Expense approval policy boundary failed');
}
if(expense_totals(500000,'۲۰٬۰۰۰','۵٬۰۰۰')!==[515000,20000,5000])throw new RuntimeException('Expense extra/discount calculation failed');
foreach([[500000,'0','500000'],[PHP_INT_MAX,'1','0'],[500000,'-1','0'],[500000,'0','1e3']] as $input){
    $blocked=false;try{expense_totals(...$input);}catch(RuntimeException $ex){$blocked=true;}
    if(!$blocked)throw new RuntimeException('Invalid extra/discount or overflow accepted');
}
echo "PASS: expense approval policies, threshold boundary, draft and exact discount/extra arithmetic\n";
