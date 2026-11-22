<?php
declare(strict_types=1);
$index=(string)file_get_contents(__DIR__.'/../public/index.php');
$start=strpos($index,"if (\$action==='edit_company')");
$end=strpos($index,"if (\$action==='company_status')",$start);
if($start===false||$end===false)throw new RuntimeException('Superadmin company editor missing.');
$body=substr($index,$start,$end-$start);
foreach([
 "SELECT id,username FROM users WHERE id=? AND company_id=? AND role=?",
 "\$revokeManagerSessions=\$managerPassword!=='' || \$managerUsername!==\$manager['username']",
 "auth_version=auth_version+1",
 "db()->beginTransaction()",
 "db()->commit()",
 "db()->rollBack()"
] as $expected) {
    if(!str_contains($body,$expected))throw new RuntimeException('Manager session revocation contract missing: '.$expected);
}
echo "Superadmin manager credential revocation contract passed\n";
