<?php
declare(strict_types=1);

function finance_preset_format_value(string $key,mixed $value,array $money,bool $excel=false): string|int {
    if($value===null || $value==='')return '';
    if(in_array($key,$money,true)){
        if($excel)return (int)$value;
        return money_toman($value);
    }
    if(in_array($key,['period','voided_at','started_at'],true)){
        if(preg_match('/^\d{4}-\d{2}-\d{2}(?:[ T].*)?$/',(string)$value)) {
            $date=jalali_datetime((string)$value,true);
            return $excel?$date:e($date);
        }
        return $excel?fa_digits((string)$value):e(fa_digits((string)$value));
    }
    if($key==='status'){
        $map=['open'=>'باز','pending'=>'در انتظار تأیید','approved'=>'تأییدشده','rejected'=>'ردشده'];
        $value=$map[$value]??(string)$value;
    }
    if($key==='payment_method') $value=$value==='cash'?'نقدی':($value==='pos'?'کارتخوان':$value);
    if(in_array($key,['invoice_number','invoice','national_code','id'],true))$value=display_financial_reference((string)$value);
    if(is_numeric($value) && !in_array($key,['national_code','invoice_number','invoice'],true))
        $value=fa_digits((string)$value);
    return $excel?(string)$value:e((string)$value);
}

function finance_preset_export(int $tenant,array $f): never {
    require_permission('reports.view');
    require_permission('reports.export');
    $result=finance_preset_data($tenant,$f,true);
    if(!$result['headers'])throw new RuntimeException($result['message']?:'این گزارش در دسترس نیست.');
    $keys=array_keys($result['headers']);
    $columns=array_values($result['headers']);
    $numericIndexes=[];
    foreach($keys as $index=>$key)if(in_array($key,$result['money'],true) || in_array($key,['invoice_count','receipt_count','item_count'],true))$numericIndexes[]=$index;
    $rows=[];
    foreach($result['rows'] as $record){
        $line=[];
        foreach($keys as $key){
            $value=finance_preset_format_value($key,$record[$key]??null,$result['money'],true);
            if(in_array($key,['invoice_count','receipt_count','item_count'],true) && $value!=='')$value=(int)$record[$key];
            $line[]=$value;
        }
        $rows[]=$line;
    }
    export_excel_xlsx('sodoryar-finance-'.$f['type'],$columns,$rows,$numericIndexes);
}

