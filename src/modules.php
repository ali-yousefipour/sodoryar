<?php
declare(strict_types=1);

function database_table_exists(string $table): bool {
    static $cache=[];
    if (!in_array($table,['user_profiles','invoice_voids'],true)) return false;
    if (!array_key_exists($table,$cache)) {
        try { query('SELECT 1 FROM '.$table.' LIMIT 0'); $cache[$table]=true; }
        catch (PDOException $ex) {
            // A temporarily unavailable database must never make voided invoices
            // appear valid by silently disabling the accounting exclusion.
            if ((string)$ex->getCode()==='42S02') $cache[$table]=false;
            else throw $ex;
        }
    }
    return $cache[$table];
}

function save_user_details(int $id, array $input): void {
    $national=ascii_digits(trim((string)($input['national_code']??'')));
    $mobile=ascii_digits(trim((string)($input['mobile']??'')));
    $address=trim((string)($input['address']??''));
    if (($national!=='' && !preg_match('/^\d{10}$/',$national)) || mb_strlen($mobile)>20 || mb_strlen($address)>1000) throw new RuntimeException('کد ملی، موبایل یا آدرس کاربر نامعتبر است.');
    if (!database_table_exists('user_profiles')) {
        if ($national!=='' || $mobile!=='' || $address!=='') throw new RuntimeException('برای ذخیره مشخصات تکمیلی، ابتدا میگرشن ۰۰۶ را اجرا کنید.');
        return;
    }
    query('INSERT INTO user_profiles(user_id,national_code,mobile,address) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE national_code=VALUES(national_code),mobile=VALUES(mobile),address=VALUES(address)',[$id,$national?:null,$mobile?:null,$address?:null]);
}

function invoice_extensions_ready(): bool {
    if (!database_table_exists('invoice_voids')) return false;
    try {
        $column=query("SHOW COLUMNS FROM invoice_items LIKE 'service_id'")->fetch();
        return $column && strtoupper((string)$column['Null'])==='YES';
    } catch (PDOException $ex) { return false; }
}

/** Authorised national-code autocomplete accepts 6-10 ASCII digits only. */
function valid_driver_lookup_prefix(string $prefix): bool {
    return preg_match('/^[0-9]{6,10}$/D',$prefix)===1;
}

function attach_global_driver(int $tenant, string $nationalCode): int {
    $nationalCode=trim($nationalCode);
    if (!preg_match('/^[0-9]{10}$/',$nationalCode)) throw new RuntimeException('کد ملی باید ده رقم باشد.');
    $driver=query('SELECT national_code,first_name,last_name,mobile,line_area,line_code FROM global_drivers WHERE national_code=?',[$nationalCode])->fetch();
    if (!$driver) throw new RuntimeException('کد ملی در بانک مرکزی تاکسیرانان یافت نشد.');
    query('INSERT INTO customers(company_id,national_code,first_name,last_name,mobile,line_area,line_code) VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id)',[
        $tenant,$driver['national_code'],$driver['first_name'],$driver['last_name'],$driver['mobile'],$driver['line_area'],$driver['line_code']
    ]);
    return (int)db()->lastInsertId();
}

/** Invoice-only mobile validation; accepts Persian digits and optional +98 prefix. */
function normalize_invoice_driver_mobile(string $value): string {
    $mobile=ascii_digits(trim($value));
    $mobile=preg_replace('/[\\s\\-()]+/u','',$mobile)??'';
    if (preg_match('/^\\+989[0-9]{9}$/D',$mobile)) $mobile='0'.substr($mobile,3);
    if (!preg_match('/^09[0-9]{9}$/D',$mobile)) {
        throw new RuntimeException('شماره همراه راننده الزامی است و باید ۱۱ رقم معتبر با ۰۹ باشد.');
    }
    return $mobile;
}

function register_missing_driver(int $tenant, string $nationalCode, array $fields, bool $invoiceRegistration=false): int {
    if (!preg_match('/^[0-9]{10}$/',$nationalCode)) throw new RuntimeException('کد ملی راننده باید ده‌رقمی باشد.');
    $existing=query('SELECT id FROM global_drivers WHERE national_code=?',[$nationalCode])->fetchColumn();
    if (!$existing) {
        $first=trim((string)($fields['first_name']??''));
        $last=trim((string)($fields['last_name']??''));
        $mobile=$invoiceRegistration
            ? normalize_invoice_driver_mobile((string)($fields['mobile']??''))
            : trim((string)($fields['mobile']??''));
        $line=trim((string)($fields['line_area']??''));
        if ($invoiceRegistration && $line!=='' && !query('SELECT 1 FROM global_drivers WHERE TRIM(line_area)=? LIMIT 1',[$line])->fetchColumn()) {
            throw new RuntimeException('خط فعالیت انتخاب‌شده در بانک مرکزی رانندگان وجود ندارد.');
        }
        $lineCode=trim((string)($fields['line_code']??''));
        if ($first==='' || $last==='' || mb_strlen($first)>100 || mb_strlen($last)>100 || mb_strlen($mobile)>20 || mb_strlen($line)>180 || mb_strlen($lineCode)>40) throw new RuntimeException('این کد ملی در بانک مرکزی وجود ندارد؛ نام و نام خانوادگی و اطلاعات معتبر راننده جدید را وارد کنید.');
        query('INSERT INTO global_drivers(national_code,first_name,last_name,mobile,line_area,line_code) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE national_code=VALUES(national_code)',[$nationalCode,$first,$last,$mobile,$line,$lineCode]);
        audit('global_driver.create',$nationalCode);
    }
    return attach_global_driver($tenant,$nationalCode);
}

function module_page_permission(string $page): ?string {
    return [
        'bills'=>'bills.view', 'commissions'=>'commissions.view',
        'pos'=>'pos.view', 'activity'=>'activity.view',
    ][$page] ?? null;
}

/** Trash is activated only after the explicit migration; no implicit schema changes. */
function bill_trash_ready(): bool {
    static $ready = null;
    if ($ready === null) {
        // A partially applied migration must not expose actions that need both fields.
        $ready = (bool) query("SHOW COLUMNS FROM bill_payments LIKE 'deleted_at'")->fetch()
            && (bool) query("SHOW COLUMNS FROM bill_payments LIKE 'deleted_by'")->fetch();
    }
    return $ready;
}

/** Never physically delete confirmed/card-terminal or traceable financial records. */
function bill_can_permanently_delete(array $bill): bool {
    return $bill['deleted_at'] !== null
        && $bill['paid_method'] === 'manual'
        && trim((string)($bill['ref_code'] ?? '')) === ''
        && trim((string)($bill['response_code'] ?? '')) === '';
}

