<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require __DIR__.'/../src/localization.php';
require __DIR__.'/../src/modules.php';

$accepted=[
    '09123456789'=>'09123456789',
    '۰۹۱۲۳۴۵۶۷۸۹'=>'09123456789',
    '٠٩١٢٣٤٥٦٧٨٩'=>'09123456789',
    '+989123456789'=>'09123456789',
    '0912 345 6789'=>'09123456789',
];
foreach($accepted as $given=>$expected){
    if(normalize_invoice_driver_mobile($given)!==$expected){
        fwrite(STDERR,"Invalid mobile normalization: ".$given.PHP_EOL);
        exit(1);
    }
}
foreach(['','12345678901','02112345678','0912345678','+981212345678','0912345678x'] as $given){
    try { normalize_invoice_driver_mobile($given); }
    catch(RuntimeException $e){continue;}
    fwrite(STDERR,"Invalid mobile accepted: ".$given.PHP_EOL);
    exit(1);
}
echo "New-invoice driver mobile validation passed.".PHP_EOL;
