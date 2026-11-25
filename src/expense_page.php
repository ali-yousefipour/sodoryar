<?php
declare(strict_types=1);
function expense_handle_post(string $action): never {
    $tenant=tenant_id();$actor=(int)user()['id'];
    if(in_array($action,['category','category_edit','policy'],true))expense_settings_post($action);
    if($action==='save'){
        $token=(string)($_POST['expense_token']??'');
        $sessionValid=isset($_SESSION['expense_create_token']) && hash_equals($_SESSION['expense_create_token'],$token);
        $replay=preg_match('/^[a-f0-9]{64}$/D',$token)?query('SELECT r.expense_id FROM expense_requests r JOIN expenses e ON e.id=r.expense_id AND e.company_id=r.company_id WHERE r.company_id=? AND r.request_key=? AND e.created_by=?',[$tenant,$token,$actor])->fetch():false;
        if(!$sessionValid&&!$replay)throw new RuntimeException('درخواست ثبت هزینه معتبر نیست؛ صفحه را دوباره بارگذاری کنید.');
        $id=expense_save($tenant,$actor,$_POST,$token);
        unset($_SESSION['expense_create_token']);
        $message='سند هزینه ثبت شد.';
        if(isset($_FILES['attachment']) && (int)$_FILES['attachment']['error']!==UPLOAD_ERR_NO_FILE){
            try{expense_attach($tenant,$id,$actor,$_FILES['attachment']);}catch(Throwable $ex){$message.=' پیوست ذخیره نشد؛ از بخش پیوست سند دوباره تلاش کنید.';}
        }
        if(($_POST['after_save']??'')==='print') {$_SESSION['flash']=$message;header('Location: ?page=expense_receipt&id='.$id);exit;}
        redirect('expenses',$message);
    }
    $id=filter_var($_POST['expense_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if(!$id)throw new RuntimeException('شناسه سند هزینه معتبر نیست.');
    $reason=trim((string)($_POST['reason']??''));
    if($action==='review')expense_review($tenant,$id,$actor,(string)($_POST['decision']??''),$reason);
    elseif($action==='void')expense_void($tenant,$id,$actor,$reason);
    elseif($action==='request_change')expense_request_change($tenant,$id,$actor,$reason);
    elseif($action==='attach')expense_attach($tenant,$id,$actor,$_FILES['attachment']??[]);
    elseif(in_array($action,['reimburse','pay'],true)){
        $token=(string)($_POST['reimburse_token']??'');
        if(!isset($_SESSION['expense_reimburse_tokens'][$id]) || !hash_equals($_SESSION['expense_reimburse_tokens'][$id],$token))throw new RuntimeException('درخواست بازپرداخت معتبر نیست.');
        if($action==='pay')expense_add_payment($tenant,$id,$actor,$_POST,$token);else expense_reimburse($tenant,$actor,$_POST,$token);unset($_SESSION['expense_reimburse_tokens'][$id]);
    }else throw new RuntimeException('عملیات هزینه ناشناخته است.');
    redirect('expenses','عملیات سند هزینه ثبت شد.');
}
function expense_ui_options(array $rows,string $label='name',bool $branch=false): string {
    $html='<option value="">انتخاب کنید</option>';
    foreach($rows as $row)$html.='<option value="'.(int)$row['id'].'"'.($branch?' data-branch="'.(int)($row['branch_id']??0).'"':'').'>'.e($row[$label]).'</option>';
    return $html;
}
function expense_ui_sources(int $tenant,int $actor): array {
    $branches=branch_choices($tenant,$actor);$ids=array_map(static fn($b)=>(int)$b['id'],$branches);$list=$ids?implode(',',$ids):'0';
    $cash=query("SELECT s.id,r.branch_id,CONCAT(r.name,' — ',u.full_name) name FROM cash_shifts s JOIN cash_registers r ON r.id=s.register_id AND r.company_id=s.company_id JOIN users u ON u.id=s.operator_id WHERE s.company_id=? AND s.status='open' AND r.is_active=1 AND r.branch_id IN ($list)".(!can('cashbox.manage')?' AND s.operator_id='.(int)$actor:'').' ORDER BY s.id',[$tenant])->fetchAll();
    $banks=can('expenses.bank')?query("SELECT id,branch_id,title name FROM company_bank_accounts WHERE company_id=? AND is_active=1 AND (branch_id IS NULL OR branch_id IN ($list)) ORDER BY title",[$tenant])->fetchAll():[];
    return [$branches,$cash,$banks];
}
function render_expenses(int $tenant): void {
    expense_require_permission('view');
    echo '<div class="heading"><div><h1>هزینه‌های شرکت</h1><p class="muted">ثبت هزینه، منبع پرداخت و بدهی شرکت به کارکنان، به تفکیک شعبه</p></div>';
    if(!expenses_ready()){echo '</div><section class="card"><p>ساختار هزینه‌ها آماده نیست؛ مهاجرت مربوط به هزینه را اجرا کنید.</p></section>';return;}
    if(can('expenses.create'))echo '<button type="button" id="expenseWizardOpen">ثبت هزینه جدید</button>';
    echo '</div>';
    expense_ensure_defaults($tenant);
    $filters=expense_report_filters($_GET);$report=expense_report_data($tenant,$filters);
    render_expense_filters($tenant,$filters);render_expense_summary($report['summary']);
    $actor=(int)user()['id'];[$branches,$cash,$banks]=expense_ui_sources($tenant,$actor);
    $states=['draft'=>'پیش‌نویس','pending'=>'در انتظار تأیید','approved'=>'تأییدشده','rejected'=>'ردشده','voided'=>'باطل‌شده'];
    echo '<section class="card"><h2>اسناد هزینه</h2><div class="table-wrap"><table><thead><tr><th>شماره / تاریخ</th><th>شرح / شعبه</th><th>دسته</th><th>مبلغ</th><th>پرداخت / مانده</th><th>بدهی به کارمند</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>';
    foreach($report['rows'] as $row){$id=(int)$row['id'];$own=(int)$row['created_by']===$actor;
        echo '<tr><td>'.e(display_financial_reference($row['document_number']??(string)$id)).'<small>'.e(jalali_datetime($row['expense_date'])).'</small><small>ثبت: '.e(jalali_datetime($row['created_at'],true)).'</small></td><td>'.e($row['title']).'<small>'.e($row['branch_name']).' — '.e($row['operator_name']).'</small></td><td>'.e($row['category_name']).'<small>'.fa_digits((string)($row['item_count']??0)).' قلم</small></td><td>'.money_toman($row['total_amount']).'</td><td>'.money_toman($row['paid_amount']).' / '.money_toman($row['remaining']).'</td><td>'.money_toman($row['employee_due']).'</td><td>'.e($states[$row['approval_status']]??$row['approval_status']).'</td><td><a href="?page=expense_receipt&amp;id='.$id.'">مشاهده / چاپ</a> <button type="button" class="small secondary" data-dialog-open="expenseActions'.$id.'">عملیات سند</button>';
        if($own && $row['approval_status']==='draft' && can('expenses.create'))echo ' <a href="?page=expenses&amp;edit='.$id.'">ویرایش</a>';
        echo '</td></tr>';
    }
    if(!$report['rows'])echo '<tr><td colspan="8">سندی با این فیلترها پیدا نشد.</td></tr>';
    echo '</tbody></table></div><nav aria-label="صفحه‌بندی هزینه‌ها" class="expense-pagination">';
    foreach(['قبلی'=>max(1,$report['page']-1),'بعدی'=>min($report['pages'],$report['page']+1)] as $label=>$page){$query=$_GET;$query['page']='expenses';$query['report_page']=$page;echo '<a href="?'.e(http_build_query($query)).'">'.$label.'</a>';}
    echo '<span>صفحه '.fa_digits((string)$report['page']).' از '.fa_digits((string)$report['pages']).'</span></nav></section>';
    foreach($report['rows'] as $row)render_expense_actions($tenant,$row,$cash,$banks);
    if(can('expenses.create'))render_expense_wizard($tenant,$branches,$cash,$banks);
    if(can('expenses.manage'))render_expense_settings($tenant);
}
function render_expense_actions(int $tenant,array $row,array $cash,array $banks): void {
    $cash=array_values(array_filter($cash,static fn($source)=>(int)$source['branch_id']===(int)$row['branch_id']));
    $banks=array_values(array_filter($banks,static fn($source)=>!$source['branch_id']||(int)$source['branch_id']===(int)$row['branch_id']));
    $id=(int)$row['id'];$actor=(int)user()['id'];$own=(int)$row['created_by']===$actor;$status=$row['approval_status'];
    $reason='<label>دلیل / توضیحات<textarea name="reason" minlength="5" maxlength="500" required></textarea></label>';
    $base=csrf_field().'<input type="hidden" name="expense_id" value="'.$id.'">';
    echo '<dialog class="app-dialog expense-actions" id="expenseActions'.$id.'"><div class="stack"><div class="heading"><h2>'.e($row['title']).'</h2><button type="button" class="secondary" data-dialog-close>بستن</button></div>';
    if(can('expenses.approve') && !$own && $status==='pending')echo '<form method="post" class="stack">'.$base.'<input type="hidden" name="action" value="review"><label>تصمیم<select name="decision"><option value="approved">تأیید هزینه</option><option value="rejected">رد هزینه</option></select></label>'.$reason.'<button>ثبت بررسی</button></form>';
    if(can('expenses.manage') && $status!=='voided')echo '<form method="post" class="stack">'.$base.'<input type="hidden" name="action" value="void">'.$reason.'<button class="danger">ابطال سند با ثبت دلیل</button></form>';
    if($own && !in_array($status,['draft','voided'],true))echo '<form method="post" class="stack">'.$base.'<input type="hidden" name="action" value="request_change">'.$reason.'<button class="secondary">ثبت درخواست اصلاح</button></form>';
    if($status!=='voided' && ($own||can('expenses.manage')))echo '<form method="post" enctype="multipart/form-data" class="stack">'.$base.'<input type="hidden" name="action" value="attach"><label>پیوست سند (PDF، JPG، PNG؛ حداکثر ۵ مگابایت)<input type="file" name="attachment" accept="application/pdf,image/jpeg,image/png" required></label><button class="secondary">افزودن پیوست</button></form>';
    if(can('expenses.manage') && $status==='approved' && (int)$row['employee_due']>0){
        $payments=query("SELECT p.id,p.amount-COALESCE((SELECT SUM(r.amount) FROM employee_reimbursements r WHERE r.company_id=p.company_id AND r.payment_id=p.id),0) amount FROM expense_payments p WHERE p.company_id=? AND p.expense_id=? AND p.method='personal' AND p.posted_at IS NOT NULL HAVING amount>0 ORDER BY p.id",[$tenant,$id])->fetchAll();
        $token=$_SESSION['expense_reimburse_tokens'][$id]??=bin2hex(random_bytes(32));
        echo '<form method="post" class="stack">'.$base.'<input type="hidden" name="action" value="reimburse"><input type="hidden" name="reimburse_token" value="'.e($token).'"><h3>بازپرداخت به کارمند</h3><label>پرداخت شخصی<select name="payment_id" required>';foreach($payments as $payment)echo '<option value="'.(int)$payment['id'].'">'.fa_digits((string)$payment['id']).' — '.money_toman($payment['amount']).'</option>';
        echo '</select></label><label>مبلغ بازپرداخت (تومان)<input name="amount" inputmode="numeric" data-money-input required></label><label>منبع<select name="method"><option value="cash">صندوق نقدی</option>'.(can('expenses.bank')?'<option value="bank">حساب بانکی</option>':'').'</select></label><label>صندوق<select name="cash_shift_id">'.expense_ui_options($cash).'</select></label><label>حساب بانکی<select name="bank_account_id">'.expense_ui_options($banks).'</select></label>'.expense_ui_date('paid_date','تاریخ پرداخت').'<label>شماره پیگیری<input name="tracking_number" maxlength="120"></label><button>ثبت بازپرداخت</button></form>';
    }
    if($status==='approved' && (int)$row['remaining']>0 && ($own||can('expenses.manage'))){
        $token=$_SESSION['expense_reimburse_tokens'][$id]??=bin2hex(random_bytes(32));
        echo '<form method="post" class="stack">'.$base.'<input type="hidden" name="action" value="pay"><input type="hidden" name="reimburse_token" value="'.e($token).'"><h3>پرداخت مانده هزینه</h3><label>مبلغ (تومان)<input name="amount" inputmode="numeric" data-money-input required></label><label>روش پرداخت<select name="method"><option value="cash">صندوق نقدی</option>'.(can('expenses.bank')?'<option value="bank">حساب بانکی</option>':'').'<option value="personal">پرداخت شخصی کارمند</option></select></label><label>صندوق<select name="cash_shift_id">'.expense_ui_options($cash).'</select></label>'.(can('expenses.bank')?'<label>حساب بانکی<select name="bank_account_id">'.expense_ui_options($banks).'</select></label>':'').expense_ui_date('paid_date','تاریخ پرداخت').'<label>شماره پیگیری<input name="tracking_number" maxlength="120"></label><button>ثبت پرداخت مانده</button></form>';
    }
    echo '</div></dialog>';
}
function expense_ui_date(string $name,string $label,string $value=''): string {
    return '<label>'.e($label).'<span class="jalali-field"><input type="hidden" name="'.e($name).'" value="'.e($value).'" data-jalali-iso required><button type="button" class="jalali-picker-button secondary" data-jalali-open>انتخاب تاریخ</button><button type="button" class="jalali-clear secondary" data-jalali-clear aria-label="پاک‌کردن تاریخ">×</button></span></label>';
}
function render_expense_wizard(int $tenant,array $branches,array $cash,array $banks): void {
    $initial=[];$editing=(int)($_GET['edit']??0);
    if($editing){$initial=expense_require_document($tenant,$editing);if($initial['approval_status']!=='draft')throw new RuntimeException('این هزینه قابل ویرایش مستقیم نیست.');$initial['items']=query('SELECT *,CAST(quantity_milli/1000 AS DECIMAL(12,3)) quantity FROM expense_items WHERE company_id=? AND expense_id=? ORDER BY id',[$tenant,$editing])->fetchAll();$initial['payments']=query('SELECT * FROM expense_payments WHERE company_id=? AND expense_id=? ORDER BY id',[$tenant,$editing])->fetchAll();}
    if(($_SERVER['REQUEST_METHOD']??'GET')==='POST' && ($_POST['action']??'')==='save')$initial=$_POST;
    $token=$_SESSION['expense_create_token']??=bin2hex(random_bytes(32));
    $categories=query('SELECT id,name,accounting_type FROM expense_categories WHERE company_id=? AND is_active=1 ORDER BY name',[$tenant])->fetchAll();
    echo '<dialog class="app-dialog" id="expenseWizard" data-bank="'.(can('expenses.bank')?'1':'0').'" data-initial="'.e(json_encode($initial,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)).'" data-reopen="'.($initial?'1':'0').'"><form method="post" enctype="multipart/form-data" id="expenseForm" novalidate>'.csrf_field().'<input type="hidden" name="action" value="save"><input type="hidden" name="expense_token" value="'.e($token).'"><input type="hidden" name="id"><input type="hidden" name="version"><input type="hidden" name="mode" value="submit"><input type="hidden" name="after_save" value="list"><header class="expense-wizard-head"><h2>ثبت هزینه شرکت</h2><p data-expense-status tabindex="-1" aria-live="polite"></p><ol class="expense-steps"><li>اطلاعات هزینه</li><li>اقلام و مبلغ</li><li>منابع پرداخت</li><li>بررسی نهایی</li></ol></header><div class="expense-wizard-body"><p class="alert error" data-expense-error role="alert" hidden></p><section data-expense-stage="0"><h3>اطلاعات هزینه</h3><div class="form-grid"><label>شعبه<select name="branch_id" required>'.expense_ui_options($branches).'</select></label>'.expense_ui_date('expense_date','تاریخ هزینه',(new DateTimeImmutable('today',new DateTimeZone('Asia/Tehran')))->format('Y-m-d')).'<label>دسته هزینه<select name="category_id" required><option value="">انتخاب کنید</option>';
    $types=['expense'=>'هزینه','asset'=>'دارایی','prepayment'=>'پیش‌پرداخت','inventory'=>'موجودی'];foreach($categories as $category)echo '<option value="'.(int)$category['id'].'">'.e($category['name']).' — '.e($types[$category['accounting_type']]??$category['accounting_type']).'</option>';
    echo '</select></label><label>عنوان هزینه<input name="title" maxlength="180" required></label><label>شرح<textarea name="description" maxlength="2000"></textarea></label><label>یادداشت<textarea name="notes" maxlength="2000"></textarea></label></div></section><section data-expense-stage="1" hidden><h3>اقلام هزینه</h3><p class="muted">مبالغ به تومان و تعداد حداکثر با سه رقم اعشار وارد شود.</p><div data-expense-items></div><button type="button" class="secondary" data-expense-add-item>افزودن قلم</button><div class="form-grid"><label>هزینه جانبی (تومان)<input name="extra_amount" value="0" inputmode="numeric" data-money-input></label><label>تخفیف (تومان)<input name="discount_amount" value="0" inputmode="numeric" data-money-input></label></div></section><section data-expense-stage="2" hidden><h3>منابع پرداخت</h3><p>هر منبع را جدا ثبت کنید. پرداخت شخصی کارمند، بدهی شرکت به اوست؛ هزینه دوباره محاسبه نمی‌شود. می‌توانید سند را با مانده پرداخت‌نشده ثبت کنید.</p><div data-expense-payments></div><button type="button" class="secondary" data-expense-add-payment>افزودن پرداخت</button><span class="jalali-field" data-expense-date-proxy hidden><input type="hidden" data-jalali-iso><button type="button" data-jalali-open>انتخاب تاریخ</button></span><template data-expense-cash>'.expense_ui_options($cash,'name',true).'</template><template data-expense-bank>'.expense_ui_options($banks,'name',true).'</template></section><section data-expense-stage="3" hidden><h3>بررسی سند</h3><div data-expense-review></div><label>پیوست اختیاری (PDF، JPG، PNG؛ حداکثر ۵ مگابایت)<input type="file" name="attachment" accept="application/pdf,image/jpeg,image/png"></label></section></div><footer class="expense-wizard-footer"><div class="expense-totals" aria-live="polite"><span>جمع سند <b data-expense-total>۰</b> تومان</span><span>پرداخت <b data-expense-paid>۰</b> تومان</span><span>مانده <b data-expense-remaining>۰</b> تومان</span></div><div class="expense-controls"><button type="button" class="secondary" data-expense-cancel>انصراف</button><button type="button" class="secondary" data-expense-prev>قبلی</button><button type="button" data-expense-next>ادامه</button><button type="submit" class="secondary" data-expense-draft>ذخیره پیش‌نویس</button><button type="submit" data-expense-save hidden>ثبت هزینه</button><button type="submit" class="secondary" data-expense-print hidden>ثبت و چاپ</button></div></footer></form></dialog>';
}
