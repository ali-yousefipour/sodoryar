<?php
declare(strict_types=1);
$bootstrap=(string)file_get_contents(__DIR__.'/../src/bootstrap.php');
$controller=(string)file_get_contents(__DIR__.'/../public/index.php');
$modules=(string)file_get_contents(__DIR__.'/../src/modules.php');
$migration=(string)file_get_contents(__DIR__.'/../database/migrations/014_session_revocation.sql');
$requirements=[
 ['bootstrap check', $bootstrap, "function auth_version_ready(): bool"],
 ['bootstrap reject', $bootstrap, "!== (int)\$record['auth_version']"],
 ['login binds session', $controller, "\$_SESSION['auth_version']=(int)\$u['auth_version']"],
 ['admin change revocation', $controller, "auth_version=auth_version+1"],
 ['own password revocation', $modules, "auth_version=auth_version+1"],
 ['migration', $migration, "ALTER TABLE users ADD COLUMN auth_version"],
 ['migration idempotence', $migration, 'information_schema.COLUMNS'],
];
foreach($requirements as [$label,$haystack,$needle]){
 if(!str_contains($haystack,$needle))throw new RuntimeException('Missing auth revocation contract: '.$label);
}
echo "Session revocation static contracts passed\n";