function handle_module_post(string $page, string $action): void {
    $tenant = $page==='profile' ? 0 : tenant_id();
    if ($page === 'roles' && in_array($action,['create','update','copy','toggle','default','remove'],true)) {
        require_admin();
        require_permission('roles.manage');
        if (!company_roles_ready()) throw new RuntimeException('ابتدا مهاجرت ۰۱۰ مدیریت نقش‌ها را اجرا کنید.');
        $id=filter_var($_POST['role_id']??null,FILTER_VALIDATE_INT);
        $existing=$id?query('SELECT id,name,is_active FROM company_roles WHERE id=? AND company_id=?',[$id,$tenant])->fetch():false;
        if ($action!=='create' && !$existing) throw new RuntimeException('نقش متعلق به این شرکت نیست.');
        $permissions=array_unique((array)($_POST['permissions']??[]));
        foreach($permissions as $permission) {
            if (!is_string($permission) || !operator_permission_assignable($permission)) {
                throw new RuntimeException('مجوز انتخاب‌شده قابل واگذاری به نقش اپراتور نیست.');
            }
        }
        $name=trim((string)($_POST['name']??''));
        $description=trim((string)($_POST['description']??''));
        if (in_array($action,['create','update','copy'],true) && ($name==='' || mb_strlen($name)>120 || mb_strlen($description)>500)) {
            throw new RuntimeException('نام یا توضیحات نقش نامعتبر است.');
        }
        db()->beginTransaction();
        try {
            if ($action==='create' || $action==='copy') {
                query('INSERT INTO company_roles(company_id,name,description) VALUES(?,?,?)',[$tenant,$name,$description]);
                $newId=(int)db()->lastInsertId();
                if ($action==='copy') {
                    $copied=query('SELECT permission_key FROM company_role_permissions WHERE role_id=? AND company_id=?',[$id,$tenant])->fetchAll(PDO::FETCH_COLUMN);
                    $permissions=array_values(array_filter($copied,static fn($key)=>operator_permission_assignable($key)));
                }
                foreach($permissions as $permission) query('INSERT INTO company_role_permissions(role_id,company_id,permission_key) VALUES(?,?,?)',[$newId,$tenant,$permission]);
                audit('role.create',json_encode(['role_id'=>$newId,'source_id'=>$action==='copy'?$id:null,'permissions'=>$permissions],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
            } elseif ($action==='update') {
                query('UPDATE company_roles SET name=?,description=? WHERE id=? AND company_id=?',[$name,$description,$id,$tenant]);
                $previous=query('SELECT permission_key FROM company_role_permissions WHERE role_id=? AND company_id=?',[$id,$tenant])->fetchAll(PDO::FETCH_COLUMN);
                query('DELETE FROM company_role_permissions WHERE role_id=? AND company_id=?',[$id,$tenant]);
                foreach($permissions as $permission) query('INSERT INTO company_role_permissions(role_id,company_id,permission_key) VALUES(?,?,?)',[$id,$tenant,$permission]);
                audit('role.update',json_encode(['role_id'=>$id,'granted'=>array_values(array_diff($permissions,$previous)),'revoked'=>array_values(array_diff($previous,$permissions))],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
            } elseif ($action==='toggle') {
                $active=(int)$existing['is_active']===1?0:1;
                query('UPDATE company_roles SET is_active=?'.(!$active?',is_default=0':'').' WHERE id=? AND company_id=?',[$active,$id,$tenant]);
                audit('role.status',json_encode(['role_id'=>$id,'active'=>$active],JSON_THROW_ON_ERROR));
            } elseif ($action==='default') {
                if (!(int)$existing['is_active']) throw new RuntimeException('نقش غیرفعال را نمی‌توان پیش‌فرض کرد.');
                query('SELECT id FROM company_roles WHERE company_id=? FOR UPDATE',[$tenant])->fetchAll();
                query('UPDATE company_roles SET is_default=0 WHERE company_id=?',[$tenant]);
                query('UPDATE company_roles SET is_default=1 WHERE id=? AND company_id=?',[$id,$tenant]);
                audit('role.default',json_encode(['role_id'=>$id],JSON_THROW_ON_ERROR));
            } else {
                $count=(int)query('SELECT COUNT(*) FROM user_role_assignments WHERE role_id=? AND company_id=?',[$id,$tenant])->fetchColumn();
                if ($count>0) throw new RuntimeException('این نقش به کاربر اختصاص داده شده و قابل حذف نیست.');
                query('DELETE FROM company_roles WHERE id=? AND company_id=?',[$id,$tenant]);
                audit('role.remove',json_encode(['role_id'=>$id],JSON_THROW_ON_ERROR));
            }
            db()->commit();
        } catch(Throwable $ex) { db()->rollBack(); throw $ex; }
        redirect('roles','تغییرات نقش ذخیره شد.');
    }
    if ($page === 'bills' && $action === 'add') {
        require_permission('bills.create');
        $type=(string)($_POST['bill_type']??'');
        $method=(string)($_POST['paid_method']??'');
        $amount=parse_money_input($_POST['amount']??null,1);
        $bill=trim((string)($_POST['bill_id']??'')); $payment=trim((string)($_POST['payment_id']??''));
        $reference=trim((string)($_POST['ref_code']??''));
        $national=ascii_digits(trim((string)($_POST['national_code']??'')));
        $driver=trim((string)($_POST['driver_name']??''));
        if (!in_array($type,['abonman','citizen','other'],true) || !in_array($method,['manual','pos'],true) || !$amount || $bill==='' || $payment===''
            || mb_strlen($bill)>100 || mb_strlen($payment)>100 || mb_strlen($reference)>120
            || ($national!=='' && !preg_match('/^[0-9]{10}$/D',$national)) || mb_strlen($driver)>200) {
            throw new RuntimeException('مشخصات قبض یا مبلغ نامعتبر است.');
        }
        db()->beginTransaction();
        try {
            query('INSERT INTO bill_payments(company_id,user_id,bill_type,bill_id,payment_id,amount,ref_code,response_code,national_code,driver_name,paid_method) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[
                $tenant,user()['id'],$type,$bill,$payment,$amount,$reference,null,$national,$driver,$method
            ]);
            $recordId=(int)db()->lastInsertId();
            audit('bill.create',json_encode(['record_id'=>$recordId,'bill_id'=>$bill],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
            db()->commit();
        } catch(Throwable $ex) { db()->rollBack(); throw $ex; }
        redirect('bills','قبض به‌صورت دستی ثبت شد؛ تأیید بانکی انجام نشده است.');
    }
    if ($page === 'bills' && in_array($action, ['trash','restore','permanent_delete'], true)) {
        if (!bill_trash_ready()) throw new RuntimeException('ابتدا مهاجرت ۰۰۹ پایگاه داده را اجرا کنید.');
        $required = [
            'trash'=>'bills.soft_delete',
            'restore'=>'bills.restore',
            'permanent_delete'=>'bills.permanent_delete'
        ][$action];
        require_permission($required);
        $id = filter_var($_POST['bill_record_id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id) throw new RuntimeException('شناسه فیش نامعتبر است.');
        db()->beginTransaction();
        try {
            $bill=query('SELECT id,company_id,user_id,bill_id,payment_id,amount,paid_method,ref_code,response_code,deleted_at FROM bill_payments WHERE id=? AND company_id=? FOR UPDATE',[$id,$tenant])->fetch();
            if (!$bill) throw new RuntimeException('فیش متعلق به این شرکت پیدا نشد.');
            require_document_owner((int)$bill['user_id']);
            if ($action === 'trash') {
                if ($bill['deleted_at'] !== null) throw new RuntimeException('فیش قبلاً حذف موقت شده است.');
                query('UPDATE bill_payments SET deleted_at=CURRENT_TIMESTAMP,deleted_by=? WHERE id=? AND company_id=?', [user()['id'],$id,$tenant]);
                audit('bill.trash',json_encode(['record_id'=>$id,'bill_id'=>$bill['bill_id']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
            } elseif ($action === 'restore') {
                if ($bill['deleted_at'] === null) throw new RuntimeException('فیش در سطل زباله نیست.');
                query('UPDATE bill_payments SET deleted_at=NULL,deleted_by=NULL WHERE id=? AND company_id=?',[$id,$tenant]);
                audit('bill.restore',json_encode(['record_id'=>$id],JSON_THROW_ON_ERROR));
            } else {
                if (!bill_can_permanently_delete($bill)) throw new RuntimeException('حذف دائمی فقط برای فیش دستی حذف‌شده و فاقد رد تراکنش بانکی مجاز است.');
                $confirm=ascii_digits(trim((string)($_POST['confirm_bill_id']??'')));
                $expected=ascii_digits((string)$bill['bill_id']);
                if (!hash_equals($expected,$confirm)) throw new RuntimeException('برای تأیید، شناسه قبض را دقیق وارد کنید.');
                $now=time();
                if ($now-(int)($_SESSION['bill_delete_auth_window']??0)>900) {
                    $_SESSION['bill_delete_auth_window']=$now;
                    $_SESSION['bill_delete_auth_failures']=0;
                }
                if ((int)($_SESSION['bill_delete_auth_failures']??0)>=5) {
                    http_response_code(429);
                    throw new RuntimeException('تعداد تلاش‌های احراز هویت زیاد است؛ پس از ۱۵ دقیقه دوباره تلاش کنید.');
                }
                $password=(string)($_POST['current_password']??'');
                $hash=query('SELECT password_hash FROM users WHERE id=? AND is_active=1',[user()['id']])->fetchColumn();
                if (!$hash || !password_verify($password,(string)$hash)) {
                    $_SESSION['bill_delete_auth_failures']=(int)($_SESSION['bill_delete_auth_failures']??0)+1;
                    throw new RuntimeException('احراز هویت مجدد ناموفق بود.');
                }
                $_SESSION['bill_delete_auth_failures']=0;
                // Keep the independent audit record without personal identifiers.
                audit('bill.permanent_delete',json_encode(['record_id'=>$id,'amount'=>$bill['amount'],'reason'=>'manual_unconfirmed_record'],JSON_THROW_ON_ERROR));
                query('DELETE FROM bill_payments WHERE id=? AND company_id=? AND deleted_at IS NOT NULL',[$id,$tenant]);
            }
            db()->commit();
        } catch (Throwable $ex) {
            db()->rollBack();
            throw $ex;
        }
        redirect('bills',$action==='trash'?'فیش به سطل زباله منتقل شد.':($action==='restore'?'فیش بازیابی شد.':'فیش واجد شرایط به‌صورت دائمی حذف شد.'));
    }
    if ($page === 'pos' && $action === 'add') {
        require_admin();
        $brand=trim((string)($_POST['brand']??'')); $ip=trim((string)($_POST['ip_address']??''));
        $port=filter_var($_POST['port']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
        if ($brand==='' || ($ip!=='' && !filter_var($ip,FILTER_VALIDATE_IP)) || !$port) throw new RuntimeException('مشخصات کارتخوان نامعتبر است.');
        query('INSERT INTO pos_settings(company_id,brand,ip_address,port,is_active) VALUES(?,?,?,?,0)',[$tenant,$brand,$ip,$port]);
        audit('pos.create',$brand); redirect('pos','کارتخوان ثبت شد (اتصال عملیاتی هنوز فعال نیست).');
    }
    if ($page === 'templates' && $action === 'add') {
        require_admin(); $title=trim((string)($_POST['title']??'')); $paper=(string)($_POST['paper_type']??'');
        $font=(string)($_POST['font_family']??'Tahoma');
        $rollWidth=filter_var($_POST['roll_width_mm']??72,FILTER_VALIDATE_INT);
        if ($rollWidth===false || $rollWidth<56 || $rollWidth>76) throw new RuntimeException('عرض قابل چاپ رول باید بین ۵۶ تا ۷۶ میلی‌متر باشد.');
        if ($title==='' || mb_strlen($title)>160 || !in_array($paper,['thermal80','a5','a4'],true) || !in_array($font,['Tahoma','Vazirmatn'],true)) throw new RuntimeException('عنوان، قلم یا اندازه کاغذ نامعتبر است.');
        if ($paper==='a4') {
            $paperColumn=query("SHOW COLUMNS FROM print_templates LIKE 'paper_type'")->fetch();
            if (!$paperColumn || !str_contains((string)$paperColumn['Type'],"'a4'")) {
                throw new RuntimeException('برای ذخیره قالب A4 ابتدا Migration شماره ۰۱۲ را اجرا کنید.');
            }
        }
        $header=trim((string)($_POST['header_text']??'')); $footer=trim((string)($_POST['footer_text']??''));
        if (mb_strlen($header)>1000 || mb_strlen($footer)>1000) throw new RuntimeException('متن سربرگ یا پانوشت طولانی است.');
        $allowed=['header','invoice_number','date','customer','items','total','payment','footer'];
        $order=explode(',',(string)($_POST['block_order']??implode(',',$allowed)));
        if (count($order)!==count($allowed) || array_unique($order)!==$order || array_diff($allowed,$order)) throw new RuntimeException('ترتیب اجزای قالب معتبر نیست.');
        $logo=null;
        $file=$_FILES['logo']??null;
        if (is_array($file) && (int)($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) {
            if ((int)$file['error']!==UPLOAD_ERR_OK || (int)$file['size']>1024*1024 || (int)$file['size']<100 || !is_uploaded_file((string)$file['tmp_name'])) throw new RuntimeException('لوگو باید کمتر از یک مگابایت باشد.');
            $info=@getimagesize((string)$file['tmp_name']);
            if (!$info || !in_array($info[2],[IMAGETYPE_PNG,IMAGETYPE_JPEG],true) || $info[0]>2000 || $info[1]>2000) throw new RuntimeException('لوگو باید PNG یا JPG با حداکثر ابعاد ۲۰۰۰ پیکسل باشد.');
            $directory=dirname(__DIR__).'/uploads/template-logos';
            if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new RuntimeException('امکان ایجاد پوشه لوگو نیست.');
            $logo=bin2hex(random_bytes(16)).($info[2]===IMAGETYPE_PNG?'.png':'.jpg');
            if (!move_uploaded_file((string)$file['tmp_name'],$directory.'/'.$logo)) throw new RuntimeException('ذخیره لوگو ناموفق بود.');
            chmod($directory.'/'.$logo,0600);
        }
        try {
            query('INSERT INTO print_templates(company_id,title,paper_type,header_text,footer_text,logo_path,layout_json,is_default) VALUES(?,?,?,?,?,?,?,0)',[$tenant,$title,$paper,$header,$footer,$logo,json_encode(['font'=>$font,'order'=>$order,'roll_width_mm'=>$rollWidth],JSON_THROW_ON_ERROR)]);
        } catch(Throwable $ex) { if ($logo) @unlink(dirname(__DIR__).'/uploads/template-logos/'.$logo); throw $ex; }
        audit('template.create',$title); redirect('templates','قالب چاپ ثبت شد.');
    }
    if ($page === 'templates' && $action === 'default') {
        require_admin(); $id=filter_var($_POST['template_id']??null,FILTER_VALIDATE_INT);
        if (!$id || !query('SELECT id FROM print_templates WHERE id=? AND company_id=?',[$id,$tenant])->fetch()) throw new RuntimeException('قالب انتخاب‌شده معتبر نیست.');
        db()->beginTransaction();
        try {
            query('UPDATE print_templates SET is_default=0 WHERE company_id=?',[$tenant]);
            query('UPDATE print_templates SET is_default=1 WHERE id=? AND company_id=?',[$id,$tenant]);
            audit('template.default',(string)$id); db()->commit();
        } catch(Throwable $ex) { db()->rollBack(); throw $ex; }
        redirect('templates','قالب پیش‌فرض انتخاب شد.');
    }
    if ($page === 'settings' && $action === 'save') {
        require_admin();
        $post=(array)$_POST;
        $post['commission_enabled']=isset($_POST['commission_enabled'])?'1':'0';
        $type=(string)($_POST['commission_type']??'fixed');
        $period=(string)($_POST['commission_period']??'monthly');
        if (!in_array($type,['fixed','threshold'],true) || !in_array($period,['daily','monthly'],true)) throw new RuntimeException('تنظیمات پورسانت نامعتبر است.');
        if (mb_strlen(trim((string)($_POST['company_name']??'')))>180) throw new RuntimeException('نام شرکت بیش از حد طولانی است.');
        $website=trim((string)($_POST['company_website']??''));
        if ($website!=='' && (!filter_var($website,FILTER_VALIDATE_URL) || !in_array(strtolower((string)parse_url($website,PHP_URL_SCHEME)),['http','https'],true))) throw new RuntimeException('آدرس وب‌سایت معتبر نیست.');
        foreach (['commission_rate','commission_limit','commission_over_limit_rate'] as $key) {
            $value=(string)($_POST[$key]??'0');
            if (!is_numeric($value) || (float)$value<0 || (float)$value>($key==='commission_limit'?1000000000000:100)) throw new RuntimeException('درصد یا سقف پورسانت نامعتبر است.');
        }
        if (!in_array((string)($_POST['currency']??''), ['rial','toman'], true)) {
            throw new RuntimeException('واحد پول انتخاب‌شده معتبر نیست.');
        }
        db()->beginTransaction();
        try {
            foreach (['company_name','company_phone','company_website','company_address','invoice_footer','currency','commission_enabled','commission_type','commission_period','commission_rate','commission_limit','commission_over_limit_rate'] as $key) {
                $value=trim((string)($post[$key]??''));
                if (mb_strlen($value)>1000) throw new RuntimeException('متن تنظیمات بیش از حد طولانی است.');
                query('INSERT INTO app_settings(company_id,setting_key,setting_value) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',[$tenant,$key,$value]);
            }
            if (trim((string)($_POST['company_name']??''))!=='') query('UPDATE companies SET name=? WHERE id=?',[trim((string)$_POST['company_name']),$tenant]);
            audit('settings.update');
            db()->commit();
        } catch (Throwable $ex) {
            db()->rollBack();
            throw $ex;
        }
        redirect('settings','تنظیمات ذخیره شد.');
    }
    if ($page === 'profile' && $action === 'password') {
        $old=(string)($_POST['current_password']??''); $new=(string)($_POST['new_password']??'');
        $hash=query('SELECT password_hash FROM users WHERE id=?',[user()['id']])->fetchColumn();
        if (!password_verify($old,(string)$hash) || strlen($new)<12 || $old===$new) throw new RuntimeException('رمز فعلی نادرست است یا رمز جدید حداقل ۱۲ کاراکتر و متفاوت نیست.');
        query('UPDATE users SET password_hash=? WHERE id=?',[password_hash($new,PASSWORD_DEFAULT),user()['id']]);
        audit('password.change'); session_regenerate_id(true); redirect('profile','رمز عبور تغییر کرد.');
    }
    if ($page === 'profile' && $action === 'photo') {
        save_profile_photo((int)user()['id']);
        redirect('profile','تصویر پروفایل ذخیره شد.');
    }
    if ($page === 'commissions' && $action === 'calculate') {
        require_admin();
        $userId=filter_var($_POST['user_id']??null,FILTER_VALIDATE_INT);
        $rate=filter_var($_POST['rate']??null,FILTER_VALIDATE_FLOAT);
        $date=ascii_digits(trim((string)($_POST['period_key']??'')));
        $period=(string)($_POST['period_type']??'');
        if (!$userId || $rate===false || $rate<0 || $rate>100 || !in_array($period,['daily','monthly'],true) || !preg_match($period==='daily'?'/^1[34]\d{2}[\/-]\d{2}[\/-]\d{2}$/':'/^1[34]\d{2}[\/-]\d{2}$/',$date)) throw new RuntimeException('دوره شمسی، نرخ یا کاربر نامعتبر است.');
        if (!query('SELECT id FROM users WHERE id=? AND company_id=? AND is_active=1',[$userId,$tenant])->fetch()) throw new RuntimeException('کاربر متعلق به شرکت نیست.');
        $date=str_replace('/','-',$date);
        $from=jalali_to_gregorian_date($period==='daily'?$date:$date.'-01');
        if ($period==='daily') $to=(new DateTimeImmutable($from))->modify('+1 day')->format('Y-m-d');
        else {
            [$jy,$jm]=array_map('intval',explode('-',$date));
            $jm++;if ($jm===13) { $jy++;$jm=1; }
            $to=jalali_to_gregorian_date(sprintf('%04d-%02d-01',$jy,$jm));
        }
        $voidFilter=database_table_exists('invoice_voids')?' AND NOT EXISTS (SELECT 1 FROM invoice_voids v WHERE v.invoice_id=invoices.id AND v.company_id=invoices.company_id)':'';
        $total=(int)query('SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE company_id=? AND user_id=? AND created_at>=? AND created_at<?'.$voidFilter,[$tenant,$userId,$from,$to])->fetchColumn();
        $settings=query('SELECT setting_key,setting_value FROM app_settings WHERE company_id=?',[$tenant])->fetchAll(PDO::FETCH_KEY_PAIR);
        if (($settings['commission_enabled']??'0')!=='1') throw new RuntimeException('ابتدا سیستم پورسانت را از تنظیمات شرکت فعال کنید.');
        $limit=max(0,(float)($settings['commission_limit']??0));
        $overRate=(float)($settings['commission_over_limit_rate']??0);
        $amount=($settings['commission_enabled']??'0')==='1' && ($settings['commission_type']??'fixed')==='threshold'
            ? (int)round(min($total,$limit)*(float)$rate/100 + max(0,$total-$limit)*$overRate/100)
            : (int)round($total*(float)$rate/100);
        query('INSERT INTO commissions(company_id,user_id,period_type,period_key,sales_total,rate,amount) VALUES(?,?,?,?,?,?,?)',[$tenant,$userId,$period,$date,$total,$rate,$amount]);
        audit('commission.calculate',$date); redirect('commissions','پورسانت محاسبه و ثبت شد.');
    }
}

function render_module(string $page, int $tenant): void {
    if ($page==='profile') { ?>
      <h1>پروفایل و امنیت حساب</h1><div class="card profile-card"><div class="profile-identity"><span class="avatar avatar-large"><?php if(user_photo_table_ready() && query('SELECT 1 FROM user_photos WHERE user_id=?',[user()['id']])->fetchColumn()): ?><img src="?page=my_photo" alt="تصویر کاربر" width="78" height="78"><?php else: ?><?=e(mb_substr(user()['full_name'],0,1))?><?php endif; ?></span><div><h2><?=e(user()['full_name'])?></h2><p class="muted">عکس پرسنلی حساب کاربری؛ به‌صورت مربعی و بهینه ذخیره می‌شود.</p></div></div><?php if(user_photo_table_ready()): ?><form method="post" enctype="multipart/form-data" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="photo"><label>انتخاب تصویر کاربر (JPG، PNG یا WebP)<input name="photo" type="file" accept="image/jpeg,image/png,image/webp" required></label><button>ذخیره تصویر</button></form><?php else: ?><div class="alert error">برای فعال‌شدن عکس پروفایل، مدیر هاست باید میگرشن ۰۰۵ را در phpMyAdmin اجرا کند.</div><?php endif; ?></div><div class="card"><h2>تغییر رمز عبور</h2><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="password"><label>رمز فعلی<input type="password" name="current_password" autocomplete="current-password" required></label><label>رمز جدید (حداقل ۱۲ کاراکتر)<input type="password" name="new_password" minlength="12" autocomplete="new-password" required></label><button>ذخیره رمز جدید</button></form></div>
    <?php return; }
    if ($page==='roles') { require_admin(); require_permission('roles.manage'); ?>
      <h1>مدیریت نقش‌های شرکت</h1>
      <p class="muted">نقش‌های سفارشی فقط برای اپراتورهای همین شرکت قابل استفاده‌اند. مجوزهای مدیریتی و ایجاد نقش قابل تفویض به این نقش‌ها نیستند.</p>
      <?php if(!company_roles_ready()): ?><div class="alert error">ابتدا مهاجرت دیتابیس ۰۱۰ را روی سرور اعمال کنید؛ کاربران و مجوزهای فعلی بدون تغییر باقی می‌مانند.</div><?php else: ?>
      <div class="card"><h2>تعریف نقش جدید</h2><form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="action" value="create">
      <div class="form-grid"><label>نام نقش<input name="name" maxlength="120" required placeholder="مثلاً مسئول صدور فاکتور"></label><label>توضیحات<input name="description" maxlength="500" placeholder="شرح مسئولیت‌های این نقش"></label></div>
      <fieldset class="permission-matrix"><legend>مجوزهای نقش</legend><div class="permission-groups"><?php foreach(permission_groups() as $group=>$items): ?><fieldset class="permission-group"><legend><?=e($group)?></legend><div class="choices"><?php foreach($items as $key=>$title): if(!operator_permission_assignable($key))continue; ?><label class="choice"><input type="checkbox" name="permissions[]" value="<?=e($key)?>"><?=e($title)?></label><?php endforeach; ?></div></fieldset><?php endforeach; ?></div></fieldset><button>ایجاد نقش</button></form></div>
      <div class="card"><h2>نقش‌های تعریف‌شده</h2>
      <?php $companyRoles=query('SELECT r.id,r.name,r.description,r.is_active,r.is_default,(SELECT COUNT(*) FROM user_role_assignments a WHERE a.role_id=r.id AND a.company_id=r.company_id) assigned_users FROM company_roles r WHERE r.company_id=? ORDER BY r.id DESC',[$tenant])->fetchAll(); ?>
      <?php if(!$companyRoles): ?><p class="muted">هنوز نقش سفارشی تعریف نشده است.</p><?php endif; ?>
      <?php foreach($companyRoles as $role): $grants=query('SELECT permission_key FROM company_role_permissions WHERE role_id=? AND company_id=?',[$role['id'],$tenant])->fetchAll(PDO::FETCH_COLUMN); ?>
      <details class="role-editor"><summary><strong><?=e($role['name'])?></strong> — <?=$role['is_active']?'فعال':'غیرفعال'?> <?=$role['is_default']?'— پیش‌فرض':''?> — <?=money($role['assigned_users'])?> کاربر</summary>
      <form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="action" value="update"><input type="hidden" name="role_id" value="<?=e($role['id'])?>">
      <div class="form-grid"><label>عنوان نقش<input name="name" maxlength="120" value="<?=e($role['name'])?>" required></label><label>توضیحات<input name="description" maxlength="500" value="<?=e($role['description'])?>"></label></div>
      <fieldset class="permission-matrix"><legend>دسترسی‌های نقش</legend><div class="permission-groups"><?php foreach(permission_groups() as $group=>$items): ?><fieldset class="permission-group"><legend><?=e($group)?></legend><div class="choices"><?php foreach($items as $key=>$title): if(!operator_permission_assignable($key))continue; ?><label class="choice"><input type="checkbox" name="permissions[]" value="<?=e($key)?>" <?=in_array($key,$grants,true)?'checked':''?>><?=e($title)?></label><?php endforeach; ?></div></fieldset><?php endforeach; ?></div></fieldset><button>ذخیره تغییرات</button></form>
      <div class="management-actions">
      <form method="post"><?=csrf_field()?><input type="hidden" name="action" value="toggle"><input type="hidden" name="role_id" value="<?=e($role['id'])?>"><button class="small secondary"><?=$role['is_active']?'غیرفعال کردن':'فعال کردن'?></button></form>
      <?php if($role['is_active'] && !$role['is_default']): ?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="default"><input type="hidden" name="role_id" value="<?=e($role['id'])?>"><button class="small secondary">تنظیم به‌عنوان نقش پیش‌فرض</button></form><?php endif; ?>
      <form method="post" class="role-copy-form"><?=csrf_field()?><input type="hidden" name="action" value="copy"><input type="hidden" name="role_id" value="<?=e($role['id'])?>"><label>نام نسخه جدید<input name="name" required maxlength="120" placeholder="کپی <?=e($role['name'])?>"></label><input type="hidden" name="description" value="<?=e($role['description'])?>"><button class="small secondary">کپی نقش</button></form>
      <?php if(!(int)$role['assigned_users']): ?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="remove"><input type="hidden" name="role_id" value="<?=e($role['id'])?>"><button class="small danger">حذف نقش بدون کاربر</button></form><?php endif; ?>
      </div></details><?php endforeach; ?></div>
      <?php endif; ?>
    <?php return; }
    if ($page==='bills') { require_permission('bills.view'); ?>
      <h1>پرداخت قبوض</h1><p class="muted">ثبت اطلاعات قبض به‌صورت دستی؛ تراکنش بانکی از این صفحه انجام نمی‌شود.</p>
      <?php if(can('bills.create')): ?><div class="card"><h2>ثبت قبض</h2><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="add"><label>نوع قبض<select name="bill_type"><option value="abonman">آبونمان</option><option value="citizen">شهروندی</option><option value="other">سایر</option></select></label><label>شناسه قبض<input name="bill_id" required></label><label>شناسه پرداخت<input name="payment_id" required></label><label>مبلغ (تومان)<input type="text" inputmode="numeric" name="amount" data-money-input data-currency="تومان" maxlength="22" required></label><label>روش ثبت<select name="paid_method"><option value="manual">ثبت دستی</option><option value="pos">کارتخوان (شماره پیگیری دستی)</option></select></label><label>شماره پیگیری<input name="ref_code"></label><label>کد ملی<input name="national_code"></label><label>نام راننده<input name="driver_name"></label><button>ثبت قبض</button></form></div><?php endif; ?>
      <?php $trashReady=bill_trash_ready(); $onlyOwn=document_scope()==='own'; $billParams=$onlyOwn?[$tenant,user()['id']]:[$tenant]; ?>
      <?php if (!$trashReady): ?><div class="alert">برای فعال‌سازی سطل زباله فیش‌ها، مهاجرت دیتابیس ۰۰۹ باید توسط مدیر پایگاه داده اجرا شود. تا آن زمان سوابق موجود بدون تغییر نمایش داده می‌شوند.</div><?php endif; ?>
      <div class="card"><h2>سوابق فیش‌ها</h2><div class="table-wrap"><table><thead><tr><th>تاریخ</th><th>نوع</th><th>شناسه قبض</th><th>مبلغ</th><th>روش</th><th>پیگیری</th><th>عملیات</th></tr></thead><tbody>
      <?php $records=query('SELECT id,created_at,bill_type,bill_id,amount,paid_method,ref_code FROM bill_payments WHERE company_id=?'.($onlyOwn?' AND user_id=?':'').($trashReady?' AND deleted_at IS NULL':'').' ORDER BY id DESC LIMIT 100',$billParams)->fetchAll(); foreach($records as $bill): ?>
      <tr><td><?=e(jalali_datetime($bill['created_at']))?></td><td><?=e($bill['bill_type'])?></td><td><?=e(fa_digits($bill['bill_id']))?></td><td><?=money_toman($bill['amount'])?></td><td><?=e($bill['paid_method'])?></td><td><?=e(fa_digits((string)$bill['ref_code']))?></td><td>
      <?php if($trashReady && can('bills.soft_delete')): ?><button class="small danger" type="button" data-dialog-open="trashBill<?=e($bill['id'])?>">حذف موقت</button><dialog id="trashBill<?=e($bill['id'])?>" class="app-dialog"><form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="action" value="trash"><input type="hidden" name="bill_record_id" value="<?=e($bill['id'])?>"><h2>انتقال فیش به سطل زباله</h2><p>شناسه قبض: <?=e(fa_digits($bill['bill_id']))?> — مبلغ: <?=money_toman($bill['amount'])?></p><p>فیش از سوابق فعال مخفی می‌شود و در صورت داشتن مجوز، قابل بازیابی است.</p><div class="dialog-actions"><button class="secondary" type="button" data-dialog-close>انصراف</button><button class="danger">تأیید حذف موقت</button></div></form></dialog><?php endif; ?>
      </td></tr><?php endforeach; ?>
      <?php if(!$records): ?><tr><td colspan="7">فیشی برای نمایش وجود ندارد.</td></tr><?php endif; ?></tbody></table></div></div>
      <?php if($trashReady && (can('bills.restore') || can('bills.permanent_delete'))): ?>
      <div class="card"><h2>سطل زباله فیش‌ها</h2><p class="muted">فیش‌های دارای شماره پیگیری یا پاسخ بانکی فقط قابل بازیابی هستند؛ حذف دائمی آن‌ها مجاز نیست.</p><div class="table-wrap"><table><thead><tr><th>شناسه</th><th>تاریخ حذف</th><th>شناسه قبض</th><th>مبلغ</th><th>عملیات</th></tr></thead><tbody>
      <?php $deletedBills=query('SELECT id,bill_id,amount,deleted_at,paid_method,ref_code,response_code FROM bill_payments WHERE company_id=?'.($onlyOwn?' AND user_id=?':'').' AND deleted_at IS NOT NULL ORDER BY deleted_at DESC LIMIT 100',$billParams)->fetchAll(); foreach($deletedBills as $bill): ?>
      <tr><td><?=e(fa_digits($bill['id']))?></td><td><?=e(jalali_datetime($bill['deleted_at']))?></td><td><?=e(fa_digits($bill['bill_id']))?></td><td><?=money_toman($bill['amount'])?></td><td><div class="management-actions">
      <?php if(can('bills.restore')): ?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="restore"><input type="hidden" name="bill_record_id" value="<?=e($bill['id'])?>"><button class="small secondary">بازیابی</button></form><?php endif; ?>
      <?php if(can('bills.permanent_delete') && bill_can_permanently_delete($bill)): ?>
      <button type="button" class="small danger" data-dialog-open="deleteBill<?=e($bill['id'])?>">حذف دائمی</button>
      <dialog class="app-dialog" id="deleteBill<?=e($bill['id'])?>" aria-labelledby="billDeleteTitle<?=e($bill['id'])?>"><form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="action" value="permanent_delete"><input type="hidden" name="bill_record_id" value="<?=e($bill['id'])?>"><h2 id="billDeleteTitle<?=e($bill['id'])?>">تأیید حذف دائمی فیش</h2><p>فیش با شناسه «<?=e(fa_digits($bill['bill_id']))?>» و مبلغ <?=money_toman($bill['amount'])?> به صورت فیزیکی حذف می‌شود. این اقدام قابل بازگشت نیست.</p><label>شناسه قبض<input name="confirm_bill_id" required autocomplete="off"></label><label>رمز عبور فعلی<input type="password" name="current_password" required autocomplete="current-password"></label><div class="dialog-actions"><button type="button" class="secondary" data-dialog-close>انصراف</button><button class="danger">تأیید حذف دائمی</button></div></form></dialog>
      <?php endif; ?></div></td></tr><?php endforeach; ?>
      <?php if(!$deletedBills): ?><tr><td colspan="5">سطل زباله خالی است.</td></tr><?php endif; ?></tbody></table></div></div>
      <?php endif; ?>
    <?php return; }
    if ($page==='pos') { require_permission('pos.view'); ?>
      <h1>مدیریت کارتخوان‌ها</h1><p class="muted">تعریف دستگاه برای استفاده در اتصال آینده؛ پرداخت آنلاین از سرور لینوکسی انجام نمی‌شود.</p>
      <?php if(can('users.manage')): ?><div class="card"><h2>افزودن کارتخوان</h2><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="add"><label>برند<input name="brand" required></label><label>IP دستگاه<input name="ip_address" placeholder="192.168.1.10"></label><label>پورت<input name="port" type="number" min="1" max="65535" required></label><button>ثبت دستگاه</button></form></div><?php endif; ?>
      <div class="card"><h2>دستگاه‌ها</h2><?php render_table(['برند','IP','پورت','فعال'],query('SELECT brand,ip_address,port,is_active FROM pos_settings WHERE company_id=? ORDER BY id DESC',[$tenant])->fetchAll()); ?></div>
    <?php return; }
    if ($page==='templates') { require_admin(); ?>
      <h1>طراحی قالب چاپ فاکتور</h1><p class="muted">تنظیم مشخصات قالب و مشاهده نمونه چاپ؛ برای استفاده در فاکتور، قالب موردنظر را پیش‌فرض کنید.</p>
      <div class="template-designer"><div class="card"><h2>قالب جدید</h2><form method="post" enctype="multipart/form-data" class="stack" id="templateEditor"><?=csrf_field()?><input type="hidden" name="action" value="add"><label>عنوان قالب<input name="title" required maxlength="160" placeholder="قالب پیش‌فرض شرکت"></label><label>نوع کاغذ<select name="paper_type"><option value="thermal80">حرارتی ۸ سانتی‌متر</option><option value="a4">A4</option><option value="a5">A5 (قالب‌های قبلی)</option></select></label><label>قلم<select name="font_family"><option value="Tahoma">Tahoma</option><option value="Vazirmatn">وزیرمتن</option></select></label><label>عرض قابل چاپ رول ۸ سانتی‌متری (میلی‌متر)<input type="number" min="56" max="76" value="72" name="roll_width_mm" required aria-describedby="rollWidthHelp"><small id="rollWidthHelp">عرض پیشنهادی: ۷۲ میلی‌متر؛ مطابق چاپگر قابل تنظیم است.</small></label><label>لوگوی شرکت (PNG/JPG حداکثر یک مگابایت)<input type="file" name="logo" accept="image/jpeg,image/png" aria-describedby="logoPreviewHelp"><small id="logoPreviewHelp">لوگو تنها در فاکتورهای همین شرکت استفاده می‌شود.</small><img id="templateLogoPreview" class="template-logo-preview" alt="پیش‌نمایش لوگوی انتخاب‌شده" hidden></label><label>متن سربرگ<input name="header_text" maxlength="1000" placeholder="فاکتور خدمات"></label><label>متن پانوشت<input name="footer_text" maxlength="1000" placeholder="با تشکر از مراجعه شما"></label><h3>چیدمان بخش‌ها</h3><p class="muted">برای تغییر ترتیب، بخش‌ها را بکشید یا از دکمه‌های جابه‌جایی استفاده کنید.</p><input type="hidden" name="block_order" id="templateBlockOrder" value="header,invoice_number,date,customer,items,total,payment,footer"><div class="template-order" id="templateOrderList"><?php foreach(['header'=>'سربرگ','invoice_number'=>'شماره فاکتور','date'=>'تاریخ','customer'=>'مشخصات تاکسیران','items'=>'ردیف خدمات','total'=>'جمع کل','payment'=>'پرداخت','footer'=>'پانوشت'] as $key=>$label): ?><div class="template-order-item" draggable="true" data-block="<?=e($key)?>"><span>☰ <?=e($label)?></span><span><button type="button" class="small secondary" data-move-up aria-label="انتقال به بالا">↑</button><button type="button" class="small secondary" data-move-down aria-label="انتقال به پایین">↓</button></span></div><?php endforeach; ?></div><button>ذخیره قالب جدید</button></form></div><div class="card"><h2>پیش‌نمایش فاکتور</h2><div class="invoice-paper" id="templatePreview" role="region" aria-label="پیش‌نمایش قالب انتخاب‌شده"><img id="templatePreviewLogo" class="receipt-logo" alt="لوگوی قالب چاپ" hidden><h3 data-block="header" data-preview-header>فاکتور خدمات</h3><p data-block="invoice_number">شماره فاکتور: ۱۴۰۵۰۸۱۶-۰۰۱</p><p data-block="date">تاریخ: ۱۴۰۵/۰۸/۱۶</p><p data-block="customer">نام مشتری: نمونه نمایشی | کد ملی: ۰۰۰۰۰۰۰۰۰۰</p><table data-block="items"><thead><tr><th>شرح خدمت</th><th>مبلغ</th></tr></thead><tbody><tr><td>خدمت نمونه</td><td>۱۰۰٬۰۰۰ تومان</td></tr></tbody></table><h3 data-block="total">مبلغ کل: ۱۰۰٬۰۰۰ تومان</h3><p data-block="payment">پرداخت: نقدی</p><p data-block="footer" data-preview-footer>با تشکر از مراجعه شما</p></div></div></div>
      <div class="card"><h2>قالب‌های ذخیره‌شده</h2><div class="table-wrap"><table><thead><tr><th>عنوان</th><th>کاغذ</th><th>پیش‌فرض</th><th>عملیات</th></tr></thead><tbody><?php foreach(query('SELECT id,title,paper_type,is_default FROM print_templates WHERE company_id=? ORDER BY id DESC',[$tenant])->fetchAll() as $t): ?><tr><td><?=e($t['title'])?></td><td><?=e($t['paper_type'])?></td><td><?=$t['is_default']?'بله':'خیر'?></td><td><?php if(!$t['is_default']): ?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="default"><input type="hidden" name="template_id" value="<?=e($t['id'])?>"><button class="small">انتخاب پیش‌فرض</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
    <?php return; }
    if ($page==='settings') { require_admin(); $s=query('SELECT setting_key,setting_value FROM app_settings WHERE company_id=?',[$tenant])->fetchAll(PDO::FETCH_KEY_PAIR); ?>
      <h1>اطلاعات شرکت و تنظیمات پورسانت</h1><form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="action" value="save"><section class="card"><h2>اطلاعات شرکت</h2><div class="form-grid"><label>نام شرکت<input name="company_name" maxlength="180" value="<?=e(query('SELECT name FROM companies WHERE id=?',[$tenant])->fetchColumn())?>" required></label><label>تلفن شرکت<input name="company_phone" value="<?=e($s['company_phone']??'')?>"></label><label>وب‌سایت<input name="company_website" type="url" placeholder="https://example.com" value="<?=e($s['company_website']??'')?>"></label><label class="wide">آدرس شرکت<input name="company_address" value="<?=e($s['company_address']??'')?>"></label></div></section><section class="card"><h2>تنظیمات پورسانت</h2><label class="choice"><input type="checkbox" name="commission_enabled" value="1" <?=($s['commission_enabled']??'0')==='1'?'checked':''?>>سیستم پورسانت فعال باشد</label><div class="form-grid"><label>نوع پورسانت<select name="commission_type"><option value="fixed" <?=($s['commission_type']??'fixed')==='fixed'?'selected':''?>>درصد ثابت</option><option value="threshold" <?=($s['commission_type']??'')==='threshold'?'selected':''?>>درصد پس از سقف</option></select></label><label>درصد پایه<input name="commission_rate" type="number" min="0" max="100" step="0.001" value="<?=e($s['commission_rate']??'0')?>"></label><label>دوره محاسبه<select name="commission_period"><option value="monthly" <?=($s['commission_period']??'monthly')==='monthly'?'selected':''?>>ماهانه</option><option value="daily" <?=($s['commission_period']??'')==='daily'?'selected':''?>>روزانه</option></select></label><label>سقف مبلغ (تومان)<input name="commission_limit" type="number" min="0" step="1" value="<?=e($s['commission_limit']??'0')?>"></label><label>درصد پس از سقف<input name="commission_over_limit_rate" type="number" min="0" max="100" step="0.001" value="<?=e($s['commission_over_limit_rate']??'0')?>"></label></div><p class="muted">در حالت ثابت، درصد پایه به کل فروش تعلق می‌گیرد. در حالت سقف‌دار، درصد دوم فقط به فروش مازاد تعلق می‌گیرد.</p></section><section class="card"><h2>تنظیمات فاکتور</h2><div class="form-grid"><label>واحد پول<select name="currency"><option value="rial" <?=($s['currency']??'')==='rial'?'selected':''?>>ریال</option><option value="toman" <?=($s['currency']??'')==='toman'?'selected':''?>>تومان</option></select></label><label class="wide">متن پانوشت فاکتور<input name="invoice_footer" value="<?=e($s['invoice_footer']??'')?>"></label></div></section><button>ذخیره تنظیمات</button></form>
    <?php return; }
    if ($page==='commissions') { require_permission('commissions.view'); ?>
      <h1>پورسانت کاربران</h1><p class="muted">محاسبه بر اساس فاکتورهای ثبت‌شده توسط هر کاربر و تاریخ میلادی ذخیره‌شده؛ این گزارش تسویه بانکی نیست.</p>
      <?php $commissionSettings=query('SELECT setting_key,setting_value FROM app_settings WHERE company_id=?',[$tenant])->fetchAll(PDO::FETCH_KEY_PAIR); if(($commissionSettings['commission_enabled']??'0')!=='1'): ?><div class="alert error">سیستم پورسانت غیرفعال است. مدیر شرکت می‌تواند آن را از بخش «تنظیمات شرکت» فعال کند.</div><?php endif; ?>
      <?php if(in_array(user()['role'],['superadmin','company_admin'],true)): ?><div class="card"><h2>محاسبه پورسانت</h2><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="calculate"><label>کاربر<select name="user_id"><?php foreach(query('SELECT id,full_name FROM users WHERE company_id=? AND is_active=1',[$tenant])->fetchAll() as $u): ?><option value="<?=e($u['id'])?>"><?=e($u['full_name'])?></option><?php endforeach; ?></select></label><label>نوع دوره<select name="period_type"><option value="daily">روزانه</option><option value="monthly">ماهانه</option></select></label><label>دوره شمسی<span class="jalali-field"><input type="hidden" name="period_key" data-jalali-iso data-jalali-output="jalali" required><button type="button" class="jalali-picker-button secondary" data-jalali-open>انتخاب تاریخ</button><button type="button" class="jalali-clear secondary" data-jalali-clear>×</button></span></label><label>درصد پورسانت<input name="rate" type="number" min="0" max="100" step="0.001" required></label><button>محاسبه و ثبت</button></form></div><?php endif; ?>
      <div class="card"><h2>سوابق پورسانت</h2><?php render_table(['کاربر','دوره','تاریخ','جمع فروش','نرخ','پورسانت'],query('SELECT u.full_name,c.period_type,c.period_key,c.sales_total,c.rate,c.amount FROM commissions c JOIN users u ON u.id=c.user_id AND u.company_id=c.company_id WHERE c.company_id=?'.(document_scope()==='own'?' AND c.user_id=?':'').' ORDER BY c.id DESC LIMIT 100',document_scope()==='own'?[$tenant,user()['id']]:[$tenant])->fetchAll(),['sales_total','amount']); ?></div>
    <?php return; }
    if ($page==='activity') { require_permission('activity.view'); ?>
      <h1>گزارش فعالیت کاربران</h1><p class="muted">بررسی فعالیت‌ها در شرکت جاری با فیلتر زمانی و کاربر</p><?php try {$filter=report_filters();}catch(RuntimeException $ex){$filter=report_filters_default();} render_report_filters('activity',$filter,false); ?><div class="card"><h2>آخرین فعالیت‌ها (۲۰۰ مورد)</h2><?php render_table(['تاریخ و ساعت','کاربر','عملیات','توضیحات'],activity_report($tenant,$filter)); ?></div>
    <?php }
}

function render_table(array $headings, array $rows, array $moneyColumns=[]): void {
    echo '<div class="table-wrap"><table><thead><tr>';
    foreach ($headings as $h) echo '<th>'.e($h).'</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) { echo '<tr>'; foreach ($row as $key=>$value) echo '<td>'.(in_array($key,$moneyColumns,true)?'<span class="money-value">'.e(money_toman($value)).'</span>':e(ui_value($value,(string)$key))).'</td>'; echo '</tr>'; }
    if (!$rows) echo '<tr><td colspan="'.count($headings).'" class="empty-state">هنوز موردی ثبت نشده است.</td></tr>';
    echo '</tbody></table></div>';
}
