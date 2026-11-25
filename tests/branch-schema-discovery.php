<?php
declare(strict_types=1);
$seen=[];
function query(string $sql,array $params=[]): object {global $seen;$seen[]=$sql;return new stdClass;}
require dirname(__DIR__).'/src/modules.php';
foreach(['branches','user_branch_assignments','pos_branch_assignments','company_bank_accounts','pos_account_registry','cash_transfers'] as $table){
 if(!database_table_exists($table))throw new RuntimeException('Branch feature silently disabled: '.$table);
}
if(database_table_exists('branches; DROP TABLE invoices'))throw new RuntimeException('Unsafe schema identifier accepted');
if(count($seen)!==6)throw new RuntimeException('Unexpected schema discovery query count');
echo "Branch schema discovery whitelist passed\n";
