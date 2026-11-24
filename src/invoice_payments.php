<?php
declare(strict_types=1);

/** Independent, tenant-scoped cash/POS payments. Money is stored as whole tomans. */
function invoice_payment_ledger_ready(): bool {
    return database_table_exists('invoice_payments');
}
function invoice_payment_amount(mixed $value): int {
    if (!is_string($value) && !is_int($value)) throw new RuntimeException('مبلغ پرداخت نامعتبر است.');
    $input=trim(ascii_digits((string)$value));
    $input=str_replace(['٬','،'],',',$input);
    if (!preg_match('/^(?:[0-9]+|[0-9]{1,3}(?:,[0-9]{3})+)$/D',$input)) {
        throw new RuntimeException('جداکننده‌های سه‌رقمی مبلغ پرداخت نامعتبر است.');
    }
    $digits=str_replace(',', '',$input);
    $digits=ltrim($digits,'0');
    $max=(string)PHP_INT_MAX;
    if ($digits==='' || !preg_match('/^[0-9]+$/D',$digits)
        || strlen($digits)>strlen($max)
        || (strlen($digits)===strlen($max) && strcmp($digits,$max)>0)) {
        throw new RuntimeException('مبلغ پرداخت باید عدد صحیح و معتبر باشد.');
    }
    $amount=(int)$digits;
    if ($amount<=0) throw new RuntimeException('مبلغ هر پرداخت باید بیشتر از صفر باشد.');
    return $amount;
}
function invoice_payment_rows(array $posted, int $total, bool $allowPartial=false): array {
    if ($allowPartial && $posted===[]) return [];
    if (!array_is_list($posted) || !$posted || count($posted)>50) {
        throw new RuntimeException('حداقل یک و حداکثر ۵۰ تراکنش پرداخت قابل ثبت است.');
    }
    $rows=[]; $sum=0; $seenPosReferences=[];
    foreach ($posted as $entry) {
        if (!is_array($entry)) throw new RuntimeException('ساختار تراکنش نامعتبر است.');
        $method=(string)($entry['method']??'');
        if (!in_array($method,['cash','pos'],true)) throw new RuntimeException('روش پرداخت نامعتبر است.');
        $amount=invoice_payment_amount($entry['amount']??'');
        if ($amount>$total-$sum) throw new RuntimeException('مجموع تراکنش‌ها بیشتر از مبلغ فاکتور است.');
        $terminalValue=trim(ascii_digits((string)($entry['terminal']??'')));
        if ($terminalValue!=='' && !preg_match('/^[1-9][0-9]{0,18}$/D',$terminalValue)) throw new RuntimeException('شناسه کارتخوان نامعتبر است.');
        if ($terminalValue!=='' && (strlen($terminalValue)>strlen((string)PHP_INT_MAX)
            || (strlen($terminalValue)===strlen((string)PHP_INT_MAX) && strcmp($terminalValue,(string)PHP_INT_MAX)>0))) {
            throw new RuntimeException('شناسه کارتخوان خارج از محدوده مجاز است.');
        }
        $terminal=$method==='pos' && $terminalValue!==''?(int)$terminalValue:null;
        $tracking=trim(ascii_digits((string)($entry['tracking']??'')));
        $reference=trim(ascii_digits((string)($entry['reference']??'')));
        if (mb_strlen($tracking)>120 || mb_strlen($reference)>120) throw new RuntimeException('شناسه پیگیری یا مرجع طولانی است.');
        if ($method==='pos' && $tracking==='') throw new RuntimeException('شماره پیگیری کارتخوان الزامی است.');
        if ($method==='pos' && $tracking!=='') {
            // Same device and tracking number cannot represent two payments on this invoice.
            // Tracking numbers may legitimately repeat across different terminals.
            foreach ($seenPosReferences as $seen) {
                if ($seen['tracking']===$tracking && ($terminal!==null && $seen['terminal']===$terminal)) {
                    throw new RuntimeException('شماره پیگیری تکراری برای این کارتخوان ثبت شده است.');
                }
            }
            $seenPosReferences[]=['terminal'=>$terminal,'tracking'=>$tracking];
        }
        $rows[]=['method'=>$method,'amount'=>$amount,'tracking'=>$method==='pos'?$tracking:'','reference'=>$method==='pos'?$reference:'','terminal'=>$terminal];
        $sum+=$amount;
    }
    if (!$allowPartial && $sum!==$total) {
        throw new RuntimeException('برای ثبت فاکتور باید جمع پرداخت‌ها با مبلغ نهایی برابر باشد.');
    }
    return $rows;
}
function create_invoice_payment_rows(int $tenant,int $invoiceId,int $creator,array $rows): void {
    foreach ($rows as $row) {
        assert_unique_invoice_pos_receipt($tenant,$row);
        $terminal=$row['terminal']??null;
        if ($terminal!==null && !query('SELECT id FROM pos_settings WHERE id=? AND company_id=? AND is_active=1',[$terminal,$tenant])->fetch()) {
            throw new RuntimeException('کارتخوان انتخاب‌شده معتبر یا متعلق به این شرکت نیست.');
        }
        $cashShift=function_exists('cashbox_active_shift')?cashbox_active_shift($tenant,$creator):null;
        if ($cashShift!==null) {
            query('INSERT INTO invoice_payments (company_id,invoice_id,payment_method,amount,terminal_id,tracking_number,reference_number,paid_at,created_by,cash_shift_id) VALUES (?,?,?,?,?,?,?,NOW(),?,?)',
                [$tenant,$invoiceId,$row['method'],$row['amount'],$terminal,$row['tracking']!==''?$row['tracking']:null,$row['reference']?:null,$creator,$cashShift]);
        } else {
            query('INSERT INTO invoice_payments (company_id,invoice_id,payment_method,amount,terminal_id,tracking_number,reference_number,paid_at,created_by) VALUES (?,?,?,?,?,?,?,NOW(),?)',
                [$tenant,$invoiceId,$row['method'],$row['amount'],$terminal,$row['tracking']!==''?$row['tracking']:null,$row['reference']?:null,$creator]);
        }
    }
}
/** Company lock serializes receipt checks across different invoices and sessions. */
function assert_unique_invoice_pos_receipt(int $tenant,array $row): void {
    if ($row['method']!=='pos') return;
    if (trim((string)($row['tracking']??''))==='') throw new RuntimeException('شماره پیگیری کارتخوان الزامی است.');
    if (!db()->inTransaction()) throw new LogicException('Receipt validation requires a transaction.');
    query('SELECT id FROM companies WHERE id=? FOR UPDATE',[$tenant])->fetch();
    $terminal=$row['terminal']??null;
    if($terminal===null)return; // Unknown device cannot prove a duplicate receipt.
    $day=new DateTimeImmutable('today',new DateTimeZone('Asia/Tehran'));
    $duplicate=query('SELECT p.id FROM invoice_payments p WHERE p.company_id=? AND p.payment_method=\'pos\'
        AND p.tracking_number=? AND p.voided_at IS NULL AND p.terminal_id=? AND p.paid_at>=? AND p.paid_at<?'.(database_table_exists('invoice_voids')?' AND NOT EXISTS (SELECT 1 FROM invoice_voids v WHERE v.company_id=p.company_id AND v.invoice_id=p.invoice_id)':'').' LIMIT 1 FOR UPDATE',
        [$tenant,$row['tracking'],$terminal,$day->format('Y-m-d').' 00:00:00',$day->modify('+1 day')->format('Y-m-d').' 00:00:00'])->fetch();
    if ($duplicate) throw new RuntimeException('این فیش کارتخوان قبلاً در شرکت ثبت شده است؛ شماره پیگیری تکراری است.');
}
function get_invoice_payment_rows(int $tenant,int $invoiceId): array {
    if (!invoice_payment_ledger_ready()) return [];
    return query('SELECT id,payment_method,amount,terminal_id,tracking_number,reference_number,paid_at FROM invoice_payments WHERE company_id=? AND invoice_id=? AND voided_at IS NULL ORDER BY id',
        [$tenant,$invoiceId])->fetchAll();
}
function invoice_payment_totals(int $tenant,int $invoiceId,int $total): array {
    $payments=get_invoice_payment_rows($tenant,$invoiceId);
    $paid=array_sum(array_map(static fn($p)=>(int)$p['amount'],$payments));
    return ['payments'=>$payments,'paid'=>$paid,'remaining'=>max(0,$total-$paid)];
}

