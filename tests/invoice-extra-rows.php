<?php
declare(strict_types=1);
require_once __DIR__.'/../src/bootstrap.php';
require_once __DIR__.'/../src/localization.php';

$rows=normalize_invoice_extra_rows(['خدمت','','خدمت دوم'],['۱۲٬۳۴۵','','100,000']);
if ($rows!==[['title'=>'خدمت','price'=>12345],['title'=>'خدمت دوم','price'=>100000]]) {
    throw new RuntimeException('Localized extra line normalization is incorrect.');
}
if (normalize_invoice_extra_rows([],[])!==[]) throw new RuntimeException('Empty extra-item list must be supported.');
function extras_fail(mixed $titles,mixed $prices): void {
    try { normalize_invoice_extra_rows($titles,$prices); }
    catch (RuntimeException $expected) { return; }
    throw new RuntimeException('Invalid ad-hoc invoice line was accepted.');
}
extras_fail(['a'],[]);
extras_fail(['a'],['99999999999999999999999999999999']);
extras_fail(['a'],['-1']);
extras_fail(['a'],['1.25']);
extras_fail(['a'],['0']);
extras_fail([''],['100']);
extras_fail(['a'],['']);
extras_fail([['a']],['100']);
extras_fail(array_fill(0,11,'x'),array_fill(0,11,'1'));
extras_fail('a',['1']);
extras_fail(['a'],'1');
echo "Invoice extra-item money and row validation passed\n";
