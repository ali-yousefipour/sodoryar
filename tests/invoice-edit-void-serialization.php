<?php
declare(strict_types=1);
$index=(string)file_get_contents(__DIR__.'/../public/index.php');
$editStart=strpos($index,"if (\$page==='invoices' && \$action==='edit')");
$voidStart=strpos($index,"if (\$page==='invoices' && \$action==='void')");
if($editStart===false || $voidStart===false || $voidStart<=$editStart) throw new RuntimeException('Invoice mutation handlers missing.');
$edit=substr($index,$editStart,$voidStart-$editStart);
$begin=strpos($edit,'db()->beginTransaction();');
$lock=strpos($edit,'FOR UPDATE');
$voidCheck=strpos($edit,"database_table_exists('invoice_voids')");
$update=strpos($edit,'UPDATE invoices SET payment_type=');
$audit=strpos($edit,"audit('invoice.payment.update'");
$commit=strpos($edit,'db()->commit()');
if($begin===false||$lock===false||$voidCheck===false||$update===false||$audit===false||$commit===false
  || !($begin<$lock && $lock<$voidCheck && $voidCheck<$update && $update<$audit && $audit<$commit)) {
    throw new RuntimeException('Payment edit is not fully serialized against invoice voids.');
}
if(!str_contains($index, "if (can('invoices.view') && can('invoices.print'))")) {
    throw new RuntimeException('Create-only users may be redirected to a forbidden receipt.');
}
echo "Invoice payment/void concurrency and IAM redirect contracts passed\n";
