<?php
declare(strict_types=1);

/** Invoice management is read-only; mutation remains on the permission-gated invoice routes. */
function invoice_management_filters(array $input): array {
    $text=static function(string $key,int $max=120) use ($input): string {
        $value=$input[$key]??'';
        if (!is_string($value)) throw new RuntimeException('ساختار فیلتر جستجو نامعتبر است.');
        return mb_substr(trim($value),0,$max);
    };
    $f=[
        'q'=>$text('q',120),
        'date_from'=>$text('date_from',10),
        'date_to'=>$text('date_to',10),
        'method'=>$text('method',12),
        'status'=>$text('status',12),
        'settlement'=>$text('settlement',12),
        'user_id'=>$text('user_id',18),
        'service'=>$text('service',120),
        'amount'=>$text('amount',20),
        'sort'=>$text('sort',20),
        'direction'=>$text('direction',4),
        'page'=>$text('p',8),
        'size'=>$text('size',3),
    ];
    foreach (['date_from','date_to'] as $key) {
        if ($f[$key]==='') continue;
        $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',ascii_digits($f[$key]));
        if (!$parsed || $parsed->format('Y-m-d')!==ascii_digits($f[$key])) throw new RuntimeException('بازه تاریخ نامعتبر است.');
        $f[$key]=ascii_digits($f[$key]);
    }
    if ($f['date_from']!=='' && $f['date_to']!=='' && $f['date_from']>$f['date_to']) throw new RuntimeException('تاریخ شروع نمی‌تواند بعد از تاریخ پایان باشد.');
    if (!in_array($f['method'],['','cash','pos','mixed'],true)) throw new RuntimeException('روش پرداخت نامعتبر است.');
    if (!in_array($f['status'],['','valid','void'],true)) throw new RuntimeException('وضعیت فاکتور نامعتبر است.');
    if (!in_array($f['settlement'],['','paid','due'],true)) throw new RuntimeException('وضعیت تسویه نامعتبر است.');
    if ($f['user_id']!=='' && !ctype_digit(ascii_digits($f['user_id']))) throw new RuntimeException('شناسه کاربر نامعتبر است.');
    $f['user_id']=ascii_digits($f['user_id']);
    if ($f['amount']!=='') {
        $parsed=parse_money_input($f['amount'],0,100000000000);
        if ($parsed===false) throw new RuntimeException('مبلغ فیلتر نامعتبر است.');
        $f['amount']=(string)$parsed;
    }
    $f['sort']=in_array($f['sort'],['date','amount','number','customer','paid'],true)?$f['sort']:'date';
    $f['direction']=$f['direction']==='asc'?'asc':'desc';
    $f['page']=max(1,min(100000,(int)ascii_digits($f['page']?:'1')));
    $f['size']=in_array((int)ascii_digits($f['size']),[20,50,100],true)?(int)ascii_digits($f['size']):20;
    $f['q']=ascii_digits($f['q']);
    return $f;
}

