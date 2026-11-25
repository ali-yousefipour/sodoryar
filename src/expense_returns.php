<?php
declare(strict_types=1);

function expense_returns_ready(): bool {
    return (int)query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='expense_returns'")->fetchColumn()===1;
}

/** Refunds are separate immutable receipts, recorded only after actual receipt of money. */
function expense_return(int $id,array $input): void {
    require_admin();
    if(!expense_extensions_ready()||!expense_returns_ready())throw new RuntimeException('مهاجرت برگشت هزینه ۰۲۸ لازم است.');
    $tenant=tenant_id();$actor=(int)user()['id'];
    $kind=$input['kind']??'';$target=filter_var($input['target']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    $key=(string)($input['request_key']??'');$amount=invoice_payment_amount($input['amount']??'');
    $reason=trim((string)($input['reason']??''));$reference=trim((string)($input['reference']??''));
    $shift=filter_var($input['cash_shift_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:null;
    if(($input['refund_confirmed']??null)!=='1'||!in_array($kind,['payment','settlement'],true)||!$target
        ||!preg_match('/^[a-f0-9]{64}$/D',$key)||mb_strlen($reason)<5||mb_strlen($reason)>500
        ||$reference===''||mb_strlen($reference)>120)throw new RuntimeException('مبلغ، مرجع، دلیل و تأیید دریافت واقعی وجه لازم است.');
    $hash=hash('sha256',json_encode([$id,$actor,$kind,$target,$amount,$shift,$reason,$reference],JSON_THROW_ON_ERROR));
    $table=$kind==='payment'?'expense_payments':'expense_settlements';
    $column=$kind==='payment'?'payment_id':'settlement_id';
    db()->beginTransaction();
    try {
        // Same company lock order as creation, approval, settlement and cancellation.
        query('SELECT id FROM companies WHERE id=? FOR UPDATE',[$tenant])->fetch();
        $x=query('SELECT * FROM expenses WHERE company_id=? AND id=? FOR UPDATE',[$tenant,$id])->fetch();
        if(!$x)throw new RuntimeException('سند موجود یا مجاز نیست.');
        branch_require($tenant,$actor,(int)$x['branch_id']);
        $old=query('SELECT request_hash FROM expense_returns WHERE company_id=? AND request_key=?',[$tenant,$key])->fetch();
        if($old){if(!hash_equals($old['request_hash'],$hash))throw new RuntimeException('درخواست تکراری با اطلاعات متفاوت است.');db()->commit();return;}
        if($x['approval_status']!=='approved')throw new RuntimeException('برگشت فقط برای سند تأییدشده مجاز است.');
        $row=query('SELECT * FROM '.$table.' WHERE company_id=? AND expense_id=? AND id=? FOR UPDATE',[$tenant,$id,$target])->fetch();
        if(!$row||!in_array($row['method'],['cash','bank'],true)||($kind==='payment'&&$row['posted_at']===null))throw new RuntimeException('پرداخت قطعی قابل برگشت نیست.');
        $returned=(int)query('SELECT COALESCE(SUM(amount),0) FROM expense_returns WHERE company_id=? AND '.$column.'=?',[$tenant,$target])->fetchColumn();
        if($amount>(int)$row['amount']-$returned)throw new RuntimeException('مبلغ برگشت بیشتر از مانده پرداخت است.');
        $date=date('Y-m-d');accounting_assert_write_open($tenant,$date);
        $adjustment=null;$account=null;
        if($row['method']==='cash'){
            if(!$shift)throw new RuntimeException('صندوق دریافت‌کننده را انتخاب کنید.');
            // A closed original shift stays closed; receive into an owned open shift of the same branch.
            $adjustment=expense_cash_entry($tenant,(int)$x['branch_id'],$shift,$amount,'in','دریافت برگشت هزینه '.$id,$actor);
        }else{
            if($shift!==null)throw new RuntimeException('برگشت بانکی به صندوق مجاز نیست.');
            $account=(int)$row['account_id'];
            if(!query('SELECT id FROM company_bank_accounts WHERE company_id=? AND id=? AND is_active=1 AND (branch_id IS NULL OR branch_id=?) FOR UPDATE',[$tenant,$account,$x['branch_id']])->fetch())throw new RuntimeException('حساب اولیه برای دریافت برگشت مجاز نیست.');
        }
        query('INSERT INTO expense_returns(company_id,expense_id,payment_id,settlement_id,amount,method,account_id,cash_shift_id,cash_adjustment_id,actor_id,return_date,reference,reason,request_key,request_hash) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',
            [$tenant,$id,$kind==='payment'?$target:null,$kind==='settlement'?$target:null,$amount,$row['method'],$account,$shift,$adjustment,$actor,$date,$reference,$reason,$key,$hash]);
        expense_event($tenant,$id,$kind.'.returned',$reason);
        db()->commit();
    }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
}

/** Called only after loading the document through expense_scope(). */
function render_expense_returns(int $tenant,array $x): void {
    if(!expense_returns_ready())return;
    $id=(int)$x['id'];
    $returns=query('SELECT * FROM expense_returns WHERE company_id=? AND expense_id=? ORDER BY id',[$tenant,$id])->fetchAll();
    ?>
    <section class="card"><h2>برگشت واقعی وجه</h2>
    <?php if(!$returns):?><p>دریافت برگشت وجه برای این سند ثبت نشده است.</p><?php else:?>
    <div class="table-wrap"><table><thead><tr><th>شماره</th><th>تاریخ</th><th>نوع سند اولیه</th><th>روش</th><th>مبلغ</th><th>مرجع</th><th>دلیل</th></tr></thead><tbody>
    <?php foreach($returns as $r):?><tr><td><?=fa_digits($r['id'])?></td><td><?=e(jalali_datetime($r['return_date'],true))?></td><td><?=$r['settlement_id']!==null?'تسویه کارکنان':'پرداخت هزینه'?> <?=fa_digits($r['settlement_id']??$r['payment_id'])?></td><td><?=e(expense_method($r['method']))?></td><td><?=money_toman($r['amount'])?></td><td><?=e($r['reference'])?></td><td><?=e($r['reason'])?></td></tr><?php endforeach;?>
    </tbody></table></div><?php endif;?>
    <?php if(expense_manager()&&$x['approval_status']==='approved'):
        $cash=query("SELECT s.id,r.name FROM cash_shifts s JOIN cash_registers r ON r.id=s.register_id AND r.company_id=s.company_id WHERE s.company_id=? AND s.operator_id=? AND s.status='open' AND r.is_active=1 AND r.branch_id=? ORDER BY r.name",[$tenant,(int)user()['id'],$x['branch_id']])->fetchAll();
        foreach(['payment'=>'expense_payments','settlement'=>'expense_settlements'] as $kind=>$table){
            $condition=$kind==='payment'?" AND posted_at IS NOT NULL AND method IN ('cash','bank')":'';
            foreach(query('SELECT * FROM '.$table.' WHERE company_id=? AND expense_id=?'.$condition.' ORDER BY id',[$tenant,$id])->fetchAll() as $target){
                $returned=0;foreach($returns as $r)if((int)$r[$kind.'_id']===(int)$target['id'])$returned+=(int)$r['amount'];
                $remaining=(int)$target['amount']-$returned;if($remaining<=0)continue;
                ?>
                <details><summary>دریافت برگشت <?=$kind==='settlement'?'تسویه':'پرداخت'?> <?=fa_digits($target['id'])?> — مانده <?=money_toman($remaining)?></summary>
                <p>سند اولیه حفظ می‌شود. مبلغ دریافتی، مانده قابل برگشت را کاهش می‌دهد. برگشت تسویه، بدهی شرکت به کارمند را دوباره برقرار می‌کند.</p>
                <form method="post" action="?page=expenses&amp;detail=<?=e($id)?>" class="form-grid"><?=csrf_field()?>
                <input type="hidden" name="action" value="return"><input type="hidden" name="id" value="<?=e($id)?>"><input type="hidden" name="kind" value="<?=e($kind)?>"><input type="hidden" name="target" value="<?=e($target['id'])?>"><input type="hidden" name="request_key" value="<?=e(bin2hex(random_bytes(32)))?>">
                <label>مبلغ دریافتی (تومان)<input name="amount" inputmode="numeric" required value="<?=e(money($remaining))?>"></label>
                <?php if($target['method']==='cash'):?><label>صندوق دریافت‌کننده در همین شعبه<select name="cash_shift_id" required><option value="">انتخاب کنید</option><?php foreach($cash as $c):?><option value="<?=e($c['id'])?>"><?=e($c['name'])?></option><?php endforeach;?></select></label>
                <?php else:?><p>وجه باید به همان حساب بانکی اولیه برگشته باشد.</p><?php endif;?>
                <label>شماره رسید / مرجع بانکی<input name="reference" required maxlength="120"></label><label>دلیل برگشت<textarea name="reason" required minlength="5" maxlength="500"></textarea></label>
                <label><input type="checkbox" name="refund_confirmed" value="1" required> دریافت واقعی وجه را تأیید می‌کنم.</label><button type="submit">ثبت دریافت برگشت وجه</button>
                </form></details>
                <?php
            }
        }
    endif;?></section>
    <?php
}
