<?php
declare(strict_types=1);
function expense_extensions_ready(): bool {
    return (int)query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('expense_settlements','expense_reversals')")->fetchColumn()===2;
}
function expense_employee_due(int $tenant,int $id): int {
    $returned=expense_returns_ready()?'COALESCE((SELECT SUM(r.amount) FROM expense_returns r WHERE r.company_id=e.company_id AND r.expense_id=e.id AND r.settlement_id IS NOT NULL),0)':'0';
    // Net in DECIMAL before converting: repeated settlement/return cycles can make gross totals exceed PHP_INT_MAX.
    $due=(string)query("SELECT COALESCE((SELECT SUM(p.amount) FROM expense_payments p WHERE p.company_id=e.company_id AND p.expense_id=e.id AND p.method='personal' AND p.posted_at IS NOT NULL),0)-COALESCE((SELECT SUM(s.amount) FROM expense_settlements s WHERE s.company_id=e.company_id AND s.expense_id=e.id),0)+".$returned.' FROM expenses e WHERE e.company_id=? AND e.id=?',[$tenant,$id])->fetchColumn();
    return $due===''||$due==='0'?0:invoice_payment_amount($due);
}
/** A cash entry must share the existing shift lock and must not reopen closed shifts. */
function expense_cash_entry(int $tenant,int $branch,int $shift,int $amount,string $direction,string $reason,?int $owner=null): int {
    if(!db()->inTransaction())throw new LogicException('Transaction required');
    if($amount<=0||!in_array($direction,['in','out'],true))throw new RuntimeException('گردش صندوق نامعتبر است.');
    branch_require($tenant,(int)user()['id'],$branch);
    $s=query("SELECT s.* FROM cash_shifts s JOIN cash_registers r ON r.id=s.register_id AND r.company_id=s.company_id WHERE s.id=? AND s.company_id=? AND s.status='open' AND r.branch_id=? AND r.is_active=1 FOR UPDATE",[$shift,$tenant,$branch])->fetch();
    if(!$s||($owner!==null&&(int)$s['operator_id']!==$owner))throw new RuntimeException('صندوق فعال و مجاز نیست.');
    if($direction==='out'&&$amount>cashbox_expected_cash($tenant,$shift))throw new RuntimeException('موجودی نقدی کافی نیست.');
    query('INSERT INTO cash_shift_adjustments(company_id,shift_id,direction,amount,reason,created_by) VALUES(?,?,?,?,?,?)',[$tenant,$shift,$direction,$amount,$reason,(int)user()['id']]);
    $adjustmentId=(int)db()->lastInsertId();
    cashbox_expected_cash($tenant,$shift);
    return $adjustmentId;
}
function expense_settle(int $id,array $input): void {
    require_admin();if(!expense_extensions_ready())throw new RuntimeException('مهاجرت ۰۲۷ لازم است.');
    $tenant=tenant_id();$actor=(int)user()['id'];$key=(string)($input['request_key']??'');$amount=invoice_payment_amount($input['amount']??'');$method=(string)($input['method']??'');$source=filter_var($input['source']??null,FILTER_VALIDATE_INT);
    if(($input['payment_confirmed']??null)!=='1'||!preg_match('/^[a-f0-9]{64}$/D',$key)||!$source||!in_array($method,['cash','bank'],true))throw new RuntimeException('اطلاعات تسویه نامعتبر است.');
    db()->beginTransaction();
    try{
        query('SELECT id FROM companies WHERE id=? FOR UPDATE',[$tenant])->fetch();
        $old=query('SELECT * FROM expense_settlements WHERE company_id=? AND request_key=?',[$tenant,$key])->fetch();
        if($old){if((int)$old['expense_id']!==$id||(int)$old['amount']!==$amount||$old['method']!==$method||(int)($old['account_id']??$old['cash_shift_id'])!==$source)throw new RuntimeException('درخواست تکراری مغایر است.');db()->commit();return;}
        $x=query('SELECT * FROM expenses WHERE company_id=? AND id=? FOR UPDATE',[$tenant,$id])->fetch();
        if(!$x||$x['approval_status']!=='approved'||$amount>expense_employee_due($tenant,$id))throw new RuntimeException('مانده قابل تسویه کافی نیست.');
        branch_require($tenant,$actor,(int)$x['branch_id']);
        accounting_assert_write_open($tenant,date('Y-m-d'));
        $adjustment=null;
        if($method==='cash')$adjustment=expense_cash_entry($tenant,(int)$x['branch_id'],$source,$amount,'out','تسویه هزینه '.$id,$actor);
        elseif(!query('SELECT id FROM company_bank_accounts WHERE company_id=? AND id=? AND is_active=1 AND (branch_id IS NULL OR branch_id=?) FOR UPDATE',[$tenant,$source,$x['branch_id']])->fetch())throw new RuntimeException('حساب بانکی مجاز نیست.');
        query('INSERT INTO expense_settlements(company_id,expense_id,amount,method,account_id,cash_shift_id,cash_adjustment_id,actor_id,request_key) VALUES(?,?,?,?,?,?,?,?,?)',[$tenant,$id,$amount,$method,$method==='bank'?$source:null,$method==='cash'?$source:null,$adjustment,$actor,$key]);
        expense_event($tenant,$id,'settled',(string)$amount);db()->commit();
    }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
}
function expense_void(int $id,string $reason): void {
    require_admin();if(!expense_extensions_ready())throw new RuntimeException('مهاجرت ۰۲۷ لازم است.');
    if(mb_strlen($reason)<5||mb_strlen($reason)>500)throw new RuntimeException('دلیل ابطال الزامی است.');
    $tenant=tenant_id();$actor=(int)user()['id'];
    db()->beginTransaction();
    try{
        query('SELECT id FROM companies WHERE id=? FOR UPDATE',[$tenant])->fetch();
        $x=query('SELECT * FROM expenses WHERE company_id=? AND id=? FOR UPDATE',[$tenant,$id])->fetch();
        if(!$x||!in_array($x['approval_status'],['approved','pending','rejected','draft'],true))throw new RuntimeException('سند قابل ابطال نیست.');
        branch_require($tenant,$actor,(int)$x['branch_id']);
        accounting_assert_write_open($tenant,$x['expense_date']);accounting_assert_write_open($tenant,date('Y-m-d'));
        $settled=(string)query('SELECT COALESCE(SUM(amount),0) FROM expense_settlements WHERE company_id=? AND expense_id=?',[$tenant,$id])->fetchColumn();
        $returned=expense_returns_ready()?(string)query('SELECT COALESCE(SUM(amount),0) FROM expense_returns WHERE company_id=? AND expense_id=? AND settlement_id IS NOT NULL',[$tenant,$id])->fetchColumn():'0';
        if($settled!==$returned)throw new RuntimeException('ابتدا مانده تسویه کارمند باید با سند برگشت واقعی دریافت شود.');
        // An administrative cancellation is not evidence that funds were physically returned.
        if($x['approval_status']==='approved'){
            $paid=(int)query("SELECT COALESCE(SUM(amount),0) FROM expense_payments WHERE company_id=? AND expense_id=? AND posted_at IS NOT NULL AND method IN ('cash','bank')",[$tenant,$id])->fetchColumn();
            $refunded=expense_returns_ready()?(int)query('SELECT COALESCE(SUM(amount),0) FROM expense_returns WHERE company_id=? AND expense_id=? AND payment_id IS NOT NULL',[$tenant,$id])->fetchColumn():0;
            if($paid!==$refunded)throw new RuntimeException('ابتدا مانده پرداخت نقدی یا بانکی باید با سند برگشت واقعی دریافت شود.');
        }
        if($x['approval_status']==='approved'){
            foreach(query('SELECT * FROM expense_payments WHERE company_id=? AND expense_id=? AND posted_at IS NOT NULL ORDER BY id FOR UPDATE',[$tenant,$id])->fetchAll() as $p){
                $adjustment=null;
                // Refund receipts already posted cash/bank movements; cancellation never posts money.
                query('INSERT INTO expense_reversals(payment_id,company_id,expense_id,cash_adjustment_id,actor_id) VALUES(?,?,?,?,?)',[$p['id'],$tenant,$id,$adjustment,$actor]);
            }
        }
        query("UPDATE expenses SET approval_status='void' WHERE company_id=? AND id=?",[$tenant,$id]);expense_event($tenant,$id,'void',$reason);db()->commit();
    }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
}
