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
header('Content-Security-Policy: default-src \'self\'; script-src \'self\'; style-src \'self\' https://fonts.googleapis.com; font-src \'self\' https://fonts.gstatic.com; img-src \'self\' data:; object-src \'none\'; base-uri \'self\'; form-action \'self\'; frame-ancestors \'self\'');
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
function money(int|string $amount): string { return fa_digits(number_format((int)$amount)); }
function user(): ?array { return $_SESSION['user'] ?? null; }
function is_super(): bool { return (user()['role'] ?? '') === 'superadmin'; }
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
        $record = query('SELECT u.id,u.role,u.company_id,u.full_name,u.is_active,c.is_active company_active FROM users u LEFT JOIN companies c ON c.id=u.company_id WHERE u.id=?',[$u['id']])->fetch();
        if (!$record || !(int)$record['is_active'] || ($record['role']!=='superadmin' && !(int)$record['company_active'])) {
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
        'bills.view'=>'مشاهده قبوض', 'bills.create'=>'ثبت پرداخت قبض',
        'commissions.view'=>'مشاهده پورسانت', 'pos.view'=>'مشاهده کارتخوان‌ها',
        'activity.view'=>'مشاهده لاگ فعالیت',
    ];
}
function can(string $permission): bool {
    $u = require_login();
    if (in_array($u['role'],['superadmin','company_admin'],true)) return true;
    if (!array_key_exists($permission,permission_catalog())) return false;
    static $allowed = null;
    if ($allowed === null) $allowed = query('SELECT permission_key FROM user_permissions WHERE user_id=?',[$u['id']])->fetchAll(PDO::FETCH_COLUMN);
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
