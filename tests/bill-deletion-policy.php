<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
require __DIR__.'/../src/modules.php';

$base=['deleted_at'=>'2026-11-22 12:00:00','paid_method'=>'manual','ref_code'=>null,'response_code'=>null];
assert(bill_can_permanently_delete($base) === true);
$cases=[
 ['deleted_at'=>null],
 ['paid_method'=>'pos'],
 ['ref_code'=>'ABC123'],
 ['response_code'=>'00']
];
foreach($cases as $overrides) {
    $bill=array_replace($base,$overrides);
    if (bill_can_permanently_delete($bill)) throw new RuntimeException('Unsafe payment record could be permanently deleted.');
}
if (!bill_can_permanently_delete($base)) throw new RuntimeException('Eligible unconfirmed manual record rejected.');
echo "Bill permanent-delete policy tests passed\n";
