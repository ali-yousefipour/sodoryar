<?php
declare(strict_types=1);
// This prerequisite refuses any database except the disposable localhost test schema.
require __DIR__.'/mysql-tenant-smoke.php';
require $root.'/src/branches.php';
require $root.'/src/expenses.php';
require $root.'/src/expense_workflow.php';
require $root.'/src/expense_settlement.php';
require $root.'/src/expense_returns.php';
require $root.'/src/expenses_page.php';
require $root.'/src/expense_financial_forms.php';
require $root.'/src/expense_reports.php';
require $root.'/src/expense_report_center.php';
foreach(range(1,2) as $pass)foreach(range(23,29) as $version){
    $files=glob($root.'/database/migrations/'.sprintf('%03d',$version).'_*.sql');
    integration_assert(count($files)===1,'Missing or ambiguous expense migration '.$version);
    apply_sql_fixture($files[0]);
}
integration_assert(expenses_ready()&&expense_extensions_ready()&&expense_returns_ready(),'Expense schema not ready');
integration_assert(count(array_filter(sodoryar_finance_schema_checks(),static fn($check)=>$check['installed']))===14,'Finance health did not detect all migrated expense stages');
query("UPDATE users SET role='company_admin' WHERE company_id=? AND id=?",[$companyA,$userA]);
$_SESSION['user']=['id'=>$userA,'company_id'=>$companyA,'full_name'=>'CI expense manager','role'=>'company_admin'];
$branch=(int)query('SELECT id FROM branches WHERE company_id=? AND is_default=1',[$companyA])->fetchColumn();
$category=(int)query("SELECT id FROM expense_categories WHERE company_id=? AND classification='expense' LIMIT 1",[$companyA])->fetchColumn();
$register=insert_fixture('INSERT INTO cash_registers(company_id,branch_id,name) VALUES(?,?,?)',[$companyA,$branch,'CI expenses isolated']);
$shift=insert_fixture("INSERT INTO cash_shifts(company_id,register_id,operator_id,opening_cash,started_at) VALUES(?,?,?,?,NOW())",[$companyA,$register,$userA,2000000]);
$salesBefore=(string)query('SELECT SUM(total_amount) FROM invoices WHERE company_id=?',[$companyA])->fetchColumn();
function expense_expect_blocked(callable $action,string $message): void {
    try{$action();}catch(RuntimeException $ex){return;}
    throw new RuntimeException($message);
}
function expense_test_balance(int $tenant,int $shift): int {
    return cashbox_expected_cash($tenant,$shift);
}
function expense_test_input(int $branch,int $category,string $method,?int $source,int $amount): array {
    return ['branch_id'=>$branch,'category_id'=>$category,'expense_date'=>jalali_datetime(date('Y-m-d'),true),'title'=>'خرید چای آزمایشی','notes'=>'isolated fixture','request_key'=>bin2hex(random_bytes(32)),
        'items'=>[['title'=>'چای','quantity'=>'۱','unit'=>'عدد','price'=>(string)$amount]],
        'payments'=>[['method'=>$method,'amount'=>(string)$amount,'source'=>$source,'tracking'=>'CI']]];
}
$input=expense_test_input($branch,$category,'cash',$shift,500000);
$expense=expense_create($input);
integration_assert(expense_create($input)===$expense,'Create retry duplicated document');
integration_assert(expense_test_balance($companyA,$shift)===2000000,'Pending expense posted cash');
expense_review($expense,'approved','CI approved');
integration_assert(expense_test_balance($companyA,$shift)===1500000,'2,000,000 minus 500,000 cash reconciliation failed');
expense_expect_blocked(fn()=>expense_review($expense,'approved','CI repeated'),'Repeated approval accepted');
expense_expect_blocked(fn()=>expense_void($expense,'CI administrative void'),'Administrative void fabricated a cash refund');
$payment=(int)query('SELECT id FROM expense_payments WHERE company_id=? AND expense_id=?',[$companyA,$expense])->fetchColumn();
$refund=['kind'=>'payment','target'=>$payment,'amount'=>'200000','cash_shift_id'=>$shift,'reference'=>'CI refund receipt','reason'=>'CI actual refund received','refund_confirmed'=>'1','request_key'=>bin2hex(random_bytes(32))];
expense_expect_blocked(fn()=>expense_return($expense,array_replace($refund,['refund_confirmed'=>'0'])),'Unconfirmed refund accepted');
expense_return($expense,$refund);expense_return($expense,$refund);
integration_assert(expense_test_balance($companyA,$shift)===1700000,'Partial refund or retry posted incorrectly');
expense_expect_blocked(fn()=>expense_return($expense,array_replace($refund,['request_key'=>bin2hex(random_bytes(32))])),'Same receipt with new request key accepted');
expense_expect_blocked(fn()=>expense_return($expense,array_replace($refund,['amount'=>'1'])),'Changed retry accepted');
expense_expect_blocked(fn()=>expense_return($expense,array_replace($refund,['request_key'=>bin2hex(random_bytes(32)),'amount'=>'300001'])),'Excess refund accepted');
// The original closed shift is immutable; actual receipts enter a new open shift.
query("UPDATE cash_shifts SET status='approved',closed_at=NOW() WHERE company_id=? AND id=?",[$companyA,$shift]);
$nextShift=insert_fixture("INSERT INTO cash_shifts(company_id,register_id,operator_id,opening_cash,started_at) VALUES(?,?,?,?,NOW())",[$companyA,$register,$userA,0]);
expense_return($expense,array_replace($refund,['amount'=>'300000','cash_shift_id'=>$nextShift,'reference'=>'CI second cash receipt','request_key'=>bin2hex(random_bytes(32))]));
integration_assert(expense_test_balance($companyA,$nextShift)===300000,'Closed-shift refund did not reach new owned shift');
$inflows=(string)query("SELECT SUM(amount) FROM cash_shift_adjustments WHERE company_id=? AND direction='in'",[$companyA])->fetchColumn();
expense_void($expense,'CI all payments returned');
integration_assert((string)query("SELECT SUM(amount) FROM cash_shift_adjustments WHERE company_id=? AND direction='in'",[$companyA])->fetchColumn()===$inflows,'Void posted refund twice');
$personal=expense_create(expense_test_input($branch,$category,'personal',null,200000));expense_review($personal,'approved','CI personal');
integration_assert(expense_employee_due($companyA,$personal)===200000,'Personal expense failed to create liability');
$settle=['amount'=>'150000','method'=>'cash','source'=>$nextShift,'payment_confirmed'=>'1','request_key'=>bin2hex(random_bytes(32))];
expense_settle($personal,$settle);expense_settle($personal,$settle);
integration_assert(expense_employee_due($companyA,$personal)===50000,'Partial settlement or retry liability incorrect');
expense_expect_blocked(fn()=>expense_settle($personal,array_replace($settle,['amount'=>'50001','request_key'=>bin2hex(random_bytes(32))])),'Over-settlement accepted');
$settlement=(int)query('SELECT id FROM expense_settlements WHERE company_id=? AND expense_id=?',[$companyA,$personal])->fetchColumn();
$settlementRefund=array_replace($refund,['kind'=>'settlement','target'=>$settlement,'amount'=>'50000','cash_shift_id'=>$nextShift,'request_key'=>bin2hex(random_bytes(32))]);
expense_return($personal,$settlementRefund);expense_return($personal,$settlementRefund);
integration_assert(expense_employee_due($companyA,$personal)===100000,'Partial settlement return did not restore liability exactly once');
expense_expect_blocked(fn()=>expense_void($personal,'CI still settled'),'Outstanding employee settlement could be voided');
expense_return($personal,array_replace($settlementRefund,['amount'=>'100000','reference'=>'CI final settlement receipt','request_key'=>bin2hex(random_bytes(32))]));
integration_assert(expense_employee_due($companyA,$personal)===200000,'Full settlement return liability incorrect');
expense_void($personal,'CI full settlement returned');
$bank=insert_fixture('INSERT INTO banks(name,bank_code) VALUES(?,?)',['CI Bank','CI-EXPENSE']);
$account=insert_fixture('INSERT INTO company_bank_accounts(company_id,branch_id,bank_id,title) VALUES(?,?,?,?)',[$companyA,$branch,$bank,'CI expense account']);
$bankExpense=expense_create(expense_test_input($branch,$category,'bank',$account,100000));expense_review($bankExpense,'approved','CI bank');
$bankPayment=(int)query('SELECT id FROM expense_payments WHERE company_id=? AND expense_id=?',[$companyA,$bankExpense])->fetchColumn();
expense_return($bankExpense,array_replace($refund,['target'=>$bankPayment,'amount'=>'100000','cash_shift_id'=>null,'request_key'=>bin2hex(random_bytes(32))]));
integration_assert((int)query('SELECT account_id FROM expense_returns WHERE company_id=? AND payment_id=?',[$companyA,$bankPayment])->fetchColumn()===$account,'Bank refund lost original account');
expense_void($bankExpense,'CI bank refund received');
$_SESSION['user']['company_id']=$companyB;$_SESSION['user']['id']=$userB;
expense_expect_blocked(fn()=>expense_return($expense,$refund),'Cross-company refund accepted');
$_SESSION['user']=['id'=>$userA,'company_id'=>$companyA,'full_name'=>'CI expense manager','role'=>'company_admin'];
integration_assert((string)query('SELECT SUM(total_amount) FROM invoices WHERE company_id=?',[$companyA])->fetchColumn()===$salesBefore,'Expense operations changed invoice sales');
$draftInput=expense_test_input($branch,$category,'personal',null,100000);
$draftInput=array_replace($draftInput,['mode'=>'draft','extra_amount'=>'20000','discount_amount'=>'5000']);
$draftId=expense_create($draftInput);
integration_assert((string)query('SELECT approval_status FROM expenses WHERE id=?',[$draftId])->fetchColumn()==='draft','Draft was not saved');
integration_assert(!query('SELECT id FROM expense_payments WHERE expense_id=? AND posted_at IS NOT NULL',[$draftId])->fetch(),'Draft posted a payment');
$editInput=array_replace($draftInput,['id'=>$draftId,'version'=>1,'title'=>'CI edited draft','request_key'=>bin2hex(random_bytes(32))]);
integration_assert(expense_create($editInput)===$draftId&&expense_create($editInput)===$draftId,'Draft edit/retry created another document');
expense_expect_blocked(fn()=>expense_create(array_replace($editInput,['request_key'=>bin2hex(random_bytes(32))])),'Stale draft version accepted');
$submitInput=array_replace($editInput,['mode'=>'submit','version'=>2,'request_key'=>bin2hex(random_bytes(32))]);$submitInput['payments'][0]['amount']='115000';
expense_create($submitInput);expense_review($draftId,'rejected','CI return to author');
$resubmit=array_replace($submitInput,['version'=>(int)query('SELECT version FROM expenses WHERE id=?',[$draftId])->fetchColumn(),'request_key'=>bin2hex(random_bytes(32))]);
expense_create($resubmit);expense_review($draftId,'approved','CI corrected and approved');
expense_expect_blocked(fn()=>expense_create(array_replace($resubmit,['version'=>(int)query('SELECT version FROM expenses WHERE id=?',[$draftId])->fetchColumn(),'request_key'=>bin2hex(random_bytes(32))])),'Approved document was editable');
expense_policy_save($companyA,['approval_mode'=>'none','approval_threshold'=>'0']);
$automatic=expense_create(expense_test_input($branch,$category,'personal',null,20000));
integration_assert((string)query('SELECT approval_status FROM expenses WHERE id=?',[$automatic])->fetchColumn()==='approved','No-approval policy did not finalize');
integration_assert((bool)query('SELECT posted_at FROM expense_payments WHERE expense_id=?',[$automatic])->fetchColumn(),'Automatic approval did not post payment');
expense_policy_save($companyA,['approval_mode'=>'threshold','approval_threshold'=>'100000']);
$atThreshold=expense_create(expense_test_input($branch,$category,'personal',null,100000));
$aboveThreshold=expense_create(expense_test_input($branch,$category,'personal',null,100001));
integration_assert((string)query('SELECT approval_status FROM expenses WHERE id=?',[$atThreshold])->fetchColumn()==='approved','Threshold equality not approved');
integration_assert((string)query('SELECT approval_status FROM expenses WHERE id=?',[$aboveThreshold])->fetchColumn()==='pending','Above-threshold expense bypassed review');
expense_policy_save($companyA,['approval_mode'=>'branch','approval_threshold'=>'0']);
expense_branch_approver_save($companyA,['branch_id'=>$branch,'user_id'=>$otherUserA,'approval_limit'=>'100000']);
$_SESSION['user']=['id'=>$otherUserA,'company_id'=>$companyA,'full_name'=>'CI Branch reviewer','role'=>'operator'];
$small=query('SELECT * FROM expenses WHERE id=?',[$atThreshold])->fetch();$large=query('SELECT * FROM expenses WHERE id=?',[$aboveThreshold])->fetch();
integration_assert(expense_can_review($small)&&!expense_can_review($large),'Branch approver ceiling was not enforced');
integration_assert(!expense_can_review(array_replace($small,['created_by'=>$otherUserA])),'Branch approver can approve own expense');
query('UPDATE user_branch_assignments SET is_active=0 WHERE company_id=? AND user_id=? AND branch_id=?',[$companyA,$otherUserA,$branch]);
integration_assert(!expense_can_review($small),'Revoked branch assignment retained approval');
query('UPDATE user_branch_assignments SET is_active=1 WHERE company_id=? AND user_id=? AND branch_id=?',[$companyA,$otherUserA,$branch]);
$_SESSION['user']=['id'=>$userA,'company_id'=>$companyA,'full_name'=>'CI expense manager','role'=>'company_admin'];
expense_policy_save($companyA,['approval_mode'=>'all','approval_threshold'=>'0']);
integration_assert((string)query('SELECT SUM(total_amount) FROM invoices WHERE company_id=?',[$companyA])->fetchColumn()===$salesBefore,'Workflow operations changed invoice sales');
$expectedCost=(int)query("SELECT COALESCE(SUM(total_amount),0) FROM expenses WHERE company_id=? AND approval_status='approved' AND classification='expense'",[$companyA])->fetchColumn();
$mixedInput=expense_test_input($branch,$category,'personal',null,100000);
$mixedInput['items']=[['title'=>'قلم اول','quantity'=>'۱','unit'=>'عدد','price'=>'40000'],['title'=>'قلم دوم','quantity'=>'۲','unit'=>'عدد','price'=>'30000']];
$mixedInput['payments']=[['method'=>'cash','amount'=>'50000','source'=>$nextShift,'tracking'=>''],['method'=>'bank','amount'=>'25000','source'=>$account,'tracking'=>'CI mixed bank'],['method'=>'personal','amount'=>'25000','source'=>null,'tracking'=>'']];
$balanceBefore=expense_test_balance($companyA,$nextShift);
$mixed=expense_create($mixedInput);expense_review($mixed,'approved','CI mixed approval');
integration_assert(expense_test_balance($companyA,$nextShift)===$balanceBefore-50000&&expense_employee_due($companyA,$mixed)===25000,'Mixed payment allocation changed cash or employee liability incorrectly');
expense_expect_blocked(fn()=>expense_void($mixed,'CI mixed missing receipt'),'Mixed payment void fabricated a receipt');
foreach(query("SELECT id,method,amount FROM expense_payments WHERE company_id=? AND expense_id=? AND method IN ('cash','bank') ORDER BY id",[$companyA,$mixed])->fetchAll() as $mixedPayment){
    expense_return($mixed,['kind'=>'payment','target'=>(int)$mixedPayment['id'],'amount'=>(string)$mixedPayment['amount'],'cash_shift_id'=>$mixedPayment['method']==='cash'?$nextShift:null,'reference'=>'CI mixed receipt '.$mixedPayment['id'],'reason'=>'CI mixed actual return','refund_confirmed'=>'1','request_key'=>bin2hex(random_bytes(32))]);
}
expense_void($mixed,'CI mixed fully returned');
integration_assert(expense_test_balance($companyA,$nextShift)===$balanceBefore,'Mixed payment return and void duplicated or lost cash');
integration_assert((string)query('SELECT SUM(total_amount) FROM invoices WHERE company_id=?',[$companyA])->fetchColumn()===$salesBefore,'Mixed expense changed sales');
echo "PASS: multiple items, cash/bank/personal mixed allocation and cancellation only after actual cash/bank receipts.\n";
foreach(array_keys(expense_report_catalog()) as $reportType){
    $reportFilter=expense_center_filters(['report'=>$reportType]);$reportRows=expense_center_rows($companyA,$reportFilter);
    $reportTotals=expense_center_totals($reportRows,expense_center_columns($reportType));
    if(in_array($reportType,['daily','monthly','branches','categories','users','comparison','performance'],true))integration_assert(($reportTotals['amount']??0)===$expectedCost,'Grouped report disagrees with operating cost: '.$reportType);
    if($reportType==='movements')integration_assert(($reportTotals['amount']??0)===0,'Cash/bank outflows and actual refunds do not reconcile');
}
$dueReport=expense_center_rows($companyA,expense_center_filters(['report'=>'liabilities']));
integration_assert(count($dueReport)>0&&array_sum(array_column($dueReport,'amount'))===$expectedCost,'Employee liability report disagrees with approved personal expenses');
echo "PASS: all 19 report queries, exact grouped cost/employee liabilities and reconciled real cash/bank movements.\n";
echo "PASS: draft/edit/version/retry/resubmit, automatic/threshold policies, exact discount/extra and branch review ceilings.\n";
echo "PASS: expense migrations twice, cash reconciliation, refunds, employee liability, retries, closed shifts, bank account and tenant boundaries.\n";

