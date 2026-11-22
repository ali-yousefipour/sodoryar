<?php
declare(strict_types=1);
$controller=(string)file_get_contents(__DIR__.'/../public/index.php');
$before=strpos($controller,'$companyManagers=[];');
$loop=strpos($controller,"foreach(query('SELECT * FROM companies ORDER BY id DESC')",$before);
if($before===false || $loop===false || $loop<$before)throw new RuntimeException('Missing batched company manager lookup.');
$segment=substr($controller,$before,$loop-$before);
if (!str_contains($segment,"FROM users WHERE role=? ORDER BY company_id,is_active DESC,id ASC")) {
    throw new RuntimeException('Company managers must be ordered and prefetched in one read.');
}
if (!str_contains($controller,"$". "manager=$". "companyManagers[(int)$". "c['id']]??false")) {
    throw new RuntimeException('Company edit dialogs must use prefetched manager data.');
}
if (str_contains($controller,"ORDER BY is_active DESC,id ASC LIMIT 1',[$". "c['id']")) {
    throw new RuntimeException('Per-company manager SQL query remains in the render loop.');
}
echo "Company manager N+1 prefetch contracts passed\n";