function invoice_management_data(int $tenant,array $f,bool $exportAll=false): array {
    $hasLedger=invoice_payment_ledger_ready();
    $hasVoids=database_table_exists('invoice_voids');
    $paymentJoin=$hasLedger
        ? ' LEFT JOIN (SELECT company_id,invoice_id, SUM(amount) paid, SUM(payment_method=\'cash\') cash_count,SUM(payment_method=\'pos\') pos_count, SUM(CASE WHEN payment_method=\'cash\' THEN amount ELSE 0 END) cash_amount,SUM(CASE WHEN payment_method=\'pos\' THEN amount ELSE 0 END) pos_amount,COUNT(*) payment_count FROM invoice_payments WHERE company_id='.(int)$tenant.' AND voided_at IS NULL GROUP BY company_id,invoice_id) pay ON pay.company_id=i.company_id AND pay.invoice_id=i.id'
        : '';
    $paidExpr=$hasLedger?'COALESCE(pay.paid,0)':'i.total_amount';
    $cashCount=$hasLedger?'COALESCE(pay.cash_count,0)':"IF(i.payment_type='cash',1,0)";
    $posCount=$hasLedger?'COALESCE(pay.pos_count,0)':"IF(i.payment_type='pos',1,0)";
    $cashAmount=$hasLedger?'COALESCE(pay.cash_amount,0)':"IF(i.payment_type='cash',i.total_amount,0)";
    $posAmount=$hasLedger?'COALESCE(pay.pos_amount,0)':"IF(i.payment_type='pos',i.total_amount,0)";
    $voidJoin=$hasVoids?' LEFT JOIN invoice_voids v ON v.company_id=i.company_id AND v.invoice_id=i.id':'';
    $voidExpr=$hasVoids?'v.invoice_id IS NOT NULL':'FALSE';
    $source=' FROM invoices i JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id'.
        ' LEFT JOIN users u ON u.id=i.user_id AND (u.company_id=i.company_id OR u.role=\'superadmin\')'.
        $paymentJoin.$voidJoin.
        ' WHERE i.company_id=?';
    $params=[$tenant];
    if (document_scope()==='own') { $source.=' AND i.user_id=?'; $params[]=(int)user()['id']; }
    if ($f['q']!=='') {
        $source.=' AND (i.invoice_number LIKE ? OR c.national_code LIKE ? OR c.mobile LIKE ? OR c.first_name LIKE ? OR c.last_name LIKE ? OR CONCAT(c.first_name,\' \',c.last_name) LIKE ?)';
        $pattern='%'.$f['q'].'%';$params=array_merge($params,array_fill(0,6,$pattern));
    }
    if ($f['date_from']!=='') {$source.=' AND COALESCE(i.invoice_date,DATE(i.created_at))>=?';$params[]=$f['date_from'];}
    if ($f['date_to']!=='') {$source.=' AND COALESCE(i.invoice_date,DATE(i.created_at))<=?';$params[]=$f['date_to'];}
    if ($f['user_id']!=='') {$source.=' AND i.user_id=?';$params[]=(int)$f['user_id'];}
    if ($f['amount']!=='') {$source.=' AND i.total_amount=?';$params[]=$f['amount'];}
    if ($f['service']!=='') {
        $source.=' AND EXISTS (SELECT 1 FROM invoice_items it WHERE it.company_id=i.company_id AND it.invoice_id=i.id AND it.service_title LIKE ?)';
        $params[]='%'.$f['service'].'%';
    }
    if ($f['status']==='valid') $source.=' AND NOT ('.$voidExpr.')';
    if ($f['status']==='void') $source.=' AND ('.$voidExpr.')';
    if ($f['settlement']==='paid') $source.=' AND ('.$paidExpr.')>=i.total_amount';
    if ($f['settlement']==='due') $source.=' AND ('.$paidExpr.')<i.total_amount';
    if ($f['method']==='cash') $source.=' AND ('.$cashCount.')>0';
    if ($f['method']==='pos') $source.=' AND ('.$posCount.')>0';
    if ($f['method']==='mixed') $source.=' AND ('.$cashCount.')>0 AND ('.$posCount.')>0';
    // Financial KPIs exclude voided invoices but retain filtered table counts.
    $summary=query('SELECT COUNT(*) invoice_count,'.
        ' COALESCE(SUM(CASE WHEN NOT ('.$voidExpr.') THEN i.total_amount ELSE 0 END),0) invoiced,'.
        ' COALESCE(SUM(CASE WHEN NOT ('.$voidExpr.') THEN ('.$paidExpr.') ELSE 0 END),0) received,'.
        ' COALESCE(SUM(CASE WHEN NOT ('.$voidExpr.') THEN ('.$cashAmount.') ELSE 0 END),0) cash_total,'.
        ' COALESCE(SUM(CASE WHEN NOT ('.$voidExpr.') THEN ('.$posAmount.') ELSE 0 END),0) pos_total'.
        $source,$params)->fetch();
    $orders=[
        'date'=>'COALESCE(i.invoice_date,DATE(i.created_at))',
        'amount'=>'i.total_amount','number'=>'i.invoice_number',
        'customer'=>'c.last_name','paid'=>$paidExpr
    ];
    $order=$orders[$f['sort']].($f['direction']==='asc'?' ASC':' DESC').', i.id DESC';
    $offset=$exportAll?0:(($f['page']-1)*$f['size']);
    $limit=$exportAll?10000:$f['size'];
    $rows=query('SELECT i.id,i.invoice_number,i.invoice_date,i.created_at,i.total_amount,i.payment_type,'.
        ' c.first_name,c.last_name,c.national_code,c.mobile,COALESCE(u.full_name,\'—\') operator_name,'.
        ' ('.$paidExpr.') paid_amount,('.$cashCount.') cash_count,('.$posCount.') pos_count,'.
        ' ('.$voidExpr.') is_void,'.
        ' (SELECT GROUP_CONCAT(DISTINCT it.service_title ORDER BY it.service_title SEPARATOR 0xD88C20) FROM invoice_items it WHERE it.company_id=i.company_id AND it.invoice_id=i.id) services'.
        $source.' ORDER BY '.$order.' LIMIT '.(int)$limit.' OFFSET '.(int)$offset,$params)->fetchAll();
    return ['summary'=>$summary,'rows'=>$rows,'has_ledger'=>$hasLedger,'page_count'=>max(1,(int)ceil(((int)$summary['invoice_count'])/$f['size']))];
}

