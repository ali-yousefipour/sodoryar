<?php
declare(strict_types=1);

$bootstrap=(string)file_get_contents(__DIR__.'/../src/bootstrap.php');
$controller=(string)file_get_contents(__DIR__.'/../public/index.php');
$modules=(string)file_get_contents(__DIR__.'/../src/modules.php');

if (!str_contains($bootstrap,'function revoke_access_sessions(int $userId,int $companyId): void')
    || !str_contains($bootstrap,'UPDATE users SET auth_version=auth_version+1 WHERE id=? AND company_id=?')) {
    throw new RuntimeException('Tenant-bound access-session invalidation helper is missing.');
}
foreach (["audit('user.permissions.update'","audit('user.document_scope.update'","audit('user.role.assign'"] as $needle) {
    $pos=strpos($controller,$needle);
    if ($pos===false || !str_contains(substr($controller,max(0,$pos-120),120),'revoke_access_sessions((int)$id,(int)$tenant)')) {
        throw new RuntimeException('Operator access change failed to revoke previous sessions.');
    }
}
foreach (["audit('role.update'","audit('role.status'"] as $needle) {
    $pos=strpos($modules,$needle);
    if ($pos===false || !str_contains(substr($modules,max(0,$pos-420),420),'u.auth_version=u.auth_version+1 WHERE a.role_id=? AND a.company_id=?')) {
        throw new RuntimeException('Inherited role change failed to revoke assigned operator sessions.');
    }
}
echo "IAM access revocation contracts passed\n";
