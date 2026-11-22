<?php
declare(strict_types=1);
$bootstrap=(string)file_get_contents(__DIR__.'/../src/bootstrap.php');
$index=(string)file_get_contents(__DIR__.'/../public/index.php');
$reporting=(string)file_get_contents(__DIR__.'/../src/reporting.php');
$modules=(string)file_get_contents(__DIR__.'/../src/modules.php');
$schema=(string)file_get_contents(__DIR__.'/../database/migrations/011_document_scopes.sql');
foreach([
 'bootstrap'=>[$bootstrap,['function document_scope()','function require_document_owner(int $ownerId)']],
 'invoice controller'=>[$index,["require_document_owner((int)\$invoice['user_id'])","require_document_owner((int)\$targetInvoice['user_id'])","if (\$invoice) require_document_owner((int)\$invoice['user_id'])","\$dashboardScopeSql"]],
 'reports'=>[$reporting,["document_scope()==='own'","AND i.user_id=?","AND l.user_id=?"]],
 'bill controller'=>[$modules,["require_document_owner((int)\$bill['user_id'])","AND user_id=?"]],
 'migration'=>[$schema,['user_document_scopes',"ENUM('own','company')"]]
] as $section=>$contract) {
    [$source,$needles]=$contract;
    foreach($needles as $needle) {
        if(!str_contains($source,$needle)) throw new RuntimeException('Missing '.$section.' document-scope contract: '.$needle);
    }
}
echo "Financial document scope static contracts passed\n";
