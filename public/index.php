<?php
declare(strict_types=1);
// Fail closed when a cancelled FTP deployment leaves an incomplete bootstrap.
ini_set('display_errors','0');
require __DIR__ . '/_private/src/bootstrap.php';
foreach (['user','is_super','can','require_login','require_permission','tenant_id','query','verify_csrf'] as $requiredFunction) {
    if (!function_exists($requiredFunction)) {
        error_log('Sodoryar incomplete bootstrap: missing '.$requiredFunction);
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        header('Retry-After: 60');
        exit('نسخه سامانه به‌طور کامل بارگذاری نشده است. لطفاً با پشتیبانی تماس بگیرید.');
    }
}
unset($requiredFunction);
/** Mixed-version deployment or stale OPcache must not expose a secondary fatal error. */
if (!function_exists('public_operation_error')) {
    function public_operation_error(Throwable $ex): string {
        return 'عملیات به دلیل خطای داخلی انجام نشد. لطفاً بعداً دوباره تلاش کنید.';
    }
}

require __DIR__ . '/_private/src/branches.php';
require __DIR__ . '/_private/src/company_resources.php';
require __DIR__ . '/_private/src/expenses.php';
require __DIR__ . '/_private/src/expense_workflow.php';
require __DIR__ . '/_private/src/expense_settlement.php';
require __DIR__ . '/_private/src/expense_returns.php';
require __DIR__ . '/_private/src/expense_financial_forms.php';
require __DIR__ . '/_private/src/expense_reports.php';
require __DIR__ . '/_private/src/expense_report_center.php';
require __DIR__ . '/_private/src/expense_pdf.php';
require __DIR__ . '/_private/src/expenses_page.php';
require __DIR__ . '/_private/src/localization.php';
require __DIR__ . '/_private/src/modules.php';
require __DIR__ . '/_private/src/photos.php';
require __DIR__ . '/_private/src/reporting.php';
require __DIR__ . '/_private/src/invoice_payments.php';
require __DIR__ . '/_private/src/invoice_numbers.php';
require __DIR__ . '/_private/src/invoice_management.php';
require __DIR__ . '/_private/src/global_search.php';
require __DIR__ . '/_private/src/cashbox.php';
require __DIR__ . '/_private/src/cashbox_page.php';
require __DIR__ . '/_private/src/driver_account.php';
require __DIR__ . '/_private/src/accounting_periods.php';
require __DIR__ . '/_private/src/service_rates.php';
require __DIR__ . '/_private/src/finance_presets.php';
require __DIR__ . '/_private/src/finance_presets_ui.php';
require __DIR__ . '/_private/src/user_preferences.php';
require __DIR__ . '/_private/src/payment_receipt.php';
require __DIR__ . '/_private/src/financial_issue_reviews.php';
require __DIR__ . '/_private/src/dashboard_finance.php';
require __DIR__ . '/_private/src/finance_schema_health.php';
require __DIR__ . '/_private/src/default_services.php';

/** Call inside an open transaction before disabling or demoting a company manager. */
function lock_active_company_admins(int $tenant): int {
    return count(query('SELECT id FROM users WHERE company_id=? AND role=? AND is_active=1 ORDER BY id FOR UPDATE',
        [$tenant,'company_admin'])->fetchAll(PDO::FETCH_COLUMN));
}

function archive_ready(string $table): bool {
    if (!in_array($table,['users','companies'],true)) return false;
    static $ready=[];
    if (!array_key_exists($table,$ready)) $ready[$table]=(bool)query("SHOW COLUMNS FROM `$table` LIKE 'archived_at'")->fetch();
    return $ready[$table];
}

