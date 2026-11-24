<?php
declare(strict_types=1);

/** Current financial dashboard values use actual receipt timestamps, not invoice dates. */
function dashboard_financial_snapshot(int $tenant): array {
    require_permission('reports.view');
    $day=new DateTimeImmutable('today',new DateTimeZone('Asia/Tehran'));
    $today=$day->format('Y-m-d');
    $tomorrow=$day->modify('+1 day')->format('Y-m-d');
    $empty=[
        'receipts_today'=>0,'cash_today'=>0,'pos_today'=>0,
        'due_total'=>0,'unsettled_count'=>0,
        'open_cashboxes'=>0,'pending_cashboxes'=>0,'unreviewed_issues'=>0,
        'ledger_ready'=>invoice_payment_ledger_ready()
    ];
    if(!invoice_payment_ledger_ready())return $empty;
    $scope=document_scope()==='own'?' AND i.user_id='.(int)user()['id']:'';
    $activeInvoice=database_table_exists('invoice_voids')?
       ' AND NOT EXISTS (SELECT 1 FROM invoice_voids v WHERE v.invoice_id=i.id AND v.company_id=i.company_id)':'';
    $receipt=query('SELECT COALESCE(SUM(CASE WHEN p.payment_method=\'cash\' THEN p.amount ELSE 0 END),0) cash_total,
        COALESCE(SUM(CASE WHEN p.payment_method=\'pos\' THEN p.amount ELSE 0 END),0) pos_total
        FROM invoice_payments p JOIN invoices i ON i.id=p.invoice_id AND i.company_id=p.company_id
        WHERE p.company_id=? AND p.voided_at IS NULL
        AND p.paid_at>=? AND p.paid_at<?'.$scope.$activeInvoice,
        [$tenant,$today.' 00:00:00',$tomorrow.' 00:00:00'])->fetch();
    $due=query('SELECT
        COALESCE(SUM(GREATEST(i.total_amount-COALESCE(pay.paid,0),0)),0) total_due,
        COALESCE(SUM(CASE WHEN COALESCE(pay.paid,0)<i.total_amount THEN 1 ELSE 0 END),0) unsettled
        FROM invoices i LEFT JOIN
        (SELECT company_id,invoice_id,SUM(amount) paid FROM invoice_payments
         WHERE company_id=? AND voided_at IS NULL GROUP BY company_id,invoice_id) pay
        ON pay.company_id=i.company_id AND pay.invoice_id=i.id
        WHERE i.company_id=?'.$scope.$activeInvoice,[$tenant,$tenant])->fetch();
    // Both methods are final receipts on successful posting; no bank approval state.
    $empty['cash_today']=(int)$receipt['cash_total'];
    $empty['pos_today']=(int)$receipt['pos_total'];
    $empty['receipts_today']=$empty['cash_today']+$empty['pos_today'];
    $empty['due_total']=(int)$due['total_due'];
    $empty['unsettled_count']=(int)$due['unsettled'];
    if(function_exists('cashbox_ready')&&cashbox_ready()){
        $operatorScope=document_scope()==='own'||!can('cashbox.manage')?
            ' AND s.operator_id='.(int)user()['id']:'';
        $cashbox=query("SELECT
            COALESCE(SUM(s.status='open'),0) open_boxes,
            COALESCE(SUM(s.status='pending'),0) pending_boxes
            FROM cash_shifts s WHERE s.company_id=?".$operatorScope,[$tenant])->fetch();
        $empty['open_cashboxes']=(int)$cashbox['open_boxes'];
        $empty['pending_cashboxes']=(int)$cashbox['pending_boxes'];
    }
    $issues=financial_control_exceptions($tenant,[
        'date_from'=>(new DateTimeImmutable('today'))->modify('-30 days')->format('Y-m-d'),
        'date_to'=>$today
    ],100);
    if(function_exists('financial_issue_latest_reviews')){
        $reviews=financial_issue_latest_reviews($tenant,$issues);
        foreach($issues as $issue){
            $key=financial_issue_fingerprint($tenant,$issue);
            if(!isset($reviews[$key]))$empty['unreviewed_issues']++;
        }
    }else{$empty['unreviewed_issues']=count($issues);}
    return $empty;
}
