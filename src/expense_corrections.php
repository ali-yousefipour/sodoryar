<?php
declare(strict_types=1);

function expense_corrections_ready(): bool {
    return (bool)query("SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='expense_corrections' AND COLUMN_NAME='correction_date'")->fetchColumn();
}
function expense_correction_row(int $tenant,int $expense): ?array {
    if(!expense_corrections_ready())return null;
    return query('SELECT * FROM expense_corrections WHERE company_id=? AND expense_id=?',[$tenant,$expense])->fetch()?:null;
}
/** Recognize a cancelling correction today; never change the original date, amount, status or money. */
function expense_correct(int $id,string $reason,string $key): int {
    require_admin();
    if(!expense_corrections_ready())throw new RuntimeException('مهاجرت سند اصلاحی ۰۳۰ لازم است.');
    if(mb_strlen($reason)<5||mb_strlen($reason)>500||!preg_match('/^[a-f0-9]{64}$/D',$key))throw new RuntimeException('دلیل یا شناسه سند اصلاحی نامعتبر است.');
    $tenant=tenant_id();$actor=(int)user()['id'];
    $hash=hash('sha256',json_encode([$id,$reason,$actor],JSON_THROW_ON_ERROR));
    db()->beginTransaction();
    try {
        query('SELECT id FROM companies WHERE id=? FOR UPDATE',[$tenant])->fetch();
        $previous=query('SELECT * FROM expense_corrections WHERE company_id=? AND request_key=?',[$tenant,$key])->fetch();
        if($previous){if(!hash_equals($previous['request_hash'],$hash))throw new RuntimeException('درخواست تکراری اصلاحیه مغایر است.');db()->commit();return (int)$previous['id'];}
        $x=query('SELECT * FROM expenses WHERE company_id=? AND id=? FOR UPDATE',[$tenant,$id])->fetch();
        if(!$x||$x['approval_status']!=='approved'||expense_correction_row($tenant,$id))throw new RuntimeException('سند قطعی معتبر و فاقد اصلاحیه را انتخاب کنید.');
        branch_require($tenant,$actor,(int)$x['branch_id']);
        accounting_assert_write_open($tenant,date('Y-m-d'));
        expense_assert_refunds_complete($tenant,$x);
        query('INSERT INTO expense_corrections(company_id,expense_id,correction_date,amount,reason,actor_id,request_key,request_hash) VALUES(?,?,?,?,?,?,?,?)',[$tenant,$id,date('Y-m-d'),$x['total_amount'],$reason,$actor,$key,$hash]);
        $correction=(int)db()->lastInsertId();
        expense_event($tenant,$id,'correction.void',$reason);
        audit('expense.correction',json_encode(['expense_id'=>$id,'correction_id'=>$correction],JSON_THROW_ON_ERROR),$tenant);
        db()->commit();return $correction;
    }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
}

/** Cost corrections belong to their own open date, including when the original period is closed. */
function expense_cost_total(int $tenant,array $filter): int {
    [$where,$args]=expense_center_where($tenant,$filter);
    $cost=expense_report_add(0,query("SELECT COALESCE(SUM(e.total_amount),0) FROM expenses e WHERE ".$where." AND e.approval_status='approved' AND e.classification='expense'",$args)->fetchColumn());
    if(!expense_corrections_ready())return $cost;
    [$where,$args]=expense_center_where($tenant,$filter,'cx.correction_date');
    $reversed=query("SELECT COALESCE(SUM(cx.amount),0) FROM expense_corrections cx JOIN expenses e ON e.id=cx.expense_id AND e.company_id=cx.company_id WHERE ".$where." AND e.approval_status='approved' AND e.classification='expense'",$args)->fetchColumn();
    return expense_report_add($cost,-expense_report_add(0,$reversed));
}
