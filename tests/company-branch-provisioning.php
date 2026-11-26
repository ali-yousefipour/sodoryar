<?php
declare(strict_types=1);
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)) {echo "Company provisioning SQLite test skipped: pdo_sqlite unavailable\n";exit(0);}
require dirname(__DIR__).'/src/company_resources.php';
$connection=new PDO('sqlite::memory:');
$connection->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$branchSchemaReady=true;
function db(): PDO {return $GLOBALS['connection'];}
function branches_ready(): bool {return $GLOBALS['branchSchemaReady'];}
function query(string $sql,array $params=[]): PDOStatement {
    $statement=db()->prepare(str_replace(' FOR UPDATE','',$sql));$statement->execute($params);return $statement;
}
function provision_check(bool $result,string $message): void {if(!$result)throw new RuntimeException($message);}
db()->exec("CREATE TABLE companies(id INTEGER PRIMARY KEY);CREATE TABLE users(id INTEGER PRIMARY KEY,company_id INTEGER);CREATE TABLE branches(id INTEGER PRIMARY KEY,company_id INTEGER,branch_number INTEGER,branch_code TEXT,name TEXT,is_default INTEGER,is_active INTEGER,UNIQUE(company_id,branch_number));CREATE TABLE cash_registers(id INTEGER PRIMARY KEY,company_id INTEGER,branch_id INTEGER,name TEXT);CREATE TABLE user_branch_assignments(company_id INTEGER,user_id INTEGER,branch_id INTEGER,is_default INTEGER,is_active INTEGER,UNIQUE(company_id,user_id,branch_id));INSERT INTO companies VALUES(1),(2);INSERT INTO users VALUES(11,1),(21,2);");
$rejected=false;
try {company_initialize_branches(1);}catch(LogicException $ex){$rejected=true;}
provision_check($rejected,'Provisioning outside caller transaction was accepted');
db()->beginTransaction();
company_initialize_branches(1);company_initialize_branches(1);company_initialize_branches(2);
provision_check((int)query('SELECT COUNT(*) FROM branches')->fetchColumn()===2,'Repeated initialization duplicated branch');
provision_check((int)query('SELECT COUNT(*) FROM cash_registers')->fetchColumn()===2,'Repeated initialization duplicated register');
company_assign_new_user_branch(1,11);company_assign_new_user_branch(2,21);
$badTenant=false;
try {company_assign_new_user_branch(1,21);}catch(RuntimeException $ex){$badTenant=true;}
provision_check($badTenant,'Cross-company user assignment accepted');
provision_check((int)query('SELECT COUNT(*) FROM user_branch_assignments a JOIN branches b ON b.id=a.branch_id AND b.company_id=a.company_id JOIN users u ON u.id=a.user_id AND u.company_id=a.company_id WHERE a.is_default=1 AND a.is_active=1')->fetchColumn()===2,'New user defaults not tenant-scoped');
db()->rollBack();
provision_check((int)query('SELECT COUNT(*) FROM branches')->fetchColumn()===0,'Rollback left provisioned branches');
provision_check((int)query('SELECT COUNT(*) FROM cash_registers')->fetchColumn()===0,'Rollback left register');
provision_check((int)query('SELECT COUNT(*) FROM user_branch_assignments')->fetchColumn()===0,'Rollback left user assignment');
echo "Company and user branch provisioning behavior passed\n";
