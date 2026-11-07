<?php
declare(strict_types=1);

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
        if ($title==='' || !in_array($paper,['thermal80','a5'],true)) throw new RuntimeException('عنوان یا اندازه کاغذ نامعتبر است.');
        query('INSERT INTO print_templates(company_id,title,paper_type,header_text,footer_text,is_default) VALUES(?,?,?,?,?,0)',[$tenant,$title,$paper,trim((string)($_POST['header_text']??'')),trim((string)($_POST['footer_text']??''))]);
        audit('template.create',$title); redirect('templates','قالب چاپ ثبت شد.');
    }
    if ($page === 'settings' && $action === 'save') {
        require_admin();
        foreach (['company_phone','company_address','invoice_footer','currency'] as $key) {
            $value=trim((string)($_POST[$key]??''));
            if (mb_strlen($value)>1000) throw new RuntimeException('متن تنظیمات بیش از حد طولانی است.');
            query('INSERT INTO app_settings(company_id,setting_key,setting_value) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)',[$tenant,$key,$value]);
        }
        audit('settings.update'); redirect('settings','تنظیمات ذخیره شد.');
    }
    if ($page === 'profile' && $action === 'password') {
        $old=(string)($_POST['current_password']??''); $new=(string)($_POST['new_password']??'');
        $hash=query('SELECT password_hash FROM users WHERE id=?',[user()['id']])->fetchColumn();
        if (!password_verify($old,(string)$hash) || strlen($new)<12 || $old===$new) throw new RuntimeException('رمز فعلی نادرست است یا رمز جدید حداقل ۱۲ کاراکتر و متفاوت نیست.');
        query('UPDATE users SET password_hash=? WHERE id=?',[password_hash($new,PASSWORD_DEFAULT),user()['id']]);
        audit('password.change'); session_regenerate_id(true); redirect('profile','رمز عبور تغییر کرد.');
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
        $amount=(int)round($total*(float)$rate/100);
        query('INSERT INTO commissions(company_id,user_id,period_type,period_key,sales_total,rate,amount) VALUES(?,?,?,?,?,?,?)',[$tenant,$userId,$period,$date,$total,$rate,$amount]);
        audit('commission.calculate',$date); redirect('commissions','پورسانت محاسبه و ثبت شد.');
    }
}

function render_module(string $page, int $tenant): void {
    if ($page==='profile') { ?>
      <h1>امنیت حساب کاربری</h1><div class="card"><h2>تغییر رمز عبور</h2><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="password"><label>رمز فعلی<input type="password" name="current_password" autocomplete="current-password" required></label><label>رمز جدید (حداقل ۱۲ کاراکتر)<input type="password" name="new_password" minlength="12" autocomplete="new-password" required></label><button>ذخیره رمز جدید</button></form></div>
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
      <h1>قالب‌های چاپ</h1><div class="card"><h2>قالب جدید</h2><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="add"><label>عنوان<input name="title" required></label><label>سایز<select name="paper_type"><option value="thermal80">حرارتی ۸۰ میلی‌متری</option><option value="a5">A5</option></select></label><label>متن سربرگ<input name="header_text"></label><label>متن پانوشت<input name="footer_text"></label><button>ثبت قالب</button></form></div>
      <div class="card"><h2>قالب‌های موجود</h2><?php render_table(['عنوان','سایز','پیش‌فرض'],query('SELECT title,paper_type,is_default FROM print_templates WHERE company_id=? ORDER BY id DESC',[$tenant])->fetchAll()); ?></div>
    <?php return; }
    if ($page==='settings') { require_admin(); $s=query('SELECT setting_key,setting_value FROM app_settings WHERE company_id=?',[$tenant])->fetchAll(PDO::FETCH_KEY_PAIR); ?>
      <h1>تنظیمات شرکت</h1><div class="card"><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="save"><label>تلفن شرکت<input name="company_phone" value="<?=e($s['company_phone']??'')?>"></label><label>نشانی شرکت<input name="company_address" value="<?=e($s['company_address']??'')?>"></label><label>واحد پول<select name="currency"><option value="rial" <?=($s['currency']??'')==='rial'?'selected':''?>>ریال</option><option value="toman" <?=($s['currency']??'')==='toman'?'selected':''?>>تومان</option></select></label><label>پانوشت فاکتور<input name="invoice_footer" value="<?=e($s['invoice_footer']??'')?>"></label><button>ذخیره تنظیمات</button></form></div>
    <?php return; }
    if ($page==='commissions') { require_permission('commissions.view'); ?>
      <h1>پورسانت کاربران</h1><p class="muted">محاسبه بر اساس فاکتورهای ثبت‌شده توسط هر کاربر و تاریخ میلادی ذخیره‌شده؛ این گزارش تسویه بانکی نیست.</p>
      <?php if(in_array(user()['role'],['superadmin','company_admin'],true)): ?><div class="card"><h2>محاسبه پورسانت</h2><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="calculate"><label>کاربر<select name="user_id"><?php foreach(query('SELECT id,full_name FROM users WHERE company_id=? AND is_active=1',[$tenant])->fetchAll() as $u): ?><option value="<?=e($u['id'])?>"><?=e($u['full_name'])?></option><?php endforeach; ?></select></label><label>نوع دوره<select name="period_type"><option value="daily">روزانه</option><option value="monthly">ماهانه</option></select></label><label>تاریخ دوره (میلادی)<input name="period_key" placeholder="2026-11-07" required></label><label>درصد پورسانت<input name="rate" type="number" min="0" max="100" step="0.001" required></label><button>محاسبه و ثبت</button></form></div><?php endif; ?>
      <div class="card"><h2>سوابق پورسانت</h2><?php render_table(['کاربر','دوره','تاریخ','جمع فروش','نرخ','پورسانت'],query('SELECT u.full_name,c.period_type,c.period_key,c.sales_total,c.rate,c.amount FROM commissions c JOIN users u ON u.id=c.user_id WHERE c.company_id=? ORDER BY c.id DESC LIMIT 100',[$tenant])->fetchAll(),['sales_total','amount']); ?></div>
    <?php return; }
    if ($page==='activity') { require_permission('activity.view'); ?>
      <h1>گزارش فعالیت‌ها</h1><div class="card"><?php render_table(['تاریخ','کاربر','عملیات','جزئیات'],query('SELECT l.created_at,COALESCE(u.full_name,\'—\') name,l.action,l.details FROM activity_logs l LEFT JOIN users u ON u.id=l.user_id WHERE l.company_id=? ORDER BY l.id DESC LIMIT 100',[$tenant])->fetchAll()); ?></div>
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
