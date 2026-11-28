<?php
declare(strict_types=1);
function expense_dashboard(int $tenant,string $monthStart,string $today): void {
    if(!expense_manager()||!expenses_ready())return;
    try{
        foreach(['expense_branch','expense_from','expense_to'] as $key)if(isset($_GET[$key])&&!is_string($_GET[$key]))throw new RuntimeException('ساختار فیلتر داشبورد نامعتبر است.');
        $input=['report'=>'daily','branch'=>(string)((int)($_GET['expense_branch']??0)?:''),'from'=>(string)($_GET['expense_from']??jalali_datetime($monthStart,true)),'to'=>(string)($_GET['expense_to']??jalali_datetime($today,true))];
        $filter=expense_center_filters($input);$branches=report_branch_choices($tenant);
        if($filter['branch']!==''&&!in_array((int)$filter['branch'],array_map('intval',array_column($branches,'id')),true))throw new RuntimeException('شعبه گزارش مجاز نیست.');
        [$where,$args]=expense_center_where($tenant,$filter);
        $summary=query("SELECT COUNT(*) documents,COUNT(DISTINCT branch_id) branches,
          COALESCE(SUM(approval_status='pending'),0) pending,COALESCE(SUM(approval_status='rejected'),0) rejected,COALESCE(SUM(approval_status='void'),0) voided,
          COALESCE(SUM(CASE WHEN approval_status='approved' AND classification='expense' THEN total_amount ELSE 0 END),0) cost FROM expenses e WHERE ".$where,$args)->fetch();
        $cost=function_exists('expense_cost_total')?expense_cost_total($tenant,$filter):expense_report_add(0,$summary['cost']);
        if(function_exists('expense_corrections_ready')&&expense_corrections_ready()){
            [$cxWhere,$cxArgs]=expense_center_where($tenant,$filter,'cx.correction_date');
            $summary['voided']+=(int)query('SELECT COUNT(*) FROM expense_corrections cx JOIN expenses e ON e.id=cx.expense_id AND e.company_id=cx.company_id WHERE '.$cxWhere,$cxArgs)->fetchColumn();
        }
        [$scope,$scopeArgs]=expense_scope($tenant);if($filter['branch']!==''){$scope.=' AND e.branch_id=?';$scopeArgs[]=$filter['branch'];}
        $calendar=query("SELECT COALESCE(SUM(CASE WHEN expense_date=? THEN total_amount ELSE 0 END),0) today,
          COALESCE(SUM(CASE WHEN expense_date BETWEEN ? AND ? THEN total_amount ELSE 0 END),0) month FROM expenses e WHERE ".$scope." AND approval_status='approved' AND classification='expense'",array_merge([$today,$monthStart,$today],$scopeArgs))->fetch();
        if(function_exists('expense_cost_total')){
            $calendar['today']=expense_cost_total($tenant,expense_center_filters(array_replace($input,['from'=>jalali_datetime($today,true),'to'=>jalali_datetime($today,true)])));
            $calendar['month']=expense_cost_total($tenant,expense_center_filters(array_replace($input,['from'=>jalali_datetime($monthStart,true),'to'=>jalali_datetime($today,true)])));
        }
        $length=(new DateTimeImmutable($filter['from_iso']))->diff(new DateTimeImmutable($filter['to_iso']))->days+1;
        $previousTo=(new DateTimeImmutable($filter['from_iso']))->modify('-1 day');$previousFrom=$previousTo->modify('-'.($length-1).' days');
        $previous=query("SELECT COALESCE(SUM(total_amount),0) FROM expenses e WHERE ".$scope." AND approval_status='approved' AND classification='expense' AND expense_date BETWEEN ? AND ?",array_merge($scopeArgs,[$previousFrom->format('Y-m-d'),$previousTo->format('Y-m-d')]))->fetchColumn();
        $previous=function_exists('expense_cost_total')?expense_cost_total($tenant,expense_center_filters(array_replace($input,['from'=>jalali_datetime($previousFrom->format('Y-m-d'),true),'to'=>jalali_datetime($previousTo->format('Y-m-d'),true)]))):expense_report_add(0,$previous);$difference=expense_report_add($cost,-$previous);
        $payments=[];
        foreach(['cash','bank','personal'] as $method){
            [$paymentWhere,$paymentArgs]=expense_center_where($tenant,$filter,'DATE(p.posted_at)');
            $payments[$method]=query("SELECT COALESCE(SUM(p.amount),0) FROM expense_payments p JOIN expenses e ON e.id=p.expense_id AND e.company_id=p.company_id WHERE ".$paymentWhere." AND p.posted_at IS NOT NULL AND e.approval_status='approved' AND p.method=?",array_merge($paymentArgs,[$method]))->fetchColumn();
        }
        $debtRows=expense_center_rows($tenant,array_replace($filter,['report'=>'liabilities']));$debt=expense_center_totals($debtRows,['amount'=>'بدهی'])['amount'];
        $invoiceWhere='i.company_id=? AND COALESCE(i.invoice_date,DATE(i.created_at)) BETWEEN ? AND ?';$invoiceArgs=[$tenant,$filter['from_iso'],$filter['to_iso']];
        if($filter['branch']!==''){$invoiceWhere.=' AND i.branch_id=?';$invoiceArgs[]=$filter['branch'];}
        if(database_table_exists('invoice_voids'))$invoiceWhere.=' AND NOT EXISTS(SELECT 1 FROM invoice_voids v WHERE v.company_id=i.company_id AND v.invoice_id=i.id)';
        $sales=expense_report_add(0,query('SELECT COALESCE(SUM(i.total_amount),0) FROM invoices i WHERE '.$invoiceWhere,$invoiceArgs)->fetchColumn());
        $link=static function(string $type,array $extra=[])use($filter):string{return '?'.http_build_query(array_merge(['page'=>'expense_reports','report'=>$type,'branch'=>$filter['branch'],'from'=>$filter['from'],'to'=>$filter['to']],$extra));};
        $cards=[
            ['هزینه‌های امروز',money_toman($calendar['today']),$link('daily',['from'=>jalali_datetime($today,true),'to'=>jalali_datetime($today,true)]),'هزینه جاری تأییدشده'],
            ['هزینه‌های ماه جاری',money_toman($calendar['month']),$link('monthly',['from'=>jalali_datetime($monthStart,true),'to'=>jalali_datetime($today,true)]),'بر مبنای ماه شمسی'],
            ['هزینه‌های دوره انتخاب‌شده',money_toman($cost),$link('daily'),'هزینه جاری تأییدشده'],
            ['هزینه‌های شعب',money_toman($cost),$link('branches'),fa_digits($summary['branches']).' شعبه دارای سند اولیه در بازه'],
            ['پرداخت نقدی اسناد',money_toman($payments['cash']),$link('cash',['status'=>'approved']),'بر مبنای تاریخ قطعی‌شدن پرداخت'],
            ['پرداخت بانکی اسناد',money_toman($payments['bank']),$link('bank',['status'=>'approved']),'گردش مستند؛ نه مانده بانکی'],
            ['پرداخت شخصی کارکنان',money_toman($payments['personal']),$link('personal',['status'=>'approved']),'پرداخت شخصی، خروج وجه شرکت نیست'],
            ['بدهی تسویه‌نشده کارکنان',money_toman($debt),$link('liabilities'),'بدهی اسناد همین بازه پس از برگشت تسویه'],
            ['تعداد اسناد هزینه',money($summary['documents']),'?'.http_build_query(['page'=>'expenses','branch'=>$filter['branch'],'from'=>$filter['from'],'to'=>$filter['to']]),'تمام وضعیت‌ها در بازه'],
            ['در انتظار تأیید',money($summary['pending']),$link('pending'),'فاقد اثر مالی قطعی'],
            ['هزینه‌های ردشده',money($summary['rejected']),$link('rejected'),'با حفظ دلیل و سابقه'],
            ['هزینه‌های ابطال‌شده',money($summary['voided']),$link('void'),'ابطال، دریافت وجه نیست'],
            ['هزینه دوره قبل',money_toman($previous),$link('daily',['from'=>jalali_datetime($previousFrom->format('Y-m-d'),true),'to'=>jalali_datetime($previousTo->format('Y-m-d'),true)]),'تغییر دوره جاری: '.money_toman($difference)],
            ['خالص عملکرد پس از هزینه',money_toman(expense_report_add($sales,-$cost)),$link('performance'),'فروش معتبر منهای هزینه جاری؛ نه سود خالص'],
        ];
    }catch(Throwable $ex){echo '<div class="alert error">'.e(public_operation_error($ex)).'</div>';return;}
    ?>
    <section class="card"><div class="heading"><h2>هزینه‌ها و گردش مالی شعب</h2><a href="?page=expense_reports">مرکز گزارش هزینه‌ها</a></div>
    <form method="get" class="form-grid"><input type="hidden" name="page" value="dashboard"><label>شعبه<select name="expense_branch"><option value="0">تمام شعب مجاز</option><?php foreach($branches as $b):?><option value="<?=e($b['id'])?>" <?=$filter['branch']===(string)$b['id']?'selected':''?>><?=e($b['name'])?></option><?php endforeach;?></select></label><label>از تاریخ شمسی<input name="expense_from" value="<?=e($filter['from'])?>" required></label><label>تا تاریخ شمسی<input name="expense_to" value="<?=e($filter['to'])?>" required></label><button>اعمال بازه هزینه‌ها</button></form>
    <div class="stats"><?php foreach($cards as [$title,$value,$url,$description]):?><article class="stat"><a href="<?=e($url)?>"><span><?=e($title)?></span><strong><?=e($value)?></strong></a><small><?=e($description)?></small></article><?php endforeach;?></div></section>
    <?php
}
function expense_export(int $tenant): never {
    if(!expenses_ready())throw new RuntimeException('ساختار هزینه‌ها آماده نیست.');
    require_permission('expenses.export');
    $rows=expense_rows($tenant,10001);
    if(count($rows)>10000)throw new RuntimeException('بازه گزارش را محدود کنید.');
    $data=array_map(static fn($x)=>[(string)$x['id'],jalali_datetime($x['expense_date'],true),$x['branch_name'],$x['title'],$x['category_name'],$x['full_name'],expense_status($x['display_status']??$x['approval_status']),$x['total_amount']],$rows);
    export_excel_xlsx('sodoryar-expenses',['شماره سند','تاریخ','شعبه','عنوان','دسته‌بندی','ثبت‌کننده','وضعیت','مبلغ (تومان)'],$data,[7]);
    exit;
}
