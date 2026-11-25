<?php
declare(strict_types=1);

/** All cash-shift writes are isolated within one company and database transaction. */
function cashbox_ready(): bool {
    static $ready=null;
    if($ready===null) $ready=(bool)query("SHOW TABLES LIKE 'cash_registers'")->fetchColumn()
        && (bool)query("SHOW TABLES LIKE 'cash_shifts'")->fetchColumn()
        && (bool)query("SHOW TABLES LIKE 'cash_shift_adjustments'")->fetchColumn()
        && invoice_payment_ledger_ready()
        && (bool)query("SHOW COLUMNS FROM invoice_payments LIKE 'cash_shift_id'")->fetch();
    return $ready;
}
function cashbox_amount(mixed $value,bool $allowZero=false): int {
    if ($allowZero && in_array((string)$value,['0','۰','٠'],true)) return 0;
    return invoice_payment_amount($value);
}
function cashbox_active_shift(int $tenant,int $operator): ?int {
    if(!cashbox_ready()) return null;
    $row=query("SELECT id FROM cash_shifts WHERE company_id=? AND operator_id=? AND status='open'
        ORDER BY id DESC LIMIT 1 FOR UPDATE",[$tenant,$operator])->fetch();
    return $row?(int)$row['id']:null;
}
function cashbox_start_shift(int $tenant,int $operator,int $register,int $opening): int {
    if(!cashbox_ready()) throw new RuntimeException('برای شروع صندوق، ابتدا مهاجرت ۰۱۸ را اعمال کنید.');
    db()->beginTransaction();
    try {
        if(!query('SELECT id FROM users WHERE id=? AND company_id=? AND is_active=1 FOR UPDATE',[$operator,$tenant])->fetch()) {
            throw new RuntimeException('اپراتور فعال به این شرکت تعلق ندارد.');
        }
        if(!query('SELECT id FROM cash_registers WHERE id=? AND company_id=? AND is_active=1 FOR UPDATE',[$register,$tenant])->fetch()) {
            throw new RuntimeException('صندوق انتخاب‌شده فعال یا متعلق به شرکت نیست.');
        }
        if(function_exists('branches_ready') && branches_ready()) {
            $branch=query('SELECT branch_id FROM cash_registers WHERE id=? AND company_id=?',[$register,$tenant])->fetchColumn();
            branch_require($tenant,$operator,$branch);
        }
        if(query("SELECT id FROM cash_shifts WHERE company_id=? AND (register_id=? OR operator_id=?)
                AND status IN ('open','pending','rejected') LIMIT 1 FOR UPDATE",
            [$tenant,$register,$operator])->fetch()) {
            throw new RuntimeException('برای این صندوق یا اپراتور یک شیفت باز یا در انتظار تسویه وجود دارد.');
        }
        query("INSERT INTO cash_shifts(company_id,register_id,operator_id,opening_cash,started_at,status)
            VALUES (?,?,?,?,NOW(),'open')",[$tenant,$register,$operator,$opening]);
        $id=(int)db()->lastInsertId();
        audit('cashbox.shift.start',json_encode(['shift_id'=>$id,'register_id'=>$register],JSON_THROW_ON_ERROR));
        db()->commit();
        return $id;
    }catch(Throwable $ex){db()->rollBack();throw $ex;}
}
function cashbox_shift_totals(int $tenant,int $shift): array {
    $money=query("SELECT COUNT(p.id) receipts,
       COALESCE(SUM(CASE WHEN p.payment_method='cash' THEN p.amount ELSE 0 END),0) cash,
       COALESCE(SUM(CASE WHEN p.payment_method='pos' THEN p.amount ELSE 0 END),0) pos
       FROM invoice_payments p WHERE p.company_id=? AND p.cash_shift_id=? AND p.voided_at IS NULL",
       [$tenant,$shift])->fetch();
    $adjust=query("SELECT
       COALESCE(SUM(CASE WHEN direction='in' THEN amount ELSE 0 END),0) incoming,
       COALESCE(SUM(CASE WHEN direction='out' THEN amount ELSE 0 END),0) outgoing
       FROM cash_shift_adjustments WHERE company_id=? AND shift_id=?",[$tenant,$shift])->fetch();
    return ['receipts'=>(int)$money['receipts'],'cash'=>(int)$money['cash'],'pos'=>(int)$money['pos'],
      'incoming'=>(int)$adjust['incoming'],'outgoing'=>(int)$adjust['outgoing']];
}
/** Apply branch authorization to every existing-shift mutation, including guessed IDs. */
function cashbox_require_shift_branch(int $tenant,int $shift,int $actor): void {
    if(function_exists('branches_ready') && branches_ready()) {
        $branch=query('SELECT r.branch_id FROM cash_shifts s JOIN cash_registers r ON r.id=s.register_id AND r.company_id=s.company_id WHERE s.company_id=? AND s.id=?',[$tenant,$shift])->fetchColumn();
        branch_require($tenant,$actor,$branch);
    }
}
function cashbox_add_adjustment(int $tenant,int $shift,int $actor,string $direction,int $amount,string $reason): void {
    if($amount<=0 || !in_array($direction,['in','out'],true)||mb_strlen($reason)<5||mb_strlen($reason)>500) {
        throw new RuntimeException('جهت و دلیل ورود یا خروج وجه نامعتبر است.');
    }
    db()->beginTransaction();
    try{
        $row=query('SELECT id,operator_id,status FROM cash_shifts WHERE company_id=? AND id=? FOR UPDATE',[$tenant,$shift])->fetch();
        if(!$row || !in_array($row['status'],['open','rejected'],true)) throw new RuntimeException('این شیفت امکان ثبت وجه جدید ندارد.');
        if((int)$row['operator_id']!==$actor && !can('cashbox.manage')) throw new RuntimeException('این شیفت متعلق به کاربر دیگری است.');
        cashbox_require_shift_branch($tenant,$shift,$actor);
        query('INSERT INTO cash_shift_adjustments(company_id,shift_id,direction,amount,reason,created_by) VALUES(?,?,?,?,?,?)',
            [$tenant,$shift,$direction,$amount,$reason,$actor]);
        audit('cashbox.adjust',json_encode(['shift_id'=>$shift,'direction'=>$direction,'amount'=>$amount,'reason'=>$reason],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        db()->commit();
    }catch(Throwable $ex){db()->rollBack();throw $ex;}
}
function cashbox_close_shift(int $tenant,int $shift,int $actor,int $declared,string $note): void {
    if(mb_strlen($note)>500) throw new RuntimeException('توضیحات تسویه طولانی است.');
    db()->beginTransaction();
    try{
        $row=query('SELECT id,operator_id,opening_cash,status FROM cash_shifts WHERE id=? AND company_id=? FOR UPDATE',[$shift,$tenant])->fetch();
        if(!$row || !in_array($row['status'],['open','rejected'],true)) throw new RuntimeException('این شیفت قابل بستن نیست.');
        if((int)$row['operator_id']!==$actor && !can('cashbox.manage')) throw new RuntimeException('بستن صندوق این کاربر مجاز نیست.');
        cashbox_require_shift_branch($tenant,$shift,$actor);
        $totals=cashbox_shift_totals($tenant,$shift);
        $expected=(int)$row['opening_cash']+$totals['cash']+$totals['incoming']-$totals['outgoing'];
        $difference=$declared-$expected;
        if($difference!==0 && mb_strlen($note)<5) throw new RuntimeException('برای مغایرت نقدی، توضیح حداقل ۵ نویسه الزامی است.');
        query("UPDATE cash_shifts SET declared_cash=?,expected_cash=?,difference_cash=?,closed_at=NOW(),
                close_note=?,status='pending',manager_note=NULL,reviewed_by=NULL,reviewed_at=NULL
                WHERE id=? AND company_id=?",
            [$declared,$expected,$difference,$note,$shift,$tenant]);
        audit('cashbox.shift.close',json_encode(['shift_id'=>$shift,'expected'=>$expected,'declared'=>$declared,'difference'=>$difference],JSON_THROW_ON_ERROR));
        db()->commit();
    }catch(Throwable $ex){db()->rollBack();throw $ex;}
}
function cashbox_review_shift(int $tenant,int $shift,int $actor,string $decision,string $note): void {
    if(!in_array($decision,['approved','rejected'],true)||mb_strlen($note)>500) throw new RuntimeException('تصمیم تسویه نامعتبر است.');
    if(!can('cashbox.approve')) throw new RuntimeException('مجوز تأیید صندوق ندارید.');
    db()->beginTransaction();
    try{
        $row=query('SELECT id,status,operator_id FROM cash_shifts WHERE company_id=? AND id=? FOR UPDATE',[$tenant,$shift])->fetch();
        if(!$row||$row['status']!=='pending') throw new RuntimeException('این صندوق در انتظار تأیید نیست.');
        if((int)$row['operator_id']===$actor) throw new RuntimeException('اپراتور نمی‌تواند تسویه صندوق خود را تأیید کند.');
        cashbox_require_shift_branch($tenant,$shift,$actor);
        query('UPDATE cash_shifts SET status=?,manager_note=?,reviewed_by=?,reviewed_at=NOW() WHERE id=? AND company_id=?',
           [$decision,$note,$actor,$shift,$tenant]);
        audit('cashbox.shift.review',json_encode(['shift_id'=>$shift,'decision'=>$decision,'note'=>$note],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        db()->commit();
    }catch(Throwable $ex){db()->rollBack();throw $ex;}
}
function cashbox_add_register(int $tenant,string $name): void {
    $name=trim($name);
    if($name===''||mb_strlen($name)>120) throw new RuntimeException('نام صندوق نامعتبر است.');
    if(function_exists('branches_ready') && branches_ready()) {
        $branch=branch_require($tenant,(int)user()['id'],$_POST['branch_id']??null);
        query('INSERT INTO cash_registers(company_id,branch_id,name) VALUES(?,?,?)',[$tenant,$branch,$name]);
    } else {
        query('INSERT INTO cash_registers(company_id,name) VALUES(?,?)',[$tenant,$name]);
    }
    audit('cashbox.register.create',$name);
}

/** Historical receipts assigned to a submitted or approved shift cannot be rewritten. */
function cashbox_assert_payments_mutable(int $tenant,int $invoiceId): void {
    if(!cashbox_ready()) return;
    $closed=query("SELECT p.id FROM invoice_payments p
        JOIN cash_shifts s ON s.id=p.cash_shift_id AND s.company_id=p.company_id
        WHERE p.company_id=? AND p.invoice_id=? AND p.voided_at IS NULL
          AND s.status<>'open' LIMIT 1",[$tenant,$invoiceId])->fetch();
    if($closed) throw new RuntimeException('پرداخت‌های این فاکتور در صندوق بسته یا در انتظار بررسی ثبت شده‌اند؛ اصلاح مستقیم مجاز نیست.');
}

/** Expenses share the cash ledger so settlement deducts each expense exactly once. */
function cashbox_expenses_ready(): bool {
    return (bool)query("SHOW COLUMNS FROM cash_shift_adjustments LIKE 'expense_branch_id'")->fetch();
}
function cashbox_add_expense(int $tenant,int $shift,int $actor,int $amount,string $reason,string $token): void {
    require_permission('cashbox.adjust');
    if (!cashbox_expenses_ready() || !branches_ready()) throw new RuntimeException('ابتدا مهاجرت‌های ۰۲۴ تا ۰۲۶ را اعمال کنید.');
    if ($amount<=0 || mb_strlen($reason)<5 || mb_strlen($reason)>500 || !preg_match('/^[a-f0-9]{64}$/D',$token)) throw new RuntimeException('مبلغ یا شرح هزینه نامعتبر است.');
    db()->beginTransaction();
    try {
        $row=query("SELECT s.operator_id,s.status,s.opening_cash,r.branch_id FROM cash_shifts s JOIN cash_registers r ON r.id=s.register_id AND r.company_id=s.company_id WHERE s.company_id=? AND s.id=? FOR UPDATE",[$tenant,$shift])->fetch();
        if (!$row || $row['status']!=='open' || ((int)$row['operator_id']!==$actor && !can('cashbox.manage'))) throw new RuntimeException('ثبت هزینه فقط در صندوق باز و مجاز امکان‌پذیر است.');
        $branch=branch_require($tenant,$actor,$row['branch_id']);
        $previous=query('SELECT shift_id,amount,reason,created_by FROM cash_shift_adjustments WHERE company_id=? AND expense_token=?',[$tenant,$token])->fetch();
        if($previous){
            if((int)$previous['shift_id']!==$shift || (int)$previous['amount']!==$amount || $previous['reason']!==$reason || (int)$previous['created_by']!==$actor) throw new RuntimeException('شناسه درخواست هزینه قبلاً برای اطلاعات دیگری استفاده شده است.');
            db()->commit();return;
        }
        $totals=cashbox_shift_totals($tenant,$shift);
        $available=(int)$row['opening_cash']+$totals['cash']+$totals['incoming']-$totals['outgoing'];
        if ($amount>$available) throw new RuntimeException('موجودی نقدی صندوق برای این هزینه کافی نیست.');
        query("INSERT INTO cash_shift_adjustments(company_id,shift_id,direction,amount,reason,created_by,expense_branch_id,expense_token) VALUES(?,?,'out',?,?,?,?,?)",[$tenant,$shift,$amount,$reason,$actor,$branch,$token]);
        audit('cashbox.expense',json_encode(['shift_id'=>$shift,'branch_id'=>$branch,'amount'=>$amount,'reason'=>$reason],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        db()->commit();
    } catch(Throwable $ex) { db()->rollBack(); throw $ex; }
}

function cashbox_transfers_ready(): bool {
    return database_table_exists('cash_transfers');
}
/** One transfer creates two cash movements in the same transaction, never a receipt or sale. */
function cashbox_transfer(int $tenant,int $source,int $destination,int $actor,int $amount,string $reason,string $token): int {
    require_permission('cashbox.manage');
    require_permission('cashbox.adjust');
    if(!cashbox_transfers_ready() || !branches_ready()) throw new RuntimeException('ابتدا مهاجرت ۰۲۷ انتقال صندوق را اعمال کنید.');
    if($source<=0 || $destination<=0 || $source===$destination || $amount<=0 || mb_strlen($reason)<5 || mb_strlen($reason)>400 || !preg_match('/^[a-f0-9]{64}$/D',$token)) throw new RuntimeException('اطلاعات انتقال وجه معتبر نیست.');
    db()->beginTransaction();
    try {
        $rows=query("SELECT s.id,s.status,s.opening_cash,r.branch_id,r.is_active FROM cash_shifts s JOIN cash_registers r ON r.id=s.register_id AND r.company_id=s.company_id WHERE s.company_id=? AND s.id IN (?,?) ORDER BY s.id FOR UPDATE",[$tenant,$source,$destination])->fetchAll();
        if(count($rows)!==2) throw new RuntimeException('صندوق مبدأ یا مقصد متعلق به شرکت نیست.');
        $shifts=[];
        foreach($rows as $row) {
            branch_require($tenant,$actor,$row['branch_id']);
            $shifts[(int)$row['id']]=$row;
        }
        $previous=query('SELECT id,source_shift_id,destination_shift_id,amount,reason FROM cash_transfers WHERE company_id=? AND request_token=?',[$tenant,$token])->fetch();
        if($previous) {
            if((int)$previous['source_shift_id']!==$source || (int)$previous['destination_shift_id']!==$destination || (int)$previous['amount']!==$amount || $previous['reason']!==$reason) throw new RuntimeException('شناسه درخواست قبلاً برای انتقال دیگری استفاده شده است.');
            db()->commit();return (int)$previous['id'];
        }
        foreach($shifts as $row) if($row['status']!=='open' || !(int)$row['is_active']) throw new RuntimeException('انتقال فقط بین صندوق‌های فعال با شیفت باز مجاز است.');
        $totals=cashbox_shift_totals($tenant,$source);
        $available=(int)$shifts[$source]['opening_cash']+$totals['cash']+$totals['incoming']-$totals['outgoing'];
        if($amount>$available) throw new RuntimeException('موجودی نقدی مبدأ کافی نیست.');
        query('INSERT INTO cash_transfers(company_id,source_shift_id,destination_shift_id,amount,reason,created_by,request_token) VALUES(?,?,?,?,?,?,?)',[$tenant,$source,$destination,$amount,$reason,$actor,$token]);
        $id=(int)db()->lastInsertId();
        foreach([[$source,'out'],[$destination,'in']] as [$shift,$direction]) {
            query('INSERT INTO cash_shift_adjustments(company_id,shift_id,direction,amount,reason,created_by,transfer_id) VALUES(?,?,?,?,?,?,?)',[$tenant,$shift,$direction,$amount,'انتقال داخلی #'.$id.' — '.$reason,$actor,$id]);
        }
        audit('cashbox.transfer',json_encode(['transfer_id'=>$id,'source_shift'=>$source,'destination_shift'=>$destination,'amount'=>$amount],JSON_THROW_ON_ERROR));
        db()->commit();return $id;
    }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
}
