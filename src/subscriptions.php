<?php
declare(strict_types=1);

/**
 * Opt-in tenant subscription enforcement. Existing companies without a row
 * retain access until a superadmin explicitly provisions their first period.
 * No implicit migrations, table creation or billing postings occur in a request.
 */
function sodoryar_subscription_ready(): bool {
    static $ready=null;
    // Retry a previously missing table: a disposable integration test may apply
    // the migration after an earlier readiness probe in the same PHP process.
    if ($ready!==true) {
        $ready=(bool)query("SHOW TABLES LIKE 'company_subscriptions'")->fetchColumn();
    }
    return $ready;
}

/** Pure and testable entitlement calculation in Asia/Tehran calendar days. */
function sodoryar_subscription_state(?array $subscription,string $today): array {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$today) || !checkdate((int)substr($today,5,2),(int)substr($today,8,2),(int)substr($today,0,4))) {
        throw new InvalidArgumentException('Invalid subscription evaluation date.');
    }
    if ($subscription===null) return ['allowed'=>true,'state'=>'unassigned','grace_days_left'=>null];
    if (($subscription['status']??'')==='suspended') return ['allowed'=>false,'state'=>'suspended','grace_days_left'=>0];
    if (($subscription['status']??'')!=='active') return ['allowed'=>false,'state'=>'invalid','grace_days_left'=>0];
    $start=(string)($subscription['start_date']??'');
    $end=(string)($subscription['end_date']??'');
    foreach([$start,$end] as $date) {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$date) ||
            !checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4))) {
            return ['allowed'=>false,'state'=>'invalid','grace_days_left'=>0];
        }
    }
    if ($end<$start) return ['allowed'=>false,'state'=>'invalid','grace_days_left'=>0];
    if ($today<$start) return ['allowed'=>false,'state'=>'not_started','grace_days_left'=>0];
    if ($today<=$end) return ['allowed'=>true,'state'=>'active','grace_days_left'=>null];
    $zone=new DateTimeZone('Asia/Tehran');
    $expiry=DateTimeImmutable::createFromFormat('!Y-m-d',$end,$zone);
    $current=DateTimeImmutable::createFromFormat('!Y-m-d',$today,$zone);
    if (!$expiry || !$current) return ['allowed'=>false,'state'=>'invalid','grace_days_left'=>0];
    $days=(int)$expiry->diff($current)->format('%a');
    if ($days>=1 && $days<=3) return ['allowed'=>true,'state'=>'grace','grace_days_left'=>4-$days];
    return ['allowed'=>false,'state'=>'expired','grace_days_left'=>0];
}

function sodoryar_company_subscription(int $companyId): ?array {
    if ($companyId<1 || !sodoryar_subscription_ready()) return null;
    $record=query('SELECT company_id,start_date,end_date,monthly_fee,months,total_amount,paid_amount,status FROM company_subscriptions WHERE company_id=? LIMIT 1',[$companyId])->fetch();
    return $record?:null;
}

/**
 * Audited subscription periods remain available after renewals and edits.
 * Never infer historical paid amounts from cashbox or branch finances.
 */
function sodoryar_subscription_history(int $companyId,int $limit=25): array {
    if ($companyId<1 || !sodoryar_subscription_ready()) return [];
    $limit=max(1,min($limit,100));
    return query(
        'SELECT e.created_at,e.actor_id,e.old_snapshot,e.new_snapshot
         FROM company_subscription_events e
         WHERE e.company_id=? ORDER BY e.id DESC LIMIT '.$limit,
        [$companyId]
    )->fetchAll();
}

function sodoryar_company_entitlement(int $companyId): array {
    return sodoryar_subscription_state(sodoryar_company_subscription($companyId),date('Y-m-d'));
}

/** Must run during both login and every authenticated request, including API POST. */
function sodoryar_subscription_enforce(int $companyId): void {
    $state=sodoryar_company_entitlement($companyId);
    if ($state['allowed']) return;
    http_response_code(403);
    header('Cache-Control: private, no-store');
    header('Content-Type: text/plain; charset=utf-8');
    exit('دسترسی شرکت به دلیل وضعیت اشتراک متوقف شده است. لطفاً برای تمدید با مدیر سامانه تماس بگیرید.');
}

/**
 * A superadmin-only provisioning primitive. Call under CSRF protection.
 * Upsert affects a company's entitlement only; no cashbox or invoice entries.
 */
function sodoryar_subscription_save(int $companyId,string $start,string $end,int $monthlyFee,int $months,int $paid,string $status,int $actor): void {
    if (!sodoryar_subscription_ready()) throw new RuntimeException('ابتدا مهاجرت مدیریت اشتراک را اجرا کنید.');
    if ($companyId<1 || $actor<1 || !in_array($status,['active','suspended'],true) ||
        $months<1 || $months>120 || $monthlyFee<0 || $paid<0 ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$start) ||
        !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$end) ||
        !checkdate((int)substr($start,5,2),(int)substr($start,8,2),(int)substr($start,0,4)) ||
        !checkdate((int)substr($end,5,2),(int)substr($end,8,2),(int)substr($end,0,4)) ||
        $start>$end || $monthlyFee>intdiv(PHP_INT_MAX,$months)) {
        throw new RuntimeException('مشخصات اشتراک نامعتبر است.');
    }
    $total=$monthlyFee*$months;
    if ($paid>$total) throw new RuntimeException('مبلغ پرداخت‌شده بیش از مبلغ اشتراک است.');
    db()->beginTransaction();
    try {
        $company=query('SELECT id FROM companies WHERE id=? FOR UPDATE',[$companyId])->fetchColumn();
        if (!$company) throw new RuntimeException('شرکت انتخاب‌شده وجود ندارد.');
        $previous=query('SELECT * FROM company_subscriptions WHERE company_id=? FOR UPDATE',[$companyId])->fetch()?:null;
        query('INSERT INTO company_subscriptions(company_id,start_date,end_date,monthly_fee,months,total_amount,paid_amount,status,updated_by) VALUES(?,?,?,?,?,?,?,?,?)
              ON DUPLICATE KEY UPDATE start_date=VALUES(start_date),end_date=VALUES(end_date),monthly_fee=VALUES(monthly_fee),months=VALUES(months),total_amount=VALUES(total_amount),paid_amount=VALUES(paid_amount),status=VALUES(status),updated_by=VALUES(updated_by)',
              [$companyId,$start,$end,$monthlyFee,$months,$total,$paid,$status,$actor]);
        query('INSERT INTO company_subscription_events(company_id,actor_id,old_snapshot,new_snapshot) VALUES(?,?,?,?)',
              [$companyId,$actor,$previous?json_encode($previous,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE):null,
               json_encode(['start_date'=>$start,'end_date'=>$end,'monthly_fee'=>$monthlyFee,'months'=>$months,'total_amount'=>$total,'paid_amount'=>$paid,'status'=>$status],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
        audit('company.subscription.update',(string)$companyId,$companyId);
        db()->commit();
    } catch(Throwable $ex) {
        if (db()->inTransaction()) db()->rollBack();
        throw $ex;
    }
}
