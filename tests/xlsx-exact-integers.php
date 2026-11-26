<?php
declare(strict_types=1);
require dirname(__DIR__).'/src/reporting.php';
if(($argv[1]??'')==='--emit')export_excel_xlsx('precision',['مبلغ'],[['500000'],['1234567890123456789']],[0]);
$process=proc_open([PHP_BINARY,'-c',php_ini_loaded_file(),__FILE__,'--emit'],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
if(!is_resource($process))throw new RuntimeException('Could not start XLSX fixture');
fclose($pipes[0]);$bytes=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
if(proc_close($process)!==0)throw new RuntimeException('XLSX fixture failed: '.$errors);
$path=tempnam(sys_get_temp_dir(),'sodoryar_exact_');file_put_contents($path,$bytes);
try{
    $zip=new ZipArchive();if($zip->open($path)!==true)throw new RuntimeException('XLSX archive invalid');
    $sheet=$zip->getFromName('xl/worksheets/sheet1.xml');$zip->close();
    if(!str_contains($sheet,'t="n"><v>500000</v>')||!str_contains($sheet,'t="inlineStr"><is><t xml:space="preserve">1234567890123456789</t>'))throw new RuntimeException('XLSX rounded a financial integer or lost ordinary numeric cells');
}finally{unlink($path);}
echo "PASS: real XLSX keeps ordinary numeric amounts and preserves integers beyond Excel precision as exact text\n";
