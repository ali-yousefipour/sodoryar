<?php
declare(strict_types=1);

/** Read-only installation checks: no production DDL or sensitive values. */
function sodoryar_finance_schema_checks(): array {
    $tableChecks=[
        'دفتر دریافت‌ها (۰۱۶)'=>['invoice_payments'],
        'کلید یکتای دریافت (۰۱۷)'=>['invoice_payments','invoice_payments.request_key'],
        'صندوق و شیفت مالی (۰۱۸)'=>['cash_registers','cash_shifts','cash_shift_adjustments','invoice_payments','invoice_payments.cash_shift_id'],
        'قفل دوره مالی (۰۱۹)'=>['financial_period_locks'],
        'تاریخچه تعرفه (۰۲۰)'=>['service_rate_versions'],
        'تنظیمات شخصی (۰۲۱)'=>['user_preferences'],
        'رسیدگی به مغایرت‌ها (۰۲۲)'=>['financial_issue_reviews'],
        'درخواست یکتای فاکتور (۰۲۳)'=>['invoice_create_requests'],
        'چندشعبه‌ای (۰۲۴)'=>['branches','user_branch_assignments','pos_branch_assignments','banks','invoices.branch_id','invoice_payments.branch_id','cash_registers.branch_id'],
        'حساب بانکی شرکت و شعبه (۰۲۵)'=>['company_bank_accounts','pos_account_registry'],
        'اسناد هزینه (۰۲۶)'=>['expenses.request_hash','expenses.classification','expense_categories.classification','expense_items.quantity','expense_payments.account_id','expense_events.event_type'],
        'تسویه و ابطال هزینه (۰۲۷)'=>['expense_settlements','expense_reversals'],
        'دریافت واقعی برگشت وجه (۰۲۸)'=>['expense_returns.request_hash','expense_returns.reference','expense_returns.return_date'],
        'گردش تأیید و پیوست خصوصی (۰۲۹)'=>['expense_policies','expense_branch_approvers','expense_mutation_requests','expense_attachments.content_hash','expenses.version','expenses.subtotal','expenses.extra_amount','expenses.discount_amount'],
    ];
    $tables=query('SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE()')->fetchAll(PDO::FETCH_COLUMN);
    $known=array_fill_keys($tables,true);
    $cols=[];
    foreach(query('SELECT TABLE_NAME,COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE()')->fetchAll() as $column)$cols[$column['TABLE_NAME'].'.'.$column['COLUMN_NAME']]=true;
    $results=[];
    foreach($tableChecks as $label=>$needs){
        $missing=[];
        foreach($needs as $thing){
            if(str_contains($thing,'.')) {
                if(!isset($cols[$thing]))$missing[]=$thing;
            }elseif(!isset($known[$thing]))$missing[]=$thing;
        }
        $results[]=['label'=>$label,'installed'=>count($missing)===0,'missing'=>$missing];
    }
    return $results;
}
function render_finance_schema_health(int $tenant): void {
    require_permission('periods.manage');
    $checks=sodoryar_finance_schema_checks();
    $complete=count(array_filter($checks,static fn($check)=>$check['installed']));
    echo '<div class="heading"><h1>سلامت ماژول‌های مالی و پایگاه داده</h1></div>';
    echo '<section class="card"><p class="muted">این بخش فقط ساختار دیتابیس شرکت انتخابی را بررسی می‌کند و هیچ داده یا جدول را تغییر نمی‌دهد. موفقیت اجرای GitHub Actions به معنی نصب خودکار Migrationها روی دیتابیس واقعی نیست.</p>';
    echo '<p>ساختارهای شناسایی‌شده: '.fa_digits((string)$complete).' از '.fa_digits((string)count($checks)).'</p>';
    echo '<div class="table-wrap"><table><thead><tr><th>بخش</th><th>وضعیت</th><th>ساختارهای موردنیاز</th></tr></thead><tbody>';
    foreach($checks as $check){
        echo '<tr><td>'.e($check['label']).'</td><td><span class="status-chip '.($check['installed']?'status-good':'status-muted').'">'
         .($check['installed']?'نصب‌شده':'نیازمند بررسی و نصب').'</span></td><td>'
         .($check['missing']?e(implode('، ',$check['missing'])):'—').'</td></tr>';
    }
    echo '</tbody></table></div><p class="muted">برای اجرای هر Migration ابتدا پشتیبان معتبر و قابل بازیابی تهیه کنید؛ راهنمای نصب و بازیابی در مخزن پروژه قرار دارد.</p></section>';
    if(invoice_payment_ledger_ready()){
        $scope=document_scope()==='own'?' AND i.user_id='.(int)user()['id']:'';
        $void=database_table_exists('invoice_voids')?
            ' AND NOT EXISTS (SELECT 1 FROM invoice_voids v WHERE v.invoice_id=i.id AND v.company_id=i.company_id)':'';
        $sales=query('SELECT COUNT(*) count_invoices,COALESCE(SUM(i.total_amount),0) amount
             FROM invoices i WHERE i.company_id=?'.$scope.$void,[$tenant])->fetch();
        $received=query('SELECT COUNT(p.id) count_payments,COALESCE(SUM(p.amount),0) amount
             FROM invoice_payments p JOIN invoices i ON i.id=p.invoice_id AND i.company_id=p.company_id
             WHERE p.company_id=? AND p.voided_at IS NULL'.$scope.$void,[$tenant])->fetch();
        echo '<section class="card"><h2>کنترل تجمیعی اسناد مالی معتبر شرکت</h2><div class="stats">'
           .'<article class="stat"><span>تعداد فاکتور</span><b>'.fa_digits((string)$sales['count_invoices']).'</b></article>'
           .'<article class="stat"><span>مبلغ فاکتورها</span><b>'.money_toman($sales['amount']).'</b></article>'
           .'<article class="stat"><span>تعداد تراکنش</span><b>'.fa_digits((string)$received['count_payments']).'</b></article>'
           .'<article class="stat"><span>مبلغ دریافت‌های ثبت‌شده</span><b>'.money_toman($received['amount']).'</b></article></div>'
           .'<p class="muted">این ارقام فقط ثبت‌های داخلی هستند؛ تأیید مستقل بانکی یا تطبیق کامل با دفاتر قانونی محسوب نمی‌شوند.</p></section>';
    }
}
