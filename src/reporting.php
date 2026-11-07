<?php
declare(strict_types=1);

function report_filters_default(): array {
    return array_fill_keys(['date_from','date_to','national_code','invoice_number','payment_type','user_id'],'');
}

function report_filters(): array {
    $filters=[];
    foreach (['date_from','date_to','national_code','invoice_number','payment_type','user_id'] as $key) {
        $filters[$key]=trim((string)($_GET[$key]??''));
    }
    foreach (['date_from','date_to'] as $dateKey) {
        if ($filters[$dateKey]!=='') {
            $date=DateTimeImmutable::createFromFormat('!Y-m-d',$filters[$dateKey]);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$filters[$dateKey]) || !$date || $date->format('Y-m-d')!==$filters[$dateKey]) throw new RuntimeException('فرمت بازه تاریخ نامعتبر است.');
        }
    }
    if ($filters['date_from']!=='' && $filters['date_to']!=='' && $filters['date_from']>$filters['date_to']) throw new RuntimeException('تاریخ شروع باید قبل از تاریخ پایان باشد.');
    if ($filters['national_code']!=='' && !preg_match('/^[0-9]{1,10}$/',$filters['national_code'])) throw new RuntimeException('کد ملی باید عددی باشد.');
    if (mb_strlen($filters['invoice_number'])>60) throw new RuntimeException('شماره فاکتور طولانی است.');
    if ($filters['payment_type']!=='' && !in_array($filters['payment_type'],['cash','pos'],true)) throw new RuntimeException('نوع پرداخت نامعتبر است.');
    if ($filters['user_id']!=='' && (!ctype_digit($filters['user_id']) || (int)$filters['user_id']<1)) throw new RuntimeException('شناسه کاربر نامعتبر است.');
    return $filters;
}

function invoice_report(int $tenant, array $filter, int $limit=200): array {
    $sql='FROM invoices i JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id JOIN users u ON u.id=i.user_id WHERE i.company_id=?';
    $params=[$tenant];
    if ($filter['date_from']!=='') { $sql.=' AND i.created_at>=?'; $params[]=$filter['date_from'].' 00:00:00'; }
    if ($filter['date_to']!=='') { $sql.=' AND i.created_at<?'; $params[]=(new DateTimeImmutable($filter['date_to']))->modify('+1 day')->format('Y-m-d').' 00:00:00'; }
    if ($filter['national_code']!=='') { $sql.=' AND c.national_code LIKE ?'; $params[]=$filter['national_code'].'%'; }
    if ($filter['invoice_number']!=='') { $sql.=' AND i.invoice_number LIKE ?'; $params[]='%'.$filter['invoice_number'].'%'; }
    if ($filter['payment_type']!=='') { $sql.=' AND i.payment_type=?'; $params[]=$filter['payment_type']; }
    if ($filter['user_id']!=='') { $sql.=' AND i.user_id=?'; $params[]=(int)$filter['user_id']; }
    $total=query('SELECT COUNT(*) qty,COALESCE(SUM(i.total_amount),0) total '.$sql,$params)->fetch();
    $rows=query('SELECT i.id,i.invoice_number,i.created_at,i.payment_type,i.pos_ref,i.total_amount,c.national_code,CONCAT(c.first_name,\' \',c.last_name) customer,u.full_name operator_name '.$sql.' ORDER BY i.id DESC LIMIT '.(int)$limit,$params)->fetchAll();
    return ['summary'=>$total,'rows'=>$rows];
}

function activity_report(int $tenant, array $filter, int $limit=200): array {
    $sql='FROM activity_logs l LEFT JOIN users u ON u.id=l.user_id WHERE l.company_id=?';
    $params=[$tenant];
    if ($filter['date_from']!=='') { $sql.=' AND l.created_at>=?'; $params[]=$filter['date_from'].' 00:00:00'; }
    if ($filter['date_to']!=='') { $sql.=' AND l.created_at<?'; $params[]=(new DateTimeImmutable($filter['date_to']))->modify('+1 day')->format('Y-m-d').' 00:00:00'; }
    if ($filter['user_id']!=='') { $sql.=' AND l.user_id=?'; $params[]=(int)$filter['user_id']; }
    return query('SELECT l.created_at,COALESCE(u.full_name,\'—\') full_name,l.action,l.details '.$sql.' ORDER BY l.id DESC LIMIT '.(int)$limit,$params)->fetchAll();
}

function export_excel_xml(string $name, array $headers, array $rows): never {
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="'.$name.'.xls"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    $escape=static fn(mixed $v): string=>htmlspecialchars((string)($v??''),ENT_XML1|ENT_QUOTES,'UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?>';
    echo '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"><Styles><Style ss:ID="head"><Font ss:Bold="1" ss:Color="#FFFFFF"/><Interior ss:Color="#106C78" ss:Pattern="Solid"/></Style><Style ss:ID="body"><Font ss:Color="#183B46"/></Style></Styles><Worksheet ss:Name="Report"><Table>';
    echo '<Row>'; foreach($headers as $header) echo '<Cell ss:StyleID="head"><Data ss:Type="String">'.$escape($header).'</Data></Cell>'; echo '</Row>';
    foreach($rows as $row) { echo '<Row>'; foreach($row as $value) echo '<Cell ss:StyleID="body"><Data ss:Type="String">'.$escape($value).'</Data></Cell>'; echo '</Row>'; }
    echo '</Table></Worksheet></Workbook>'; exit;
}

function render_report_filters(string $page, array $filter, bool $invoice=true): void {
    $users=query('SELECT id,full_name FROM users WHERE company_id=? AND is_active=1 ORDER BY full_name',[tenant_id()])->fetchAll();
    echo '<form method="get" class="card report-filter"><input type="hidden" name="page" value="'.e($page).'"><div class="form-grid">';
    echo '<label>از تاریخ (میلادی)<input type="date" name="date_from" value="'.e($filter['date_from']).'"></label><label>تا تاریخ (میلادی)<input type="date" name="date_to" value="'.e($filter['date_to']).'"></label>';
    echo '<label>کاربر<select name="user_id"><option value="">همه کاربران</option>';
    foreach($users as $u) echo '<option value="'.e($u['id']).'"'.($filter['user_id']===(string)$u['id']?' selected':'').'>'.e($u['full_name']).'</option>';
    echo '</select></label>';
    if ($invoice) {
        echo '<label>نوع پرداخت<select name="payment_type"><option value="">همه</option><option value="cash"'.($filter['payment_type']==='cash'?' selected':'').'>نقدی</option><option value="pos"'.($filter['payment_type']==='pos'?' selected':'').'>کارتخوان</option></select></label>';
        echo '<label>کد ملی راننده<input name="national_code" inputmode="numeric" maxlength="10" value="'.e($filter['national_code']).'"></label>';
        echo '<label>شماره فاکتور<input name="invoice_number" maxlength="60" value="'.e($filter['invoice_number']).'"></label>';
    }
    echo '</div><div class="filter-actions"><button>نمایش نتایج</button><button type="submit" name="export" value="excel" class="secondary">خروجی اکسل</button><a class="button-link" href="?page='.e($page).'">پاک‌کردن فیلترها</a></div></form>';
}