/** Export only permitted, filtered invoice documents; amounts remain numeric Excel cells. */
function export_invoice_management(int $tenant): never {
    require_permission('invoices.view');
    require_permission('reports.export');
    $f=invoice_management_filters($_GET);
    $result=invoice_management_data($tenant,$f,true);
    if ((int)$result['summary']['invoice_count']>10000) {
        throw new RuntimeException('بیش از ۱۰٬۰۰۰ فاکتور با این فیلترها یافت شد؛ بازه را محدود کنید.');
    }
    $headMap=[
        'row'=>'ردیف','number'=>'شماره فاکتور','date'=>'تاریخ شمسی','customer'=>'مشتری',
        'national'=>'کد ملی','mobile'=>'موبایل','services'=>'شرح خدمات',
        'amount'=>'مبلغ فاکتور (تومان)','paid'=>'دریافتی (تومان)',
        'remaining'=>'مانده (تومان)','method'=>'روش پرداخت','operator'=>'صادرکننده','status'=>'وضعیت'
    ];
    $columnsRaw=$_GET['columns']??'';
    if (is_array($columnsRaw)) {
        if (count($columnsRaw)>count($headMap) || !array_is_list($columnsRaw)) throw new RuntimeException('تنظیم ستون‌های خروجی نامعتبر است.');
        foreach($columnsRaw as $value) if (!is_string($value)) throw new RuntimeException('ستون خروجی نامعتبر است.');
        $selected=array_values(array_unique($columnsRaw));
    } elseif (is_string($columnsRaw) && strlen($columnsRaw)<=300) {
        $selected=$columnsRaw===''?array_keys($headMap):array_values(array_unique(explode(',',$columnsRaw)));
    } else throw new RuntimeException('تنظیمات ستون‌های خروجی معتبر نیست.');
    if (!$selected || count($selected)>count($headMap)) throw new RuntimeException('حداقل یک ستون خروجی باید انتخاب شود.');
    foreach($selected as $column) if (!array_key_exists($column,$headMap)) throw new RuntimeException('ستون خروجی ناشناخته است.');
    $heads=array_map(static fn($key)=>$headMap[$key],$selected);
    $rows=[];
    foreach($result['rows'] as $i=>$item) {
        $cash=(int)$item['cash_count'];$pos=(int)$item['pos_count'];
        $method=$cash>0 && $pos>0?'ترکیبی':($pos>0?'کارتخوان':'نقدی');
        $amount=(int)$item['total_amount'];$paid=(int)$item['paid_amount'];
        $void=(bool)$item['is_void'];
        $record=[
            'row'=>$i+1,'number'=>display_financial_reference($item['invoice_number']),
            'date'=>jalali_datetime($item['invoice_date']?:$item['created_at'],true),
            'customer'=>$item['first_name'].' '.$item['last_name'],
            'national'=>fa_digits((string)$item['national_code']),
            'mobile'=>fa_digits((string)$item['mobile']),'services'=>(string)$item['services'],
            'amount'=>$amount,'paid'=>$paid,'remaining'=>max(0,$amount-$paid),
            'method'=>$method,'operator'=>$item['operator_name'],
            'status'=>$void?'باطل‌شده':($paid>=$amount?'تسویه‌شده':'دارای مانده')
        ];
        $rows[]=array_map(static fn($key)=>$record[$key],$selected);
    }
    $numericColumns=[];
    foreach($selected as $index=>$column) if (in_array($column,['row','amount','paid','remaining'],true)) $numericColumns[]=$index;
    export_excel_xlsx('sodoryar-invoice-management',$heads,$rows,$numericColumns);
}

