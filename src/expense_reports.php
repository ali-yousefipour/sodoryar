<?php
declare(strict_types=1);

function expense_report_filters(array $input=[]): array {
    if(!$input)$input=$_GET;
    $filter=array_fill_keys(['branch_id','user_id','category_id','date_from','date_to','method','bank_account_id','cash_register_id','approval_status','payment_status','amount_min','amount_max'],'');
    foreach($filter as $key=>$unused){
        if(isset($input[$key]) && !is_string($input[$key]))throw new RuntimeException('فیلتر هزینه نامعتبر است.');
        $filter[$key]=ascii_digits(trim((string)($input[$key]??'')));
    }
    foreach(['branch_id','user_id','category_id','bank_account_id','cash_register_id'] as $key)
        if($filter[$key]!=='' && !filter_var($filter[$key],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]))throw new RuntimeException('شناسه فیلتر هزینه معتبر نیست.');
    foreach(['date_from','date_to'] as $key){
        if($filter[$key]==='')continue;
        $date=DateTimeImmutable::createFromFormat('!Y-m-d',$filter[$key]);
        if(!$date || $date->format('Y-m-d')!==$filter[$key])throw new RuntimeException('تاریخ فیلتر هزینه معتبر نیست.');
    }
    if($filter['date_from']!=='' && $filter['date_to']!=='' && $filter['date_from']>$filter['date_to'])throw new RuntimeException('بازه تاریخ هزینه نامعتبر است.');
    foreach(['method'=>['','cash','bank','personal'],'approval_status'=>['','draft','pending','approved','rejected','voided'],'payment_status'=>['','unpaid','partial','paid']] as $key=>$values)
        if(!in_array($filter[$key],$values,true))throw new RuntimeException('وضعیت فیلتر هزینه نامعتبر است.');
    foreach(['amount_min','amount_max'] as $key){
        if($filter[$key]==='')continue;
        $amount=parse_money_input($filter[$key],0,1000000000000);
        if($amount===false)throw new RuntimeException('محدوده مبلغ هزینه نامعتبر است.');
        $filter[$key]=(string)$amount;
    }
    if($filter['amount_min']!=='' && $filter['amount_max']!=='' && (int)$filter['amount_min']>(int)$filter['amount_max'])throw new RuntimeException('حداقل هزینه از حداکثر بیشتر است.');
    $page=$input['report_page']??'1';
    $filter['report_page']=is_scalar($page)?max(1,min(100000,(int)ascii_digits((string)$page))):1;
    return $filter;
}

