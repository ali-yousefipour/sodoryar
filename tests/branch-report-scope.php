<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/reporting.php';
$role='operator';
function user(): array {global $role;return ['id'=>19,'role'=>$role];}
function branches_ready(): bool {return true;}
function query(string $sql,array $params=[]): object {
    if($params[0]!==7)throw new LogicException('Tenant scope missing');
    if(str_contains($sql,'user_branch_assignments') && ($params[1]??0)!==19)throw new LogicException('Actor scope missing');
    return new class {function fetchAll(): array {return [['id'=>3,'name'=>'A'],['id'=>5,'name'=>'B']];}};
}
function check(bool $ok): void {if(!$ok)throw new RuntimeException('Branch report scope failed');}
check(report_branch_sql(7,'i.branch_id','3')===' AND i.branch_id=3');
check(report_branch_sql(7,'p.branch_id','')===' AND p.branch_id IN (3,5)');
foreach(['9','3 OR 1=1','-1','0'] as $invalid){
    $rejected=false;try {report_branch_sql(7,'i.branch_id',$invalid);}catch(RuntimeException $ex){$rejected=true;}check($rejected);
}
$role='company_admin';check(report_branch_sql(7,'i.branch_id','')==='');
check(array_key_exists('branch_id',report_filters_default()));
$source=file_get_contents(dirname(__DIR__).'/src/reporting.php');
$receipts=substr($source,strpos($source,'function invoice_payment_report'),strpos($source,'function financial_control_exceptions')-strpos($source,'function invoice_payment_report'));
check(str_contains($receipts,"report_branch_sql(\$tenant,'p.branch_id'"));
check(str_contains($receipts,"AND p.created_by=?"));
echo "Branch report tenant, membership, selection and receipt ownership checks passed\n";
