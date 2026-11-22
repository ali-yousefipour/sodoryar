<?php
declare(strict_types=1);
require_once __DIR__.'/../src/localization.php';

function expect_total_failure(array $lines): void {
    try {
        sum_invoice_line_amounts($lines);
    } catch (RuntimeException $ex) {
        return;
    }
    throw new RuntimeException('Invalid invoice amounts must fail closed.');
}

if (sum_invoice_line_amounts([['price'=>100],['price'=>'250'],['price'=>0]])!==350) {
    throw new RuntimeException('Integer invoice sum is incorrect.');
}
if (sum_invoice_line_amounts([['price'=>PHP_INT_MAX]])!==PHP_INT_MAX) {
    throw new RuntimeException('Maximum integer sum is incorrect.');
}
expect_total_failure([['price'=>PHP_INT_MAX],['price'=>1]]);
expect_total_failure([['price'=>'9999999999999999999999999999']]);
expect_total_failure([['price'=>'1.5']]);
expect_total_failure([['price'=>'-1']]);
expect_total_failure([['price'=>'1e3']]);
expect_total_failure([['price'=>null]]);
echo "Invoice total integer overflow contracts passed\n";
