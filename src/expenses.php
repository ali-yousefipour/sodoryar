<?php
declare(strict_types=1);
function expenses_ready(): bool {
    return branches_ready() && (int)query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('expenses','expense_items','expense_payments','expense_categories','expense_events','company_bank_accounts')")->fetchColumn()===6;
}
function expense_manager(): bool {return in_array(user()['role'],['company_admin','superadmin'],true);}
function expense_items_normalize(array $input): array {
    if(!$input||count($input)>100)throw new RuntimeException('حداقل یک و حداکثر صد قلم وارد کنید.');
    $items=[];$total=0;
    foreach($input as $r){
        if(!is_array($r))throw new RuntimeException('قلم نامعتبر است.');
        $title=trim((string)($r['title']??''));$unit=trim((string)($r['unit']??'عدد'));$desc=trim((string)($r['description']??''));
        if($title===''||mb_strlen($title)>180||$unit===''||mb_strlen($unit)>40||mb_strlen($desc)>500)throw new RuntimeException('مشخصات قلم نامعتبر است.');
        $q=invoice_payment_amount($r['quantity']??'');$p=invoice_payment_amount($r['price']??'');
        if($q>1000000||$p>intdiv(PHP_INT_MAX-$total,$q))throw new RuntimeException('مبلغ خارج از محدوده است.');
        $line=$q*$p;$total+=$line;$items[]=[$title,$desc,$q,$unit,$p,$line];
    }
    return [$items,$total];
}
function expense_event(int $tenant,int $id,string $event,string $reason=''): void {
    query('INSERT INTO expense_events(company_id,expense_id,actor_id,event_type,reason) VALUES(?,?,?,?,?)',[$tenant,$id,(int)user()['id'],$event,$reason]);
}
function expense_create(array $input): int {
    require_login();
    if(!expenses_ready())throw new RuntimeException('ساختار هزینه‌ها آماده نیست.');
    $tenant=tenant_id();$actor=(int)user()['id'];
    if(is_super())throw new RuntimeException('ثبت هزینه باید توسط کاربر همان شرکت انجام شود.');
    $branch=branch_require($tenant,$actor,$input['branch_id']??null);
    $category=filter_var($input['category_id']??null,FILTER_VALIDATE_INT);
    $date=jalali_to_gregorian_date((string)($input['expense_date']??''));$d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    $title=trim((string)($input['title']??''));$notes=trim((string)($input['notes']??''));
    $key=(string)($input['request_key']??'');
    if(!$category||!$d||$d->format('Y-m-d')!==$date||$date>date('Y-m-d')||$title===''||mb_strlen($title)>180||mb_strlen($notes)>4000||!preg_match('/^[a-f0-9]{64}$/D',$key))throw new RuntimeException('اطلاعات سند معتبر نیست.');
    [$items,$total]=expense_items_normalize((array)($input['items']??[]));
    $payments=(array)($input['payments']??[]);if(!$payments||count($payments)>30)throw new RuntimeException('روش پرداخت را مشخص کنید.');
    $alloc=[];$sum=0;
    foreach($payments as $p){
        if(!is_array($p))throw new RuntimeException('پرداخت نامعتبر است.');
        $method=(string)($p['method']??'');$amount=invoice_payment_amount($p['amount']??'');
        if(!in_array($method,['cash','bank','personal'],true)||$amount>$total-$sum)throw new RuntimeException('جمع یا روش پرداخت نامعتبر است.');
        $source=filter_var($p['source']??null,FILTER_VALIDATE_INT);$tracking=trim((string)($p['tracking']??''));
        if(mb_strlen($tracking)>120||($method!=='personal'&&!$source))throw new RuntimeException('منبع پرداخت معتبر نیست.');
        $alloc[]=[$method,$amount,$method==='personal'?null:$source,$tracking];$sum+=$amount;
    }
    if($sum!==$total)throw new RuntimeException('جمع پرداخت‌ها باید برابر مبلغ هزینه باشد.');
    $hash=hash('sha256',json_encode([$branch,$category,$date,$title,$notes,$items,$alloc],JSON_THROW_ON_ERROR));
    db()->beginTransaction();
    try{
        query('SELECT id FROM companies WHERE id=? FOR UPDATE',[$tenant])->fetch();
        $old=query('SELECT id,request_hash FROM expenses WHERE company_id=? AND created_by=? AND request_key=?',[$tenant,$actor,$key])->fetch();
        if($old){if(!hash_equals($old['request_hash'],$hash))throw new RuntimeException('شناسه درخواست تکراری است.');db()->commit();return (int)$old['id'];}
        accounting_assert_write_open($tenant,$date);
        $cat=query('SELECT classification FROM expense_categories WHERE id=? AND company_id=? AND is_active=1',[$category,$tenant])->fetch();
        if(!$cat)throw new RuntimeException('دسته‌بندی معتبر نیست.');
        query('INSERT INTO expenses(company_id,branch_id,category_id,created_by,expense_date,title,notes,classification,total_amount,request_key,request_hash) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[$tenant,$branch,$category,$actor,$date,$title,$notes,$cat['classification'],$total,$key,$hash]);
        $id=(int)db()->lastInsertId();
        foreach($items as $r)query('INSERT INTO expense_items(company_id,expense_id,title,description,quantity,unit,unit_price,line_total) VALUES(?,?,?,?,?,?,?,?)',array_merge([$tenant,$id],$r));
        foreach($alloc as [$method,$amount,$source,$tracking]){
            if($method==='cash'&&!query("SELECT s.id FROM cash_shifts s JOIN cash_registers r ON r.id=s.register_id AND r.company_id=s.company_id WHERE s.id=? AND s.company_id=? AND s.operator_id=? AND s.status='open' AND r.branch_id=? AND r.is_active=1 FOR UPDATE",[$source,$tenant,$actor,$branch])->fetch())throw new RuntimeException('صندوق فعال متعلق به شما و شعبه نیست.');
            if($method==='bank'&&!query('SELECT id FROM company_bank_accounts WHERE id=? AND company_id=? AND is_active=1 AND (branch_id IS NULL OR branch_id=?)',[$source,$tenant,$branch])->fetch())throw new RuntimeException('حساب مجاز نیست.');
            query('INSERT INTO expense_payments(company_id,expense_id,method,amount,account_id,cash_shift_id,tracking) VALUES(?,?,?,?,?,?,?)',[$tenant,$id,$method,$amount,$method==='bank'?$source:null,$method==='cash'?$source:null,$tracking?:null]);
        }
        expense_event($tenant,$id,'submitted');db()->commit();return $id;
    }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
}
function expense_review(int $id,string $decision,string $reason): void {
    require_admin();$tenant=tenant_id();$actor=(int)user()['id'];
    if(!in_array($decision,['approved','rejected'],true)||mb_strlen($reason)>500||($decision==='rejected'&&mb_strlen($reason)<5))throw new RuntimeException('تصمیم یا دلیل معتبر نیست.');
    db()->beginTransaction();
    try{
        query('SELECT id FROM companies WHERE id=? FOR UPDATE',[$tenant])->fetch();
        $x=query('SELECT * FROM expenses WHERE company_id=? AND id=? FOR UPDATE',[$tenant,$id])->fetch();
        if(!$x||$x['approval_status']!=='pending')throw new RuntimeException('سند در انتظار تأیید نیست.');
        if($decision==='approved'){
            accounting_assert_write_open($tenant,$x['expense_date']);accounting_assert_write_open($tenant,date('Y-m-d'));
            $rows=query('SELECT * FROM expense_payments WHERE company_id=? AND expense_id=? ORDER BY id FOR UPDATE',[$tenant,$id])->fetchAll();
            foreach($rows as $p){
                if($p['posted_at']!==null)throw new RuntimeException('پرداخت قبلاً قطعی شده است.');
                $adjustment=null;
                if($p['method']==='cash'){
                    $s=query("SELECT s.* FROM cash_shifts s JOIN cash_registers r ON r.id=s.register_id AND r.company_id=s.company_id WHERE s.id=? AND s.company_id=? AND s.status='open' AND r.branch_id=? AND r.is_active=1 FOR UPDATE",[$p['cash_shift_id'],$tenant,$x['branch_id']])->fetch();
                    if(!$s||(int)$s['operator_id']!==(int)$x['created_by'])throw new RuntimeException('شیفت اولیه صندوق دیگر فعال نیست.');
                    $t=cashbox_shift_totals($tenant,(int)$s['id']);
                    if((int)$p['amount']>(int)$s['opening_cash']+$t['cash']+$t['incoming']-$t['outgoing'])throw new RuntimeException('موجودی نقدی کافی نیست.');
                    query("INSERT INTO cash_shift_adjustments(company_id,shift_id,direction,amount,reason,created_by) VALUES(?,?,'out',?,?,?)",[$tenant,$s['id'],$p['amount'],'سند هزینه '.$id,$actor]);$adjustment=(int)db()->lastInsertId();
                }elseif($p['method']==='bank'&&!query('SELECT id FROM company_bank_accounts WHERE company_id=? AND id=? AND is_active=1 AND (branch_id IS NULL OR branch_id=?) FOR UPDATE',[$tenant,$p['account_id'],$x['branch_id']])->fetch())throw new RuntimeException('حساب پرداخت دیگر مجاز نیست.');
                query('UPDATE expense_payments SET posted_at=NOW(),cash_adjustment_id=? WHERE company_id=? AND id=?',[$adjustment,$tenant,$p['id']]);
            }
        }
        query('UPDATE expenses SET approval_status=?,reviewed_by=?,reviewed_at=NOW(),review_note=? WHERE company_id=? AND id=?',[$decision,$actor,$reason,$tenant,$id]);
        expense_event($tenant,$id,$decision,$reason);db()->commit();
    }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
}
