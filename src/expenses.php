<?php
declare(strict_types=1);

function expenses_ready(): bool {return database_table_exists('expenses') && database_table_exists('expense_payments');}
function expense_require_permission(string $action): void {
    if(!can('expenses.'.$action))throw new RuntimeException('مجوز عملیات هزینه را ندارید.');
}
function expense_scope_sql(int $tenant,string $alias='e'): string {
    if(!in_array($alias,['e','expenses'],true))throw new LogicException('Invalid expense alias');
    if($tenant!==tenant_id())throw new RuntimeException('شرکت معتبر نیست.');
    $scope=document_scope()==='own' && !can('expenses.approve')?' AND '.$alias.'.created_by='.(int)user()['id']:'';
    $branchScope=report_branch_sql($tenant,'i.branch_id');
    return $scope.str_replace('i.branch_id',$alias.'.branch_id',$branchScope);
}
function expense_require_document(int $tenant,int $id,bool $lock=false): array {
    expense_require_permission('view');
    if($lock&&!db()->inTransaction())throw new LogicException('Expense lock requires transaction');
    $row=query('SELECT e.* FROM expenses e WHERE e.company_id=? AND e.id=?'.expense_scope_sql($tenant).($lock?' FOR UPDATE':''),[$tenant,$id])->fetch();
    if(!$row)throw new RuntimeException('سند هزینه موجود یا مجاز نیست.');
    return $row;
}
function expense_date(mixed $value): string {
    $date=ascii_digits(trim((string)$value));$day=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if(!$day||$day->format('Y-m-d')!==$date||$date>date('Y-m-d'))throw new RuntimeException('تاریخ هزینه یا پرداخت معتبر نیست.');
    return $date;
}
function expense_text(mixed $value,int $max,bool $required=false): string {
    $text=trim((string)$value);
    if(($required&&$text==='')||mb_strlen($text)>$max)throw new RuntimeException('عنوان یا توضیحات هزینه معتبر نیست.');
    return $text;
}
function expense_items_calculate(array $items,mixed $extra='0',mixed $discount='0'): array {
    if(!$items||count($items)>200)throw new RuntimeException('بین یک تا ۲۰۰ قلم هزینه وارد کنید.');
    $total=0;$clean=[];
    foreach($items as $item){
        if(!is_array($item))throw new RuntimeException('قلم هزینه معتبر نیست.');
        $quantity=ascii_digits(trim((string)($item['quantity']??'')));
        if(!preg_match('/^([0-9]{1,6})(?:\.([0-9]{1,3}))?$/D',$quantity,$m))throw new RuntimeException('تعداد با حداکثر سه رقم اعشار وارد شود.');
        $milli=(int)$m[1]*1000+(int)str_pad($m[2]??'',3,'0');
        $price=cashbox_amount($item['unit_price']??'');
        if($milli<=0||$price>1000000000000||$milli>intdiv(PHP_INT_MAX-500,$price))throw new RuntimeException('تعداد یا قیمت خارج از محدوده است.');
        $line=intdiv($milli*$price+500,1000);
        if($line<=0||$line>1000000000000000-$total)throw new RuntimeException('جمع اقلام خارج از محدوده است.');
        $total+=$line;
        $clean[]=['title'=>expense_text($item['title']??'',180,true),'description'=>expense_text($item['description']??'',500),'quantity_milli'=>$milli,'unit'=>expense_text($item['unit']??'',40),'unit_price'=>$price,'line_total'=>$line];
    }
    $extra=cashbox_amount($extra,true);$discount=cashbox_amount($discount,true);
    if($extra>1000000000000000-$total||$discount>=$total+$extra)throw new RuntimeException('تخفیف یا هزینه جانبی معتبر نیست.');
    return ['items'=>$clean,'subtotal'=>$total,'extra_amount'=>$extra,'discount_amount'=>$discount,'total_amount'=>$total+$extra-$discount];
}
function expense_event(int $tenant,int $id,int $actor,string $action,string $reason=''): void {
    query('INSERT INTO expense_events(company_id,expense_id,actor_id,action,reason) VALUES(?,?,?,?,?)',[$tenant,$id,$actor,$action,$reason]);
    audit('expense.'.$action,json_encode(['expense_id'=>$id,'reason'=>$reason],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$tenant);
}
/** Must be called within the document transaction; every cash outflow has a ledger row. */
function expense_cash_movement(int $tenant,int $branch,int $actor,int $shift,int $amount,string $direction,string $reason): int {
    require_permission('cashbox.adjust');
    $row=query('SELECT s.operator_id,s.status,s.opening_cash,r.branch_id,r.is_active FROM cash_shifts s JOIN cash_registers r ON r.id=s.register_id AND r.company_id=s.company_id WHERE s.company_id=? AND s.id=? FOR UPDATE',[$tenant,$shift])->fetch();
    if(!$row||$row['status']!=='open'||!(int)$row['is_active']||(int)$row['branch_id']!==$branch||((int)$row['operator_id']!==$actor&&!can('cashbox.manage')))throw new RuntimeException('صندوق باز و مجاز همین شعبه را انتخاب کنید.');
    branch_require($tenant,$actor,$branch);
    if($direction==='out'){
        $totals=cashbox_shift_totals($tenant,$shift);
        if($amount>(int)$row['opening_cash']+$totals['cash']+$totals['incoming']-$totals['outgoing'])throw new RuntimeException('موجودی نقدی صندوق کافی نیست.');
    }
    query('INSERT INTO cash_shift_adjustments(company_id,shift_id,direction,amount,reason,created_by) VALUES(?,?,?,?,?,?)',[$tenant,$shift,$direction,$amount,$reason,$actor]);
    return (int)db()->lastInsertId();
}
function expense_bank_require(int $tenant,int $branch,int $account): void {
    expense_require_permission('bank');
    if(!query('SELECT id FROM company_bank_accounts WHERE company_id=? AND id=? AND is_active=1 AND (branch_id IS NULL OR branch_id=?) FOR UPDATE',[$tenant,$account,$branch])->fetch())throw new RuntimeException('حساب بانکی مجاز شرکت یا شعبه را انتخاب کنید.');
}
function expense_post_payments(int $tenant,array $expense,int $actor): void {
    $id=(int)$expense['id'];$branch=(int)$expense['branch_id'];
    foreach(query('SELECT * FROM expense_payments WHERE company_id=? AND expense_id=? AND posted_at IS NULL ORDER BY id FOR UPDATE',[$tenant,$id])->fetchAll() as $payment){
        accounting_assert_write_open($tenant,$payment['paid_date']);
        $adjustment=null;
        if($payment['method']==='cash')$adjustment=expense_cash_movement($tenant,$branch,$actor,(int)$payment['cash_shift_id'],(int)$payment['amount'],'out','پرداخت هزینه '.$expense['document_number']);
        elseif($payment['method']==='bank'){
            expense_bank_require($tenant,$branch,(int)$payment['bank_account_id']);
            query("INSERT INTO expense_bank_movements(company_id,expense_id,payment_id,bank_account_id,direction,amount,paid_date,reference,created_by) VALUES(?,?,?,?,'out',?,?,?,?)",[$tenant,$id,$payment['id'],$payment['bank_account_id'],$payment['amount'],$payment['paid_date'],$payment['tracking_number'],$actor]);
        }
        query('UPDATE expense_payments SET posted_at=NOW(),cash_adjustment_id=? WHERE company_id=? AND id=?',[$adjustment,$tenant,$payment['id']]);
    }
}
function expense_save(int $tenant,int $actor,array $input,string $requestKey): int {
    expense_require_permission('create');
    if(!expenses_ready()||!branches_ready())throw new RuntimeException('ابتدا مهاجرت ۰۳۱ و وابستگی‌های آن را نصب کنید.');
    if($tenant!==tenant_id()||$actor!==(int)user()['id']||!preg_match('/^[a-f0-9]{64}$/D',$requestKey))throw new RuntimeException('درخواست ثبت هزینه معتبر نیست.');
    expense_ensure_defaults($tenant);
    $branch=branch_require($tenant,$actor,$input['branch_id']??null);
    $date=expense_date($input['expense_date']??'');
    $money=expense_items_calculate($input['items']??[],$input['extra_amount']??'0',$input['discount_amount']??'0');
    $title=expense_text($input['title']??'',180,true);$description=expense_text($input['description']??'',2000);$notes=expense_text($input['notes']??'',2000);
    $mode=(string)($input['mode']??'submit');if(!in_array($mode,['draft','submit'],true))throw new RuntimeException('وضعیت ثبت معتبر نیست.');
    $category=(int)($input['category_id']??0);$payments=$input['payments']??[];
    if(!is_array($payments)||count($payments)>50)throw new RuntimeException('حداکثر ۵۰ پرداخت قابل ثبت است.');
    $clean=[];$sum=0;
    foreach($payments as $p){
        if(!is_array($p)||!in_array($p['method']??'', ['cash','bank','personal'],true))throw new RuntimeException('روش پرداخت معتبر نیست.');
        $amount=cashbox_amount($p['amount']??'');if($amount>$money['total_amount']-$sum)throw new RuntimeException('جمع پرداخت بیشتر از مبلغ هزینه است.');$sum+=$amount;
        $clean[]=['method'=>$p['method'],'amount'=>$amount,'cash_shift_id'=>$p['method']==='cash'?(int)($p['cash_shift_id']??0):null,'bank_account_id'=>$p['method']==='bank'?(int)($p['bank_account_id']??0):null,'paid_date'=>expense_date($p['paid_date']??$date),'tracking_number'=>expense_text($p['tracking_number']??'',120)];
    }
    $id=(int)($input['id']??0);$digest=hash('sha256',json_encode([$id,$input['version']??0,$branch,$date,$category,$title,$description,$notes,$money,$clean,$mode],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    db()->beginTransaction();
    try{
        query('SELECT id FROM companies WHERE id=? FOR UPDATE',[$tenant])->fetch();
        $prior=query('SELECT expense_id id,request_digest FROM expense_requests WHERE company_id=? AND request_key=?',[$tenant,$requestKey])->fetch();
        if($prior){$original=expense_require_document($tenant,(int)$prior['id']);if((int)$original['created_by']!==$actor)throw new RuntimeException('درخواست هزینه متعلق به کاربر دیگری است.');if(!hash_equals($prior['request_digest'],$digest))throw new RuntimeException('شناسه درخواست قبلاً برای سند دیگری مصرف شده است.');db()->commit();return (int)$prior['id'];}
        if(!query('SELECT id FROM expense_categories WHERE company_id=? AND id=? AND is_active=1',[$tenant,$category])->fetch())throw new RuntimeException('دسته‌بندی فعال هزینه را انتخاب کنید.');
        $policy=expense_policy($tenant);
        $approval=$mode==='draft'?'draft':(($policy['approval_mode']==='all'||($policy['approval_mode']==='threshold'&&$money['total_amount']>(int)$policy['approval_threshold']))?'pending':'approved');
        if($mode!=='draft')accounting_assert_write_open($tenant,$date);
        if($id){
            $old=expense_require_document($tenant,$id,true);
            if($old['approval_status']!=='draft'||(int)$old['created_by']!==$actor||(int)$old['version']!==(int)($input['version']??0))throw new RuntimeException('پیش‌نویس تغییر کرده یا قابل ویرایش نیست.');
            query('UPDATE expenses SET branch_id=?,expense_date=?,category_id=?,title=?,description=?,notes=?,subtotal=?,extra_amount=?,discount_amount=?,total_amount=?,approval_status=?,request_key=?,request_digest=?,version=version+1 WHERE company_id=? AND id=?',[$branch,$date,$category,$title,$description,$notes,$money['subtotal'],$money['extra_amount'],$money['discount_amount'],$money['total_amount'],$approval,$requestKey,$digest,$tenant,$id]);
            query('DELETE FROM expense_payments WHERE company_id=? AND expense_id=?',[$tenant,$id]);query('DELETE FROM expense_items WHERE company_id=? AND expense_id=?',[$tenant,$id]);
        }else{
            $number='EX-'.strtoupper(bin2hex(random_bytes(8)));
            query('INSERT INTO expenses(company_id,branch_id,created_by,document_number,expense_date,category_id,title,description,notes,subtotal,extra_amount,discount_amount,total_amount,approval_status,request_key,request_digest) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$tenant,$branch,$actor,$number,$date,$category,$title,$description,$notes,$money['subtotal'],$money['extra_amount'],$money['discount_amount'],$money['total_amount'],$approval,$requestKey,$digest]);$id=(int)db()->lastInsertId();
        }
        foreach($money['items'] as $item)query('INSERT INTO expense_items(company_id,expense_id,title,description,quantity_milli,unit,unit_price,line_total) VALUES(?,?,?,?,?,?,?,?)',[$tenant,$id,$item['title'],$item['description'],$item['quantity_milli'],$item['unit'],$item['unit_price'],$item['line_total']]);
        foreach($clean as $p){
            if($p['method']==='cash'){
                if(!query('SELECT s.id FROM cash_shifts s JOIN cash_registers r ON r.id=s.register_id AND r.company_id=s.company_id WHERE s.company_id=? AND s.id=? AND r.branch_id=? AND r.is_active=1 AND s.status=\'open\' AND (s.operator_id=? OR ?=1)',[$tenant,$p['cash_shift_id'],$branch,$actor,can('cashbox.manage')?1:0])->fetch())throw new RuntimeException('صندوق انتخاب‌شده معتبر نیست.');
            }elseif($p['method']==='bank')expense_bank_require($tenant,$branch,(int)$p['bank_account_id']);
            query('INSERT INTO expense_payments(company_id,expense_id,method,amount,cash_shift_id,bank_account_id,paid_date,tracking_number) VALUES(?,?,?,?,?,?,?,?)',[$tenant,$id,$p['method'],$p['amount'],$p['cash_shift_id'],$p['bank_account_id'],$p['paid_date'],$p['tracking_number']]);
        }
        $expense=expense_require_document($tenant,$id,true);
        if($approval==='approved')expense_post_payments($tenant,$expense,$actor);
        query('INSERT INTO expense_requests(company_id,request_key,request_digest,expense_id) VALUES(?,?,?,?)',[$tenant,$requestKey,$digest,$id]);
        expense_event($tenant,$id,$actor,(int)($input['id']??0)>0?'draft.edit':'create',$approval);
        db()->commit();return $id;
    }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
}
function expense_review(int $tenant,int $id,int $actor,string $decision,string $reason): void {
    expense_require_permission('approve');$reason=expense_text($reason,1000,true);
    if(!in_array($decision,['approved','rejected'],true))throw new RuntimeException('تصمیم معتبر نیست.');
    db()->beginTransaction();try{
        $e=expense_require_document($tenant,$id,true);
        if($e['approval_status']!=='pending'||(int)$e['created_by']===$actor)throw new RuntimeException('هزینه منتظر تأیید کاربر دیگری را انتخاب کنید.');
        accounting_assert_write_open($tenant,$e['expense_date']);
        if($decision==='approved')expense_post_payments($tenant,$e,$actor);
        query('UPDATE expenses SET approval_status=?,approved_by=?,approved_at=NOW(),version=version+1 WHERE company_id=? AND id=?',[$decision,$actor,$tenant,$id]);
        expense_event($tenant,$id,$actor,$decision,$reason);db()->commit();
    }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
}
function expense_reimburse(int $tenant,int $actor,array $input,string $requestKey): int {
    expense_require_permission('manage');$id=(int)($input['expense_id']??0);$payment=(int)($input['payment_id']??0);$amount=cashbox_amount($input['amount']??'');$date=expense_date($input['paid_date']??'');
    $method=$input['method']??'';if(!in_array($method,['cash','bank'],true)||!preg_match('/^[a-f0-9]{64}$/D',$requestKey))throw new RuntimeException('درخواست بازپرداخت معتبر نیست.');
    $shift=$method==='cash'?(int)($input['cash_shift_id']??0):null;$account=$method==='bank'?(int)($input['bank_account_id']??0):null;$tracking=expense_text($input['tracking_number']??'',120);
    db()->beginTransaction();try{
        $e=expense_require_document($tenant,$id,true);
        $previous=query('SELECT * FROM employee_reimbursements WHERE company_id=? AND request_key=?',[$tenant,$requestKey])->fetch();
        if($previous){if((int)$previous['expense_id']!==$id||(int)$previous['payment_id']!==$payment||(int)$previous['amount']!==$amount||$previous['method']!==$method||(int)$previous['cash_shift_id']!==(int)$shift||(int)$previous['bank_account_id']!==(int)$account||$previous['paid_date']!==$date||($previous['tracking_number']??'')!==$tracking)throw new RuntimeException('درخواست بازپرداخت با اطلاعات قبلی متفاوت است.');db()->commit();return (int)$previous['id'];}
        if($e['approval_status']!=='approved')throw new RuntimeException('هزینه تأییدشده را انتخاب کنید.');
        $p=query("SELECT p.amount-COALESCE((SELECT SUM(r.amount) FROM employee_reimbursements r WHERE r.company_id=p.company_id AND r.payment_id=p.id),0) remaining FROM expense_payments p WHERE p.company_id=? AND p.expense_id=? AND p.id=? AND p.method='personal' AND p.posted_at IS NOT NULL FOR UPDATE",[$tenant,$id,$payment])->fetch();
        if(!$p||$amount>(int)$p['remaining'])throw new RuntimeException('مبلغ بازپرداخت بیشتر از بدهی به کارمند است.');
        accounting_assert_write_open($tenant,$date);$adjustment=null;
        if($method==='cash')$adjustment=expense_cash_movement($tenant,(int)$e['branch_id'],$actor,(int)$shift,$amount,'out','بازپرداخت کارمند بابت '.$e['document_number']);else expense_bank_require($tenant,(int)$e['branch_id'],(int)$account);
        query('INSERT INTO employee_reimbursements(company_id,expense_id,payment_id,amount,method,cash_shift_id,bank_account_id,cash_adjustment_id,paid_date,tracking_number,created_by,request_key) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)',[$tenant,$id,$payment,$amount,$method,$shift,$account,$adjustment,$date,$tracking,$actor,$requestKey]);$rid=(int)db()->lastInsertId();
        if($method==='bank')query("INSERT INTO expense_bank_movements(company_id,expense_id,reimbursement_id,bank_account_id,direction,amount,paid_date,reference,created_by) VALUES(?,?,?,?,'out',?,?,?,?)",[$tenant,$id,$rid,$account,$amount,$date,$tracking,$actor]);
        expense_event($tenant,$id,$actor,'reimburse',(string)$rid);db()->commit();return $rid;
    }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
}
function expense_void(int $tenant,int $id,int $actor,string $reason): void {
    expense_require_permission('manage');$reason=expense_text($reason,1000,true);
    db()->beginTransaction();try{
        $e=expense_require_document($tenant,$id,true);
        if($e['approval_status']==='voided'){db()->commit();return;}
        accounting_assert_write_open($tenant,$e['expense_date']);accounting_assert_write_open($tenant,date('Y-m-d'));
        if(query('SELECT id FROM employee_reimbursements WHERE company_id=? AND expense_id=? LIMIT 1',[$tenant,$id])->fetch())throw new RuntimeException('این سند بازپرداخت کارمند دارد؛ ابتدا فرایند برگشت بازپرداخت لازم است.');
        foreach(query('SELECT * FROM expense_payments WHERE company_id=? AND expense_id=? AND posted_at IS NOT NULL ORDER BY cash_shift_id,id FOR UPDATE',[$tenant,$id])->fetchAll() as $p){
            if($p['method']==='cash')expense_cash_movement($tenant,(int)$e['branch_id'],$actor,(int)$p['cash_shift_id'],(int)$p['amount'],'in','برگشت هزینه '.$e['document_number']);
            elseif($p['method']==='bank')query("INSERT INTO expense_bank_movements(company_id,expense_id,payment_id,bank_account_id,direction,amount,paid_date,reference,created_by) VALUES(?,?,?,?,'in',?,?,?,?)",[$tenant,$id,$p['id'],$p['bank_account_id'],$p['amount'],date('Y-m-d'),'برگشت: '.$reason,$actor]);
        }
        query("UPDATE expenses SET approval_status='voided',voided_at=NOW(),version=version+1 WHERE company_id=? AND id=?",[$tenant,$id]);expense_event($tenant,$id,$actor,'void',$reason);db()->commit();
    }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
}
function expense_request_change(int $tenant,int $id,int $actor,string $reason): void {
    $reason=expense_text($reason,1000,true);db()->beginTransaction();try{expense_require_document($tenant,$id,true);expense_event($tenant,$id,$actor,'change.request',$reason);db()->commit();}catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
}
function expense_attachment_directory(): string {return dirname(__DIR__).'/storage/expense-attachments';}
function expense_attach(int $tenant,int $id,int $actor,array $file): void {
    $e=expense_require_document($tenant,$id);
    if((int)$e['created_by']!==$actor&&!can('expenses.manage'))throw new RuntimeException('پیوست این سند مجاز نیست.');
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||($file['size']??0)>5242880||!is_uploaded_file($file['tmp_name']??''))throw new RuntimeException('پیوست معتبر با حداکثر حجم ۵ مگابایت انتخاب کنید.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$extensions=['image/jpeg'=>'jpg','image/png'=>'png','application/pdf'=>'pdf'];
    if(!isset($extensions[$mime]))throw new RuntimeException('فقط تصویر JPEG، PNG یا PDF مجاز است.');
    $dir=expense_attachment_directory();if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('ذخیره پیوست ممکن نیست.');
    $name=bin2hex(random_bytes(24)).'.'.$extensions[$mime];$path=$dir.'/'.$name;
    if(!move_uploaded_file($file['tmp_name'],$path))throw new RuntimeException('ذخیره پیوست ناموفق بود.');
    try{query('INSERT INTO expense_attachments(company_id,expense_id,original_name,stored_name,mime_type,size_bytes,created_by) VALUES(?,?,?,?,?,?,?)',[$tenant,$id,expense_text(basename($file['name']),180,true),$name,$mime,$file['size'],$actor]);audit('expense.attachment',(string)$id,$tenant);}catch(Throwable $ex){unlink($path);throw $ex;}
}
function expense_serve_attachment(int $tenant,int $id): never {
    $row=query('SELECT * FROM expense_attachments WHERE company_id=? AND id=?',[$tenant,$id])->fetch();
    if(!$row)throw new RuntimeException('پیوست موجود نیست.');expense_require_document($tenant,(int)$row['expense_id']);
    if(!preg_match('/^[a-f0-9]{48}\.(jpg|png|pdf)$/D',$row['stored_name']))throw new RuntimeException('پیوست معتبر نیست.');
    $path=expense_attachment_directory().'/'.$row['stored_name'];if(!is_file($path))throw new RuntimeException('فایل پیوست در دسترس نیست.');
    header('Content-Type: '.$row['mime_type']);header('X-Content-Type-Options: nosniff');header('Content-Disposition: attachment; filename="expense-attachment.'.strtolower(pathinfo($path,PATHINFO_EXTENSION)).'"');header('Cache-Control: private, no-store');readfile($path);exit;
}

function expense_add_payment(int $tenant,int $id,int $actor,array $input,string $key): int {
    expense_require_permission('create');
    $method=$input['method']??'';$amount=cashbox_amount($input['amount']??'');$date=expense_date($input['paid_date']??'');$tracking=expense_text($input['tracking_number']??'',120);
    if(!in_array($method,['cash','bank','personal'],true)||!preg_match('/^[a-f0-9]{64}$/D',$key))throw new RuntimeException('درخواست پرداخت معتبر نیست.');
    $shift=$method==='cash'?(int)($input['cash_shift_id']??0):null;$account=$method==='bank'?(int)($input['bank_account_id']??0):null;
    $digest=hash('sha256',json_encode(['pay',$id,$actor,$method,$amount,$date,$tracking,$shift,$account],JSON_THROW_ON_ERROR));
    db()->beginTransaction();try{
        $e=expense_require_document($tenant,$id,true);
        $prior=query('SELECT expense_id,request_digest FROM expense_requests WHERE company_id=? AND request_key=?',[$tenant,$key])->fetch();
        if($prior){if((int)$prior['expense_id']!==$id||!hash_equals($prior['request_digest'],$digest))throw new RuntimeException('درخواست پرداخت تکراری با اطلاعات متفاوت است.');db()->commit();return $id;}
        if($e['approval_status']!=='approved')throw new RuntimeException('فقط هزینه تأییدشده قابل پرداخت است.');
        $paid=(int)query('SELECT COALESCE(SUM(amount),0) FROM expense_payments WHERE company_id=? AND expense_id=?',[$tenant,$id])->fetchColumn();
        if($amount>(int)$e['total_amount']-$paid)throw new RuntimeException('پرداخت بیشتر از مانده هزینه است.');
        if($method==='personal'&&(int)$e['created_by']!==$actor)throw new RuntimeException('پرداخت شخصی فقط توسط صاحب سند ثبت می‌شود.');
        query('INSERT INTO expense_payments(company_id,expense_id,method,amount,cash_shift_id,bank_account_id,paid_date,tracking_number) VALUES(?,?,?,?,?,?,?,?)',[$tenant,$id,$method,$amount,$shift,$account,$date,$tracking]);
        expense_post_payments($tenant,$e,$actor);
        query('INSERT INTO expense_requests(company_id,request_key,request_digest,expense_id) VALUES(?,?,?,?)',[$tenant,$key,$digest,$id]);expense_event($tenant,$id,$actor,'pay',(string)$amount);db()->commit();return $id;
    }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
}
