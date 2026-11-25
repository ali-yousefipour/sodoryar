<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/expense_reports.php';
function ascii_digits(string $text): string {return strtr($text,['۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','۰'=>'0']);}
function parse_money_input(string $text,int $min,int $max): int|false {$text=str_replace(',','',$text);return ctype_digit($text) && (int)$text<=$max?(int)$text:false;}
function expense_scope_sql(int $tenant,string $alias='e'): string {if($tenant!==7)throw new LogicException('Wrong tenant');return ' AND '.$alias.'.created_by=31 AND '.$alias.'.branch_id IN (2)';}
function expense_require_permission(string $permission): void {if($permission!=='view')throw new LogicException('Missing report view guard');}
function query(string $sql,array $params=[]): object {
    if(array_slice($params,0,3)!==[7,7,7] || !str_contains($sql,'e.created_by=31') || !str_contains($sql,'e.branch_id IN (2)'))throw new RuntimeException('Report bypassed tenant or actor scope');
    if(substr_count($sql,'?')!==count($params))throw new RuntimeException('Wrong report bind count');
    return new class {function fetch(): array {return ['document_count'=>61,'total_amount'=>'9999999999999999999','approved_expenses'=>'400','paid_amount'=>'300','cash_paid'=>'100','bank_paid'=>'50','personal_paid'=>'150','employee_due'=>'75','remaining'=>'100'];}function fetchAll(): array {return [];}};
}
function expense_report_assert(bool $value): void {if(!$value)throw new RuntimeException('Expense report regression');}
$filter=expense_report_filters(['branch_id'=>'۲','category_id'=>'3','method'=>'personal','date_from'=>'2026-01-01','date_to'=>'2026-12-31','approval_status'=>'approved','payment_status'=>'partial','bank_account_id'=>'5','cash_register_id'=>'4','report_page'=>'3','amount_min'=>'100','amount_max'=>'1,000']);
expense_report_assert($filter['branch_id']==='2' && $filter['amount_max']==='1000');
[$sql,$params]=expense_report_query(7,$filter);
expense_report_assert(substr_count($sql,'EXISTS')===2 && str_contains($sql,'fp.company_id=e.company_id') && str_contains($sql,'r.company_id=ep.company_id') && str_contains($sql,'pay.paid_amount<e.total_amount') && str_contains($sql,'ep.posted_at IS NOT NULL'));
$result=expense_report_data(7,$filter);expense_report_assert($result['page']===3 && $result['pages']===3 && $result['summary']['total_amount']==='9999999999999999999');
foreach([['branch_id'=>'2 OR 1=1'],['method'=>'pos'],['approval_status'=>'deleted'],['date_from'=>'2026-02-30'],['date_from'=>'2026-12-02','date_to'=>'2026-01-01'],['amount_min'=>'200','amount_max'=>'100'],['category_id'=>['3']]] as $invalid){
    $rejected=false;try{expense_report_filters($invalid);}catch(RuntimeException $ex){$rejected=true;}expense_report_assert($rejected);
}
expense_report_assert(expense_report_exact_add('99999999999999999999','1')==='100000000000000000000');
expense_report_assert(expense_report_exact_add('0','0')==='0');
echo "Expense report filters, tenant/actor scope, exact sums and pagination checks passed\n";
