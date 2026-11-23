<?php
declare(strict_types=1);
require __DIR__.'/../src/invoice_numbers.php';

$calls=[];$collisions=0;$failure=null;
function query(string $sql,array $params): void {
    global $calls,$collisions,$failure;
    $calls[]=$params;
    if ($failure) throw $failure;
    if ($collisions-->0) {
        $ex=new PDOException('duplicate');
        $ex->errorInfo=['23000',1062,"Duplicate entry for key 'uq_invoice_number'"];
        throw $ex;
    }
}
for ($i=0;$i<1000;$i++) {
    $number=insert_invoice_with_short_number(7,11,13,50000,'cash',null,'2026-11-23');
    if (!preg_match('/^[A-Z]{2}[0-9]{6}$/D',$number)) throw new RuntimeException('Invalid short number format');
}
$calls=[];$collisions=2;
$number=insert_invoice_with_short_number(7,11,13,50000,'pos','REF','2026-11-23');
if (count($calls)!==3 || $calls[2]!==[7,$number,11,13,50000,'pos','REF','2026-11-23']) throw new RuntimeException('Collision retry changed invoice values');
$calls=[];$collisions=5;
try {
    insert_invoice_with_short_number(7,11,13,50000,'cash',null,'2026-11-23');
    throw new LogicException('Collision exhaustion accepted');
} catch (RuntimeException $ex) {
    if ($ex instanceof PDOException || count($calls)!==5) throw $ex;
}
foreach ([['23000',1062,'other_unique_key'],['23000',1452,'foreign key'],['HY000',2006,'server unavailable']] as $info) {
    $calls=[];$failure=new PDOException('database error');$failure->errorInfo=$info;
    try {
        insert_invoice_with_short_number(7,11,13,50000,'cash',null,'2026-11-23');
        throw new LogicException('Database failure swallowed');
    } catch (PDOException $ex) {
        if ($ex!==$failure || count($calls)!==1) throw new RuntimeException('Unrelated failure retried');
    }
}
echo "Short invoice numbers, bounded collision retries and failure propagation passed\n";
