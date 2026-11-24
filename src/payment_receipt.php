<?php
declare(strict_types=1);

/** Tenant-secured printable receipt for one immutable payment event. */
function render_individual_payment_receipt(int $tenant,int $paymentId): void {
    require_permission('invoices.view');
    require_permission('invoices.print');
    if(!invoice_payment_ledger_ready()){
        echo '<p class="alert">دفتر دریافت‌ها هنوز فعال نیست.</p>';return;
    }
    $payment=query('SELECT p.id,p.amount,p.payment_method,p.tracking_number,p.reference_number,p.terminal_id,
      p.paid_at,p.created_by,p.voided_at,i.invoice_number,i.total_amount,i.user_id,c.first_name,c.last_name,c.national_code,
      u.full_name operator_name
      FROM invoice_payments p
      JOIN invoices i ON i.id=p.invoice_id AND i.company_id=p.company_id
      JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id
      LEFT JOIN users u ON u.id=p.created_by AND (u.company_id=p.company_id OR u.role=\'superadmin\')
      WHERE p.company_id=? AND p.id=? LIMIT 1',[$tenant,$paymentId])->fetch();
    if(!$payment){echo '<div class="alert error">رسید پرداخت پیدا نشد.</div>';return;}
    require_document_owner((int)$payment['user_id']);
    $voidInvoice=database_table_exists('invoice_voids')?query(
        'SELECT invoice_id FROM invoice_voids WHERE company_id=? AND invoice_id=(SELECT invoice_id FROM invoice_payments WHERE company_id=? AND id=?)',
        [$tenant,$tenant,$paymentId])->fetch():null;
    $voided=($payment['voided_at']!==null)||$voidInvoice!==false&&$voidInvoice!==null;
    $template=query('SELECT id,logo_path,paper_type FROM print_templates WHERE company_id=? ORDER BY is_default DESC,id DESC LIMIT 1',[$tenant])->fetch();
    $company=query('SELECT name FROM companies WHERE id=?',[$tenant])->fetchColumn();
    $paper=$template['paper_type']??'a5';
    $class=$paper==='thermal80'?'receipt-thermal':($paper==='a4'?'receipt-a4':'receipt-a5');
    echo '<section class="receipt card '.$class.($voided?' receipt-voided':'').'"><div class="receipt-toolbar">'
         .'<button type="button" data-print-receipt>چاپ رسید دریافت وجه</button></div>';
    if($template && $template['logo_path']){
        echo '<img class="receipt-logo" src="?page=template_logo&amp;id='.(int)$template['id'].'" alt="لوگوی شرکت">';
    }
    echo '<h1>رسید مستقل دریافت وجه</h1><p>'.e($company).'</p>';
    if($voided)echo '<div class="receipt-void-label">سند باطل‌شده — فاقد اعتبار مالی</div>';
    echo '<p>شناسه یکتای رسید: '.e(fa_digits((string)$payment['id'])).'</p>'
      .'<p>شماره فاکتور: '.e(display_financial_reference($payment['invoice_number'])).'</p>'
      .'<p>تاریخ و ساعت دریافت: '.e(jalali_datetime($payment['paid_at'],true)).'</p>'
      .'<p>تاکسیران: '.e($payment['first_name'].' '.$payment['last_name']).'</p>'
      .'<p>کد ملی: '.e(fa_digits($payment['national_code'])).'</p>'
      .'<p>روش پرداخت: '.($payment['payment_method']==='cash'?'نقدی':'کارتخوان').'</p>';
    if($payment['terminal_id']) echo '<p>شناسه کارتخوان: '.e(fa_digits((string)$payment['terminal_id'])).'</p>';
    if($payment['tracking_number'])echo '<p>شماره پیگیری: '.e(display_financial_reference($payment['tracking_number'])).'</p>';
    if($payment['reference_number'])echo '<p>شماره مرجع: '.e(display_financial_reference($payment['reference_number'])).'</p>';
    echo '<h2>مبلغ این دریافت: '.money_toman($payment['amount']).'</h2>'
      .'<p>دریافت‌کننده: '.e($payment['operator_name']?:'—').'</p>'
       .(!$voided?'<p class="receipt-payment-note">این دریافت در سامانه به‌صورت قطعی ثبت شده است.</p>':'')
      .'</section>';
}
