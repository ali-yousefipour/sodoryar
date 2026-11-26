<?php
declare(strict_types=1);

function expense_workflow_ready(): bool {
    return (int)query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('expense_policies','expense_branch_approvers','expense_mutation_requests','expense_attachments')")->fetchColumn()===4;
}
function expense_policy(int $tenant): array {
    if(!expense_workflow_ready())return ['approval_mode'=>'all','approval_threshold'=>'0'];
    return query('SELECT * FROM expense_policies WHERE company_id=?',[$tenant])->fetch()?:['approval_mode'=>'all','approval_threshold'=>'0'];
}
function expense_approval_status(array $policy,int $amount,string $mode): string {
    if(!in_array($mode,['draft','submit'],true))throw new RuntimeException('نوع ثبت هزینه معتبر نیست.');
    if($mode==='draft')return 'draft';
    return $policy['approval_mode']==='none'||($policy['approval_mode']==='threshold'&&$amount<=(int)$policy['approval_threshold'])?'approved':'pending';
}
function expense_totals(int $subtotal,mixed $extra,mixed $discount): array {
    $extra=trim(ascii_digits((string)$extra));$discount=trim(ascii_digits((string)$discount));
    $extra=$extra==='0'?0:invoice_payment_amount($extra);
    $discount=$discount==='0'?0:invoice_payment_amount($discount);
    if($extra>PHP_INT_MAX-$subtotal||$discount>=$subtotal+$extra)throw new RuntimeException('تخفیف یا هزینه جانبی از جمع هزینه بیشتر است.');
    return [$subtotal+$extra-$discount,$extra,$discount];
}
function expense_can_review(array $expense): bool {
    if(expense_manager())return true;
    if(!expense_workflow_ready()||expense_policy(tenant_id())['approval_mode']!=='branch'||(int)$expense['created_by']===(int)user()['id'])return false;
    return (bool)query('SELECT a.user_id FROM expense_branch_approvers a JOIN user_branch_assignments m ON m.company_id=a.company_id AND m.branch_id=a.branch_id AND m.user_id=a.user_id AND m.is_active=1 JOIN branches b ON b.id=a.branch_id AND b.company_id=a.company_id AND b.is_active=1 WHERE a.company_id=? AND a.branch_id=? AND a.user_id=? AND a.approval_limit>=?',
        [tenant_id(),$expense['branch_id'],user()['id'],$expense['total_amount']])->fetch();
}
function expense_policy_save(int $tenant,array $input): void {
    require_admin();if(!expense_workflow_ready())throw new RuntimeException('مهاجرت گردش هزینه ۰۲۹ لازم است.');
    $mode=(string)($input['approval_mode']??'');$threshold=trim(ascii_digits((string)($input['approval_threshold']??'0')));
    if(!in_array($mode,['none','all','threshold','branch'],true))throw new RuntimeException('سیاست تأیید معتبر نیست.');
    $threshold=$threshold==='0'?0:invoice_payment_amount($threshold);
    db()->beginTransaction();try{
        query('SELECT id FROM companies WHERE id=? FOR UPDATE',[$tenant])->fetch();
        query('INSERT INTO expense_policies(company_id,approval_mode,approval_threshold) VALUES(?,?,?) ON DUPLICATE KEY UPDATE approval_mode=VALUES(approval_mode),approval_threshold=VALUES(approval_threshold)',[$tenant,$mode,$threshold]);
        audit('expense.policy.update',$mode,$tenant);db()->commit();
    }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
}
function expense_branch_approver_save(int $tenant,array $input): void {
    require_admin();if(!expense_workflow_ready())throw new RuntimeException('مهاجرت گردش هزینه ۰۲۹ لازم است.');
    $branch=branch_require($tenant,(int)user()['id'],$input['branch_id']??null);
    $actor=filter_var($input['user_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $limit=invoice_payment_amount($input['approval_limit']??'');
    db()->beginTransaction();try{
        query('SELECT id FROM companies WHERE id=? FOR UPDATE',[$tenant])->fetch();
        if(!$actor||!query('SELECT u.id FROM users u JOIN user_branch_assignments a ON a.user_id=u.id AND a.company_id=u.company_id WHERE u.company_id=? AND u.id=? AND u.is_active=1 AND a.branch_id=? AND a.is_active=1 FOR UPDATE',[$tenant,$actor,$branch])->fetch())throw new RuntimeException('کاربر فعال عضو همین شعبه را انتخاب کنید.');
        if(isset($input['revoke']))query('DELETE FROM expense_branch_approvers WHERE company_id=? AND branch_id=? AND user_id=?',[$tenant,$branch,$actor]);
        else query('INSERT INTO expense_branch_approvers(company_id,branch_id,user_id,approval_limit) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE approval_limit=VALUES(approval_limit)',[$tenant,$branch,$actor,$limit]);
        audit('expense.approver.update',json_encode(['branch'=>$branch,'user'=>$actor,'revoked'=>isset($input['revoke'])],JSON_THROW_ON_ERROR),$tenant);db()->commit();
    }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
}

function expense_attach(int $tenant,int $id,array $file): void {
    require_permission('expenses.create');
    if(!expense_workflow_ready())throw new RuntimeException('مهاجرت پیوست هزینه ۰۲۹ لازم است.');
    $x=query('SELECT created_by FROM expenses WHERE company_id=? AND id=?',[$tenant,$id])->fetch();
    if(!$x||(int)$x['created_by']!==(int)user()['id'])throw new RuntimeException('پیوست سند دیگری مجاز نیست.');
    if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||($file['size']??0)>5242880||!is_uploaded_file($file['tmp_name']??''))throw new RuntimeException('پیوست معتبر با حداکثر حجم ۵ مگابایت انتخاب کنید.');
    $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);$extensions=['image/jpeg'=>'jpg','image/png'=>'png','application/pdf'=>'pdf'];
    if(!isset($extensions[$mime]))throw new RuntimeException('فقط JPEG، PNG یا PDF مجاز است.');
    $name=basename((string)$file['name']);if($name===''||mb_strlen($name)>180)throw new RuntimeException('نام فایل پیوست طولانی یا نامعتبر است.');
    $hash=hash_file('sha256',$file['tmp_name']);
    if(query('SELECT id FROM expense_attachments WHERE company_id=? AND expense_id=? AND content_hash=?',[$tenant,$id,$hash])->fetch())return;
    $dir=dirname(__DIR__).'/storage/expense-attachments';
    if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new RuntimeException('ذخیره پیوست ممکن نیست.');
    $stored=bin2hex(random_bytes(24)).'.'.$extensions[$mime];$path=$dir.'/'.$stored;
    if(!move_uploaded_file($file['tmp_name'],$path))throw new RuntimeException('ذخیره پیوست ناموفق بود.');
    try{
        query('INSERT INTO expense_attachments(company_id,expense_id,original_name,stored_name,content_hash,mime_type,size_bytes,created_by) VALUES(?,?,?,?,?,?,?,?)',[$tenant,$id,$name,$stored,$hash,$mime,$file['size'],user()['id']]);
        expense_event($tenant,$id,'attachment.added');
    }catch(Throwable $ex){unlink($path);throw $ex;}
}
function expense_serve_attachment(int $tenant,int $id): never {
    require_permission('expenses.view');[$scope,$args]=expense_scope($tenant);$args[]=$id;
    $row=query('SELECT a.* FROM expense_attachments a JOIN expenses e ON e.company_id=a.company_id AND e.id=a.expense_id WHERE '.$scope.' AND a.id=?',$args)->fetch();
    if(!$row||!preg_match('/^[a-f0-9]{48}\.(jpg|png|pdf)$/D',$row['stored_name'])){http_response_code(404);throw new RuntimeException('پیوست موجود یا مجاز نیست.');}
    $path=dirname(__DIR__).'/storage/expense-attachments/'.$row['stored_name'];
    if(!is_file($path)){http_response_code(404);throw new RuntimeException('فایل پیوست در دسترس نیست.');}
    header('Content-Type: '.$row['mime_type']);header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');
    header('Content-Disposition: attachment; filename="expense-attachment.'.pathinfo($path,PATHINFO_EXTENSION).'"');readfile($path);exit;
}

