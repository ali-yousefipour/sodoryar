<?php
declare(strict_types=1);
require_once __DIR__.'/../src/localization.php';

function expect_selection_rejected(mixed $input): void {
    try {
        normalize_invoice_service_ids($input);
    } catch (RuntimeException $ex) {
        return;
    }
    throw new RuntimeException('Malformed or oversized invoice service selection was accepted.');
}

if (normalize_invoice_service_ids(['5','2','5',3]) !== [5,2,3]) {
    throw new RuntimeException('Selected invoice service IDs were not normalized correctly.');
}
if (normalize_invoice_service_ids([]) !== []) {
    throw new RuntimeException('An invoice with extra-only lines must permit an empty service selection.');
}
foreach ([null,'1',['0'],['-1'],['1.0'],[['1']],['1e4'],['9999999999999999999999999'],array_fill(0,501,'1')] as $invalid) {
    expect_selection_rejected($invalid);
}
echo "Invoice service selection validation passed\n";
