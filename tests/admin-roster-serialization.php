<?php
declare(strict_types=1);

$src=(string)file_get_contents(__DIR__.'/../public/index.php');
if (!str_contains($src,"SELECT id FROM users WHERE company_id=? AND role=? AND is_active=1 ORDER BY id FOR UPDATE")) {
    throw new RuntimeException('Manager roster must be locked, in a stable order.');
}
$mutations=[
    "if (\$page === 'users' && \$action === 'edit')",
    "if (\$page==='users' && in_array(\$action,['user_status','archive_user','restore_user'],true))"
];
foreach ($mutations as $i=>$marker) {
    $start=strpos($src,$marker);
    if ($start===false) throw new RuntimeException('User mutation block is missing.');
    $next=strpos($src,$i===0?$mutations[1]:"if (\$page === 'customers' && \$action === 'add')",$start);
    if ($next===false) throw new RuntimeException('User mutation block boundary missing.');
    $body=substr($src,$start,$next-$start);
    $begin=strpos($body,'db()->beginTransaction()');
    $lock=strpos($body,'lock_active_company_admins($tenant)');
    $row=strpos($body,'FOR UPDATE');
    $commit=strpos($body,'db()->commit()');
    if ($begin===false || $lock===false || $row===false || $commit===false ||
        !($begin<$lock && $lock<$row && $row<$commit)) {
        throw new RuntimeException('Admin mutations must serialize last-manager checks with their writes.');
    }
    if (!str_contains($body,'db()->rollBack()')) {
        throw new RuntimeException('Admin mutation lacks rollback on error.');
    }
}
echo "Company admin locking contract checks passed\n";
