<?php
declare(strict_types=1);

/** Independent, tenant-scoped cash/POS payments. Money is stored as whole tomans. */
function invoice_payment_ledger_ready(): bool {
    return database_table_exists('invoice_payments');
}
function invoice_payment_amount(mixed $value): int {
    if (!is_string($value) && !is_int($value)) throw new RuntimeException('مبلغ پرداخت نامعتبر است.');
    $digits=ascii_digits((string)$value);
    $digits=str_replace([',','٬','،',' ','\xc2\xa0'], '',$digits);
    if (!preg_match('/^[0-9]{1,19}$/D',$digits) || (float)$digits > PHP_INT_MAX) {
        throw new RuntimeException('مبلغ پرداخت باید عدد صحیح و معتبر باشد.');
    }
    $amount=(int)$digits;
    if ($amount<=0) throw new RuntimeException('مبلغ هر پرداخت باید بیشتر از صفر باشد.');
    return $amount;
}
function invoice_payment_rows(array $posted, int $total, bool $allowPartial=false): array {
    if (!array_is_list($posted) || !$posted || count($posted)>50) {
        throw new RuntimeException('حداقل یک و حداکثر ۵۰ تراکنش پرداخت قابل ثبت است.');
    }
    $rows=[]; $sum=0;
    foreach ($posted as $entry) {
        if (!is_array($entry)) throw new RuntimeException('ساختار تراکنش نامعتبر است.');
        $method=(string)($entry['method']??'');
        if (!in_array($method,['cash','pos'],true)) throw new RuntimeException('روش پرداخت نامعتبر است.');
        $amount=invoice_payment_amount($entry['amount']??'');
        if ($amount>$total-$sum) throw new RuntimeException('مجموع تراکنش‌ها بیشتر از مبلغ فاکتور است.');
        $tracking=trim(ascii_digits((string)($entry['tracking']??'')));
        $reference=trim(ascii_digits((string)($entry['reference']??'')));
        if (mb_strlen($tracking)>120 || mb_strlen($reference)>120) throw new RuntimeException('شناسه پیگیری یا مرجع طولانی است.');
        $rows[]=['method'=>$method,'amount'=>$amount,'tracking'=>$method==='pos'?$tracking:'','reference'=>$method==='pos'?$reference:''];
        $sum+=$amount;
    }
    if (!$allowPartial && $sum!==$total) {
        throw new RuntimeException('برای ثبت فاکتور باید جمع پرداخت‌ها با مبلغ نهایی برابر باشد.');
    }
    return $rows;
}
function create_invoice_payment_rows(int $tenant,int $invoiceId,int $creator,array $rows): void {
    foreach ($rows as $row) {
        query('INSERT INTO invoice_payments (company_id,invoice_id,payment_method,amount,tracking_number,reference_number,paid_at,created_by) VALUES (?,?,?,?,?,?,NOW(),?)',
            [$tenant,$invoiceId,$row['method'],$row['amount'],$row['tracking']?:null,$row['reference']?:null,$creator]);
    }
}
function get_invoice_payment_rows(int $tenant,int $invoiceId): array {
    if (!invoice_payment_ledger_ready()) return [];
    return query('SELECT id,payment_method,amount,tracking_number,reference_number,paid_at FROM invoice_payments WHERE company_id=? AND invoice_id=? AND voided_at IS NULL ORDER BY id',
        [$tenant,$invoiceId])->fetchAll();
}
function invoice_payment_totals(int $tenant,int $invoiceId,int $total): array {
    $payments=get_invoice_payment_rows($tenant,$invoiceId);
    $paid=array_sum(array_map(static fn($p)=>(int)$p['amount'],$payments));
    return ['payments'=>$payments,'paid'=>$paid,'remaining'=>max(0,$total-$paid)];
}
