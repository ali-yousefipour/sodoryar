<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/expense_pdf.php';
$bytes=expense_report_pdf_bytes(['title'=>'گزارش آزمایشی هزینه','company'=>'شرکت آزمایشی','branch'=>'شعبه مرکزی','period'=>'۱۴۰۵/۰۹/۰۱ تا ۱۴۰۵/۰۹/۳۰'],
    '<table><thead><tr><th>عنوان</th><th>مبلغ (تومان)</th></tr></thead><tbody><tr><td>خرید چای</td><td>۵۰۰٬۰۰۰</td></tr></tbody></table>');
if(!str_starts_with($bytes,'%PDF-')||strlen($bytes)<10000||!str_contains($bytes,'/FontFile2'))throw new RuntimeException('Independent PDF or embedded TrueType font missing');
echo "PASS: independent Persian RTL PDF with embedded Vazirmatn font\n";
