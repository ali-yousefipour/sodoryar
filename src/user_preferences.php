<?php
declare(strict_types=1);

/** Bound to the logged-in user and selected company, never taking IDs from input. */
function user_preferences_ready(): bool {
    static $ready=null;
    if($ready===null)$ready=(bool)query("SHOW TABLES LIKE 'user_preferences'")->fetchColumn();
    return $ready;
}
function user_preferences_all(int $tenant,int $userId): array {
    if(!user_preferences_ready())return [];
    $rows=query('SELECT pref_key,pref_json FROM user_preferences
        WHERE company_id=? AND user_id=?',[$tenant,$userId])->fetchAll();
    $result=[];
    foreach($rows as $row){
        try{$result[$row['pref_key']]=json_decode($row['pref_json'],true,32,JSON_THROW_ON_ERROR);}
        catch(JsonException $ex){error_log('Invalid Sodoryar stored preference '.$row['pref_key']);}
    }
    return $result;
}
function user_preference_validate(string $key,mixed $value): mixed {
    if(strlen($key)>64)throw new RuntimeException('نوع تنظیم شخصی نامعتبر است.');
    switch($key) {
    case 'sidebar_collapsed':
        if(!is_bool($value))throw new RuntimeException('وضعیت منوی کناری نامعتبر است.');
        return $value;
    case 'table_page_size':
        if(!is_int($value)||!in_array($value,[20,50,100],true))throw new RuntimeException('تعداد ردیف‌های جدول نامعتبر است.');
        return $value;
    case 'report_favorites':
        if(!is_array($value)||!array_is_list($value)||count($value)>20)throw new RuntimeException('فهرست علاقه‌مندی گزارش‌ها نامعتبر است.');
        $catalog=array_keys(finance_preset_catalog());
        foreach($value as $item)if(!is_string($item)||!in_array($item,$catalog,true))throw new RuntimeException('گزارش علاقه‌مندی نامعتبر است.');
        return array_values(array_unique($value));
    case 'invoice_manager_columns':
        $allowed=['row','number','date','customer','national','mobile','services','amount','paid','remaining','method','operator','status'];
        if(!is_array($value)||!array_is_list($value)||!$value||count($value)>count($allowed))
            throw new RuntimeException('ستون‌های مدیریت فاکتور نامعتبرند.');
        foreach($value as $item)if(!is_string($item)||!in_array($item,$allowed,true))throw new RuntimeException('ستون نامعتبر است.');
        return array_values(array_unique($value));
    case 'report_columns':
        if(!is_array($value)||array_is_list($value)||count($value)>20)throw new RuntimeException('ستون‌های گزارش نامعتبرند.');
        $catalog=array_keys(finance_preset_catalog());
        $result=[];
        foreach($value as $type=>$columns){
            if(!is_string($type)||!in_array($type,$catalog,true)
                ||!is_array($columns)||!array_is_list($columns)||count($columns)>20)
                throw new RuntimeException('تنظیم ستون گزارش نامعتبر است.');
            foreach($columns as $column)if(!is_string($column)||!preg_match('/^[a-z_]{1,40}$/D',$column))
                throw new RuntimeException('نام ستون گزارش نامعتبر است.');
            $result[$type]=array_values(array_unique($columns));
        }
        return $result;
    case 'print_preferences':
        if(!is_array($value)||array_is_list($value)||array_diff(array_keys($value),['paper','margin_mm']))
            throw new RuntimeException('تنظیم چاپ نامعتبر است.');
        $paper=$value['paper']??'a4';
        $margin=$value['margin_mm']??8;
        if(!in_array($paper,['a4','a5','thermal'],true)||!is_int($margin)||$margin<0||$margin>20)
            throw new RuntimeException('نوع کاغذ یا حاشیه چاپ نامعتبر است.');
        return ['paper'=>$paper,'margin_mm'=>$margin];
    case 'saved_report_filters':
        if(!is_array($value)||array_is_list($value)||count($value)>15)throw new RuntimeException('فیلترهای ذخیره‌شده نامعتبر است.');
        foreach($value as $name=>$item){
            if(!is_string($name)||mb_strlen($name)>60||!is_array($item)||count($item)>14)
                throw new RuntimeException('عنوان یا فیلتر ذخیره‌شده نامعتبر است.');
            foreach($item as $field=>$fieldValue){
                if(!in_array($field,['report_type','date_from','date_to','user_id','terminal_id','payment_type',
                    'service','amount_min','amount_max'],true)||!is_string($fieldValue)||mb_strlen($fieldValue)>120)
                    throw new RuntimeException('فیلتر ذخیره‌شده نامعتبر است.');
            }
        }
        return $value;
    default:throw new RuntimeException('این تنظیم قابل ذخیره نیست.');
    }
}
function user_preference_save(int $tenant,int $userId,string $key,mixed $value): void {
    if(!user_preferences_ready())throw new RuntimeException('ذخیره تنظیمات شخصی پس از مهاجرت ۰۲۱ فعال می‌شود.');
    $normalized=user_preference_validate($key,$value);
    $json=json_encode($normalized,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    if(strlen($json)>6000)throw new RuntimeException('حجم تنظیم شخصی بیش از حد مجاز است.');
    query('INSERT INTO user_preferences(company_id,user_id,pref_key,pref_json)
        VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE pref_json=VALUES(pref_json),updated_at=CURRENT_TIMESTAMP',
        [$tenant,$userId,$key,$json]);
}
