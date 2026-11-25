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
    $branch=null;
    if (function_exists('branches_ready') && branches_ready()) {
        $branch=(int)query('SELECT branch_id FROM invoices WHERE company_id=? AND id=? FOR UPDATE',[$tenant,$invoiceId])->fetchColumn();
        branch_require($tenant,$creator,$branch);
    }
    foreach ($rows as $row) {
        assert_unique_invoice_pos_receipt($tenant,$row);
        if ($branch!==null) {
            $cashShift=branch_payment_shift($tenant,$creator,$branch,$row);
            query('INSERT INTO invoice_payments(company_id,invoice_id,branch_id,payment_method,amount,terminal_id,tracking_number,reference_number,paid_at,created_by,cash_shift_id) VALUES(?,?,?,?,?,?,?,?,NOW(),?,?)',
                [$tenant,$invoiceId,$branch,$row['method'],$row['amount'],$row['terminal']??null,$row['tracking']?:null,$row['reference']?:null,$creator,$cashShift]);
            continue;
        }
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
function assert_unique_invoice_pos_receipt(int $tenant,array $row,?string $paidAt=null,?int $excludeInvoice=null): void {
    if ($row['method']!=='pos') return;
    if (trim((string)($row['tracking']??''))==='') throw new RuntimeException('شماره پیگیری کارتخوان الزامی است.');
    if (!db()->inTransaction()) throw new LogicException('Receipt validation requires a transaction.');
    query('SELECT id FROM companies WHERE id=? FOR UPDATE',[$tenant])->fetch();
    $terminal=$row['terminal']??null;
    if($terminal===null)return; // Unknown device cannot prove a duplicate receipt.
    $day=new DateTimeImmutable($paidAt??'today',new DateTimeZone('Asia/Tehran'));
    $day=$day->setTime(0,0);
    $excluded=$excludeInvoice===null?'':' AND p.invoice_id<>'.(int)$excludeInvoice;
    $duplicate=query('SELECT p.id FROM invoice_payments p WHERE p.company_id=? AND p.payment_method=\'pos\'
        AND p.tracking_number=? AND p.voided_at IS NULL AND p.terminal_id=? AND p.paid_at>=? AND p.paid_at<?'.(database_table_exists('invoice_voids')?' AND NOT EXISTS (SELECT 1 FROM invoice_voids v WHERE v.company_id=p.company_id AND v.invoice_id=p.invoice_id)':'').$excluded.' LIMIT 1 FOR UPDATE',
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
    if (function_exists('branches_ready') && branches_ready()) {
        $branch=branch_require($tenant,$creator,$_POST['receiving_branch_id']??null);
        assert_unique_invoice_pos_receipt($tenant,$row);
        $shift=branch_payment_shift($tenant,$creator,$branch,$row);
        query('INSERT INTO invoice_payments(company_id,invoice_id,branch_id,payment_method,amount,terminal_id,tracking_number,reference_number,paid_at,created_by,request_key,cash_shift_id) VALUES(?,?,?,?,?,?,?,?,NOW(),?,?,?)',
            [$tenant,$invoiceId,$branch,$row['method'],$row['amount'],$row['terminal']??null,$row['tracking']?:null,$row['reference']?:null,$creator,$key,$shift]);
        return (int)db()->lastInsertId();
    }
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

/** Corrections are not new collections. Keep receipt identity, timestamp, creator and shift.
 * Caller holds invoice lock and transaction; each original receipt is edited once.
 */
function correct_invoice_payment_rows(int $tenant,int $invoiceId,array $old,array $replacement,string $reason): array {
    if(!db()->inTransaction())throw new LogicException('Receipt correction requires a transaction.');
    if(!$old || count($old)!==count($replacement))throw new RuntimeException('تعداد تراکنش‌های قبلی در اصلاح فیش قابل تغییر نیست.');
    if(mb_strlen($reason)<5||mb_strlen($reason)>500)throw new RuntimeException('دلیل اصلاح نامعتبر است.');
    query('SELECT id FROM companies WHERE id=? FOR UPDATE',[$tenant])->fetch();
    $seen=[];$changes=[];
    foreach($old as $index=>$before){
        $after=$replacement[$index];
        if((int)$before['amount']!==(int)$after['amount'])throw new RuntimeException('مبلغ دریافت قبلی در اصلاح مشخصات فیش قابل تغییر نیست.');
        $paidAt=(string)$before['paid_at'];
        accounting_assert_write_open($tenant,substr($paidAt,0,10));
        $terminal=$after['terminal']??null;
        if($terminal!==null && !query('SELECT id FROM pos_settings WHERE id=? AND company_id=? AND (is_active=1 OR id=?)',[$terminal,$tenant,$before['terminal_id']])->fetch())throw new RuntimeException('دستگاه کارتخوان نامعتبر است.');
        if($after['method']==='pos' && $terminal!==null){
            $key=json_encode([$terminal,$after['tracking'],substr($paidAt,0,10)],JSON_THROW_ON_ERROR);
            if(isset($seen[$key]))throw new RuntimeException('شماره پیگیری تکراری برای دستگاه و تاریخ دریافت.');
            $seen[$key]=true;
        }
        assert_unique_invoice_pos_receipt($tenant,$after,$paidAt,$invoiceId);
        $new=['id'=>(int)$before['id'],'payment_method'=>$after['method'],'amount'=>(int)$after['amount'],'terminal_id'=>$terminal,'tracking_number'=>$after['tracking']!==''?$after['tracking']:null,'reference_number'=>$after['reference']!==''?$after['reference']:null,'paid_at'=>$paidAt];
        $changes[]=['before'=>$before,'after'=>$new];
    }
    foreach($changes as $change){
        $new=$change['after'];
        query('UPDATE invoice_payments SET payment_method=?,terminal_id=?,tracking_number=?,reference_number=? WHERE id=? AND company_id=? AND invoice_id=? AND voided_at IS NULL',
            [$new['payment_method'],$new['terminal_id'],$new['tracking_number'],$new['reference_number'],$new['id'],$tenant,$invoiceId]);
    }
    return $changes;
}
