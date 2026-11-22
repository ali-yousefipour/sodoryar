<?php
declare(strict_types=1);

function config(string $key, string $default = ''): string {
    static $values = null;
    if ($values === null) {
        $values = [];
        $path = dirname(__DIR__) . '/.env';
        if (is_readable($path)) {
            foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $values[trim($k)] = trim($v, " \t\r\n\"'");
            }
        }
    }
    return (string) (getenv($key) ?: ($values[$key] ?? $default));
}

ini_set('display_errors', config('APP_ENV', 'production') === 'development' ? '1' : '0');
date_default_timezone_set('Asia/Tehran');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
session_name('sodoryar_session');
session_set_cookie_params([
    'httponly' => true, 'secure' => config('SESSION_SECURE', '1') === '1',
    'samesite' => 'Lax', 'path' => '/',
]);
session_start();
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
header('Cache-Control: private, no-store');
header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' https://fonts.googleapis.com; font-src \'self\' https://fonts.gstatic.com; img-src \'self\' data: blob:; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'');
if (config('SESSION_SECURE', '1') === '1') header('Strict-Transport-Security: max-age=31536000');

function db(): PDO {
    static $pdo;
    if (!$pdo) {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            config('DB_HOST', 'localhost'), config('DB_PORT', '3306'), config('DB_NAME'));
        $pdo = new PDO($dsn, config('DB_USER'), config('DB_PASS'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+03:30'");
    }
    return $pdo;
}
function query(string $sql, array $params = []): PDOStatement {
    $stmt = db()->prepare($sql); $stmt->execute($params); return $stmt;
}
function login_attempt_key(string $username): string {
    return hash('sha256', strtolower(trim($username)) . '|' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}
function login_ip_key(): string {
    return hash('sha256','ip:'.($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
}
function login_is_limited(string $key,int $limit=5): bool {
    return (int) query('SELECT COUNT(*) FROM login_attempts WHERE attempt_key=? AND created_at >= NOW() - INTERVAL 15 MINUTE',[$key])->fetchColumn() >= $limit;
}
function login_failed(string $key): void {
    query('INSERT INTO login_attempts(attempt_key) VALUES(?)',[$key]);
}
function login_succeeded(string $key): void {
    query('DELETE FROM login_attempts WHERE attempt_key=?',[$key]);
}
function login_captcha_new(): array {
    $a=random_int(2,9); $b=random_int(2,9);
    $_SESSION['login_captcha']=['answer'=>(string)($a+$b),'issued_at'=>time()];
    return [$a,$b];
}
function login_captcha_verify(string $submitted): bool {
    $challenge=$_SESSION['login_captcha']??null;
    unset($_SESSION['login_captcha']);
    $value=ascii_digits(trim($submitted));
    return is_array($challenge) && isset($challenge['answer'],$challenge['issued_at'])
        && time()-(int)$challenge['issued_at']<=300 && time()>=(int)$challenge['issued_at']
        && preg_match('/^[0-9]{1,2}$/D',$value)===1 && hash_equals((string)$challenge['answer'],$value);
}
function e(mixed $value): string { return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function fa_digits(string|int|float $value): string {
    return strtr((string)$value,['0'=>'۰','1'=>'۱','2'=>'۲','3'=>'۳','4'=>'۴','5'=>'۵','6'=>'۶','7'=>'۷','8'=>'۸','9'=>'۹']);
}
function ascii_digits(string $value): string {
    return strtr($value,['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
}
function money(int|string $amount): string { return fa_digits(number_format((int)$amount,0,'.','٬')); }
function user(): ?array { return $_SESSION['user'] ?? null; }
function is_super(): bool { return (user()['role'] ?? '') === 'superadmin'; }
/** Optional session version for revoking old sessions on credential/role changes. */
function auth_version_ready(): bool {
    static $ready=null;
    if ($ready===null) {
        $ready=(bool)query("SHOW COLUMNS FROM users LIKE 'auth_version'")->fetch();
    }
    return $ready;
}
function require_login(): array {
    $u = user(); if (!$u) { header('Location: ?page=login'); exit; }
    if (time()-(int)($_SESSION['last_activity']??time())>1800) {
        $_SESSION=[]; session_regenerate_id(true); header('Location: ?page=login'); exit;
    }
    $_SESSION['last_activity']=time();
    if (time()-(int)($_SESSION['regenerated_at']??0)>900) {
        session_regenerate_id(true); $_SESSION['regenerated_at']=time();
    }
    static $checked = false;
    if (!$checked) {
        $versioned=auth_version_ready();
        $record = query('SELECT u.id,u.role,u.company_id,u.full_name,u.is_active'.($versioned?',u.auth_version':'').',c.is_active company_active FROM users u LEFT JOIN companies c ON c.id=u.company_id WHERE u.id=?',[$u['id']])->fetch();
        if (!$record || !(int)$record['is_active'] || ($record['role']!=='superadmin' && !(int)$record['company_active'])
            || ($versioned && (!isset($_SESSION['auth_version']) || (int)$_SESSION['auth_version'] !== (int)$record['auth_version']))) {
            $_SESSION=[]; session_regenerate_id(true); header('Location: ?page=login'); exit;
        }
        $_SESSION['user']=['id'=>(int)$record['id'],'role'=>$record['role'],'company_id'=>$record['company_id'],'full_name'=>$record['full_name']];
        $checked=true;
    }
    return $_SESSION['user'];
}
function tenant_id(): int {
    $u = require_login();
    if (is_super()) {
        $id = (int) ($_SESSION['selected_company'] ?? 0);
        if ($id < 1) throw new RuntimeException('ابتدا یک شرکت انتخاب کنید.');
        // A tenant selected earlier must not remain accessible after deactivation.
        // Never accept the selected tenant from request parameters.
        if (!query('SELECT 1 FROM companies WHERE id=? AND is_active=1 LIMIT 1', [$id])->fetchColumn()) {
            unset($_SESSION['selected_company']);
            http_response_code(403);
            throw new RuntimeException('شرکت انتخاب‌شده غیرفعال یا نامعتبر است. دوباره شرکت را انتخاب کنید.');
        }
        return $id;
    }
    return (int) $u['company_id'];
}
function require_admin(): void {
    if (!in_array(user()['role'] ?? '', ['superadmin','company_admin'], true)) {
        http_response_code(403); exit('دسترسی مجاز نیست');
    }
}
function permission_catalog(): array {
    return [
        'customers.view'=>'مشاهده تاکسیرانان', 'customers.create'=>'ثبت تاکسیران',
        'services.view'=>'مشاهده خدمات', 'invoices.view'=>'مشاهده فاکتورها',
        'invoices.create'=>'صدور فاکتور', 'reports.view'=>'گزارش‌های مالی',
        'reports.export'=>'دریافت اکسل گزارش‌های مالی', 'activity.export'=>'دریافت اکسل فعالیت کاربران',
        'bills.view'=>'مشاهده قبوض', 'bills.create'=>'ثبت پرداخت قبض',
        'commissions.view'=>'مشاهده پورسانت', 'pos.view'=>'مشاهده کارتخوان‌ها',
        'activity.view'=>'مشاهده لاگ فعالیت',
        'users.manage'=>'مدیریت کاربران شرکت', 'roles.manage'=>'مدیریت نقش‌های شرکت',
        'invoices.print'=>'چاپ فاکتور', 'invoices.payment_edit'=>'ویرایش روش پرداخت فاکتور',
        'invoices.void'=>'ابطال فاکتور',
        'bills.soft_delete'=>'انتقال فیش به سطل زباله',
        'bills.restore'=>'بازیابی فیش حذف‌شده',
        'bills.permanent_delete'=>'حذف دائمی فیش مجاز',
    ];
}
/** Optional IAM schema; retain the legacy permission system until migration 010. */
/** Consistent, localized permission groups used by company IAM screens. */
function document_scopes_ready(): bool {
    static $ready=null;
    if ($ready===null) $ready=(bool)query("SHOW TABLES LIKE 'user_document_scopes'")->fetchColumn();
    return $ready;
}
/** Financial document scope. No new restrictions until migration 011 is installed. */
function document_scope(): string {
    $u=require_login();
    if ($u['role']!=='operator') return 'company';
    static $scope=null;
    if ($scope!==null) return $scope;
    try {
        if (!document_scopes_ready()) return $scope='company';
        $value=query('SELECT s.scope FROM user_document_scopes s JOIN users u ON u.id=s.user_id AND u.company_id=s.company_id WHERE s.user_id=? AND s.company_id=?',[$u['id'],$u['company_id']])->fetchColumn();
        return $scope=$value==='own'?'own':'company';
    } catch(PDOException $ex) {
        error_log('Sodoryar document scope read error: '.$ex->getCode());
        // When the migrated scope table exists but reading fails, fail closed.
        http_response_code(503);
        throw new RuntimeException('امکان بررسی محدوده دسترسی اسناد وجود ندارد.');
    }
}
function require_document_owner(int $ownerId): void {
    if (document_scope()==='own' && $ownerId!==(int)user()['id']) {
        http_response_code(403);
        if (($_SERVER['REQUEST_METHOD']??'GET')==='GET') exit('این سند خارج از محدوده دسترسی شماست.');
        throw new RuntimeException('این سند خارج از محدوده دسترسی شماست.');
    }
}
/** Permissions only for company managers; never delegate these to operators. */
function operator_permission_assignable(string $permission): bool {
    return array_key_exists($permission,permission_catalog())
        && !in_array($permission,['users.manage','roles.manage'],true);
}
function permission_groups(): array {
    $names=[
        'customers'=>'تاکسیرانان', 'services'=>'خدمات', 'invoices'=>'فاکتورها',
        'bills'=>'فیش‌ها و قبوض', 'reports'=>'گزارش‌های مالی', 'activity'=>'گزارش فعالیت‌ها',
        'commissions'=>'پورسانت', 'pos'=>'کارتخوان‌ها', 'users'=>'کاربران',
        'roles'=>'مدیریت نقش‌ها'
    ];
    $groups=[];
    foreach(permission_catalog() as $key=>$label) {
        $module=explode('.',$key,2)[0];
        $group=$names[$module]??$module;
        $groups[$group][$key]=$label;
    }
    return $groups;
}
function company_roles_ready(): bool {
    static $ready=null;
    if ($ready===null) {
        try {
            $ready=(bool)query("SHOW TABLES LIKE 'company_roles'")->fetchColumn()
                && (bool)query("SHOW TABLES LIKE 'company_role_permissions'")->fetchColumn()
                && (bool)query("SHOW TABLES LIKE 'user_role_assignments'")->fetchColumn()
                && (bool)query("SHOW COLUMNS FROM company_roles LIKE 'is_default'")->fetch();
        } catch (PDOException $ex) {
            error_log('Sodoryar IAM migration readiness: '.$ex->getCode());
            $ready=false;
        }
    }
    return $ready;
}
function effective_operator_permissions(int $userId, int $companyId): array {
    $direct=query('SELECT permission_key FROM user_permissions WHERE user_id=?',[$userId])->fetchAll(PDO::FETCH_COLUMN);
    $direct=array_values(array_filter($direct,'operator_permission_assignable'));
    if (!company_roles_ready()) return $direct;
    $inherited=query('SELECT rp.permission_key
        FROM user_role_assignments a
        JOIN company_roles r ON r.id=a.role_id AND r.company_id=a.company_id AND r.is_active=1
        JOIN company_role_permissions rp ON rp.role_id=r.id AND rp.company_id=r.company_id
        WHERE a.user_id=? AND a.company_id=?',[$userId,$companyId])->fetchAll(PDO::FETCH_COLUMN);
    return array_values(array_unique(array_filter(array_merge($direct,$inherited),'operator_permission_assignable')));
}
function can(string $permission): bool {
    $u = require_login();
    if (!array_key_exists($permission,permission_catalog())) return false;
    if (in_array($u['role'],['superadmin','company_admin'],true)) return true;
    static $allowed = null;
    if ($allowed === null) $allowed = effective_operator_permissions((int)$u['id'],(int)$u['company_id']);
    return in_array($permission,$allowed,true);
}
function require_permission(string $permission): void {
    if (!can($permission)) { http_response_code(403); exit('دسترسی مجاز نیست'); }
}
function csrf_token(): string { return $_SESSION['csrf'] ??= bin2hex(random_bytes(32)); }
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(csrf_token()) . '">'; }
function verify_csrf(): void {
    if (!hash_equals(csrf_token(), (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(419); exit('نشست یا توکن امنیتی نامعتبر است.');
    }
}
function redirect(string $page, string $message = ''): never {
    if ($message !== '') $_SESSION['flash'] = $message;
    header('Location: ?page=' . rawurlencode($page)); exit;
}
function audit(string $action, string $details = '', ?int $company = null): void {
    query('INSERT INTO activity_logs(company_id,user_id,action,details) VALUES(?,?,?,?)',
        [$company ?? (is_super() ? ($_SESSION['selected_company'] ?? null) : (user()['company_id'] ?? null)), user()['id'] ?? null, $action, $details]);
}
