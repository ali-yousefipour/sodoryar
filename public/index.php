<?php
declare(strict_types=1);
require __DIR__ . '/_private/src/bootstrap.php';
require __DIR__ . '/_private/src/modules.php';
require __DIR__ . '/_private/src/photos.php';
require __DIR__ . '/_private/src/reporting.php';

$page = (string) ($_GET['page'] ?? 'dashboard');
$valid = ['login','logout','dashboard','companies','users','customers','services','invoices','reports','receipt','commissions','templates','settings','activity','profile','driver_lookup','user_photo'];
if (!in_array($page, $valid, true)) { http_response_code(404); exit('صفحه پیدا نشد'); }
if ($page==='user_photo') {
    if ($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405); exit; }
    serve_profile_photo((int)($_GET['id']??0));
}
if ($page==='driver_lookup') {
    require_login(); tenant_id();
    if (!can('customers.view') && !can('invoices.create')) { http_response_code(403); exit; }
    header('Content-Type: application/json; charset=utf-8');
    if ($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405); echo json_encode(['error'=>'روش درخواست مجاز نیست.'],JSON_UNESCAPED_UNICODE); exit; }
    // Per-session throttling reduces bulk national-code enumeration without storing extra PII.
    $window=(int)($_SESSION['driver_lookup_window']??0);
    if (time()-$window>=60) { $_SESSION['driver_lookup_window']=time(); $_SESSION['driver_lookup_count']=0; }
    if ((int)($_SESSION['driver_lookup_count']??0)>=60) { http_response_code(429); header('Retry-After: 60'); echo json_encode(['error'=>'تعداد جست‌وجو زیاد است؛ کمی بعد دوباره تلاش کنید.'],JSON_UNESCAPED_UNICODE); exit; }
    $_SESSION['driver_lookup_count']=(int)($_SESSION['driver_lookup_count']??0)+1;
    $code=trim((string)($_GET['national_code']??''));
    if (!preg_match('/^[0-9]{10}$/',$code)) { http_response_code(422); echo json_encode(['error'=>'کد ملی ده‌رقمی وارد کنید.'],JSON_UNESCAPED_UNICODE); exit; }
    $found=query('SELECT national_code,first_name,last_name,line_area,vehicle_plate FROM global_drivers WHERE national_code=?',[$code])->fetch();
    if (!$found) { http_response_code(404); echo json_encode(['error'=>'تاکسیران پیدا نشد.'],JSON_UNESCAPED_UNICODE); exit; }
    echo json_encode(['driver'=>$found],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); exit;
}