// Synthetic turnover above PHP_INT_MAX must net exactly, without floating point.
db()->beginTransaction();
try {
    $hugeShift=insert_fixture("INSERT INTO cash_shifts(company_id,register_id,operator_id,opening_cash,started_at) VALUES(?,?,?,?,NOW())",[$companyA,$register,$userA,17]);
    foreach(['in','in','out','out'] as $direction) {
        query('INSERT INTO cash_shift_adjustments(company_id,shift_id,direction,amount,reason,created_by) VALUES(?,?,?,?,?,?)',[$companyA,$hugeShift,$direction,(string)PHP_INT_MAX,'CI integer boundary',$userA]);
    }
    integration_assert(cashbox_expected_cash($companyA,$hugeShift)===17,'Large offsetting cash turnover lost precision');
    integration_assert(cashbox_shift_totals($companyA,$hugeShift)['incoming']==='18446744073709551614','Gross cash turnover was truncated');
    query('INSERT INTO cash_shift_adjustments(company_id,shift_id,direction,amount,reason,created_by) VALUES(?,?,?,?,?,?)',[$companyA,$hugeShift,'in',(string)PHP_INT_MAX,'CI excessive balance',$userA]);
    expense_expect_blocked(fn()=>cashbox_expected_cash($companyA,$hugeShift),'Unrepresentable cash balance was silently truncated');
} finally {db()->rollBack();}
echo "PASS: exact cash balance with gross turnover above PHP_INT_MAX and explicit net overflow rejection.\n";

