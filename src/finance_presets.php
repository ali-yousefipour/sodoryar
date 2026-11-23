<?php
declare(strict_types=1);

/** Specialized management reports. Sale dates and receipt dates are intentionally separate. */
function finance_preset_catalog(): array {
    return [
        'sales_daily'=>'فروش روزانه',
        'sales_monthly'=>'فروش ماهانه شمسی',
        'receipts_daily'=>'دریافت‌های روزانه',
        'receipts_cash'=>'دریافت‌های نقدی',
        'receipts_pos'=>'دریافت‌های کارتخوان',
        'receipts_mixed'=>'فاکتورهای ترکیبی',
        'pos_terminals'=>'دریافت به تفکیک دستگاه',
        'operator_performance'=>'عملکرد اپراتورها',
        'cashbox_performance'=>'عملکرد صندوق‌ها',
        'debtors'=>'مطالبات و بدهکاران',
        'invoice_balances'=>'مانده فاکتورها',
        'voided_invoices'=>'فاکتورهای ابطال‌شده',
        'corrected_receipts'=>'تراکنش‌های اصلاحی',
        'finance_exceptions'=>'مغایرت‌های مالی',
        'service_revenue'=>'درآمد خدمات',
        'driver_revenue'=>'درآمد به تفکیک تاکسیران',
        'sales_vs_receipts'=>'مقایسه فروش و دریافت',
        'approved_cashboxes'=>'صندوق‌های تأییدشده',
        'period_balances'=>'مانده پایان دوره',
        'management_summary'=>'گزارش جامع مدیریتی'
    ];
}
function finance_preset_input(array $input): array {
    $raw=$input['report_type']??'sales_daily';
    if(!is_string($raw)||!array_key_exists($raw,finance_preset_catalog())) throw new RuntimeException('نوع گزارش تخصصی نامعتبر است.');
    $from=$input['date_from']??'';
    $to=$input['date_to']??'';
    foreach([$from,$to] as $date){
        if(!is_string($date))throw new RuntimeException('تاریخ گزارش نامعتبر است.');
        if($date!=='') {
            $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',ascii_digits($date));
            if(!$parsed||$parsed->format('Y-m-d')!==ascii_digits($date)) throw new RuntimeException('فرمت تاریخ گزارش نامعتبر است.');
        }
    }
    $defaultTo=new DateTimeImmutable('today');
    $to=$to!==''?ascii_digits($to):$defaultTo->format('Y-m-d');
    $from=$from!==''?ascii_digits($from):$defaultTo->modify('-30 days')->format('Y-m-d');
    $days=(new DateTimeImmutable($from))->diff(new DateTimeImmutable($to))->days;
    if($from>$to || $days>366)throw new RuntimeException('بازه گزارش باید حداکثر یک سال و یک روز باشد.');
    $user=$input['user_id']??'';
    if(!is_string($user)||($user!=='' && (!ctype_digit(ascii_digits($user))||(int)ascii_digits($user)<1)))throw new RuntimeException('شناسه اپراتور نامعتبر است.');
    $terminal=$input['terminal_id']??'';
    if(!is_string($terminal)||($terminal!=='' && (!ctype_digit(ascii_digits($terminal))||(int)ascii_digits($terminal)<1)))throw new RuntimeException('شناسه دستگاه کارتخوان نامعتبر است.');
    $method=$input['payment_type']??'';
    if(!is_string($method)||!in_array($method,['','cash','pos'],true))throw new RuntimeException('روش پرداخت نامعتبر است.');
    $service=$input['service']??'';
    if(!is_string($service)||mb_strlen($service)>100)throw new RuntimeException('عنوان خدمت نامعتبر است.');
    $min=$input['amount_min']??'';
    $max=$input['amount_max']??'';
    foreach([$min,$max] as $v)if(!is_string($v))throw new RuntimeException('محدوده مبلغ نامعتبر است.');
    $minimum=$min===''?null:parse_money_input($min,0,1000000000000);
    $maximum=$max===''?null:parse_money_input($max,0,1000000000000);
    if($minimum===false||$maximum===false||($minimum!==null&&$maximum!==null&&$minimum>$maximum))throw new RuntimeException('محدوده مبلغ گزارش نامعتبر است.');
    $page=$input['report_page']??'1';
    if(!is_string($page)||!ctype_digit(ascii_digits($page)))$page='1';
    return [
        'type'=>$raw,'from'=>$from,'to'=>$to,
        'to_exclusive'=>(new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d'),
        'user'=>$user===''?null:(int)ascii_digits($user),
        'terminal'=>$terminal===''?null:(int)ascii_digits($terminal),
        'method'=>$method,'service'=>$service,
        'min'=>$minimum,'max'=>$maximum,
        'page'=>max(1,min(100000,(int)ascii_digits($page)))
    ];
}

/** Parametrized input scope is shared by preset queries; no interpolated user identifiers. */
function finance_invoice_scope(int $tenant,array $f,bool $useDate=true): array {
    $sql=' FROM invoices i JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id WHERE i.company_id=?';
    $params=[$tenant];
    if(database_table_exists('invoice_voids'))$sql.=' AND NOT EXISTS(SELECT 1 FROM invoice_voids v WHERE v.invoice_id=i.id AND v.company_id=i.company_id)';
    if(document_scope()==='own'){$sql.=' AND i.user_id=?';$params[]=(int)user()['id'];}
    if($useDate){
        $sql.=' AND COALESCE(i.invoice_date,DATE(i.created_at))>=? AND COALESCE(i.invoice_date,DATE(i.created_at))<?';
        array_push($params,$f['from'],$f['to_exclusive']);
    }
    if($f['user']!==null){$sql.=' AND i.user_id=?';$params[]=$f['user'];}
    if($f['service']!==''){
        $sql.=' AND EXISTS(SELECT 1 FROM invoice_items it WHERE it.company_id=i.company_id AND it.invoice_id=i.id AND it.service_title LIKE ?)';
        $params[]='%'.$f['service'].'%';
    }
    if($f['min']!==null){$sql.=' AND i.total_amount>=?';$params[]=$f['min'];}
    if($f['max']!==null){$sql.=' AND i.total_amount<=?';$params[]=$f['max'];}
    if($f['method']!==''){
        if(invoice_payment_ledger_ready()){
            $sql.=' AND EXISTS (SELECT 1 FROM invoice_payments pm WHERE pm.company_id=i.company_id AND pm.invoice_id=i.id AND pm.payment_method=? AND pm.voided_at IS NULL)';
        }else{$sql.=' AND i.payment_type=?';}
        $params[]=$f['method'];
    }
    if($f['terminal']!==null && invoice_payment_ledger_ready()){
        $sql.=' AND EXISTS (SELECT 1 FROM invoice_payments pt WHERE pt.company_id=i.company_id AND pt.invoice_id=i.id AND pt.terminal_id=? AND pt.voided_at IS NULL)';
        $params[]=$f['terminal'];
    }
    return [$sql,$params];
}
function finance_receipt_scope(int $tenant,array $f,string $method=''): array {
    $sql=' FROM invoice_payments p JOIN invoices i ON i.id=p.invoice_id AND i.company_id=p.company_id
      JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id
      WHERE p.company_id=? AND p.voided_at IS NULL AND p.paid_at>=? AND p.paid_at<?';
    $params=[$tenant,$f['from'].' 00:00:00',$f['to_exclusive'].' 00:00:00'];
    if(database_table_exists('invoice_voids'))$sql.=' AND NOT EXISTS (SELECT 1 FROM invoice_voids v WHERE v.invoice_id=i.id AND v.company_id=i.company_id)';
    if(document_scope()==='own'){$sql.=' AND i.user_id=?';$params[]=(int)user()['id'];}
    if($f['user']!==null){$sql.=' AND p.created_by=?';$params[]=$f['user'];}
    if($f['terminal']!==null){$sql.=' AND p.terminal_id=?';$params[]=$f['terminal'];}
    $filterMethod=$method!==''?$method:$f['method'];
    if($filterMethod!==''){$sql.=' AND p.payment_method=?';$params[]=$filterMethod;}
    if($f['service']!==''){$sql.=' AND EXISTS(SELECT 1 FROM invoice_items it WHERE it.company_id=i.company_id AND it.invoice_id=i.id AND it.service_title LIKE ?)';$params[]='%'.$f['service'].'%';}
    if($f['min']!==null){$sql.=' AND p.amount>=?';$params[]=$f['min'];}
    if($f['max']!==null){$sql.=' AND p.amount<=?';$params[]=$f['max'];}
    return [$sql,$params];
}

/** Return actual numeric result rows. Limits are enforced before any XLSX response. */
function finance_preset_data(int $tenant,array $f,bool $export=false): array {
    require_permission('reports.view');
    $type=$f['type'];
    $headers=[];$money=[];$rows=[];$message='';
    $limit=$export?10000:200;
    $offset=$export?0:(($f['page']-1)*$limit);
    [$invoiceSql,$invoiceArgs]=finance_invoice_scope($tenant,$f);
    if(in_array($type,['receipts_daily','receipts_cash','receipts_pos','receipts_mixed','pos_terminals',
      'operator_performance','cashbox_performance','debtors','invoice_balances',
      'corrected_receipts','finance_exceptions','driver_revenue','sales_vs_receipts',
      'approved_cashboxes','period_balances','management_summary'],true) && !invoice_payment_ledger_ready()){
        return ['headers'=>[],'rows'=>[],'money'=>[],'message'=>'گزارش دریافت‌ها نیازمند نصب ساختار دفتر پرداخت (مهاجرت ۰۱۶) است.','total'=>0];
    }
    $execute=static function(string $sql,array $args)use($limit,$offset):array{
        return query($sql.' LIMIT '.(int)($limit+1).' OFFSET '.(int)$offset,$args)->fetchAll();
    };
    switch($type) {
    case 'sales_daily':
    case 'sales_monthly':
        $dailySql='SELECT COALESCE(i.invoice_date,DATE(i.created_at)) report_date,
             COUNT(*) invoice_count,COALESCE(SUM(i.total_amount),0) amount'.$invoiceSql.'
             GROUP BY report_date ORDER BY report_date DESC';
        $daily=$type==='sales_monthly'
            ? query($dailySql.' LIMIT 400',$invoiceArgs)->fetchAll()
            : $execute($dailySql,$invoiceArgs);
        if($type==='sales_monthly'){
            $aggregated=[];
            foreach($daily as $day){
                [$jy,$jm]=gregorian_to_jalali((int)substr($day['report_date'],0,4),
                    (int)substr($day['report_date'],5,2),(int)substr($day['report_date'],8,2));
                $key=sprintf('%04d/%02d',$jy,$jm);
                if(!isset($aggregated[$key]))$aggregated[$key]=['period'=>$key,'invoice_count'=>0,'amount'=>0];
                $aggregated[$key]['invoice_count']+=(int)$day['invoice_count'];
                $aggregated[$key]['amount']+=(int)$day['amount'];
            }
            $rows=array_slice(array_values($aggregated),$offset,$limit+1);
        }else{$rows=array_map(static fn($r)=>[
            'period'=>$r['report_date'],'invoice_count'=>(int)$r['invoice_count'],'amount'=>(int)$r['amount']],$daily);}
        $headers=['period'=>'دوره','invoice_count'=>'تعداد فاکتور','amount'=>'فروش'];$money=['amount'];
        break;
    case 'receipts_daily':
    case 'receipts_cash':
    case 'receipts_pos':
        [$receiptSql,$receiptArgs]=finance_receipt_scope($tenant,$f,
           $type==='receipts_cash'?'cash':($type==='receipts_pos'?'pos':''));
        $rows=$execute('SELECT DATE(p.paid_at) period,COUNT(*) receipt_count,
           COALESCE(SUM(p.amount),0) amount'.$receiptSql.'
           GROUP BY DATE(p.paid_at) ORDER BY period DESC',$receiptArgs);
        $headers=['period'=>'تاریخ دریافت','receipt_count'=>'تعداد دریافت','amount'=>'دریافتی'];
        $money=['amount'];break;
    case 'receipts_mixed':
        [$sql,$params]=finance_invoice_scope($tenant,$f);
        $payJoin=' JOIN (
                SELECT company_id,invoice_id,
                SUM(CASE WHEN payment_method=\'cash\' THEN amount ELSE 0 END) cash,
                SUM(CASE WHEN payment_method=\'pos\' THEN amount ELSE 0 END) pos
                FROM invoice_payments WHERE company_id='.(int)$tenant.' AND voided_at IS NULL
                GROUP BY company_id,invoice_id
            ) pay ON pay.company_id=i.company_id AND pay.invoice_id=i.id';
        $sql=str_replace(' WHERE i.company_id=?',$payJoin.' WHERE i.company_id=? AND pay.cash>0 AND pay.pos>0',$sql);
        $rows=$execute('SELECT i.invoice_number,CONCAT(c.first_name,\' \',c.last_name) customer,
            i.total_amount invoice_total,COALESCE(pay.cash,0) cash,COALESCE(pay.pos,0) pos,
            COALESCE(pay.cash,0)+COALESCE(pay.pos,0) collected'
            .$sql.' ORDER BY i.id DESC',$params);
        $headers=['invoice_number'=>'شماره فاکتور','customer'=>'تاکسیران','invoice_total'=>'مبلغ فاکتور',
          'cash'=>'نقدی','pos'=>'کارتخوان','collected'=>'کل دریافتی'];
        $money=['invoice_total','cash','pos','collected'];break;
    case 'pos_terminals':
        [$sql,$params]=finance_receipt_scope($tenant,$f,'pos');
        $sql=str_replace(' WHERE p.company_id=?',' LEFT JOIN pos_settings ps
             ON ps.id=p.terminal_id AND ps.company_id=p.company_id WHERE p.company_id=?',$sql);
        $rows=$execute('SELECT COALESCE(ps.brand,\'دستگاه نامشخص\') terminal,
              COUNT(*) receipt_count,COALESCE(SUM(p.amount),0) amount'
              .$sql.' GROUP BY p.terminal_id,ps.brand ORDER BY amount DESC',$params);
        $headers=['terminal'=>'دستگاه کارتخوان','receipt_count'=>'تعداد تراکنش','amount'=>'مبلغ'];$money=['amount'];break;
    case 'operator_performance':
        [$sql,$params]=finance_receipt_scope($tenant,$f);
        $sql=str_replace(' WHERE p.company_id=?',' LEFT JOIN users u ON u.id=p.created_by
            AND (u.company_id=p.company_id OR u.role=\'superadmin\') WHERE p.company_id=?',$sql);
        $rows=$execute('SELECT COALESCE(u.full_name,\'نامشخص\') operator_name,
            COUNT(*) receipt_count,
            COALESCE(SUM(CASE WHEN p.payment_method=\'cash\' THEN p.amount ELSE 0 END),0) cash,
            COALESCE(SUM(CASE WHEN p.payment_method=\'pos\' THEN p.amount ELSE 0 END),0) pos'
            .$sql.' GROUP BY p.created_by,u.full_name ORDER BY (cash+pos) DESC',$params);
        $headers=['operator_name'=>'اپراتور دریافت‌کننده','receipt_count'=>'تعداد تراکنش','cash'=>'نقدی','pos'=>'کارتخوان'];
        $money=['cash','pos'];break;
    case 'debtors':
    case 'driver_revenue':
    case 'period_balances':
        // Use independent invoice and receipt aggregates so one invoice is never multiplied by payments.
        [$sql,$params]=finance_invoice_scope($tenant,$f,$type!=='period_balances');
        if($type==='period_balances'){
            $sql.=' AND COALESCE(i.invoice_date,DATE(i.created_at))<?';
            $params[]=$f['to_exclusive'];
        }
        $dateBound=$type==='period_balances'?' AND paid_at<?':'';
        $joinParams=$type==='period_balances'?[$f['to_exclusive'].' 00:00:00']:[];
        $paymentJoin=' LEFT JOIN (SELECT company_id,invoice_id,SUM(amount) paid
          FROM invoice_payments WHERE voided_at IS NULL AND company_id=?'.$dateBound.'
          GROUP BY company_id,invoice_id) pmt ON pmt.company_id=i.company_id AND pmt.invoice_id=i.id';
        // Join placeholder precedes invoice WHERE placeholders.
        $rows=$execute('SELECT c.national_code,CONCAT(c.first_name,\' \',c.last_name) customer,
           COUNT(*) invoice_count,COALESCE(SUM(i.total_amount),0) sales,
           COALESCE(SUM(COALESCE(pmt.paid,0)),0) received,
           COALESCE(SUM(GREATEST(0,i.total_amount-COALESCE(pmt.paid,0))),0) due
           FROM invoices i JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id
           '.$paymentJoin.' WHERE i.company_id=?'
           .substr($sql,strpos($sql,' WHERE i.company_id=?')+strlen(' WHERE i.company_id=?')).'
           GROUP BY c.id,c.national_code,c.first_name,c.last_name'
           .($type==='debtors'?' HAVING due>0':'')
           .' ORDER BY '.($type==='driver_revenue'?'sales':'due').' DESC',
           array_merge([$tenant],$joinParams,$params));
        $headers=['national_code'=>'کد ملی','customer'=>'تاکسیران','invoice_count'=>'تعداد فاکتور',
          'sales'=>'فروش','received'=>'دریافتی','due'=>'مانده'];
        $money=['sales','received','due'];break;
    case 'invoice_balances':
        [$sql,$params]=finance_invoice_scope($tenant,$f);
        $rows=$execute('SELECT i.invoice_number,c.national_code,
           CONCAT(c.first_name,\' \',c.last_name) customer,i.total_amount,
           COALESCE(pmt.paid,0) received,i.total_amount-COALESCE(pmt.paid,0) due
           FROM invoices i JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id
           LEFT JOIN (SELECT company_id,invoice_id,SUM(amount) paid FROM invoice_payments WHERE
             voided_at IS NULL AND company_id=? GROUP BY company_id,invoice_id) pmt
             ON pmt.company_id=i.company_id AND pmt.invoice_id=i.id
           WHERE i.company_id=?'
           .substr($sql,strpos($sql,' WHERE i.company_id=?')+strlen(' WHERE i.company_id=?')).'
           AND COALESCE(pmt.paid,0)<i.total_amount ORDER BY i.id DESC',
           array_merge([$tenant],$params));
        $headers=['invoice_number'=>'شماره فاکتور','national_code'=>'کد ملی','customer'=>'تاکسیران',
           'total_amount'=>'مبلغ فاکتور','received'=>'دریافتی','due'=>'مانده'];$money=['total_amount','received','due'];break;
    case 'voided_invoices':
        if(!database_table_exists('invoice_voids')){$message='مهاجرت اسناد ابطال‌شده نصب نشده است.';break;}
        $sql=' FROM invoice_voids v JOIN invoices i ON i.id=v.invoice_id AND i.company_id=v.company_id
            JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id
            WHERE v.company_id=? AND v.voided_at>=? AND v.voided_at<?';
        $args=[$tenant,$f['from'].' 00:00:00',$f['to_exclusive'].' 00:00:00'];
        if(document_scope()==='own'){$sql.=' AND i.user_id=?';$args[]=(int)user()['id'];}
        if($f['user']!==null){$sql.=' AND v.voided_by=?';$args[]=$f['user'];}
        $rows=$execute('SELECT i.invoice_number,CONCAT(c.first_name,\' \',c.last_name) customer,
         i.total_amount amount,v.voided_at,v.reason'.$sql.' ORDER BY v.voided_at DESC',$args);
        $headers=['invoice_number'=>'شماره فاکتور','customer'=>'تاکسیران','amount'=>'مبلغ',
         'voided_at'=>'زمان ابطال','reason'=>'دلیل ابطال'];$money=['amount'];break;
    case 'corrected_receipts':
        $sql=' FROM invoice_payments p JOIN invoices i ON i.id=p.invoice_id AND i.company_id=p.company_id
              WHERE p.company_id=? AND p.voided_at IS NOT NULL AND p.voided_at>=? AND p.voided_at<?';
        $args=[$tenant,$f['from'].' 00:00:00',$f['to_exclusive'].' 00:00:00'];
        if(document_scope()==='own'){$sql.=' AND i.user_id=?';$args[]=(int)user()['id'];}
        $rows=$execute('SELECT i.invoice_number,p.id,p.payment_method,p.amount,p.voided_at,p.void_reason'
           .$sql.' ORDER BY p.voided_at DESC,p.id DESC',$args);
        $headers=['invoice_number'=>'فاکتور','id'=>'شناسه تراکنش','payment_method'=>'روش','amount'=>'مبلغ اولیه',
          'voided_at'=>'زمان اصلاح','void_reason'=>'دلیل'];$money=['amount'];break;
    case 'finance_exceptions':
        $rawFilter=report_filters_default();
        $rawFilter['date_from']=$f['from'];$rawFilter['date_to']=$f['to'];
        $issues=financial_control_exceptions($tenant,$rawFilter,$limit);
        $rows=array_map(static fn($issue)=>[
            'kind'=>$issue['kind'],'invoice'=>$issue['invoice'],'detail'=>$issue['detail']],$issues);
        $headers=['kind'=>'نوع مغایرت','invoice'=>'شماره فاکتور','detail'=>'جزئیات'];break;
    case 'service_revenue':
        [$sql,$params]=finance_invoice_scope($tenant,$f);
        $rows=$execute('SELECT it.service_title service,COUNT(*) item_count,COALESCE(SUM(it.price),0) amount
         FROM invoice_items it JOIN invoices i ON i.id=it.invoice_id AND i.company_id=it.company_id
         JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id WHERE it.company_id=?'
         .substr($sql,strpos($sql,' WHERE i.company_id=?')+strlen(' WHERE i.company_id=?')).'
         GROUP BY it.service_title ORDER BY amount DESC',
         array_merge([$tenant],array_slice($params,1)));
        $headers=['service'=>'عنوان خدمت','item_count'=>'تعداد خدمات','amount'=>'جمع مبلغ'];$money=['amount'];break;
    case 'sales_vs_receipts':
        [$salesSql,$salesArgs]=finance_invoice_scope($tenant,$f);
        [$receivedSql,$receivedArgs]=finance_receipt_scope($tenant,$f);
        $rows=$execute('SELECT period,SUM(invoice_count) invoice_count,SUM(sales) sales,
           SUM(receipt_count) receipt_count,SUM(received) received FROM (
             SELECT COALESCE(i.invoice_date,DATE(i.created_at)) period,
               COUNT(*) invoice_count,SUM(i.total_amount) sales,0 receipt_count,0 received'
               .$salesSql.' GROUP BY period
             UNION ALL SELECT DATE(p.paid_at) period,0,0,COUNT(*),SUM(p.amount)'
               .$receivedSql.' GROUP BY DATE(p.paid_at)
           ) x GROUP BY period ORDER BY period DESC',array_merge($salesArgs,$receivedArgs));
        $headers=['period'=>'تاریخ','invoice_count'=>'تعداد فاکتور','sales'=>'فروش',
           'receipt_count'=>'تعداد دریافت','received'=>'دریافتی'];$money=['sales','received'];break;
    case 'cashbox_performance':
    case 'approved_cashboxes':
        if(!function_exists('cashbox_ready')||!cashbox_ready()){$message='برای این گزارش ابتدا مهاجرت صندوق ۰۱۸ را اجرا کنید.';break;}
        $args=[$tenant,$f['from'].' 00:00:00',$f['to_exclusive'].' 00:00:00'];
        $scope=document_scope()==='own'?' AND s.operator_id='.(int)user()['id']:'';
        if($f['user']!==null){$scope.=' AND s.operator_id=?';$args[]=$f['user'];}
        if($type==='approved_cashboxes')$scope.=" AND s.status='approved'";
        $rows=$execute('SELECT r.name register_name,u.full_name operator_name,s.started_at,s.status,
           s.opening_cash,s.expected_cash,s.declared_cash,s.difference_cash
           FROM cash_shifts s
           JOIN cash_registers r ON r.id=s.register_id AND r.company_id=s.company_id
           JOIN users u ON u.id=s.operator_id AND u.company_id=s.company_id
           WHERE s.company_id=? AND s.started_at>=? AND s.started_at<?'.$scope.'
           ORDER BY s.id DESC',$args);
        $headers=['register_name'=>'صندوق','operator_name'=>'اپراتور','started_at'=>'شروع شیفت',
          'status'=>'وضعیت','opening_cash'=>'موجودی اولیه','expected_cash'=>'موجودی مورد انتظار',
          'declared_cash'=>'وجه اعلامی','difference_cash'=>'مغایرت'];
        $money=['opening_cash','expected_cash','declared_cash','difference_cash'];break;
    case 'management_summary':
        $sales=query('SELECT COUNT(*) invoice_count,COALESCE(SUM(i.total_amount),0) sales'.$invoiceSql,$invoiceArgs)->fetch();
        [$receiptSql,$receiptArgs]=finance_receipt_scope($tenant,$f);
        $received=query('SELECT COUNT(*) receipt_count,
          COALESCE(SUM(CASE WHEN p.payment_method=\'cash\' THEN p.amount ELSE 0 END),0) cash,
          COALESCE(SUM(CASE WHEN p.payment_method=\'pos\' THEN p.amount ELSE 0 END),0) pos'.$receiptSql,$receiptArgs)->fetch();
        $rows=[
            ['metric'=>'فاکتور معتبر','value'=>(int)$sales['invoice_count']],
            ['metric'=>'مبلغ فروش','value'=>(int)$sales['sales']],
            ['metric'=>'تعداد دریافتی','value'=>(int)$received['receipt_count']],
            ['metric'=>'دریافتی نقدی','value'=>(int)$received['cash']],
            ['metric'=>'دریافتی کارتخوان','value'=>(int)$received['pos']],
            ['metric'=>'مانده فروش منهای دریافت در بازه','value'=>(int)$sales['sales']-(int)$received['cash']-(int)$received['pos']]
        ];
        $headers=['metric'=>'شاخص','value'=>'مقدار'];$money=['value'];
        $message='فروش بر اساس تاریخ سند و دریافت بر اساس زمان وصول است؛ تفاضل این دو، لزوماً مانده بدهی فاکتورهای همین دوره نیست.';
        break;
    default:throw new RuntimeException('گزارش تعریف‌نشده است.');
    }
    $total=count($rows);
    $hasMore=$total>$limit;
    if($hasMore){
        if($export) throw new RuntimeException('گزارش بیشتر از ۱۰٬۰۰۰ ردیف است؛ بازه را محدود کنید تا خروجی کامل باشد.');
        $rows=array_slice($rows,0,$limit);
    }
    return ['headers'=>$headers,'rows'=>$rows,'money'=>$money,'message'=>$message,
      'total'=>count($rows),'has_more'=>$hasMore];
}
