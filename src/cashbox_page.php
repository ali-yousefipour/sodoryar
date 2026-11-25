<?php
declare(strict_types=1);

function cashbox_handle_post(string $action): never {
    require_permission('cashbox.view');
    if(!cashbox_ready()) throw new RuntimeException('ابتدا مهاجرت صندوق ۰۱۸ را پس از پشتیبان‌گیری اعمال کنید.');
    $tenant=tenant_id();
    $actor=(int)user()['id'];
    if($action==='register'){
        require_permission('cashbox.manage');
        cashbox_add_register($tenant,(string)($_POST['name']??''));
        redirect('cashbox','صندوق جدید ثبت شد.');
    }
    if($action==='start'){
        require_permission('cashbox.open');
        $register=filter_var($_POST['register_id']??null,FILTER_VALIDATE_INT);
        if(!$register) throw new RuntimeException('صندوق انتخاب‌شده معتبر نیست.');
        cashbox_start_shift($tenant,$actor,(int)$register,cashbox_amount($_POST['opening_cash']??'0',true));
        redirect('cashbox','شیفت صندوق شروع شد.');
    }
    if($action==='transfer'){
        $token=(string)($_POST['transfer_token']??'');
        if (!isset($_SESSION['cash_transfer_token']) || !hash_equals($_SESSION['cash_transfer_token'],$token)) throw new RuntimeException('درخواست انتقال وجه نامعتبر است.');
        cashbox_transfer($tenant,(int)($_POST['source_shift_id']??0),(int)($_POST['destination_shift_id']??0),$actor,cashbox_amount($_POST['amount']??''),trim((string)($_POST['reason']??'')),$token);
        unset($_SESSION['cash_transfer_token']);
        redirect('cashbox','انتقال داخلی وجه با موفقیت ثبت شد.');
    }
    if($action==='expense'){
        $shift=(int)($_POST['shift_id']??0);
        $token=(string)($_POST['expense_token']??'');
        if (!isset($_SESSION['expense_tokens'][$shift]) || !hash_equals($_SESSION['expense_tokens'][$shift],$token)) throw new RuntimeException('درخواست هزینه نامعتبر یا تکراری است.');
        cashbox_add_expense($tenant,$shift,$actor,cashbox_amount($_POST['amount']??''),trim((string)($_POST['reason']??'')),$token);
        unset($_SESSION['expense_tokens'][$shift]);
        redirect('cashbox','هزینه شرکت از صندوق نقدی ثبت شد.');
    }
    if($action==='adjust'){
        require_permission('cashbox.adjust');
        $shift=filter_var($_POST['shift_id']??null,FILTER_VALIDATE_INT);
        if(!$shift) throw new RuntimeException('شناسه شیفت نامعتبر است.');
        cashbox_add_adjustment($tenant,(int)$shift,$actor,(string)($_POST['direction']??''),
            cashbox_amount($_POST['amount']??''),trim((string)($_POST['reason']??'')));
        redirect('cashbox','رویداد ورود یا خروج وجه ثبت شد.');
    }
    if($action==='close'){
        require_permission('cashbox.close');
        $shift=filter_var($_POST['shift_id']??null,FILTER_VALIDATE_INT);
        if(!$shift) throw new RuntimeException('شناسه شیفت نامعتبر است.');
        cashbox_close_shift($tenant,(int)$shift,$actor,cashbox_amount($_POST['declared_cash']??'0',true),
            trim((string)($_POST['note']??'')));
        redirect('cashbox','درخواست تسویه صندوق برای بررسی مدیر ثبت شد.');
    }
    if($action==='review'){
        require_permission('cashbox.approve');
        $shift=filter_var($_POST['shift_id']??null,FILTER_VALIDATE_INT);
        if(!$shift) throw new RuntimeException('شناسه شیفت نامعتبر است.');
        cashbox_review_shift($tenant,(int)$shift,$actor,(string)($_POST['decision']??''),
            trim((string)($_POST['note']??'')));
        redirect('cashbox','نتیجه بررسی تسویه ثبت شد.');
    }
    throw new RuntimeException('عملیات صندوق ناشناخته است.');
}

