<?php
declare(strict_types=1);
// This prerequisite refuses any database except the disposable localhost test schema.
require __DIR__.'/mysql-tenant-smoke.php';
require $root.'/src/branches.php';
require $root.'/src/expenses.php';
require $root.'/src/expense_settlement.php';
require $root.'/src/expense_returns.php';
require $root.'/src/expenses_page.php';
require $root.'/src/expense_financial_forms.php';
require $root.'/src/expense_reports.php';
foreach(range(1,2) as $pass)foreach(range(23,28) as $version){
    $files=glob($root.'/database/migrations/'.sprintf('%03d',$version).'_*.sql');
    integration_assert(count($files)===1,'Missing or ambiguous expense migration '.$version);
    apply_sql_fixture($files[0]);
}
integration_assert(expenses_ready()&&expense_extensions_ready()&&expense_returns_ready(),'Expense schema not ready');
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
    $t=cashbox_shift_totals($tenant,$shift);
    return (int)query('SELECT opening_cash FROM cash_shifts WHERE company_id=? AND id=?',[$tenant,$shift])->fetchColumn()+$t['cash']+$t['incoming']-$t['outgoing'];
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
expense_expect_blocked(fn()=>expense_return($expense,array_replace($refund,['amount'=>'1'])),'Changed retry accepted');
expense_expect_blocked(fn()=>expense_return($expense,array_replace($refund,['request_key'=>bin2hex(random_bytes(32)),'amount'=>'300001'])),'Excess refund accepted');
// The original closed shift is immutable; actual receipts enter a new open shift.
query("UPDATE cash_shifts SET status='approved',closed_at=NOW() WHERE company_id=? AND id=?",[$companyA,$shift]);
$nextShift=insert_fixture("INSERT INTO cash_shifts(company_id,register_id,operator_id,opening_cash,started_at) VALUES(?,?,?,?,NOW())",[$companyA,$register,$userA,0]);
expense_return($expense,array_replace($refund,['amount'=>'300000','cash_shift_id'=>$nextShift,'request_key'=>bin2hex(random_bytes(32))]));
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
expense_return($personal,array_replace($settlementRefund,['amount'=>'100000','request_key'=>bin2hex(random_bytes(32))]));
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
echo "PASS: expense migrations twice, cash reconciliation, refunds, employee liability, retries, closed shifts, bank account and tenant boundaries.\n";
