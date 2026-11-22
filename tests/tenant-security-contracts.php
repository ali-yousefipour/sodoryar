<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
$required=['invoices.print','invoices.void','invoices.payment_edit','bills.soft_delete','bills.restore','bills.permanent_delete','roles.manage'];
foreach($required as $permission) {
    if(!array_key_exists($permission,permission_catalog())) throw new RuntimeException('Missing permission '.$permission);
}
$index=(string)file_get_contents(__DIR__.'/../public/index.php');
$modules=(string)file_get_contents(__DIR__.'/../src/modules.php');
if(!str_contains($index,"require_permission('invoices.print')")) throw new RuntimeException('Print permission guard missing');
if(!str_contains($index,"require_permission('invoices.void')")) throw new RuntimeException('Void permission guard missing');
if(!str_contains($index,"require_permission('invoices.payment_edit')")) throw new RuntimeException('Payment edit permission guard missing');
if(!str_contains($modules,"WHERE id=? AND company_id=? FOR UPDATE")) throw new RuntimeException('Tenant-scoped bill lock missing');
if(!str_contains($modules,"password_verify(\$password,(string)\$hash)")) throw new RuntimeException('Bill deletion re-auth missing');
if(!str_contains($index,"invoice_create_nonce")) throw new RuntimeException('Invoice idempotency token missing');
echo "Tenant security static-contract checks passed\n";
