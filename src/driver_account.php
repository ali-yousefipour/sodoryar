<?php
declare(strict_types=1);

/** Customer account snapshot: never multiply invoices by individual receipt rows. */
function driver_account_data(int $tenant,int $customerId,int $page=1): array {
    require_permission('customers.view');
    require_permission('invoices.view');
    $customer=query('SELECT id,national_code,first_name,last_name,mobile,address,line_area,line_code,created_at
        FROM customers WHERE id=? AND company_id=?',[$customerId,$tenant])->fetch();
    if(!$customer) throw new RuntimeException('پرونده تاکسیران در شرکت پیدا نشد.');
    $scope=document_scope()==='own'?' AND i.user_id='.(int)user()['id']:'';
    $valid=database_table_exists('invoice_voids')?
        ' AND NOT EXISTS (SELECT 1 FROM invoice_voids v WHERE v.invoice_id=i.id AND v.company_id=i.company_id)':'';
    $ledger=invoice_payment_ledger_ready();
    $summary=query('SELECT COUNT(*) invoices,COALESCE(SUM(i.total_amount),0) invoiced
        FROM invoices i WHERE i.company_id=? AND i.customer_id=?'.$scope.$valid,
        [$tenant,$customerId])->fetch();
    $invoiceCount=(int)$summary['invoices'];
    if($ledger){
        $receipts=query('SELECT COUNT(p.id) transactions, COALESCE(SUM(p.amount),0) paid,
            COALESCE(SUM(CASE WHEN p.payment_method=\'cash\' THEN p.amount ELSE 0 END),0) cash_paid,
            COALESCE(SUM(CASE WHEN p.payment_method=\'pos\' THEN p.amount ELSE 0 END),0) pos_paid
            FROM invoice_payments p JOIN invoices i ON i.id=p.invoice_id AND i.company_id=p.company_id
            WHERE i.company_id=? AND i.customer_id=? AND p.voided_at IS NULL'.$scope.$valid,
            [$tenant,$customerId])->fetch();
    }else{
        $receipts=['transactions'=>$invoiceCount,'paid'=>$summary['invoiced'],'cash_paid'=>0,'pos_paid'=>0];
    }
    $page=max(1,min(100000,$page));
    $offset=($page-1)*30;
    $paidExpr=$ledger?'COALESCE(paid.paid,0)':'i.total_amount';
    $paymentJoin=$ledger?
        ' LEFT JOIN (SELECT company_id,invoice_id,SUM(amount) paid
                     FROM invoice_payments WHERE company_id='.(int)$tenant.'
                     AND voided_at IS NULL GROUP BY company_id,invoice_id) paid
                     ON paid.company_id=i.company_id AND paid.invoice_id=i.id':'';
    $invoices=query('SELECT i.id,i.invoice_number,i.invoice_date,i.created_at,i.total_amount,'
        .$paidExpr.' received,'.($ledger?'GREATEST(0,i.total_amount-'.$paidExpr.')':'0').' balance
        FROM invoices i'.$paymentJoin.'
        WHERE i.company_id=? AND i.customer_id=?'.$scope.$valid.'
        ORDER BY i.id DESC LIMIT 30 OFFSET '.(int)$offset,[$tenant,$customerId])->fetchAll();
    $history=[];
    if($ledger) {
        $history=query('SELECT p.id,p.amount,p.payment_method,p.paid_at,p.tracking_number,
             i.invoice_number,u.full_name operator_name
             FROM invoice_payments p
             JOIN invoices i ON i.id=p.invoice_id AND i.company_id=p.company_id
             JOIN users u ON u.id=p.created_by AND (u.company_id=p.company_id OR u.role=\'superadmin\')
             WHERE i.company_id=? AND i.customer_id=? AND p.voided_at IS NULL'.$scope.$valid.'
             ORDER BY p.paid_at DESC,p.id DESC LIMIT 30',[$tenant,$customerId])->fetchAll();
    }
    return ['customer'=>$customer,'summary'=>$summary,'receipts'=>$receipts,'invoices'=>$invoices,
      'history'=>$history,'page'=>$page,'page_count'=>max(1,(int)ceil($invoiceCount/30)),'has_ledger'=>$ledger];
}

function render_driver_account(int $tenant,int $customerId): void {
    $data=driver_account_data($tenant,$customerId,(int)($_GET['p']??1));
    $customer=$data['customer'];$invoiced=(int)$data['summary']['invoiced'];$paid=(int)$data['receipts']['paid'];
    echo '<div class="heading"><div><h1>پرونده مالی تاکسیران</h1><p class="muted">'
      .e($customer['first_name'].' '.$customer['last_name']).' | کد ملی '
      .e(fa_digits($customer['national_code'])).' | همراه '.e(fa_digits((string)$customer['mobile']))
      .'</p></div>'.(can('reports.export')?'<a class="button-link" href="?page=driver_account&amp;id='.(int)$customerId.'&amp;export=excel">خروجی Excel پرونده</a>':'').'<a class="button-link" href="?page=customers">بازگشت به تاکسیرانان</a></div>';
    echo '<div class="stats"><article class="stat"><span>تعداد فاکتور معتبر</span><b>'.fa_digits((string)$data['summary']['invoices']).'</b></article>'
      .'<article class="stat"><span>جمع خدمات و فاکتورها</span><b>'.money_toman($invoiced).'</b></article>'
      .'<article class="stat"><span>جمع وجوه دریافت‌شده</span><b>'.money_toman($paid).'</b></article>'
      .'<article class="stat"><span>مانده مطالبات</span><b>'.money_toman(max(0,$invoiced-$paid)).'</b></article></div>';
    echo '<div class="card"><h2>مشخصات پرونده</h2><p>خط فعالیت: '.e($customer['line_area']?:'ثبت نشده')
      .' | کد خط: '.e($customer['line_code']?:'ثبت نشده').'</p>'
      .'<p>نشانی: '.e($customer['address']?:'ثبت نشده').'</p></div>';
    echo '<div class="card"><h2>سوابق فاکتورها</h2><div class="table-wrap"><table><thead>'
      .'<tr><th>شماره فاکتور</th><th>تاریخ</th><th>مبلغ</th><th>دریافتی</th><th>مانده</th><th>سند</th></tr></thead><tbody>';
    foreach($data['invoices'] as $invoice){
        echo '<tr><td>'.e(display_financial_reference($invoice['invoice_number'])).'</td>'
          .'<td>'.e(jalali_datetime($invoice['invoice_date']?:$invoice['created_at'],true)).'</td>'
          .'<td>'.money_toman($invoice['total_amount']).'</td><td>'.money_toman($invoice['received']).'</td>'
          .'<td>'.money_toman($invoice['balance']).'</td><td>';
        if(can('invoices.print')) echo '<a href="?page=receipt&amp;id='.(int)$invoice['id'].'">مشاهده و چاپ</a>';
        echo '</td></tr>';
    }
    if(!$data['invoices']) echo '<tr><td colspan="6">فاکتوری ثبت نشده است.</td></tr>';
    echo '</tbody></table></div>';
    if($data['page']>1) echo '<a class="button-link" href="?page=driver_account&amp;id='.(int)$customerId.'&amp;p='.($data['page']-1).'">صفحه قبل</a> ';
    if($data['page']<$data['page_count']) echo '<a class="button-link" href="?page=driver_account&amp;id='.(int)$customerId.'&amp;p='.($data['page']+1).'">صفحه بعد</a>';
    echo '</div>';
    echo '<div class="card"><h2>آخرین دریافت‌ها</h2><div class="table-wrap"><table><thead><tr>'
      .'<th>زمان دریافت</th><th>فاکتور</th><th>روش</th><th>مبلغ</th><th>اپراتور دریافت‌کننده</th></tr></thead><tbody>';
    foreach($data['history'] as $row){
        echo '<tr><td>'.e(jalali_datetime($row['paid_at'],true)).'</td>'
          .'<td>'.e(display_financial_reference($row['invoice_number'])).'</td>'
          .'<td>'.($row['payment_method']==='cash'?'نقدی':'کارتخوان').'</td>'
          .'<td>'.money_toman($row['amount']).'</td><td>'.e($row['operator_name']).'</td></tr>';
    }
    if(!$data['history']) echo '<tr><td colspan="5">دریافتی ثبت نشده است.</td></tr>';
    echo '</tbody></table></div></div>';
}

/** Complete filtered financial customer statement with real numeric XLSX amounts. */
function export_driver_account(int $tenant,int $customerId): never {
    require_permission('customers.view');
    require_permission('invoices.view');
    require_permission('reports.export');
    $data=driver_account_data($tenant,$customerId);
    if((int)$data['summary']['invoices']>10000) {
        throw new RuntimeException('تعداد فاکتورهای پرونده بیش از سقف گزارش است؛ ابتدا گزارش دوره‌ای تهیه کنید.');
    }
    $scope=document_scope()==='own'?' AND i.user_id='.(int)user()['id']:'';
    $valid=database_table_exists('invoice_voids')?
        ' AND NOT EXISTS (SELECT 1 FROM invoice_voids v WHERE v.invoice_id=i.id AND v.company_id=i.company_id)':'';
    $ledger=invoice_payment_ledger_ready();
    $paid=$ledger?'COALESCE(pay.paid,0)':'i.total_amount';
    $join=$ledger?
        ' LEFT JOIN (SELECT company_id,invoice_id,SUM(amount) paid FROM invoice_payments
                    WHERE company_id='.(int)$tenant.' AND voided_at IS NULL GROUP BY company_id,invoice_id) pay
                    ON pay.company_id=i.company_id AND pay.invoice_id=i.id':'';
    $records=query('SELECT i.invoice_number,i.invoice_date,i.created_at,i.total_amount,'.$paid.' collected
        FROM invoices i'.$join.'
        WHERE i.company_id=? AND i.customer_id=?'.$scope.$valid.'
        ORDER BY i.id DESC LIMIT 10000',[$tenant,$customerId])->fetchAll();
    $rows=[];
    foreach($records as $index=>$record){
        $amount=(int)$record['total_amount'];$collected=(int)$record['collected'];
        $rows[]=[(int)$index+1,display_financial_reference($record['invoice_number']),
            jalali_datetime($record['invoice_date']?:$record['created_at'],true),
            fa_digits($data['customer']['national_code']),$data['customer']['first_name'].' '.$data['customer']['last_name'],
            $amount,$collected,max(0,$amount-$collected)];
    }
    export_excel_xlsx('sodoryar-driver-account',['ردیف','شماره فاکتور','تاریخ شمسی',
        'کد ملی','تاکسیران','مبلغ فاکتور','دریافتی','مانده'],$rows,[0,5,6,7]);
}