/** Follow-up collections require 017 and execute under the caller's locked invoice transaction. */
function invoice_followup_ready(): bool {
    static $ready=null;
    if ($ready===null) $ready=invoice_payment_ledger_ready()
        && (bool)query("SHOW COLUMNS FROM invoice_payments LIKE 'request_key'")->fetch();
    return $ready;
}
function invoice_followup_record(int $tenant,int $invoiceId,int $creator,array $row,string $key): int {
    if (!invoice_followup_ready()) throw new RuntimeException('برای دریافت مانده، ابتدا مهاجرت دیتابیس ۰۱۷ را اجرا کنید.');
    if (!preg_match('/^[a-f0-9]{64}$/D',$key)) throw new RuntimeException('شناسه یکتای عملیات دریافت نامعتبر است.');
    $terminal=$row['terminal']??null;
    if ($terminal!==null && !query('SELECT id FROM pos_settings WHERE id=? AND company_id=? AND is_active=1',[$terminal,$tenant])->fetch()) {
        throw new RuntimeException('دستگاه کارتخوان متعلق به شرکت یا فعال نیست.');
    }
    assert_unique_invoice_pos_receipt($tenant,$row);
    $cashShift=function_exists('cashbox_active_shift')?cashbox_active_shift($tenant,$creator):null;
    if ($cashShift!==null) {
        query('INSERT INTO invoice_payments (company_id,invoice_id,payment_method,amount,terminal_id,tracking_number,reference_number,paid_at,created_by,request_key,cash_shift_id) VALUES (?,?,?,?,?,?,?,NOW(),?,?,?)',
            [$tenant,$invoiceId,$row['method'],$row['amount'],$terminal,$row['tracking']!==''?$row['tracking']:null,$row['reference']?:null,$creator,$key,$cashShift]);
    } else {
        query('INSERT INTO invoice_payments (company_id,invoice_id,payment_method,amount,terminal_id,tracking_number,reference_number,paid_at,created_by,request_key) VALUES (?,?,?,?,?,?,?,NOW(),?,?)',
            [$tenant,$invoiceId,$row['method'],$row['amount'],$terminal,$row['tracking']!==''?$row['tracking']:null,$row['reference']?:null,$creator,$key]);
    }
    return (int)db()->lastInsertId();
}
