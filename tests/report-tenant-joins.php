<?php
declare(strict_types=1);
$reporting=(string)file_get_contents(__DIR__.'/../src/reporting.php');
$checks=[
    'invoices: user must be same-tenant or superadmin'=>"u.id=i.user_id AND (u.company_id=i.company_id OR u.role='superadmin')",
    'activity: user must be same-tenant or superadmin'=>"u.id=l.user_id AND (u.company_id=l.company_id OR u.role='superadmin')",
    'invoices: SQL tenant filter'=>'WHERE i.company_id=?',
    'activity: SQL tenant filter'=>'WHERE l.company_id=?',
    'missing cross-tenant/removed issuer displays a placeholder'=>"COALESCE(u.full_name",
];
foreach($checks as $description=>$needle){
    if(!str_contains($reporting,$needle))throw new RuntimeException('Tenant-scoped reporting regression: '.$description);
}
echo "Tenant-safe financial/activity report joins passed\n";
