<?php
declare(strict_types=1);

/** Bounded, permission-filtered, tenant-isolated global search results. */
function sodoryar_global_search(int $tenant,string $needle): array {
    $q=mb_substr(trim(ascii_digits($needle)),0,80);
    $result=['customers'=>[],'invoices'=>[],'payments'=>[],'vehicles'=>[],'services'=>[]];
    if (mb_strlen($q)<3 || str_contains($q,'%') || str_contains($q,'_') || str_contains($q,chr(92))
        || (preg_match('/^[0-9]+$/D',$q) && mb_strlen($q)<6)) return $result;
    $prefix=$q.'%';
    $contains='%'.$q.'%';
    if (can('customers.view')) {
        $rows=query('SELECT national_code,first_name,last_name FROM customers
            WHERE company_id=? AND (national_code LIKE ? OR mobile LIKE ? OR first_name LIKE ? OR last_name LIKE ?)
            ORDER BY id DESC LIMIT 6',[$tenant,$prefix,$prefix,$contains,$contains])->fetchAll();
        foreach($rows as $row) {
            $result['customers'][]=[
                'title'=>$row['first_name'].' '.$row['last_name'],
                'detail'=>fa_digits($row['national_code']),
                'url'=>can('invoices.view')?'?page=invoice_manager&q='.rawurlencode($row['national_code']):'?page=customers'
            ];
        }
    }
    if (can('customers.view') && mb_strlen($q)>=5 && (bool)query("SHOW TABLES LIKE 'global_drivers'")->fetchColumn()) {
        $vehicles=query('SELECT c.national_code,c.first_name,c.last_name,g.vehicle_plate
            FROM customers c JOIN global_drivers g ON g.national_code=c.national_code
            WHERE c.company_id=? AND g.vehicle_plate LIKE ?
            ORDER BY c.id DESC LIMIT 6',[$tenant,$contains])->fetchAll();
        foreach($vehicles as $vehicle) {
            $result['vehicles'][]=[
              'title'=>fa_digits((string)$vehicle['vehicle_plate']),
              'detail'=>$vehicle['first_name'].' '.$vehicle['last_name'],
              'url'=>can('invoices.view')?'?page=invoice_manager&q='.rawurlencode($vehicle['national_code']):'?page=customers'
            ];
        }
    }
    if (can('invoices.view')) {
        $scope=document_scope()==='own'?' AND i.user_id='.(int)user()['id']:'';
        $rows=query('SELECT i.id,i.invoice_number,c.first_name,c.last_name
            FROM invoices i JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id
            WHERE i.company_id=? AND (i.invoice_number LIKE ? OR c.national_code LIKE ?)'.$scope.'
            ORDER BY i.id DESC LIMIT 6',[$tenant,$contains,$prefix])->fetchAll();
        foreach($rows as $row) {
            $result['invoices'][]=[
                'title'=>display_financial_reference($row['invoice_number']),
                'detail'=>$row['first_name'].' '.$row['last_name'],
                'url'=>can('invoices.print')?'?page=receipt&id='.(int)$row['id']
                    :'?page=invoice_manager&q='.rawurlencode($row['invoice_number'])
            ];
        }
        if (invoice_payment_ledger_ready()) {
            $paymentId=ctype_digit($q) && strlen($q)<=18?(int)$q:0;
            if(preg_match('/^(?:تراکنش|پرداخت)[ -]*([0-9]{1,18})$/u',$q,$paymentMatch))$paymentId=(int)$paymentMatch[1];
            $rows=query('SELECT DISTINCT i.id,i.invoice_number,p.id payment_id,p.tracking_number
                FROM invoice_payments p JOIN invoices i ON i.id=p.invoice_id AND i.company_id=p.company_id
                WHERE p.company_id=? AND p.voided_at IS NULL
                  AND (p.tracking_number LIKE ? OR p.reference_number LIKE ? OR p.id=?)'.$scope.'
                ORDER BY i.id DESC LIMIT 6',[$tenant,$contains,$contains,$paymentId])->fetchAll();
            foreach($rows as $row) {
                $result['payments'][]=[
                    'title'=>display_financial_reference($row['tracking_number']?:'تراکنش '.$row['payment_id']),
                    'detail'=>'فاکتور '.display_financial_reference($row['invoice_number']),
                    'url'=>can('invoices.print')?'?page=payment_receipt&id='.(int)$row['payment_id']
                        :'?page=invoice_manager&q='.rawurlencode($row['invoice_number'])
                ];
            }
        }
    }
    if (can('services.view')) {
        $rows=query('SELECT title,price FROM services WHERE company_id=? AND title LIKE ?
            ORDER BY is_active DESC,id DESC LIMIT 6',[$tenant,$contains])->fetchAll();
        foreach($rows as $row) {
            $result['services'][]=['title'=>$row['title'],'detail'=>money_toman($row['price']),'url'=>'?page=services'];
        }
    }
    return $result;
}