function render_expense_policy_settings(int $tenant,array $branches): void {
    if(!expense_manager()||!expense_workflow_ready())return;$policy=expense_policy($tenant);
    ?>
    <details class="card"><summary>سیاست تأیید هزینه‌ها و مسئولان شعب</summary>
    <form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="policy">
    <label>سیاست تأیید<select name="approval_mode"><?php foreach(['all'=>'تأیید همه هزینه‌ها','none'=>'ثبت قطعی بدون تأیید','threshold'=>'تأیید مبالغ بالاتر از سقف','branch'=>'تأیید توسط مسئول مجاز شعبه'] as $value=>$label):?><option value="<?=e($value)?>" <?=$policy['approval_mode']===$value?'selected':''?>><?=e($label)?></option><?php endforeach;?></select></label>
    <label>سقف ثبت بدون تأیید (تومان)<input name="approval_threshold" inputmode="numeric" value="<?=e(money($policy['approval_threshold']))?>" required></label><button>ذخیره سیاست تأیید</button></form>
    <p>در حالت مسئول شعبه، تأییدکننده باید عضو فعال همان شعبه باشد و مبلغ سند از سقف مجوز او بیشتر نباشد. تأیید سند خود توسط مسئول شعبه مجاز نیست.</p>
    <?php $people=query('SELECT id,full_name FROM users WHERE company_id=? AND is_active=1 ORDER BY full_name',[$tenant])->fetchAll(); ?>
    <form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="approver"><label>شعبه<select name="branch_id"><?php foreach($branches as $b):?><option value="<?=e($b['id'])?>"><?=e($b['name'])?></option><?php endforeach;?></select></label>
    <label>مسئول شعبه<select name="user_id"><?php foreach($people as $p):?><option value="<?=e($p['id'])?>"><?=e($p['full_name'])?></option><?php endforeach;?></select></label><label>سقف تأیید (تومان)<input name="approval_limit" inputmode="numeric" required></label><button>ذخیره سقف مسئول شعبه</button><button name="revoke" value="1">لغو مجوز تأیید شعبه</button></form>
    <div class="table-wrap"><table><thead><tr><th>شعبه</th><th>مسئول</th><th>سقف تأیید</th></tr></thead><tbody><?php foreach(query('SELECT a.approval_limit,b.name,u.full_name FROM expense_branch_approvers a JOIN branches b ON b.id=a.branch_id AND b.company_id=a.company_id JOIN users u ON u.id=a.user_id AND u.company_id=a.company_id WHERE a.company_id=?',[$tenant])->fetchAll() as $a):?><tr><td><?=e($a['name'])?></td><td><?=e($a['full_name'])?></td><td><?=money_toman($a['approval_limit'])?></td></tr><?php endforeach;?></tbody></table></div></details>
    <?php
}
