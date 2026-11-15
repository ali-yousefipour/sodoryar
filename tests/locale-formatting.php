<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require __DIR__.'/../src/localization.php';

function check_locale(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR,"Localization check failed: ".$message.PHP_EOL); exit(1); }
}
check_locale(money(1234567)==='۱٬۲۳۴٬۵۶۷','number grouping and Persian digits');
check_locale(money_toman(80000)==='۸۰٬۰۰۰ تومان','toman unit');
check_locale(parse_money_input('۱٬۲۳۴٬۵۶۷')===1234567,'Persian amount parse');
check_locale(parse_money_input('1,234,567')===1234567,'Latin grouped amount parse');
check_locale(parse_money_input('منفی')===false,'reject invalid amount');
check_locale(parse_money_input('۱۰۰٬۰۰۰٬۰۰۰٬۰۰۱',0,100000000000)===false,'reject out of range');
check_locale(jalali_datetime('2026-11-15',true)==='۱۴۰۵/۰۸/۲۴','Jalali date');
check_locale(ui_value('2026-11-15','invoice_date')==='۱۴۰۵/۰۸/۲۴','invoice date conversion');
check_locale(ui_value('2026-11-15','payment_date')==='۱۴۰۵/۰۸/۲۴','payment date conversion');
check_locale(ui_value('1405-08','period_key')==='۱۴۰۵/۰۸','Jalali period display');
echo "Locale and amount checks passed.".PHP_EOL;
