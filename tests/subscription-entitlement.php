<?php
declare(strict_types=1);
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
