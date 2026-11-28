<?php
declare(strict_types=1);

/** Regression contract: date and payment editing share one invoice dialog on both screens. */
$source=(string)file_get_contents(__DIR__.'/../public/index.php');
$manager=(string)file_get_contents(__DIR__.'/../src/invoice_management.php');
$begin=strpos($source,'function render_invoice_edit_dialog(');
$end=strpos($source,'function render_invoices(',($begin===false?0:$begin));
if($begin===false||$end===false||$end<=$begin)throw new RuntimeException('Shared invoice edit renderer missing.');
$editor=substr($source,$begin,$end-$begin);
if(!str_contains($editor,'invoiceEditor')
    || !str_contains($editor,'ویرایش فاکتور')
    || !str_contains($editor,'data-jalali-iso')
    || !str_contains($editor,'value="edit_invoice_date"')
    || !str_contains($editor,'render_invoice_payment_replace_dialog(')
    || substr_count($editor,'<dialog')!==1
    || !str_contains($source,'render_invoice_edit_dialog($tenant,$r,$actualPayments)')
    || !str_contains($manager,"render_invoice_edit_dialog(\$tenant,\$row,\$paymentsByInvoice[(int)\$row['id']]??[])")
    || str_contains($source,'function render_invoice_date_edit(')
    || str_contains($manager,'render_invoice_date_edit(')) {
    throw new RuntimeException('Invoice date must be embedded within the common editor on both pages.');
}
if(!str_contains($source,"in_array(\$page,['invoices','invoice_manager'],true) && \$action==='replace_payments'")
    || !str_contains($editor,'name="reason"')
    || !str_contains($manager,"FROM invoice_payments WHERE company_id=? AND voided_at IS NULL AND invoice_id IN")) {
    throw new RuntimeException('Shared editor lost authorization, audit reason, or tenant-scoped receipt prefetch.');
}
echo "Single invoice editor/date picker regression contract passed.\n";
