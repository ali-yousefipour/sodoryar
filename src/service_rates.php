<?php
declare(strict_types=1);

/** Effective-date tariff history, never mutating previously issued invoice snapshots. */
function service_rates_ready(): bool {
    static $ready=null;
    if($ready===null) $ready=(bool)query("SHOW TABLES LIKE 'service_rate_versions'")->fetchColumn();
    return $ready;
}

function effective_service_rows(int $tenant,string $date,array $selected=[]): array {
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if(!$parsed||$parsed->format('Y-m-d')!==$date) throw new RuntimeException('تاریخ تعرفه نامعتبر است.');
    if(count($selected)>1000) throw new RuntimeException('تعداد خدمات انتخابی نامعتبر است.');
    $params=[];
    $sql='SELECT s.id,';
    if(service_rates_ready()){
        $sql.='COALESCE(rate.service_title,s.title) title,COALESCE(rate.rate,s.price) price';
        $join=' LEFT JOIN service_rate_versions rate ON rate.id=(
             SELECT rv.id FROM service_rate_versions rv
             WHERE rv.company_id=s.company_id AND rv.service_id=s.id AND rv.effective_from<=?
             ORDER BY rv.effective_from DESC,rv.id DESC LIMIT 1)';
        $params[]=$date;
    }else{
        $sql.='s.title,s.price';
        $join='';
    }
    $sql.=' FROM services s'.$join.' WHERE s.company_id=? AND s.is_active=1';
    $params[]=$tenant;
    if($selected){
        $sql.=' AND s.id IN ('.implode(',',array_fill(0,count($selected),'?')).')';
        $params=array_merge($params,$selected);
    }
    $sql.=' ORDER BY title,id';
    return query($sql,$params)->fetchAll();
}

function register_initial_service_rate(int $tenant,int $serviceId,int $actor,string $title,int $price): void {
    if(!service_rates_ready())return;
    query("INSERT INTO service_rate_versions(company_id,service_id,effective_from,service_title,rate,changed_by,reason)
        VALUES(?,?,'1000-01-01',?,?,?,'تعرفه اولیه خدمت')",[$tenant,$serviceId,$title,$price,$actor]);
}

/** Must be called inside an explicit database transaction after locking the tenant's service row. */
function update_service_with_rates(int $tenant,int $serviceId,string $title,int $price,int $active,
    string $jalaliEffective,string $reason,int $actor): void {
    if(!db()->inTransaction()) throw new LogicException('Service update requires a transaction.');
    $old=query('SELECT id,title,price,is_active FROM services WHERE company_id=? AND id=? FOR UPDATE',
        [$tenant,$serviceId])->fetch();
    if(!$old) throw new RuntimeException('خدمت متعلق به این شرکت نیست.');
    $changed=$title!==(string)$old['title'] || $price!==(int)$old['price'];
    if($changed && service_rates_ready()){
        if(mb_strlen($reason)<5||mb_strlen($reason)>500) throw new RuntimeException('دلیل تغییر تعرفه بین ۵ تا ۵۰۰ نویسه الزامی است.');
        $effective=$jalaliEffective!==''?jalali_to_gregorian_date($jalaliEffective):date('Y-m-d');
        if($effective<date('Y-m-d')) throw new RuntimeException('برای جلوگیری از تغییر نرخ اسناد تاریخی، تاریخ اثر تعرفه نمی‌تواند پیش از امروز باشد.');
        query('INSERT INTO service_rate_versions(company_id,service_id,effective_from,service_title,rate,changed_by,reason)
            VALUES(?,?,?,?,?,?,?)',[$tenant,$serviceId,$effective,$title,$price,$actor,$reason]);
        audit('service.rate.change',json_encode([
          'service_id'=>$serviceId,'effective_from'=>$effective,
          'old_title'=>$old['title'],'new_title'=>$title,
          'old_price'=>(int)$old['price'],'new_price'=>$price,'reason'=>$reason
        ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
    }elseif($changed && !service_rates_ready()){
        throw new RuntimeException('برای تغییر تعرفه با حفظ تاریخچه، ابتدا مهاجرت ۰۲۰ را اجرا کنید.');
    }
    query('UPDATE services SET title=?,price=?,is_active=? WHERE id=? AND company_id=?',
        [$title,$price,$active,$serviceId,$tenant]);
    if($active!==(int)$old['is_active']){
        audit('service.activation.change',json_encode(['service_id'=>$serviceId,'is_active'=>$active],JSON_THROW_ON_ERROR));
    }
}

function render_service_rate_history(int $tenant,int $serviceId): string {
    if(!service_rates_ready()) return 'تاریخچه تغییر تعرفه پس از اجرای مهاجرت ۰۲۰ فعال می‌شود.';
    $records=query('SELECT effective_from,service_title,rate,changed_by,reason,recorded_at
        FROM service_rate_versions WHERE company_id=? AND service_id=?
        ORDER BY effective_from DESC,id DESC LIMIT 30',[$tenant,$serviceId])->fetchAll();
    $result='';
    foreach($records as $record){
        $result.='<li>'.e(jalali_datetime($record['effective_from'],true)).' — '
           .e($record['service_title']).' — '.money_toman($record['rate'])
           .' — '.e($record['reason']).'</li>';
    }
    return $result===''?'تغییر تعرفه‌ای ثبت نشده است.':'<ol>'.$result.'</ol>';
}