function render_finance_presets(int $tenant): void {
    require_permission('reports.view');
    $f=finance_preset_input($_GET);
    $catalog=finance_preset_catalog();
    echo '<section class="card financial-report-workspace"><h2>مرکز گزارش‌های تخصصی و مدیریتی</h2>'
        .'<p class="muted">گزارش‌ها مستقل از فاکتورها و دفتر واقعی دریافت‌ها محاسبه می‌شوند؛ تاریخ فروش و تاریخ وصول دو مبنای متفاوت دارند.</p>';
    echo '<form method="get" class="form-grid finance-preset-form">'
        .'<input type="hidden" name="page" value="finance_center"><label>نوع گزارش<select name="report_type">';
    foreach($catalog as $slug=>$title){
        echo '<option value="'.e($slug).'"'.($slug===$f['type']?' selected':'').'>'.e($title).'</option>';
    }
    echo '</select></label>';
    foreach(['from'=>'از تاریخ','to'=>'تا تاریخ'] as $key=>$label){
        $id='finance-preset-date-'.$key;
        echo '<label for="'.$id.'">'.e($label).' (شمسی)</label><div class="jalali-field">'
           .'<input id="'.$id.'" type="hidden" name="date_'.$key.'" value="'.e($f[$key]).'" data-jalali-iso>'
           .'<button type="button" class="secondary" data-jalali-open>انتخاب تاریخ</button></div>';
    }
    $operators=document_scope()==='own'?
      query('SELECT id,full_name FROM users WHERE id=? AND company_id=?',[(int)user()['id'],$tenant])->fetchAll():
      query('SELECT id,full_name FROM users WHERE company_id=? AND is_active=1 ORDER BY full_name,id LIMIT 300',[$tenant])->fetchAll();
    echo '<label>اپراتور<select name="user_id"><option value="">همه کاربران مجاز</option>';
    foreach($operators as $operator){
        echo '<option value="'.(int)$operator['id'].'"'.($f['user']===(int)$operator['id']?' selected':'').'>'.e($operator['full_name']).'</option>';
    }
    echo '</select></label><label>روش پرداخت<select name="payment_type"><option value="">تمام روش‌ها</option>'
      .'<option value="cash"'.($f['method']==='cash'?' selected':'').'>نقدی</option>'
      .'<option value="pos"'.($f['method']==='pos'?' selected':'').'>کارتخوان</option></select></label>';
    $terminals=query('SELECT id,brand FROM pos_settings WHERE company_id=? AND is_active=1 ORDER BY brand,id LIMIT 100',[$tenant])->fetchAll();
    echo '<label>دستگاه کارتخوان<select name="terminal_id"><option value="">تمام دستگاه‌ها</option>';
    foreach($terminals as $terminal){
        echo '<option value="'.(int)$terminal['id'].'"'.($f['terminal']===(int)$terminal['id']?' selected':'').'>'.e($terminal['brand']).'</option>';
    }
    echo '</select></label>'
      .'<label>عنوان خدمت<input name="service" maxlength="100" value="'.e($f['service']).'" placeholder="عنوان خدمت"></label>'
      .'<label>حداقل مبلغ (تومان)<input name="amount_min" data-money-input inputmode="numeric" value="'.e($f['min']===null?'':(string)$f['min']).'"></label>'
      .'<label>حداکثر مبلغ (تومان)<input name="amount_max" data-money-input inputmode="numeric" value="'.e($f['max']===null?'':(string)$f['max']).'"></label>'
      .'<button type="submit">ساخت گزارش</button></form>';
    $result=finance_preset_data($tenant,$f);
    $queryParams=[
        'page'=>'finance_center','report_type'=>$f['type'],'date_from'=>$f['from'],'date_to'=>$f['to'],
        'user_id'=>$f['user']??'','terminal_id'=>$f['terminal']??'',
        'payment_type'=>$f['method'],'service'=>$f['service'],
        'amount_min'=>$f['min']??'','amount_max'=>$f['max']??'',
    ];
    if(can('reports.export') && $result['headers']){
        $downloadParams=$queryParams+['preset_export'=>'excel'];
        echo '<p><a class="button-link" href="?'.e(http_build_query($downloadParams)).'">خروجی Excel گزارش کامل</a></p>';
    }
    if($result['message'])echo '<p class="muted">'.e($result['message']).'</p>';
    if(!$result['headers']){echo '</section>';return;}
    echo '<div class="table-wrap"><table><thead><tr>';
    foreach($result['headers'] as $title)echo '<th>'.e($title).'</th>';
    echo '</tr></thead><tbody>';
    foreach($result['rows'] as $record){
        echo '<tr>';
        foreach($result['headers'] as $key=>$title)
            echo '<td>'.finance_preset_format_value($key,$record[$key]??null,$result['money']).'</td>';
        echo '</tr>';
    }
    if(!$result['rows'])echo '<tr><td colspan="'.count($result['headers']).'">رکوردی مطابق فیلترهای انتخابی پیدا نشد.</td></tr>';
    echo '</tbody></table></div>';
    $page=$f['page'];
    if($page>1){
        echo '<a class="button-link" href="?'.e(http_build_query($queryParams+['report_page'=>$page-1])).'">صفحه قبل</a> ';
    }
    if($result['has_more']){
        echo '<a class="button-link" href="?'.e(http_build_query($queryParams+['report_page'=>$page+1])).'">صفحه بعد</a>';
    }
    echo '<p class="muted">صفحه '.fa_digits((string)$page).' | '.fa_digits((string)$result['total']).' ردیف نمایش داده‌شده</p></section>';
}
