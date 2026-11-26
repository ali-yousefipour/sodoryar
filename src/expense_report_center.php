<?php
declare(strict_types=1);

function expense_report_catalog(): array {
    return ['daily'=>'هزینه‌های روزانه','monthly'=>'هزینه‌های ماهانه','branches'=>'هزینه‌های شعب','categories'=>'دسته‌بندی هزینه‌ها','users'=>'هزینه‌های کاربران',
        'cash'=>'هزینه‌های نقدی','bank'=>'هزینه‌های بانکی','personal'=>'خریدهای شخصی کارکنان','liabilities'=>'بدهی به کارکنان','settlements'=>'تسویه کارکنان',
        'pending'=>'در انتظار تأیید','rejected'=>'هزینه‌های ردشده','void'=>'هزینه‌های ابطال‌شده','comparison'=>'مقایسه فروش و هزینه','performance'=>'خالص عملکرد شعب',
        'items'=>'اقلام خریداری‌شده','movements'=>'گردش وجوه هزینه‌ها','returns'=>'برگشت هزینه‌ها','settlement_returns'=>'برگشت تسویه‌ها'];
}
function expense_center_filters(array $input): array {
    $filter=[];
    foreach(['report','q','branch','user_id','category_id','from','to','method','status','minimum','maximum','sort','direction'] as $key){
        if(isset($input[$key])&&!is_string($input[$key]))throw new RuntimeException('فیلتر گزارش نامعتبر است.');
        $filter[$key]=ascii_digits(trim((string)($input[$key]??'')));
    }
    $filter['report']=$filter['report']?:'daily';
    if(!isset(expense_report_catalog()[$filter['report']]))throw new RuntimeException('گزارش انتخاب‌شده معتبر نیست.');
    if(mb_strlen($filter['q'])>180)throw new RuntimeException('عبارت جستجو طولانی است.');
    foreach(['branch','user_id','category_id'] as $key)if($filter[$key]!==''&&!filter_var($filter[$key],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]))throw new RuntimeException('شناسه فیلتر معتبر نیست.');
    foreach(['from','to'] as $key)$filter[$key.'_iso']=$filter[$key]!==''?jalali_to_gregorian_date($filter[$key]):'';
    if($filter['from_iso']!==''&&$filter['to_iso']!==''&&$filter['from_iso']>$filter['to_iso'])throw new RuntimeException('تاریخ شروع بعد از پایان است.');
    if(!in_array($filter['method'],['','cash','bank','personal'],true)||!in_array($filter['status'],['','draft','pending','approved','rejected','void'],true))throw new RuntimeException('روش پرداخت یا وضعیت معتبر نیست.');
    foreach(['minimum','maximum'] as $key)if($filter[$key]!=='')$filter[$key]=(string)cashbox_amount($filter[$key],true);
    if($filter['minimum']!==''&&$filter['maximum']!==''&&(int)$filter['minimum']>(int)$filter['maximum'])throw new RuntimeException('بازه مبلغ معتبر نیست.');
    $filter['direction']=$filter['direction']==='asc'?'asc':'desc';
    $page=$input['report_page']??'1';$size=$input['size']??'25';
    if(!is_scalar($page)||!is_scalar($size))throw new RuntimeException('صفحه گزارش معتبر نیست.');
    $filter['report_page']=max(1,min(10000,(int)ascii_digits((string)$page)));
    $filter['size']=in_array((int)$size,[25,50,100],true)?(int)$size:25;
    $columns=$input['columns']??[];
    if(!is_array($columns)||count($columns)>26||array_filter($columns,fn($v)=>!is_string($v)))throw new RuntimeException('ستون‌های گزارش معتبر نیستند.');
    $filter['columns']=$columns;
    return $filter;
}
function expense_center_where(int $tenant,array $filter,string $date='e.expense_date'): array {
    if(!in_array($date,['e.expense_date','DATE(p.posted_at)','DATE(s.created_at)','r.return_date'],true))throw new LogicException('Unexpected report date column');
    [$sql,$args]=expense_scope($tenant);
    foreach(['branch'=>'e.branch_id','user_id'=>'e.created_by','category_id'=>'e.category_id','status'=>'e.approval_status'] as $key=>$column)
        if($filter[$key]!==''){$sql.=' AND '.$column.'=?';$args[]=$filter[$key];}
    foreach(['from_iso'=>[$date,'>='],'to_iso'=>[$date,'<='],'minimum'=>['e.total_amount','>='],'maximum'=>['e.total_amount','<=']] as $key=>[$column,$op])
        if($filter[$key]!==''){$sql.=' AND '.$column.$op.'?';$args[]=$filter[$key];}
    if($filter['q']!==''){$sql.=' AND (e.title LIKE ? OR CAST(e.id AS CHAR) LIKE ?)';$args[]='%'.$filter['q'].'%';$args[]='%'.$filter['q'].'%';}
    if($date==='e.expense_date'&&$filter['method']!==''){$sql.=' AND EXISTS(SELECT 1 FROM expense_payments fp WHERE fp.company_id=e.company_id AND fp.expense_id=e.id AND fp.method=?)';$args[]=$filter['method'];}
    return [$sql,$args];
}
function expense_report_add(int $a,int|string $b): int {
    if(!preg_match('/^-?[0-9]+$/D',(string)$b))throw new RuntimeException('مبلغ گزارش معتبر نیست.');
    $digits=ltrim((string)$b,'-0');$max=(string)PHP_INT_MAX;
    if(strlen($digits)>strlen($max)||(strlen($digits)===strlen($max)&&strcmp($digits,$max)>0))throw new RuntimeException('مجموع گزارش خارج از محدوده محاسبات دقیق است؛ بازه را محدود کنید.');
    $b=(int)$b;
    if(($b>0&&$a>PHP_INT_MAX-$b)||($b<0&&$a<PHP_INT_MIN-$b))throw new RuntimeException('مجموع گزارش خارج از محدوده است؛ بازه را محدود کنید.');
    return $a+$b;
}
function expense_center_rows(int $tenant,array $filter): array {
    $ownsTransaction=!db()->inTransaction();
    if($ownsTransaction)db()->beginTransaction();
    try{$rows=expense_center_read_rows($tenant,$filter);if($ownsTransaction)db()->commit();return $rows;}
    catch(Throwable $ex){if($ownsTransaction&&db()->inTransaction())db()->rollBack();throw $ex;}
}
function expense_center_read_rows(int $tenant,array $filter): array {
    require_permission('expenses.view');
    if(!expenses_ready()||!expense_extensions_ready())throw new RuntimeException('ساختار گزارش هزینه آماده نیست.');
    $type=$filter['report'];$rows=[];
    $joins=' FROM expenses e JOIN branches b ON b.id=e.branch_id AND b.company_id=e.company_id JOIN expense_categories c ON c.id=e.category_id AND c.company_id=e.company_id JOIN users u ON u.id=e.created_by AND u.company_id=e.company_id ';
    $names='b.name branch,c.name category,u.full_name user,e.title,e.id document_id';
    $ledger=in_array($type,['cash','bank','personal','settlements','returns','settlement_returns','movements'],true);
    if($ledger){
        if(in_array($type,['cash','bank','personal','movements'],true)){
            [$where,$args]=expense_center_where($tenant,$filter,'DATE(p.posted_at)');
            $method=$type==='movements'?" AND p.method IN ('cash','bank')":' AND p.method=?';
            if($type!=='movements')$args[]=$type;
            if($filter['method']!==''){$method.=' AND p.method=?';$args[]=$filter['method'];}
            $rows=query('SELECT '.$names.",p.posted_at date,p.id entry_id,p.method,p.amount,p.tracking reference,p.account_id,p.cash_shift_id,\n              'payment' kind,'out' direction".$joins.' JOIN expense_payments p ON p.expense_id=e.id AND p.company_id=e.company_id WHERE '.$where.' AND p.posted_at IS NOT NULL'.$method.' ORDER BY p.id DESC LIMIT 10001',$args)->fetchAll();
        }
        if(in_array($type,['settlements','movements'],true)){
            [$where,$args]=expense_center_where($tenant,$filter,'DATE(s.created_at)');
            if($filter['method']!==''){$where.=' AND s.method=?';$args[]=$filter['method'];}
            $rows=array_merge($rows,query('SELECT '.$names.",s.created_at date,s.id entry_id,s.method,s.amount,CAST(s.id AS CHAR) reference,s.account_id,s.cash_shift_id,'settlement' kind,'out' direction".$joins.' JOIN expense_settlements s ON s.expense_id=e.id AND s.company_id=e.company_id WHERE '.$where.' ORDER BY s.id DESC LIMIT 10001',$args)->fetchAll());
        }
        if(in_array($type,['returns','settlement_returns','movements'],true)&&expense_returns_ready()){
            [$where,$args]=expense_center_where($tenant,$filter,'r.return_date');
            $target=$type==='returns'?' AND r.payment_id IS NOT NULL':($type==='settlement_returns'?' AND r.settlement_id IS NOT NULL':'');
            if($filter['method']!==''){$target.=' AND r.method=?';$args[]=$filter['method'];}
            $rows=array_merge($rows,query('SELECT '.$names.",r.return_date date,r.id entry_id,r.method,r.amount,r.reference,r.account_id,r.cash_shift_id,IF(r.settlement_id IS NULL,'payment.return','settlement.return') kind,'in' direction".$joins.' JOIN expense_returns r ON r.expense_id=e.id AND r.company_id=e.company_id WHERE '.$where.$target.' ORDER BY r.id DESC LIMIT 10001',$args)->fetchAll());
        }
        // Personal payment recognizes a liability; it is not a cash/bank outflow.
        if($type==='movements')$rows=array_values(array_filter($rows,fn($r)=>$r['method']!=='personal'));
        $accounts=query('SELECT id,title FROM company_bank_accounts WHERE company_id=?',[$tenant])->fetchAll(PDO::FETCH_KEY_PAIR);
        $shifts=query('SELECT sh.id,cr.name FROM cash_shifts sh JOIN cash_registers cr ON cr.id=sh.register_id AND cr.company_id=sh.company_id WHERE sh.company_id=?',[$tenant])->fetchAll(PDO::FETCH_KEY_PAIR);
        foreach($rows as &$row){$row['source']=$row['account_id']!==null?($accounts[$row['account_id']]??'حساب').' #'.fa_digits($row['account_id']):($row['cash_shift_id']!==null?($shifts[$row['cash_shift_id']]??'صندوق').' #'.fa_digits($row['cash_shift_id']):'شخصی');$row['method']=expense_method($row['method']);$row['amount']=$row['direction']==='in'?'-'.$row['amount']:$row['amount'];$row['direction']=$row['direction']==='in'?'ورود وجه':'خروج وجه';$row['date']=substr($row['date'],0,10);$row['kind']=['payment'=>'پرداخت هزینه','settlement'=>'تسویه کارکنان','payment.return'=>'برگشت هزینه','settlement.return'=>'برگشت تسویه'][$row['kind']];}unset($row);
    }else{
        [$where,$args]=expense_center_where($tenant,$filter);
        if(in_array($type,['pending','rejected','void'],true)){$where.=' AND e.approval_status=?';$args[]=$type;}
        if($type==='items'){
            if($filter['status']==='')$where.=" AND e.approval_status='approved'";
            $rows=query('SELECT '.$names.',e.expense_date date,it.title item,it.description,it.quantity qty,it.unit,it.unit_price,it.line_total amount'.$joins.' JOIN expense_items it ON it.expense_id=e.id AND it.company_id=e.company_id WHERE '.$where.' ORDER BY e.expense_date DESC,it.id DESC LIMIT 10001',$args)->fetchAll();
        }elseif($type==='liabilities'){
            $returned=expense_returns_ready()?"COALESCE((SELECT SUM(rf.amount) FROM expense_returns rf WHERE rf.company_id=e.company_id AND rf.expense_id=e.id AND rf.settlement_id IS NOT NULL),0)":'0';
            $due="COALESCE((SELECT SUM(ep.amount) FROM expense_payments ep WHERE ep.company_id=e.company_id AND ep.expense_id=e.id AND ep.method='personal' AND ep.posted_at IS NOT NULL),0)-COALESCE((SELECT SUM(es.amount) FROM expense_settlements es WHERE es.company_id=e.company_id AND es.expense_id=e.id),0)+".$returned;
            $rows=query('SELECT '.$names.',e.expense_date date,e.approval_status status,('.$due.') amount'.$joins.' WHERE '.$where." AND e.approval_status='approved' HAVING amount>0 ORDER BY e.expense_date DESC,e.id DESC LIMIT 10001",$args)->fetchAll();
        }else{
            $documents=query('SELECT '.$names.',e.expense_date date,e.total_amount amount,e.approval_status status,e.classification,e.branch_id,e.category_id,e.created_by'.$joins.' WHERE '.$where.' ORDER BY e.expense_date DESC,e.id DESC LIMIT 10001',$args)->fetchAll();
            if(count($documents)>10000)throw new RuntimeException('گزارش بیشتر از ۱۰٬۰۰۰ سند است؛ فیلتر را محدود کنید.');
            if(in_array($type,['daily','monthly','branches','categories','users','comparison','performance'],true)){
                $groups=[];
                foreach($documents as $doc){
                    if($doc['status']!=='approved'||$doc['classification']!=='expense')continue;
                    $key=match($type){'daily'=>$doc['date'],'monthly'=>substr(ascii_digits(jalali_datetime($doc['date'],true)),0,7),'categories'=>(string)$doc['category_id'],'users'=>(string)$doc['created_by'],default=>(string)$doc['branch_id']};
                    if(!isset($groups[$key]))$groups[$key]=['date'=>$type==='monthly'?$key:$doc['date'],'branch'=>$doc['branch'],'category'=>$doc['category'],'user'=>$doc['user'],'qty'=>0,'amount'=>0,'sales'=>0];
                    $groups[$key]['qty']++;$groups[$key]['amount']=expense_report_add($groups[$key]['amount'],$doc['amount']);
                }
                if(in_array($type,['comparison','performance'],true)){
                    require_permission('reports.view');
                    if(!expense_manager())throw new RuntimeException('مقایسه تجمیعی فروش و هزینه مختص مدیر شرکت است.');
                    // Category, payment method and text filters would make sales/expense incomparable.
                    if($filter['category_id']!==''||$filter['method']!==''||$filter['q']!==''||$filter['minimum']!==''||$filter['maximum']!==''||$filter['status']!=='')throw new RuntimeException('مقایسه فروش و هزینه فقط با فیلتر شعبه، کاربر و بازه زمانی انجام می‌شود.');
                    $salesWhere='i.company_id=?';$params=[$tenant];
                    if(!expense_manager()){$salesWhere.=' AND i.user_id=? AND EXISTS(SELECT 1 FROM user_branch_assignments a WHERE a.company_id=i.company_id AND a.branch_id=i.branch_id AND a.user_id=? AND a.is_active=1)';array_push($params,user()['id'],user()['id']);}
                    foreach(['branch'=>'i.branch_id','user_id'=>'i.user_id'] as $key=>$column)if($filter[$key]!==''){$salesWhere.=' AND '.$column.'=?';$params[]=$filter[$key];}
                    foreach(['from_iso'=>'>=','to_iso'=>'<='] as $key=>$op)if($filter[$key]!==''){$salesWhere.=' AND COALESCE(i.invoice_date,DATE(i.created_at))'.$op.'?';$params[]=$filter[$key];}
                    if(database_table_exists('invoice_voids'))$salesWhere.=' AND NOT EXISTS(SELECT 1 FROM invoice_voids v WHERE v.company_id=i.company_id AND v.invoice_id=i.id)';
                    foreach(query('SELECT i.branch_id,b.name branch,COALESCE(SUM(i.total_amount),0) sales FROM invoices i LEFT JOIN branches b ON b.id=i.branch_id AND b.company_id=i.company_id WHERE '.$salesWhere.' GROUP BY i.branch_id,b.name',$params)->fetchAll() as $sale){
                        $key=(string)$sale['branch_id'];if(!isset($groups[$key]))$groups[$key]=['branch'=>$sale['branch']??'بدون انتساب شعبه','qty'=>0,'amount'=>0,'sales'=>0];
                        $groups[$key]['sales']=expense_report_add(0,$sale['sales']);
                    }
                    foreach($groups as &$group)$group['performance']=expense_report_add($group['sales'],-$group['amount']);unset($group);
                }
                $rows=array_values($groups);
            }else $rows=$documents;
        }
    }
    if(count($rows)>10000)throw new RuntimeException('گزارش بیشتر از ۱۰٬۰۰۰ ردیف است؛ فیلتر را محدود کنید.');
    $columns=expense_center_columns($type);$sort=isset($columns[$filter['sort']])?$filter['sort']:(isset($columns['date'])?'date':array_key_first($columns));
    $numeric=['amount','qty','unit_price','sales','performance'];
    usort($rows,function($a,$b)use($sort,$filter,$numeric){$left=$a[$sort]??'';$right=$b[$sort]??'';$cmp=in_array($sort,$numeric,true)?expense_report_add(0,$left)<=>expense_report_add(0,$right):strcmp((string)$left,(string)$right);return $filter['direction']==='asc'?$cmp:-$cmp;});
    return $rows;
}
function expense_center_columns(string $type): array {
    return match($type){
        'daily','monthly'=>['date'=>'دوره شمسی','qty'=>'تعداد اسناد','amount'=>'هزینه جاری (تومان)'],
        'branches'=>['branch'=>'شعبه','qty'=>'تعداد اسناد','amount'=>'هزینه جاری (تومان)'],
        'categories'=>['category'=>'دسته‌بندی','qty'=>'تعداد اسناد','amount'=>'هزینه جاری (تومان)'],
        'users'=>['user'=>'ثبت‌کننده','qty'=>'تعداد اسناد','amount'=>'هزینه جاری (تومان)'],
        'comparison','performance'=>['branch'=>'شعبه','sales'=>'فروش معتبر (تومان)','amount'=>'هزینه جاری (تومان)','performance'=>'فروش منهای هزینه جاری (تومان)'],
        'cash','bank','personal','settlements','returns','settlement_returns','movements'=>['date'=>'تاریخ گردش','document_id'=>'سند هزینه','entry_id'=>'شناسه گردش','branch'=>'شعبه','user'=>'ثبت‌کننده هزینه','title'=>'عنوان','kind'=>'نوع گردش','method'=>'روش','source'=>'حساب / صندوق','direction'=>'جهت','amount'=>'خروج خالص وجه (تومان)','reference'=>'مرجع'],
        'items'=>['date'=>'تاریخ','document_id'=>'سند','branch'=>'شعبه','item'=>'کالا / خدمت','description'=>'شرح','qty'=>'تعداد','unit'=>'واحد','unit_price'=>'قیمت واحد (تومان)','amount'=>'مبلغ ردیف (تومان)'],
        default=>['date'=>'تاریخ','document_id'=>'سند','branch'=>'شعبه','category'=>'دسته‌بندی','user'=>'ثبت‌کننده','title'=>'عنوان','status'=>'وضعیت','amount'=>$type==='liabilities'?'بدهی به کارمند (تومان)':'مبلغ سند (تومان)'],
    };
}
function expense_center_value(string $key,mixed $value): string {
    if($key==='date')return preg_match('/^\d{4}-\d{2}-\d{2}$/D',(string)$value)?jalali_datetime((string)$value,true):fa_digits((string)$value);
    if($key==='status')return expense_status((string)$value);
    if(in_array($key,['amount','qty','unit_price','sales','performance','document_id','entry_id'],true))return money((string)$value);
    return (string)$value;
}