/** Aggregate each payment once before joining documents, including personal reimbursements. */
function expense_report_source(int $tenant): array {
    $sql=" FROM expenses e JOIN branches b ON b.id=e.branch_id AND b.company_id=e.company_id
      JOIN expense_categories c ON c.id=e.category_id AND c.company_id=e.company_id
      LEFT JOIN users u ON u.id=e.created_by AND u.company_id=e.company_id
      LEFT JOIN (SELECT ep.company_id,ep.expense_id,SUM(ep.amount) paid_amount,
       SUM(CASE WHEN ep.method='cash' THEN ep.amount ELSE 0 END) cash_paid,
       SUM(CASE WHEN ep.method='bank' THEN ep.amount ELSE 0 END) bank_paid,
       SUM(CASE WHEN ep.method='personal' THEN ep.amount ELSE 0 END) personal_paid,
       SUM(CASE WHEN ep.method='personal' THEN GREATEST(ep.amount-COALESCE(r.reimbursed,0),0) ELSE 0 END) employee_due
       FROM expense_payments ep LEFT JOIN
        (SELECT payment_id,company_id,SUM(amount) reimbursed FROM employee_reimbursements WHERE company_id=? GROUP BY company_id,payment_id) r
        ON r.payment_id=ep.id AND r.company_id=ep.company_id
       WHERE ep.company_id=? AND ep.posted_at IS NOT NULL GROUP BY ep.company_id,ep.expense_id) pay
       ON pay.company_id=e.company_id AND pay.expense_id=e.id
       WHERE e.company_id=?".expense_scope_sql($tenant,'e');
    return [$sql,[$tenant,$tenant,$tenant]];
}
function expense_report_query(int $tenant,array $filter): array {
    [$sql,$params]=expense_report_source($tenant);
    foreach(['branch_id'=>'e.branch_id','user_id'=>'e.created_by','category_id'=>'e.category_id','approval_status'=>'e.approval_status'] as $key=>$column){
        if(($filter[$key]??'')!==''){$sql.=' AND '.$column.'=?';$params[]=$filter[$key];}
    }
    foreach(['date_from'=>['>=','e.expense_date'],'date_to'=>['<=','e.expense_date'],'amount_min'=>['>=','e.total_amount'],'amount_max'=>['<=','e.total_amount']] as $key=>[$operator,$column]){
        if(($filter[$key]??'')!==''){$sql.=' AND '.$column.$operator.'?';$params[]=$filter[$key];}
    }
    $payment='';$paymentParams=[];
    foreach(['method'=>'fp.method','bank_account_id'=>'fp.bank_account_id'] as $key=>$column)
        if(($filter[$key]??'')!==''){$payment.=' AND '.$column.'=?';$paymentParams[]=$filter[$key];}
    if(($filter['cash_register_id']??'')!==''){
        $payment.=' AND EXISTS(SELECT 1 FROM cash_shifts fs WHERE fs.id=fp.cash_shift_id AND fs.company_id=fp.company_id AND fs.register_id=?)';
        $paymentParams[]=$filter['cash_register_id'];
    }
    if($payment!==''){$sql.=' AND EXISTS(SELECT 1 FROM expense_payments fp WHERE fp.expense_id=e.id AND fp.company_id=e.company_id'.$payment.')';array_push($params,...$paymentParams);}
    $state=$filter['payment_status']??'';
    if($state==='unpaid')$sql.=' AND COALESCE(pay.paid_amount,0)=0';
    elseif($state==='partial')$sql.=' AND COALESCE(pay.paid_amount,0)>0 AND pay.paid_amount<e.total_amount';
    elseif($state==='paid')$sql.=' AND COALESCE(pay.paid_amount,0)>=e.total_amount';
    return [$sql,$params];
}
function expense_report_data(int $tenant,array $filter,bool $all=false): array {
    expense_require_permission('view');
    [$sql,$params]=expense_report_query($tenant,$filter);
    $summary=query("SELECT COUNT(*) document_count,COALESCE(SUM(e.total_amount),0) total_amount,
      COALESCE(SUM(CASE WHEN e.approval_status='approved' AND c.accounting_type='expense' THEN e.total_amount ELSE 0 END),0) approved_expenses,
      COALESCE(SUM(pay.paid_amount),0) paid_amount,COALESCE(SUM(pay.cash_paid),0) cash_paid,
      COALESCE(SUM(pay.bank_paid),0) bank_paid,COALESCE(SUM(pay.personal_paid),0) personal_paid,
      COALESCE(SUM(CASE WHEN e.approval_status='approved' THEN pay.employee_due ELSE 0 END),0) employee_due,
      COALESCE(SUM(GREATEST(e.total_amount-COALESCE(pay.paid_amount,0),0)),0) remaining".$sql,$params)->fetch();
    $total=(int)$summary['document_count'];$pages=max(1,intdiv($total+29,30));$page=min($pages,max(1,(int)($filter['report_page']??1)));
    $rows=query("SELECT e.*,b.name branch_name,c.name category_name,c.accounting_type,COALESCE(u.full_name,'—') operator_name,
      COALESCE(pay.paid_amount,0) paid_amount,COALESCE(pay.cash_paid,0) cash_paid,COALESCE(pay.bank_paid,0) bank_paid,
      COALESCE(pay.personal_paid,0) personal_paid,COALESCE(pay.employee_due,0) employee_due,
      GREATEST(e.total_amount-COALESCE(pay.paid_amount,0),0) remaining".$sql.' ORDER BY e.expense_date DESC,e.id DESC'.($all?'':' LIMIT 30 OFFSET '.(($page-1)*30)),$params)->fetchAll();
    return ['summary'=>$summary,'rows'=>$rows,'page'=>$page,'pages'=>$pages,'total'=>$total];
}
function expense_approval_label(string $status): string {
    return ['draft'=>'پیش‌نویس','pending'=>'در انتظار تأیید','approved'=>'تأییدشده','rejected'=>'ردشده','voided'=>'باطل‌شده'][$status]??$status;
}
function expense_method_label(string $method): string {return ['cash'=>'صندوق نقدی','bank'=>'حساب بانکی','personal'=>'پرداخت شخصی کارمند'][$method]??$method;}
function render_expense_summary(array $summary): void {
    echo '<div class="stats">';
    foreach(['total_amount'=>'جمع اسناد انتخاب‌شده','approved_expenses'=>'هزینه جاری تأییدشده','paid_amount'=>'پرداخت به فروشنده','remaining'=>'مانده پرداخت اسناد','employee_due'=>'بدهی به کارکنان'] as $key=>$title)
        echo '<article class="stat"><span>'.e($title).'</span><b>'.money_toman($summary[$key]??0).'</b></article>';
    echo '</div>';
}
function render_expense_filters(int $tenant,array $filter): void {
    echo '<form method="get" class="card form-grid"><input type="hidden" name="page" value="expenses">';
    render_branch_report_select($tenant,$filter['branch_id']);
    $users=query('SELECT id,full_name name FROM users WHERE company_id=?'.(document_scope()==='own'?' AND id='.(int)user()['id']:'').' ORDER BY full_name',[$tenant])->fetchAll();
    $categories=query('SELECT id,name FROM expense_categories WHERE company_id=? ORDER BY name',[$tenant])->fetchAll();
    foreach(['user_id'=>['ثبت‌کننده',$users],'category_id'=>['دسته هزینه',$categories]] as $key=>[$label,$rows]){
        echo '<label>'.e($label).'<select name="'.$key.'"><option value="">همه</option>';
        foreach($rows as $row)echo '<option value="'.(int)$row['id'].'"'.((string)$filter[$key]===(string)$row['id']?' selected':'').'>'.e($row['name']).'</option>';
        echo '</select></label>';
    }
    foreach(['date_from'=>'از تاریخ شمسی','date_to'=>'تا تاریخ شمسی'] as $key=>$title)
        echo '<label>'.e($title).'<span class="jalali-field"><input type="hidden" name="'.$key.'" value="'.e($filter[$key]).'" data-jalali-iso><button type="button" class="jalali-picker-button secondary" data-jalali-open>انتخاب تاریخ</button><button type="button" class="jalali-clear secondary" data-jalali-clear>پاک‌کردن</button></span></label>';
    foreach(['method'=>['روش پرداخت',['cash'=>'نقدی','bank'=>'بانکی','personal'=>'شخصی کارمند']],'approval_status'=>['وضعیت تأیید',array_combine(['draft','pending','approved','rejected','voided'],array_map('expense_approval_label',['draft','pending','approved','rejected','voided']))],'payment_status'=>['وضعیت پرداخت',['unpaid'=>'پرداخت‌نشده','partial'=>'بخشی پرداخت‌شده','paid'=>'پرداخت‌شده']]] as $key=>[$label,$options]){
        echo '<label>'.e($label).'<select name="'.$key.'"><option value="">همه</option>';
        foreach($options as $value=>$title)echo '<option value="'.$value.'"'.($filter[$key]===$value?' selected':'').'>'.e($title).'</option>';
        echo '</select></label>';
    }
    if(database_table_exists('company_bank_accounts')){
        echo '<label>حساب بانکی<select name="bank_account_id"><option value="">همه</option>';
        foreach(query('SELECT id,title FROM company_bank_accounts WHERE company_id=? ORDER BY title',[$tenant])->fetchAll() as $account)echo '<option value="'.(int)$account['id'].'"'.((string)$filter['bank_account_id']===(string)$account['id']?' selected':'').'>'.e($account['title']).'</option>';
        echo '</select></label>';
    }
    echo '<label>صندوق<select name="cash_register_id"><option value="">همه</option>';
    foreach(query('SELECT r.id,r.name FROM cash_registers r WHERE r.company_id=?'.report_branch_sql($tenant,'r.branch_id',$filter['branch_id']).' ORDER BY r.name',[$tenant])->fetchAll() as $register)
        echo '<option value="'.(int)$register['id'].'"'.((string)$filter['cash_register_id']===(string)$register['id']?' selected':'').'>'.e($register['name']).'</option>';
    echo '</select></label>';
    foreach(['amount_min'=>'حداقل مبلغ (تومان)','amount_max'=>'حداکثر مبلغ (تومان)'] as $key=>$label)echo '<label>'.e($label).'<input name="'.$key.'" inputmode="numeric" data-money-input value="'.e($filter[$key]).'"></label>';
    echo '<button>نمایش گزارش</button>';
    if(can('expenses.export'))echo '<button name="export" value="xlsx" class="secondary">خروجی Excel</button><button name="export" value="csv" class="secondary">خروجی CSV</button>';
    echo '</form>';
}
function expense_report_export(int $tenant,array $filter,string $format): never {
    expense_require_permission('export');
    if(!in_array($format,['xlsx','csv'],true))throw new RuntimeException('نوع خروجی هزینه نامعتبر است.');
    $report=expense_report_data($tenant,$filter,true);
    $company=(string)query('SELECT name FROM companies WHERE id=?',[$tenant])->fetchColumn();
    $headers=['شرکت','شماره سند','تاریخ شمسی','شعبه','ثبت‌کننده','دسته','عنوان','وضعیت','مبلغ کل (تومان)','پرداخت‌شده','مانده سند','بدهی به کارمند'];$rows=[];
    foreach($report['rows'] as $row)$rows[]=[$company,$row['document_number'],jalali_datetime($row['expense_date']),$row['branch_name'],$row['operator_name'],$row['category_name'],$row['title'],expense_approval_label($row['approval_status']),$row['total_amount'],$row['paid_amount'],$row['remaining'],$row['employee_due']];
    $sum=$report['summary'];$rows[]=[$company,'جمع کنترل','','','','','','',$sum['total_amount'],$sum['paid_amount'],$sum['remaining'],$sum['employee_due']];
    if($format==='xlsx')export_excel_xlsx('sodoryar-expenses',$headers,$rows,[8,9,10,11]);
    header('Content-Type: text/csv; charset=UTF-8');header('Content-Disposition: attachment; filename="sodoryar-expenses.csv"');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
    $out=fopen('php://output','wb');fwrite($out,"\xEF\xBB\xBF");fputcsv($out,$headers,',','"','');
    foreach($rows as $row){foreach($row as $index=>&$cell)if(!in_array($index,[8,9,10,11],true) && preg_match('/^(?:\s*[=+@\-]|[\t\r\n])/u',(string)$cell))$cell="'".$cell;unset($cell);fputcsv($out,$row,',','"','');}
    fclose($out);exit;
}

function render_expense_receipt(int $tenant,int $id): void {
    expense_require_permission('view');
    $expense=expense_require_document($tenant,$id);
    $company=(string)query('SELECT name FROM companies WHERE id=?',[$tenant])->fetchColumn();
    $branch=(string)query('SELECT name FROM branches WHERE id=? AND company_id=?',[$expense['branch_id'],$tenant])->fetchColumn();
    $creator=(string)query('SELECT full_name FROM users WHERE id=? AND company_id=?',[$expense['created_by'],$tenant])->fetchColumn();
    $items=query('SELECT * FROM expense_items WHERE company_id=? AND expense_id=? ORDER BY id',[$tenant,$id])->fetchAll();
    $payments=query('SELECT * FROM expense_payments WHERE company_id=? AND expense_id=? ORDER BY id',[$tenant,$id])->fetchAll();
    echo '<section class="receipt receipt-a4 receipt-font-vazirmatn card"><div class="receipt-toolbar"><button type="button" data-print-receipt>چاپ / ذخیره PDF سند هزینه</button></div><h1>سند هزینه شرکت</h1><h2>'.e($company).'</h2>';
    if($expense['approval_status']!=='approved')echo '<p class="receipt-void-label">'.e(expense_approval_label($expense['approval_status'])).' — سند قطعی تأییدشده نیست</p>';
    echo '<p>شماره سند: '.e(display_financial_reference($expense['document_number'])).' | شعبه: '.e($branch).'</p><p>تاریخ هزینه: '.e(jalali_datetime($expense['expense_date'])).' | ثبت‌کننده: '.e($creator).'</p><h3>'.e($expense['title']).'</h3><p>'.nl2br(e($expense['description']??'')).'</p>';
    echo '<table><thead><tr><th>شرح قلم</th><th>مقدار</th><th>مبلغ واحد (تومان)</th><th>جمع (تومان)</th></tr></thead><tbody>';
    foreach($items as $item){
        $quantity=(int)$item['quantity_milli'];$fraction=$quantity%1000;
        $display=(string)intdiv($quantity,1000).($fraction?'.'.rtrim(str_pad((string)$fraction,3,'0',STR_PAD_LEFT),'0'):'');
        echo '<tr><td>'.e($item['title']??$item['description']??'').'</td><td>'.e(fa_digits($display)).'</td><td>'.money_toman($item['unit_price']).'</td><td>'.money_toman($item['line_total']).'</td></tr>';
    }
    echo '</tbody></table><p>جمع اقلام: '.money_toman($expense['subtotal']).' | هزینه اضافی: '.money_toman($expense['extra_amount']).' | تخفیف: '.money_toman($expense['discount_amount']).'</p><h2>مبلغ کل سند: '.money_toman($expense['total_amount']).'</h2><h3>پرداخت‌های ثبت‌شده</h3><table><thead><tr><th>روش</th><th>تاریخ پرداخت</th><th>مبلغ (تومان)</th><th>وضعیت ثبت</th></tr></thead><tbody>';
    foreach($payments as $payment)echo '<tr><td>'.e(expense_method_label($payment['method'])).'</td><td>'.e(jalali_datetime($payment['paid_date'])).'</td><td>'.money_toman($payment['amount']).'</td><td>'.($payment['posted_at']!==null?'ثبت‌شده':'در انتظار تأیید').'</td></tr>';
    echo '</tbody></table>';
    $reimbursements=query('SELECT r.* FROM employee_reimbursements r JOIN expense_payments p ON p.id=r.payment_id AND p.company_id=r.company_id WHERE p.company_id=? AND p.expense_id=? ORDER BY r.id',[$tenant,$id])->fetchAll();
    if($reimbursements){echo '<h3>بازپرداخت به کارکنان (تسویه بدهی؛ هزینه جدید نیست)</h3>';foreach($reimbursements as $row)echo '<p>'.e(jalali_datetime($row['paid_date'])).' — '.e(expense_method_label($row['method'])).' — '.money_toman($row['amount']).'</p>';}
    echo '<p>یادداشت: '.nl2br(e($expense['notes']??'')).'</p><p>زمان ثبت: '.e(jalali_datetime($expense['created_at'],true)).' | نسخه سند: '.e(fa_digits((string)$expense['version'])).'</p><p>امضای ثبت‌کننده: ...................　 امضای تأییدکننده: ...................</p></section>';
}
function expense_dashboard_snapshot(int $tenant): array {
    expense_require_permission('view');
    $now=new DateTimeImmutable('today',new DateTimeZone('Asia/Tehran'));
    [$jy,$jm]=gregorian_to_jalali((int)$now->format('Y'),(int)$now->format('n'),(int)$now->format('j'));
    $from=jalali_to_gregorian_date(sprintf('%04d-%02d-01',$jy,$jm));$to=$now->format('Y-m-d');
    $filter=expense_report_filters(['date_from'=>$from,'date_to'=>$to,'branch_id'=>(string)($_GET['branch_id']??''),'approval_status'=>'approved']);
    [$source,$params]=expense_report_query($tenant,$filter);
    $recognized=query("SELECT COALESCE(SUM(CASE WHEN c.accounting_type='expense' THEN e.total_amount ELSE 0 END),0) recognized_expenses".$source,$params)->fetchColumn();
    $branchSql=$filter['branch_id']!==''?' AND e.branch_id='.(int)$filter['branch_id']:'';
    $scope=expense_scope_sql($tenant,'e').$branchSql;
    $next=$now->modify('+1 day')->format('Y-m-d');
    $payments=query("SELECT COALESCE(SUM(CASE WHEN p.method='cash' THEN p.amount ELSE 0 END),0) cash_paid,
      COALESCE(SUM(CASE WHEN p.method='bank' THEN p.amount ELSE 0 END),0) bank_paid
      FROM expense_payments p JOIN expenses e ON e.id=p.expense_id AND e.company_id=p.company_id
      WHERE e.company_id=? AND e.approval_status='approved' AND p.posted_at IS NOT NULL AND p.paid_date>=? AND p.paid_date<?".$scope,[$tenant,$from,$next])->fetch();
    $reimbursements=query("SELECT COALESCE(SUM(CASE WHEN r.method='cash' THEN r.amount ELSE 0 END),0) cash_paid,
      COALESCE(SUM(CASE WHEN r.method='bank' THEN r.amount ELSE 0 END),0) bank_paid
      FROM employee_reimbursements r JOIN expense_payments p ON p.id=r.payment_id AND p.company_id=r.company_id
      JOIN expenses e ON e.id=p.expense_id AND e.company_id=p.company_id
      WHERE e.company_id=? AND e.approval_status='approved' AND r.paid_date>=? AND r.paid_date<?".$scope,[$tenant,$from,$next])->fetch();
    [$allSource,$allParams]=expense_report_source($tenant);
    $owed=query("SELECT COALESCE(SUM(pay.employee_due),0)".$allSource." AND e.approval_status='approved'".$branchSql,$allParams)->fetchColumn();
    $salesScope=(document_scope()==='own'?' AND i.user_id='.(int)user()['id']:'').report_branch_sql($tenant,'i.branch_id',$filter['branch_id']);
    $valid=database_table_exists('invoice_voids')?' AND NOT EXISTS(SELECT 1 FROM invoice_voids v WHERE v.company_id=i.company_id AND v.invoice_id=i.id)':'';
    $sales=query('SELECT COALESCE(SUM(i.total_amount),0) FROM invoices i WHERE i.company_id=? AND COALESCE(i.invoice_date,DATE(i.created_at))>=? AND COALESCE(i.invoice_date,DATE(i.created_at))<=?'.$salesScope.$valid,[$tenant,$from,$to])->fetchColumn();
    return ['from'=>$from,'to'=>$to,'sales'=>$sales,'recognized_expenses'=>$recognized,'cash_paid'=>expense_report_exact_add((string)$payments['cash_paid'],(string)$reimbursements['cash_paid']),'bank_paid'=>expense_report_exact_add((string)$payments['bank_paid'],(string)$reimbursements['bank_paid']),'employee_due'=>$owed];
}
/** MySQL DECIMAL results may exceed PHP integer range; add without binary floats. */
function expense_report_exact_add(string $left,string $right): string {
    $left=ltrim($left,'0')?:'0';$right=ltrim($right,'0')?:'0';$carry=0;$sum='';
    for($a=strlen($left)-1,$b=strlen($right)-1;$a>=0 || $b>=0 || $carry;$a--,$b--){$digit=($a>=0?(int)$left[$a]:0)+($b>=0?(int)$right[$b]:0)+$carry;$sum=(string)($digit%10).$sum;$carry=intdiv($digit,10);}
    return $sum;
}
function render_expense_dashboard(int $tenant): void {
    if(!can('expenses.view'))return;
    $snapshot=expense_dashboard_snapshot($tenant);
    echo '<section class="card"><h2>هزینه و پرداخت‌های ماه شمسی جاری</h2><p>'.e(jalali_datetime($snapshot['from'])).' تا '.e(jalali_datetime($snapshot['to'])).'</p><div class="stats">';
    foreach(['sales'=>'فروش ثبت‌شده','recognized_expenses'=>'هزینه جاری تأییدشده','cash_paid'=>'پرداخت نقدی اسناد تأییدشده و بازپرداخت کارکنان','bank_paid'=>'پرداخت بانکی اسناد تأییدشده و بازپرداخت کارکنان','employee_due'=>'بدهی باز به کارکنان (تمام دوره‌ها)'] as $key=>$title)
        echo '<article class="stat"><span>'.e($title).'</span><b>'.money_toman($snapshot[$key]).'</b></article>';
    // Decimal subtraction stays in the database; this is a performance indicator, not accounting profit.
    $net=query('SELECT CAST(? AS DECIMAL(30,0))-CAST(? AS DECIMAL(30,0))',[$snapshot['sales'],$snapshot['recognized_expenses']])->fetchColumn();
    echo '<article class="stat"><span>فروش منهای هزینه جاری (شاخص عملکرد، نه سود حسابداری)</span><b>'.money_toman($net).'</b></article></div></section>';
}
