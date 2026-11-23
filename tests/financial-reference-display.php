<?php
declare(strict_types=1);
require_once dirname(__DIR__).'/src/bootstrap.php';
require_once dirname(__DIR__).'/src/localization.php';
foreach ([
    'CI-E2E-BILL'=>'CI-E2E-BILL',
    'CI-E2E-REF'=>'CI-E2E-REF',
    '12345'=>'۱۲۳۴۵',
    '123-456'=>'۱۲۳-۴۵۶',
] as $input=>$expected) {
    if (display_financial_reference($input)!==$expected) {
        throw new RuntimeException('Financial identifier display mismatch: '.$input);
    }
}
$module=(string)file_get_contents(dirname(__DIR__).'/src/modules.php');
if (!str_contains($module,"display_financial_reference(\$bill['bill_id'])")
    || !str_contains($module,"display_financial_reference(\$bill['ref_code'])")) {
    throw new RuntimeException('Bill list must preserve mixed Latin-numeric reference identifiers.');
}
$receipt=(string)file_get_contents(dirname(__DIR__).'/public/index.php');
if (!str_contains($receipt,"display_financial_reference(\$pay['tracking_number'])")) {
    throw new RuntimeException('POS receipt must preserve Latin-alphanumeric tracking references.');
}
echo "Financial reference rendering contracts passed\n";
