<?php
declare(strict_types=1);
$source=(string)file_get_contents(__DIR__.'/../public/index.php');
if(!str_contains($source,'$directPermissionsByUser=[];')
   || !str_contains($source,'SELECT up.user_id,up.permission_key FROM user_permissions up JOIN users u ON u.id=up.user_id WHERE u.company_id=?')
   || !str_contains($source,"\$granted=\$directPermissionsByUser[(int)\$r['id']]??[];")) {
    throw new RuntimeException('User permissions must be fetched in one tenant-scoped batch.');
}
if(str_contains($source,"SELECT permission_key FROM user_permissions WHERE user_id=?',[$"."r['id']")) {
    throw new RuntimeException('Per-user permission SQL query still used by the list.');
}
echo "Tenant-filtered operator permission batch test passed\n";
