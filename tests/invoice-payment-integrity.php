<?php
declare(strict_types=1);
require_once __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/../src/localization.php';
require_once __DIR__.'/../src/invoice_payments.php';

function expect_payment_rejection(array $rows, int $total): void {
    try { invoice_payment_rows($rows,$total); }
    catch (RuntimeException $expected) { return; }
    throw new RuntimeException('Invalid payment batch was accepted.');
}

$valid=invoice_payment_rows([
    ['method'=>'cash','amount'=>'۱٬۰۰۰٬۰۰۰'],
    ['method'=>'pos','amount'=>'۲٬۰۰۰٬۰۰۰','terminal'=>'1','tracking'=>'۱۲۳'],
    ['method'=>'pos','amount'=>'۲٬۰۰۰٬۰۰۰','terminal'=>'2','tracking'=>'۱۲۳'],
],5000000);
if (count($valid)!==3 || array_sum(array_column($valid,'amount'))!==5000000) {
    throw new RuntimeException('Mixed payment normalization failed.');
}
expect_payment_rejection([
    ['method'=>'pos','amount'=>'100','terminal'=>'1','tracking'=>'۱۲۳'],
    ['method'=>'pos','amount'=>'100','terminal'=>'1','tracking'=>'123'],
],200);
expect_payment_rejection([['method'=>'pos','amount'=>'100','terminal'=>'9999999999999999999']],100);
expect_payment_rejection([['method'=>'cash','amount'=>'101']],100);
$partial=invoice_payment_rows([['method'=>'cash','amount'=>'۱٬۰۰۰٬۰۰۰']],5000000,true);
if ((int)$partial[0]['amount']!==1000000 || invoice_payment_rows([],5000000,true)!==[]) {
    throw new RuntimeException('Partial or zero-initial invoice payment normalization failed.');
}
expect_payment_rejection([['method'=>'cash','amount'=>'۱٬۰۰۰٬۰۰۰']],5000000);
expect_payment_rejection([['method'=>'cash','amount'=>'۶٬۰۰۰٬۰۰۰']],5000000);
expect_payment_rejection([['method'=>'pos','amount'=>'100','tracking'=>'  ']],100);
expect_payment_rejection([
    ['method'=>'pos','amount'=>'50','terminal'=>'1','tracking'=>'۱۲۳'],
    ['method'=>'pos','amount'=>'50','tracking'=>'123'],
],100);

echo "Invoice POS reference and terminal validation passed\n";
