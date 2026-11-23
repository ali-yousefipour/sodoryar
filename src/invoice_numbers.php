<?php
declare(strict_types=1);

/** Insert inside the caller's financial transaction; the tenant unique key is authoritative. */
function insert_invoice_with_short_number(int $tenant,int $customer,int $creator,int $total,string $payment,?string $reference,string $date): string {
    for ($attempt=0;$attempt<5;$attempt++) {
        $number=chr(random_int(65,90)).chr(random_int(65,90)).sprintf('%06d',random_int(0,999999));
        try {
            query('INSERT INTO invoices(company_id,invoice_number,customer_id,user_id,total_amount,payment_type,pos_ref,invoice_date) VALUES(?,?,?,?,?,?,?,?)',
                [$tenant,$number,$customer,$creator,$total,$payment,$reference,$date]);
            return $number;
        } catch (PDOException $ex) {
            // Retry only a number collision, never another constraint or database failure.
            if ((int)($ex->errorInfo[1]??0)!==1062 || !str_contains((string)($ex->errorInfo[2]??''),'uq_invoice_number')) throw $ex;
        }
    }
    throw new RuntimeException('تخصیص شماره یکتای فاکتور انجام نشد؛ لطفاً دوباره تلاش کنید.');
}
