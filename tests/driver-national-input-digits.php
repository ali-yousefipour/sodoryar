<?php
declare(strict_types=1);
require __DIR__.'/../src/bootstrap.php';
$fa='۱۲۳۴۵۶۷۸۹۰';
$arabic='١٢٣٤٥٦٧٨٩٠';
$expected='1234567890';
foreach([$fa,$arabic] as $digits) {
    if (ascii_digits($digits)!==$expected) throw new RuntimeException('Persian/Arabic digits not normalized.');
}
$controller=(string)file_get_contents(__DIR__.'/../public/index.php');
$modules=(string)file_get_contents(__DIR__.'/../src/modules.php');
foreach([
    "ascii_digits(trim((string)(\$_POST['national_code'] ?? '')))",
    "ascii_digits(trim((string)(\$_POST['driver_national_code']??'')))"
] as $needle) {
    if (!str_contains($controller,$needle)) throw new RuntimeException('Customer/invoice national ID lacks backend digit normalization.');
}
if (!str_contains($modules,'$nationalCode=ascii_digits(trim($nationalCode));')) {
    throw new RuntimeException('Central driver registry national ID lacks normalization.');
}
echo "Persian and Arabic driver national-ID input tests passed\n";
