<?php
declare(strict_types=1);

/** Period closures are immutable, tenant-scoped, and checked on every financial write. */
function accounting_periods_ready(): bool {
    static $ready=null;
    if($ready===null) $ready=(bool)query("SHOW TABLES LIKE 'financial_period_locks'")->fetchColumn();
    return $ready;
}
function assert_accounting_date_open(int $tenant,string $date): void {
    if(!accounting_periods_ready()) return;
    $date=substr($date,0,10);
    $parsed=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if(!$parsed || $parsed->format('Y-m-d')!==$date) throw new RuntimeException('تاریخ سند مالی نامعتبر است.');
    $closed=query('SELECT id FROM financial_period_locks
      WHERE company_id=? AND start_date<=? AND end_date>=? LIMIT 1',
      [$tenant,$date,$date])->fetch();
    if($closed) throw new RuntimeException('دوره مالی این سند بسته شده است؛ ثبت یا اصلاح مستقیم مجاز نیست.');
}
function accounting_lock_period(int $tenant,int $actor,string $jalaliStart,string $jalaliEnd,string $reason): int {
    if(!accounting_periods_ready()) throw new RuntimeException('برای بستن دوره، ابتدا میگرشن ۰۱۹ را اجرا کنید.');
    require_permission('periods.manage');
    if(mb_strlen($reason)<5 || mb_strlen($reason)>500) throw new RuntimeException('دلیل بستن دوره بین ۵ تا ۵۰۰ نویسه الزامی است.');
    $start=jalali_to_gregorian_date($jalaliStart);
    $end=jalali_to_gregorian_date($jalaliEnd);
    $days=(new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days;
    if($start>$end || $days>31) throw new RuntimeException('بازه دوره باید حداکثر ۳۲ روز باشد.');
    if($end>(new DateTimeImmutable('today'))->format('Y-m-d')) throw new RuntimeException('بستن دوره آینده مجاز نیست.');
    db()->beginTransaction();
    try {
        // Serializes overlapping period creation in one company, including empty tables.
        if(!query('SELECT id FROM companies WHERE id=? AND is_active=1 FOR UPDATE',[$tenant])->fetch()) {
            throw new RuntimeException('شرکت فعال پیدا نشد.');
        }
        if(query('SELECT id FROM financial_period_locks WHERE company_id=?
            AND NOT (end_date<? OR start_date>?) LIMIT 1',[$tenant,$start,$end])->fetch()) {
            throw new RuntimeException('این بازه با دوره بسته‌شده دیگری همپوشانی دارد.');
        }
        if(function_exists('cashbox_ready') && cashbox_ready()) {
            $pending=query("SELECT id FROM cash_shifts WHERE company_id=? AND status IN ('open','pending','rejected')
                AND DATE(started_at)<=? AND (closed_at IS NULL OR DATE(closed_at)>=?) LIMIT 1",[$tenant,$end,$start])->fetch();
            if($pending) throw new RuntimeException('برای بستن دوره، ابتدا صندوق‌های باز یا در انتظار بررسی را تسویه کنید.');
        }
        query('INSERT INTO financial_period_locks(company_id,start_date,end_date,locked_by,reason) VALUES(?,?,?,?,?)',
            [$tenant,$start,$end,$actor,$reason]);
        $id=(int)db()->lastInsertId();
        audit('accounting.period.lock',json_encode(['id'=>$id,'start'=>$start,'end'=>$end,'reason'=>$reason],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
        db()->commit();
        return $id;
    } catch(Throwable $ex) {db()->rollBack();throw $ex;}
}
function render_accounting_periods(int $tenant): void {
    require_permission('periods.manage');
    echo '<div class="heading"><h1>قفل دوره‌های مالی</h1></div>';
    if(!accounting_periods_ready()){
        echo '<div class="card"><p>پس از پشتیبان‌گیری معتبر و اجرای مهاجرت ۰۱۹، امکان بستن دوره فعال می‌شود.</p></div>';
        return;
    }
    echo '<section class="card"><h2>بستن دوره مالی</h2><p class="muted">اسناد تاریخ‌های این بازه پس از تأیید تغییر یا دریافت جدید نمی‌پذیرند. پیش از ثبت، مغایرت‌ها و صندوق‌ها را بررسی کنید.</p>'
      .'<form method="post" class="form-grid">'.csrf_field()
      .'<input type="hidden" name="action" value="lock_period">'
      .'<label>شروع دوره (شمسی)<input name="date_from" placeholder="۱۴۰۵/۰۸/۰۱" inputmode="numeric" required></label>'
      .'<label>پایان دوره (شمسی)<input name="date_to" placeholder="۱۴۰۵/۰۸/۳۰" inputmode="numeric" required></label>'
      .'<label>دلیل بستن دوره<textarea name="reason" minlength="5" maxlength="500" required></textarea></label>'
      .'<button type="submit" data-confirm="از بسته شدن این دوره مالی اطمینان دارید؟">بستن قطعی دوره</button></form></section>';
    $periods=query('SELECT start_date,end_date,reason,created_at,locked_by FROM financial_period_locks
        WHERE company_id=? ORDER BY id DESC LIMIT 36',[$tenant])->fetchAll();
    echo '<section class="card"><h2>دوره‌های بسته‌شده</h2><div class="table-wrap"><table><thead><tr>'
      .'<th>تاریخ شروع</th><th>تاریخ پایان</th><th>تاریخ ثبت</th><th>علت</th></tr></thead><tbody>';
    foreach($periods as $period){
        echo '<tr><td>'.e(jalali_datetime($period['start_date'],true)).'</td>'
          .'<td>'.e(jalali_datetime($period['end_date'],true)).'</td>'
          .'<td>'.e(jalali_datetime($period['created_at'],true)).'</td>'
          .'<td>'.e($period['reason']).'</td></tr>';
    }
    if(!$periods)echo '<tr><td colspan="4">دوره بسته‌شده‌ای وجود ندارد.</td></tr>';
    echo '</tbody></table></div></section>';
}
