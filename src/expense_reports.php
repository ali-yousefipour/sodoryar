<?php
declare(strict_types=1);
function expense_dashboard(int $tenant,string $from,string $to): void {
    if(!expense_manager()||!expenses_ready())return;
    $branch=(int)($_GET['expense_branch']??0);$branches=branch_choices($tenant,(int)user()['id']);
    if($branch)branch_require($tenant,(int)user()['id'],$branch);
    $filter=$branch?' AND branch_id=?':'';$args=[$tenant,$from,$to];if($branch)$args[]=$branch;
    $s=query("SELECT COUNT(CASE WHEN approval_status='approved' THEN 1 END) qty,COUNT(CASE WHEN approval_status='pending' THEN 1 END) pending,COALESCE(SUM(CASE WHEN approval_status='approved' AND classification='expense' THEN total_amount ELSE 0 END),0) cost FROM expenses WHERE company_id=? AND expense_date BETWEEN ? AND ?".$filter,$args)->fetch();
    $valid=database_table_exists('invoice_voids')?' AND NOT EXISTS(SELECT 1 FROM invoice_voids v WHERE v.company_id=invoices.company_id AND v.invoice_id=invoices.id)':'';
    $sales=query('SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE company_id=? AND COALESCE(invoice_date,DATE(created_at)) BETWEEN ? AND ?'.$filter.$valid,$args)->fetchColumn();
    $link='?'.http_build_query(['page'=>'expenses','branch'=>$branch,'from'=>jalali_datetime($from,true),'to'=>jalali_datetime($to,true)]);
    ?>
    <section class="card"><div class="heading"><h2>هزینه‌های شعب در ماه جاری</h2><a href="<?=e($link)?>">گزارش هزینه‌ها</a></div>
    <form method="get" class="form-grid"><input type="hidden" name="page" value="dashboard"><label>شعبه<select name="expense_branch"><option value="0">تمام شعب</option><?php foreach($branches as $b):?><option value="<?=e($b['id'])?>" <?=$branch===(int)$b['id']?'selected':''?>><?=e($b['name'])?></option><?php endforeach;?></select></label><button>اعمال فیلتر</button></form>
    <div class="stats"><div class="card"><small>هزینه جاری تأییدشده</small><h3><?=money_toman($s['cost'])?></h3></div><div class="card"><small>اسناد تأییدشده</small><h3><?=money($s['qty'])?></h3></div><div class="card"><small>در انتظار تأیید</small><h3><?=money($s['pending'])?></h3></div><div class="card"><small>فروش معتبر منهای هزینه جاری</small><h3><?=money_toman((int)$sales-(int)$s['cost'])?></h3><small>شاخص مدیریتی؛ نه سود خالص حسابداری</small></div></div></section>
    <?php
}
function expense_export(int $tenant): never {
    if(!expenses_ready())throw new RuntimeException('ساختار هزینه‌ها آماده نیست.');
    if(!expense_manager())require_permission('reports.export');
    $rows=expense_rows($tenant,10001);
    if(count($rows)>10000)throw new RuntimeException('بازه گزارش را محدود کنید.');
    $data=array_map(static fn($x)=>[(string)$x['id'],jalali_datetime($x['expense_date'],true),$x['branch_name'],$x['title'],$x['category_name'],$x['full_name'],expense_status($x['approval_status']),$x['total_amount']],$rows);
    export_excel_xlsx('sodoryar-expenses',['شماره سند','تاریخ','شعبه','عنوان','دسته‌بندی','ثبت‌کننده','وضعیت','مبلغ (تومان)'],$data,[7]);
    exit;
}
