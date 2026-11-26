<?php
declare(strict_types=1);

function expense_report_pdf_bytes(array $context,string $table): string {
    $autoload=dirname(__DIR__).'/vendor/autoload.php';
    if(!is_file($autoload))throw new RuntimeException('کتابخانه PDF روی میزبان نصب نشده است.');
    require_once $autoload;
    $temp=dirname(__DIR__).'/storage/pdf-cache';
    if(!is_dir($temp)&&!mkdir($temp,0700,true)&&!is_dir($temp))throw new RuntimeException('مسیر موقت PDF در دسترس نیست.');
    $pdf=new \Mpdf\Mpdf([
        'mode'=>'utf-8','format'=>'A4-L','tempDir'=>$temp,'margin_top'=>22,'margin_bottom'=>18,
        'fontDir'=>[__DIR__.'/fonts'],
        'fontdata'=>['vazirmatn'=>['R'=>'Vazirmatn-Regular.ttf','B'=>'Vazirmatn-Bold.ttf','useOTL'=>0xFF,'useKashida'=>75]],
        'default_font'=>'vazirmatn','default_font_size'=>9,
    ]);
    $pdf->SetDirectionality('rtl');$pdf->SetTitle($context['title']);$pdf->SetAuthor($context['company']);
    $pdf->SetHTMLHeader('<div style="font-family:vazirmatn;color:#26234d;text-align:right">'.e($context['company']).' | صدوریار</div>');
    $pdf->SetHTMLFooter('<div style="font-family:vazirmatn;text-align:center">صفحه {PAGENO} از {nbpg}</div>');
    $html='<html lang="fa" dir="rtl"><head><style>body{font-family:vazirmatn;direction:rtl;color:#173c3c}h1{font-size:16pt;color:#26234d}table{width:100%;border-collapse:collapse;direction:rtl}th,td{border:1px solid #d9e2e0;padding:6px;text-align:right;vertical-align:top}thead th,tfoot th{background:#ecebf4;color:#26234d}p{font-size:9pt}</style></head><body>';
    $html.='<h1>'.e($context['title']).'</h1><p>'.e($context['company'].' | '.$context['branch'].' | '.$context['period']).'</p>';
    $html.=$table.'<p>واحد مبالغ تومان است. خالص عملکرد پس از هزینه‌های جاری ثبت‌شده، سود خالص حسابداری نیست.</p></body></html>';
    $pdf->WriteHTML($html);
    return $pdf->OutputBinaryData();
}
function expense_report_pdf(array $context,string $table): never {
    $bytes=expense_report_pdf_bytes($context,$table);
    header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="sodoryar-expenses.pdf"');
    header('Content-Length: '.strlen($bytes));header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
    echo $bytes;exit;
}