function render_cashbox(int $tenant): void {
    require_permission('cashbox.view');
    echo '<div class="heading"><div><h1>صندوق و تسویه روزانه</h1><p class="muted">فروش فاکتور از وجوه دریافت‌شده مستقل است؛ موجودی نقدی با کارتخوان جمع نمی‌شود.</p></div></div>';
    if(!cashbox_ready()){
        echo '<div class="card"><p>پایگاه داده صندوق آماده نیست. مهاجرت ۰۱۸ باید پس از تهیه پشتیبان معتبر اعمال شود.</p></div>';
        return;
    }
    $actor=(int)user()['id'];
    $branchField='';
    $branchScope='';$expenseScope='';
    if (function_exists('branches_ready') && branches_ready()) {
        $choices=branch_choices($tenant,$actor);
        $ids=array_map(static fn($b)=>(int)$b['id'],$choices);
        $idList=$ids?implode(',',$ids):'0';
        $branchScope=' AND r.branch_id IN ('.$idList.')';
        $expenseScope=' AND a.expense_branch_id IN ('.$idList.')';
        $registers=$ids?query("SELECT r.id,CONCAT(b.name,' — ',r.name) name FROM cash_registers r JOIN branches b ON b.id=r.branch_id AND b.company_id=r.company_id WHERE r.company_id=? AND r.is_active=1 AND r.branch_id IN (".implode(',',array_fill(0,count($ids),'?')).") ORDER BY b.branch_number,r.name",array_merge([$tenant],$ids))->fetchAll():[];
        $branchField='<label>شعبه<select name="branch_id" required><option value="">انتخاب شعبه</option>';
        foreach ($choices as $b) $branchField.='<option value="'.(int)$b['id'].'"'.((count($choices)===1 || (int)$b['is_default']===1)?' selected':'').'>'.e($b['name']).'</option>';
        $branchField.='</select></label>';
    } else {
        $registers=query('SELECT id,name FROM cash_registers WHERE company_id=? AND is_active=1 ORDER BY name,id',[$tenant])->fetchAll();
    }
    if(can('cashbox.manage')){
        echo '<div class="card"><h2>تعریف صندوق</h2><form class="form-grid" method="post">'.csrf_field()
           .'<input type="hidden" name="action" value="register"><label>نام صندوق<input name="name" maxlength="120" required></label>'
           .$branchField
           .'<button type="submit">ثبت صندوق</button></form></div>';
    }
    if(can('cashbox.open')){
        echo '<div class="card"><h2>شروع شیفت</h2><form class="form-grid" method="post">'.csrf_field()
           .'<input type="hidden" name="action" value="start"><label>صندوق<select name="register_id" required><option value="">انتخاب کنید</option>';
        foreach($registers as $register){
            echo '<option value="'.(int)$register['id'].'">'.e($register['name']).'</option>';
        }
        echo '</select></label><label>موجودی نقدی اولیه (تومان)<input name="opening_cash" value="۰" inputmode="numeric" data-money-input required></label>'
           .'<button type="submit"'.(!$registers?' disabled':'').'>شروع شیفت</button></form></div>';
    }
    $own=!can('cashbox.manage');
    $list=query('SELECT s.*,r.name register_name,u.full_name operator_name
       FROM cash_shifts s JOIN cash_registers r ON r.id=s.register_id AND r.company_id=s.company_id
       JOIN users u ON u.id=s.operator_id AND u.company_id=s.company_id
       WHERE s.company_id=?'.$branchScope.($own?' AND s.operator_id=?':'').' ORDER BY s.id DESC LIMIT 30',
       $own?[$tenant,$actor]:[$tenant])->fetchAll();
    if(cashbox_expenses_ready()){
        $summary=query("SELECT b.name,SUM(a.amount) total FROM cash_shift_adjustments a JOIN branches b ON b.id=a.expense_branch_id AND b.company_id=a.company_id JOIN cash_shifts s ON s.id=a.shift_id AND s.company_id=a.company_id WHERE a.company_id=?".$expenseScope.($own?' AND s.operator_id=?':'')." GROUP BY b.id,b.name ORDER BY b.name",$own?[$tenant,$actor]:[$tenant])->fetchAll();
        echo '<section class="card"><h2>جمع هزینه‌های نقدی قدیمی '.($own?'ثبت‌شده در صندوق‌های شما':'شرکت به تفکیک شعبه').'</h2>';
        foreach($summary as $item) echo '<p>'.e($item['name']).': '.money_toman($item['total']).'</p>';
        if(!$summary) echo '<p>هزینه‌ای ثبت نشده است.</p>';
        echo '</section>';
    }
    if(can('cashbox.manage') && can('cashbox.adjust') && cashbox_transfers_ready() && branches_ready()){
        $open=query("SELECT s.id,CONCAT(b.name,' — ',r.name,' — ',u.full_name) name FROM cash_shifts s JOIN cash_registers r ON r.id=s.register_id AND r.company_id=s.company_id JOIN branches b ON b.id=r.branch_id AND b.company_id=r.company_id JOIN users u ON u.id=s.operator_id AND u.company_id=s.company_id WHERE s.company_id=? AND s.status='open' AND r.is_active=1".$branchScope.' ORDER BY s.id',[$tenant])->fetchAll();
        $token=$_SESSION['cash_transfer_token']??=bin2hex(random_bytes(32));
        $options='<option value="">انتخاب صندوق باز</option>';
        foreach($open as $row)$options.='<option value="'.(int)$row['id'].'">'.e($row['name']).'</option>';
        echo '<section class="card"><h2>انتقال داخلی وجه نقد</h2><p>این عملیات فروش یا هزینه نیست؛ خروج از مبدأ و ورود به مقصد هم‌زمان ثبت می‌شوند.</p><form method="post" class="form-grid">'.csrf_field().'<input type="hidden" name="action" value="transfer"><input type="hidden" name="transfer_token" value="'.e($token).'"><label>صندوق مبدأ<select name="source_shift_id" required>'.$options.'</select></label><label>صندوق مقصد<select name="destination_shift_id" required>'.$options.'</select></label><label>مبلغ (تومان)<input name="amount" inputmode="numeric" data-money-input required></label><label>شرح انتقال<input name="reason" minlength="5" maxlength="400" required></label><button type="submit">ثبت انتقال قطعی وجه</button></form></section>';
    }
    echo '<section class="card"><h2>شیفت‌ها و وضعیت تسویه</h2>';
    if(!$list) echo '<p class="muted">هنوز شیفتی ثبت نشده است.</p>';
    foreach($list as $shift){
        $id=(int)$shift['id'];
        $totals=cashbox_shift_totals($tenant,$id);
        $canOperate=(int)$shift['operator_id']===$actor || can('cashbox.manage');
        $names=['open'=>'باز','pending'=>'در انتظار تأیید','approved'=>'تأییدشده','rejected'=>'ردشده / نیازمند اصلاح'];
        echo '<details class="cash-shift-details"><summary><strong>'.e($shift['register_name']).'</strong> — '
           .e($shift['operator_name']).' — '.e($names[$shift['status']]??$shift['status'])
           .' — '.e(jalali_datetime($shift['started_at'],true)).'</summary>';
        echo '<div class="stats"><article class="stat"><span>موجودی اولیه</span><b>'.money_toman($shift['opening_cash']).'</b></article>'
           .'<article class="stat"><span>دریافتی نقدی</span><b>'.money_toman($totals['cash']).'</b></article>'
           .'<article class="stat"><span>دریافتی کارتخوان</span><b>'.money_toman($totals['pos']).'</b></article>'
           .'<article class="stat"><span>ورود وجه / خروج وجه</span><b>'.money_toman($totals['incoming']).' / '.money_toman($totals['outgoing']).'</b></article>'
           .'<article class="stat"><span>تعداد تراکنش</span><b>'.fa_digits((string)$totals['receipts']).'</b></article></div>';
        if(cashbox_expenses_ready()){
            $expenses=query('SELECT a.amount,a.reason,a.created_at,b.name branch_name FROM cash_shift_adjustments a JOIN branches b ON b.id=a.expense_branch_id AND b.company_id=a.company_id WHERE a.company_id=? AND a.shift_id=? ORDER BY a.id DESC',[$tenant,$id])->fetchAll();
            echo '<h3>هزینه‌های شرکت از این صندوق</h3><p>هزینه‌ها در خروج وجه و مانده صندوق محاسبه شده‌اند.</p>';
            foreach($expenses as $expense) echo '<p>'.e($expense['branch_name']).' — '.money_toman($expense['amount']).' — '.e($expense['reason']).' — '.e(jalali_datetime($expense['created_at'],true)).'</p>';
            if(!$expenses) echo '<p class="muted">هزینه‌ای ثبت نشده است.</p>';
            if($canOperate && $shift['status']==='open' && can('cashbox.adjust') && (!function_exists('expenses_ready') || !expenses_ready())){
                $token=$_SESSION['expense_tokens'][$id]??=bin2hex(random_bytes(32));
                echo '<form method="post" class="form-grid">'.csrf_field().'<input type="hidden" name="action" value="expense"><input type="hidden" name="shift_id" value="'.$id.'"><input type="hidden" name="expense_token" value="'.e($token).'"><label>مبلغ هزینه (تومان)<input name="amount" inputmode="numeric" data-money-input required></label><label>شرح هزینه شرکت<input name="reason" minlength="5" maxlength="500" required></label><button type="submit">ثبت هزینه از صندوق نقدی</button></form>';
            }
        }
        $available=(int)$shift['opening_cash']+$totals['cash']+$totals['incoming']-$totals['outgoing'];
        echo '<p>مانده محاسباتی نقدی: '.money_toman($available).'</p>';
        $movements=query('SELECT direction,amount,reason,created_at FROM cash_shift_adjustments WHERE company_id=? AND shift_id=? ORDER BY id DESC',[$tenant,$id])->fetchAll();
        if($movements){
            echo '<h3>گردش ورود و خروج نقدی</h3>';
            foreach($movements as $movement) echo '<p>'.($movement['direction']==='in'?'ورود':'خروج').' — '.money_toman($movement['amount']).' — '.e($movement['reason']).' — '.e(jalali_datetime($movement['created_at'],true)).'</p>';
        }
        if($shift['expected_cash']!==null){
            echo '<p>موجودی مورد انتظار هنگام درخواست: '.money_toman($shift['expected_cash'])
                 .' | موجودی اعلام‌شده: '.money_toman($shift['declared_cash'])
                 .' | مغایرت: '.money_toman($shift['difference_cash']).'</p>';
        }
        if($shift['close_note']) echo '<p>توضیحات اپراتور: '.e($shift['close_note']).'</p>';
        if($shift['manager_note']) echo '<p>یادداشت مدیر: '.e($shift['manager_note']).'</p>';
        if($canOperate && in_array($shift['status'],['open','rejected'],true)){
            if(can('cashbox.adjust')){
                echo '<form method="post" class="form-grid">'.csrf_field()
                    .'<input type="hidden" name="action" value="adjust"><input type="hidden" name="shift_id" value="'.$id.'">'
                    .'<label>نوع رویداد<select name="direction"><option value="in">ورود وجه</option><option value="out">خروج وجه</option></select></label>'
                    .'<label>مبلغ (تومان)<input name="amount" inputmode="numeric" data-money-input required></label>'
                    .'<label>دلیل<input name="reason" minlength="5" maxlength="500" required></label>'
                    .'<button type="submit">ثبت رویداد صندوق</button></form>';
            }
            if(can('cashbox.close')){
                echo '<form method="post" class="form-grid">'.csrf_field()
                    .'<input type="hidden" name="action" value="close"><input type="hidden" name="shift_id" value="'.$id.'">'
                    .'<label>موجودی نقدی واقعی (تومان)<input name="declared_cash" inputmode="numeric" data-money-input required></label>'
                    .'<label>توضیحات (در صورت مغایرت الزامی)<textarea name="note" maxlength="500"></textarea></label>'
                    .'<button type="submit">ارسال درخواست بستن صندوق</button></form>';
            }
        }
        if(can('cashbox.approve') && $shift['status']==='pending' && (int)$shift['operator_id']!==$actor){
            echo '<form method="post" class="form-grid">'.csrf_field()
                .'<input type="hidden" name="action" value="review"><input type="hidden" name="shift_id" value="'.$id.'">'
                .'<label>تصمیم<select name="decision"><option value="approved">تأیید تسویه</option><option value="rejected">رد و ارجاع برای اصلاح</option></select></label>'
                .'<label>توضیح مدیر<textarea name="note" maxlength="500"></textarea></label>'
                .'<button type="submit">ثبت نتیجه بررسی</button></form>';
        }
        echo '</details>';
    }
    echo '</section>';
}
