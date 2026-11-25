<?php
declare(strict_types=1);
function pos_settlements_ready(): bool {
    return database_table_exists('pos_bank_settlements') && database_table_exists('pos_bank_settlement_items');
}
function pos_settlement_record(int $tenant,int $actor,int $terminal,int $account,array $ids,int $amount,string $date,string $reference,string $key): int {
    require_admin();
    if(!pos_settlements_ready())throw new RuntimeException('ابتدا مهاجرت ۰۳۰ تطبیق بانکی را اجرا کنید.');
    $day=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if(!$day || $day->format('Y-m-d')!==$date || $date>(new DateTimeImmutable('today',new DateTimeZone('Asia/Tehran')))->format('Y-m-d') || $amount<=0 || $reference==='' || mb_strlen($reference)>120 || !preg_match('/^[a-f0-9]{64}$/D',$key) || !$ids || count($ids)>200)throw new RuntimeException('اطلاعات تطبیق بانکی معتبر نیست.');
    $clean=[];
    foreach($ids as $id){$n=filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if(!$n)throw new RuntimeException('شناسه دریافت معتبر نیست.');$clean[]=$n;}
    if(count(array_unique($clean))!==count($clean))throw new RuntimeException('دریافت تکراری در درخواست تطبیق وجود دارد.');
    sort($clean,SORT_NUMERIC);
    $digest=hash('sha256',json_encode([$terminal,$account,$clean,$amount,$date,$reference],JSON_THROW_ON_ERROR));
    db()->beginTransaction();
    try {
        $device=query('SELECT p.id FROM pos_settings p JOIN pos_account_registry r ON r.terminal_id=p.id AND r.company_id=p.company_id JOIN company_bank_accounts a ON a.id=r.account_id AND a.company_id=r.company_id WHERE p.company_id=? AND p.id=? AND r.account_id=? AND a.is_active=1 FOR UPDATE',[$tenant,$terminal,$account])->fetch();
        if(!$device)throw new RuntimeException('کارتخوان و حساب مقصد متعلق به این شرکت یا معتبر نیستند.');
        $previous=query('SELECT id,request_digest FROM pos_bank_settlements WHERE company_id=? AND request_key=?',[$tenant,$key])->fetch();
        if($previous){if(!hash_equals($previous['request_digest'],$digest))throw new RuntimeException('شناسه درخواست قبلاً برای تطبیق دیگری استفاده شده است.');db()->commit();return (int)$previous['id'];}
        $marks=implode(',',array_fill(0,count($clean),'?'));
        $receipts=query("SELECT p.id,p.amount,p.branch_id,p.paid_at FROM invoice_payments p JOIN invoices i ON i.id=p.invoice_id AND i.company_id=p.company_id WHERE p.company_id=? AND p.terminal_id=? AND p.payment_method='pos' AND p.voided_at IS NULL AND p.id IN ($marks) AND NOT EXISTS(SELECT 1 FROM invoice_voids v WHERE v.invoice_id=i.id AND v.company_id=i.company_id) ORDER BY p.id FOR UPDATE",array_merge([$tenant,$terminal],$clean))->fetchAll();
        if(count($receipts)!==count($clean))throw new RuntimeException('یک یا چند دریافت معتبر این کارتخوان نیست.');
        $total=0;
        foreach($receipts as $receipt){
            branch_require($tenant,$actor,$receipt['branch_id']);
            if(substr($receipt['paid_at'],0,10)>$date)throw new RuntimeException('تاریخ واریز بانکی نمی‌تواند قبل از دریافت باشد.');
            $raw=(string)$receipt['amount'];
            if(!ctype_digit($raw) || strlen($raw)>strlen((string)PHP_INT_MAX) || (strlen($raw)===strlen((string)PHP_INT_MAX) && strcmp($raw,(string)PHP_INT_MAX)>0))throw new RuntimeException('مبلغ دریافت خارج از محدوده محاسبه دقیق است.');
            $part=(int)$raw;if($part<=0 || $part>PHP_INT_MAX-$total)throw new RuntimeException('مبلغ دریافتی خارج از محدوده است.');$total+=$part;
        }
        if($total!==$amount)throw new RuntimeException('مبلغ واریز مستند بانک باید دقیقاً برابر جمع دریافت‌های منتخب باشد؛ کارمزد یا مغایرت را با مبلغ ساختگی نپوشانید.');
        if(query("SELECT payment_id FROM pos_bank_settlement_items WHERE company_id=? AND payment_id IN ($marks) LIMIT 1",array_merge([$tenant],$clean))->fetch())throw new RuntimeException('دریافت منتخب قبلاً با بانک تطبیق داده شده است.');
        query('INSERT INTO pos_bank_settlements(company_id,terminal_id,account_id,amount,bank_reference,settled_date,request_key,request_digest,created_by) VALUES(?,?,?,?,?,?,?,?,?)',[$tenant,$terminal,$account,$amount,$reference,$date,$key,$digest,$actor]);
        $id=(int)db()->lastInsertId();
        foreach($receipts as $receipt)query('INSERT INTO pos_bank_settlement_items(company_id,settlement_id,payment_id,amount) VALUES(?,?,?,?)',[$tenant,$id,$receipt['id'],$receipt['amount']]);
        audit('pos.bank.reconcile',json_encode(['settlement_id'=>$id,'terminal_id'=>$terminal,'account_id'=>$account,'amount'=>$amount,'payments'=>$clean],JSON_THROW_ON_ERROR));
        db()->commit();return $id;
    }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
}
/** Call while holding the invoice lock before correcting or voiding settled receipts. */
function pos_assert_invoice_unsettled(int $tenant,int $invoice): void {
    if(!pos_settlements_ready())return;
    if(!db()->inTransaction())throw new LogicException('Settlement guard requires transaction.');
    if(query('SELECT x.payment_id FROM pos_bank_settlement_items x JOIN invoice_payments p ON p.id=x.payment_id AND p.company_id=x.company_id WHERE p.company_id=? AND p.invoice_id=? LIMIT 1 FOR UPDATE',[$tenant,$invoice])->fetch())throw new RuntimeException('این فاکتور دریافت تطبیق‌شده بانکی دارد؛ اصلاح یا ابطال مستقیم مجاز نیست.');
}
function render_pos_settlements(int $tenant): void {
    require_admin();
    echo '<section class="card"><h2>تطبیق تسویه بانکی کارتخوان</h2><p>ثبت قطعی دریافت کارتخوان به معنی واریز بانک نیست. این بخش فقط دریافت‌های موجود را با واریز مستند بانک تطبیق می‌دهد و فروش یا دریافت جدید ایجاد نمی‌کند.</p>';
    if(!pos_settlements_ready()){echo '<p>مهاجرت ۰۳۰ برای تطبیق بانکی لازم است.</p></section>';return;}
    $devices=query('SELECT p.id,p.brand name FROM pos_settings p JOIN pos_account_registry r ON r.terminal_id=p.id AND r.company_id=p.company_id WHERE p.company_id=? ORDER BY p.brand',[$tenant])->fetchAll();
    $accounts=query('SELECT id,title FROM company_bank_accounts WHERE company_id=? AND is_active=1 ORDER BY title',[$tenant])->fetchAll();
    $token=$_SESSION['pos_settle_token']??=bin2hex(random_bytes(32));
    echo '<form method="post" class="stack" data-once-submit>'.csrf_field().'<input type="hidden" name="action" value="settle"><input type="hidden" name="settlement_token" value="'.e($token).'"><div class="form-grid"><label>کارتخوان<select name="terminal_id" required><option value="">انتخاب کنید</option>'.resource_options($devices).'</select></label><label>حساب مقصد کارتخوان<select name="account_id" required><option value="">انتخاب کنید</option>'.resource_options($accounts,'title').'</select></label><label>مبلغ واریز بانک (تومان)<input name="amount" inputmode="numeric" data-money-input required></label><label>شماره مرجع واریز<input name="bank_reference" maxlength="120" required></label><label>تاریخ واریز<span class="jalali-field"><input type="hidden" name="settled_date" data-jalali-iso required><button type="button" class="jalali-picker-button secondary" data-jalali-open>انتخاب تاریخ</button></span></label></div>';
    $pending=query("SELECT p.id,p.amount,p.paid_at,t.brand,b.name branch_name,p.tracking_number FROM invoice_payments p JOIN pos_settings t ON t.id=p.terminal_id AND t.company_id=p.company_id JOIN branches b ON b.id=p.branch_id AND b.company_id=p.company_id WHERE p.company_id=? AND p.payment_method='pos' AND p.voided_at IS NULL AND NOT EXISTS(SELECT 1 FROM pos_bank_settlement_items x WHERE x.company_id=p.company_id AND x.payment_id=p.id) AND NOT EXISTS(SELECT 1 FROM invoice_voids v WHERE v.company_id=p.company_id AND v.invoice_id=p.invoice_id) ORDER BY p.id DESC LIMIT 200",[$tenant])->fetchAll();
    echo '<fieldset><legend>دریافت‌های تطبیق‌داده‌نشده (۲۰۰ مورد آخر)</legend>';
    foreach($pending as $row)echo '<label class="choice"><input type="checkbox" name="payment_ids[]" value="'.(int)$row['id'].'">'.e($row['brand']).' — '.e($row['branch_name']).' — '.money_toman($row['amount']).' — '.e(jalali_datetime($row['paid_at'],true)).' — '.e(display_financial_reference($row['tracking_number']??'')).'</label>';
    if(!$pending)echo '<p>دریافت شناخته‌شده‌ای در انتظار تطبیق نیست؛ تراکنش‌های تاریخی فاقد دستگاه به دستگاه حدسی منتسب نمی‌شوند.</p>';
    echo '</fieldset><button type="submit">ثبت تطبیق با واریز بانک</button></form></section>';
    $history=query('SELECT p.brand,a.title,s.amount,s.bank_reference,s.settled_date FROM pos_bank_settlements s JOIN pos_settings p ON p.id=s.terminal_id AND p.company_id=s.company_id JOIN company_bank_accounts a ON a.id=s.account_id AND a.company_id=s.company_id WHERE s.company_id=? ORDER BY s.id DESC LIMIT 200',[$tenant])->fetchAll();
    echo '<section class="card"><h2>سوابق تسویه بانکی (۲۰۰ مورد آخر)</h2>';
    foreach($history as $row)echo '<p>'.e($row['brand']).' — '.e($row['title']).' — '.money_toman($row['amount']).' — '.e(display_financial_reference($row['bank_reference'])).' — '.e(jalali_datetime($row['settled_date'])).'</p>';
    echo '</section>';
}