function expense_center_selected_columns(array $filter): array {
    $all=expense_center_columns($filter['report']);
    if(!$filter['columns'])return $all;
    foreach($filter['columns'] as $column)if(!isset($all[$column]))throw new RuntimeException('ستون انتخابی متعلق به این گزارش نیست.');
    return array_intersect_key($all,array_flip($filter['columns']));
}
function expense_center_totals(array $rows,array $columns): array {
    $totals=[];
    foreach(['amount','qty','sales','performance'] as $column)if(isset($columns[$column])){
        $totals[$column]=0;foreach($rows as $row)$totals[$column]=expense_report_add($totals[$column],$row[$column]??0);
    }
    return $totals;
}
function expense_center_context(int $tenant,array $filter): array {
    $company=(string)query('SELECT name FROM companies WHERE id=?',[$tenant])->fetchColumn();
    $branch=$filter['branch']!==''?(string)query('SELECT name FROM branches WHERE company_id=? AND id=?',[$tenant,$filter['branch']])->fetchColumn():'تمام شعب مجاز';
    return ['title'=>expense_report_catalog()[$filter['report']],'company'=>$company,'branch'=>$branch,'period'=>($filter['from']!==''?fa_digits($filter['from']):'ابتدای ثبت‌ها').' تا '.($filter['to']!==''?fa_digits($filter['to']):'آخرین ثبت')];
}
function expense_center_table(array $rows,array $columns,array $totals): string {
    $html='<table><thead><tr>';
    foreach($columns as $label)$html.='<th>'.e($label).'</th>';
    $html.='</tr></thead><tbody>';
    foreach($rows as $row){$html.='<tr>';foreach($columns as $key=>$label)$html.='<td>'.e(expense_center_value($key,$row[$key]??'')).'</td>';$html.='</tr>';}
    if(!$rows)$html.='<tr><td colspan="'.count($columns).'">در این بازه و با این فیلتر، نتیجه‌ای ثبت نشده است.</td></tr>';
    $html.='</tbody><tfoot><tr>';$first=true;
    foreach($columns as $key=>$label){$html.='<th>'.(isset($totals[$key])?e(money((string)$totals[$key])):($first?'جمع کل':'')).'</th>';$first=false;}
    return $html.'</tr></tfoot></table>';
}
function expense_center_export(int $tenant): never {
    require_permission('expenses.export');
    $filter=expense_center_filters($_GET);$rows=expense_center_rows($tenant,$filter);$columns=expense_center_selected_columns($filter);
    $totals=expense_center_totals($rows,$columns);$context=expense_center_context($tenant,$filter);
    if(($_GET['export']??'')==='pdf'){
        if(count($rows)>2000)throw new RuntimeException('برای PDF، بازه گزارش را به حداکثر ۲٬۰۰۰ ردیف محدود کنید؛ XLSX تا ۱۰٬۰۰۰ ردیف پشتیبانی می‌شود.');
        expense_report_pdf($context,expense_center_table($rows,$columns,$totals));
    }
    $data=[];$metadata=array_fill(0,count($columns),'');$metadata[0]=implode(' | ',$context);$data[]=$metadata;
    foreach($rows as $row){$record=[];foreach($columns as $key=>$label)$record[]=in_array($key,['amount','qty','unit_price','sales','performance'],true)?($row[$key]??0):expense_center_value($key,$row[$key]??'');$data[]=$record;}
    $last=[];$first=true;foreach($columns as $key=>$label){$last[]=$totals[$key]??($first?'جمع کل':'');$first=false;}$data[]=$last;
    $numeric=[];foreach(array_keys($columns) as $index=>$key)if(in_array($key,['amount','qty','unit_price','sales','performance'],true))$numeric[]=$index;
    export_excel_xlsx('sodoryar-expense-'.$filter['report'],array_values($columns),$data,$numeric);
}
function render_expense_report_center(int $tenant): void {
    require_permission('expenses.view');
    try{$filter=expense_center_filters($_GET);$rows=expense_center_rows($tenant,$filter);$columns=expense_center_selected_columns($filter);}
    catch(Throwable $ex){echo '<div class="alert error">'.e(public_operation_error($ex)).'</div>';return;}
    $allColumns=expense_center_columns($filter['report']);$totals=expense_center_totals($rows,$columns);$context=expense_center_context($tenant,$filter);
    $pages=max(1,(int)ceil(count($rows)/$filter['size']));$page=min($pages,$filter['report_page']);$visible=isset($_GET['print'])?$rows:array_slice($rows,($page-1)*$filter['size'],$filter['size']);
    $printQuery=$_GET;$printQuery['print']='1';unset($printQuery['export']);$printUrl='?'.http_build_query($printQuery);
    $branches=branch_choices($tenant,(int)user()['id']);$categories=query('SELECT id,name FROM expense_categories WHERE company_id=? ORDER BY name',[$tenant])->fetchAll();
    $users=query('SELECT id,full_name FROM users WHERE company_id=?'.(!expense_manager()?' AND id='.(int)user()['id']:'').' ORDER BY full_name',[$tenant])->fetchAll();
    ?>
    <section class="card"><div class="heading"><h1>گزارش‌های تخصصی هزینه‌ها</h1><a href="?page=expenses">ثبت و مدیریت هزینه</a></div>
    <form method="get" class="form-grid" data-expense-report-form><input type="hidden" name="page" value="expense_reports">
    <label>نوع گزارش<select name="report"><?php foreach(expense_report_catalog() as $key=>$name):?><option value="<?=e($key)?>" <?=$filter['report']===$key?'selected':''?>><?=e($name)?></option><?php endforeach;?></select></label>
    <label>از تاریخ شمسی<input name="from" value="<?=e($filter['from'])?>" placeholder="۱۴۰۵/۰۹/۰۱"></label><label>تا تاریخ شمسی<input name="to" value="<?=e($filter['to'])?>" placeholder="۱۴۰۵/۰۹/۳۰"></label>
    <label>شعبه<select name="branch"><option value="">تمام شعب مجاز</option><?php foreach($branches as $b):?><option value="<?=e($b['id'])?>" <?=$filter['branch']===(string)$b['id']?'selected':''?>><?=e($b['name'])?></option><?php endforeach;?></select></label>
    <label>ثبت‌کننده<select name="user_id"><option value="">تمام کاربران مجاز</option><?php foreach($users as $u):?><option value="<?=e($u['id'])?>" <?=$filter['user_id']===(string)$u['id']?'selected':''?>><?=e($u['full_name'])?></option><?php endforeach;?></select></label>
    <label>دسته‌بندی<select name="category_id"><option value="">همه دسته‌ها</option><?php foreach($categories as $c):?><option value="<?=e($c['id'])?>" <?=$filter['category_id']===(string)$c['id']?'selected':''?>><?=e($c['name'])?></option><?php endforeach;?></select></label>
    <label>روش پرداخت<select name="method"><option value="">همه روش‌ها</option><?php foreach(['cash','bank','personal'] as $method):?><option value="<?=e($method)?>" <?=$filter['method']===$method?'selected':''?>><?=e(expense_method($method))?></option><?php endforeach;?></select></label>
    <label>وضعیت<select name="status"><option value="">همه وضعیت‌ها</option><?php foreach(['draft','pending','approved','rejected','void'] as $status):?><option value="<?=e($status)?>" <?=$filter['status']===$status?'selected':''?>><?=e(expense_status($status))?></option><?php endforeach;?></select></label>
    <label>عنوان یا شماره سند<input name="q" maxlength="180" value="<?=e($filter['q'])?>"></label><label>حداقل مبلغ سند<input name="minimum" inputmode="numeric" value="<?=e($filter['minimum'])?>"></label><label>حداکثر مبلغ سند<input name="maximum" inputmode="numeric" value="<?=e($filter['maximum'])?>"></label>
    <label>مرتب‌سازی<select name="sort"><?php foreach($allColumns as $key=>$name):?><option value="<?=e($key)?>" <?=$filter['sort']===$key?'selected':''?>><?=e($name)?></option><?php endforeach;?></select></label><label>ترتیب<select name="direction"><option value="desc" <?=$filter['direction']==='desc'?'selected':''?>>نزولی</option><option value="asc" <?=$filter['direction']==='asc'?'selected':''?>>صعودی</option></select></label>
    <label>ردیف در صفحه<select name="size"><?php foreach([25,50,100] as $size):?><option value="<?=e($size)?>" <?=$filter['size']===$size?'selected':''?>><?=fa_digits($size)?></option><?php endforeach;?></select></label>
    <fieldset class="expense-report-columns"><legend>ستون‌های خروجی</legend><?php foreach($allColumns as $key=>$name):?><label><input type="checkbox" name="columns[]" value="<?=e($key)?>" <?=isset($columns[$key])?'checked':''?>><?=e($name)?></label><?php endforeach;?></fieldset>
    <button>نمایش گزارش</button><?php if(can('expenses.export')):?><button name="export" value="xlsx">دریافت XLSX</button><button name="export" value="pdf">دریافت PDF</button><?php endif;?><a href="<?=e($printUrl)?>" target="_blank" rel="noopener">چاپ تمام نتایج</a></form></section>
    <section class="card expense-report-print"><h2><?=e($context['title'])?></h2><p><?=e($context['company'].' | '.$context['branch'].' | '.$context['period'])?></p>
    <p>تعداد نتایج: <?=fa_digits(count($rows))?>؛ صفحه <?=fa_digits($page)?> از <?=fa_digits($pages)?>. جمع پایین جدول، کل نتایج فیلترشده را پوشش می‌دهد.</p>
    <?php if(in_array($filter['report'],['comparison','performance'],true)):?><p>فروش معتبر منهای هزینه جاری ثبت‌شده، سود خالص حسابداری نیست. دارایی، موجودی و پیش‌پرداخت در هزینه جاری منظور نشده‌اند.</p><?php endif;?>
    <?php if(in_array($filter['report'],['movements','returns','settlement_returns'],true)):?><p>ورود وجه با مبلغ منفی و خروج وجه با مبلغ مثبت نمایش داده می‌شود. بازپرداخت کارکنان هزینه جدید نیست. مانده بانکی از این گزارش استنباط نمی‌شود.</p><?php endif;?>
    <div class="table-wrap"><?=expense_center_table($visible,$columns,$totals)?></div>
    <nav aria-label="صفحه‌بندی گزارش هزینه"><?php foreach(array_unique([1,max(1,$page-1),$page,min($pages,$page+1),$pages]) as $number):$url=$_GET;$url['report_page']=$number;unset($url['export']);?><a href="?<?=e(http_build_query($url))?>" <?=$number===$page?'aria-current="page"':''?>><?=fa_digits($number)?></a> <?php endforeach;?></nav></section>
    <?php if(isset($_GET['print'])):?><span data-expense-auto-print hidden></span><?php endif;
}
