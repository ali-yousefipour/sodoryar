<?php
declare(strict_types=1);

function report_filters_default(): array {
    return array_fill_keys(['date_from','date_to','national_code','invoice_number','payment_type','user_id'],'');
}

function report_filters(): array {
    $filters=[];
    foreach (['date_from','date_to','national_code','invoice_number','payment_type','user_id'] as $key) {
        $filters[$key]=ascii_digits(trim((string)($_GET[$key]??'')));
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
    $sql='FROM invoices i JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id LEFT JOIN users u ON u.id=i.user_id AND (u.company_id=i.company_id OR u.role=\'superadmin\') WHERE i.company_id=?';
    if (database_table_exists('invoice_voids')) $sql.=' AND NOT EXISTS (SELECT 1 FROM invoice_voids v WHERE v.invoice_id=i.id AND v.company_id=i.company_id)';
    $params=[$tenant];
    if (document_scope()==='own') { $sql.=' AND i.user_id=?'; $params[]=(int)user()['id']; }
    if ($filter['date_from']!=='') { $sql.=' AND COALESCE(i.invoice_date, DATE(i.created_at))>=?'; $params[]=$filter['date_from']; }
    if ($filter['date_to']!=='') { $sql.=' AND COALESCE(i.invoice_date, DATE(i.created_at))<=?'; $params[]=$filter['date_to']; }
    if ($filter['national_code']!=='') { $sql.=' AND c.national_code LIKE ?'; $params[]=$filter['national_code'].'%'; }
    if ($filter['invoice_number']!=='') { $sql.=' AND i.invoice_number LIKE ?'; $params[]='%'.$filter['invoice_number'].'%'; }
    $ledger=database_table_exists('invoice_payments');
    if ($filter['payment_type']!=='') {
        $sql.=$ledger?' AND EXISTS (SELECT 1 FROM invoice_payments pm WHERE pm.company_id=i.company_id AND pm.invoice_id=i.id AND pm.voided_at IS NULL AND pm.payment_method=?)':' AND i.payment_type=?';
        $params[]=$filter['payment_type'];
    }
    if ($filter['user_id']!=='') { $sql.=' AND i.user_id=?'; $params[]=(int)$filter['user_id']; }
    $total=query('SELECT COUNT(*) qty,COALESCE(SUM(i.total_amount),0) total '.$sql,$params)->fetch();
    $rows=query('SELECT i.id,i.invoice_number,COALESCE(i.invoice_date, DATE(i.created_at)) AS invoice_date,i.created_at,i.payment_type,i.pos_ref,i.total_amount,c.national_code,CONCAT(c.first_name,\' \',c.last_name) customer,COALESCE(u.full_name,\'—\') operator_name '.$sql.' ORDER BY i.id DESC LIMIT '.(int)$limit,$params)->fetchAll();
    if ($ledger) {
        // Apply the exact invoice filters, including tenant and document scope, before payment aggregation.
        $sourceSql='SELECT i.id,i.company_id '.$sql;
        $aggregateSql='SELECT COALESCE(SUM(CASE WHEN p.payment_method=\'cash\' THEN p.amount ELSE 0 END),0) cash_paid,COALESCE(SUM(CASE WHEN p.payment_method=\'pos\' THEN p.amount ELSE 0 END),0) pos_paid,COUNT(p.id) transaction_count FROM ('.$sourceSql.') matched JOIN invoice_payments p ON p.invoice_id=matched.id AND p.company_id=matched.company_id AND p.voided_at IS NULL';
        $aggregateParams=$params;
        if ($filter['payment_type']!=='') { $aggregateSql.=' AND p.payment_method=?'; $aggregateParams[]=$filter['payment_type']; }
        $aggregate=query($aggregateSql,$aggregateParams)->fetch();
        $total=array_merge($total,$aggregate);
        foreach($rows as &$row) {
            $parts=query('SELECT payment_method,COUNT(*) qty,SUM(amount) amount FROM invoice_payments WHERE company_id=? AND invoice_id=? AND voided_at IS NULL GROUP BY payment_method',[$tenant,$row['id']])->fetchAll();
            if(count($parts)>1) $row['payment_type']='mixed';
            elseif($parts) $row['payment_type']=$parts[0]['payment_method'];
        }
        unset($row);
    }
    return ['summary'=>$total,'rows'=>$rows];
}

/**
 * Cash/POS receipts are independent ledger events. Dates use actual paid_at,
 * not invoice issue date; no join ever multiplies invoice sales totals.
 */
function invoice_payment_report(int $tenant,array $filter,int $limit=200): array {
    if (!database_table_exists('invoice_payments')) return [
        'summary'=>['transaction_count'=>0,'cash_paid'=>0,'pos_paid'=>0],
        'rows'=>[]
    ];
    $sql=' FROM invoice_payments p JOIN invoices i ON i.id=p.invoice_id AND i.company_id=p.company_id JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id LEFT JOIN users u ON u.id=p.created_by AND (u.company_id=i.company_id OR u.role=\'superadmin\') WHERE p.company_id=? AND p.voided_at IS NULL';
    $params=[$tenant];
    if (database_table_exists('invoice_voids')) $sql.=' AND NOT EXISTS (SELECT 1 FROM invoice_voids v WHERE v.invoice_id=i.id AND v.company_id=i.company_id)';
    if (document_scope()==='own') {$sql.=' AND i.user_id=?';$params[]=(int)user()['id'];}
    if ($filter['date_from']!=='') {$sql.=' AND p.paid_at>=?';$params[]=$filter['date_from'].' 00:00:00';}
    if ($filter['date_to']!=='') {$sql.=' AND p.paid_at<?';$params[]=(new DateTimeImmutable($filter['date_to']))->modify('+1 day')->format('Y-m-d').' 00:00:00';}
    if ($filter['national_code']!=='') {$sql.=' AND c.national_code LIKE ?';$params[]=$filter['national_code'].'%';}
    if ($filter['invoice_number']!=='') {$sql.=' AND i.invoice_number LIKE ?';$params[]='%'.$filter['invoice_number'].'%';}
    if ($filter['payment_type']!=='') {$sql.=' AND p.payment_method=?';$params[]=$filter['payment_type'];}
    if ($filter['user_id']!=='') {$sql.=' AND p.created_by=?';$params[]=(int)$filter['user_id'];}
    $summary=query('SELECT COUNT(p.id) transaction_count, COALESCE(SUM(CASE WHEN p.payment_method=\'cash\' THEN p.amount ELSE 0 END),0) cash_paid,COALESCE(SUM(CASE WHEN p.payment_method=\'pos\' THEN p.amount ELSE 0 END),0) pos_paid'.$sql,$params)->fetch();
    $rows=query('SELECT p.id,p.payment_method,p.amount,p.tracking_number,p.reference_number,p.paid_at,p.terminal_id,i.invoice_number,i.id invoice_id,c.national_code,CONCAT(c.first_name,\' \',c.last_name) customer,COALESCE(u.full_name,\'—\') operator_name'.$sql.' ORDER BY p.paid_at DESC,p.id DESC LIMIT '.(int)$limit,$params)->fetchAll();
    return ['summary'=>$summary,'rows'=>$rows];
}

/**
 * Tenant-scoped financial control exceptions.
 * Never claim a bank settlement is verified: POS entries are operator declarations.
 */
function financial_control_exceptions(int $tenant,array $filter,int $limit=100): array {
    if (!invoice_payment_ledger_ready()) return [];
    $limit=max(1,min(200,$limit));
    $dateFrom=$filter['date_from']!==''?$filter['date_from']:'2026-01-01';
    $dateTo=$filter['date_to']!==''?$filter['date_to']:(new DateTimeImmutable('today'))->format('Y-m-d');
    $toExclusive=(new DateTimeImmutable($dateTo))->modify('+1 day')->format('Y-m-d');
    $scope=document_scope()==='own'?' AND i.user_id='.(int)user()['id']:'';
    $issues=[];
    $overpaid=query(
        'SELECT i.id,i.invoice_number,i.total_amount,COALESCE(SUM(p.amount),0) paid
         FROM invoices i JOIN invoice_payments p ON p.invoice_id=i.id AND p.company_id=i.company_id AND p.voided_at IS NULL
         WHERE i.company_id=? AND i.invoice_date>=? AND i.invoice_date<?'.$scope.'
         GROUP BY i.id,i.invoice_number,i.total_amount HAVING paid>i.total_amount
         ORDER BY i.id DESC LIMIT '.(int)$limit,
        [$tenant,$dateFrom,$toExclusive]
    )->fetchAll();
    foreach ($overpaid as $row) {
        $issues[]=['kind'=>'دریافت مازاد','invoice'=>$row['invoice_number'],
            'detail'=>'دریافتی '.money_toman($row['paid']).' در برابر مبلغ فاکتور '.money_toman($row['total_amount'])];
    }
    $missingTracking=query(
        'SELECT i.invoice_number,p.id,p.amount FROM invoice_payments p
         JOIN invoices i ON i.id=p.invoice_id AND i.company_id=p.company_id
         WHERE p.company_id=? AND p.payment_method=\'pos\' AND p.voided_at IS NULL
           AND (p.tracking_number IS NULL OR p.tracking_number=\'\')
           AND p.paid_at>=? AND p.paid_at<?'.$scope.'
         ORDER BY p.id DESC LIMIT '.(int)$limit,
        [$tenant,$dateFrom.' 00:00:00',$toExclusive.' 00:00:00']
    )->fetchAll();
    foreach ($missingTracking as $row) {
        $issues[]=['kind'=>'بدون شماره پیگیری کارتخوان','invoice'=>$row['invoice_number'],
            'detail'=>'شناسه دریافت: '.fa_digits((string)$row['id']).'، مبلغ: '.money_toman($row['amount'])];
    }
    $duplicates=query(
        'SELECT i.invoice_number,p.id,p.tracking_number,p.terminal_id
         FROM invoice_payments p JOIN invoices i ON i.id=p.invoice_id AND i.company_id=p.company_id
         JOIN (SELECT company_id,terminal_id,tracking_number
               FROM invoice_payments WHERE company_id=? AND payment_method=\'pos\' AND voided_at IS NULL
                 AND tracking_number IS NOT NULL AND tracking_number<>\'\'
                 AND paid_at>=? AND paid_at<?
               GROUP BY company_id,terminal_id,tracking_number HAVING COUNT(*)>1) d
             ON d.company_id=p.company_id AND d.terminal_id <=> p.terminal_id
             AND d.tracking_number=p.tracking_number
         WHERE p.company_id=? AND p.voided_at IS NULL'.$scope.'
         ORDER BY p.id DESC LIMIT '.(int)$limit,
        [$tenant,$dateFrom.' 00:00:00',$toExclusive.' 00:00:00',$tenant]
    )->fetchAll();
    foreach ($duplicates as $row) {
        $issues[]=['kind'=>'شماره پیگیری تکراری','invoice'=>$row['invoice_number'],
            'detail'=>'کارتخوان '.fa_digits((string)($row['terminal_id']??'نامشخص')).'، پیگیری '.display_financial_reference($row['tracking_number'])];
    }
    return $issues;
}

function activity_report(int $tenant, array $filter, int $limit=200): array {
    $sql='FROM activity_logs l LEFT JOIN users u ON u.id=l.user_id AND (u.company_id=l.company_id OR u.role=\'superadmin\') WHERE l.company_id=?';
    $params=[$tenant];
    if (document_scope()==='own') { $sql.=' AND l.user_id=?'; $params[]=(int)user()['id']; }
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

/** Native XLSX with styled RTL header; does not need Composer or a third-party package. */
function export_excel_xlsx(string $name, array $headers, array $rows, array $numericColumns=[]): never {
    if (!class_exists(ZipArchive::class)) throw new RuntimeException('برای خروجی XLSX باید افزونه ZIP در PHP هاست فعال باشد.');
    if (!$headers || count($headers)>26) throw new RuntimeException('ساختار ستون‌های گزارش نامعتبر است.');
    $tmp=tempnam(sys_get_temp_dir(),'sodoryar_xlsx_');
    if ($tmp===false) throw new RuntimeException('امکان ساخت فایل موقت گزارش وجود ندارد.');
    $zip=new ZipArchive();
    if ($zip->open($tmp,ZipArchive::OVERWRITE)!==true) { @unlink($tmp); throw new RuntimeException('ساخت فایل اکسل ناموفق بود.'); }
    $xml=static fn(mixed $value): string=>htmlspecialchars((string)($value??''),ENT_XML1|ENT_QUOTES,'UTF-8');
    $line=static function(array $cells,int $number,bool $header=false) use($xml,$numericColumns): string {
        $result='<row r="'.$number.'">';
        foreach(array_values($cells) as $index=>$value) {
            $cell=chr(65+$index).$number;
            if (!$header && in_array($index,$numericColumns,true) && is_numeric($value)) {
                $result.='<c r="'.$cell.'" s="2" t="n"><v>'.$xml($value).'</v></c>';
            } else {
                $result.='<c r="'.$cell.'" s="'.($header?'1':'0').'" t="inlineStr"><is><t xml:space="preserve">'.$xml($value).'</t></is></c>';
            }
        }
        return $result.'</row>';
    };
    $sheet='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView rightToLeft="1" workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetFormatPr defaultRowHeight="22"/><cols>';
    foreach($headers as $i=>$header) $sheet.='<col min="'.($i+1).'" max="'.($i+1).'" width="25" customWidth="1"/>';
    $sheet.='</cols><sheetData>'.$line($headers,1,true);
    foreach($rows as $i=>$row) $sheet.=$line(array_values($row),$i+2);
    $sheet.='</sheetData><autoFilter ref="A1:'.chr(64+count($headers)).(count($rows)+1).'"/></worksheet>';
    $zip->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="گزارش صدوریار" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Vazirmatn"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Vazirmatn"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF125B6A"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/><xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs></styleSheet>');
    $zip->addFromString('xl/worksheets/sheet1.xml',$sheet);
    if (!$zip->close()) { @unlink($tmp); throw new RuntimeException('ذخیره فایل اکسل ناموفق بود.'); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$name.'.xlsx"');
    header('Content-Length: '.filesize($tmp));
    header('Cache-Control: private, no-store');
    readfile($tmp); @unlink($tmp); exit;
}

function render_report_filters(string $page, array $filter, bool $invoice=true): void {
    $scope=document_scope();
    $users=query('SELECT id,full_name FROM users WHERE company_id=? AND is_active=1'.($scope==='own'?' AND id=?':'').' ORDER BY full_name',$scope==='own'?[tenant_id(),user()['id']]:[tenant_id()])->fetchAll();
    echo '<form method="get" class="card report-filter" data-report-filter-form><input type="hidden" name="page" value="'.e($page).'"><div class="form-grid">';
    echo '<label>از تاریخ (شمسی)<span class="jalali-field"><input type="hidden" name="date_from" value="'.e($filter['date_from']).'" data-jalali-iso><button type="button" class="jalali-picker-button secondary" data-jalali-open>انتخاب تاریخ</button><button type="button" class="jalali-clear secondary" data-jalali-clear aria-label="پاک کردن تاریخ شروع">×</button></span></label><label>تا تاریخ (شمسی)<span class="jalali-field"><input type="hidden" name="date_to" value="'.e($filter['date_to']).'" data-jalali-iso><button type="button" class="jalali-picker-button secondary" data-jalali-open>انتخاب تاریخ</button><button type="button" class="jalali-clear secondary" data-jalali-clear aria-label="پاک کردن تاریخ پایان">×</button></span></label>';
    echo '<label>کاربر<select name="user_id"><option value="">همه کاربران</option>';
    foreach($users as $u) echo '<option value="'.e($u['id']).'"'.($filter['user_id']===(string)$u['id']?' selected':'').'>'.e($u['full_name']).'</option>';
    echo '</select></label>';
    if ($invoice) {
        echo '<label>نوع پرداخت<select name="payment_type"><option value="">همه</option><option value="cash"'.($filter['payment_type']==='cash'?' selected':'').'>نقدی</option><option value="pos"'.($filter['payment_type']==='pos'?' selected':'').'>کارتخوان</option></select></label>';
        echo '<label>کد ملی راننده<input name="national_code" inputmode="numeric" maxlength="10" value="'.e($filter['national_code']).'"></label>';
        echo '<label>شماره فاکتور<input name="invoice_number" maxlength="60" value="'.e($filter['invoice_number']).'"></label>';
    }
    echo '</div><div class="filter-actions"><button>نمایش نتایج</button>';
    if (can($page === 'reports' ? 'reports.export' : 'activity.export')) {
        echo '<button type="submit" name="export" value="excel" class="secondary">خروجی اکسل</button>';
    }
    echo '<a class="button-link" href="?page='.e($page).'">پاک‌کردن فیلترها</a><span class="report-progress" data-report-progress role="status" aria-live="polite" hidden></span></div></form>';
}
