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
    $sql='FROM invoices i JOIN customers c ON c.id=i.customer_id AND c.company_id=i.company_id JOIN users u ON u.id=i.user_id WHERE i.company_id=?';
    if (database_table_exists('invoice_voids')) $sql.=' AND NOT EXISTS (SELECT 1 FROM invoice_voids v WHERE v.invoice_id=i.id AND v.company_id=i.company_id)';
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

/** Native XLSX with styled RTL header; does not need Composer or a third-party package. */
function export_excel_xlsx(string $name, array $headers, array $rows): never {
    if (!class_exists(ZipArchive::class)) throw new RuntimeException('برای خروجی XLSX باید افزونه ZIP در PHP هاست فعال باشد.');
    if (!$headers || count($headers)>26) throw new RuntimeException('ساختار ستون‌های گزارش نامعتبر است.');
    $tmp=tempnam(sys_get_temp_dir(),'sodoryar_xlsx_');
    if ($tmp===false) throw new RuntimeException('امکان ساخت فایل موقت گزارش وجود ندارد.');
    $zip=new ZipArchive();
    if ($zip->open($tmp,ZipArchive::OVERWRITE)!==true) { @unlink($tmp); throw new RuntimeException('ساخت فایل اکسل ناموفق بود.'); }
    $xml=static fn(mixed $value): string=>htmlspecialchars((string)($value??''),ENT_XML1|ENT_QUOTES,'UTF-8');
    $line=static function(array $cells,int $number,bool $header=false) use($xml): string {
        $result='<row r="'.$number.'">';
        foreach(array_values($cells) as $index=>$value) {
            $cell=chr(65+$index).$number;
            $result.='<c r="'.$cell.'" s="'.($header?'1':'0').'" t="inlineStr"><is><t xml:space="preserve">'.$xml($value).'</t></is></c>';
        }
        return $result.'</row>';
    };
    $sheet='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView rightToLeft="1" workbookViewId="0"/></sheetViews><sheetFormatPr defaultRowHeight="22"/><cols>';
    foreach($headers as $i=>$header) $sheet.='<col min="'.($i+1).'" max="'.($i+1).'" width="25" customWidth="1"/>';
    $sheet.='</cols><sheetData>'.$line($headers,1,true);
    foreach($rows as $i=>$row) $sheet.=$line(array_values($row),$i+2);
    $sheet.='</sheetData></worksheet>';
    $zip->addFromString('[Content_Types].xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
    $zip->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $zip->addFromString('xl/workbook.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="گزارش صدوریار" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
    $zip->addFromString('xl/styles.xml','<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Tahoma"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Tahoma"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF125B6A"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs></styleSheet>');
    $zip->addFromString('xl/worksheets/sheet1.xml',$sheet);
    if (!$zip->close()) { @unlink($tmp); throw new RuntimeException('ذخیره فایل اکسل ناموفق بود.'); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="'.$name.'.xlsx"');
    header('Content-Length: '.filesize($tmp));
    header('Cache-Control: private, no-store');
    readfile($tmp); @unlink($tmp); exit;
}

function render_report_filters(string $page, array $filter, bool $invoice=true): void {
    $users=query('SELECT id,full_name FROM users WHERE company_id=? AND is_active=1 ORDER BY full_name',[tenant_id()])->fetchAll();
    echo '<form method="get" class="card report-filter"><input type="hidden" name="page" value="'.e($page).'"><div class="form-grid">';
    echo '<label>از تاریخ (شمسی)<span class="jalali-field"><input type="hidden" name="date_from" value="'.e($filter['date_from']).'" data-jalali-iso><button type="button" class="jalali-picker-button secondary" data-jalali-open>انتخاب تاریخ</button><button type="button" class="jalali-clear secondary" data-jalali-clear aria-label="پاک کردن تاریخ شروع">×</button></span></label><label>تا تاریخ (شمسی)<span class="jalali-field"><input type="hidden" name="date_to" value="'.e($filter['date_to']).'" data-jalali-iso><button type="button" class="jalali-picker-button secondary" data-jalali-open>انتخاب تاریخ</button><button type="button" class="jalali-clear secondary" data-jalali-clear aria-label="پاک کردن تاریخ پایان">×</button></span></label>';
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
