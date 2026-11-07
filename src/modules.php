<?php
declare(strict_types=1);

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

function register_missing_driver(int $tenant, string $nationalCode, array $fields): int {
    if (!preg_match('/^[0-9]{10}$/',$nationalCode)) throw new RuntimeException('کد ملی راننده باید ده‌رقمی باشد.');
    $existing=query('SELECT id FROM global_drivers WHERE national_code=?',[$nationalCode])->fetchColumn();
    if (!$existing) {
        $first=trim((string)($fields['first_name']??''));
        $last=trim((string)($fields['last_name']??''));
        $mobile=trim((string)($fields['mobile']??''));
        $line=trim((string)($fields['line_area']??''));
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

function handle_module_post(string $page, string $action): void {
    $tenant = $page==='profile' ? 0 : tenant_id();
    if ($page === 'bills' && $action === 'add') {
        require_permission('bills.create');
        $type=(string)($_POST['bill_type']??'');
        $method=(string)($_POST['paid_method']??'');
        $amount=filter_var($_POST['amount']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $bill=trim((string)($_POST['bill_id']??'')); $payment=trim((string)($_POST['payment_id']??''));
        if (!in_array($type,['abonman','citizen','other'],true) || !in_array($method,['manual','pos'],true) || !$amount || $bill==='' || $payment==='') throw new RuntimeException('مشخصات قبض یا مبلغ نامعتبر است.');
        query('INSERT INTO bill_payments(company_id,user_id,bill_type,bill_id,payment_id,amount,ref_code,response_code,national_code,driver_name,paid_method) VALUES(?,?,?,?,?,?,?,?,?,?,?)',[
            $tenant,user()['id'],$type,$bill,$payment,$amount,trim((string)($_POST['ref_code']??'')),null,trim((string)($_POST['national_code']??'')),trim((string)($_POST['driver_name']??'')),$method
        ]);
        audit('bill.create',$bill); redirect('bills','قبض به‌صورت دستی ثبت شد؛ تأیید بانکی انجام نشده است.');
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
        if ($title==='' || mb_strlen($title)>160 || !in_array($paper,['thermal80','a5'],true) || !in_array($font,['Tahoma','Vazirmatn'],true)) throw new RuntimeException('عنوان، قلم یا اندازه کاغذ نامعتبر است.');
        $header=trim((string)($_POST['header_text']??'')); $footer=trim((string)($_POST['footer_text']??''));
        if (mb_strlen($header)>1000 || mb_strlen($footer)>1000) throw new RuntimeException('متن سربرگ یا پانوشت طولانی است.');
        query('INSERT INTO print_templates(company_id,title,paper_type,header_text,footer_text,layout_json,is_default) VALUES(?,?,?,?,?,?,0)',[$tenant,$title,$paper,$header,$footer,json_encode(['font'=>$font],JSON_THROW_ON_ERROR)]);
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
        foreach (['company_name','company_phone','company_website','company_address','invoice_footer','currency','commission_enabled','commission_type','commission_period','commission_rate','commission_limit','commission_over_limit_rate'] as $key) {
            $value=trim((string)($post[$key]??''));
            if (mb_strlen($value)>1000) throw new RuntimeException('متن تنظیمات بیش از حد طولانی است.');
            query('INSERT INTO app_settings(company_id,setting_key,setting_value) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',[$tenant,$key,$value]);
        }
        if (trim((string)($_POST['company_name']??''))!=='') query('UPDATE companies SET name=? WHERE id=?',[trim((string)$_POST['company_name']),$tenant]);
        audit('settings.update'); redirect('settings','تنظیمات ذخیره شد.');
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
        $date=(string)($_POST['period_key']??'');
        $period=(string)($_POST['period_type']??'');
        if (!$userId || $rate===false || $rate<0 || $rate>100 || !in_array($period,['daily','monthly'],true) || !preg_match($period==='daily'?'/^\d{4}-\d{2}-\d{2}$/':'/^\d{4}-\d{2}$/',$date)) throw new RuntimeException('دوره، نرخ یا کاربر نامعتبر است.');
        if (!query('SELECT id FROM users WHERE id=? AND company_id=? AND is_active=1',[$userId,$tenant])->fetch()) throw new RuntimeException('کاربر متعلق به شرکت نیست.');
        $from=$period==='daily'?$date:$date.'-01';
        $to=$period==='daily'?(new DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d'):(new DateTimeImmutable($from))->modify('+1 month')->format('Y-m-d');
        $total=(int)query('SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE company_id=? AND user_id=? AND created_at>=? AND created_at<?',[$tenant,$userId,$from,$to])->fetchColumn();
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
      <h1>پروفایل و امنیت حساب</h1><div class="card profile-card"><div class="profile-identity"><span class="avatar avatar-large"><?php if(user_photo_table_ready() && query('SELECT 1 FROM user_photos WHERE user_id=?',[user()['id']])->fetchColumn()): ?><img src="?page=user_photo&amp;id=<?=e(user()['id'])?>" alt="تصویر کاربر"><?php else: ?><?=e(mb_substr(user()['full_name'],0,1))?><?php endif; ?></span><div><h2><?=e(user()['full_name'])?></h2><p class="muted">عکس پرسنلی حساب کاربری؛ به‌صورت مربعی و بهینه ذخیره می‌شود.</p></div></div><?php if(user_photo_table_ready()): ?><form method="post" enctype="multipart/form-data" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="photo"><label>انتخاب تصویر کاربر (JPG، PNG یا WebP)<input name="photo" type="file" accept="image/jpeg,image/png,image/webp" required></label><button>ذخیره تصویر</button></form><?php else: ?><div class="alert error">برای فعال‌شدن عکس پروفایل، مدیر هاست باید میگرشن ۰۰۵ را در phpMyAdmin اجرا کند.</div><?php endif; ?></div><div class="card"><h2>تغییر رمز عبور</h2><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="password"><label>رمز فعلی<input type="password" name="current_password" autocomplete="current-password" required></label><label>رمز جدید (حداقل ۱۲ کاراکتر)<input type="password" name="new_password" minlength="12" autocomplete="new-password" required></label><button>ذخیره رمز جدید</button></form></div>
    <?php return; }
    if ($page==='bills') { require_permission('bills.view'); ?>
      <h1>پرداخت قبوض</h1><p class="muted">ثبت اطلاعات قبض به‌صورت دستی؛ تراکنش بانکی از این صفحه انجام نمی‌شود.</p>
      <?php if(can('bills.create')): ?><div class="card"><h2>ثبت قبض</h2><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="add"><label>نوع قبض<select name="bill_type"><option value="abonman">آبونمان</option><option value="citizen">شهروندی</option><option value="other">سایر</option></select></label><label>شناسه قبض<input name="bill_id" required></label><label>شناسه پرداخت<input name="payment_id" required></label><label>مبلغ<input type="number" name="amount" min="1" required></label><label>روش ثبت<select name="paid_method"><option value="manual">ثبت دستی</option><option value="pos">کارتخوان (شماره پیگیری دستی)</option></select></label><label>شماره پیگیری<input name="ref_code"></label><label>کد ملی<input name="national_code"></label><label>نام راننده<input name="driver_name"></label><button>ثبت قبض</button></form></div><?php endif; ?>
      <div class="card"><h2>سوابق قبوض</h2><?php render_table(['تاریخ','نوع','شناسه قبض','مبلغ','روش','پیگیری'],query('SELECT created_at,bill_type,bill_id,amount,paid_method,ref_code FROM bill_payments WHERE company_id=? ORDER BY id DESC LIMIT 100',[$tenant])->fetchAll(),['amount']); ?></div>
    <?php return; }
    if ($page==='pos') { require_permission('pos.view'); ?>
      <h1>مدیریت کارتخوان‌ها</h1><p class="muted">تعریف دستگاه برای استفاده در اتصال آینده؛ پرداخت آنلاین از سرور لینوکسی انجام نمی‌شود.</p>
      <?php if(can('users.manage')): ?><div class="card"><h2>افزودن کارتخوان</h2><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="add"><label>برند<input name="brand" required></label><label>IP دستگاه<input name="ip_address" placeholder="192.168.1.10"></label><label>پورت<input name="port" type="number" min="1" max="65535" required></label><button>ثبت دستگاه</button></form></div><?php endif; ?>
      <div class="card"><h2>دستگاه‌ها</h2><?php render_table(['برند','IP','پورت','فعال'],query('SELECT brand,ip_address,port,is_active FROM pos_settings WHERE company_id=? ORDER BY id DESC',[$tenant])->fetchAll()); ?></div>
    <?php return; }
    if ($page==='templates') { require_admin(); ?>
      <h1>طراحی قالب چاپ فاکتور</h1><p class="muted">تنظیم مشخصات قالب و مشاهده نمونه چاپ؛ برای استفاده در فاکتور، قالب موردنظر را پیش‌فرض کنید.</p>
      <div class="template-designer"><div class="card"><h2>قالب جدید</h2><form method="post" class="stack" id="templateEditor"><?=csrf_field()?><input type="hidden" name="action" value="add"><label>عنوان قالب<input name="title" required maxlength="160" placeholder="قالب پیش‌فرض شرکت"></label><label>نوع کاغذ<select name="paper_type"><option value="thermal80">حرارتی ۸ سانتی‌متر</option><option value="a5">A5</option></select></label><label>قلم<select name="font_family"><option value="Tahoma">Tahoma</option><option value="Vazirmatn">وزیرمتن</option></select></label><label>متن سربرگ<input name="header_text" maxlength="1000" placeholder="فاکتور خدمات"></label><label>متن پانوشت<input name="footer_text" maxlength="1000" placeholder="با تشکر از مراجعه شما"></label><button>ذخیره قالب جدید</button></form></div><div class="card"><h2>پیش‌نمایش فاکتور</h2><div class="invoice-paper" id="templatePreview"><h3 data-preview-header>فاکتور خدمات</h3><p>شماره فاکتور: ۱۴۰۵۰۸۱۶-۰۰۱</p><p>تاریخ: ۱۴۰۵/۰۸/۱۶</p><p>نام مشتری: نمونه نمایشی</p><p>کد ملی: ۰۰۰۰۰۰۰۰۰۰</p><table><thead><tr><th>شرح خدمت</th><th>مبلغ</th></tr></thead><tbody><tr><td>خدمت نمونه</td><td>۱۰۰٬۰۰۰ تومان</td></tr></tbody></table><h3>مبلغ کل: ۱۰۰٬۰۰۰ تومان</h3><p data-preview-footer>با تشکر از مراجعه شما</p></div></div></div>
      <div class="card"><h2>قالب‌های ذخیره‌شده</h2><div class="table-wrap"><table><thead><tr><th>عنوان</th><th>کاغذ</th><th>پیش‌فرض</th><th>عملیات</th></tr></thead><tbody><?php foreach(query('SELECT id,title,paper_type,is_default FROM print_templates WHERE company_id=? ORDER BY id DESC',[$tenant])->fetchAll() as $t): ?><tr><td><?=e($t['title'])?></td><td><?=e($t['paper_type'])?></td><td><?=$t['is_default']?'بله':'خیر'?></td><td><?php if(!$t['is_default']): ?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="default"><input type="hidden" name="template_id" value="<?=e($t['id'])?>"><button class="small">انتخاب پیش‌فرض</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
    <?php return; }
    if ($page==='settings') { require_admin(); $s=query('SELECT setting_key,setting_value FROM app_settings WHERE company_id=?',[$tenant])->fetchAll(PDO::FETCH_KEY_PAIR); ?>
      <h1>اطلاعات شرکت و تنظیمات پورسانت</h1><form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="action" value="save"><section class="card"><h2>اطلاعات شرکت</h2><div class="form-grid"><label>نام شرکت<input name="company_name" maxlength="180" value="<?=e(query('SELECT name FROM companies WHERE id=?',[$tenant])->fetchColumn())?>" required></label><label>تلفن شرکت<input name="company_phone" value="<?=e($s['company_phone']??'')?>"></label><label>وب‌سایت<input name="company_website" type="url" placeholder="https://example.com" value="<?=e($s['company_website']??'')?>"></label><label class="wide">آدرس شرکت<input name="company_address" value="<?=e($s['company_address']??'')?>"></label></div></section><section class="card"><h2>تنظیمات پورسانت</h2><label class="choice"><input type="checkbox" name="commission_enabled" value="1" <?=($s['commission_enabled']??'0')==='1'?'checked':''?>>سیستم پورسانت فعال باشد</label><div class="form-grid"><label>نوع پورسانت<select name="commission_type"><option value="fixed" <?=($s['commission_type']??'fixed')==='fixed'?'selected':''?>>درصد ثابت</option><option value="threshold" <?=($s['commission_type']??'')==='threshold'?'selected':''?>>درصد پس از سقف</option></select></label><label>درصد پایه<input name="commission_rate" type="number" min="0" max="100" step="0.001" value="<?=e($s['commission_rate']??'0')?>"></label><label>دوره محاسبه<select name="commission_period"><option value="monthly" <?=($s['commission_period']??'monthly')==='monthly'?'selected':''?>>ماهانه</option><option value="daily" <?=($s['commission_period']??'')==='daily'?'selected':''?>>روزانه</option></select></label><label>سقف مبلغ<input name="commission_limit" type="number" min="0" step="1" value="<?=e($s['commission_limit']??'0')?>"></label><label>درصد پس از سقف<input name="commission_over_limit_rate" type="number" min="0" max="100" step="0.001" value="<?=e($s['commission_over_limit_rate']??'0')?>"></label></div><p class="muted">در حالت ثابت، درصد پایه به کل فروش تعلق می‌گیرد. در حالت سقف‌دار، درصد دوم فقط به فروش مازاد تعلق می‌گیرد.</p></section><section class="card"><h2>تنظیمات فاکتور</h2><div class="form-grid"><label>واحد پول<select name="currency"><option value="rial" <?=($s['currency']??'')==='rial'?'selected':''?>>ریال</option><option value="toman" <?=($s['currency']??'')==='toman'?'selected':''?>>تومان</option></select></label><label class="wide">متن پانوشت فاکتور<input name="invoice_footer" value="<?=e($s['invoice_footer']??'')?>"></label></div></section><button>ذخیره تنظیمات</button></form>
    <?php return; }
    if ($page==='commissions') { require_permission('commissions.view'); ?>
      <h1>پورسانت کاربران</h1><p class="muted">محاسبه بر اساس فاکتورهای ثبت‌شده توسط هر کاربر و تاریخ میلادی ذخیره‌شده؛ این گزارش تسویه بانکی نیست.</p>
      <?php $commissionSettings=query('SELECT setting_key,setting_value FROM app_settings WHERE company_id=?',[$tenant])->fetchAll(PDO::FETCH_KEY_PAIR); if(($commissionSettings['commission_enabled']??'0')!=='1'): ?><div class="alert error">سیستم پورسانت غیرفعال است. مدیر شرکت می‌تواند آن را از بخش «تنظیمات شرکت» فعال کند.</div><?php endif; ?>
      <?php if(in_array(user()['role'],['superadmin','company_admin'],true)): ?><div class="card"><h2>محاسبه پورسانت</h2><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="calculate"><label>کاربر<select name="user_id"><?php foreach(query('SELECT id,full_name FROM users WHERE company_id=? AND is_active=1',[$tenant])->fetchAll() as $u): ?><option value="<?=e($u['id'])?>"><?=e($u['full_name'])?></option><?php endforeach; ?></select></label><label>نوع دوره<select name="period_type"><option value="daily">روزانه</option><option value="monthly">ماهانه</option></select></label><label>تاریخ دوره (میلادی)<input name="period_key" placeholder="2026-11-07" required></label><label>درصد پورسانت<input name="rate" type="number" min="0" max="100" step="0.001" required></label><button>محاسبه و ثبت</button></form></div><?php endif; ?>
      <div class="card"><h2>سوابق پورسانت</h2><?php render_table(['کاربر','دوره','تاریخ','جمع فروش','نرخ','پورسانت'],query('SELECT u.full_name,c.period_type,c.period_key,c.sales_total,c.rate,c.amount FROM commissions c JOIN users u ON u.id=c.user_id WHERE c.company_id=? ORDER BY c.id DESC LIMIT 100',[$tenant])->fetchAll(),['sales_total','amount']); ?></div>
    <?php return; }
    if ($page==='activity') { require_permission('activity.view'); ?>
      <h1>گزارش فعالیت کاربران</h1><p class="muted">بررسی فعالیت‌ها در شرکت جاری با فیلتر زمانی و کاربر</p><?php try {$filter=report_filters();}catch(RuntimeException $ex){$filter=report_filters_default();} render_report_filters('activity',$filter,false); ?><div class="card"><h2>آخرین فعالیت‌ها (۲۰۰ مورد)</h2><?php render_table(['تاریخ و ساعت','کاربر','عملیات','توضیحات'],activity_report($tenant,$filter)); ?></div>
    <?php }
}

function render_table(array $headings, array $rows, array $moneyColumns=[]): void {
    echo '<div class="table-wrap"><table><thead><tr>';
    foreach ($headings as $h) echo '<th>'.e($h).'</th>';
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) { echo '<tr>'; foreach ($row as $key=>$value) echo '<td>'.(in_array($key,$moneyColumns,true)?money($value):e($value)).'</td>'; echo '</tr>'; }
    if (!$rows) echo '<tr><td colspan="'.count($headings).'" class="empty-state">هنوز موردی ثبت نشده است.</td></tr>';
    echo '</tbody></table></div>';
}
