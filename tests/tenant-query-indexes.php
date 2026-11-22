<?php
declare(strict_types=1);
$indexes=(string)file_get_contents(__DIR__.'/../database/migrations/013_tenant_query_indexes.sql');
foreach([
 'idx_invoices_tenant_user_date',
 'idx_activity_tenant_user_date',
 'idx_bills_tenant_user_date',
 'idx_comm_tenant_user_period'
] as $name) {
    if (!str_contains($indexes,$name)) throw new RuntimeException('Missing index: '.$name);
}
if (substr_count($indexes,'information_schema.STATISTICS')!==4) {
    throw new RuntimeException('One or more new indexes are not guarded against duplicate migration runs.');
}
echo "Tenant access index migration contract passed\n";
