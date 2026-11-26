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
    $exact=static fn($value): int|string => filter_var($value,FILTER_VALIDATE_INT)!==false ? (int)$value : (string)$value;
    return ['receipts'=>(int)$money['receipts'],'cash'=>$exact($money['cash']),'pos'=>$exact($money['pos']),
      'incoming'=>$exact($adjust['incoming']),'outgoing'=>$exact($adjust['outgoing'])];
}
/** Net in DECIMAL before conversion: gross turnover may exceed the PHP integer range. */
function cashbox_expected_cash(int $tenant,int $shift): int {
    $value=query("SELECT s.opening_cash
        + COALESCE((SELECT SUM(p.amount) FROM invoice_payments p WHERE p.company_id=s.company_id
            AND p.cash_shift_id=s.id AND p.voided_at IS NULL AND p.payment_method='cash'),0)
        + COALESCE((SELECT SUM(CASE WHEN a.direction='in' THEN CAST(a.amount AS DECIMAL(65,0)) ELSE -CAST(a.amount AS DECIMAL(65,0)) END)
            FROM cash_shift_adjustments a WHERE a.company_id=s.company_id AND a.shift_id=s.id),0)
        FROM cash_shifts s WHERE s.company_id=? AND s.id=?",[$tenant,$shift])->fetchColumn();
    $amount=filter_var($value,FILTER_VALIDATE_INT);
    if($amount===false) throw new RuntimeException('موجودی صندوق خارج از محدوده عدد صحیح قابل ثبت است.');
    return $amount;
}
function cashbox_require_shift_branch(int $tenant,int $shift,int $actor): void {
    if(function_exists('branches_ready')&&branches_ready()) {
        $branch=query('SELECT r.branch_id FROM cash_shifts s JOIN cash_registers r ON r.id=s.register_id AND r.company_id=s.company_id WHERE s.company_id=? AND s.id=?',[$tenant,$shift])->fetchColumn();
        branch_require($tenant,$actor,$branch);
    }
}
function cashbox_add_adjustment(int $tenant,int $shift,int $actor,string $direction,int $amount,string $reason): void {
    if($amount<=0||!in_array($direction,['in','out'],true)||mb_strlen($reason)<5||mb_strlen($reason)>500) {
        throw new RuntimeException('جهت و دلیل ورود یا خروج وجه نامعتبر است.');
    }
    db()->beginTransaction();
    try{
        $row=query('SELECT id,operator_id,status FROM cash_shifts WHERE company_id=? AND id=? FOR UPDATE',[$tenant,$shift])->fetch();
        if(!$row || !in_array($row['status'],['open','rejected'],true)) throw new RuntimeException('این شیفت امکان ثبت وجه جدید ندارد.');
        if((int)$row['operator_id']!==$actor && !can('cashbox.manage')) throw new RuntimeException('این شیفت متعلق به کاربر دیگری است.');
        cashbox_require_shift_branch($tenant,$shift,$actor);
        if($direction==='out'&&$amount>cashbox_expected_cash($tenant,$shift))throw new RuntimeException('موجودی نقدی صندوق کافی نیست.');
        query('INSERT INTO cash_shift_adjustments(company_id,shift_id,direction,amount,reason,created_by) VALUES(?,?,?,?,?,?)',
            [$tenant,$shift,$direction,$amount,$reason,$actor]);
        cashbox_expected_cash($tenant,$shift);
        audit('cashbox.adjust',json_encode(['shift_id'=>$shift,'direction'=>$direction,'amount'=>$amount,'reason'=>$reason],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        db()->commit();
    }catch(Throwable $ex){db()->rollBack();throw $ex;}
}
function cashbox_close_shift(int $tenant,int $shift,int $actor,int $declared,string $note): void {
    if($declared<0||mb_strlen($note)>500) throw new RuntimeException('مبلغ یا توضیحات تسویه نامعتبر است.');
    db()->beginTransaction();
    try{
        $row=query('SELECT id,operator_id,opening_cash,status FROM cash_shifts WHERE id=? AND company_id=? FOR UPDATE',[$shift,$tenant])->fetch();
        if(!$row || !in_array($row['status'],['open','rejected'],true)) throw new RuntimeException('این شیفت قابل بستن نیست.');
        if((int)$row['operator_id']!==$actor && !can('cashbox.manage')) throw new RuntimeException('بستن صندوق این کاربر مجاز نیست.');
        cashbox_require_shift_branch($tenant,$shift,$actor);
        $expected=cashbox_expected_cash($tenant,$shift);
        if($expected<0 && $declared>PHP_INT_MAX+$expected) throw new RuntimeException('مغایرت صندوق خارج از محدوده قابل ثبت است.');
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