require_once $root.'/src/company_resources.php';
db()->beginTransaction();
try {
    $newTenant=insert_fixture('INSERT INTO companies(name,slug) VALUES(?,?)',['CI fresh company','ci-fresh-'.bin2hex(random_bytes(5))]);
    company_initialize_branches($newTenant);company_initialize_branches($newTenant);
    $newUser=insert_fixture('INSERT INTO users(company_id,username,password_hash,full_name,role) VALUES(?,?,?,?,?)',[$newTenant,'fresh-'.bin2hex(random_bytes(5)),password_hash('disposable password',PASSWORD_DEFAULT),'CI fresh user','operator']);
    company_assign_new_user_branch($newTenant,$newUser);
    expense_initialize_categories($newTenant);expense_initialize_categories($newTenant);
    integration_assert((int)query('SELECT COUNT(*) FROM branches WHERE company_id=?',[$newTenant])->fetchColumn()===1,'New company has duplicate or missing central branch');
    integration_assert((int)query('SELECT COUNT(*) FROM cash_registers WHERE company_id=?',[$newTenant])->fetchColumn()===1,'New company has duplicate or missing register');
    integration_assert((int)query('SELECT COUNT(*) FROM expense_categories WHERE company_id=?',[$newTenant])->fetchColumn()===13,'New company default categories are missing or duplicated');
    integration_assert((int)query('SELECT COUNT(*) FROM user_branch_assignments WHERE company_id=? AND user_id=? AND is_active=1 AND is_default=1',[$newTenant,$newUser])->fetchColumn()===1,'New user lacks active default branch');
    expense_expect_blocked(fn()=>company_assign_new_user_branch($companyA,$newUser),'New user provisioning accepted cross-company assignment');
} finally {db()->rollBack();}
echo "PASS: atomic new-company central branch/register/category provisioning and new-user branch isolation.\n";
