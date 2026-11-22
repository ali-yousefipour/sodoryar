<?php
declare(strict_types=1);

$bootstrap=(string)file_get_contents(__DIR__.'/../src/bootstrap.php');
$index=(string)file_get_contents(__DIR__.'/../public/index.php');
$htaccess=(string)file_get_contents(__DIR__.'/../public/.htaccess');

if (!str_contains($bootstrap, "dirname(__DIR__) . '/sessions'")
    || !str_contains($bootstrap, 'session_save_path($privateSessionDirectory)')
    || !str_contains($bootstrap, 'is_writable($privateSessionDirectory)')
    || !str_contains($bootstrap, "session_status() !== PHP_SESSION_ACTIVE")) {
    throw new RuntimeException('Hosted login must have protected persistent session storage and fail closed when session startup fails.');
}
if (!str_contains($bootstrap, 'hash_equals(csrf_token(), $submitted)')
    || !str_contains($bootstrap, 'http_response_code(419)')
    || !str_contains($bootstrap, 'Sodoryar CSRF mismatch: cookie=')) {
    throw new RuntimeException('CSRF protection and non-sensitive session diagnostics must remain in place.');
}
if (!str_contains($index, 'if ($page === \'login\' && $_SERVER[\'REQUEST_METHOD\'] === \'POST\')')
    || !str_contains($index, 'verify_csrf();')
    || !str_contains($index, '<?=csrf_field()?>')
    || !str_contains($index, '/favicon.svg')) {
    throw new RuntimeException('Login must emit and verify its CSRF token and advertise the real favicon.');
}
if (!is_file(__DIR__.'/../public/favicon.svg')
    || !str_contains($htaccess, 'RewriteRule ^favicon\\.ico$ favicon.svg [L]')) {
    throw new RuntimeException('Legacy favicon.ico requests must resolve to the existing SVG favicon.');
}
echo "Login session, CSRF and favicon contracts passed\n";
