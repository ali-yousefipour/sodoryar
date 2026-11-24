<?php
declare(strict_types=1);

/** Current financial dashboard values use actual receipt timestamps, not invoice dates. */
function dashboard_financial_snapshot(int $tenant): array {
    require_permission('reports.view');
    $day=new DateTimeImmutable('today',new DateTimeZone('Asia/Tehran'));
    $today=$day->format('Y-m-d');
    $tomorrow=$day->modify('+1 day')->format('Y-m-d');
    $empty=[
        'receipts_today'=>0,'cash_today'=>0,'pos_today'=>0,
        'due_total'=>0,'unsettled_count'=>0,
        'open_cashboxes'=>0,'pending_cashboxes'=>0,'unreviewed_issues'=>0,
        'ledger_ready'=>invoice_payment_ledger_ready()
    ];
    if(!invoice_payment_ledger_ready())return $empty;
    $scope=document_scope()==='own'?' AND i.user_id='.(int)user()['id']:'';
    $activeInvoice=database_table_exists('invoice_voids')?
       ' AND NOT EXISTS (SELECT 1 FROM invoice_voids v WHERE v.invoice_id=i.id AND v.company_id=i.company_id)':'';
    $receipt=query('SELECT COALESCE(SUM(CASE WHEN p.payment_method=\'cash\' THEN p.amount ELSE 0 END),0) cash_total,
        COALESCE(SUM(CASE WHEN p.payment_method=\'pos\' THEN p.amount ELSE 0 END),0) pos_total
        FROM invoice_payments p JOIN invoices i ON i.id=p.invoice_id AND i.company_id=p.company_id
        WHERE p.company_id=? AND p.voided_at IS NULL
        AND p.paid_at>=? AND p.paid_at<?'.$scope.$activeInvoice,
        [$tenant,$today.' 00:00:00',$tomorrow.' 00:00:00'])->fetch();
    $due=query('SELECT
        COALESCE(SUM(GREATEST(i.total_amount-COALESCE(pay.paid,0),0)),0) total_due,
        COALESCE(SUM(CASE WHEN COALESCE(pay.paid,0)<i.total_amount THEN 1 ELSE 0 END),0) unsettled
        FROM invoices i LEFT JOIN
        (SELECT company_id,invoice_id,SUM(amount) paid FROM invoice_payments
         WHERE company_id=? AND voided_at IS NULL GROUP BY company_id,invoice_id) pay
        ON pay.company_id=i.company_id AND pay.invoice_id=i.id
        WHERE i.company_id=?'.$scope.$activeInvoice,[$tenant,$tenant])->fetch();
    // Both methods are final receipts on successful posting; no bank approval state.
    $empty['cash_today']=(int)$receipt['cash_total'];
    $empty['pos_today']=(int)$receipt['pos_total'];
    $empty['receipts_today']=$empty['cash_today']+$empty['pos_today'];
    $empty['due_total']=(int)$due['total_due'];
    $empty['unsettled_count']=(int)$due['unsettled'];
    if(function_exists('cashbox_ready')&&cashbox_ready()){
        $operatorScope=document_scope()==='own'||!can('cashbox.manage')?
            ' AND s.operator_id='.(int)user()['id']:'';
        $cashbox=query("SELECT
            COALESCE(SUM(s.status='open'),0) open_boxes,
            COALESCE(SUM(s.status='pending'),0) pending_boxes
            FROM cash_shifts s WHERE s.company_id=?".$operatorScope,[$tenant])->fetch();
        $empty['open_cashboxes']=(int)$cashbox['open_boxes'];
        $empty['pending_cashboxes']=(int)$cashbox['pending_boxes'];
    }
    $issues=financial_control_exceptions($tenant,[
        'date_from'=>(new DateTimeImmutable('today'))->modify('-30 days')->format('Y-m-d'),
        'date_to'=>$today
    ],100);
    if(function_exists('financial_issue_latest_reviews')){
        $reviews=financial_issue_latest_reviews($tenant,$issues);
        foreach($issues as $issue){
            $key=financial_issue_fingerprint($tenant,$issue);
            if(!isset($reviews[$key]))$empty['unreviewed_issues']++;
        }
    }else{$empty['unreviewed_issues']=count($issues);}
    return $empty;
}

/** Detail rows use the same tenant, document scope, dates and active-record filters as dashboard totals. */
function dashboard_detail(int $tenant,string $key,string $today,string $monthStart): array {
    require_permission('reports.view');
    $scope=document_scope()==='own'?' AND i.user_id='.(int)user()['id']:'';
    $valid=database_table_exists('invoice_voids')?' AND NOT EXISTS (SELECT 1 FROM invoice_voids v WHERE v.company_id=i.company_id AND v.invoice_id=i.id)':'';
    $invoiceDate='COALESCE(i.invoice_date,DATE(i.created_at))';
    $customer=" LEFT JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id ";
    $person="COALESCE(CONCAT(c.first_name,' ',c.last_name),'—')";
    $next=(new DateTimeImmutable($today))->modify('+1 day')->format('Y-m-d');
    $headers=[];$rows=[];
    if(in_array($key,['today_count','month_count','today_total','month_total','invoices','revenue'],true)){
        $headers=['شماره فاکتور','تاریخ صدور','تاکسیران','مبلغ (تومان)'];
        $sql="SELECT i.invoice_number,$invoiceDate invoice_day,$person customer,i.total_amount FROM invoices i".$customer."WHERE i.company_id=?".$scope.$valid;
        $params=[$tenant];
        if(str_starts_with($key,'today_')){$sql.=" AND $invoiceDate=?";$params[]=$today;}
        elseif(str_starts_with($key,'month_')){$sql.=" AND $invoiceDate BETWEEN ? AND ?";$params[]=$monthStart;$params[]=$today;}
        foreach(query($sql.' ORDER BY i.id DESC',$params)->fetchAll() as $r)$rows[]=[display_financial_reference($r['invoice_number']),jalali_datetime($r['invoice_day']),$r['customer'],money($r['total_amount'])];
    }elseif(in_array($key,['receipts_today','cash_today','pos_today'],true)){
        if(!invoice_payment_ledger_ready())return [[],[]];
        $headers=['شماره فاکتور','زمان وصول','تاکسیران','روش','شماره پیگیری','مبلغ (تومان)'];
        $sql="SELECT i.invoice_number,p.paid_at,$person customer,p.payment_method,p.tracking_number,p.amount FROM invoice_payments p JOIN invoices i ON i.id=p.invoice_id AND i.company_id=p.company_id".$customer."WHERE p.company_id=? AND p.voided_at IS NULL AND p.paid_at>=? AND p.paid_at<?".$scope.$valid;
        $params=[$tenant,$today.' 00:00:00',$next.' 00:00:00'];
        if($key!=='receipts_today'){$sql.=' AND p.payment_method=?';$params[]=$key==='cash_today'?'cash':'pos';}
        foreach(query($sql.' ORDER BY p.paid_at DESC,p.id DESC',$params)->fetchAll() as $r)$rows[]=[display_financial_reference($r['invoice_number']),jalali_datetime($r['paid_at'],true),$r['customer'],$r['payment_method']==='cash'?'نقدی':'کارتخوان',display_financial_reference($r['tracking_number']??''),money($r['amount'])];
    }elseif(in_array($key,['due_total','unsettled_count'],true)){
        if(!invoice_payment_ledger_ready())return [[],[]];
        $headers=['شماره فاکتور','تاکسیران','مبلغ فاکتور','دریافتی','مانده (تومان)'];
        $sql="SELECT i.invoice_number,$person customer,i.total_amount,COALESCE(pay.paid,0) paid FROM invoices i".$customer." LEFT JOIN (SELECT company_id,invoice_id,SUM(amount) paid FROM invoice_payments WHERE company_id=? AND voided_at IS NULL GROUP BY company_id,invoice_id) pay ON pay.company_id=i.company_id AND pay.invoice_id=i.id WHERE i.company_id=? AND COALESCE(pay.paid,0)<i.total_amount".$scope.$valid;
        foreach(query($sql.' ORDER BY i.id DESC',[$tenant,$tenant])->fetchAll() as $r)$rows[]=[display_financial_reference($r['invoice_number']),$r['customer'],money($r['total_amount']),money($r['paid']),money((int)$r['total_amount']-(int)$r['paid'])];
    }elseif(in_array($key,['open_cashboxes','pending_cashboxes'],true)){
        if(!cashbox_ready())return [[],[]];
        $headers=['شناسه صندوق','اپراتور','زمان شروع','موجودی اولیه (تومان)'];
        $operatorScope=document_scope()==='own'||!can('cashbox.manage')?' AND s.operator_id='.(int)user()['id']:'';
        foreach(query("SELECT s.id,s.operator_id,s.started_at,s.opening_cash,COALESCE(u.full_name,'—') operator_name FROM cash_shifts s LEFT JOIN users u ON u.id=s.operator_id AND (u.company_id=s.company_id OR u.role='superadmin') WHERE s.company_id=? AND s.status=?".$operatorScope.' ORDER BY s.id DESC',[$tenant,$key==='open_cashboxes'?'open':'pending'])->fetchAll() as $r)$rows[]=[fa_digits((string)$r['id']),$r['operator_name'],jalali_datetime($r['started_at'],true),money($r['opening_cash'])];
    }elseif($key==='unreviewed_issues'){
        $headers=['نوع مغایرت','شماره فاکتور','جزئیات'];
        $issues=financial_control_exceptions($tenant,['date_from'=>(new DateTimeImmutable($today))->modify('-30 days')->format('Y-m-d'),'date_to'=>$today],100);
        $reviews=financial_issue_latest_reviews($tenant,$issues);
        foreach($issues as $r)if(!isset($reviews[financial_issue_fingerprint($tenant,$r)]))$rows[]=[$r['kind'],display_financial_reference($r['invoice']),$r['detail']];
    }elseif($key==='customers'){
        $headers=['نام','نام خانوادگی','کد ملی'];
        foreach(query('SELECT first_name,last_name,national_code FROM customers WHERE company_id=? ORDER BY id DESC',[$tenant])->fetchAll() as $r)$rows[]=[$r['first_name'],$r['last_name'],fa_digits($r['national_code'])];
    }elseif($key==='services'){
        $headers=['عنوان خدمت'];
        foreach(query('SELECT * FROM services WHERE company_id=? AND is_active=1 ORDER BY id DESC',[$tenant])->fetchAll() as $r)$rows[]=[$r['title']??$r['name']??fa_digits((string)$r['id'])];
    }
    return [$headers,$rows];
}
function render_dashboard_stat(string $key,string $title,mixed $value,string $note=''): void {
    echo '<article class="stat dashboard-stat-card" role="button" tabindex="0" data-dashboard-detail="'.e($key).'" aria-haspopup="dialog"><span>'.e($title).'</span><b>'.money($value).'</b>';
    if($note!=='')echo '<small>'.e($note).'</small>';
    echo '</article>';
}
