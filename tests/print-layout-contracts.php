<?php
declare(strict_types=1);
$css=(string)file_get_contents(__DIR__.'/../public/assets/print-engine.css');
$modules=(string)file_get_contents(__DIR__.'/../src/modules.php');
$index=(string)file_get_contents(__DIR__.'/../public/index.php');
foreach(['@page sodoryarA5','size:A5 portrait','@page sodoryarThermal','--thermal-content-width','display:table-header-group'] as $needle){
    if(!str_contains($css,$needle)) throw new RuntimeException('Print layout missing: '.$needle);
}
if(!str_contains($modules,"'roll_width_mm'")) throw new RuntimeException('Missing printable width setting');
if(!str_contains($index,"receipt-thermal")) throw new RuntimeException('Missing thermal receipt selection');
if(!str_contains($index,"print-engine.css")) throw new RuntimeException('Print stylesheet not linked');
echo "A5 and thermal print static-contract checks passed\n";
