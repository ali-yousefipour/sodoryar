<?php
declare(strict_types=1);
if(!in_array('sqlite',PDO::getAvailableDrivers(),true)){echo "Expense settings SQLite test skipped: pdo_sqlite unavailable\n";exit(0);}
require dirname(__DIR__).'/src/expense_settings.php';
$connection=new PDO('sqlite::memory:');$connection->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
function db(): PDO {return $GLOBALS['connection'];}
function tenant_id(): int {return 1;}
function query(string $sql,array $params=[]): PDOStatement {$q=db()->prepare(str_replace(' FOR UPDATE','',$sql));$q->execute($params);return $q;}
function expense_settings_check(bool $ok,string $why): void {if(!$ok)throw new RuntimeException($why);}
db()->exec("CREATE TABLE companies(id INTEGER PRIMARY KEY);INSERT INTO companies VALUES(1),(2);CREATE TABLE expense_policies(company_id INTEGER PRIMARY KEY,approval_mode TEXT,approval_threshold INTEGER);CREATE TABLE expense_categories(id INTEGER PRIMARY KEY,company_id INTEGER,name TEXT,accounting_type TEXT,is_active INTEGER DEFAULT 1,UNIQUE(company_id,name));");
expense_ensure_defaults(1);expense_ensure_defaults(1);
expense_settings_check(count(expense_categories(1))===count(expense_default_categories()),'Duplicate or missing defaults');
expense_settings_check(expense_policy(1)['approval_mode']==='all','Unsafe default approval policy');
query("UPDATE expense_policies SET approval_mode='none' WHERE company_id=1");
query("UPDATE expense_categories SET name='Custom name',is_active=0 WHERE id=1");
expense_ensure_defaults(1);
expense_settings_check(expense_policy(1)['approval_mode']==='none','Configured policy overwritten');
expense_settings_check(count(expense_categories(1,false))===count(expense_default_categories()),'Renamed default respawned');
expense_settings_check(count(expense_categories(1))===count(expense_default_categories())-1,'Inactive category returned in active selection');
$blocked=false;try{expense_ensure_defaults(2);}catch(RuntimeException $ex){$blocked=true;}
expense_settings_check($blocked,'Other tenant defaults accessible');
foreach([['name'=>'','accounting_type'=>'expense'],['name'=>'Invalid','accounting_type'=>'income'],['name'=>str_repeat('x',161),'accounting_type'=>'asset']] as $input){$blocked=false;try{expense_category_values($input);}catch(RuntimeException $ex){$blocked=true;}expense_settings_check($blocked,'Invalid classification accepted');}
db()->exec('DELETE FROM expense_categories;DELETE FROM expense_policies;');
db()->beginTransaction();expense_ensure_defaults(1);expense_settings_check(db()->inTransaction(),'Helper committed caller transaction');db()->rollBack();
expense_settings_check((int)query('SELECT COUNT(*) FROM expense_categories')->fetchColumn()===0,'Caller rollback left defaults');
echo "Expense settings defaults, tenant access, classification and rollback behavior passed\n";
