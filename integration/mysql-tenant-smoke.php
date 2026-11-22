<?php
declare(strict_types=1);

/**
 * Destructive fixtures are strictly isolated to a disposable CI MariaDB database.
 * NEVER use with production credentials or a production schema.
 */
if (getenv('SODORYAR_INTEGRATION')!=='1' || getenv('DB_NAME')!=='sodoryar_integration_test'
    || !in_array(getenv('DB_HOST'),['127.0.0.1','localhost'],true)) {
    fwrite(STDERR,"Integration test requires an isolated localhost DB named sodoryar_integration_test.\n");
    exit(2);
}
require dirname(__DIR__).'/src/bootstrap.php';
require dirname(__DIR__).'/src/localization.php';
require dirname(__DIR__).'/src/modules.php';
require dirname(__DIR__).'/src/reporting.php';

function integration_assert(bool $result,string $message): void {
    if (!$result) throw new RuntimeException($message);
}
function apply_sql_fixture(string $path): void {
    $raw=(string)file_get_contents($path);
    // Files under test use ordinary SQL statements, not stored procedures.
    $raw=(string)preg_replace('/^\s*--[^\n]*(?:\n|$)/m','',$raw);
    foreach (explode(';',$raw) as $statement) {
        $statement=trim($statement);
        if ($statement === '') continue;
        // Idempotent migrations sometimes EXECUTE a prepared SELECT 1. Consume
        // every result set before issuing another statement on this connection;
        // PDO::exec() otherwise leaves a pending result (MySQL error 2014).
        $cursor = db()->query($statement);
        try {
            do {
                if ($cursor->columnCount() > 0) {
                    while ($cursor->fetch(PDO::FETCH_NUM) !== false) {
                        // Discard fixture-only rows; no production data is read here.
                    }
                }
            } while ($cursor->nextRowset());
        } finally {
            $cursor->closeCursor();
        }
    }
}
$root=dirname(__DIR__);
apply_sql_fixture($root.'/database/install.sql');
foreach(range(1,2) as $pass) {
    for ($version=9;$version<=15;$version++) {
        $files=glob($root.'/database/migrations/'.sprintf('%03d',$version).'_*.sql');
        integration_assert(count($files)===1,'Missing or duplicated migration '.$version);
        apply_sql_fixture($files[0]);
    }
}
integration_assert((bool)query("SHOW COLUMNS FROM users LIKE 'auth_version'")->fetch(),'Missing session version');
integration_assert((bool)query("SHOW COLUMNS FROM bill_payments LIKE 'deleted_by'")->fetch(),'Missing trash owner');
integration_assert((bool)query("SHOW COLUMNS FROM print_templates LIKE 'paper_type'")->fetch(),'Missing print enum');
$paperColumn=query("SHOW COLUMNS FROM print_templates LIKE 'paper_type'")->fetch();
integration_assert(str_contains((string)$paperColumn['Type'],"'a4'"),'A4 not installed');

function insert_fixture(string $sql,array $params): int {
    query($sql,$params);
    return (int)db()->lastInsertId();
}
$companyA=insert_fixture('INSERT INTO companies(name,slug) VALUES(?,?)',['CI A','ci-tenant-a']);
$companyB=insert_fixture('INSERT INTO companies(name,slug) VALUES(?,?)',['CI B','ci-tenant-b']);
$pass=password_hash('Temporary-Test-Passphrase-123!',PASSWORD_DEFAULT);
$userA=insert_fixture('INSERT INTO users(company_id,username,password_hash,full_name,role) VALUES(?,?,?,?,?)',
    [$companyA,'ci_operator_a',$pass,'Operator A','operator']);
$userB=insert_fixture('INSERT INTO users(company_id,username,password_hash,full_name,role) VALUES(?,?,?,?,?)',
    [$companyB,'ci_operator_b',$pass,'Operator B','operator']);
$customerA=insert_fixture('INSERT INTO customers(company_id,national_code,first_name,last_name) VALUES(?,?,?,?)',
    [$companyA,'1234567890','Test','A']);
$customerB=insert_fixture('INSERT INTO customers(company_id,national_code,first_name,last_name) VALUES(?,?,?,?)',
    [$companyB,'9999999999','Test','B']);
$invoiceA=insert_fixture('INSERT INTO invoices(company_id,invoice_number,customer_id,user_id,total_amount,payment_type,invoice_date) VALUES(?,?,?,?,?,?,?)',
    [$companyA,'CI-A-0001',$customerA,$userA,123456,'cash','2026-11-22']);
$invoiceB=insert_fixture('INSERT INTO invoices(company_id,invoice_number,customer_id,user_id,total_amount,payment_type,invoice_date) VALUES(?,?,?,?,?,?,?)',
    [$companyB,'CI-B-0001',$customerB,$userB,765432,'cash','2026-11-22']);
