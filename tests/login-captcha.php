<?php
declare(strict_types=1);

require dirname(__DIR__).'/src/bootstrap.php';

[$a,$b]=login_captcha_new();
if (!login_captcha_verify((string)($a+$b))) throw new RuntimeException('Valid CAPTCHA rejected');
if (login_captcha_verify((string)($a+$b))) throw new RuntimeException('CAPTCHA replay accepted');

[$a,$b]=login_captcha_new();
if (!login_captcha_verify(fa_digits($a+$b))) throw new RuntimeException('Persian digits rejected');

login_captcha_new();
$_SESSION['login_captcha']['issued_at']=time()-301;
if (login_captcha_verify('10')) throw new RuntimeException('Expired CAPTCHA accepted');

login_captcha_new();
if (login_captcha_verify('invalid')) throw new RuntimeException('Invalid CAPTCHA accepted');

echo "CAPTCHA tests passed\n";
