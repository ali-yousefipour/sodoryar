<?php
declare(strict_types=1);
require __DIR__.'/../src/localization.php';
require __DIR__.'/../src/subscriptions.php';
function expect_state(?array $row,string $date,string $expected,bool $allowed): void {
    $actual=sodoryar_subscription_state($row,$date);
    if ($actual['state']!==$expected || $actual['allowed']!==$allowed) {
        throw new RuntimeException('Subscription state mismatch: '.json_encode([$actual,$expected,$allowed]));
    }
}
$row=['status'=>'active','start_date'=>'2026-11-01','end_date'=>'2026-11-27'];
expect_state(null,'2026-12-10','unassigned',true);
expect_state($row,'2026-10-31','not_started',false);
expect_state($row,'2026-11-27','active',true);
expect_state($row,'2026-11-28','grace',true);
expect_state($row,'2026-11-29','grace',true);
expect_state($row,'2026-11-30','grace',true);
expect_state($row,'2026-12-01','expired',false);
expect_state([...$row,'status'=>'suspended'],'2026-11-27','suspended',false);
expect_state([...$row,'end_date'=>'2026-11-01'],'2026-11-27','expired',false);
echo "Subscription entitlement checks passed.\n";

$first=jalali_to_gregorian_date('1405/09/01');
$expected=jalali_to_gregorian_date('1405/09/30');
if(sodoryar_subscription_jalali_end($first,1)!==$expected)throw new RuntimeException('Jalali one-month inclusive end mismatch.');
$acrossYear=jalali_to_gregorian_date('1405/12/01');
$end=jalali_to_gregorian_date('1405/12/29');
if(sodoryar_subscription_jalali_end($acrossYear,1)!==$end)throw new RuntimeException('Esfand Jalali coverage mismatch.');
$yearStart=jalali_to_gregorian_date('1405/01/01');
$endYear=(new DateTimeImmutable(jalali_to_gregorian_date('1406/01/01')))->modify('-1 day')->format('Y-m-d');
if(sodoryar_subscription_jalali_end($yearStart,12)!==$endYear)throw new RuntimeException('Jalali annual coverage mismatch.');
try{sodoryar_subscription_jalali_end($first,0);throw new RuntimeException('Zero months accepted.');}catch(RuntimeException $e){if($e->getMessage()==='Zero months accepted.')throw $e;}
echo "Jalali subscription billing-period tests passed.\n";