query('INSERT INTO invoice_items(company_id,invoice_id,service_title,price) VALUES(?,?,?,?)',
    [$companyA,$invoiceA,'Local A service',123456]);
query('INSERT INTO invoice_items(company_id,invoice_id,service_title,price) VALUES(?,?,?,?)',
    [$companyB,$invoiceB,'Local B service',765432]);
$crossTenantItemBlocked=false;
try {
    query('INSERT INTO invoice_items(company_id,invoice_id,service_title,price) VALUES(?,?,?,?)',
        [$companyA,$invoiceB,'FORBIDDEN cross-tenant invoice item',100]);
} catch (PDOException $ex) {
    if ($ex->getCode()!=='23000') throw $ex;
    $crossTenantItemBlocked=true;
}
integration_assert($crossTenantItemBlocked,'Composite invoice-item foreign key did not reject a cross-company write.');
$crossTenantCustomerBlocked=false;
try {
    query('INSERT INTO invoices(company_id,invoice_number,customer_id,user_id,total_amount,payment_type) VALUES(?,?,?,?,?,?)',
        [$companyA,'CI-FORBIDDEN',$customerB,$userA,999,'cash']);
} catch (PDOException $ex) {
    if ($ex->getCode()!=='23000') throw $ex;
    $crossTenantCustomerBlocked=true;
}
integration_assert($crossTenantCustomerBlocked,'Invoice foreign key did not reject another company customer.');
// Access changes must invalidate earlier authenticated sessions in the same tenant.
$versionBefore=(int)query('SELECT auth_version FROM users WHERE id=? AND company_id=?',[$userB,$companyB])->fetchColumn();
db()->beginTransaction();
try {
    revoke_access_sessions($userB,$companyB);
    db()->commit();
} catch (Throwable $ex) {
    db()->rollBack();
    throw $ex;
}
$versionAfter=(int)query('SELECT auth_version FROM users WHERE id=? AND company_id=?',[$userB,$companyB])->fetchColumn();
integration_assert($versionAfter===$versionBefore+1,'Changing permissions did not revoke an existing user session.');
integration_assert((int)query('SELECT auth_version FROM users WHERE id=? AND company_id=?',[$userA,$companyA])->fetchColumn()===1,
    'Access-session revocation affected an unrelated company user.');

$billA=insert_fixture('INSERT INTO bill_payments(company_id,user_id,bill_type,bill_id,payment_id,amount,paid_method) VALUES(?,?,?,?,?,?,?)',
    [$companyA,$userA,'other','TEST-A','TEST-PAY-A',25000,'manual']);
$billB=insert_fixture('INSERT INTO bill_payments(company_id,user_id,bill_type,bill_id,payment_id,amount,paid_method) VALUES(?,?,?,?,?,?,?)',
    [$companyB,$userB,'other','TEST-B','TEST-PAY-B',35000,'pos']);

$_SESSION['user']=['id'=>$userA,'company_id'=>$companyA,'full_name'=>'Operator A','role'=>'operator'];
$_SESSION['auth_version']=1;
$_SESSION['last_activity']=time();
$_SESSION['regenerated_at']=time();
$_GET['company_id']=(string)$companyB;
integration_assert(tenant_id()===$companyA,'Tenant can be overridden by URL');
// The "own" document policy must exclude another employee of the same company,
// not only completely separate companies. Use the real migrated schema.
$otherUserA=insert_fixture('INSERT INTO users(company_id,username,password_hash,full_name,role) VALUES(?,?,?,?,?)',
    [$companyA,'ci_operator_a2',$pass,'Operator A2','operator']);
$otherInvoiceA=insert_fixture('INSERT INTO invoices(company_id,invoice_number,customer_id,user_id,total_amount,payment_type,invoice_date) VALUES(?,?,?,?,?,?,?)',
    [$companyA,'CI-A-OTHER',$customerA,$otherUserA,888,'cash','2026-11-22']);
query('INSERT INTO invoice_items(company_id,invoice_id,service_title,price) VALUES(?,?,?,?)',
    [$companyA,$otherInvoiceA,'Other operator service',888]);
query('INSERT INTO user_document_scopes(user_id,company_id,scope) VALUES(?,?,?)',
    [$userA,$companyA,'own']);
integration_assert(document_scope()==='own','Migrated document scope must be enforced for operators');
$report=invoice_report(tenant_id(),report_filters_default(),20);
integration_assert((int)$report['summary']['qty']===1,'A report included a different company');
integration_assert((int)$report['summary']['total']===123456,'A report total includes other tenant');
integration_assert(count($report['rows'])===1 && $report['rows'][0]['invoice_number']==='CI-A-0001',
    'Cross-company invoice leaked into report');