/** Recent issuance and cash receipts are separate accounting timelines. */
function invoice_management_trend(int $tenant): array {
    $today=new DateTimeImmutable('today',new DateTimeZone('Asia/Tehran'));
    $start=$today->modify('-6 days')->format('Y-m-d');
    $days=[];
    for ($i=6;$i>=0;$i--) {
        $date=$today->modify('-'.$i.' days')->format('Y-m-d');
        $days[$date]=['date'=>$date,'invoices'=>0,'sales'=>0,'receipts'=>0];
    }
    $owner=document_scope()==='own';
    $scope=$owner?' AND i.user_id=?':'';
    $void=database_table_exists('invoice_voids')?' AND NOT EXISTS (SELECT 1 FROM invoice_voids v WHERE v.company_id=i.company_id AND v.invoice_id=i.id)':'';
    $sales=query('SELECT COALESCE(i.invoice_date,DATE(i.created_at)) d,COUNT(*) count_invoices,SUM(i.total_amount) total'.
        ' FROM invoices i WHERE i.company_id=? AND COALESCE(i.invoice_date,DATE(i.created_at))>=?'.$scope.$void.
        ' GROUP BY COALESCE(i.invoice_date,DATE(i.created_at))',
        $owner?[$tenant,$start,(int)user()['id']]:[$tenant,$start])->fetchAll();
    foreach($sales as $row) if(isset($days[$row['d']])) {
        $days[$row['d']]['invoices']=(int)$row['count_invoices'];
        $days[$row['d']]['sales']=(int)$row['total'];
    }
    if (invoice_payment_ledger_ready()) {
        $receipts=query('SELECT DATE(p.paid_at) d,SUM(p.amount) total'.
            ' FROM invoice_payments p JOIN invoices i ON i.company_id=p.company_id AND i.id=p.invoice_id'.
            ' WHERE p.company_id=? AND p.voided_at IS NULL AND DATE(p.paid_at)>=?'.$scope.$void.
            ' GROUP BY DATE(p.paid_at)',
            $owner?[$tenant,$start,(int)user()['id']]:[$tenant,$start])->fetchAll();
        foreach($receipts as $row) if(isset($days[$row['d']])) $days[$row['d']]['receipts']=(int)$row['total'];
    } else {
        foreach($days as &$day) $day['receipts']=$day['sales'];
        unset($day);
    }
    return array_values($days);
}


