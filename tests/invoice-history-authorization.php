<?php
declare(strict_types=1);

/**
 * Behavioral authorization regression test. Extract only the presentation function
 * without executing index.php, session initialization, or real database requests.
 */
$source=(string)file_get_contents(__DIR__.'/../public/index.php');
$marker='function render_invoices(int $tenant, int $limit): void {';
$start=strpos($source,$marker);
if ($start===false) throw new RuntimeException('Invoice history renderer missing.');
$function=substr($source,$start);
if (!str_ends_with(trim($function),'}')) throw new RuntimeException('Unexpected renderer structure.');
$authorized=false;
$databaseTouched=false;
function require_permission(string $permission): void {
    global $authorized;
    if ($permission!=='invoices.view') throw new RuntimeException('Unexpected permission requested.');
    if (!$authorized) throw new DomainException('Access denied.');
}
function database_table_exists(string $table): bool {
    global $databaseTouched;
    $databaseTouched=true;
    throw new LogicException('Database boundary reached.');
}
eval($function);

try {
    render_invoices(1,8);
    throw new RuntimeException('A user without invoices.view could render history.');
} catch (DomainException $expected) {}
if ($databaseTouched) throw new RuntimeException('Database was touched before authorization.');
$authorized=true;
try {
    render_invoices(1,8);
    throw new RuntimeException('The authorized renderer did not reach the data layer.');
} catch (LogicException $expected) {}
if (!$databaseTouched) throw new RuntimeException('Authorized renderer did not proceed.');

if (!str_contains($source,"<?php if(can('invoices.view')): ?><div class=\"card\"><div class=\"heading\"><h2>آخرین فاکتورها")) {
    throw new RuntimeException('Dashboard invoice history lacks its view permission gate.');
}
if (!str_contains($source,"if (can('invoices.payment_edit')) {")) {
    throw new RuntimeException('Payment edit dialog is not restricted to its own permission.');
}
echo "Invoice history authorization behavior passed\n";