try {
    if ($page === 'logout') {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
        verify_csrf(); $_SESSION = []; session_regenerate_id(true); redirect('login');
    }
    if ($page === 'login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
        verify_csrf();
        $login = trim((string) ($_POST['username'] ?? ''));
        $attemptKey = login_attempt_key($login);
        if (login_is_limited($attemptKey)) {
            $error = 'تعداد تلاش‌های ناموفق زیاد است. ۱۵ دقیقه دیگر تلاش کنید.';
        } else {
        $u = query('SELECT u.*, c.is_active AS company_active FROM users u LEFT JOIN companies c ON c.id=u.company_id WHERE u.username=? LIMIT 1', [$login])->fetch();
        if ($u && (int) $u['is_active'] === 1 && ($u['role'] === 'superadmin' || (int) $u['company_active'] === 1) && password_verify((string) ($_POST['password'] ?? ''), $u['password_hash'])) {
            login_succeeded($attemptKey);
            session_regenerate_id(true);
            $_SESSION['user'] = ['id'=>(int)$u['id'],'full_name'=>$u['full_name'],'role'=>$u['role'],'company_id'=>$u['company_id']];
            unset($_SESSION['selected_company']); audit('login'); redirect('dashboard');
        }
        login_failed($attemptKey);
        $error = 'نام کاربری یا رمز عبور نادرست است.';
        }
    }
    if ($page !== 'login') require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page !== 'login') {
        verify_csrf();
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'select_company' && is_super()) {
            $id = filter_var($_POST['company_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || !query('SELECT id FROM companies WHERE id=? AND is_active=1',[$id])->fetch()) throw new RuntimeException('شرکت معتبر نیست.');
            $_SESSION['selected_company'] = (int)$id; redirect('dashboard');
        }
        if ($page === 'companies' && is_super() && $action === 'add') {
            $name = trim((string)($_POST['name'] ?? ''));
            $slug = strtolower(trim((string)($_POST['slug'] ?? '')));
            $manager = trim((string)($_POST['manager_username'] ?? ''));
            $managerName = trim((string)($_POST['manager_name'] ?? ''));
            $managerPass = (string)($_POST['manager_password'] ?? '');
            if (mb_strlen($name)<2 || !preg_match('/^[a-z0-9-]{3,80}$/',$slug) || !preg_match('/^[a-zA-Z0-9_.-]{3,100}$/',$manager) || mb_strlen($managerName)<2 || strlen($managerPass)<12) throw new RuntimeException('اطلاعات شرکت یا مدیر اولیه نامعتبر است (رمز حداقل ۱۲ کاراکتر).');
            db()->beginTransaction();
            try {
                query('INSERT INTO companies(name,slug) VALUES(?,?)',[$name,$slug]);
                $companyId=(int)db()->lastInsertId();
                query('INSERT INTO users(company_id,username,password_hash,full_name,role) VALUES(?,?,?,?,?)',[$companyId,$manager,password_hash($managerPass,PASSWORD_DEFAULT),$managerName,'company_admin']);
                audit('company.create',$slug,$companyId);
                db()->commit();
            } catch(Throwable $ex) { db()->rollBack(); throw $ex; }
            redirect('companies','شرکت و حساب مدیر آن ایجاد شدند.');
        }
        if ($page === 'users' && $action === 'add') {
            require_admin(); $tenant = tenant_id();
            $username = trim((string)($_POST['username'] ?? ''));
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $role = (string)($_POST['role'] ?? 'operator');
            if (!preg_match('/^[a-zA-Z0-9_.-]{3,100}$/',$username) || mb_strlen($fullName)<2 || strlen($password)<12 || !in_array($role,['operator','company_admin'],true)) throw new RuntimeException('اطلاعات کاربر نامعتبر است؛ رمز حداقل ۱۲ کاراکتر باشد.');
            $newPermissions=array_unique((array)($_POST['permissions']??[]));
            foreach($newPermissions as $permission) if (!is_string($permission) || !array_key_exists($permission,permission_catalog())) throw new RuntimeException('مجوز نامعتبر است.');
            db()->beginTransaction();
            try {
            query('INSERT INTO users(company_id,username,password_hash,full_name,role) VALUES(?,?,?,?,?)',[$tenant,$username,password_hash($password,PASSWORD_DEFAULT),$fullName,$role]);
            $newUserId=(int)db()->lastInsertId();
            if ($role==='operator') foreach($newPermissions as $permission) {
                query('INSERT INTO user_permissions(user_id,permission_key) VALUES(?,?)',[$newUserId,$permission]);
            }
            audit('user.create',$username); db()->commit();
            } catch(Throwable $ex) { db()->rollBack(); throw $ex; }
            redirect('users','کاربر ثبت شد.');
        }
        if ($page === 'users' && $action === 'permissions') {
            require_admin(); $tenant=tenant_id();
            $id=filter_var($_POST['user_id']??null,FILTER_VALIDATE_INT);
            $target=$id?query('SELECT id,role FROM users WHERE id=? AND company_id=?',[$id,$tenant])->fetch():false;
            if (!$target || $target['role']!=='operator') throw new RuntimeException('اپراتور مورد نظر معتبر نیست.');
            $permissions=array_unique((array)($_POST['permissions']??[]));
            foreach($permissions as $permission) if (!is_string($permission) || !array_key_exists($permission,permission_catalog())) throw new RuntimeException('مجوز نامعتبر است.');
            db()->beginTransaction();
            try {
                query('DELETE FROM user_permissions WHERE user_id=?',[$id]);
                foreach($permissions as $permission) query('INSERT INTO user_permissions(user_id,permission_key) VALUES(?,?)',[$id,$permission]);
                audit('user.permissions.update',(string)$id); db()->commit();
            } catch(Throwable $ex) { db()->rollBack(); throw $ex; }
            redirect('users','دسترسی‌های اپراتور ذخیره شد.');
        }
        if ($page === 'users' && $action === 'photo') {
            require_admin();
            $id=filter_var($_POST['user_id']??null,FILTER_VALIDATE_INT);
            if (!$id) throw new RuntimeException('کاربر انتخاب‌شده معتبر نیست.');
            save_profile_photo((int)$id);
            redirect('users','تصویر کاربر ذخیره شد.');
        }
        if ($page === 'users' && $action === 'edit') {
            require_admin(); $tenant=tenant_id();
            $id=filter_var($_POST['user_id']??null,FILTER_VALIDATE_INT);
            $target=$id?query('SELECT id,role,is_active FROM users WHERE id=? AND company_id=?',[$id,$tenant])->fetch():false;
            $fullName=trim((string)($_POST['full_name']??''));
            $username=trim((string)($_POST['username']??''));
            $active=isset($_POST['is_active'])?1:0;
            if (!$target || mb_strlen($fullName)<2 || mb_strlen($fullName)>180 || !preg_match('/^[a-zA-Z0-9_.-]{3,100}$/',$username)) throw new RuntimeException('اطلاعات کاربر معتبر نیست.');
            if ((int)$id===(int)user()['id'] && !$active) throw new RuntimeException('نمی‌توانید حساب خود را غیرفعال کنید.');
            if ($target['role']==='company_admin' && !$active && (int)query('SELECT COUNT(*) FROM users WHERE company_id=? AND role=? AND is_active=1',[$tenant,'company_admin'])->fetchColumn()<=1) throw new RuntimeException('آخرین مدیر فعال شرکت را نمی‌توان غیرفعال کرد.');
            query('UPDATE users SET full_name=?,username=?,is_active=? WHERE id=? AND company_id=?',[$fullName,$username,$active,$id,$tenant]);
            audit('user.update',(string)$id); redirect('users','اطلاعات کاربر به‌روزرسانی شد.');
        }
        if ($page === 'customers' && $action === 'add') {
            require_permission('customers.create');
            $tenant = tenant_id(); $code = trim((string)($_POST['national_code'] ?? ''));
            $first = trim((string)($_POST['first_name'] ?? '')); $last = trim((string)($_POST['last_name'] ?? ''));
            if (!preg_match('/^[0-9]{10}$/',$code) || $first==='' || $last==='' || mb_strlen($first)>100 || mb_strlen($last)>100) throw new RuntimeException('کد ملی ده‌رقمی و نام و نام خانوادگی معتبر الزامی هستند.');
            db()->beginTransaction();
            try {
                register_missing_driver($tenant,$code,['first_name'=>$first,'last_name'=>$last,'mobile'=>$_POST['mobile']??'','line_area'=>$_POST['line_area']??'','line_code'=>$_POST['line_code']??'']);
                audit('customer.create',$code);
                db()->commit();
            } catch(Throwable $ex) { db()->rollBack(); throw $ex; }
            redirect('customers','تاکسیران در بانک مرکزی و پرونده شرکت ثبت شد.');
        }
        if ($page==='customers' && $action==='link_global') {
            require_permission('customers.create');
            $national=trim((string)($_POST['national_code']??''));
            attach_global_driver(tenant_id(),$national);
            audit('customer.link_global',$national); redirect('customers','تاکسیران به پرونده این شرکت اضافه شد.');
        }
        if ($page === 'services' && $action === 'add') {
            require_admin(); $title = trim((string)($_POST['title'] ?? ''));
            $price = filter_var($_POST['price'] ?? null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
            if ($title==='' || mb_strlen($title)>200 || $price===false) throw new RuntimeException('عنوان و مبلغ معتبر وارد کنید.');
            query('INSERT INTO services(company_id,title,price) VALUES(?,?,?)',[tenant_id(),$title,$price]);
            audit('service.create',$title); redirect('services','خدمت ثبت شد.');
        }
        if ($page === 'services' && $action === 'update') {
            require_admin(); $tenant=tenant_id();
            $id=filter_var($_POST['service_id']??null,FILTER_VALIDATE_INT);
            $title=trim((string)($_POST['title']??''));
            $price=filter_var($_POST['price']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
            $active=isset($_POST['is_active'])?1:0;
            if (!$id || $title==='' || mb_strlen($title)>200 || $price===false) throw new RuntimeException('مشخصات خدمت معتبر نیست.');
            if (!query('SELECT id FROM services WHERE id=? AND company_id=?',[$id,$tenant])->fetch()) throw new RuntimeException('خدمت به این شرکت تعلق ندارد.');
            query('UPDATE services SET title=?,price=?,is_active=? WHERE id=? AND company_id=?',[$title,$price,$active,$id,$tenant]);
            audit('service.update',(string)$id); redirect('services','مشخصات و وضعیت خدمت به‌روزرسانی شد.');
        }
        if ($page === 'invoices' && $action === 'add') {
            require_permission('invoices.create');
            $tenant = tenant_id();
            $national=trim((string)($_POST['driver_national_code']??''));
            $selected = array_unique(array_filter(array_map('intval',(array)($_POST['service_ids'] ?? [])),static fn($v)=>$v>0));
            $payment = (string)($_POST['payment_type'] ?? ''); $ref = trim((string)($_POST['pos_ref'] ?? ''));
            if (!preg_match('/^[0-9]{10}$/',$national) || !$selected || !in_array($payment,['cash','pos'],true) || ($payment==='pos' && $ref==='')) throw new RuntimeException('کد ملی، خدمات و شیوه پرداخت را کامل کنید.');
            db()->beginTransaction();
            try {
                $customer=register_missing_driver($tenant,$national,(array)($_POST['new_driver']??[]));
                if (!query('SELECT id FROM customers WHERE id=? AND company_id=?',[$customer,$tenant])->fetch()) throw new RuntimeException('مشتری متعلق به شرکت نیست.');
                $marks = implode(',',array_fill(0,count($selected),'?'));
                $items = query("SELECT id,title,price FROM services WHERE company_id=? AND is_active=1 AND id IN ($marks)",array_merge([$tenant],$selected))->fetchAll();
                if (count($items)!==count($selected)) throw new RuntimeException('یک یا چند خدمت معتبر نیست.');
                $total = array_sum(array_map(static fn($i)=>(int)$i['price'],$items));
                $number = date('YmdHis') . '-' . bin2hex(random_bytes(4));
                query('INSERT INTO invoices(company_id,invoice_number,customer_id,user_id,total_amount,payment_type,pos_ref) VALUES(?,?,?,?,?,?,?)',[$tenant,$number,$customer,user()['id'],$total,$payment,$payment==='pos'?$ref:null]);
                $invoiceId = (int)db()->lastInsertId();
                foreach ($items as $item) query('INSERT INTO invoice_items(company_id,invoice_id,service_id,service_title,price) VALUES(?,?,?,?,?)',[$tenant,$invoiceId,$item['id'],$item['title'],$item['price']]);
                audit('invoice.create',$number); db()->commit();
                header('Location: ?page=receipt&id='.$invoiceId); exit;
            } catch(Throwable $ex) { db()->rollBack(); throw $ex; }
        }
        if (in_array($page,['templates','settings','commissions','profile'],true)) handle_module_post($page,$action);
    }
} catch (Throwable $ex) {
    error_log((string)$ex);
    $error = $ex instanceof RuntimeException ? $ex->getMessage() : 'عملیات انجام نشد؛ اطلاعات یا تکراری‌بودن رکورد را بررسی کنید.';
}

if ($page === 'companies' && !is_super()) { http_response_code(403); exit('دسترسی مجاز نیست'); }
if ($page === 'users') require_admin();
if ($page === 'services') require_permission('services.view');
if ($page === 'templates' || $page === 'settings') require_admin();
if ($permission=module_page_permission($page)) require_permission($permission);
if ($page === 'customers') require_permission('customers.view');
if (in_array($page,['invoices','receipt'],true)) require_permission('invoices.view');
if ($page === 'reports') require_permission('reports.view');
$tenant = null;
if (!in_array($page,['login','companies','profile'],true) && (!is_super() || isset($_SESSION['selected_company']))) {
    $tenant = tenant_id();
}
$companyName = $tenant ? (query('SELECT name FROM companies WHERE id=?',[$tenant])->fetchColumn() ?: '') : '';
if (in_array($page,['reports','activity'],true) && $tenant) {
    try {
        $reportFilter=report_filters();
        if (($_GET['export']??'')==='excel') {
            if ($page==='reports') {
                $reportData=invoice_report($tenant,$reportFilter,10000);
                $rows=array_map(static fn($r)=>[$r['invoice_number'],$r['created_at'],$r['national_code'],$r['customer'],$r['operator_name'],$r['payment_type']==='cash'?'نقدی':'کارتخوان',$r['pos_ref'],$r['total_amount']],$reportData['rows']);
                export_excel_xml('sodoryar-invoices',['شماره فاکتور','تاریخ','کد ملی','نام راننده','اپراتور','نوع پرداخت','کد پیگیری','مبلغ'],$rows);
            } else {
                $rows=activity_report($tenant,$reportFilter,10000);
                export_excel_xml('sodoryar-activity',['تاریخ و ساعت','کاربر','عملیات','توضیحات'],array_map('array_values',$rows));
            }
        }
    } catch (RuntimeException $ex) { $error=$ex->getMessage(); $reportFilter=report_filters_default(); }
}
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
?><!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=e($page==='login'?'ورود':$companyName.' | صدوریار')?></title><link rel="icon" type="image/svg+xml" href="favicon.svg"><link rel="stylesheet" href="assets/app.css"><link rel="stylesheet" href="assets/refinements.css"></head><body>
<?php if ($page==='login'): ?>
<main class="login-wrap"><section class="login-card"><div class="brand-mark">ص</div><h1>ورود به صدوریار</h1><p class="muted">سامانه یکپارچه خدمات تاکسیرانی</p>
<?php if(isset($error)): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>
<form method="post" class="stack"><?=csrf_field()?><label>نام کاربری<input name="username" autocomplete="username" required autofocus></label><label>رمز عبور<input name="password" type="password" autocomplete="current-password" required></label><button>ورود به سامانه</button></form></section></main>
<?php else: ?>
<div class="app-shell"><aside class="sidebar"><div class="brand"><div class="brand-mark">ص</div><div><strong>صدوریار</strong><small>مدیریت خدمات تاکسیرانی</small></div></div>
<nav aria-label="منوی اصلی"><a class="<?= $page==='dashboard'?'active':'' ?>" href="?page=dashboard">داشبورد</a>
<?php if(is_super()): ?><a href="?page=companies">مدیریت شرکت‌ها</a><?php endif; ?>
<?php if($tenant): ?>
<?php if(can('customers.view')): ?><a href="?page=customers">تاکسیرانان</a><?php endif; ?>
<?php if(can('services.view')): ?><a href="?page=services">خدمات و تعرفه‌ها</a><?php endif; ?>
<?php if(user()['role']!=='operator'): ?><a href="?page=users">کاربران شرکت</a><?php endif; ?>
<?php if(can('invoices.view')): ?><a href="?page=invoices">فاکتورها</a><?php endif; ?>
<?php if(can('reports.view')): ?><a href="?page=reports">گزارش مالی</a><?php endif; ?>
<?php if(can('commissions.view')): ?><a href="?page=commissions">پورسانت</a><?php endif; ?>
<?php if(user()['role']!=='operator'): ?><a href="?page=templates">قالب چاپ</a><a href="?page=settings">تنظیمات شرکت</a><?php endif; ?>
<?php if(can('activity.view')): ?><a href="?page=activity">لاگ فعالیت‌ها</a><?php endif; ?>
<?php endif; ?><a href="?page=profile">تغییر رمز عبور</a></nav>
<form method="post" class="logout"><?=csrf_field()?><input type="hidden" name="action" value="logout"><button formaction="?page=logout" class="ghost">خروج از حساب</button></form></aside>
<div class="main-wrap"><header class="topbar"><div class="topbar-title"><small>پنل مدیریت صدوریار</small><strong><?=e($companyName ?: 'مدیریت کل شرکت‌ها')?></strong></div><a class="user-chip" href="?page=profile"><span class="avatar"><?php if(user_photo_table_ready() && query('SELECT 1 FROM user_photos WHERE user_id=?',[user()['id']])->fetchColumn()): ?><img src="?page=user_photo&amp;id=<?=e(user()['id'])?>" alt="تصویر پروفایل"><?php else: ?><?=e(mb_substr(user()['full_name'],0,1))?><?php endif; ?></span><span><b><?=e(user()['full_name'])?></b><small><?=e(['superadmin'=>'مدیر کل','company_admin'=>'مدیر شرکت','operator'=>'اپراتور'][user()['role']])?></small></span></a></header>
<main class="content">
<?php if($flash): ?><div class="alert success"><?=e($flash)?></div><?php endif; ?>
<?php if(isset($error)): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>
<?php if(is_super() && !$tenant && !in_array($page,['companies','profile'],true)): ?>
<h1>انتخاب شرکت</h1><p class="muted">برای مشاهده اطلاعات هر شرکت، ابتدا شرکت مورد نظر را انتخاب کنید.</p>
<div class="card"><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="select_company"><label>شرکت<select name="company_id" required><?php foreach(query('SELECT id,name FROM companies WHERE is_active=1 ORDER BY name')->fetchAll() as $c): ?><option value="<?=e($c['id'])?>"><?=e($c['name'])?></option><?php endforeach; ?></select></label><button>ورود به پنل شرکت</button></form></div>
<?php elseif($page==='companies'): ?>
<div class="heading"><div><h1>مدیریت شرکت‌ها</h1><p class="muted">هر شرکت پایگاه داده منطقی و دسترسی مستقل دارد.</p></div></div>
<div class="card"><h2>افزودن شرکت و مدیر اولیه</h2><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="add"><label>نام شرکت<input name="name" required></label><label>شناسه انگلیسی<input name="slug" pattern="[a-z0-9-]{3,80}" placeholder="mashhad-taxi" required></label><label>نام مدیر شرکت<input name="manager_name" required></label><label>نام کاربری مدیر<input name="manager_username" pattern="[a-zA-Z0-9_.-]{3,100}" required></label><label>رمز اولیه مدیر<input type="password" minlength="12" autocomplete="new-password" name="manager_password" required></label><button>ثبت شرکت و مدیر</button></form></div>
<div class="card"><h2>شرکت‌های ثبت‌شده</h2><div class="table-wrap"><table><thead><tr><th>نام</th><th>شناسه</th><th>وضعیت</th><th>ورود</th></tr></thead><tbody><?php foreach(query('SELECT * FROM companies ORDER BY id DESC')->fetchAll() as $c): ?><tr><td><?=e($c['name'])?></td><td><?=e($c['slug'])?></td><td><?= (int)$c['is_active']?'فعال':'غیرفعال' ?></td><td><?php if((int)$c['is_active']): ?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="select_company"><input type="hidden" name="company_id" value="<?=e($c['id'])?>"><button class="small">انتخاب</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php elseif($page==='dashboard'): ?>
<section class="dashboard-hero"><div><span class="hero-eyebrow">مرکز کنترل خدمات</span><h1>داشبورد <?=e($companyName ?: 'صدوریار')?></h1><p>نمای یکپارچه فعالیت‌های شرکت، وضعیت خدمات و امور مالی</p></div><div class="hero-actions"><?php if(can('invoices.create')): ?><a class="hero-button" href="?page=invoices">+ صدور فاکتور جدید</a><?php endif; ?><a class="hero-secondary" href="?page=profile">حساب کاربری</a></div></section>
<?php if(!can('reports.view')): ?><div class="card"><h2>خوش آمدید</h2><p class="muted">برای مشاهده گزارش‌های مالی، مدیر شرکت باید دسترسی مربوط را برای حساب شما فعال کند.</p></div><?php else: ?>
<?php $stats=query('SELECT (SELECT COUNT(*) FROM customers WHERE company_id=?) customers,(SELECT COUNT(*) FROM services WHERE company_id=? AND is_active=1) services,(SELECT COUNT(*) FROM invoices WHERE company_id=?) invoices,(SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE company_id=?) revenue',[$tenant,$tenant,$tenant,$tenant])->fetch(); ?>
<?php $period=query('SELECT COALESCE(SUM(CASE WHEN DATE(created_at)=CURRENT_DATE() THEN 1 ELSE 0 END),0) today_count,COALESCE(SUM(CASE WHEN created_at>=DATE_FORMAT(CURRENT_DATE(),\'%Y-%m-01\') THEN 1 ELSE 0 END),0) month_count,COALESCE(SUM(CASE WHEN DATE(created_at)=CURRENT_DATE() THEN total_amount ELSE 0 END),0) today_total,COALESCE(SUM(CASE WHEN created_at>=DATE_FORMAT(CURRENT_DATE(),\'%Y-%m-01\') THEN total_amount ELSE 0 END),0) month_total FROM invoices WHERE company_id=?',[$tenant])->fetch(); ?>
<div class="stats"><article class="stat"><span>خدمات ثبت‌شده امروز</span><b><?=money($period['today_count'])?></b></article><article class="stat"><span>خدمات ماه جاری</span><b><?=money($period['month_count'])?></b></article><article class="stat"><span>فروش امروز</span><b><?=money($period['today_total'])?></b><small>مبلغ ثبت‌شده</small></article><article class="stat"><span>فروش ماه جاری</span><b><?=money($period['month_total'])?></b><small>مبلغ ثبت‌شده</small></article></div>
<div class="dashboard-secondary"><div class="card"><h2>عملکرد کاربران در ماه جاری</h2><?php $ranking=query('SELECT u.full_name,COUNT(i.id) invoice_count,COALESCE(SUM(i.total_amount),0) total FROM users u LEFT JOIN invoices i ON i.user_id=u.id AND i.company_id=u.company_id AND i.created_at>=DATE_FORMAT(CURRENT_DATE(),\'%Y-%m-01\') WHERE u.company_id=? AND u.is_active=1 GROUP BY u.id,u.full_name ORDER BY invoice_count DESC,total DESC LIMIT 8',[$tenant])->fetchAll(); ?><div class="ranking-chart"><?php $max=max(1,...array_map(static fn($r)=>(int)$r['invoice_count'],$ranking)); foreach($ranking as $row): ?><div class="ranking-row"><span><?=e($row['full_name'])?></span><div class="ranking-track"><span style="width:<?=e((int)round(100*$row['invoice_count']/$max))?>%"></span></div><b><?=money($row['invoice_count'])?></b></div><?php endforeach; ?></div></div><div class="card"><h2>شاخص‌های جاری</h2><dl class="metrics-list"><div><dt>رانندگان در پرونده شرکت</dt><dd><?=money($stats['customers'])?></dd></div><div><dt>خدمات فعال</dt><dd><?=money($stats['services'])?></dd></div><div><dt>کل فاکتورهای ثبت‌شده</dt><dd><?=money($stats['invoices'])?></dd></div><div><dt>مجموع مبالغ ثبت‌شده</dt><dd><?=money($stats['revenue'])?></dd></div></dl></div></div>
<div class="card"><div class="heading"><h2>آخرین فاکتورها</h2><a href="?page=invoices">مشاهده همه</a></div><?php render_invoices($tenant,8); ?></div>
<?php endif; ?>
<?php elseif($page==='users'): ?>
<h1>کاربران شرکت</h1><p class="muted">مدیر شرکت به همه بخش‌های شرکت خود دسترسی دارد؛ مجوزهای اپراتورها جداگانه تنظیم می‌شوند.</p><div class="card"><h2>ایجاد کاربر</h2><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="add"><label>نام و نام خانوادگی<input name="full_name" required></label><label>نام کاربری<input name="username" pattern="[a-zA-Z0-9_.-]{3,100}" required></label><label>رمز اولیه (حداقل ۱۲ کاراکتر)<input type="password" minlength="12" name="password" autocomplete="new-password" required></label><label>نقش<select name="role"><option value="operator">اپراتور</option><option value="company_admin">مدیر شرکت</option></select></label><fieldset class="wide"><legend>دسترسی‌های اختیاری اپراتور</legend><div class="choices"><?php foreach(permission_catalog() as $key=>$title): ?><label class="choice"><input type="checkbox" name="permissions[]" value="<?=e($key)?>"><?=e($title)?></label><?php endforeach; ?></div></fieldset><button>ایجاد کاربر</button></form></div>
<div class="card"><h2>فهرست کاربران و ویرایش مجوزها</h2><div class="table-wrap"><table><thead><tr><th>کاربر</th><th>نام کاربری</th><th>نقش</th><th>وضعیت</th><th>تصویر</th><th>دسترسی‌های اپراتور</th></tr></thead><tbody><?php $userListSQL=user_photo_table_ready()?'SELECT u.id,u.full_name,u.username,u.role,u.is_active,p.storage_key FROM users u LEFT JOIN user_photos p ON p.user_id=u.id WHERE u.company_id=? ORDER BY u.id DESC':'SELECT u.id,u.full_name,u.username,u.role,u.is_active,NULL AS storage_key FROM users u WHERE u.company_id=? ORDER BY u.id DESC'; foreach(query($userListSQL,[$tenant])->fetchAll() as $r): ?><tr><td><div class="table-person"><span class="avatar"><?php if($r['storage_key']): ?><img src="?page=user_photo&amp;id=<?=e($r['id'])?>" alt=""><?php else: ?><?=e(mb_substr($r['full_name'],0,1))?><?php endif; ?></span><?=e($r['full_name'])?></div></td><td><?=e($r['username'])?></td><td><?=e($r['role'])?></td><td><?=$r['is_active']?'فعال':'غیرفعال'?></td><td><?php if(user_photo_table_ready()): ?><form method="post" enctype="multipart/form-data" class="photo-upload"><?=csrf_field()?><input type="hidden" name="action" value="photo"><input type="hidden" name="user_id" value="<?=e($r['id'])?>"><input aria-label="انتخاب عکس <?=e($r['full_name'])?>" name="photo" type="file" accept="image/jpeg,image/png,image/webp" required><button class="small">ذخیره عکس</button></form><?php else: ?><small>نیاز به میگرشن ۰۰۵</small><?php endif; ?></td><td><?php if($r['role']==='operator'): ?><?php $granted=query('SELECT permission_key FROM user_permissions WHERE user_id=?',[$r['id']])->fetchAll(PDO::FETCH_COLUMN); ?><form method="post"><input type="hidden" name="action" value="permissions"><input type="hidden" name="user_id" value="<?=e($r['id'])?>"><?=csrf_field()?><details><summary>انتخاب دسترسی‌ها</summary><div class="permission-list"><?php foreach(permission_catalog() as $key=>$title): ?><label><input type="checkbox" name="permissions[]" value="<?=e($key)?>" <?=in_array($key,$granted,true)?'checked':''?>><?=e($title)?></label><?php endforeach; ?></div><button class="small">ذخیره</button></details></form><?php else: ?>تمام دسترسی‌های شرکت<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<div class="card"><div class="heading"><div><h2>ویرایش کاربران</h2><p class="muted">ویرایش نام، نام کاربری و وضعیت فعال‌بودن؛ تغییر دسترسی‌های اپراتور از جدول بالا انجام می‌شود.</p></div><button type="button" data-dialog-open="userEdit">ویرایش کاربر</button></div></div>
<dialog id="userEdit" class="app-dialog"><form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="action" value="edit"><h2>ویرایش مشخصات کاربر</h2><label>کاربر<select name="user_id" id="editUserSelect" required><option value="">انتخاب کنید</option><?php foreach(query('SELECT id,full_name,username,is_active FROM users WHERE company_id=? ORDER BY full_name',[$tenant])->fetchAll() as $u): ?><option value="<?=e($u['id'])?>" data-full-name="<?=e($u['full_name'])?>" data-username="<?=e($u['username'])?>" data-active="<?=e($u['is_active'])?>"><?=e($u['full_name'])?></option><?php endforeach; ?></select></label><label>نام و نام خانوادگی<input id="editUserName" name="full_name" maxlength="180" required></label><label>نام کاربری<input id="editUserUsername" name="username" pattern="[a-zA-Z0-9_.-]{3,100}" required></label><label class="choice"><input id="editUserActive" type="checkbox" name="is_active" value="1" checked>حساب فعال باشد</label><div class="dialog-actions"><button class="secondary" type="button" data-dialog-close>انصراف</button><button>ثبت تغییرات</button></div></form></dialog>
<?php elseif($page==='customers'): ?>
<?php if(can('customers.create')): ?><div class="card"><h2>فراخوانی از بانک مرکزی تاکسیرانان</h2><p class="muted">کد ملی را وارد کنید تا راننده به پرونده این شرکت متصل شود؛ سوابق مالی شرکت‌های دیگر منتقل یا نمایش داده نمی‌شوند.</p><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="link_global"><label>کد ملی<input name="national_code" data-driver-lookup inputmode="numeric" maxlength="10" pattern="[0-9]{10}" required><small class="lookup-result" data-driver-result role="status" aria-live="polite"></small></label><button>افزودن به پرونده شرکت</button></form></div><?php endif; ?>
<h1>تاکسیرانان</h1><?php if(can('customers.create')): ?><div class="card"><h2>ثبت تاکسیران</h2><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="add"><label>کد ملی<input name="national_code" inputmode="numeric" pattern="[0-9]{10}" required></label><label>نام<input name="first_name" required></label><label>نام خانوادگی<input name="last_name" required></label><label>تلفن همراه<input name="mobile" inputmode="tel"></label><label>خط فعالیت<input name="line_area"></label><label>کد در خط<input name="line_code"></label><button>ثبت تاکسیران</button></form></div><?php endif; ?>
<div class="card"><h2>فهرست تاکسیرانان</h2><div class="table-wrap"><table><thead><tr><th>کد ملی</th><th>نام</th><th>همراه</th><th>خط</th></tr></thead><tbody><?php foreach(query('SELECT * FROM customers WHERE company_id=? ORDER BY id DESC LIMIT 100',[$tenant])->fetchAll() as $r): ?><tr><td><?=e($r['national_code'])?></td><td><?=e($r['first_name'].' '.$r['last_name'])?></td><td><?=e($r['mobile'])?></td><td><?=e($r['line_area'])?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php elseif($page==='services'): ?>
<div class="heading"><h1>تعریف خدمات و تعرفه‌ها</h1><?php if(user()['role']!=='operator'): ?><button type="button" data-dialog-open="serviceCreate">+ خدمت جدید</button><?php endif; ?></div>
<?php if(user()['role']!=='operator'): ?><dialog id="serviceCreate" class="app-dialog"><form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="action" value="add"><h2>ثبت خدمت جدید</h2><label>عنوان خدمت<input name="title" maxlength="200" placeholder="مثلاً هزینه تمدید پروانه تاکسیرانی" required></label><label>مبلغ (تومان)<input name="price" type="number" min="0" required></label><div class="dialog-actions"><button type="button" class="secondary" data-dialog-close>انصراف</button><button>ذخیره خدمت</button></div></form></dialog><?php endif; ?>
<div class="card table-wrap"><table><thead><tr><th>شناسه</th><th>عنوان خدمت</th><th>مبلغ</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody><?php foreach(query('SELECT id,title,price,is_active FROM services WHERE company_id=? ORDER BY id DESC',[$tenant])->fetchAll() as $r): ?><tr><td><?=e($r['id'])?></td><td><?=e($r['title'])?></td><td><?=money($r['price'])?></td><td><span class="status-chip <?=$r['is_active']?'status-good':'status-muted'?>"><?=$r['is_active']?'فعال':'غیرفعال'?></span></td><td><?php if(user()['role']!=='operator'): ?><button type="button" class="small secondary" data-dialog-open="serviceEdit<?=e($r['id'])?>">ویرایش</button><dialog id="serviceEdit<?=e($r['id'])?>" class="app-dialog"><form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="action" value="update"><input type="hidden" name="service_id" value="<?=e($r['id'])?>"><h2>ویرایش خدمت</h2><label>عنوان خدمت<input name="title" maxlength="200" value="<?=e($r['title'])?>" required></label><label>مبلغ (تومان)<input name="price" type="number" min="0" value="<?=e($r['price'])?>" required></label><label class="choice"><input type="checkbox" name="is_active" value="1" <?=$r['is_active']?'checked':''?>>فعال باشد</label><div class="dialog-actions"><button type="button" class="secondary" data-dialog-close>انصراف</button><button>ذخیره تغییرات</button></div></form></dialog><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php elseif($page==='invoices'): ?>
<?php if(can('invoices.create')): ?><h1>صدور فاکتور</h1><div class="card invoice-workspace"><h2>فاکتور جدید</h2><p class="muted">با کد ملی، راننده مستقیماً از بانک اطلاعات مرکزی شناسایی می‌شود. در صورت نبودن، امکان ثبت راننده جدید وجود دارد.</p><form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="action" value="add"><div class="form-grid"><label>کد ملی تاکسیران<input name="driver_national_code" data-driver-lookup inputmode="numeric" pattern="[0-9]{10}" maxlength="10" placeholder="کد ملی ده‌رقمی" required><small class="lookup-result" data-driver-result role="status" aria-live="polite">برای جستجو کد ملی را وارد کنید.</small></label><label>نوع پرداخت<select name="payment_type" id="paymentType" required><option value="cash">نقدی</option><option value="pos">کارتخوان (ثبت دستی شماره پیگیری)</option></select></label><label>شماره پیگیری کارتخوان<input name="pos_ref" id="posRef" placeholder="برای پرداخت کارتی الزامی است"></label></div><fieldset data-driver-manual hidden><legend>ثبت راننده جدید در بانک مرکزی</legend><p class="muted">کد ملی واردشده یافت نشد. لطفاً مشخصات راننده جدید را وارد کنید.</p><div class="form-grid"><label>نام<input name="new_driver[first_name]" maxlength="100" data-driver-required></label><label>نام خانوادگی<input name="new_driver[last_name]" maxlength="100" data-driver-required></label><label>شماره همراه<input name="new_driver[mobile]" maxlength="30" inputmode="tel"></label><label>خط فعالیت<input name="new_driver[line_area]" maxlength="180"></label></div></fieldset>
<fieldset><legend>خدمات ارائه‌شده</legend><div class="choices"><?php foreach(query('SELECT * FROM services WHERE company_id=? AND is_active=1 ORDER BY title',[$tenant])->fetchAll() as $r): ?><label class="choice"><input type="checkbox" name="service_ids[]" value="<?=e($r['id'])?>"><span><?=e($r['title'])?></span><strong><?=money($r['price'])?></strong></label><?php endforeach; ?></div></fieldset><button>ثبت نهایی فاکتور</button></form></div>
<?php endif; ?><div class="card"><h2>آخرین فاکتورها</h2><?php render_invoices($tenant,50); ?></div>
<?php elseif($page==='reports'): ?>
<h1>گزارش‌گیری پیشرفته</h1><p class="muted">فیلتر براساس تاریخ، کاربر، نوع پرداخت، شماره فاکتور و کد ملی راننده</p>
<?php render_report_filters('reports',$reportFilter); $reportData=invoice_report($tenant,$reportFilter); ?>
<div class="stats"><article class="stat"><span>تعداد فاکتورهای این گزارش</span><b><?=money($reportData['summary']['qty'])?></b></article><article class="stat"><span>مجموع مبالغ این گزارش</span><b><?=money($reportData['summary']['total'])?></b></article></div>
<div class="card"><h2>نتایج گزارش (۲۰۰ مورد آخر)</h2><?php render_table(['شماره فاکتور','تاریخ','کد ملی','مشتری','اپراتور','نوع پرداخت','کد پیگیری','مبلغ'],array_map(static fn($r)=>['invoice_number'=>$r['invoice_number'],'created_at'=>$r['created_at'],'national_code'=>$r['national_code'],'customer'=>$r['customer'],'operator_name'=>$r['operator_name'],'payment_type'=>$r['payment_type']==='cash'?'نقدی':'کارتخوان','pos_ref'=>$r['pos_ref'],'total_amount'=>$r['total_amount']],$reportData['rows']),['total_amount']); ?></div>
<?php elseif($page==='receipt'): ?>
<?php
$invoice=query('SELECT i.*,c.first_name,c.last_name,c.national_code FROM invoices i JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id WHERE i.id=? AND i.company_id=?',[(int)($_GET['id']??0),$tenant])->fetch();
$selectedTemplate=query('SELECT title,paper_type,header_text,footer_text,layout_json FROM print_templates WHERE company_id=? AND is_default=1 LIMIT 1',[$tenant])->fetch();
$templateStyle=$selectedTemplate?json_decode((string)$selectedTemplate['layout_json'],true):[];
?>
<?php if(!$invoice): ?><div class="alert error">فاکتور پیدا نشد.</div><?php else: ?>
<div class="receipt card <?=$selectedTemplate && $selectedTemplate['paper_type']==='thermal80'?'receipt-thermal':''?>" style="font-family:<?=$templateStyle && ($templateStyle['font']??'')==='Tahoma'?'Tahoma,sans-serif':'Vazirmatn,Tahoma,sans-serif'?>"><div class="heading"><h1><?=e($selectedTemplate['header_text']??'فاکتور خدمات')?></h1><button data-print-receipt type="button">چاپ فاکتور</button></div><p><?=e($companyName)?></p><p>شماره: <?=e($invoice['invoice_number'])?> | تاریخ: <?=e($invoice['created_at'])?></p><p>تاکسیران: <?=e($invoice['first_name'].' '.$invoice['last_name'])?> | کد ملی: <?=e($invoice['national_code'])?></p><table><thead><tr><th>خدمت</th><th>مبلغ</th></tr></thead><tbody><?php foreach(query('SELECT service_title,price FROM invoice_items WHERE invoice_id=? AND company_id=?',[$invoice['id'],$tenant])->fetchAll() as $i): ?><tr><td><?=e($i['service_title'])?></td><td><?=money($i['price'])?></td></tr><?php endforeach; ?></tbody></table><h2>جمع کل: <?=money($invoice['total_amount'])?></h2><p>روش پرداخت: <?=$invoice['payment_type']==='cash'?'نقدی':'کارتخوان'?> <?=e($invoice['pos_ref'])?></p><?php if($selectedTemplate && $selectedTemplate['footer_text']!==''): ?><p class="receipt-footer"><?=e($selectedTemplate['footer_text'])?></p><?php endif; ?></div>
<?php endif; ?>
<?php elseif(in_array($page,['commissions','templates','settings','activity','profile'],true)): ?>
<?php render_module($page,(int)$tenant); ?>
<?php endif; ?>
</main></div></div>
<?php endif; ?><script src="assets/app.js" defer></script></body></html>
<?php
function render_invoices(int $tenant, int $limit): void {
    $rows=query('SELECT i.id,i.invoice_number,i.total_amount,i.payment_type,i.created_at,c.first_name,c.last_name FROM invoices i JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id WHERE i.company_id=? ORDER BY i.id DESC LIMIT '.(int)$limit,[$tenant])->fetchAll();
    echo '<div class="table-wrap"><table><thead><tr><th>شماره</th><th>تاکسیران</th><th>مبلغ</th><th>پرداخت</th><th>تاریخ</th><th>جزئیات</th></tr></thead><tbody>';
    foreach($rows as $r) echo '<tr><td>'.e($r['invoice_number']).'</td><td>'.e($r['first_name'].' '.$r['last_name']).'</td><td>'.money($r['total_amount']).'</td><td>'.($r['payment_type']==='cash'?'نقدی':'کارتخوان').'</td><td>'.e($r['created_at']).'</td><td><a href="?page=receipt&amp;id='.e($r['id']).'">چاپ</a></td></tr>';
    echo '</tbody></table></div>';
}