$page = (string) ($_GET['page'] ?? 'dashboard');
$valid = ['expense_reports','expense_attachment','expenses','branches','branch_users','bank_accounts','login','logout','dashboard','companies','users','roles','customers','driver_account','payment_receipt','services','service_rate_history','invoices','invoice_manager','cashbox','periods','finance_health','reports','finance_center','receipt','bills','pos','commissions','templates','settings','activity','profile','driver_lookup','global_search','dashboard_detail','service_rate_quotes','user_preferences','user_photo','my_photo','template_logo'];
if (!in_array($page, $valid, true)) { http_response_code(404); exit('صفحه پیدا نشد'); }
if ($page==='user_photo') {
    if ($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405); exit; }
    serve_profile_photo((int)($_GET['id']??0));
}
if ($page==='my_photo') {
    if ($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405); exit; }
    require_login();
    serve_profile_photo((int)user()['id']);
}
if ($page==='template_logo') {
    require_login();
    if ($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405); exit; }
    $tenant=tenant_id();
    $id=filter_var($_GET['id']??null,FILTER_VALIDATE_INT);
    $record=$id?query('SELECT logo_path FROM print_templates WHERE id=? AND company_id=?',[$id,$tenant])->fetch():false;
    if (!$record || !preg_match('/^[a-f0-9]{32}\.(png|jpg)$/',(string)$record['logo_path'])) { http_response_code(404); exit; }
    $path=__DIR__.'/_private/uploads/template-logos/'.$record['logo_path'];
    if (!is_file($path)) { http_response_code(404); exit; }
    header('Content-Type: '.(str_ends_with($path,'.png')?'image/png':'image/jpeg'));
    header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, no-store');
    readfile($path); exit;
}
if ($page==='user_preferences') {
    require_login();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    try {
        $tenant=tenant_id();$actor=(int)user()['id'];
        if ($_SERVER['REQUEST_METHOD']==='GET') {
            echo json_encode(['ready'=>user_preferences_ready(),'preferences'=>user_preferences_all($tenant,$actor)],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
            exit;
        }
        if ($_SERVER['REQUEST_METHOD']!=='POST') {http_response_code(405);echo '{}';exit;}
        verify_csrf();
        $key=$_POST['key']??'';
        $valueJson=$_POST['value']??'';
        if(!is_string($key)||!is_string($valueJson)||strlen($valueJson)>8000)throw new RuntimeException('تنظیم شخصی نامعتبر است.');
        $value=json_decode($valueJson,true,32,JSON_THROW_ON_ERROR);
        user_preference_save($tenant,$actor,$key,$value);
        echo json_encode(['saved'=>true],JSON_THROW_ON_ERROR);exit;
    } catch(Throwable $ex) {
        error_log('Sodoryar user prefs: '.$ex->getCode());
        http_response_code(422);
        echo json_encode(['error'=>'ثبت یا دریافت تنظیمات شخصی امکان‌پذیر نیست.'],JSON_UNESCAPED_UNICODE);exit;
    }
}
if ($page==='service_rate_quotes') {
    require_login();
    require_permission('invoices.create');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    $dateValue=$_GET['date']??'';
    if(!is_string($dateValue) || !preg_match('/^\\d{4}-\\d{2}-\\d{2}$/D',$dateValue)){
        http_response_code(422); echo json_encode(['error'=>'تاریخ نامعتبر است.'],JSON_UNESCAPED_UNICODE);exit;
    }
    try {
        $rows=effective_service_rows(tenant_id(),$dateValue);
        echo json_encode(['services'=>$rows],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    } catch(Throwable $ex){
        error_log('Sodoryar rate quotes: '.$ex->getCode());
        http_response_code(503);echo json_encode(['error'=>'دریافت تعرفه در دسترس نیست.'],JSON_UNESCAPED_UNICODE);
    }
    exit;
}
if ($page==='dashboard_detail') {
    require_login(); require_permission('reports.view');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    if ($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405); exit; }
    try {
        $key=$_GET['key']??'';
        if(!is_string($key)||!in_array($key,['today_count','month_count','today_total','month_total','receipts_today','cash_today','pos_today','due_total','unsettled_count','open_cashboxes','pending_cashboxes','unreviewed_issues','customers','services','invoices','revenue'],true)){http_response_code(400);exit;}
        if($key==='customers'&&!can('customers.view')){http_response_code(403);echo json_encode(['error'=>'دسترسی به اطلاعات رانندگان مجاز نیست.'],JSON_UNESCAPED_UNICODE);exit;}
        $today=date('Y-m-d');
        [$jy,$jm]=gregorian_to_jalali((int)date('Y'),(int)date('n'),(int)date('j'));
        $month=jalali_to_gregorian_date(sprintf('%04d-%02d-01',$jy,$jm));
        [$headers,$rows]=dashboard_detail((int)tenant_id(),$key,$today,$month);
        echo json_encode(['headers'=>$headers,'rows'=>$rows],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    }catch(Throwable $ex){error_log('Dashboard detail: '.$ex->getCode());http_response_code(503);echo '{}';}
    exit;
}
if ($page==='global_search') {
    require_login();
    if ($_SERVER['REQUEST_METHOD']!=='GET') { http_response_code(405); exit; }
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: private, no-store');
    $queryValue=$_GET['q']??'';
    if (!is_string($queryValue)) { http_response_code(422); echo json_encode(['error'=>'عبارت جستجو نامعتبر است.'],JSON_UNESCAPED_UNICODE); exit; }
    $q=trim($queryValue);
    if (mb_strlen($q)>80) { http_response_code(422); echo '{"error":"عبارت جستجو طولانی است."}'; exit; }
    $now=time();
    if ($now-(int)($_SESSION['global_search_started']??0)>=60) {
        $_SESSION['global_search_started']=$now;
        $_SESSION['global_search_count']=0;
    }
    if ((int)($_SESSION['global_search_count']??0)>=40) {
        http_response_code(429); header('Retry-After: 60');
        echo json_encode(['error'=>'تعداد جستجوها زیاد است.'],JSON_UNESCAPED_UNICODE); exit;
    }
    $_SESSION['global_search_count']=(int)($_SESSION['global_search_count']??0)+1;
    if (is_super() && empty($_SESSION['selected_company'])) {
        echo json_encode(['customers'=>[],'invoices'=>[],'payments'=>[],'vehicles'=>[],'services'=>[]],JSON_UNESCAPED_UNICODE); exit;
    }
    try {
        echo json_encode(sodoryar_global_search(tenant_id(),$q),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    } catch(Throwable $ex) {
        error_log('Sodoryar global search: '.$ex->getCode());
        http_response_code(503);
        echo json_encode(['error'=>'امکان اجرای جستجو در حال حاضر وجود ندارد.'],JSON_UNESCAPED_UNICODE);
    }
    exit;
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
    // Invoice autocomplete: only permitted users, prefixes of at least 6 digits,
    // strictly bounded prefix search and a small response. Never expose other-company records.
    $prefix=ascii_digits(trim((string)($_GET['prefix']??'')));
    if (isset($_GET['prefix'])) {
        if (!can('invoices.create')) { http_response_code(403); echo json_encode(['error'=>'دسترسی مجاز نیست.'],JSON_UNESCAPED_UNICODE); exit; }
        if (!valid_driver_lookup_prefix($prefix)) { http_response_code(422); echo json_encode(['error'=>'حداقل شش رقم کد ملی وارد کنید.'],JSON_UNESCAPED_UNICODE); exit; }
        $drivers=query('SELECT national_code,first_name,last_name,line_area,vehicle_plate FROM global_drivers WHERE national_code LIKE ? ORDER BY national_code ASC LIMIT 8',[$prefix.'%'])->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['drivers'=>$drivers,'exact'=>strlen($prefix)===10 && count($drivers)===1 && $drivers[0]['national_code']===$prefix],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR); exit;
    }
    $code=ascii_digits(trim((string)($_GET['national_code']??'')));
    if (!preg_match('/^[0-9]{10}$/D',$code)) { http_response_code(422); echo json_encode(['error'=>'کد ملی ده‌رقمی وارد کنید.'],JSON_UNESCAPED_UNICODE); exit; }
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
        $password=(string)($_POST['password']??'');
        if (strlen($login)>100 || strlen($password)>4096) throw new RuntimeException('اطلاعات ورود نامعتبر است.');
        $attemptKey = login_attempt_key($login);
        $ipKey=login_ip_key();
        if (login_is_limited($attemptKey) || login_is_limited($ipKey,20)) {
            $error = 'تعداد تلاش‌های ناموفق زیاد است. ۱۵ دقیقه دیگر تلاش کنید.';
        } else {
            $captchaValid=login_captcha_verify((string)($_POST['captcha_answer']??''));
            $u=$captchaValid?query('SELECT u.*, c.is_active AS company_active FROM users u LEFT JOIN companies c ON c.id=u.company_id WHERE u.username=? LIMIT 1', [$login])->fetch():false;
            if ($captchaValid && $u && (int) $u['is_active'] === 1 && ($u['role'] === 'superadmin' || (int) $u['company_active'] === 1) && password_verify($password, $u['password_hash'])) {
                if ($u['role']!=='superadmin') sodoryar_subscription_enforce((int)$u['company_id']);
                login_succeeded($attemptKey);
                session_regenerate_id(true);
                rotate_csrf_token();
                unset($_SESSION['login_captcha'], $_SESSION['invoice_create_nonce'], $_SESSION['invoice_start_next']);
                $_SESSION['user'] = ['id'=>(int)$u['id'],'full_name'=>$u['full_name'],'role'=>$u['role'],'company_id'=>$u['company_id']];
                if (auth_version_ready()) $_SESSION['auth_version']=(int)$u['auth_version'];
                $_SESSION['last_activity']=time(); $_SESSION['regenerated_at']=time();
                unset($_SESSION['selected_company']); audit('login'); redirect('dashboard');
            }
            login_failed($attemptKey); login_failed($ipKey);
            $error = 'اطلاعات ورود یا پاسخ سؤال امنیتی نادرست است.';
        }
    }
    if ($page !== 'login') require_login();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $page !== 'login') {
        verify_csrf();
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'select_company' && is_super()) {
            $id = filter_var($_POST['company_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || !query('SELECT id FROM companies WHERE id=? AND is_active=1',[$id])->fetch()) throw new RuntimeException('شرکت معتبر نیست.');
            $_SESSION['selected_company'] = (int)$id; rotate_csrf_token(); redirect('dashboard');
        }
        if ($action === 'leave_company' && is_super()) {
            unset($_SESSION['selected_company']); rotate_csrf_token(); redirect('dashboard','به داشبورد مدیریت کل بازگشتید.');
        }
        if ($page === 'companies' && is_super() && $action === 'save_subscription') {
            $id=filter_var($_POST['company_id']??null,FILTER_VALIDATE_INT);
            $normalizeAmount=static function(mixed $value): string {
                return preg_replace('/[,\x{066C}\s\x{200C}]/u','',ascii_digits(trim((string)$value)));
            };
            $fee=filter_var($normalizeAmount($_POST['monthly_fee']??''),FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
            $months=filter_var($normalizeAmount($_POST['months']??''),FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>120]]);
            $paid=filter_var($normalizeAmount($_POST['paid_amount']??''),FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
            if (!$id || $fee===false || $months===false || $paid===false) throw new RuntimeException('مبالغ یا تعداد ماه‌های اشتراک نامعتبر است.');
            $start=(string)($_POST['start_date']??'');
            $end=(string)($_POST['end_date']??'');
            if(($_POST['period_basis']??'')==='jalali') {
                $end=sodoryar_subscription_jalali_end($start,(int)$months);
            }
            sodoryar_subscription_save((int)$id,$start,$end,(int)$fee,(int)$months,(int)$paid,(string)($_POST['status']??''),(int)user()['id']);
            redirect('companies','اشتراک شرکت با ثبت تاریخچه تغییرات ذخیره شد.');
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
                company_initialize_branches($companyId);
                query('INSERT INTO users(company_id,username,password_hash,full_name,role) VALUES(?,?,?,?,?)',[$companyId,$manager,password_hash($managerPass,PASSWORD_DEFAULT),$managerName,'company_admin']);
                company_assign_new_user_branch($companyId,(int)db()->lastInsertId());
                initialize_company_catalog($companyId,$name);
                if(expenses_ready())expense_initialize_categories($companyId);
                audit('company.create',$slug,$companyId);
                db()->commit();
            } catch(Throwable $ex) { db()->rollBack(); throw $ex; }
            redirect('companies','شرکت و حساب مدیر آن ایجاد شدند.');
        }
        if ($page==='companies' && is_super() && in_array($action,['edit_company','company_status','archive_company','restore_company','import_default_services'],true)) {
            $id=filter_var($_POST['company_id']??null,FILTER_VALIDATE_INT);
            $company=$id?query('SELECT id,name,slug,is_active'.(archive_ready('companies')?',archived_at':'').' FROM companies WHERE id=?',[$id])->fetch():false;
            if (!$company) throw new RuntimeException('شرکت مورد نظر پیدا نشد.');
            $archived=archive_ready('companies') && $company['archived_at']!==null;
            if ($action==='import_default_services') {
                if ($archived) throw new RuntimeException('برای افزودن خدمات، ابتدا شرکت را از بایگانی بازیابی کنید.');
                db()->beginTransaction();
                try {
                    $added=add_missing_default_services((int)$id);
                    audit('company.services.defaults',json_encode(['added'=>$added],JSON_THROW_ON_ERROR),$id);
                    db()->commit();
                } catch(Throwable $ex) { db()->rollBack(); throw $ex; }
                redirect('companies',$added.' خدمت پیش‌فرض جدید افزوده شد؛ تعرفه‌ها و خدمات قبلی بدون تغییر باقی ماندند.');
            }
            if ($action==='edit_company') {
                if ($archived) throw new RuntimeException('ابتدا شرکت بایگانی‌شده را بازیابی کنید.');
                $name=trim((string)($_POST['name']??'')); $slug=strtolower(trim((string)($_POST['slug']??'')));
                if (mb_strlen($name)<2 || mb_strlen($name)>180 || !preg_match('/^[a-z0-9-]{3,80}$/',$slug)) throw new RuntimeException('نام یا شناسه شرکت معتبر نیست.');
                $managerId=filter_var($_POST['manager_id']??null,FILTER_VALIDATE_INT);
                $manager=$managerId?query('SELECT id,username FROM users WHERE id=? AND company_id=? AND role=?',[$managerId,$id,'company_admin'])->fetch():false;
                if ($managerId && !$manager) throw new RuntimeException('مدیر انتخاب‌شده متعلق به این شرکت نیست.');
                $managerName=trim((string)($_POST['manager_name']??''));
                $managerUsername=trim((string)($_POST['manager_username']??''));
                $managerPassword=(string)($_POST['manager_password']??'');
                if (mb_strlen($managerName)<2 || mb_strlen($managerName)>180 || !preg_match('/^[a-zA-Z0-9_.-]{3,100}$/',$managerUsername) || ($managerPassword!=='' && (strlen($managerPassword)<12 || strlen($managerPassword)>4096))) throw new RuntimeException('مشخصات مدیر یا رمز او معتبر نیست؛ رمز حداقل ۱۲ کاراکتر باشد.');
                if (!$manager && strlen($managerPassword)<12) throw new RuntimeException('برای ایجاد حساب مدیر، رمز اولیه حداقل ۱۲ کاراکتری الزامی است.');
                db()->beginTransaction();
                try {
                    query('UPDATE companies SET name=?,slug=? WHERE id=?',[$name,$slug,$id]);
                    if ($manager) {
                        $revokeManagerSessions=$managerPassword!=='' || $managerUsername!==$manager['username'];
                        $sql='UPDATE users SET full_name=?,username=?'.($managerPassword!==''?',password_hash=?':'').($revokeManagerSessions && auth_version_ready()?',auth_version=auth_version+1':'').' WHERE id=? AND company_id=? AND role=?';
                        $params=[$managerName,$managerUsername];
                        if ($managerPassword!=='') $params[]=password_hash($managerPassword,PASSWORD_DEFAULT);
                        query($sql,array_merge($params,[$managerId,$id,'company_admin']));
                    } else {
                        query('INSERT INTO users(company_id,username,password_hash,full_name,role) VALUES(?,?,?,?,?)',[$id,$managerUsername,password_hash($managerPassword,PASSWORD_DEFAULT),$managerName,'company_admin']);
                        $managerId=(int)db()->lastInsertId();
                        company_assign_new_user_branch((int)$id,$managerId);
                    }
                    audit('company.update',$slug,$id);
                    audit($manager?'company.manager.update':'company.manager.create',(string)($managerId?:db()->lastInsertId()),$id);
                    db()->commit();
                } catch(Throwable $ex) { db()->rollBack(); throw $ex; }
                redirect('companies','مشخصات شرکت و حساب مدیر آن ذخیره شد.');
            }
            if ($action==='company_status') {
                if ($archived) throw new RuntimeException('شرکت بایگانی‌شده قابل فعال‌سازی نیست.');
                $active=(int)$company['is_active']===1?0:1;
                query('UPDATE companies SET is_active=? WHERE id=?',[$active,$id]);
                if (!$active && (int)($_SESSION['selected_company']??0)===$id) unset($_SESSION['selected_company']);
                audit($active?'company.activate':'company.deactivate',(string)$id,$id);
                redirect('companies',$active?'شرکت فعال شد.':'شرکت غیرفعال شد و ورود کاربران آن متوقف شد.');
            }
            if (!archive_ready('companies')) throw new RuntimeException('برای بایگانی شرکت، ابتدا میگرشن ۰۰۷ را اجرا کنید.');
            if ($action==='archive_company') {
                if ($archived) throw new RuntimeException('شرکت قبلاً بایگانی شده است.');
                query('UPDATE companies SET is_active=0,archived_at=CURRENT_TIMESTAMP WHERE id=?',[$id]);
                if ((int)($_SESSION['selected_company']??0)===$id) unset($_SESSION['selected_company']);
                audit('company.archive',(string)$id,$id); redirect('companies','شرکت بایگانی شد؛ سوابق مالی آن محفوظ است.');
            }
            if (!$archived) throw new RuntimeException('شرکت بایگانی نشده است.');
            query('UPDATE companies SET archived_at=NULL,is_active=0 WHERE id=?',[$id]);
            audit('company.restore',(string)$id,$id); redirect('companies','شرکت بازیابی شد؛ برای شروع فعالیت، آن را فعال کنید.');
        }
        if ($page === 'users' && $action === 'add') {
            require_admin(); $tenant = tenant_id();
            $username = trim((string)($_POST['username'] ?? ''));
            $fullName = trim((string)($_POST['full_name'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $role = (string)($_POST['role'] ?? 'operator');
            if (!preg_match('/^[a-zA-Z0-9_.-]{3,100}$/',$username) || mb_strlen($fullName)<2 || strlen($password)<12 || !in_array($role,['operator','company_admin'],true)) throw new RuntimeException('اطلاعات کاربر نامعتبر است؛ رمز حداقل ۱۲ کاراکتر باشد.');
            $newPermissions=array_unique((array)($_POST['permissions']??[]));
            foreach($newPermissions as $permission) if (!is_string($permission) || !operator_permission_assignable($permission)) throw new RuntimeException('مجوز نامعتبر است.');
            db()->beginTransaction();
            try {
            query('INSERT INTO users(company_id,username,password_hash,full_name,role) VALUES(?,?,?,?,?)',[$tenant,$username,password_hash($password,PASSWORD_DEFAULT),$fullName,$role]);
            $newUserId=(int)db()->lastInsertId();
            company_assign_new_user_branch($tenant,$newUserId);
            save_user_details($newUserId,(array)($_POST['details']??[]));
            if ($role==='operator') {
                foreach($newPermissions as $permission) {
                    query('INSERT INTO user_permissions(user_id,permission_key) VALUES(?,?)',[$newUserId,$permission]);
                }
                if (company_roles_ready()) {
                    $defaultRole=query('SELECT id FROM company_roles WHERE company_id=? AND is_default=1 AND is_active=1 ORDER BY id LIMIT 1',[$tenant])->fetchColumn();
                    if ($defaultRole) query('INSERT INTO user_role_assignments(user_id,company_id,role_id) VALUES(?,?,?)',[$newUserId,$tenant,$defaultRole]);
                }
            }
            audit('user.create',$username); db()->commit();
            } catch(Throwable $ex) { db()->rollBack(); throw $ex; }
            redirect('users','کاربر ثبت شد.');
        }
        if ($page === 'users' && $action === 'permissions') {
            require_admin(); $tenant=tenant_id();
            $id=filter_var($_POST['user_id']??null,FILTER_VALIDATE_INT);
            $target=$id?query('SELECT id,role,is_active'.(archive_ready('users')?',archived_at':'').' FROM users WHERE id=? AND company_id=?',[$id,$tenant])->fetch():false;
            if ($target && (!(int)$target['is_active'] || (archive_ready('users') && $target['archived_at']!==null))) throw new RuntimeException('دسترسی حساب غیرفعال یا بایگانی‌شده قابل تغییر نیست.');
            if (!$target || $target['role']!=='operator') throw new RuntimeException('اپراتور مورد نظر معتبر نیست.');
            $permissions=array_unique((array)($_POST['permissions']??[]));
            foreach($permissions as $permission) if (!is_string($permission) || !operator_permission_assignable($permission)) throw new RuntimeException('مجوز نامعتبر است.');
            db()->beginTransaction();
            try {
                // Serialize permission edits even when the user has no permission rows yet.
                if (!query('SELECT id FROM users WHERE id=? AND company_id=? AND is_active=1 FOR UPDATE',[$id,$tenant])->fetch()) {
                    throw new RuntimeException('کاربر مجاز برای تغییر دسترسی پیدا نشد.');
                }
                $before=query('SELECT permission_key FROM user_permissions WHERE user_id=? ORDER BY permission_key FOR UPDATE',[$id])->fetchAll(PDO::FETCH_COLUMN);
                $after=array_values($permissions);
                sort($after, SORT_STRING);
                query('DELETE FROM user_permissions WHERE user_id=?',[$id]);
                foreach($after as $permission) query('INSERT INTO user_permissions(user_id,permission_key) VALUES(?,?)',[$id,$permission]);
                revoke_access_sessions((int)$id,(int)$tenant);
                audit('user.permissions.update',json_encode([
                    'user_id'=>$id,
                    'granted'=>array_values(array_diff($after,$before)),
                    'revoked'=>array_values(array_diff($before,$after))
                ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
                db()->commit();
            } catch(Throwable $ex) { db()->rollBack(); throw $ex; }
            redirect('users','دسترسی‌های اپراتور ذخیره شد.');
        }
        if ($page === 'users' && $action === 'document_scope') {
            require_admin();
            if (!document_scopes_ready()) throw new RuntimeException('ابتدا مهاجرت دیتابیس ۰۱۱ را اجرا کنید.');
            $tenant=tenant_id();
            $id=filter_var($_POST['user_id']??null,FILTER_VALIDATE_INT);
            $scope=(string)($_POST['document_scope']??'');
            if (!$id || !in_array($scope,['own','company'],true)) throw new RuntimeException('محدوده دسترسی معتبر نیست.');
            db()->beginTransaction();
            try {
                if (!query('SELECT id FROM users WHERE id=? AND company_id=? AND role=? FOR UPDATE',[$id,$tenant,'operator'])->fetch()) {
                    throw new RuntimeException('محدوده اسناد فقط برای اپراتور همین شرکت قابل تنظیم است.');
                }
                $before=query('SELECT scope FROM user_document_scopes WHERE user_id=? AND company_id=?',[$id,$tenant])->fetchColumn() ?: 'company';
                query('INSERT INTO user_document_scopes(user_id,company_id,scope) VALUES(?,?,?) ON DUPLICATE KEY UPDATE company_id=VALUES(company_id),scope=VALUES(scope)',[$id,$tenant,$scope]);
                revoke_access_sessions((int)$id,(int)$tenant);
                audit('user.document_scope.update',json_encode(['user_id'=>$id,'before'=>$before,'after'=>$scope],JSON_THROW_ON_ERROR));
                db()->commit();
            } catch(Throwable $ex) { db()->rollBack(); throw $ex; }
            redirect('users','محدوده دسترسی اسناد مالی ذخیره شد.');
        }
        if ($page === 'users' && $action === 'assign_role') {
            require_admin();
            require_permission('roles.manage');
            if (!company_roles_ready()) throw new RuntimeException('ابتدا مهاجرت دیتابیس ۰۱۰ را اجرا کنید.');
            $tenant=tenant_id();
            $id=filter_var($_POST['user_id']??null,FILTER_VALIDATE_INT);
            $roleInput=trim((string)($_POST['company_role_id']??''));
            $roleId=$roleInput===''?null:filter_var($roleInput,FILTER_VALIDATE_INT);
            if (!$id || ($roleInput!=='' && !$roleId)) throw new RuntimeException('شناسه کاربر یا نقش معتبر نیست.');
            db()->beginTransaction();
            try {
                $target=query('SELECT id FROM users WHERE id=? AND company_id=? AND role=? AND is_active=1 FOR UPDATE',[$id,$tenant,'operator'])->fetch();
                if (!$target) throw new RuntimeException('انتساب نقش فقط برای اپراتور فعال همین شرکت مجاز است.');
                if ($roleId && !query('SELECT id FROM company_roles WHERE id=? AND company_id=? AND is_active=1',[$roleId,$tenant])->fetch()) {
                    throw new RuntimeException('نقش انتخاب‌شده متعلق به شرکت یا فعال نیست.');
                }
                $before=query('SELECT role_id FROM user_role_assignments WHERE user_id=? AND company_id=?',[$id,$tenant])->fetchColumn();
                if ($roleId===null) query('DELETE FROM user_role_assignments WHERE user_id=? AND company_id=?',[$id,$tenant]);
                else query('INSERT INTO user_role_assignments(user_id,company_id,role_id) VALUES(?,?,?) ON DUPLICATE KEY UPDATE company_id=VALUES(company_id),role_id=VALUES(role_id)',[$id,$tenant,$roleId]);
                revoke_access_sessions((int)$id,(int)$tenant);
                audit('user.role.assign',json_encode(['user_id'=>$id,'before'=>$before?:null,'after'=>$roleId],JSON_THROW_ON_ERROR));
                db()->commit();
            } catch(Throwable $ex) { db()->rollBack(); throw $ex; }
            redirect('users','نقش سفارشی کاربر به‌روزرسانی شد.');
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
            $target=$id?query('SELECT id,role,is_active'.(archive_ready('users')?',archived_at':'').' FROM users WHERE id=? AND company_id=?',[$id,$tenant])->fetch():false;
            $fullName=trim((string)($_POST['full_name']??''));
            $username=trim((string)($_POST['username']??''));
            $active=isset($_POST['is_active'])?1:0;
            $role=(string)($_POST['role']??($target['role']??''));
            $newPassword=(string)($_POST['new_password']??'');
            if (!$target || mb_strlen($fullName)<2 || mb_strlen($fullName)>180 || !preg_match('/^[a-zA-Z0-9_.-]{3,100}$/',$username)) throw new RuntimeException('اطلاعات کاربر معتبر نیست.');
            if (!in_array($role,['operator','company_admin'],true) || ($newPassword!=='' && (strlen($newPassword)<12 || strlen($newPassword)>4096))) throw new RuntimeException('نقش یا رمز جدید معتبر نیست (حداقل ۱۲ کاراکتر).');
            if (archive_ready('users') && $target['archived_at']!==null) throw new RuntimeException('ابتدا کاربر را از بایگانی بازیابی کنید.');
            if ((int)$id===(int)user()['id'] && (!$active || $role!=='company_admin')) throw new RuntimeException('نمی‌توانید حساب مدیریتی خود را غیرفعال کنید یا نقش آن را تغییر دهید.');
            db()->beginTransaction();
            try {
                // Serialize admin count checks and writes; otherwise two managers can
                // concurrently demote one another and leave a company unmanaged.
                $adminCount=lock_active_company_admins($tenant);
                $locked=query('SELECT id,role,is_active'.(archive_ready('users')?',archived_at':'').' FROM users WHERE id=? AND company_id=? FOR UPDATE',[$id,$tenant])->fetch();
                if (!$locked || (archive_ready('users') && $locked['archived_at']!==null)) {
                    throw new RuntimeException('حساب کاربر در حال حاضر قابل ویرایش نیست.');
                }
                if ((int)$id===(int)user()['id'] && (!$active || $role!=='company_admin')) {
                    throw new RuntimeException('نمی‌توانید نقش حساب مدیریتی خود را حذف یا آن را غیرفعال کنید.');
                }
                if ($locked['role']==='company_admin' && (int)$locked['is_active']===1
                    && (!$active || $role!=='company_admin') && $adminCount<=1) {
                    throw new RuntimeException('آخرین مدیر فعال شرکت را نمی‌توان غیرفعال کرد یا نقش آن را تغییر داد.');
                }
                $revokeSessions=auth_version_ready() && ($newPassword!=='' || $role!==$locked['role'] || $active!==(int)$locked['is_active']);
                query('UPDATE users SET full_name=?,username=?,is_active=?,role=?'.($newPassword!==''?',password_hash=?':'').($revokeSessions?',auth_version=auth_version+1':'').' WHERE id=? AND company_id=?',array_merge([$fullName,$username,$active,$role],$newPassword!==''?[password_hash($newPassword,PASSWORD_DEFAULT),$id,$tenant]:[$id,$tenant]));
                if ($revokeSessions && (int)$id===(int)user()['id']) {
                    $_SESSION['auth_version']=(int)query('SELECT auth_version FROM users WHERE id=?',[$id])->fetchColumn();
                }
                if ($role==='company_admin') {
                    query('DELETE FROM user_permissions WHERE user_id=?',[$id]);
                    if (company_roles_ready()) query('DELETE FROM user_role_assignments WHERE user_id=? AND company_id=?',[$id,$tenant]);
                }
                save_user_details((int)$id,(array)($_POST['details']??[]));
                audit('user.update',(string)$id); db()->commit();
            } catch(Throwable $ex) { db()->rollBack(); throw $ex; }
            redirect('users','اطلاعات کاربر به‌روزرسانی شد.');
        }
        if ($page==='users' && in_array($action,['user_status','archive_user','restore_user'],true)) {
            require_admin(); $tenant=tenant_id();
            $id=filter_var($_POST['user_id']??null,FILTER_VALIDATE_INT);
            if (!$id) throw new RuntimeException('شناسه کاربر معتبر نیست.');
            if ((int)$id===(int)user()['id'] && $action!=='restore_user') {
                throw new RuntimeException('حساب خودتان را نمی‌توانید غیرفعال یا بایگانی کنید.');
            }
            if ($action!=='user_status' && !archive_ready('users')) {
                throw new RuntimeException('برای مدیریت بایگانی کاربران ابتدا Migration شماره ۰۰۷ را اجرا کنید.');
            }
            db()->beginTransaction();
            try {
                $adminCount=lock_active_company_admins($tenant);
                $target=query('SELECT id,role,is_active'.(archive_ready('users')?',archived_at':'')
                    .' FROM users WHERE id=? AND company_id=? FOR UPDATE',[$id,$tenant])->fetch();
                if (!$target) throw new RuntimeException('کاربر متعلق به این شرکت نیست.');
                $archived=archive_ready('users') && $target['archived_at']!==null;
                $versionSql=auth_version_ready()?',auth_version=auth_version+1':'';
                if ($action==='user_status') {
                    if ($archived) throw new RuntimeException('ابتدا کاربر را از بایگانی بازیابی کنید.');
                    $active=(int)$target['is_active']===1?0:1;
                    if (!$active && $target['role']==='company_admin' && $adminCount<=1) {
                        throw new RuntimeException('آخرین مدیر فعال شرکت را نمی‌توان غیرفعال کرد.');
                    }
                    query('UPDATE users SET is_active=?'.$versionSql.' WHERE id=? AND company_id=?',[$active,$id,$tenant]);
                    audit($active?'user.activate':'user.deactivate',(string)$id);
                    $message=$active?'حساب کاربر فعال شد.':'حساب کاربر غیرفعال شد.';
                } elseif ($action==='archive_user') {
                    if ($archived) throw new RuntimeException('کاربر قبلاً بایگانی شده است.');
                    if ($target['role']==='company_admin' && (int)$target['is_active']===1 && $adminCount<=1) {
                        throw new RuntimeException('آخرین مدیر فعال شرکت را نمی‌توان بایگانی کرد.');
                    }
                    query('UPDATE users SET is_active=0,archived_at=CURRENT_TIMESTAMP'.$versionSql.' WHERE id=? AND company_id=?',[$id,$tenant]);
                    audit('user.archive',(string)$id);
                    $message='کاربر بایگانی شد؛ سوابق فاکتورهای او محفوظ است.';
                } else {
                    if (!$archived) throw new RuntimeException('کاربر بایگانی نشده است.');
                    query('UPDATE users SET archived_at=NULL,is_active=0'.$versionSql.' WHERE id=? AND company_id=?',[$id,$tenant]);
                    audit('user.restore',(string)$id);
                    $message='کاربر بازیابی شد؛ برای ورود، حساب را فعال کنید.';
                }
                db()->commit();
            } catch(Throwable $ex) {
                db()->rollBack();
                throw $ex;
            }
            redirect('users',$message);
        }
        if ($page === 'customers' && $action === 'add') {
            require_permission('customers.create');
            $tenant = tenant_id(); $code = ascii_digits(trim((string)($_POST['national_code'] ?? '')));
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
            $national=ascii_digits(trim((string)($_POST['national_code']??'')));
            attach_global_driver(tenant_id(),$national);
            audit('customer.link_global',$national); redirect('customers','تاکسیران به پرونده این شرکت اضافه شد.');
        }
        if ($page === 'services' && $action === 'add') {
            require_admin(); $title = trim((string)($_POST['title'] ?? ''));
            $price = parse_money_input($_POST['price'] ?? null);
            if ($title==='' || mb_strlen($title)>200 || $price===false) throw new RuntimeException('عنوان و مبلغ معتبر وارد کنید.');
            $tenant=tenant_id();
            db()->beginTransaction();
            try {
                query('INSERT INTO services(company_id,title,price) VALUES(?,?,?)',[$tenant,$title,$price]);
                register_initial_service_rate($tenant,(int)db()->lastInsertId(),(int)user()['id'],$title,$price);
                audit('service.create',$title);
                db()->commit();
            } catch(Throwable $ex) {db()->rollBack();throw $ex;}
            redirect('services','خدمت ثبت شد.');
        }
        if ($page === 'services' && $action === 'update') {
            require_admin(); $tenant=tenant_id();
            $id=filter_var($_POST['service_id']??null,FILTER_VALIDATE_INT);
            $title=trim((string)($_POST['title']??''));
            $price=parse_money_input($_POST['price']??null);
            $active=isset($_POST['is_active'])?1:0;
            if (!$id || $title==='' || mb_strlen($title)>200 || $price===false) throw new RuntimeException('مشخصات خدمت معتبر نیست.');
            db()->beginTransaction();
            try {
                update_service_with_rates($tenant,(int)$id,$title,(int)$price,$active,
                    trim((string)($_POST['effective_from']??'')),trim((string)($_POST['change_reason']??'')),(int)user()['id']);
                audit('service.update',(string)$id);
                db()->commit();
            } catch(Throwable $ex) {db()->rollBack();throw $ex;}
            redirect('services','مشخصات و وضعیت خدمت به‌روزرسانی شد.');
        }
        if ($page === 'invoices' && $action === 'add') {
            require_permission('invoices.create');
            $expectedNonce=(string)($_SESSION['invoice_create_nonce']??'');
            $submittedNonce=(string)($_POST['invoice_create_nonce']??'');
            $tenant = tenant_id();
            $actor=(int)user()['id'];
            if (!preg_match('/^[a-f0-9]{32}$/D',$submittedNonce)) throw new RuntimeException('شناسه درخواست نامعتبر است.');
            $requestLedger=(bool)query("SHOW TABLES LIKE 'invoice_create_requests'")->fetch();
            $payload=$_POST;
            unset($payload['invoice_after_save'],$payload['csrf'],$payload['_csrf'],$payload['csrf_token']);
            $requestHash=hash('sha256',serialize($payload));
            if ($requestLedger) {
                $previous=query('SELECT invoice_id,request_hash FROM invoice_create_requests WHERE company_id=? AND user_id=? AND request_key=?',[$tenant,$actor,$submittedNonce])->fetch();
                if ($previous) {
                    if (!hash_equals($previous['request_hash'],$requestHash)) throw new RuntimeException('این شناسه قبلاً برای فاکتور دیگری استفاده شده است.');
                    finish_invoice_creation((int)$previous['invoice_id']);
                }
            }
            if ($expectedNonce==='' || !hash_equals($expectedNonce,$submittedNonce)) {
                throw new RuntimeException('این درخواست صدور قبلاً ثبت شده یا فرم منقضی شده است؛ صفحه را تازه‌سازی کنید.');
            }
            $national=ascii_digits(trim((string)($_POST['driver_national_code']??'')));
            $selected = normalize_invoice_service_ids($_POST['service_ids'] ?? []);
            $extras=normalize_invoice_extra_rows($_POST['extra_title']??[],$_POST['extra_price']??[]);
            // The invoice date is independent of its immutable creation timestamp.
            $invoiceDate=ascii_digits(trim((string)($_POST['invoice_date']??'')));
            $parsedInvoiceDate=DateTimeImmutable::createFromFormat('!Y-m-d',$invoiceDate);
            if (!preg_match('/^\\d{4}-\\d{2}-\\d{2}$/',$invoiceDate) || !$parsedInvoiceDate || $parsedInvoiceDate->format('Y-m-d')!==$invoiceDate) {
                throw new RuntimeException('تاریخ فاکتور نامعتبر است. تاریخ را از تقویم شمسی انتخاب کنید.');
            }
            if (!query("SHOW COLUMNS FROM invoices LIKE 'invoice_date'")->fetch()) {
                throw new RuntimeException('برای ثبت تاریخ فاکتور، ابتدا مهاجرت دیتابیس ۰۰۸ را اجرا کنید.');
            }
            if (!preg_match('/^[0-9]{10}$/D',$national) || (!$selected && !$extras)) throw new RuntimeException('کد ملی و خدمات را کامل کنید.');

            if ($extras && !invoice_extensions_ready()) throw new RuntimeException('برای ثبت آیتم اضافی، ابتدا میگرشن دیتابیس ۰۰۶ را اجرا کنید.');
            db()->beginTransaction();
            try {
                if ($requestLedger) {
                    query('INSERT INTO invoice_create_requests(company_id,user_id,request_key,request_hash) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE request_key=request_key',[$tenant,$actor,$submittedNonce,$requestHash]);
                    $claimed=query('SELECT invoice_id,request_hash FROM invoice_create_requests WHERE company_id=? AND user_id=? AND request_key=? FOR UPDATE',[$tenant,$actor,$submittedNonce])->fetch();
                    if (!hash_equals($claimed['request_hash'],$requestHash)) throw new RuntimeException('شناسه درخواست با محتوای فاکتور مطابقت ندارد.');
                    if ($claimed['invoice_id']) {
                        db()->commit();
                        finish_invoice_creation((int)$claimed['invoice_id']);
                    }
                }
                accounting_assert_write_open($tenant,$invoiceDate);
                $issueBranch=branches_ready()?branch_require($tenant,$actor,$_POST['branch_id']??null):null;
                $customer=register_missing_driver($tenant,$national,(array)($_POST['new_driver']??[]),true);
                if (!query('SELECT id FROM customers WHERE id=? AND company_id=?',[$customer,$tenant])->fetch()) throw new RuntimeException('مشتری متعلق به شرکت نیست.');
                $items=[];
                if ($selected) $items=effective_service_rows($tenant,$invoiceDate,$selected);
                if (count($items)!==count($selected)) throw new RuntimeException('یک یا چند خدمت معتبر نیست.');
                $total = sum_invoice_line_amounts(array_merge($items,$extras));
                $allowPartial=isset($_POST['allow_partial']) && (string)$_POST['allow_partial']==='1';
                $paymentRows=invoice_payment_rows((array)($_POST['payments']??[]),$total,$allowPartial);
                if (!invoice_payment_ledger_ready() && in_array('pos',array_column($paymentRows,'method'),true)) throw new RuntimeException('برای ثبت و کنترل فیش کارتخوان، ابتدا میگرشن ۰۱۶ را اجرا کنید.');
                if ($allowPartial && !invoice_payment_ledger_ready()) throw new RuntimeException('صدور فاکتور با مانده پس از اجرای میگرشن ۰۱۶ فعال می‌شود.');
                if (!invoice_payment_ledger_ready() && count($paymentRows)>1) throw new RuntimeException('ثبت چندتراکنشی پس از اجرای میگرشن ۰۱۶ فعال می‌شود.');
                $payment=$paymentRows[0]['method']??'cash';
                $ref=count($paymentRows)===1?$paymentRows[0]['tracking']:null;
                $number = insert_invoice_with_short_number($tenant,$customer,(int)user()['id'],$total,$payment,$payment==='pos'?$ref:null,$invoiceDate);
                $invoiceId = (int)db()->lastInsertId();
                if ($issueBranch!==null) query('UPDATE invoices SET branch_id=? WHERE company_id=? AND id=?',[$issueBranch,$tenant,$invoiceId]);
                foreach ($items as $item) query('INSERT INTO invoice_items(company_id,invoice_id,service_id,service_title,price) VALUES(?,?,?,?,?)',[$tenant,$invoiceId,$item['id'],$item['title'],$item['price']]);
                foreach ($extras as $extra) query('INSERT INTO invoice_items(company_id,invoice_id,service_id,service_title,price) VALUES(?,?,?,?,?)',[$tenant,$invoiceId,null,$extra['title'],$extra['price']]);
                if (invoice_payment_ledger_ready()) create_invoice_payment_rows($tenant,$invoiceId,(int)user()['id'],$paymentRows);
                if ($requestLedger) query('UPDATE invoice_create_requests SET invoice_id=? WHERE company_id=? AND user_id=? AND request_key=?',[$invoiceId,$tenant,$actor,$submittedNonce]);
                audit('invoice.create',$number); db()->commit();
                finish_invoice_creation($invoiceId);
            } catch(Throwable $ex) { if(db()->inTransaction())db()->rollBack(); throw $ex; }
        }
        if ($page==='invoices' && $action==='collect_balance') {
            require_permission('invoices.payment_edit');
            $tenant=tenant_id();
            if (!invoice_followup_ready()) throw new RuntimeException('ثبت دریافت بعدی نیازمند اجرای میگرشن ۰۱۷ است.');
            $id=filter_var($_POST['invoice_id']??null,FILTER_VALIDATE_INT);
            $nonce=(string)($_POST['collect_nonce']??'');
            $expected=(string)($_SESSION['invoice_collect_nonces'][$id]??'');
            if (!$id || $expected==='' || !hash_equals($expected,$nonce)) {
                throw new RuntimeException('درخواست دریافت تکراری یا منقضی است؛ صفحه را تازه‌سازی کنید.');
            }
            $raw=[['method'=>$_POST['payment_method']??'','amount'=>$_POST['amount']??'',
                'terminal'=>$_POST['terminal']??'','tracking'=>$_POST['tracking']??'',
                'reference'=>$_POST['reference']??'']];
            db()->beginTransaction();
            try {
                accounting_assert_write_open($tenant,date('Y-m-d'));
                $invoice=query('SELECT id,total_amount,user_id FROM invoices WHERE company_id=? AND id=? FOR UPDATE',[$tenant,$id])->fetch();
                if (!$invoice) throw new RuntimeException('فاکتور متعلق به این شرکت نیست.');
                require_document_owner((int)$invoice['user_id']);
                if (database_table_exists('invoice_voids') && query('SELECT invoice_id FROM invoice_voids WHERE company_id=? AND invoice_id=?',[$tenant,$id])->fetch()) {
                    throw new RuntimeException('دریافت برای فاکتور باطل‌شده مجاز نیست.');
                }
                $summary=invoice_payment_totals($tenant,$id,(int)$invoice['total_amount']);
                $remaining=(int)$invoice['total_amount']-(int)$summary['paid'];
                if ($remaining<=0) throw new RuntimeException('مانده قابل وصول برای این فاکتور وجود ندارد.');
                $rows=invoice_payment_rows($raw,$remaining,true);
                $paymentId=invoice_followup_record($tenant,$id,(int)user()['id'],$rows[0],hash('sha256',$nonce));
                audit('invoice.payment.collect',json_encode(['invoice_id'=>$id,'payment_id'=>$paymentId,'amount'=>$rows[0]['amount']],JSON_THROW_ON_ERROR));
                db()->commit();
                unset($_SESSION['invoice_collect_nonces'][$id]);
            } catch(Throwable $ex) { db()->rollBack(); throw $ex; }
            redirect('invoices','دریافت جدید ثبت شد و مانده فاکتور به‌روزرسانی شد.');
        }
        if ($page==='invoices' && $action==='edit') {
            require_permission('invoices.payment_edit'); $tenant=tenant_id();
            $id=filter_var($_POST['invoice_id']??null,FILTER_VALIDATE_INT);
            $payment=(string)($_POST['payment_type']??'');
            $ref=trim(ascii_digits((string)($_POST['pos_ref']??'')));
            $reason=trim((string)($_POST['reason']??''));
            if (!$id || !in_array($payment,['cash','pos'],true) || mb_strlen($ref)>120 || ($payment==='pos' && $ref==='')
                || mb_strlen($reason)<5 || mb_strlen($reason)>500) throw new RuntimeException('روش پرداخت و دلیل اصلاح (۵ تا ۵۰۰ کاراکتر) الزامی است.');
            db()->beginTransaction();
            try {
                $invoice=query('SELECT id,payment_type,pos_ref,total_amount,user_id,COALESCE(invoice_date,DATE(created_at)) financial_date FROM invoices WHERE id=? AND company_id=? FOR UPDATE',[$id,$tenant])->fetch();
                if (!$invoice) throw new RuntimeException('فاکتور متعلق به این شرکت نیست.');
                require_document_owner((int)$invoice['user_id']);
                if (database_table_exists('invoice_voids') && query('SELECT invoice_id FROM invoice_voids WHERE invoice_id=? AND company_id=?',[$id,$tenant])->fetch()) {
                    throw new RuntimeException('فاکتور باطل‌شده قابل ویرایش نیست.');
                }
                accounting_assert_write_open($tenant,$invoice['financial_date']);
                if (invoice_payment_ledger_ready()) {
                    cashbox_assert_payments_mutable($tenant,(int)$id);
                    $active=get_invoice_payment_rows($tenant,(int)$id);
                    if (count($active)!==1 || (int)$active[0]['amount']!==(int)$invoice['total_amount']) {
                        throw new RuntimeException('برای اصلاح چندتراکنشی از عملیات تخصصی مالی استفاده کنید؛ جایگزینی یکباره مجاز نیست.');
                    }
                    $old=$active[0];
                    $replacement=invoice_payment_rows([['method'=>$payment,'amount'=>(int)$old['amount'],'tracking'=>$payment==='pos'?$ref:'','reference'=>$payment==='pos'?(string)($old['reference_number']??''):'','terminal'=>$payment==='pos'?(string)($old['terminal_id']??''):'']],(int)$old['amount']);
                    $paymentChanges=correct_invoice_payment_rows($tenant,(int)$id,$active,$replacement,$reason);
                }
                query('UPDATE invoices SET payment_type=?,pos_ref=? WHERE id=? AND company_id=?',[$payment,$payment==='pos'?$ref:null,$id,$tenant]);
                audit('invoice.payment.update',json_encode(['invoice_id'=>$id,'reason'=>$reason,'receipts'=>$paymentChanges??[],'before'=>['payment_type'=>$invoice['payment_type'],'pos_ref'=>$invoice['pos_ref']],'after'=>['payment_type'=>$payment,'pos_ref'=>$payment==='pos'?$ref:null]],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
                db()->commit();
            } catch(Throwable $ex) { db()->rollBack(); throw $ex; }
            redirect('invoices','شیوه پرداخت فاکتور با ثبت سابقه اصلاح شد.');
        }
        if (in_array($page,['invoices','invoice_manager'],true) && $action==='edit_invoice_date') {
            require_permission('invoices.payment_edit'); $tenant=tenant_id();
            $id=filter_var($_POST['invoice_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $date=ascii_digits((string)($_POST['invoice_date']??''));
            $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
            $reason=trim((string)($_POST['reason']??''));
            if(!$id||!$parsed||$parsed->format('Y-m-d')!==$date||$date>date('Y-m-d')||mb_strlen($reason)<5||mb_strlen($reason)>500)throw new RuntimeException('تاریخ یا دلیل اصلاح معتبر نیست.');
            db()->beginTransaction();
            try{
                $invoice=query('SELECT * FROM invoices WHERE company_id=? AND id=? FOR UPDATE',[$tenant,$id])->fetch();
                if(!$invoice)throw new RuntimeException('فاکتور پیدا نشد.');
                require_document_owner((int)$invoice['user_id']);
                if(branches_ready())branch_require($tenant,(int)user()['id'],$invoice['branch_id']);
                if(database_table_exists('invoice_voids')&&query('SELECT invoice_id FROM invoice_voids WHERE company_id=? AND invoice_id=?',[$tenant,$id])->fetch())throw new RuntimeException('فاکتور ابطال‌شده قابل ویرایش نیست.');
                $before=$invoice['invoice_date']?:substr($invoice['created_at'],0,10);
                accounting_assert_write_open($tenant,$before);
                accounting_assert_write_open($tenant,$date);
                if($before!==$date){
                    query('UPDATE invoices SET invoice_date=? WHERE company_id=? AND id=?',[$date,$tenant,$id]);
                    audit('invoice.date.update',json_encode(['invoice_id'=>$id,'reason'=>$reason,'before_date'=>$before,'after_date'=>$date],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$tenant);
                }
                db()->commit();
            }catch(Throwable $ex){if(db()->inTransaction())db()->rollBack();throw $ex;}
            redirect($page,'تاریخ فاکتور اصلاح شد؛ تاریخ دریافت وجه بدون تغییر باقی ماند.');
        }
        if (in_array($page,['invoices','invoice_manager'],true) && $action==='replace_payments') {
            require_permission('invoices.payment_edit'); $tenant=tenant_id();
            if (!invoice_payment_ledger_ready()) throw new RuntimeException('ابتدا میگرشن ۰۱۶ را اجرا کنید.');
            $id=filter_var($_POST['invoice_id']??null,FILTER_VALIDATE_INT);
            $reason=trim((string)($_POST['reason']??''));
            $nonce=(string)($_POST['payment_replace_nonce']??'');
            if (!$id || mb_strlen($reason)<5 || mb_strlen($reason)>500) throw new RuntimeException('دلیل اصلاح باید بین ۵ و ۵۰۰ نویسه باشد.');
            if (empty($_SESSION['invoice_replace_nonce']) || !hash_equals((string)$_SESSION['invoice_replace_nonce'],$nonce)) {
                throw new RuntimeException('فرم اصلاح منقضی شده یا قبلاً ثبت شده است.');
            }
            db()->beginTransaction();
            try {
                $invoice=query('SELECT id,total_amount,user_id,COALESCE(invoice_date,DATE(created_at)) financial_date FROM invoices WHERE id=? AND company_id=? FOR UPDATE',[$id,$tenant])->fetch();
                if (!$invoice) throw new RuntimeException('فاکتور متعلق به این شرکت نیست.');
                require_document_owner((int)$invoice['user_id']);
                if (database_table_exists('invoice_voids') && query('SELECT invoice_id FROM invoice_voids WHERE invoice_id=? AND company_id=?',[$id,$tenant])->fetch()) {
                    throw new RuntimeException('فاکتور باطل‌شده قابل ویرایش نیست.');
                }
                accounting_assert_write_open($tenant,$invoice['financial_date']);
                cashbox_assert_payments_mutable($tenant,(int)$id);
                $oldPayments=get_invoice_payment_rows($tenant,(int)$id);
                if (!$oldPayments) throw new RuntimeException('فاکتور فاقد سابقه پرداخت است و نیاز به بررسی مالی دارد.');
                $posted=(array)($_POST['payments']??[]);
                $oldIds=array_map(static fn($p)=>(int)$p['id'],$oldPayments);
                if(array_map(static fn($p)=>(int)($p['id']??0),$posted)!==$oldIds)throw new RuntimeException('ردیف‌های دریافت تغییر کرده‌اند؛ صفحه را تازه‌سازی کنید.');
                $paidTotal=array_sum(array_map(static fn($p)=>(int)$p['amount'],$oldPayments));
                $replacement=invoice_payment_rows($posted,$paidTotal);
                $paymentChanges=correct_invoice_payment_rows($tenant,(int)$id,$oldPayments,$replacement,$reason);
                $first=$replacement[0];
                query('UPDATE invoices SET payment_type=?,pos_ref=? WHERE id=? AND company_id=?',
                    [$first['method'],count($replacement)===1 && $first['method']==='pos'?$first['tracking']:null,$id,$tenant]);
                audit('invoice.payments.replace',json_encode([
                    'invoice_id'=>$id,'reason'=>$reason,'receipts'=>$paymentChanges,
                    'payment_count'=>count($replacement),'payment_total'=>$paidTotal
                ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
                db()->commit();
                unset($_SESSION['invoice_replace_nonce']);
            } catch(Throwable $ex) { db()->rollBack(); throw $ex; }
            redirect($page,'پرداخت‌ها با حفظ سوابق قبلی اصلاح شدند.');
        }
        if ($page==='invoices' && $action==='void') {
            require_permission('invoices.void'); $tenant=tenant_id();
            if (!database_table_exists('invoice_voids')) throw new RuntimeException('برای ابطال فاکتور ابتدا میگرشن دیتابیس ۰۰۶ را اجرا کنید.');
            $id=filter_var($_POST['invoice_id']??null,FILTER_VALIDATE_INT);
            $reason=trim((string)($_POST['reason']??''));
            if (!$id || mb_strlen($reason)<5 || mb_strlen($reason)>500) throw new RuntimeException('دلیل ابطال را در ۵ تا ۵۰۰ کاراکتر وارد کنید.');
            $targetInvoice=query('SELECT id,user_id FROM invoices WHERE id=? AND company_id=?',[$id,$tenant])->fetch();
            if (!$targetInvoice) throw new RuntimeException('فاکتور متعلق به این شرکت نیست.');
            require_document_owner((int)$targetInvoice['user_id']);
            db()->beginTransaction();
            try {
                // Lock the tenant's invoice while recording its void and audit trail.
                $locked=query('SELECT id,COALESCE(invoice_date,DATE(created_at)) financial_date FROM invoices WHERE id=? AND company_id=? FOR UPDATE',[$id,$tenant])->fetch();
                if (!$locked) throw new RuntimeException('فاکتور متعلق به این شرکت نیست.');
                accounting_assert_write_open($tenant,$locked['financial_date']);
                if(query('SELECT invoice_id FROM invoice_voids WHERE company_id=? AND invoice_id=?',[$tenant,$id])->fetch())
                    throw new RuntimeException('این فاکتور قبلاً باطل شده است.');
                if(function_exists('cashbox_assert_payments_mutable'))cashbox_assert_payments_mutable($tenant,(int)$id);
                query('INSERT INTO invoice_voids(invoice_id,company_id,voided_by,reason) VALUES(?,?,?,?)',[$id,$tenant,user()['id'],$reason]);
                audit('invoice.void',json_encode(['invoice_id'=>$id,'reason'=>$reason],JSON_UNESCAPED_UNICODE));
                db()->commit();
            } catch (Throwable $ex) {
                db()->rollBack();
                throw $ex;
            }
            redirect('invoices','فاکتور با حفظ سوابق مالی باطل شد.');
        }
        if ($page==='reports' && $action==='financial_issue_review') {
            financial_issue_review_submit(tenant_id(),(int)user()['id'],
                (string)($_POST['issue_key']??''),(string)($_POST['status']??''),
                trim((string)($_POST['note']??'')),(string)($_POST['date_from']??''),
                (string)($_POST['date_to']??''));
            redirect('reports','بررسی مغایرت ثبت شد؛ سابقه قبلی حفظ شده است.');
        }
        if (in_array($page,['branches','branch_users','bank_accounts'],true)) company_resources_post($page,$action);
        if ($page==='expenses') expense_handle_post($action);
        if ($page==='cashbox') cashbox_handle_post($action);
        if ($page==='periods' && $action==='lock_period') {
            require_permission('periods.manage');
            accounting_lock_period(tenant_id(),(int)user()['id'],
                (string)($_POST['date_from']??''),(string)($_POST['date_to']??''),trim((string)($_POST['reason']??'')));
            redirect('periods','دوره مالی با ثبت دلیل و سابقه قطعی بسته شد.');
        }
        if (in_array($page,['templates','settings','commissions','profile','roles','bills','pos'],true)) handle_module_post($page,$action);
    }
} catch (Throwable $ex) {
    error_log((string)$ex);
    $error = public_operation_error($ex);
}

if ($page === 'companies' && !is_super()) { http_response_code(403); exit('دسترسی مجاز نیست'); }
if ($page === 'companies' && is_super() && isset($_SESSION['selected_company'])) redirect('dashboard','برای مدیریت شرکت‌ها ابتدا به داشبورد مدیریت کل بازگردید.');
if (in_array($page,['branches','branch_users','bank_accounts'],true)) company_resources_guard();
if ($page === 'users') require_admin();
if ($page === 'roles') { require_admin(); require_permission('roles.manage'); }
if ($page === 'services' || $page==='service_rate_history') require_permission('services.view');
if ($page === 'templates' || $page === 'settings') require_admin();
if ($permission=module_page_permission($page)) require_permission($permission);
if ($page === 'customers') require_permission('customers.view');
if ($page==='driver_account') { require_permission('customers.view'); require_permission('invoices.view'); }
if ($page==='invoice_manager') require_permission('invoices.view');
if ($page==='cashbox') require_permission('cashbox.view');
if ($page==='periods'||$page==='finance_health') require_permission('periods.manage');
if ($page==='invoices' && !can('invoices.view') && !can('invoices.create')) { http_response_code(403); exit('دسترسی مجاز نیست'); }
if ($page==='receipt'||$page==='payment_receipt') require_permission('invoices.view');
if ($page==='receipt'||$page==='payment_receipt') require_permission('invoices.print');
if ($page === 'reports' || $page==='finance_center') require_permission('reports.view');
$tenant = null;
if (!in_array($page,['login','companies'],true) && (!is_super() || isset($_SESSION['selected_company']))) {
    $tenant = tenant_id();
}
if($page==='expense_reports'&&$tenant&&isset($_GET['export'])){
    try{expense_center_export((int)$tenant);}catch(Throwable $ex){$error=public_operation_error($ex);}
}
if($page==='expense_attachment'&&$tenant){
    try{expense_serve_attachment((int)$tenant,(int)($_GET['id']??0));}catch(Throwable $ex){http_response_code(404);$error=public_operation_error($ex);}
}
if ($page==='expenses' && ($_GET['export']??'')==='xlsx' && $tenant) {
    try { expense_export((int)$tenant); } catch(Throwable $ex) { error_log((string)$ex); $error=public_operation_error($ex); }
}
$companyName = $tenant ? (query('SELECT name FROM companies WHERE id=?',[$tenant])->fetchColumn() ?: '') : '';
if ($page==='finance_center' && ($_GET['preset_export']??'')==='excel' && $tenant) {
    try { finance_preset_export((int)$tenant,finance_preset_input($_GET)); }
    catch(Throwable $ex) {error_log('Sodoryar finance center export: '.$ex->getCode());$error=public_operation_error($ex);}
}
if ($page==='driver_account' && ($_GET['export']??'')==='excel' && $tenant) {
    try { export_driver_account((int)$tenant,(int)($_GET['id']??0)); }
    catch(Throwable $ex) { error_log('Sodoryar driver account export: '.$ex->getCode()); $error=public_operation_error($ex); }
}
if ($page==='invoice_manager' && ($_GET['export']??'')==='excel' && $tenant) {
    try { export_invoice_management((int)$tenant); }
    catch (Throwable $ex) {
        error_log('Sodoryar invoice manager export: '.$ex->getCode());
        $error=public_operation_error($ex);
    }
}
if (in_array($page,['reports','activity'],true) && $tenant) {
    try {
        $reportFilter=report_filters();
        if (in_array((string)($_GET['export']??''),['excel','payments'],true)) {
            require_permission($page==='reports' ? 'reports.export' : 'activity.export');
            if ($page==='reports' && ($_GET['export']??'')==='payments') {
                if (!invoice_payment_ledger_ready()) throw new RuntimeException('ابتدا میگرشن ۰۱۶ را اجرا کنید.');
                $transactions=invoice_payment_report((int)$tenant,$reportFilter,10001);
                if ((int)$transactions['summary']['transaction_count']>10000) throw new RuntimeException('تعداد تراکنش‌ها بیش از ۱۰٬۰۰۰ است؛ فیلترهای گزارش را محدود کنید.');
                $rows=array_map(static fn($p)=>[
                    display_financial_reference($p['invoice_number']),jalali_datetime($p['paid_at'],true),
                    fa_digits($p['national_code']),$p['customer'],$p['operator_name'],
                    $p['payment_method']==='cash'?'نقدی':'کارتخوان',
                    display_financial_reference($p['tracking_number']??''),display_financial_reference($p['reference_number']??''),
                    (int)$p['amount']
                ],$transactions['rows']);
                export_excel_xlsx('sodoryar-invoice-payments',['شماره فاکتور','زمان دریافت','کد ملی','نام راننده','اپراتور','روش دریافت','پیگیری','مرجع','مبلغ'],$rows,[8]);
            } elseif ($page==='reports') {
                $reportData=invoice_report($tenant,$reportFilter,10000);
                if ((int)$reportData['summary']['qty']>10000) {
                    throw new RuntimeException('تعداد فاکتورهای این خروجی بیش از ۱۰٬۰۰۰ مورد است؛ برای دریافت فایل کامل، بازه تاریخ یا فیلترها را محدود کنید.');
                }
                $rows=array_map(static fn($r)=>[display_financial_reference($r['invoice_number']),jalali_datetime($r['invoice_date'] ?: $r['created_at'],true),fa_digits($r['national_code']),$r['customer'],$r['operator_name'],$r['payment_type']==='cash'?'نقدی':($r['payment_type']==='pos'?'کارتخوان':'ترکیبی'),display_financial_reference($r['pos_ref']),money_toman($r['total_amount'])],$reportData['rows']);
                export_excel_xlsx('sodoryar-invoices',['شماره فاکتور','تاریخ','کد ملی','نام راننده','اپراتور','نوع پرداخت','کد پیگیری','مبلغ'],$rows);
            } else {
                // Fetch one extra record to detect and refuse a silently truncated export.
                $rows=activity_report($tenant,$reportFilter,10001);
                if (count($rows)>10000) {
                    throw new RuntimeException('تعداد سوابق فعالیت بیش از ۱۰٬۰۰۰ ردیف است؛ برای خروجی کامل، فیلتر تاریخ یا کاربر را محدود کنید.');
                }
                export_excel_xlsx('sodoryar-activity',['تاریخ و ساعت','کاربر','عملیات','توضیحات'],array_map(static fn($r)=>[jalali_datetime($r['created_at']),$r['full_name'],$r['action'],$r['details']],$rows));
            }
        }
    } catch (PDOException $ex) {
        error_log('Sodoryar report database failure: '.$ex->getCode());
        $error='دریافت گزارش به دلیل خطای داخلی امکان‌پذیر نیست.';
        $reportFilter=report_filters_default();
    } catch (RuntimeException $ex) {
        $error=$ex->getMessage();
        $reportFilter=report_filters_default();
    }
}
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
if ($page==='login') [$captchaFirst,$captchaSecond]=login_captcha_new();
?><!doctype html>
<html lang="fa" dir="rtl"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><?php if($page!=='login'): ?><meta name="csrf-token" content="<?=e(csrf_token())?>"><?php endif; ?>
<title><?=e($page==='login'?'ورود | برنامه مدیریت خدمات کارگزاریهای تاکسیرانی':'برنامه مدیریت خدمات کارگزاریهای تاکسیرانی | '.$companyName)?></title><meta name="theme-color" content="#26234D"><meta name="application-name" content="صدوریار"><link rel="icon" type="image/svg+xml" href="/favicon.svg"><link rel="alternate icon" type="image/png" href="/assets/sodoryar-logo.png"><link rel="stylesheet" href="assets/app.css?v=<?=e(substr(hash_file('sha256',__DIR__.'/assets/app.css'),0,16))?>"><link rel="stylesheet" href="assets/refinements.css?v=<?=e((string)filemtime(__DIR__.'/assets/refinements.css'))?>"><link rel="stylesheet" href="assets/workspace-v2.css?v=<?=e((string)filemtime(__DIR__.'/assets/workspace-v2.css'))?>"><link rel="stylesheet" href="assets/invoice-redesign.css?v=<?=e(substr(hash_file('sha256',__DIR__.'/assets/invoice-redesign.css'),0,16))?>"><link rel="stylesheet" href="assets/ux-unified.css?v=<?=e(substr(hash_file('sha256',__DIR__.'/assets/ux-unified.css'),0,16))?>"><link rel="stylesheet" href="assets/print-engine.css?v=<?=e(substr(hash_file('sha256',__DIR__.'/assets/print-engine.css'),0,16))?>"><link rel="stylesheet" href="assets/iam-ux.css?v=<?=e(substr(hash_file('sha256',__DIR__.'/assets/iam-ux.css'),0,16))?>"><?php if ($page!=='login'): ?><link rel="stylesheet" href="assets/workspace-refresh.css?v=<?=e(substr(hash_file('sha256',__DIR__.'/assets/workspace-refresh.css'),0,16))?>"><?php endif; ?><?php if ($page==='dashboard'): ?><link rel="stylesheet" href="assets/dashboard-detail.css?v=<?=e(substr(hash_file('sha256',__DIR__.'/assets/dashboard-detail.css'),0,16))?>"><script src="assets/dashboard-detail.js?v=<?=e(substr(hash_file('sha256',__DIR__.'/assets/dashboard-detail.js'),0,16))?>" defer></script><?php endif; ?><?php if ($page==='invoices'): ?><script src="assets/invoice-payments.js?v=<?=e(substr(hash_file('sha256',__DIR__.'/assets/invoice-payments.js'),0,16))?>" defer></script><link rel="stylesheet" href="assets/invoice-wizard.css?v=<?=e(substr(hash_file('sha256',__DIR__.'/assets/invoice-wizard.css'),0,16))?>"><script src="assets/service-rates.js?v=<?=e(substr(hash_file('sha256',__DIR__.'/assets/service-rates.js'),0,16))?>" defer></script><script src="assets/invoice-wizard.js?v=<?=e(substr(hash_file('sha256',__DIR__.'/assets/invoice-wizard.js'),0,16))?>" defer></script><?php endif; ?><?php if ($page==='invoice_manager'): ?><script src="assets/invoice-manager.js?v=<?=e(substr(hash_file('sha256',__DIR__.'/assets/invoice-manager.js'),0,16))?>" defer></script><?php endif; ?><?php if ($page==='login'): ?><link rel="stylesheet" href="assets/login-experience.css?v=<?=e(substr(hash_file('sha256',__DIR__.'/assets/login-experience.css'),0,16))?>"><?php endif; ?><link rel="stylesheet" href="assets/design-system-audit.css?v=<?=e(substr(hash_file('sha256',__DIR__.'/assets/design-system-audit.css'),0,16))?>"><link rel="stylesheet" href="assets/global-search.css?v=<?=e((string)filemtime(__DIR__.'/assets/global-search.css'))?>"><?php if(in_array($page,['expenses','expense_reports'],true)): ?><link rel="stylesheet" href="assets/expenses.css?v=<?=e(substr(hash_file('sha256',__DIR__.'/assets/expenses.css'),0,16))?>"><?php endif; ?></head><body>
<?php if ($page==='login'): ?>
<main class="login-wrap" id="mainContent">
  <div class="login-layout">
    <section class="login-showcase" aria-labelledby="loginIntro">
      <div class="login-showcase-brand">
        <span class="login-showcase-symbol"><img src="assets/sodoryar-logo.png" width="59" height="59" alt=""></span>
        <span><b>صدوریار</b><small>سامانه مدیریت خدمات کارگزاری‌های تاکسیرانی</small></span>
      </div>
      <div class="login-showcase-content">
        <span class="login-eyebrow">راهکار یکپارچه مدیریت کارگزاری</span>
        <h2 id="loginIntro">همه فعالیت‌های کارگزاری، در یک فضای منظم.</h2>
        <p>از ثبت اطلاعات تاکسیرانان و صدور فاکتور تا مدیریت کاربران و گزارش‌های مالی؛ دسترسی به ابزارهای موردنیاز در یک سامانه.</p>
        <div class="login-features" aria-label="قابلیت‌های سامانه">
          <span>صدور و پیگیری فاکتور</span>
          <span>گزارش‌های مالی</span>
          <span>مدیریت کاربران</span>
          <span>تنظیمات اختصاصی شرکت</span>
        </div>
      </div>
      <p class="login-showcase-footer">سامانه اختصاصی کارگزاری‌های تاکسیرانی</p>
    </section>
    <section class="login-card" aria-labelledby="loginTitle">
      <div class="login-form-head">
        <div class="brand-mark"><img src="assets/sodoryar-logo.png" alt="نشان صدوریار" width="52" height="52"></div>
        <span class="login-pill">ورود به پنل کاربری</span>
      </div>
      <h1 id="loginTitle">خوش آمدید</h1>
      <p class="muted">برای ورود، نام کاربری و رمز عبور خود را وارد کنید.</p>
      <?php if(isset($error)): ?><div class="alert error" role="alert"><?=e($error)?></div><?php endif; ?>
      <form method="post" class="stack login-form" autocomplete="on">
        <?=csrf_field()?>
        <label for="loginUsername">نام کاربری
          <span class="login-input-wrap">
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.5-7 8-7s8 2.6 8 7"/></svg>
            <input id="loginUsername" name="username" autocomplete="username" maxlength="100" spellcheck="false" required autofocus value="<?=e(isset($error)?(string)($_POST['username']??''):'')?>" placeholder="نام کاربری">
          </span>
        </label>
        <div class="login-field">
          <label for="loginPassword">رمز عبور</label>
          <span class="login-input-wrap">
            <svg aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>
            <input id="loginPassword" name="password" type="password" autocomplete="current-password" required placeholder="رمز عبور">
            <button type="button" class="login-password-toggle" data-login-password-toggle aria-controls="loginPassword" aria-label="نمایش رمز عبور" aria-pressed="false">نمایش</button>
          </span>
        </div>
        <fieldset class="captcha-fieldset">
          <legend>تأیید امنیتی</legend>
          <p class="captcha-hint">برای تأیید، پاسخ جمع زیر را وارد کنید.</p>
          <div class="captcha-grid">
            <div class="captcha-question" id="captchaQuestion" aria-label="<?=e(fa_digits($captchaFirst).' به علاوه '.fa_digits($captchaSecond))?>">
              <span><?=fa_digits($captchaFirst)?></span><span aria-hidden="true">+</span><span><?=fa_digits($captchaSecond)?></span><span aria-hidden="true">=</span><span aria-hidden="true">؟</span>
            </div>
            <label for="loginCaptcha" class="captcha-answer-label">پاسخ
              <input id="loginCaptcha" name="captcha_answer" inputmode="numeric" autocomplete="off" pattern="[0-9۰-۹٠-٩]{1,2}" maxlength="2" required aria-describedby="captchaQuestion" placeholder="عدد">
            </label>
          </div>
        </fieldset>
        <button class="login-submit" type="submit">
          <span>ورود به سامانه</span>
          <svg aria-hidden="true" width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M5 12h14m-6-6 6 6-6 6"/></svg>
        </button>
      </form>
      <p class="login-security-note"><span aria-hidden="true">●</span> برای امنیت حساب، اطلاعات ورود را در اختیار دیگران قرار ندهید.</p>
    </section>
  </div>
</main>
<?php else: ?>
<div class="app-shell"><a class="skip-link" href="#mainContent">پرش به محتوای اصلی</a><aside class="sidebar" id="siteSidebar" aria-label="ناوبری صدوریار"><div class="brand"><div class="brand-mark"><img src="assets/sodoryar-logo.png" alt="" width="48" height="48"></div><div><strong>صدوریار</strong><small>مدیریت خدمات کارگزاری‌های تاکسیرانی</small></div><button type="button" class="sidebar-collapse-toggle" data-sidebar-collapse aria-label="جمع کردن منوی کناری" aria-expanded="true" aria-controls="primaryNav" title="جمع کردن منو"><svg class="nav-icon" aria-hidden="true"><use href="assets/sidebar-icons.svg#panel-collapse"></use></svg></button></div>
<button type="button" class="sidebar-toggle" aria-expanded="false" aria-controls="primaryNav" data-sidebar-toggle><svg class="nav-icon" aria-hidden="true" focusable="false"><use href="assets/sidebar-icons.svg#menu"></use></svg><span data-sidebar-toggle-label>نمایش فهرست بخش‌ها</span></button>
<button type="button" class="sidebar-search-expand" data-sidebar-search-expand aria-label="جستجو در منوی کناری" title="جستجو در منو"><svg class="nav-icon" aria-hidden="true"><use href="assets/sidebar-icons.svg#search"></use></svg></button>
<div class="sidebar-search"><label for="sidebarNavSearch">جستجوی بخش‌های سامانه</label><div class="sidebar-search-field"><svg class="nav-icon" aria-hidden="true" focusable="false"><use href="assets/sidebar-icons.svg#search"></use></svg><input id="sidebarNavSearch" type="search" placeholder="نام بخش را بنویسید…" autocomplete="off" aria-controls="primaryNav" data-nav-search></div><small data-nav-search-status aria-live="polite"></small></div>
<?php
$navItems=[['dashboard',is_super() && !$tenant?'داشبورد مدیریت کل':'داشبورد شرکت','dashboard','نمای کلی']];
if (is_super() && !$tenant) $navItems[]=['companies','مدیریت شرکت‌ها','companies','مدیریت کل'];
if ($tenant) {
    if (can('customers.view')) $navItems[]=['customers','تاکسیرانان','drivers','عملیات و صدور'];
    if (can('services.view')) $navItems[]=['services','خدمات و تعرفه‌ها','services','عملیات و صدور'];
    if (can('invoices.create')) $navItems[]=['invoices','صدور فاکتور جدید','invoices','عملیات روزانه'];
    if (can('invoices.view')) $navItems[]=['invoice_manager','مدیریت و گزارش فاکتورها','reports','عملیات روزانه'];
    if (can('expenses.view')) { $navItems[]=['expenses','هزینه‌ها','commissions','عملیات روزانه']; $navItems[]=['expense_reports','گزارش هزینه‌ها','reports','گزارش‌ها و نظارت']; }
    if (can('cashbox.view')) $navItems[]=['cashbox','صندوق و تسویه روزانه','commissions','عملیات روزانه'];
    if (can('periods.manage')) $navItems[]=['periods','قفل دوره‌های مالی','reports','گزارش‌ها و نظارت'];
    if (can('periods.manage')) $navItems[]=['finance_health','سلامت ساختار مالی','settings','تنظیمات و پیکربندی'];
    if (can('bills.view')) $navItems[]=['bills','فیش‌ها و قبوض','invoices','عملیات و صدور'];
    if (can('reports.view')) $navItems[]=['reports','گزارش مالی','reports','گزارش‌ها و نظارت'];
    if (can('reports.view')) $navItems[]=['finance_center','مرکز گزارش‌های تخصصی','reports','گزارش‌ها و نظارت'];
    if (can('commissions.view')) $navItems[]=['commissions','پورسانت','commissions','گزارش‌ها و نظارت'];
    if (can('activity.view')) $navItems[]=['activity','لاگ فعالیت‌ها','activity','گزارش‌ها و نظارت'];
    if (user()['role']!=='operator') {
        $navItems[]=['users','کاربران شرکت','users','کاربران و دسترسی'];
        $navItems[]=['branches','مدیریت شعب','companies','تنظیمات و پیکربندی'];
        $navItems[]=['bank_accounts','حساب‌های بانکی','commissions','تنظیمات و پیکربندی'];
        $navItems[]=['branch_users','تخصیص کاربران به شعب','users','کاربران و دسترسی'];
        $navItems[]=['roles','نقش‌ها و مجوزها','users','کاربران و دسترسی'];
        if (can('pos.view')) $navItems[]=['pos','کارتخوان‌ها','services','تنظیمات و پیکربندی'];
        $navItems[]=['templates','قالب چاپ','templates','تنظیمات و پیکربندی'];
        $navItems[]=['settings','تنظیمات شرکت','settings','تنظیمات و پیکربندی'];
    } elseif (can('pos.view')) {
        $navItems[]=['pos','کارتخوان‌ها','services','تنظیمات و پیکربندی'];
    }
}
$navItems[]=['profile','پروفایل و امنیت','profile','حساب کاربری'];
$navCategories=[];
foreach ($navItems as $item) $navCategories[$item[3]][]=$item;
$navItems=array_merge(...array_values($navCategories));
$navGroup=null;
?>
<nav id="primaryNav" aria-label="منوی اصلی صدوریار" data-nav-menu>
<?php foreach($navItems as [$navPage,$navLabel,$navIcon,$navCategory]): ?>
<?php if ($navCategory!==$navGroup): $navGroup=$navCategory; ?><p class="nav-section-label"><?=e($navCategory)?></p><?php endif; ?>
<a class="nav-link<?=($page===$navPage || (($page==='driver_account' && $navPage==='customers') || ($page==='service_rate_history' && $navPage==='services')))?' active':''?>" href="?page=<?=e($navPage)?>" data-nav-category="<?=e($navCategory)?>" <?=($page===$navPage || ($page==='driver_account' && $navPage==='customers'))?'aria-current="page"':''?> title="<?=e($navLabel)?>" aria-label="<?=e($navLabel)?>"><svg class="nav-icon" aria-hidden="true" focusable="false"><use href="assets/sidebar-icons.svg#<?=e($navIcon)?>"></use></svg><span class="nav-link-label"><?=e($navLabel)?></span></a>
<?php endforeach; ?><p class="sidebar-no-results" data-nav-empty hidden>بخشی با این عبارت پیدا نشد.</p></nav>
<div class="sidebar-footer">
<?php if(is_super() && $tenant): ?><form method="post" class="return-global"><?=csrf_field()?><input type="hidden" name="action" value="leave_company"><button class="sidebar-action" type="submit" title="بازگشت به مدیریت کل" aria-label="بازگشت به مدیریت کل"><svg class="nav-icon" aria-hidden="true" focusable="false"><use href="assets/sidebar-icons.svg#back"></use></svg><span>بازگشت به مدیریت کل</span></button></form><?php endif; ?>
<form method="post" class="logout"><?=csrf_field()?><input type="hidden" name="action" value="logout"><button formaction="?page=logout" class="sidebar-action sidebar-logout" title="خروج از حساب" aria-label="خروج از حساب"><svg class="nav-icon" aria-hidden="true" focusable="false"><use href="assets/sidebar-icons.svg#logout"></use></svg><span>خروج از حساب</span></button></form></div></aside>
<?php $activeNavLabel='داشبورد'; foreach($navItems as [$navPage,$navLabel]) { if($navPage===$page || ($page==='driver_account' && $navPage==='customers')){$activeNavLabel=$navLabel;break;} } ?>
<div class="main-wrap"><header class="topbar"><div class="topbar-identity"><img class="topbar-brand-icon" src="assets/sodoryar-logo.png" alt="نشان صدوریار" width="43" height="43"><div class="topbar-title"><small>صدوریار / <?=e($companyName ?: 'مدیریت کل')?></small><strong><?=e($activeNavLabel)?></strong></div></div><?php if($tenant): ?><button type="button" class="global-search-trigger secondary" data-global-search-open aria-keyshortcuts="Control+K" title="جستجوی سراسری (Ctrl+K)">جستجوی سراسری <small>Ctrl+K</small></button><?php endif; ?><a class="user-chip" href="?page=profile"><span class="avatar"><?php if(user_photo_table_ready() && query('SELECT 1 FROM user_photos WHERE user_id=?',[user()['id']])->fetchColumn()): ?><img src="?page=my_photo" alt="تصویر پروفایل" width="38" height="38"><?php else: ?><?=e(mb_substr(user()['full_name'],0,1))?><?php endif; ?></span><span><b><?=e(user()['full_name'])?></b><small><?=e(['superadmin'=>'مدیر کل','company_admin'=>'مدیر شرکت','operator'=>'اپراتور'][user()['role']])?></small></span></a></header>
<?php if($tenant): ?><dialog id="globalSearchDialog" class="app-dialog global-search-dialog" aria-label="جستجوی سراسری">
<div class="global-search-head"><label for="globalSearchInput">جستجو در رانندگان، فاکتورها، دریافت‌ها و خدمات</label><button type="button" class="secondary" data-global-search-close aria-label="بستن">بستن</button></div>
<input id="globalSearchInput" type="search" autocomplete="off" placeholder="حداقل ۳ نویسه؛ برای شماره‌ها حداقل ۶ رقم" aria-controls="globalSearchResults">
<div id="globalSearchResults" class="global-search-results" role="status" aria-live="polite">عبارت مورد نظر را وارد کنید.</div>
</dialog><?php endif; ?>
<main class="content" id="mainContent" tabindex="-1">
<?php if($flash): ?><div class="alert success"><?=e($flash)?></div><?php endif; ?>
<?php if(isset($error)): ?><div class="alert error"><?=e($error)?></div><?php endif; ?>
<?php if(is_super() && !$tenant && !in_array($page,['companies','profile'],true)): ?>
<section class="dashboard-hero global-hero"><div><span class="hero-eyebrow">فرماندهی مجموعه</span><h1>داشبورد مدیریت کل صدوریار</h1><p>نمای تجمیعی شرکت‌ها، کاربران و عملیات مالی، بدون تغییر در استقلال اطلاعات شرکت‌ها</p></div><div class="hero-actions"><a class="hero-button" href="?page=companies">مدیریت شرکت‌ها</a><a class="hero-secondary" href="?page=profile">امنیت حساب مدیر کل</a></div></section>
<?php $globalValid=database_table_exists('invoice_voids')?' WHERE NOT EXISTS(SELECT 1 FROM invoice_voids v WHERE v.invoice_id=invoices.id AND v.company_id=invoices.company_id)':''; $globalToday=$globalValid?str_replace(' WHERE ',' WHERE DATE(created_at)=CURRENT_DATE() AND ', $globalValid):' WHERE DATE(created_at)=CURRENT_DATE()'; $globalStats=query('SELECT (SELECT COUNT(*) FROM companies) companies,(SELECT COUNT(*) FROM companies WHERE is_active=1) active_companies,(SELECT COUNT(*) FROM users WHERE is_active=1 AND company_id IS NOT NULL) active_users,(SELECT COUNT(*) FROM invoices'.$globalValid.') invoices,(SELECT COALESCE(SUM(total_amount),0) FROM invoices'.$globalValid.') revenue,(SELECT COUNT(*) FROM invoices'.$globalToday.') today_invoices')->fetch(); ?>
<div class="stats"><article class="stat"><span>شرکت‌های ثبت‌شده</span><b><?=money($globalStats['companies'])?></b><small><?=money($globalStats['active_companies'])?> شرکت فعال</small></article><article class="stat"><span>کاربران فعال شرکت‌ها</span><b><?=money($globalStats['active_users'])?></b></article><article class="stat"><span>کل فاکتورها</span><b><?=money($globalStats['invoices'])?></b><small><?=money($globalStats['today_invoices'])?> فاکتور امروز</small></article><article class="stat"><span>مجموع مبالغ ثبت‌شده</span><b><?=money($globalStats['revenue'])?></b><small>تومان</small></article></div>
<div class="card"><div class="heading"><div><h2>مدیریت و پایش شرکت‌ها</h2><p class="muted">آمار مالی فقط فاکتورهای معتبر را محاسبه می‌کند.</p></div><a class="button-link" href="?page=companies">تعریف شرکت جدید</a></div><div class="table-wrap"><table><thead><tr><th>نام شرکت</th><th>وضعیت</th><th>کاربران فعال</th><th>تعداد فاکتور معتبر</th><th>درآمد معتبر</th><th>عملیات</th></tr></thead><tbody><?php $companyVoid=database_table_exists('invoice_voids')?' AND NOT EXISTS(SELECT 1 FROM invoice_voids v WHERE v.invoice_id=i.id AND v.company_id=i.company_id)':''; foreach(query('SELECT c.id,c.name,c.is_active,(SELECT COUNT(*) FROM users u WHERE u.company_id=c.id AND u.is_active=1) users_count,(SELECT COUNT(*) FROM invoices i WHERE i.company_id=c.id'.$companyVoid.') invoices_count,(SELECT COALESCE(SUM(i.total_amount),0) FROM invoices i WHERE i.company_id=c.id'.$companyVoid.') revenue FROM companies c ORDER BY c.id DESC LIMIT 100')->fetchAll() as $c): ?><tr><td><?=e($c['name'])?></td><td><span class="status-chip <?=$c['is_active']?'status-good':'status-muted'?>"><?=$c['is_active']?'فعال':'غیرفعال'?></span></td><td><?=money($c['users_count'])?></td><td><?=money($c['invoices_count'])?></td><td><span class="money-value"><?=money_toman($c['revenue'])?></span></td><td><?php if($c['is_active']): ?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="select_company"><input type="hidden" name="company_id" value="<?=e($c['id'])?>"><button class="small">ورود به شرکت</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php elseif($page==='companies'): ?>
<div class="heading"><div><h1>مدیریت شرکت‌ها</h1><p class="muted">هر شرکت پایگاه داده منطقی و دسترسی مستقل دارد.</p></div></div>
<div class="card"><h2>افزودن شرکت و مدیر اولیه</h2><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="add"><label>نام شرکت<input name="name" required></label><label>شناسه انگلیسی<input name="slug" pattern="[a-z0-9-]{3,80}" placeholder="mashhad-taxi" required></label><label>نام مدیر شرکت<input name="manager_name" required></label><label>نام کاربری مدیر<input name="manager_username" pattern="[a-zA-Z0-9_.-]{3,100}" required></label><label>رمز اولیه مدیر<input type="password" minlength="12" autocomplete="new-password" name="manager_password" required></label><button>ثبت شرکت و مدیر</button></form></div>
<div class="card company-defaults-card"><div class="heading"><div><h2>افزودن خدمات پیش‌فرض به شرکت‌های موجود</h2><p class="muted">فقط خدماتی که عنوانشان در شرکت وجود ندارد افزوده می‌شوند؛ مبلغ و وضعیت خدمات قبلی تغییر نمی‌کند. این عملیات را می‌توان دوباره اجرا کرد.</p></div><span class="status-chip status-good">بدون تغییر تعرفه‌های قبلی</span></div><form method="post" class="company-defaults-form"><?=csrf_field()?><input type="hidden" name="action" value="import_default_services"><label>شرکت مقصد<select name="company_id" required><option value="">انتخاب شرکت</option><?php foreach(query('SELECT id,name FROM companies'.(archive_ready('companies')?' WHERE archived_at IS NULL':'').' ORDER BY name')->fetchAll() as $companyOption): ?><option value="<?=e($companyOption['id'])?>"><?=e($companyOption['name'])?></option><?php endforeach; ?></select></label><button type="submit">افزودن خدمات پیش‌فرض</button></form></div>
<?php if(sodoryar_subscription_ready()): ?>
<div class="card"><h2>مدیریت اشتراک شرکت‌ها</h2><p class="muted">اشتراک شرکت‌های قبلی بدون ثبت دوره جدید همچنان فعال می‌ماند. مهلت تمدید سه روز کامل پس از تاریخ پایان است.</p>
<form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="save_subscription">
<label>شرکت<select name="company_id" required><option value="">انتخاب شرکت</option><?php foreach(query('SELECT id,name FROM companies ORDER BY name')->fetchAll() as $sc): ?><option value="<?=e($sc['id'])?>"><?=e($sc['name'])?></option><?php endforeach; ?></select></label>
<label>تاریخ شروع (میلادی، ذخیره‌سازی داخلی)<input type="date" name="start_date" required></label>
<label>تاریخ پایان (میلادی، ذخیره‌سازی داخلی)<input type="date" name="end_date" required></label>
<label>هزینه ماهانه (تومان)<input type="number" name="monthly_fee" min="0" step="1" required></label>
<label>تعداد ماه<input type="number" name="months" min="1" max="120" value="1" required></label>
<label>مبلغ پرداخت‌شده (تومان)<input type="number" name="paid_amount" min="0" step="1" value="0" required></label>
<label>وضعیت<select name="status"><option value="active">فعال</option><option value="suspended">تعلیق دستی</option></select></label>
<button>ثبت یا به‌روزرسانی اشتراک</button></form>
<div class="table-wrap"><table><thead><tr><th>شرکت</th><th>پایان اشتراک</th><th>وضعیت</th><th>مانده (تومان)</th><th>عملیات</th></tr></thead><tbody>
<?php foreach(query('SELECT c.name,s.company_id,s.start_date,s.end_date,s.monthly_fee,s.months,s.total_amount,s.paid_amount,s.status FROM company_subscriptions s JOIN companies c ON c.id=s.company_id ORDER BY s.end_date')->fetchAll() as $sub): $subState=sodoryar_subscription_state($sub,date('Y-m-d')); ?>
<tr><td><?=e($sub['name'])?></td><td><?=e($sub['end_date'])?></td><td><?=e(['active'=>'فعال','grace'=>'مهلت تمدید','expired'=>'منقضی','suspended'=>'تعلیق‌شده','not_started'=>'آغازنشده','invalid'=>'نامعتبر','unassigned'=>'تخصیص‌نیافته'][$subState['state']]??'نامشخص')?></td><td><?=money((int)$sub['total_amount']-(int)$sub['paid_amount'])?></td><td>
<button class="small secondary" type="button" data-dialog-open="subEdit<?=e($sub['company_id'])?>">تمدید / ویرایش</button>
<dialog class="app-dialog" id="subEdit<?=e($sub['company_id'])?>">
<form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="action" value="save_subscription"><input type="hidden" name="company_id" value="<?=e($sub['company_id'])?>">
<h2>اشتراک <?=e($sub['name'])?></h2>
<label>شروع دوره<input type="date" name="start_date" value="<?=e($sub['start_date'])?>" required></label>
<label>پایان دوره<input type="date" name="end_date" value="<?=e($sub['end_date'])?>" required></label>
<label>هزینه ماهانه (تومان)<input type="number" name="monthly_fee" min="0" value="<?=e($sub['monthly_fee'])?>" required></label>
<label>تعداد ماه<input type="number" name="months" min="1" max="120" value="<?=e($sub['months'])?>" required></label>
<label>مبلغ پرداخت‌شده (تومان)<input type="number" name="paid_amount" min="0" value="<?=e($sub['paid_amount'])?>" required></label>
<label>وضعیت<select name="status"><option value="active" <?=$sub['status']==='active'?'selected':''?>>فعال</option><option value="suspended" <?=$sub['status']==='suspended'?'selected':''?>>تعلیق دستی</option></select></label>
<p class="muted">تاریخچه تغییرات قبلی محفوظ می‌ماند.</p>
<div class="dialog-actions"><button type="button" class="secondary" data-dialog-close>انصراف</button><button>ذخیره</button></div>
</form></dialog></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>
<?php if(sodoryar_subscription_ready()): ?>
<div class="card"><div class="heading"><div><h2>وضعیت اشتراک شرکت‌ها</h2><p class="muted">این مبالغ مربوط به اشتراک نرم‌افزار هستند و وارد گردش صندوق شعب نمی‌شوند.</p></div></div>
<?php
$subscriptionSummary=query("SELECT
 COUNT(*) total,
 SUM(CASE WHEN status='suspended' THEN 1 ELSE 0 END) suspended,
 SUM(CASE WHEN status='active' AND end_date>=CURRENT_DATE() AND start_date<=CURRENT_DATE() THEN 1 ELSE 0 END) active_count,
 SUM(CASE WHEN status='active' AND end_date<CURRENT_DATE() AND DATEDIFF(CURRENT_DATE(),end_date) BETWEEN 1 AND 3 THEN 1 ELSE 0 END) grace_count,
 SUM(CASE WHEN status='active' AND DATEDIFF(CURRENT_DATE(),end_date)>3 THEN 1 ELSE 0 END) expired_count,
 COALESCE(SUM(total_amount-paid_amount),0) outstanding
 FROM company_subscriptions")->fetch();
?>
<div class="stats">
<article class="stat"><span>اشتراک‌های ثبت‌شده</span><b><?=money((int)$subscriptionSummary['total'])?></b></article>
<article class="stat"><span>فعال</span><b><?=money((int)$subscriptionSummary['active_count'])?></b></article>
<article class="stat"><span>مهلت تمدید</span><b><?=money((int)$subscriptionSummary['grace_count'])?></b></article>
<article class="stat"><span>منقضی</span><b><?=money((int)$subscriptionSummary['expired_count'])?></b></article>
<article class="stat"><span>تعلیق‌شده</span><b><?=money((int)$subscriptionSummary['suspended'])?></b></article>
<article class="stat"><span>مطالبات ثبت‌شده (تومان)</span><b><?=money((string)$subscriptionSummary['outstanding'])?></b></article>
</div>
<details><summary>آخرین تغییرات و تمدید اشتراک‌ها</summary>
<div class="table-wrap"><table><thead><tr><th>شرکت</th><th>زمان ثبت تغییر</th><th>کاربر ثبت‌کننده</th><th>شروع دوره</th><th>پایان دوره</th><th>هزینه ماهانه</th><th>مبلغ پرداخت‌شده</th><th>وضعیت</th></tr></thead><tbody>
<?php foreach(query('SELECT e.created_at,e.new_snapshot,c.name AS company_name,u.full_name AS actor_name FROM company_subscription_events e JOIN companies c ON c.id=e.company_id LEFT JOIN users u ON u.id=e.actor_id ORDER BY e.id DESC LIMIT 50')->fetchAll() as $event):
$snapshot=json_decode((string)$event['new_snapshot'],true); if(!is_array($snapshot))continue; ?>
<tr><td><?=e($event['company_name'])?></td><td><?=e($event['created_at'])?></td><td><?=e($event['actor_name']??'نامشخص')?></td><td><?=e($snapshot['start_date']??'')?></td><td><?=e($snapshot['end_date']??'')?></td><td><?=money((string)($snapshot['monthly_fee']??'0'))?></td><td><?=money((string)($snapshot['paid_amount']??'0'))?></td><td><?=e($snapshot['status']==='active'?'فعال':'تعلیق‌شده')?></td></tr>
<?php endforeach; ?></tbody></table></div></details>
</div>
<?php endif; ?>
<div class="card"><h2>شرکت‌های ثبت‌شده</h2><p class="muted">حذف شرکت به‌صورت بایگانی انجام می‌شود تا فاکتورها و گزارش‌های مالی آن محفوظ بمانند.</p><div class="table-wrap"><table><thead><tr><th>نام</th><th>شناسه</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody><?php
$companyManagers=[];
foreach(query('SELECT id,company_id,full_name,username,is_active FROM users WHERE role=? ORDER BY company_id,is_active DESC,id ASC',['company_admin'])->fetchAll() as $adminRow) {
    $companyManagers[(int)$adminRow['company_id']]??=$adminRow;
}
foreach(query('SELECT * FROM companies ORDER BY id DESC')->fetchAll() as $c): $archived=archive_ready('companies') && $c['archived_at']!==null; ?><tr><td><?=e($c['name'])?></td><td><?=e($c['slug'])?></td><td><span class="status-chip <?=!$archived && (int)$c['is_active']?'status-good':'status-muted'?>"><?=$archived?'بایگانی‌شده':((int)$c['is_active']?'فعال':'غیرفعال')?></span></td><td><div class="management-actions"><?php if(!$archived): ?><?php if((int)$c['is_active']): ?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="select_company"><input type="hidden" name="company_id" value="<?=e($c['id'])?>"><button class="small">ورود</button></form><?php endif; ?><button class="small secondary" type="button" data-dialog-open="companyEdit<?=e($c['id'])?>">ویرایش</button><button class="small secondary" type="button" data-dialog-open="companyStatus<?=e($c['id'])?>"><?=(int)$c['is_active']?'غیرفعال‌سازی':'فعال‌سازی'?></button><button class="small danger" type="button" data-dialog-open="companyArchive<?=e($c['id'])?>">حذف</button><?php else: ?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="restore_company"><input type="hidden" name="company_id" value="<?=e($c['id'])?>"><button class="small secondary">بازیابی</button></form><?php endif; ?></div>
<?php if(!$archived): $manager=$companyManagers[(int)$c['id']]??false; ?><dialog class="app-dialog" id="companyEdit<?=e($c['id'])?>"><form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="action" value="edit_company"><input type="hidden" name="company_id" value="<?=e($c['id'])?>"><input type="hidden" name="manager_id" value="<?=e($manager['id']??'')?>"><h2>ویرایش شرکت <?=e($c['name'])?></h2><label>نام شرکت<input name="name" value="<?=e($c['name'])?>" maxlength="180" required></label><label>شناسه انگلیسی<input name="slug" value="<?=e($c['slug'])?>" pattern="[a-z0-9-]{3,80}" required></label><fieldset><legend><?= $manager?'اطلاعات حساب مدیر شرکت':'تعریف مدیر اولیه شرکت' ?></legend><div class="stack"><label>نام و نام خانوادگی مدیر شرکت<input name="manager_name" value="<?=e($manager['full_name']??'')?>" maxlength="180" autocomplete="name" required></label><label>نام کاربری مدیر شرکت<input name="manager_username" value="<?=e($manager['username']??'')?>" pattern="[a-zA-Z0-9_.-]{3,100}" autocomplete="off" required></label><label><?= $manager?'رمز عبور جدید مدیر (اختیاری)':'رمز اولیه مدیر (الزامی)' ?><input type="password" name="manager_password" minlength="12" maxlength="4096" autocomplete="new-password" <?=$manager?'':'required'?>></label><p class="muted"><?=$manager?'اگر رمز تغییر نمی‌کند، این کادر را خالی بگذارید.':'رمز اولیه باید حداقل ۱۲ کاراکتر داشته باشد.'?><?php if($manager && !(int)$manager['is_active']): ?> این حساب غیرفعال است؛ برای فعال‌سازی از بخش کاربران شرکت استفاده کنید.<?php endif; ?></p></div></fieldset><div class="dialog-actions"><button type="button" class="secondary" data-dialog-close>انصراف</button><button>ذخیره تغییرات</button></div></form></dialog>
<dialog class="app-dialog" id="companyStatus<?=e($c['id'])?>"><form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="action" value="company_status"><input type="hidden" name="company_id" value="<?=e($c['id'])?>"><h2><?=(int)$c['is_active']?'غیرفعال‌سازی':'فعال‌سازی'?> شرکت</h2><p>آیا از تغییر وضعیت «<?=e($c['name'])?>» مطمئن هستید؟ <?=$c['is_active']?'تا زمان فعال‌سازی مجدد، کاربران شرکت امکان ورود نخواهند داشت.':''?></p><div class="dialog-actions"><button type="button" class="secondary" data-dialog-close>انصراف</button><button>تأیید</button></div></form></dialog>
<dialog class="app-dialog" id="companyArchive<?=e($c['id'])?>"><form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="action" value="archive_company"><input type="hidden" name="company_id" value="<?=e($c['id'])?>"><h2>حذف شرکت</h2><p>شرکت «<?=e($c['name'])?>» بایگانی و دسترسی کاربران آن مسدود می‌شود. اطلاعات و سوابق مالی حذف نمی‌شوند.</p><div class="dialog-actions"><button type="button" class="secondary" data-dialog-close>انصراف</button><button class="danger">حذف و بایگانی</button></div></form></dialog><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php elseif($page==='dashboard'): ?>
<?php if(!is_super() && $tenant && sodoryar_subscription_ready()): $mySubscription=sodoryar_company_entitlement((int)$tenant); if($mySubscription['state']==='grace'): ?>
<div class="card" role="status" aria-live="polite"><strong>یادآوری تمدید اشتراک:</strong> اشتراک شرکت منقضی شده است؛ <?=money((int)$mySubscription['grace_days_left'])?> روز از مهلت تمدید باقی مانده است. برای جلوگیری از مسدودشدن دسترسی با مدیر سامانه تماس بگیرید.</div>
<?php endif; endif; ?>
<section class="dashboard-hero"><div><span class="hero-eyebrow">مرکز کنترل خدمات</span><h1>داشبورد <?=e($companyName ?: 'صدوریار')?></h1><p>نمای یکپارچه فعالیت‌های شرکت، وضعیت خدمات و امور مالی</p></div><div class="hero-actions"><?php if(can('invoices.create')): ?><a class="hero-button" href="?page=invoices">+ صدور فاکتور جدید</a><?php endif; ?><a class="hero-secondary" href="?page=profile">حساب کاربری</a></div></section>
<?php if(!can('reports.view')): ?><div class="card"><h2>خوش آمدید</h2><p class="muted">برای مشاهده گزارش‌های مالی، مدیر شرکت باید دسترسی مربوط را برای حساب شما فعال کند.</p></div><?php else: ?>
<?php $validInvoice=database_table_exists('invoice_voids')?' AND NOT EXISTS(SELECT 1 FROM invoice_voids v WHERE v.invoice_id=invoices.id AND v.company_id=invoices.company_id)':''; $dashboardOwn=document_scope()==='own'; $dashboardScopeSql=$dashboardOwn?' AND user_id=?':''; $dashboardScopeParams=$dashboardOwn?[user()['id']]:[]; $stats=query('SELECT (SELECT COUNT(*) FROM customers WHERE company_id=?) customers,(SELECT COUNT(*) FROM services WHERE company_id=? AND is_active=1) services,(SELECT COUNT(*) FROM invoices WHERE company_id=?'.$dashboardScopeSql.$validInvoice.') invoices,(SELECT COALESCE(SUM(total_amount),0) FROM invoices WHERE company_id=?'.$dashboardScopeSql.$validInvoice.') revenue',array_merge([$tenant,$tenant,$tenant],$dashboardScopeParams,[$tenant],$dashboardScopeParams))->fetch(); ?>
<?php [$todayJy,$todayJm]=gregorian_to_jalali((int)date('Y'),(int)date('n'),(int)date('j')); $jalaliMonthStart=jalali_to_gregorian_date(sprintf('%04d-%02d-01',$todayJy,$todayJm)); $dashboardToday=date('Y-m-d'); $period=query('SELECT COALESCE(SUM(CASE WHEN COALESCE(invoice_date, DATE(created_at))=? THEN 1 ELSE 0 END),0) today_count,COALESCE(SUM(CASE WHEN COALESCE(invoice_date, DATE(created_at)) BETWEEN ? AND ? THEN 1 ELSE 0 END),0) month_count,COALESCE(SUM(CASE WHEN COALESCE(invoice_date, DATE(created_at))=? THEN total_amount ELSE 0 END),0) today_total,COALESCE(SUM(CASE WHEN COALESCE(invoice_date, DATE(created_at)) BETWEEN ? AND ? THEN total_amount ELSE 0 END),0) month_total FROM invoices WHERE company_id=?'.$dashboardScopeSql.$validInvoice,array_merge([$dashboardToday,$jalaliMonthStart,$dashboardToday,$dashboardToday,$jalaliMonthStart,$dashboardToday,$tenant],$dashboardScopeParams))->fetch(); ?>
<div class="stats"><?php
render_dashboard_stat('today_count','خدمات ثبت‌شده امروز',$period['today_count']);
render_dashboard_stat('month_count','خدمات ماه جاری',$period['month_count']);
render_dashboard_stat('today_total','فروش امروز',$period['today_total'],'تومان؛ تاریخ صدور فاکتور');
render_dashboard_stat('month_total','فروش ماه جاری',$period['month_total'],'تومان؛ تاریخ صدور فاکتور');
?></div>
<?php if($tenant && expense_manager()) expense_dashboard((int)$tenant,$jalaliMonthStart,$dashboardToday); ?>
<?php $financeNow=dashboard_financial_snapshot((int)$tenant); ?>
<?php if($financeNow['ledger_ready']): ?>
<div class="stats finance-dashboard-stats"><?php
render_dashboard_stat('receipts_today','کل دریافتی قطعی امروز',$financeNow['receipts_today'],'تومان؛ جمع نقدی و کارتخوان');
render_dashboard_stat('cash_today','دریافت نقدی امروز',$financeNow['cash_today'],'تومان');
render_dashboard_stat('pos_today','دریافت کارتخوان امروز',$financeNow['pos_today'],'تومان؛ ثبت قطعی در سامانه');
render_dashboard_stat('due_total','مانده کل مطالبات',$financeNow['due_total'],'تومان؛ اسناد معتبر');
render_dashboard_stat('unsettled_count','فاکتورهای تسویه‌نشده',$financeNow['unsettled_count']);
render_dashboard_stat('open_cashboxes','صندوق‌های باز',$financeNow['open_cashboxes']);
render_dashboard_stat('pending_cashboxes','صندوق‌های در انتظار تأیید',$financeNow['pending_cashboxes']);
render_dashboard_stat('unreviewed_issues','مغایرت‌های بررسی‌نشده ۳۰ روز اخیر',$financeNow['unreviewed_issues'],'حداکثر ۱۰۰ یافته از هر نوع');
?>
</div>
<?php else: ?><div class="alert">دفتر دریافت‌ها فعال نیست؛ آمار وصول تا تکمیل ساختار مالی قابل نمایش نیست.</div><?php endif; ?>
<dialog class="app-dialog dashboard-detail-dialog" id="dashboardDetail" aria-labelledby="dashboardDetailTitle"><div class="heading"><h2 id="dashboardDetailTitle">جزئیات شاخص</h2><button type="button" class="secondary small" data-dialog-close>بستن</button></div><p id="dashboardDetailStatus" role="status" aria-live="polite"></p><label>جستجو در جزئیات<input type="search" id="dashboardDetailSearch"></label><div class="table-wrap dashboard-detail-scroll"><table><thead id="dashboardDetailHead"></thead><tbody id="dashboardDetailBody"></tbody></table></div><div class="dialog-actions"><button type="button" class="secondary" data-dialog-close>بستن</button></div></dialog>
<div class="dashboard-secondary"><div class="card"><h2>عملکرد کاربران در ماه شمسی جاری</h2><?php $rankingVoid=database_table_exists('invoice_voids')?' AND NOT EXISTS(SELECT 1 FROM invoice_voids v WHERE v.invoice_id=i.id AND v.company_id=i.company_id)':''; $ranking=query('SELECT u.full_name,COUNT(i.id) invoice_count,COALESCE(SUM(i.total_amount),0) total FROM users u LEFT JOIN invoices i ON i.user_id=u.id AND i.company_id=u.company_id AND COALESCE(i.invoice_date, DATE(i.created_at)) BETWEEN ? AND ?'.$rankingVoid.' WHERE u.company_id=? AND u.is_active=1 AND u.role=?'.($dashboardOwn?' AND u.id=?':'').' GROUP BY u.id,u.full_name ORDER BY invoice_count DESC,total DESC LIMIT 8',array_merge([$jalaliMonthStart,$dashboardToday,$tenant,'operator'],$dashboardScopeParams))->fetchAll(); ?><div class="ranking-chart"><?php if (!$ranking): ?><p class="muted">هنوز اپراتور فعالی برای نمایش عملکرد وجود ندارد.</p><?php endif; ?><?php $max=max(1,...array_map(static fn($r)=>(int)$r['invoice_count'],$ranking)); foreach($ranking as $row): ?><div class="ranking-row"><span><?=e($row['full_name'])?></span><div class="ranking-track"><progress max="<?=e((int)$max)?>" value="<?=e((int)$row['invoice_count'])?>" aria-label="تعداد فاکتورهای <?=e($row['full_name'])?>"></progress></div><b><?=money($row['invoice_count'])?></b></div><?php endforeach; ?></div></div><div class="card"><h2>شاخص‌های جاری</h2><dl class="metrics-list"><div><dt>رانندگان در پرونده شرکت</dt><dd><?php if(can('customers.view')): ?><button type="button" class="small secondary" data-dashboard-detail="customers"><?=money($stats['customers'])?></button><?php else: ?><?=money($stats['customers'])?><?php endif; ?></dd></div><div><dt>خدمات فعال</dt><dd><button type="button" class="small secondary" data-dashboard-detail="services"><?=money($stats['services'])?></button></dd></div><div><dt>کل فاکتورهای ثبت‌شده</dt><dd><button type="button" class="small secondary" data-dashboard-detail="invoices"><?=money($stats['invoices'])?></button></dd></div><div><dt>مجموع مبالغ ثبت‌شده</dt><dd><button type="button" class="small secondary" data-dashboard-detail="revenue"><?=money_toman($stats['revenue'])?></button></dd></div></dl></div></div>
<?php if(can('invoices.view')): ?><div class="card"><div class="heading"><h2>آخرین فاکتورها</h2><a href="?page=invoice_manager">مشاهده همه</a></div><?php render_invoices($tenant,8); ?></div><?php endif; ?>
<?php endif; ?>
<?php elseif($page==='invoice_manager'): ?>
<?php render_invoice_management((int)$tenant); ?>
<?php elseif($page==='users'): ?>
<h1>کاربران شرکت</h1><p class="muted">مدیر شرکت به همه بخش‌های شرکت خود دسترسی دارد؛ مجوزهای اپراتورها جداگانه تنظیم می‌شوند.</p>
<?php if(company_roles_ready()): ?><div class="card"><h2>انتساب نقش سفارشی</h2><p class="muted">هر اپراتور می‌تواند یک نقش سفارشی فعال داشته باشد. مجوزهای نقش با مجوزهای مستقیم کاربر ترکیب می‌شوند.</p><div class="table-wrap"><table><thead><tr><th>اپراتور</th><th>نقش فعلی</th><th>نقش جدید</th><th>اعمال</th></tr></thead><tbody>
<?php $assignments=query('SELECT u.id,u.full_name,a.role_id,r.name role_name FROM users u LEFT JOIN user_role_assignments a ON a.user_id=u.id AND a.company_id=u.company_id LEFT JOIN company_roles r ON r.id=a.role_id AND r.company_id=u.company_id WHERE u.company_id=? AND u.role=? AND u.is_active=1 ORDER BY u.full_name',[$tenant,'operator'])->fetchAll(); $availableRoles=query('SELECT id,name FROM company_roles WHERE company_id=? AND is_active=1 ORDER BY name',[$tenant])->fetchAll(); foreach($assignments as $assign): ?>
<tr><td><?=e($assign['full_name'])?></td><td><?=e((string)($assign['role_name']??'بدون نقش'))?></td><td colspan="2"><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="assign_role"><input type="hidden" name="user_id" value="<?=e($assign['id'])?>"><label>نقش<select name="company_role_id"><option value="">بدون نقش سفارشی</option><?php foreach($availableRoles as $role): ?><option value="<?=e($role['id'])?>" <?=(int)$assign['role_id']===(int)$role['id']?'selected':''?>><?=e($role['name'])?></option><?php endforeach; ?></select></label><button class="small">ذخیره نقش</button></form></td></tr><?php endforeach; ?>
<?php if(!$assignments): ?><tr><td colspan="4">اپراتور فعالی برای انتساب نقش وجود ندارد.</td></tr><?php endif; ?></tbody></table></div></div><?php else: ?><div class="alert">برای فعال‌سازی نقش‌های سفارشی، مهاجرت دیتابیس ۰۱۰ را اجرا کنید.</div><?php endif; ?><?php if(document_scopes_ready()): ?><div class="card"><h2>محدوده اسناد مالی اپراتورها</h2><p class="muted">این سیاست بر مشاهده و عملیات فاکتورها، فیش‌ها و گزارش‌های مالی اعمال می‌شود و تغییری در اطلاعات شرکت‌های دیگر ایجاد نمی‌کند.</p><div class="table-wrap"><table><thead><tr><th>اپراتور</th><th>محدوده دسترسی</th><th>ذخیره</th></tr></thead><tbody><?php $scopeUsers=query("SELECT u.id,u.full_name,COALESCE(s.scope,'company') document_scope FROM users u LEFT JOIN user_document_scopes s ON s.user_id=u.id AND s.company_id=u.company_id WHERE u.company_id=? AND u.role='operator' AND u.is_active=1 ORDER BY u.full_name",[$tenant])->fetchAll(); foreach($scopeUsers as $operator): ?><tr><td><?=e($operator['full_name'])?></td><td colspan="2"><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="document_scope"><input type="hidden" name="user_id" value="<?=e($operator['id'])?>"><label>دسترسی به اسناد<select name="document_scope"><option value="company" <?=$operator['document_scope']==='company'?'selected':''?>>تمام اسناد شرکت</option><option value="own" <?=$operator['document_scope']==='own'?'selected':''?>>فقط اسناد ثبت‌شده توسط خود کاربر</option></select></label><button class="small">ذخیره محدوده</button></form></td></tr><?php endforeach; ?><?php if(!$scopeUsers): ?><tr><td colspan="3">اپراتور فعالی وجود ندارد.</td></tr><?php endif; ?></tbody></table></div></div><?php else: ?><div class="alert">برای تعریف محدوده اسناد مالی، مهاجرت دیتابیس ۰۱۱ را اجرا کنید.</div><?php endif; ?>
<div class="card"><h2>ایجاد کاربر</h2><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="add"><label>نام و نام خانوادگی<input name="full_name" required></label><label>نام کاربری<input name="username" pattern="[a-zA-Z0-9_.-]{3,100}" required></label><label>رمز اولیه (حداقل ۱۲ کاراکتر)<input type="password" minlength="12" name="password" autocomplete="new-password" required></label><label>نقش<select name="role"><option value="operator">اپراتور</option><option value="company_admin">مدیر شرکت</option></select></label>
<fieldset class="wide"><legend>اطلاعات تکمیلی کاربر</legend><div class="form-grid"><label>کد ملی<input name="details[national_code]" inputmode="numeric" maxlength="10" pattern="[0-9]{10}"></label><label>تلفن همراه<input name="details[mobile]" type="tel" maxlength="20"></label><label class="wide">آدرس<textarea name="details[address]" rows="2" maxlength="1000"></textarea></label></div></fieldset>
<fieldset class="wide"><legend>دسترسی‌های اختیاری اپراتور</legend><div class="choices"><?php foreach(permission_catalog() as $key=>$title): if(!operator_permission_assignable($key)) continue; ?><label class="choice"><input type="checkbox" name="permissions[]" value="<?=e($key)?>"><?=e($title)?></label><?php endforeach; ?></div></fieldset><button>ایجاد کاربر</button></form></div>
<div class="card"><h2>فهرست کاربران و ویرایش مجوزها</h2><div class="table-wrap"><table><thead><tr><th>کاربر</th><th>نام کاربری</th><th>نقش</th><th>وضعیت</th><th>تصویر</th><th>دسترسی‌های اپراتور</th></tr></thead><tbody><?php
$directPermissionsByUser=[];
foreach(query('SELECT up.user_id,up.permission_key FROM user_permissions up JOIN users u ON u.id=up.user_id WHERE u.company_id=?',[$tenant])->fetchAll() as $permissionRow) {
    $directPermissionsByUser[(int)$permissionRow['user_id']][]=$permissionRow['permission_key'];
}
$userListSQL=user_photo_table_ready()?'SELECT u.id,u.full_name,u.username,u.role,u.is_active,p.storage_key FROM users u LEFT JOIN user_photos p ON p.user_id=u.id WHERE u.company_id=? ORDER BY u.id DESC':'SELECT u.id,u.full_name,u.username,u.role,u.is_active,NULL AS storage_key FROM users u WHERE u.company_id=? ORDER BY u.id DESC'; foreach(query($userListSQL,[$tenant])->fetchAll() as $r): ?><tr><td><div class="table-person"><span class="avatar"><?php if($r['storage_key']): ?><img src="?page=user_photo&amp;id=<?=e($r['id'])?>" alt=""><?php else: ?><?=e(mb_substr($r['full_name'],0,1))?><?php endif; ?></span><?=e($r['full_name'])?></div></td><td><?=e($r['username'])?></td><td><?=e($r['role'])?></td><td><?=$r['is_active']?'فعال':'غیرفعال'?></td><td><?php if(user_photo_table_ready()): ?><form method="post" enctype="multipart/form-data" class="photo-upload"><?=csrf_field()?><input type="hidden" name="action" value="photo"><input type="hidden" name="user_id" value="<?=e($r['id'])?>"><input aria-label="انتخاب عکس <?=e($r['full_name'])?>" name="photo" type="file" accept="image/jpeg,image/png,image/webp" required><button class="small">ذخیره عکس</button></form><?php else: ?><small>نیاز به میگرشن ۰۰۵</small><?php endif; ?></td><td><?php if($r['role']==='operator'): ?><?php $granted=$directPermissionsByUser[(int)$r['id']]??[]; ?><form method="post"><input type="hidden" name="action" value="permissions"><input type="hidden" name="user_id" value="<?=e($r['id'])?>"><?=csrf_field()?><details><summary>انتخاب دسترسی‌ها</summary><div class="permission-list"><?php foreach(permission_catalog() as $key=>$title): if(!operator_permission_assignable($key)) continue; ?><label><input type="checkbox" name="permissions[]" value="<?=e($key)?>" <?=in_array($key,$granted,true)?'checked':''?>><?=e($title)?></label><?php endforeach; ?></div><button class="small">ذخیره</button></details></form><?php else: ?>تمام دسترسی‌های شرکت<?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<div class="card"><h2>مدیریت وضعیت و حذف کاربران</h2><p class="muted">حذف کاربران به شکل بایگانی قابل‌بازیابی است؛ سوابق مالی و فعالیت‌های ثبت‌شده محفوظ می‌ماند.</p><div class="table-wrap"><table><thead><tr><th>نام کاربر</th><th>وضعیت</th><th>عملیات مدیریتی</th></tr></thead><tbody><?php foreach(query('SELECT id,full_name,role,is_active'.(archive_ready('users')?',archived_at':'').' FROM users WHERE company_id=? ORDER BY id DESC',[$tenant])->fetchAll() as $manageable): $archived=archive_ready('users') && $manageable['archived_at']!==null; $self=(int)$manageable['id']===(int)user()['id']; ?><tr><td><?=e($manageable['full_name'])?><?=$self?' (شما)':''?></td><td><span class="status-chip <?=!$archived && $manageable['is_active']?'status-good':'status-muted'?>"><?=$archived?'بایگانی‌شده':($manageable['is_active']?'فعال':'غیرفعال')?></span></td><td><div class="management-actions"><?php if($archived): ?><form method="post"><?=csrf_field()?><input type="hidden" name="action" value="restore_user"><input type="hidden" name="user_id" value="<?=e($manageable['id'])?>"><button class="small secondary">بازیابی</button></form><?php else: ?><button type="button" class="small secondary" data-edit-user-id="<?=e($manageable['id'])?>">ویرایش</button><?php if(!$self): ?><button type="button" class="small secondary" data-dialog-open="userStatus<?=e($manageable['id'])?>"><?=$manageable['is_active']?'غیرفعال‌سازی':'فعال‌سازی'?></button><button type="button" class="small danger" data-dialog-open="userArchive<?=e($manageable['id'])?>">حذف</button><?php endif; ?><?php endif; ?></div>
<?php if(!$archived && !$self): ?><dialog id="userStatus<?=e($manageable['id'])?>" class="app-dialog"><form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="action" value="user_status"><input type="hidden" name="user_id" value="<?=e($manageable['id'])?>"><h2><?=$manageable['is_active']?'غیرفعال‌سازی':'فعال‌سازی'?> کاربر</h2><p>وضعیت حساب «<?=e($manageable['full_name'])?>» تغییر کند؟</p><div class="dialog-actions"><button type="button" class="secondary" data-dialog-close>انصراف</button><button>تأیید</button></div></form></dialog><dialog id="userArchive<?=e($manageable['id'])?>" class="app-dialog"><form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="action" value="archive_user"><input type="hidden" name="user_id" value="<?=e($manageable['id'])?>"><h2>حذف کاربر</h2><p>«<?=e($manageable['full_name'])?>» بایگانی می‌شود؛ امکان ورود نخواهد داشت و فاکتورها حفظ می‌شوند.</p><div class="dialog-actions"><button type="button" class="secondary" data-dialog-close>انصراف</button><button class="danger">تأیید حذف</button></div></form></dialog><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<div class="card"><div class="heading"><div><h2>ویرایش کاربران</h2><p class="muted">تغییر مشخصات، نقش، رمز عبور و وضعیت؛ دسترسی‌های اپراتور از جدول بالا تنظیم می‌شود.</p></div><button type="button" data-dialog-open="userEdit">ویرایش کاربر</button></div></div>
<dialog id="userEdit" class="app-dialog"><form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="action" value="edit"><h2>ویرایش مشخصات کاربر</h2>
<label>جستجوی کاربر<input type="search" id="editUserSearch" placeholder="نام، نام خانوادگی یا نام کاربری…" autocomplete="off" aria-controls="editUserSelect"></label><label>کاربر<select name="user_id" id="editUserSelect" required><option value="">انتخاب کنید</option><?php $detailSelect=database_table_exists('user_profiles')?'SELECT u.id,u.full_name,u.username,u.role,u.is_active,p.national_code,p.mobile,p.address'.(archive_ready('users')?',u.archived_at':'').' FROM users u LEFT JOIN user_profiles p ON p.user_id=u.id WHERE u.company_id=? ORDER BY u.full_name':'SELECT id,full_name,username,role,is_active,NULL national_code,NULL mobile,NULL address'.(archive_ready('users')?',archived_at':'').' FROM users WHERE company_id=? ORDER BY full_name'; foreach(query($detailSelect,[$tenant])->fetchAll() as $u): if(archive_ready('users') && $u['archived_at']!==null) continue; ?><option value="<?=e($u['id'])?>" data-full-name="<?=e($u['full_name'])?>" data-username="<?=e($u['username'])?>" data-role="<?=e($u['role'])?>" data-active="<?=e($u['is_active'])?>" data-national-code="<?=e($u['national_code'])?>" data-mobile="<?=e($u['mobile'])?>" data-address="<?=e($u['address'])?>"><?=e($u['full_name'])?></option><?php endforeach; ?></select></label>
<label>نام و نام خانوادگی<input id="editUserName" name="full_name" maxlength="180" required></label><label>نام کاربری<input id="editUserUsername" name="username" pattern="[a-zA-Z0-9_.-]{3,100}" required></label><label>کد ملی<input id="editUserNational" name="details[national_code]" maxlength="10" inputmode="numeric" pattern="[0-9]{10}"></label><label>تلفن همراه<input id="editUserMobile" name="details[mobile]" type="tel" maxlength="20"></label><label>آدرس<textarea id="editUserAddress" name="details[address]" maxlength="1000" rows="2"></textarea></label>
<label>نقش کاربر<select id="editUserRole" name="role"><option value="operator">اپراتور</option><option value="company_admin">مدیر شرکت</option></select></label><label>رمز عبور جدید (اختیاری)<input type="password" name="new_password" minlength="12" autocomplete="new-password" placeholder="در صورت عدم تغییر، خالی بماند"></label><label class="choice"><input id="editUserActive" type="checkbox" name="is_active" value="1" checked>حساب فعال باشد</label><div class="dialog-actions"><button class="secondary" type="button" data-dialog-close>انصراف</button><button>ثبت تغییرات</button></div></form></dialog>
<?php elseif($page==='customers'): ?>
<?php if(can('customers.create')): ?><div class="card"><h2>فراخوانی از بانک مرکزی تاکسیرانان</h2><p class="muted">کد ملی را وارد کنید تا راننده به پرونده این شرکت متصل شود؛ سوابق مالی شرکت‌های دیگر منتقل یا نمایش داده نمی‌شوند.</p><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="link_global"><label>کد ملی<input name="national_code" data-driver-lookup inputmode="numeric" maxlength="10" pattern="[0-9]{10}" required><small class="lookup-result" data-driver-result role="status" aria-live="polite"></small></label><button>افزودن به پرونده شرکت</button></form></div><?php endif; ?>
<h1>تاکسیرانان</h1><?php if(can('customers.create')): ?><div class="card"><h2>ثبت تاکسیران</h2><form method="post" class="form-grid"><?=csrf_field()?><input type="hidden" name="action" value="add"><label>کد ملی<input name="national_code" inputmode="numeric" pattern="[0-9]{10}" required></label><label>نام<input name="first_name" required></label><label>نام خانوادگی<input name="last_name" required></label><label>تلفن همراه<input name="mobile" inputmode="tel"></label><label>خط فعالیت<input name="line_area"></label><label>کد در خط<input name="line_code"></label><button>ثبت تاکسیران</button></form></div><?php endif; ?>
<div class="card"><h2>فهرست تاکسیرانان</h2><div class="table-wrap"><table><thead><tr><th>کد ملی</th><th>نام</th><th>همراه</th><th>خط</th><th>پرونده مالی</th></tr></thead><tbody><?php foreach(query('SELECT * FROM customers WHERE company_id=? ORDER BY id DESC LIMIT 100',[$tenant])->fetchAll() as $r): ?><tr><td><?=e(fa_digits($r['national_code']))?></td><td><?=e($r['first_name'].' '.$r['last_name'])?></td><td><?=e(fa_digits((string)$r['mobile']))?></td><td><?=e($r['line_area'])?></td><td><?php if(can('invoices.view')): ?><a href="?page=driver_account&amp;id=<?=e($r['id'])?>">مشاهده حساب</a><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php elseif($page==='driver_account'): ?>
<?php render_driver_account((int)$tenant,(int)($_GET['id']??0)); ?>
<?php elseif($page==='services'): ?>
<div class="heading"><h1>تعریف خدمات و تعرفه‌ها</h1><?php if(user()['role']!=='operator'): ?><button type="button" data-dialog-open="serviceCreate">+ خدمت جدید</button><?php endif; ?></div>
<?php if(user()['role']!=='operator'): ?><dialog id="serviceCreate" class="app-dialog"><form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="action" value="add"><h2>ثبت خدمت جدید</h2><label>عنوان خدمت<input name="title" maxlength="200" placeholder="مثلاً هزینه تمدید پروانه تاکسیرانی" required></label><label>مبلغ (تومان)<input name="price" type="text" inputmode="numeric" data-money-input data-currency="تومان" maxlength="22" required></label><div class="dialog-actions"><button type="button" class="secondary" data-dialog-close>انصراف</button><button>ذخیره خدمت</button></div></form></dialog><?php endif; ?>
<div class="card table-wrap"><table><thead><tr><th>شناسه</th><th>عنوان خدمت</th><th>مبلغ</th><th>وضعیت</th><th>عملیات</th></tr></thead><tbody><?php foreach(query('SELECT id,title,price,is_active FROM services WHERE company_id=? ORDER BY id DESC',[$tenant])->fetchAll() as $r): ?><tr><td><?=e($r['id'])?></td><td><?=e($r['title'])?></td><td><span class="money-value"><?=money_toman($r['price'])?></span></td><td><span class="status-chip <?=$r['is_active']?'status-good':'status-muted'?>"><?=$r['is_active']?'فعال':'غیرفعال'?></span></td><td><?php if(user()['role']!=='operator'): ?><button type="button" class="small secondary" data-dialog-open="serviceEdit<?=e($r['id'])?>">ویرایش</button> <a class="small button-link" href="?page=service_rate_history&amp;id=<?=e($r['id'])?>">تاریخچه تعرفه</a><dialog id="serviceEdit<?=e($r['id'])?>" class="app-dialog"><form method="post" class="stack"><?=csrf_field()?><input type="hidden" name="action" value="update"><input type="hidden" name="service_id" value="<?=e($r['id'])?>"><h2>ویرایش خدمت</h2><label>عنوان خدمت<input name="title" maxlength="200" value="<?=e($r['title'])?>" required></label><label>مبلغ (تومان)<input name="price" type="text" inputmode="numeric" data-money-input data-currency="تومان" maxlength="22" value="<?=e($r['price'])?>" required></label><label>شروع اعتبار نرخ جدید (شمسی، اختیاری؛ پیش‌فرض امروز)</label><div class="jalali-field"><input name="effective_from" type="hidden" data-jalali-iso data-jalali-output="jalali"><button type="button" class="secondary" data-jalali-open>انتخاب شروع اعتبار</button></div><label>دلیل تغییر تعرفه<input name="change_reason" maxlength="500" placeholder="در صورت تغییر نرخ یا عنوان، دلیل الزامی است"></label><label class="choice"><input type="checkbox" name="is_active" value="1" <?=$r['is_active']?'checked':''?>>فعال باشد</label><div class="dialog-actions"><button type="button" class="secondary" data-dialog-close>انصراف</button><button>ذخیره تغییرات</button></div></form></dialog><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
<?php elseif($page==='service_rate_history'): ?>
<?php $serviceHistoryId=(int)($_GET['id']??0);$serviceHistory=query('SELECT title FROM services WHERE company_id=? AND id=?',[$tenant,$serviceHistoryId])->fetch(); ?>
<?php if(!$serviceHistory): ?><div class="alert error">این خدمت در شرکت وجود ندارد.</div><?php else: ?><div class="card"><h1>تاریخچه تعرفه خدمت <?=e($serviceHistory['title'])?></h1><?=render_service_rate_history((int)$tenant,$serviceHistoryId)?><p><a href="?page=services">بازگشت به تعرفه‌ها</a></p></div><?php endif; ?>
<?php elseif($page==='invoices'): ?>
<div class="invoice-page-heading invoice-page-toolbar"><div><span class="invoice-eyebrow">عملیات مالی / مدیریت فاکتورها</span><h1>فاکتورها</h1><p>صدور سند جدید و دسترسی به سوابق مالی تاکسیرانان</p></div><div class="invoice-page-actions"><?php if(can('invoices.view')): ?><a class="button-link secondary" href="?page=invoice_manager">جستجو و گزارش فاکتورها</a><?php endif; ?><?php if(can('invoices.create')): ?><button type="button" id="invoiceWizardOpen" aria-haspopup="dialog" aria-controls="invoiceWizard"><span aria-hidden="true">＋</span> صدور فاکتور جدید</button><?php endif; ?></div></div>
<?php if(can('invoices.create')): ?>
<?php $invoiceDriverLines=query("SELECT DISTINCT TRIM(line_area) AS line_name FROM global_drivers WHERE line_area IS NOT NULL AND TRIM(line_area) <> '' ORDER BY line_name")->fetchAll(PDO::FETCH_COLUMN); ?>
<dialog id="invoiceWizard" class="invoice-wizard" aria-labelledby="invoiceWizardTitle"<?=!empty($_SESSION['invoice_start_next']) ? ' data-start-next="1"' : ''?>><?php unset($_SESSION['invoice_start_next']); ?><div class="invoice-workspace">
<form method="post" class="stack invoice-form" id="invoiceCreateForm" data-invoice-ui-version="invoice-wizard-v2" novalidate><header class="invoice-wizard-header"><div class="invoice-wizard-title"><h2 id="invoiceWizardTitle">صدور فاکتور جدید</h2><p data-wizard-status tabindex="-1" aria-live="polite"></p></div><ol class="invoice-wizard-steps" aria-label="مراحل صدور فاکتور"><li><span>۱</span><b>تاکسیران</b></li><li><span>۲</span><b>خدمات</b></li><li><span>۳</span><b>پرداخت</b></li><li><span>۴</span><b>تأیید نهایی</b></li></ol></header><div class="invoice-wizard-body"><input type="hidden" name="invoice_after_save" value="list"><?=csrf_field()?><input type="hidden" name="action" value="add"><input type="hidden" name="invoice_create_nonce" value="<?=e($_SESSION['invoice_create_nonce'] ??= bin2hex(random_bytes(16)))?>">
<section data-wizard-stage="1" class="invoice-panel invoice-panel-details" aria-labelledby="invoiceDetailsTitle">
<?php if(branches_ready()): $branchOptions=branch_choices($tenant,(int)user()['id']); ?><label for="invoiceBranch">شعبه<select id="invoiceBranch" name="branch_id" required><option value="">انتخاب شعبه</option><?php foreach($branchOptions as $branch): ?><option value="<?=e($branch['id'])?>" <?=count($branchOptions)===1 || (int)$branch['is_default']===1?'selected':''?>><?=e($branch['name'])?></option><?php endforeach; ?></select></label><?php endif; ?>
<div class="invoice-panel-heading"><span class="invoice-step">۱</span><div><h2 id="invoiceDetailsTitle">اطلاعات فاکتور</h2><p>شناسه تاکسیران و تاریخ انجام خدمت</p></div></div>
<div class="invoice-details-grid">
<div class="invoice-driver-wrap"><label class="invoice-driver-field" for="invoiceDriverNational">کد ملی تاکسیران<input id="invoiceDriverNational" name="driver_national_code" data-driver-lookup data-driver-autocomplete inputmode="numeric" pattern="[0-9]{10}" maxlength="10" placeholder="از رقم ششم جستجو می‌شود" autocomplete="off" aria-autocomplete="list" aria-expanded="false" aria-controls="invoiceDriverSuggestions" aria-describedby="invoiceDriverStatus" required autofocus><small id="invoiceDriverStatus" class="lookup-result" data-driver-result role="status" aria-live="polite">با ورود حداقل ۶ رقم، رانندگان مشابه نمایش داده می‌شوند.</small></label><div id="invoiceDriverSuggestions" class="invoice-driver-suggestions" data-driver-suggestions role="listbox" aria-label="رانندگان مشابه" hidden></div></div>
<div class="invoice-date-wrap"><label id="invoiceDateLabel">تاریخ فاکتور <span class="invoice-required" aria-hidden="true">*</span></label><div class="jalali-field invoice-date-picker" role="group" aria-labelledby="invoiceDateLabel"><input type="hidden" name="invoice_date" value="<?=e((new DateTimeImmutable('now',new DateTimeZone('Asia/Tehran')))->format('Y-m-d'))?>" data-jalali-iso required><button type="button" class="secondary jalali-picker-button" data-jalali-open aria-label="انتخاب تاریخ فاکتور از تقویم شمسی"></button><button type="button" class="secondary invoice-date-today" data-jalali-today>امروز</button></div><small class="invoice-help">پیش‌فرض: امروز، قابل تغییر از تقویم شمسی</small><small id="invoiceRateStatus" role="status" aria-live="polite"></small></div>

</div></section><dialog id="invoiceDriverCreate" class="app-dialog invoice-driver-dialog" data-driver-manual aria-labelledby="invoiceDriverDialogTitle"><div class="invoice-driver-dialog-heading"><h2 id="invoiceDriverDialogTitle">ثبت راننده جدید</h2><p>این کد ملی در بانک مرکزی رانندگان موجود نیست. مشخصات را کامل کنید؛ اطلاعات هنگام ثبت نهایی فاکتور ذخیره می‌شود.</p></div><p class="invoice-driver-code">کد ملی: <strong data-driver-new-national></strong></p><div class="form-grid"><label>نام <span class="invoice-required">*</span><input name="new_driver[first_name]" maxlength="100" data-driver-required autocomplete="off"></label><label>نام خانوادگی <span class="invoice-required">*</span><input name="new_driver[last_name]" maxlength="100" data-driver-required autocomplete="off"></label><label>شماره همراه <span class="invoice-required" aria-hidden="true">*</span><input name="new_driver[mobile]" type="tel" maxlength="15" inputmode="tel" autocomplete="tel" placeholder="۰۹۱۲۳۴۵۶۷۸۹" data-driver-required data-driver-mobile></label><div class="invoice-driver-line-field"><label for="invoiceDriverLineSearch">خط فعالیت <small>(اختیاری)</small></label><input id="invoiceDriverLineSearch" type="search" data-driver-line-search placeholder="جستجوی نام خط…" autocomplete="off" aria-controls="invoiceDriverLineSelect"><select id="invoiceDriverLineSelect" name="new_driver[line_area]" data-driver-line-select aria-label="انتخاب خط فعالیت"><option value="">بدون انتخاب خط</option><?php foreach($invoiceDriverLines as $lineName): ?><option value="<?=e((string)$lineName)?>"><?=e((string)$lineName)?></option><?php endforeach; ?></select><small class="invoice-driver-line-help">فهرست از بانک مرکزی رانندگان دریافت شده است.</small></div></div><div class="dialog-actions"><button class="secondary" type="button" data-driver-dialog-cancel>انصراف</button><button type="button" data-driver-dialog-confirm>تأیید اطلاعات راننده</button></div></dialog>
<fieldset data-wizard-stage="2" class="invoice-services invoice-panel" data-invoice-services><legend><span class="invoice-step">۲</span> انتخاب خدمات ارائه‌شده</legend><div class="invoice-service-toolbar"><label for="invoiceServiceSearch" class="invoice-search-label">جستجوی خدمت<input id="invoiceServiceSearch" type="search" placeholder="بخشی از عنوان خدمت را بنویسید…" autocomplete="off" aria-controls="invoiceServiceList" aria-describedby="invoiceServiceCount" data-service-search></label><button type="button" class="secondary invoice-clear-search" data-service-clear hidden>پاک کردن جستجو</button></div><div class="invoice-service-summary"><div class="invoice-service-meta"><span id="invoiceServiceCount" role="status" aria-live="polite" data-service-count></span><strong data-service-selected>۰ خدمت انتخاب شده</strong></div><div class="invoice-total-box" role="status" aria-live="polite" aria-atomic="true"><span>جمع مبلغ خدمات انتخاب‌شده</span><div class="invoice-total-amount"><strong data-service-total>۰</strong><span>تومان</span></div></div></div><div id="invoiceServiceList" class="invoice-service-list"><?php foreach(effective_service_rows((int)$tenant,date('Y-m-d')) as $r): ?><label class="invoice-service-option" data-service-option data-service-title="<?=e($r['title'])?>"><input type="checkbox" name="service_ids[]" value="<?=e($r['id'])?>" data-service-price="<?=e($r['price'])?>"><span class="invoice-service-title"><?=e($r['title'])?></span><strong class="invoice-service-price"><?=money($r['price'])?> <small>تومان</small></strong></label><?php endforeach; ?></div><p class="invoice-service-empty" data-service-empty hidden>خدمتی با این عبارت پیدا نشد. عبارت دیگری جستجو کنید.</p><p class="muted invoice-service-help">با Tab بین جستجو و خدمات جابه‌جا شوید و با Space خدمت را انتخاب کنید. انتخاب‌ها با جستجو حذف نمی‌شوند.</p></fieldset>
<fieldset data-wizard-stage="2" class="invoice-panel invoice-extras"><legend>خدمات موردی (اختیاری)</legend><p class="muted">برای خدمات موردی، عنوان و مبلغ را وارد کنید؛ این آیتم‌ها به تعرفه‌های ثابت شرکت افزوده نمی‌شوند.</p><div id="extraInvoiceItems" class="stack"></div><button class="secondary" type="button" data-add-invoice-item>+ افزودن آیتم دیگر</button></fieldset>
<section data-wizard-stage="3" class="invoice-panel invoice-multipayment" id="invoicePaymentPanel">
<div class="invoice-panel-heading"><span class="invoice-step">۳</span><div><h2>جزئیات پرداخت فاکتور</h2><p>یک پرداخت یا چند تراکنش نقدی و کارتخوان</p></div></div>
<div class="payment-mode-group" role="group" aria-label="نوع پرداخت"><button type="button" class="secondary" data-payment-mode="cash" aria-pressed="true">نقدی</button><button type="button" class="secondary" data-payment-mode="pos" aria-pressed="false">کارتخوان</button><button type="button" class="secondary" data-payment-mode="multi" aria-pressed="false">ترکیبی / چندتراکنشی</button></div>
<select id="invoiceTerminalOptions" hidden aria-hidden="true" data-branch-scoped="<?=branches_ready()?'1':'0'?>"><option value="">انتخاب دستگاه</option><?php
$invoiceTerminals=branches_ready()
    ?query('SELECT DISTINCT p.id,p.brand,a.branch_id FROM pos_settings p JOIN pos_branch_assignments a ON a.terminal_id=p.id AND a.company_id=p.company_id JOIN branches b ON b.id=a.branch_id AND b.company_id=a.company_id WHERE p.company_id=? AND p.is_active=1 AND b.is_active=1 AND a.effective_from<=NOW() AND (a.effective_until IS NULL OR a.effective_until>NOW()) ORDER BY p.brand,p.id,a.branch_id',[$tenant])->fetchAll()
    :query('SELECT id,brand FROM pos_settings WHERE company_id=? AND is_active=1 ORDER BY brand,id',[$tenant])->fetchAll();
$invoiceAllowedBranches=branches_ready()?array_map('intval',array_column($branchOptions,'id')):[];
foreach($invoiceTerminals as $terminal): if(branches_ready()&&!in_array((int)$terminal['branch_id'],$invoiceAllowedBranches,true))continue; ?><option value="<?=e($terminal['id'])?>" data-branch="<?=e($terminal['branch_id']??'')?>"><?=e($terminal['brand'])?> (<?=e(fa_digits($terminal['id']))?>)</option><?php endforeach; ?></select>
<div id="invoicePaymentRows" class="invoice-payment-rows"></div><button type="button" class="secondary" id="invoiceAddPayment" hidden>+ افزودن پرداخت</button><label class="choice"><input type="checkbox" name="allow_partial" value="1" id="invoiceAllowPartial"> صدور فاکتور با مانده بدهی (پرداخت جزئی)</label><label class="choice"><input type="checkbox" id="invoiceNoInitialPayment"> صدور بدون دریافت اولیه</label>
<div class="invoice-payment-summary" aria-live="polite"><span>مبلغ فاکتور: <strong data-payable-total>۰</strong></span><span>جمع دریافتی: <strong data-payment-paid>۰</strong></span><span>مانده: <strong data-payment-remaining>۰</strong></span><span>تعداد تراکنش: <strong data-payment-count>۱</strong></span></div><p id="invoicePaymentError" class="invoice-payment-error" role="alert" hidden></p></section>
<section data-wizard-stage="4" class="invoice-panel" hidden><h2>بررسی نهایی فاکتور</h2><div data-wizard-review></div></section></div><p data-wizard-error role="alert" class="invoice-wizard-error"></p><div class="invoice-form-footer"><div class="invoice-footer-summary"><span>مبلغ فاکتور (تومان)</span><strong data-wizard-total>۰</strong></div><div class="invoice-footer-actions"><button type="button" class="secondary" data-wizard-cancel>انصراف</button><button type="button" class="secondary" data-wizard-prev hidden>مرحله قبل</button><button type="button" data-wizard-next>مرحله بعد</button><button type="submit" class="invoice-submit" hidden>ذخیره فاکتور</button><?php if(can('invoices.view') && can('invoices.print')): ?><button type="submit" data-print="1" hidden>ذخیره و چاپ</button><?php endif; ?><button type="submit" data-save-next="1" hidden>ذخیره و ثبت سند جدید</button></div></div></form></div></dialog>
<?php endif; ?><?php if(can('invoices.view')): ?><div class="card invoice-history"><div class="invoice-history-heading"><div><h2>آخرین فاکتورها</h2><p>سوابق صدور، دریافت و مانده حساب</p></div><a href="?page=invoice_manager">مشاهده همه</a></div><?php render_invoices($tenant,50); ?></div><?php endif; ?>
<?php elseif($page==='finance_center'): ?>
<?php render_finance_presets((int)$tenant); ?>
<?php elseif($page==='reports'): ?>
<h1>گزارش‌گیری پیشرفته</h1><p class="muted">فیلتر براساس تاریخ، کاربر، نوع پرداخت، شماره فاکتور و کد ملی راننده</p>
<?php render_report_filters('reports',$reportFilter); $reportData=invoice_report($tenant,$reportFilter); ?>
<div class="stats"><article class="stat"><span>تعداد فاکتورهای این گزارش</span><b><?=money($reportData['summary']['qty'])?></b></article><article class="stat"><span>مجموع مبالغ این گزارش</span><b><?=money($reportData['summary']['total'])?></b><small>تومان</small></article>
<?php if(isset($reportData['summary']['cash_paid'])): ?>
<article class="stat"><span>دریافتی نقدی فاکتورهای منتخب</span><b><?=money($reportData['summary']['cash_paid'])?></b><small>تومان</small></article>
<article class="stat"><span>دریافتی کارتخوان فاکتورهای منتخب</span><b><?=money($reportData['summary']['pos_paid'])?></b><small>تومان</small></article>
<article class="stat"><span>تعداد تراکنش‌ها</span><b><?=money($reportData['summary']['transaction_count'])?></b></article>
<?php endif; ?></div>

<?php if(invoice_payment_ledger_ready()): ?>
<?php $controlIssues=financial_control_exceptions((int)$tenant,$reportFilter); ?>
<?php $issueReviews=financial_issue_latest_reviews((int)$tenant,$controlIssues); ?>
<section class="card"><div class="heading"><div><h2>کنترل مغایرت‌های مالی</h2>
<p class="muted">موارد نیازمند بررسی داخلی؛ رسیدگی به مغایرت‌ها شرط ثبت قطعی دریافت نیست. در صورت انتخاب‌نکردن بازه، ۳۰ روز اخیر بررسی می‌شود.</p></div></div>
<div class="table-wrap"><table><thead><tr><th>نوع مغایرت</th><th>شماره فاکتور</th><th>جزئیات</th><th>رسیدگی</th></tr></thead><tbody>
<?php foreach($controlIssues as $issue): ?>
<?php $issueKey=financial_issue_fingerprint((int)$tenant,$issue);$issueReview=$issueReviews[$issueKey]??null; ?>
<tr><td><?=e($issue['kind'])?></td><td><?=e(display_financial_reference($issue['invoice']))?></td><td><?=e($issue['detail'])?></td>
<td><?php if($issueReview): ?><p><?=e(['investigating'=>'در حال بررسی','resolved'=>'ثبت بررسی و رفع','reopened'=>'بازگشایی‌شده'][$issueReview['status']]??'نامشخص')?></p><small><?=e($issueReview['note'])?></small><?php else: ?>بررسی‌نشده<?php endif; ?>
<?php if(can('finance.reconcile') && financial_issue_reviews_ready()): ?>
<details><summary>ثبت نتیجه بررسی</summary><form method="post" class="stack"><?=csrf_field()?>
<input type="hidden" name="action" value="financial_issue_review">
<input type="hidden" name="issue_key" value="<?=e($issueKey)?>">
<input type="hidden" name="date_from" value="<?=e($reportFilter['date_from'])?>">
<input type="hidden" name="date_to" value="<?=e($reportFilter['date_to'])?>">
<label>وضعیت<select name="status"><option value="investigating">در حال بررسی</option><option value="resolved">رفع‌شده، با ثبت مستندات</option><option value="reopened">بازگشایی</option></select></label>
<label>شرح و مستندات بررسی<textarea name="note" minlength="5" maxlength="500" required></textarea></label>
<button type="submit">ثبت نتیجه</button></form></details>
<?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$controlIssues): ?><tr><td colspan="4">در بازه بررسی‌شده مغایرتی از انواع تحت پوشش شناسایی نشد.</td></tr><?php endif; ?>
</tbody></table></div></section>
<?php endif; ?>
<?php $paymentReport=invoice_payment_report((int)$tenant,$reportFilter); ?>
<?php if(invoice_payment_ledger_ready()): ?>
<section class="card"><div class="heading"><div><h2>ریز تراکنش‌های دریافت</h2><p class="muted">مبنای این جدول زمان واقعی دریافت است؛ برای جلوگیری از دوباره‌شماری، هر تراکنش به‌صورت مستقل محاسبه می‌شود.</p></div></div>
<div class="stats"><article class="stat"><span>تعداد تراکنش‌ها</span><b><?=money($paymentReport['summary']['transaction_count'])?></b></article><article class="stat"><span>نقدی</span><b><?=money($paymentReport['summary']['cash_paid'])?></b><small>تومان</small></article><article class="stat"><span>کارتخوان</span><b><?=money($paymentReport['summary']['pos_paid'])?></b><small>تومان</small></article></div>
<?php if(can('reports.export')): ?><a class="button-link" href="?page=reports&amp;export=payments&amp;<?=e(http_build_query(array_filter($reportFilter,static fn($v)=>$v!=='')))?>">خروجی اکسل ریز تراکنش‌ها</a><?php endif; ?>
<div class="table-wrap"><table><thead><tr><th>شماره فاکتور</th><th>زمان دریافت</th><th>روش</th><th>مبلغ</th><th>کد پیگیری</th><th>اپراتور</th></tr></thead><tbody>
<?php foreach($paymentReport['rows'] as $transaction): ?><tr><td><?=e(display_financial_reference($transaction['invoice_number']))?></td><td><?=e(jalali_datetime($transaction['paid_at'],true))?></td><td><?=$transaction['payment_method']==='cash'?'نقدی':'کارتخوان'?></td><td><?=money_toman($transaction['amount'])?></td><td><?=e(display_financial_reference($transaction['tracking_number']??''))?></td><td><?=e($transaction['operator_name'])?></td></tr><?php endforeach; ?>
<?php if (!$paymentReport['rows']): ?><tr><td colspan="6">تراکنشی در این بازه وجود ندارد.</td></tr><?php endif; ?></tbody></table></div></section>
<?php else: ?><div class="alert">گزارش ریز تراکنش‌ها پس از اجرای میگرشن ۰۱۶ فعال می‌شود.</div><?php endif; ?>
<div class="card"><h2>نتایج گزارش (۲۰۰ مورد آخر)</h2><?php render_table(['شماره فاکتور','تاریخ','کد ملی','مشتری','اپراتور','نوع پرداخت','کد پیگیری','مبلغ'],array_map(static fn($r)=>['invoice_number'=>$r['invoice_number'],'created_at'=>$r['invoice_date'] ?: $r['created_at'],'national_code'=>$r['national_code'],'customer'=>$r['customer'],'operator_name'=>$r['operator_name'],'payment_type'=>$r['payment_type']==='cash'?'نقدی':($r['payment_type']==='pos'?'کارتخوان':'ترکیبی'),'pos_ref'=>$r['pos_ref'],'total_amount'=>$r['total_amount']],$reportData['rows']),['total_amount']); ?></div>
<?php elseif($page==='payment_receipt'): ?>
<?php render_individual_payment_receipt((int)$tenant,(int)($_GET['id']??0)); ?>
<?php elseif($page==='receipt'): ?>
<?php
$invoice=query('SELECT i.*,c.first_name,c.last_name,c.national_code FROM invoices i JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id WHERE i.id=? AND i.company_id=?',[(int)($_GET['id']??0),$tenant])->fetch();
if ($invoice) require_document_owner((int)$invoice['user_id']);
$selectedTemplate=query('SELECT id,title,paper_type,header_text,footer_text,logo_path,layout_json FROM print_templates WHERE company_id=? ORDER BY is_default DESC,id DESC LIMIT 1',[$tenant])->fetch();
$templateStyle=$selectedTemplate?json_decode((string)$selectedTemplate['layout_json'],true):[];
$companyPrintDetails=query("SELECT setting_key,setting_value FROM app_settings WHERE company_id=? AND setting_key IN ('company_phone','company_address')",[$tenant])->fetchAll(PDO::FETCH_KEY_PAIR);
$rollWidth=(int)($templateStyle['roll_width_mm']??72);
$rollWidth=max(56,min(76,$rollWidth));
?>
<?php if(!$invoice): ?><div class="alert error">فاکتور پیدا نشد.</div><?php else: ?>
<?php
$receiptSections=['header','invoice_number','date','customer','items','total','payment','footer'];
$configuredOrder=$templateStyle['order']??null;
if (is_array($configuredOrder) && count($configuredOrder)===count($receiptSections) && count(array_diff($receiptSections,$configuredOrder))===0 && count(array_unique($configuredOrder))===count($receiptSections)) $receiptSections=$configuredOrder;
$voidRecord=database_table_exists('invoice_voids')?query('SELECT reason,voided_at FROM invoice_voids WHERE invoice_id=? AND company_id=?',[$invoice['id'],$tenant])->fetch():false;
?>
<div class="receipt card <?=$voidRecord?'receipt-voided':''?> <?=($selectedTemplate && $selectedTemplate['paper_type']==='thermal80')?'receipt-thermal':(($selectedTemplate && $selectedTemplate['paper_type']==='a5')?'receipt-a5':'receipt-a4')?> receipt-roll-<?=e((string)$rollWidth)?> <?=($templateStyle['font']??'')==='Tahoma'?'receipt-font-tahoma':'receipt-font-vazirmatn'?>">
<div class="receipt-toolbar"><button type="button" data-print-receipt>چاپ فاکتور</button></div><script src="assets/invoice-auto-print.js?v=1" defer></script>
<?php if($voidRecord): ?><div class="receipt-void-label" role="status">نسخه باطل‌شده — فاقد اعتبار مالی</div><div class="alert error"><strong>فاکتور باطل شده است؛ برای ارائه به مشتری معتبر نیست.</strong></div><?php endif; ?>
<?php foreach($receiptSections as $section): ?>
<?php if($section==='header'): ?><?php if($selectedTemplate && $selectedTemplate['logo_path']): ?><img class="receipt-logo" src="?page=template_logo&amp;id=<?=e($selectedTemplate['id'])?>" alt="لوگوی شرکت"><?php endif; ?><h1><?=e($selectedTemplate['header_text']??'فاکتور خدمات')?></h1><p><?=e($companyName)?></p><?php if(!empty($companyPrintDetails['company_phone'])): ?><p class="receipt-contact">تلفن: <?=e(fa_digits($companyPrintDetails['company_phone']))?></p><?php endif; ?><?php if(!empty($companyPrintDetails['company_address'])): ?><p class="receipt-contact">نشانی: <?=e($companyPrintDetails['company_address'])?></p><?php endif; ?>
<?php elseif($section==='invoice_number'): ?><p>شماره فاکتور: <?=e(display_financial_reference($invoice['invoice_number']))?></p>
<?php elseif($section==='date'): ?><p>تاریخ فاکتور: <?=e(jalali_datetime(($invoice['invoice_date']??null) ?: $invoice['created_at'],true))?></p>
<?php elseif($section==='customer'): ?><p>تاکسیران: <?=e($invoice['first_name'].' '.$invoice['last_name'])?> | کد ملی: <?=e(fa_digits($invoice['national_code']))?></p>
<?php elseif($section==='items'): ?><table><thead><tr><th>خدمت</th><th>مبلغ</th></tr></thead><tbody><?php foreach(query('SELECT service_title,price FROM invoice_items WHERE invoice_id=? AND company_id=?',[$invoice['id'],$tenant])->fetchAll() as $i): ?><tr><td><?=e($i['service_title'])?></td><td><?=money_toman($i['price'])?></td></tr><?php endforeach; ?></tbody></table>
<?php elseif($section==='total'): ?><h2>جمع کل: <?=money_toman($invoice['total_amount'])?></h2><p class="receipt-amount-words">مبلغ به حروف: <?=e(toman_in_words($invoice['total_amount']))?></p>
<?php elseif($section==='payment'): ?>
<?php $paymentSummary=invoice_payment_totals((int)$tenant,(int)$invoice['id'],(int)$invoice['total_amount']); ?>
<h3>جزئیات پرداخت</h3><table><thead><tr><th>روش پرداخت</th><th>مبلغ</th><th>رسید</th></tr></thead><tbody>
<?php foreach($paymentSummary['payments'] as $pay): ?><tr><td><?=$pay['payment_method']==='cash'?'نقدی':'کارتخوان'?><?php if($pay['tracking_number']): ?> — <?=e(display_financial_reference($pay['tracking_number']))?><?php endif; ?></td><td><?=money_toman($pay['amount'])?></td><td><a href="?page=payment_receipt&amp;id=<?=e($pay['id'])?>">چاپ رسید</a></td></tr><?php endforeach; ?>
<?php if (!$paymentSummary['payments'] && !invoice_payment_ledger_ready()): ?><tr><td><?=$invoice['payment_type']==='cash'?'نقدی':'کارتخوان'?></td><td><?=money_toman($invoice['total_amount'])?></td></tr><?php elseif (!$paymentSummary['payments']): ?><tr><td colspan="3">هنوز مبلغی دریافت نشده است.</td></tr><?php endif; ?>
</tbody></table><p>جمع دریافتی: <?=money_toman(invoice_payment_ledger_ready()?$paymentSummary['paid']:(int)$invoice['total_amount'])?></p><p>مانده: <?=money_toman(invoice_payment_ledger_ready()?$paymentSummary['remaining']:0)?></p>
<p class="receipt-payment-note">دریافت‌های معتبر ثبت‌شده در سامانه قطعی هستند و نیازی به تأیید جداگانه ندارند.</p>
<?php elseif($section==='footer' && $selectedTemplate && $selectedTemplate['footer_text']!==''): ?><p class="receipt-footer"><?=e($selectedTemplate['footer_text'])?></p>
<?php endif; endforeach; ?></div>
<?php endif; ?>
<?php elseif(in_array($page,['branches','branch_users','bank_accounts'],true)): ?>
<?php render_company_resources($page); ?>
<?php elseif($page==='expense_reports'): ?>
<?php render_expense_report_center(tenant_id()); ?>
<?php elseif($page==='expenses'): ?>
<?php render_expenses(tenant_id()); ?>
<?php elseif($page==='cashbox'): ?>
<?php render_cashbox((int)$tenant); ?>
<?php elseif($page==='periods'): ?>
<?php render_accounting_periods((int)$tenant); ?>
<?php elseif($page==='finance_health'): ?>
<?php render_finance_schema_health((int)$tenant); ?>
<?php elseif(in_array($page,['commissions','templates','settings','activity','profile','roles','bills','pos'],true)): ?>
<?php render_module($page,(int)$tenant); ?>
<?php endif; ?>
</main></div></div>
<?php endif; ?><?php if ($page==='login'): ?><script src="assets/login-ui.js?v=<?=e(substr(hash_file('sha256',__DIR__.'/assets/login-ui.js'),0,16))?>" defer></script><?php endif; ?><?php if($page!=='login'): ?><script src="assets/user-preferences.js?v=<?=e((string)filemtime(__DIR__.'/assets/user-preferences.js'))?>" defer></script><?php endif; ?><script src="assets/app.js?v=<?=e(substr(hash_file('sha256',__DIR__.'/assets/app.js'),0,16))?>" defer></script><script src="assets/global-search.js?v=<?=e((string)filemtime(__DIR__.'/assets/global-search.js'))?>" defer></script><script src="assets/table-experience.js?v=<?=e(substr(hash_file('sha256',__DIR__.'/assets/table-experience.js'),0,16))?>" defer></script><script src="assets/report-progress.js?v=<?=e(substr(hash_file('sha256',__DIR__.'/assets/report-progress.js'),0,16))?>" defer></script></body></html>
<?php
function finish_invoice_creation(int $invoiceId): never {
    if (hash_equals((string)($_SESSION['invoice_create_nonce']??''),(string)($_POST['invoice_create_nonce']??''))) unset($_SESSION['invoice_create_nonce']);
    if (($_POST['invoice_after_save']??'list')==='next' && can('invoices.create')) {
        $_SESSION['invoice_start_next']=true;
        redirect('invoices','فاکتور قبلی با موفقیت ثبت شد. آماده صدور فاکتور جدید هستید.');
    }
    if (can('invoices.view') && can('invoices.print')) {
        if (($_POST['invoice_after_save']??'list')==='print') {
            $_SESSION['flash']='فاکتور با موفقیت ثبت شد.';
            header('Location: ?page=receipt&id='.$invoiceId); exit;
        }
    }
    redirect(can('invoices.view')?'invoices':'dashboard','فاکتور با موفقیت ثبت شد.');
}

/** One edit dialog shared by the recent-invoices and invoice-manager screens. */
function render_invoice_edit_dialog(int $tenant,array $invoice,array $payments=[]): void {
    if (!can('invoices.payment_edit')) return;
    $id=(int)$invoice['id'];
    $date=(string)(($invoice['invoice_date']??null) ?: substr((string)$invoice['created_at'],0,10));
    $dialog='invoiceEditor'.$id;
    echo ' <button type="button" class="small secondary" data-dialog-open="'.e($dialog).'">ویرایش فاکتور</button>';
    echo '<dialog class="app-dialog invoice-payment-edit-dialog" id="'.e($dialog).'" aria-label="ویرایش فاکتور">';
    echo '<div class="heading"><h2>ویرایش فاکتور</h2><button type="button" class="secondary small" data-dialog-close>بستن</button></div>';
    echo '<section class="invoice-edit-section"><h3>تاریخ فاکتور</h3><form method="post" class="stack">'.csrf_field()
        .'<input type="hidden" name="action" value="edit_invoice_date">'
        .'<input type="hidden" name="invoice_id" value="'.e((string)$id).'">'
        .'<label>تاریخ شمسی فاکتور<span class="jalali-field">'
        .'<input type="hidden" name="invoice_date" data-jalali-iso value="'.e($date).'">'
        .'<button type="button" class="secondary jalali-picker-button" data-jalali-open>انتخاب تاریخ</button>'
        .'</span></label>'
        .'<p class="muted">زمان ثبت اولیه و تاریخ دریافت واقعی وجه بدون تغییر باقی می‌مانند. دوره مالی مبدأ و مقصد بررسی می‌شود.</p>'
        .'<label>دلیل تغییر تاریخ<textarea name="reason" minlength="5" maxlength="500" required></textarea></label>'
        .'<div class="dialog-actions"><button type="submit">ذخیره تاریخ فاکتور</button></div></form></section>';
    if (invoice_payment_ledger_ready() && $payments) {
        echo '<section class="invoice-edit-section"><h3>جزئیات تراکنش‌ها</h3>';
        render_invoice_payment_replace_dialog($tenant,$id,(int)$invoice['total_amount'],$payments,true);
        echo '</section>';
    } elseif (!invoice_payment_ledger_ready() && isset($invoice['payment_type'])) {
        echo '<section class="invoice-edit-section"><h3>اطلاعات پرداخت</h3><form method="post" class="stack">'.csrf_field()
            .'<input type="hidden" name="action" value="edit"><input type="hidden" name="invoice_id" value="'.e((string)$id).'">'
            .'<label>نوع پرداخت<select name="payment_type"><option value="cash"'.($invoice['payment_type']==='cash'?' selected':'').'>نقدی</option>'
            .'<option value="pos"'.($invoice['payment_type']==='pos'?' selected':'').'>کارتخوان</option></select></label>'
            .'<label>کد پیگیری<input name="pos_ref" maxlength="120" value="'.e((string)($invoice['pos_ref']??'')).'"></label>'
            .'<label>دلیل اصلاح<textarea name="reason" minlength="5" maxlength="500" required></textarea></label>'
            .'<div class="dialog-actions"><button type="submit">ذخیره پرداخت</button></div></form></section>';
    }
    echo '</dialog>';
}

function render_invoices(int $tenant, int $limit): void {
    require_permission('invoices.view');
    $voidColumn=database_table_exists('invoice_voids')?'v.reason AS void_reason, v.voided_at':'NULL AS void_reason, NULL AS voided_at';
    $voidJoin=database_table_exists('invoice_voids')?' LEFT JOIN invoice_voids v ON v.invoice_id=i.id AND v.company_id=i.company_id':'';
    $own=document_scope()==='own';
    $rows=query('SELECT i.id,i.invoice_number,i.total_amount,i.payment_type,i.pos_ref,i.invoice_date,i.created_at,c.first_name,c.last_name,'.$voidColumn.' FROM invoices i JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id'.$voidJoin.' WHERE i.company_id=?'.($own?' AND i.user_id=?':'').($voidJoin!==''?' AND v.invoice_id IS NULL':'').' ORDER BY i.id DESC LIMIT '.(int)$limit,$own?[$tenant,user()['id']]:[$tenant])->fetchAll();
    $paymentsByInvoice=[];
    $ledger=invoice_payment_ledger_ready();
    if ($ledger && $rows) {
        $invoiceIds=array_map(static fn($invoice)=>(int)$invoice['id'],$rows);
        $placeholders=implode(',',array_fill(0,count($invoiceIds),'?'));
        $paymentRows=query('SELECT id,invoice_id,payment_method,amount,terminal_id,tracking_number,reference_number,paid_at
            FROM invoice_payments WHERE company_id=? AND voided_at IS NULL AND invoice_id IN ('.$placeholders.')
            ORDER BY invoice_id,id',array_merge([$tenant],$invoiceIds))->fetchAll();
        foreach ($paymentRows as $paymentRow) {
            $paymentsByInvoice[(int)$paymentRow['invoice_id']][]=$paymentRow;
        }
    }
    echo '<div class="table-wrap"><table><thead><tr><th>شماره</th><th>تاکسیران</th><th>مبلغ</th><th>پرداخت</th><th>مانده</th><th>تاریخ</th><th>وضعیت</th><th>جزئیات</th></tr></thead><tbody>';
    foreach($rows as $r) {
        $voided=$r['voided_at']!==null;
        $actualPayments=$paymentsByInvoice[(int)$r['id']]??[];
        $methods=array_unique(array_column($actualPayments,'payment_method'));
        $paidAmount=array_sum(array_map(static fn($payment)=>(int)$payment['amount'],$actualPayments));
        $balance=$ledger?max(0,(int)$r['total_amount']-$paidAmount):0;
        $payLabel=count($methods)>1?'ترکیبی':(($methods[0]??$r['payment_type'])==='cash'?'نقدی':'کارتخوان');
        $payLabel.=count($actualPayments)>1?' ('.fa_digits((string)count($actualPayments)).' تراکنش)':'';
        echo '<tr><td>'.e(display_financial_reference($r['invoice_number'])).'</td><td>'.e($r['first_name'].' '.$r['last_name']).'</td><td>'.money_toman($r['total_amount']).'</td><td>'.e($payLabel).'</td><td>'.money_toman($voided?0:$balance).'</td><td>'.e(jalali_datetime($r['invoice_date'] ?: $r['created_at'],true)).'</td><td>'.($voided?'<span class="status-chip status-muted" title="'.e($r['void_reason']).'">باطل‌شده</span>':'<span class="status-chip status-good">معتبر</span>').'</td><td>'.(can('invoices.print')?'<a href="?page=receipt&amp;id='.e($r['id']).'">چاپ</a>':'');
        if (!$voided) render_invoice_edit_dialog($tenant,$r,$actualPayments);
        if (can('invoices.payment_edit') && !$voided && invoice_followup_ready() && $balance>0) {
            render_invoice_collect_dialog($tenant,(int)$r['id'],$balance);
        }
        if ((can('invoices.payment_edit') || can('invoices.void')) && !$voided) {
            $voidId='invoiceVoid'.(int)$r['id'];
            if (can('invoices.void') && database_table_exists('invoice_voids')) {
                echo ' <button type="button" class="small danger" data-dialog-open="'.$voidId.'">ابطال</button>';
                echo '<dialog class="app-dialog" id="'.$voidId.'"><form class="stack" method="post">'.csrf_field().'<input type="hidden" name="action" value="void"><input type="hidden" name="invoice_id" value="'.(int)$r['id'].'"><h2>ابطال فاکتور</h2><p>این عملیات برگشت‌ناپذیر است، اما سابقه فاکتور برای پیگیری باقی می‌ماند.</p><label>دلیل ابطال<textarea name="reason" required minlength="5" maxlength="500"></textarea></label><div class="dialog-actions"><button type="button" class="secondary" data-dialog-close>انصراف</button><button class="danger">تأیید ابطال</button></div></form></dialog>';
            }
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div>';
}

/** Editable payment allocation; changes are always audit-preserving replacements. */
function render_invoice_payment_replace_dialog(int $tenant,int $invoiceId,int $total,array $payments,bool $embedded=false): void {
    static $terminalLists=[];
    if (!isset($terminalLists[$tenant])) {
        $terminalLists[$tenant]=query('SELECT id,brand FROM pos_settings WHERE company_id=? AND is_active=1 ORDER BY brand,id',[$tenant])->fetchAll();
    }
    $dialogId='invoiceReplace'.$invoiceId;
    if (!$embedded) echo ' <button type="button" class="small secondary" data-dialog-open="'.e($dialogId).'">ویرایش تراکنش‌ها</button><dialog class="app-dialog invoice-payment-edit-dialog" id="'.e($dialogId).'">';
    echo '<form method="post" class="stack invoice-payment-replace" data-payment-total="'.e((string)array_sum(array_map(static fn($p)=>(int)$p['amount'],$payments))).'">'.csrf_field().
         '<input type="hidden" name="action" value="replace_payments"><input type="hidden" name="invoice_id" value="'.e((string)$invoiceId).'">'.
         '<input type="hidden" name="payment_replace_nonce" value="'.e($_SESSION['invoice_replace_nonce']??=bin2hex(random_bytes(16))).'">'.
         '<h2>اصلاح جزئیات پرداخت</h2><p class="muted">تاریخ و مبلغ وصول، شناسه رسید و صندوق ثابت می‌مانند؛ فقط مشخصات فیش اصلاح و سابقه تغییر ثبت می‌شود.</p>'.
         '<p>جمع مبلغ فاکتور: <strong>'.money_toman($total).'</strong></p><div class="invoice-payment-rows" data-edit-payment-list>';
    foreach($payments as $index=>$row) {
        $method=(string)$row['payment_method'];
        echo '<div class="invoice-payment-row" data-edit-payment-row><input type="hidden" name="payments['.$index.'][id]" value="'.e((string)$row['id']).'"><p class="muted">زمان وصول: '.e(jalali_datetime($row['paid_at'],true)).'</p>';
        echo '<label>روش<select name="payments['.$index.'][method]"><option value="cash"'.($method==='cash'?' selected':'').'>نقدی</option><option value="pos"'.($method==='pos'?' selected':'').'>کارتخوان</option></select></label>';
        echo '<label>مبلغ (تومان)<input type="text" inputmode="numeric" required readonly name="payments['.$index.'][amount]" value="'.e(fa_digits((string)$row['amount'])).'"></label>';
        echo '<div class="payment-pos-fields">';
        echo '<label>شماره پیگیری<input maxlength="120" name="payments['.$index.'][tracking]" value="'.e((string)$row['tracking_number']).'"></label>';
        echo '<label>شماره مرجع<input maxlength="120" name="payments['.$index.'][reference]" value="'.e((string)$row['reference_number']).'"></label>';
        if ($terminalLists[$tenant]) {
            echo '<label>دستگاه کارتخوان<select name="payments['.$index.'][terminal]"><option value="">بدون انتخاب</option>';
            foreach ($terminalLists[$tenant] as $terminal) {
                echo '<option value="'.e((string)$terminal['id']).'"'.((int)$row['terminal_id']===(int)$terminal['id']?' selected':'').'>'.e($terminal['brand']).'</option>';
            }
            echo '</select></label>';
        }
        echo '</div></div>';
    }
    echo '</div>'.
         '<p class="muted" data-edit-summary></p><p class="invoice-payment-error" data-edit-error hidden></p>'.
         '<label>دلیل اصلاح <textarea name="reason" minlength="5" maxlength="500" required></textarea></label>'.
         '<div class="dialog-actions"><button type="button" class="secondary" data-dialog-close>انصراف</button><button type="submit">ثبت اصلاحات پرداخت</button></div></form>';
    if (!$embedded) echo '</dialog>'; 
}

/** Collect an additional amount without replacing or changing previous payment records. */
function render_invoice_collect_dialog(int $tenant,int $invoiceId,int $balance): void {
    static $terminals=[];
    if (!isset($terminals[$tenant])) {
        $terminals[$tenant]=query('SELECT id,brand FROM pos_settings WHERE company_id=? AND is_active=1 ORDER BY brand,id',[$tenant])->fetchAll();
    }
    $branchField='';
    if (branches_ready()) {
        $choices=branch_choices($tenant,(int)user()['id']);
        $branchField='<label>شعبه دریافت‌کننده<select name="receiving_branch_id" required><option value="">انتخاب شعبه</option>';
        foreach ($choices as $branch) {
            $branchField.='<option value="'.(int)$branch['id'].'"'.((count($choices)===1 || (int)$branch['is_default']===1)?' selected':'').'>'.e($branch['name']).'</option>';
        }
        $branchField.='</select></label>';
    }
    $id='invoiceCollect'.$invoiceId;
    $nonce=$_SESSION['invoice_collect_nonces'][$invoiceId]??=bin2hex(random_bytes(32));
    echo ' <button type="button" class="small secondary" data-dialog-open="'.e($id).'">دریافت مانده</button>';
    echo '<dialog class="app-dialog" id="'.e($id).'"><form method="post" class="stack">'.csrf_field()
        .'<input type="hidden" name="action" value="collect_balance"><input type="hidden" name="invoice_id" value="'.(int)$invoiceId.'">'
        .'<input type="hidden" name="collect_nonce" value="'.e($nonce).'"><h2>ثبت دریافت جدید</h2>'
        .'<p>مانده قابل دریافت: <strong>'.money_toman($balance).'</strong></p>'
        .$branchField
        .'<label>مبلغ دریافت (تومان)<input name="amount" type="text" inputmode="numeric" data-money-input required maxlength="22"></label>'
        .'<label>روش پرداخت<select name="payment_method" data-collect-method><option value="cash">نقدی</option><option value="pos">کارتخوان</option></select></label>'
        .'<label>دستگاه کارتخوان (در صورت استفاده)<select name="terminal"><option value="">بدون انتخاب دستگاه</option>';
    foreach ($terminals[$tenant] as $terminal) {
        echo '<option value="'.(int)$terminal['id'].'">'.e($terminal['brand']).'</option>';
    }
    echo '</select></label><label>شماره پیگیری کارتخوان<input name="tracking" maxlength="120" data-collect-tracking></label>'
        .'<label>شماره مرجع<input name="reference" maxlength="120"></label>'
        .'<p class="muted">دریافت جدید مستقل از پرداخت‌های قبلی ثبت می‌شود و مبلغ آن نباید از مانده بیشتر باشد.</p>'
        .'<div class="dialog-actions"><button type="button" class="secondary" data-dialog-close>انصراف</button>'
        .'<button type="submit">ثبت دریافت</button></div></form></dialog>';
}