/** A separate read-only document view; do not expose internal audit notes to ordinary operators. */
function render_invoice_manager_detail(int $tenant,int $id): void {
    require_permission('invoices.view');
    $branch=branches_ready();
    $invoice=query("SELECT i.*,c.first_name,c.last_name,c.national_code,c.mobile,c.line_area,c.line_code,u.full_name issuer".($branch?',b.name branch_name':',NULL branch_name')." FROM invoices i JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id LEFT JOIN users u ON u.id=i.user_id AND (u.company_id=i.company_id OR u.role='superadmin')".($branch?' LEFT JOIN branches b ON b.id=i.branch_id AND b.company_id=i.company_id':'')." WHERE i.company_id=? AND i.id=?",[$tenant,$id])->fetch();
    echo '<div class="heading"><h1>جزئیات فاکتور</h1><a class="button-link secondary" href="?page=invoice_manager">بازگشت به گزارش فاکتورها</a></div>';
    if(!$invoice){echo '<div class="alert error">فاکتور پیدا نشد.</div>';return;}
    require_document_owner((int)$invoice['user_id']);
    if($branch && user()['role']==='operator' && !in_array((int)$invoice['branch_id'],array_map('intval',array_column(branch_choices($tenant,(int)user()['id']),'id')),true)){echo '<div class="alert error">این سند خارج از شعب مجاز شماست.</div>';return;}
    $manager=in_array(user()['role'],['company_admin','superadmin'],true)||can('invoices.payment_edit')||can('invoices.void');
    $void=database_table_exists('invoice_voids')?query("SELECT v.reason,v.voided_at,u.full_name actor FROM invoice_voids v LEFT JOIN users u ON u.id=v.voided_by WHERE v.company_id=? AND v.invoice_id=?",[$tenant,$id])->fetch():false;
    $fields=['شماره فاکتور'=>display_financial_reference($invoice['invoice_number']),'تاریخ سند'=>jalali_datetime($invoice['invoice_date']?:$invoice['created_at'],true),'زمان ثبت'=>jalali_datetime($invoice['created_at']),'صادرکننده'=>$invoice['issuer'],'شعبه صادرکننده'=>$invoice['branch_name'],'وضعیت'=>$void?'باطل‌شده':'معتبر','نام تاکسیران'=>$invoice['first_name'].' '.$invoice['last_name'],'کد ملی'=>fa_digits($invoice['national_code']),'تلفن همراه'=>fa_digits((string)$invoice['mobile']),'خط فعالیت'=>$invoice['line_area'],'کد خط'=>fa_digits((string)$invoice['line_code'])];
    echo '<section class="card"><h2>مشخصات سند و تاکسیران</h2><div class="form-grid">';
    foreach($fields as $label=>$value)echo '<div><small class="muted">'.e($label).'</small><p>'.e((string)($value?:'—')).'</p></div>';
    echo '</div></section><section class="card"><h2>خدمات دریافتی</h2><div class="table-wrap"><table><thead><tr><th>خدمت</th><th>مبلغ</th></tr></thead><tbody>';
    foreach(query('SELECT service_title,price FROM invoice_items WHERE company_id=? AND invoice_id=? ORDER BY id',[$tenant,$id])->fetchAll() as $item)echo '<tr><td>'.e($item['service_title']).'</td><td>'.money_toman($item['price']).'</td></tr>';
    echo '</tbody></table></div><p>جمع کل: <strong>'.money_toman($invoice['total_amount']).'</strong></p></section>';
    $totals=invoice_payment_totals($tenant,$id,(int)$invoice['total_amount']);
    echo '<section class="card"><h2>پرداخت‌ها</h2><div class="table-wrap"><table><thead><tr><th>روش</th><th>مبلغ</th><th>شماره پیگیری</th><th>زمان</th></tr></thead><tbody>';
    foreach($totals['payments'] as $pay)echo '<tr><td>'.($pay['payment_method']==='cash'?'نقدی':'کارتخوان').'</td><td>'.money_toman($pay['amount']).'</td><td>'.e(display_financial_reference($pay['tracking_number']??'')).'</td><td>'.e(jalali_datetime($pay['paid_at'])).'</td></tr>';
    echo '</tbody></table></div><p>دریافتی: '.money_toman($totals['paid']).' | مانده: '.money_toman($totals['remaining']).'</p></section>';
    if(!$manager)return;
    echo '<section class="card"><h2>سابقه ابطال</h2>';
    if($void)echo '<p>دلیل: '.e($void['reason']).'</p><p>ابطال‌کننده: '.e($void['actor']??'—').' | زمان: '.e(jalali_datetime($void['voided_at'])).'</p>';else echo '<p>سند ابطال نشده است.</p>';
    echo '</section><section class="card"><h2>تاریخچه ویرایش</h2>';
    $logs=query("SELECT l.action,l.details,l.created_at,u.full_name actor FROM activity_logs l LEFT JOIN users u ON u.id=l.user_id WHERE l.company_id=? AND l.action IN ('invoice.payment.update','invoice.payments.replace') AND CASE WHEN JSON_VALID(l.details) THEN JSON_UNQUOTE(JSON_EXTRACT(l.details,'$.invoice_id')) ELSE NULL END=? ORDER BY l.id DESC",[$tenant,(string)$id])->fetchAll();
    if(!$logs)echo '<p>سابقه ویرایشی ثبت نشده است.</p>';
    foreach($logs as $log){
        $d=json_decode((string)$log['details'],true);
        echo '<details class="card"><summary>'.e($log['actor']??'—').' — '.e(jalali_datetime($log['created_at'])).'</summary><p>دلیل: '.e((string)($d['reason']??'—')).'</p>';
        foreach(($d['receipts']??[]) as $change){
            if(!is_array($change)||!isset($change['before'],$change['after']))continue;
            echo '<div class="table-wrap"><table><thead><tr><th>مشخصه</th><th>قبل</th><th>بعد</th></tr></thead><tbody>';
            foreach(['amount'=>'مبلغ (تومان)','payment_method'=>'روش پرداخت','terminal_id'=>'شناسه کارتخوان','tracking_number'=>'پیگیری','reference_number'=>'مرجع'] as $key=>$label){
                $before=$change['before'][$key]??null;$after=$change['after'][$key]??null;
                $format=static fn($v)=>$v===null?'—':($key==='amount'?money_toman($v):($key==='payment_method'?($v==='cash'?'نقدی':'کارتخوان'):fa_digits((string)$v)));
                echo '<tr><td>'.e($label).'</td><td>'.e($format($before)).'</td><td>'.e($format($after)).'</td></tr>';
            }
            echo '</tbody></table></div>';
        }
        if(isset($d['before'],$d['after']))echo '<p>روش قبلی: '.e((string)($d['before']['payment_type']??'—')).' | روش جدید: '.e((string)($d['after']['payment_type']??'—')).'</p><p>پیگیری قبلی: '.e(fa_digits((string)($d['before']['pos_ref']??'—'))).' | پیگیری جدید: '.e(fa_digits((string)($d['after']['pos_ref']??'—'))).'</p>';
        echo '</details>';
    }
    echo '</section>';
}