integration_assert((int)$report['summary']['total']===123456,'Own-only report total includes another employee or tenant');
integration_assert(count($report['rows'])===1 && $report['rows'][0]['invoice_number']==='CI-A-0001',
    'Own-only report exposed another employee invoice');
// A back-dated invoice entered later must belong to its service-date period.
query('UPDATE invoices SET created_at=? WHERE id=? AND company_id=?',
    ['2025-01-02 10:00:00',$invoiceA,$companyA]);
integration_assert(commission_sales_total($companyA,$userA,'2026-11-22','2026-11-23')===123456,
    'Commission period ignored chosen invoice date.');
integration_assert(commission_sales_total($companyA,$otherUserA,'2026-11-22','2026-11-23')===888,
    'Commission included another employee or dropped a same-company invoice.');
integration_assert(commission_sales_total($companyB,$userB,'2026-11-22','2026-11-23')===765432,
    'Commission excluded a legitimate foreign tenant invoice.');
integration_assert(commission_sales_total($companyA,$userA,'2025-01-02','2025-01-03')===0,
    'Commission incorrectly used creation timestamp.');
if (database_table_exists('invoice_voids')) {
    query('INSERT INTO invoice_voids(invoice_id,company_id,voided_by,reason) VALUES(?,?,?,?)',
        [$invoiceA,$companyA,$userA,'CI void check']);
    integration_assert(commission_sales_total($companyA,$userA,'2026-11-22','2026-11-23')===0,
        'Voided invoice must not contribute to commission sales.');
    integration_assert(commission_sales_total($companyA,$otherUserA,'2026-11-22','2026-11-23')===888,
        'Voiding one operator invoice must not alter other employee sales.');
}

integration_assert(!query('SELECT id FROM invoices WHERE id=? AND company_id=?',[$invoiceB,$companyA])->fetch(),
    'Foreign invoice accepted by tenant lookup');
integration_assert(!query('SELECT id FROM bill_payments WHERE id=? AND company_id=?',[$billB,$companyA])->fetch(),
    'Foreign bill accepted by tenant lookup');
integration_assert((int)query('SELECT amount FROM bill_payments WHERE id=? AND company_id=?',[$billA,$companyA])->fetchColumn()===25000,
    'Tenant bill value incorrect');

// Database integrity must reject a valid user ID paired with a foreign company,
// even when application code or an administrative script supplies the wrong tenant.
$foreignScopeRejected=false;
try {
    query('INSERT INTO user_document_scopes(user_id,company_id,scope) VALUES(?,?,?)',
        [$userB,$companyA,'own']);
} catch (PDOException $ex) {
    if ($ex->getCode()!=='23000') throw $ex;
    $foreignScopeRejected=true;
}
integration_assert($foreignScopeRejected,'Cross-company document scope was accepted at database level.');

$roleA=insert_fixture('INSERT INTO company_roles(company_id,name) VALUES(?,?)',[$companyA,'CI limited role']);
query('INSERT INTO company_role_permissions(role_id,company_id,permission_key) VALUES(?,?,?)',
    [$roleA,$companyA,'invoices.view']);
$foreignRoleRejected=false;
try {
    query('INSERT INTO user_role_assignments(user_id,company_id,role_id) VALUES(?,?,?)',
        [$userB,$companyA,$roleA]);
} catch (PDOException $ex) {
    if ($ex->getCode()!=='23000') throw $ex;
    $foreignRoleRejected=true;
}
integration_assert($foreignRoleRejected,'Cross-company user-role assignment was accepted at database level.');
query('INSERT INTO user_role_assignments(user_id,company_id,role_id) VALUES(?,?,?)',
    [$userA,$companyA,$roleA]);
$effective=effective_operator_permissions($userA,$companyA);
integration_assert(in_array('invoices.view',$effective,true),'Company role grant missing');
integration_assert(!in_array('roles.manage',$effective,true),'Manager permission inherited by operator');

query('UPDATE bill_payments SET deleted_at=CURRENT_TIMESTAMP,deleted_by=? WHERE id=? AND company_id=?',[$userA,$billA,$companyA]);
$deleted=query('SELECT deleted_at,paid_method,ref_code,response_code FROM bill_payments WHERE id=? AND company_id=?',
    [$billA,$companyA])->fetch();
integration_assert(bill_can_permanently_delete($deleted),'Eligible manually entered bill should be deletable');
$unconfirmed=query('SELECT deleted_at,paid_method,ref_code,response_code FROM bill_payments WHERE id=? AND company_id=?',
    [$billB,$companyB])->fetch();
integration_assert(!bill_can_permanently_delete($unconfirmed),'Bank/POS bill must not be permanently deleted');

echo "PASS: clean schema and migrations 009-015 twice; tenant and own-only reports; invoice-date commission totals; forbidden cross-company inserts; bills; roles; deletion policy.\n";
