<?php
declare(strict_types=1);
$module=(string)file_get_contents(__DIR__.'/../src/modules.php');
$invoice=(string)file_get_contents(__DIR__.'/../public/index.php');
foreach (['bill_create_nonce','commission_calculate_nonce'] as $key) {
    if (!str_contains($module,"hash_equals((string)(\$_SESSION['$key']??''),\$nonce)")
        || !str_contains($module,"unset(\$_SESSION['$key'])")
        || !str_contains($module,"name=\"$key\" value=\"<?=e(\$_SESSION['$key']??=bin2hex(random_bytes(16)))?>\"")) {
        throw new RuntimeException('Financial write-once form protection missing: '.$key);
    }
}
if (!str_contains($invoice,'invoice_create_nonce')) {
    throw new RuntimeException('Invoice create nonce must remain active.');
}
echo "Invoice, bill and commission write-once nonce contracts passed\n";
