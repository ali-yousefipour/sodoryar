<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
require_once dirname(__DIR__).'/src/localization.php';

$rates=['0'=>0,'0.001'=>1,'۰٫۱۲۵'=>125,'1.5'=>1500,'12.345'=>12345,'100.000'=>100000];
foreach ($rates as $raw=>$expected) {
    if (commission_rate_milli_percent($raw)!==$expected) {
        throw new RuntimeException('Commission rate precision mismatch: '.$raw);
    }
}
foreach (['100.001','101','-1','1.2345','1e2','abc','010',''] as $invalid) {
    try { commission_rate_milli_percent($invalid); }
    catch (RuntimeException $expected) { continue; }
    throw new RuntimeException('Invalid commission rate was accepted: '.$invalid);
}
$cases=[
    [[1000,0,1500,0,false],15],
    [[100,0,500,0,false],1],
    [[10000,5000,1000,2000,true],150],
    [[100,50,1000,500,true],1],
    [[0,0,100000,100000,true],0],
    [[PHP_INT_MAX,0,100000,0,false],PHP_INT_MAX],
    [[100000000000,0,100000,0,false],100000000000],
];
foreach ($cases as [$args,$expected]) {
    $actual=commission_amount_toman(...$args);
    if ($actual!==$expected) throw new RuntimeException('Commission amount precision mismatch.');
}
try {
    commission_amount_toman(1000,0,100001,0,false);
    throw new RuntimeException('Commission over 100% must be rejected.');
} catch (RuntimeException $expected) {}

$module=(string)file_get_contents(dirname(__DIR__).'/src/modules.php');
foreach (['commission_sales_total($tenant,$userId,$from,$to)','commission_amount_toman($total,$limit,$rateMilli,$overRateMilli,',
          'db()->beginTransaction();','audit(\'commission.calculate\',$date);','db()->commit();'] as $contract) {
    if (!str_contains($module,$contract)) {
        throw new RuntimeException('Commission posting or audit contract missing: '.$contract);
    }
}
echo "Exact commission rate, money and transaction contracts passed\n";
