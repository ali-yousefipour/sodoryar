<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/reporting.php';
function database_table_exists(string $table): bool {return true;}
function cashbox_ready(): bool {return true;}
[$sql,$params]=report_payment_resources(['terminal_id'=>'12','bank_id'=>'8','cash_register_id'=>'5']);
if($params!==[12,8,5] || substr_count($sql,'EXISTS')!==2 || !str_contains($sql,'pr.company_id=p.company_id') || !str_contains($sql,'cs.company_id=p.company_id'))throw new RuntimeException('Receipt resource scoping failed');
foreach(['0','-1','8 OR 1=1'] as $invalid){
    $rejected=false;try{report_payment_resources(['terminal_id'=>$invalid]);}catch(RuntimeException $ex){$rejected=true;}
    if(!$rejected)throw new RuntimeException('Unsafe resource filter accepted');
}
[$sql,$params]=report_payment_resources(report_filters_default());
if($sql!=='' || $params!==[])throw new RuntimeException('Missing filters changed report');
echo "Receipt resource parameter and tenant correlation checks passed\n";