function render_invoice_management(int $tenant): void {
    if(isset($_GET['detail'])){render_invoice_manager_detail($tenant,(int)$_GET['detail']);return;}
    require_permission('invoices.view');
    try {$f=invoice_management_filters($_GET);} catch(RuntimeException $ex) {
        echo '<div class="alert error">'.e($ex->getMessage()).'</div>';
        $f=invoice_management_filters([]);
    }
    $data=invoice_management_data($tenant,$f);
    $sum=$data['summary'];$rows=$data['rows'];
    $url=static fn(array $patch=[])=>'?'.http_build_query(array_merge(
        ['page'=>'invoice_manager'],
        array_filter(array_diff_key($f,['page'=>true]),static fn($v)=>$v!==''),
        ['p'=>$f['page']],
        $patch
    ));
    echo '<section class="manager-heading"><div><h1>مدیریت و گزارش فاکتورها</h1><p class="muted">جستجوی یکپارچه اسناد، ریز دریافت‌ها، وضعیت تسویه و چاپ مجدد</p></div>';
    if (can('invoices.create')) echo '<a class="button-link" href="?page=invoices">+ صدور فاکتور</a>';
    echo '</section>';
    echo '<div class="stats manager-summary">';
    foreach ([
        ['تعداد فاکتورها',$sum['invoice_count'],false],
        ['مبلغ فاکتورهای معتبر',$sum['invoiced'],true],
        ['جمع دریافت‌ها',$sum['received'],true],
        ['نقدی',$sum['cash_total'],true],
        ['کارتخوان',$sum['pos_total'],true],
        ['مانده مطالبات',max(0,(int)$sum['invoiced']-(int)$sum['received']),true],
    ] as [$label,$value,$isMoney]) echo '<article class="stat"><span>'.e($label).'</span><b>'.money($value).'</b>'.($isMoney?'<small>تومان</small>':'').'</article>';
    echo '</div>';
    $trend=invoice_management_trend($tenant);
    $maxTrend=max(1,...array_map(static fn($day)=>max($day['sales'],$day['receipts']),$trend));
    echo '<section class="card manager-trend"><div class="heading"><h2>روند صدور و دریافت وجه — ۷ روز اخیر</h2><span class="muted">مبنای فروش تاریخ فاکتور و مبنای دریافت تاریخ تراکنش است</span></div><div class="manager-trend-list">';
    foreach($trend as $day) {
        echo '<div class="manager-trend-row"><span>'.e(jalali_datetime($day['date'],true)).'</span><div class="manager-trend-bars">'.
            '<label>فروش <progress max="'.(int)$maxTrend.'" value="'.(int)$day['sales'].'"></progress><small>'.money_toman($day['sales']).'</small></label>'.
            '<label>دریافتی <progress class="manager-receipt-progress" max="'.(int)$maxTrend.'" value="'.(int)$day['receipts'].'"></progress><small>'.money_toman($day['receipts']).'</small></label>'.
            '</div></div>';
    }
    echo '</div></section>';
    $today=(new DateTimeImmutable('now',new DateTimeZone('Asia/Tehran')))->format('Y-m-d');
    $yesterday=(new DateTimeImmutable($today))->modify('-1 day')->format('Y-m-d');
    $weekStart=(new DateTimeImmutable($today))->modify('-6 days')->format('Y-m-d');
    [$jy,$jm]=gregorian_to_jalali((int)substr($today,0,4),(int)substr($today,5,2),(int)substr($today,8,2));
    $monthStart=jalali_to_gregorian_date(sprintf('%04d/%02d/01',$jy,$jm));
    echo '<nav class="manager-quick-filters" aria-label="فیلتر سریع تاریخ">';
    foreach([
        ['امروز',['date_from'=>$today,'date_to'=>$today,'p'=>1]],
        ['دیروز',['date_from'=>$yesterday,'date_to'=>$yesterday,'p'=>1]],
        ['۷ روز اخیر',['date_from'=>$weekStart,'date_to'=>$today,'p'=>1]],
        ['ماه جاری شمسی',['date_from'=>$monthStart,'date_to'=>$today,'p'=>1]],
        ['همه تاریخ‌ها',['date_from'=>'','date_to'=>'','p'=>1]]
    ] as [$label,$patch]) echo '<a class="button-link secondary" href="'.e($url($patch)).'">'.e($label).'</a>';
    echo '</nav>';
    echo '<form class="card manager-filters" method="get"><input type="hidden" name="page" value="invoice_manager"><div class="form-grid">';
    echo '<label>شماره، نام، کد ملی یا همراه<input type="search" name="q" value="'.e($f['q']).'" placeholder="جستجو در فاکتورها"></label>';
    echo '<label>عنوان خدمت<input type="search" name="service" value="'.e($f['service']).'" placeholder="بخشی از شرح خدمت"></label>';
    echo '<label>مبلغ دقیق (تومان)<input type="text" inputmode="numeric" data-money-input data-currency="تومان" name="amount" value="'.e($f['amount']).'" placeholder="مبلغ فاکتور"></label>';
    foreach(['date_from'=>'از تاریخ شمسی','date_to'=>'تا تاریخ شمسی'] as $field=>$label) {
        echo '<label>'.e($label).'<span class="jalali-field"><input type="hidden" name="'.$field.'" data-jalali-iso value="'.e($f[$field]).'"><button type="button" class="secondary jalali-picker-button" data-jalali-open>انتخاب تاریخ</button><button type="button" class="secondary" data-jalali-clear>پاک کردن</button></span></label>';
    }
    foreach ([
        'method'=>['روش پرداخت'=>[''=>'همه','cash'=>'نقدی','pos'=>'کارتخوان','mixed'=>'ترکیبی']],
        'status'=>['وضعیت سند'=>[''=>'همه','valid'=>'معتبر','void'=>'ابطال‌شده']],
        'settlement'=>['تسویه'=>[''=>'همه','paid'=>'تسویه‌شده','due'=>'دارای مانده']],
    ] as $key=>$definition) {
        [$label,$options]=$definition;
        echo '<label>'.e($label).'<select name="'.$key.'">';
        foreach($options as $value=>$title) echo '<option value="'.e((string)$value).'"'.($f[$key]===(string)$value?' selected':'').'>'.e($title).'</option>';
        echo '</select></label>';
    }
    $people=query('SELECT id,full_name FROM users WHERE company_id=?'.(document_scope()==='own'?' AND id=?':'').' ORDER BY full_name',document_scope()==='own'?[$tenant,(int)user()['id']]:[$tenant])->fetchAll();
    echo '<label>صادرکننده<select name="user_id"><option value="">همه کاربران مجاز</option>';
    foreach($people as $person) echo '<option value="'.(int)$person['id'].'"'.($f['user_id']===(string)$person['id']?' selected':'').'>'.e($person['full_name']).'</option>';
    echo '</select></label>';
    echo '<label>مرتب‌سازی<select name="sort">';
    foreach (['date'=>'تاریخ','number'=>'شماره','amount'=>'مبلغ','paid'=>'دریافتی','customer'=>'نام مشتری'] as $value=>$title) echo '<option value="'.$value.'"'.($f['sort']===$value?' selected':'').'>'.e($title).'</option>';
    echo '</select></label><label>جهت مرتب‌سازی<select name="direction"><option value="desc"'.($f['direction']==='desc'?' selected':'').'>نزولی</option><option value="asc"'.($f['direction']==='asc'?' selected':'').'>صعودی</option></select></label>';
    echo '<label>تعداد ردیف<select name="size">';
    foreach([20,50,100] as $size)echo '<option value="'.$size.'"'.($f['size']===$size?' selected':'').'>'.fa_digits((string)$size).'</option>';
    echo '</select></label></div><div class="filter-actions"><button type="submit">اعمال فیلترها</button><a class="button-link secondary" href="?page=invoice_manager">پاک کردن فیلترها</a>';
    if(can('reports.view')) echo '<a class="button-link secondary" href="?page=reports">گزارش‌های مالی و اکسل تراکنش‌ها</a>';

    echo '</div></form>';
    if (can('reports.export')) {
        $exportHeaders=[
            'row'=>'ردیف','number'=>'شماره فاکتور','date'=>'تاریخ شمسی','customer'=>'مشتری',
            'national'=>'کد ملی','mobile'=>'تلفن همراه','services'=>'شرح خدمات',
            'amount'=>'مبلغ فاکتور','paid'=>'مبلغ دریافت‌شده',
            'remaining'=>'مانده','method'=>'روش پرداخت','operator'=>'صادرکننده','status'=>'وضعیت'
        ];
        echo '<details class="card manager-export-config"><summary>تنظیم و دریافت Excel فاکتورها</summary><form method="get" data-manager-export-form data-tenant="'.(int)$tenant.'">';
        echo '<input type="hidden" name="page" value="invoice_manager"><input type="hidden" name="export" value="excel">';
        foreach(['q','date_from','date_to','method','status','settlement','user_id','service','amount','sort','direction'] as $key) {
            if ($f[$key]!=='') echo '<input type="hidden" name="'.$key.'" value="'.e((string)$f[$key]).'">';
        }
        echo '<p class="muted">تمام ستون‌ها به‌صورت پیش‌فرض فعال هستند. ترتیب ستون‌ها را می‌توانید تغییر دهید؛ انتخاب‌ها در همین مرورگر ذخیره می‌شوند.</p><div class="manager-export-columns" data-manager-export-columns>';
        foreach($exportHeaders as $key=>$title) {
            echo '<div class="manager-export-column" data-export-column="'.e($key).'"><label><input type="checkbox" name="columns[]" value="'.e($key).'" checked> '.e($title).'</label><div class="manager-export-move"><button type="button" class="secondary small" data-column-up aria-label="انتقال به بالا">↑</button><button type="button" class="secondary small" data-column-down aria-label="انتقال به پایین">↓</button></div></div>';
        }
        echo '</div><div class="filter-actions"><button type="button" class="secondary" data-columns-all>انتخاب همه</button><button type="button" class="secondary" data-columns-reset>پیش‌فرض</button><button type="submit">دریافت Excel نتایج فیلترشده</button></div><p class="muted">کد ملی و شماره همراه در فایل به‌صورت متن نگهداری می‌شوند.</p></form></details>';
    }
    echo '<section class="card manager-results"><div class="heading"><h2>فهرست اسناد</h2><span>'.money($sum['invoice_count']).' نتیجه</span></div>';
    echo '<div class="table-wrap" tabindex="0" role="region" aria-label="فهرست اسناد؛ برای مشاهده ستون‌ها افقی جابه‌جا کنید"><table data-pagination="server"><thead><tr><th>ردیف</th><th>شماره فاکتور</th><th>تاریخ</th><th>مشتری</th><th>خدمات</th><th>مبلغ</th><th>دریافتی</th><th>مانده</th><th>روش پرداخت</th><th>صادرکننده</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody>';
    foreach($rows as $i=>$row){
        $paid=(int)$row['paid_amount'];$total=(int)$row['total_amount'];$void=(bool)$row['is_void'];
        $methods=(int)$row['cash_count']>0 && (int)$row['pos_count']>0?'ترکیبی':((int)$row['pos_count']>0?'کارتخوان':'نقدی');
        $status=$void?'باطل‌شده':($paid>=$total?'تسویه‌شده':'دارای مانده');
        echo '<tr><td>'.fa_digits((string)(($f['page']-1)*$f['size']+$i+1)).'</td><td>'.e(display_financial_reference($row['invoice_number'])).'</td><td>'.e(jalali_datetime($row['invoice_date']?:$row['created_at'],true)).'</td>'.
            '<td>'.e($row['first_name'].' '.$row['last_name']).'<small class="manager-secondary">'.e(fa_digits((string)$row['national_code'])).'</small></td>'.
            '<td class="manager-service">'.e((string)$row['services']).'</td><td>'.money_toman($total).'</td><td>'.money_toman($paid).'</td><td>'.money_toman(max(0,$total-$paid)).'</td>'.
            '<td>'.e($methods).'</td><td>'.e($row['operator_name']).'</td><td><span class="status-chip '.($void?'status-muted':($paid>=$total?'status-good':'status-warning')).'">'.e($status).'</span></td>'.
            '<td><div class="management-actions"><a class="button-link secondary small" href="?page=invoice_manager&amp;detail='.(int)$row['id'].'">جزئیات</a>'.(can('invoices.print')?'<a class="button-link small" target="_blank" rel="noopener" href="?page=receipt&amp;id='.(int)$row['id'].'&amp;auto_print=1">چاپ</a>':'').'</div></td></tr>';
    }
    if(!$rows) echo '<tr><td colspan="12">فاکتوری با این فیلترها پیدا نشد.</td></tr>';
    echo '</tbody></table></div><nav class="manager-pagination" aria-label="صفحه‌بندی فاکتورها">';
    $pageCount=$data['page_count'];$current=$f['page'];
    if($current>1)echo '<a class="button-link secondary" href="'.e($url(['p'=>$current-1])).'">صفحه قبل</a>';
    echo '<span>صفحه '.fa_digits((string)$current).' از '.fa_digits((string)$pageCount).'</span>';
    if($current<$pageCount)echo '<a class="button-link secondary" href="'.e($url(['p'=>$current+1])).'">صفحه بعد</a>';
    echo '</nav></section>';
}
