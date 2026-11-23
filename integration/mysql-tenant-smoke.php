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
require dirname(__DIR__).'/src/invoice_payments.php';
require dirname(__DIR__).'/src/cashbox.php';
require dirname(__DIR__).'/src/driver_account.php';
require dirname(__DIR__).'/src/accounting_periods.php';
require dirname(__DIR__).'/src/service_rates.php';
require dirname(__DIR__).'/src/finance_presets.php';
require dirname(__DIR__).'/src/invoice_management.php';

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
// Ensure the ledger exists before invoice_report() caches table availability.
apply_sql_fixture($root.'/database/migrations/016_invoice_payments.sql');
$report=invoice_report(tenant_id(),report_filters_default(),20);
integration_assert((int)$report['summary']['qty']===1,'A report included a different company');
integration_assert((int)$report['summary']['total']===123456,'A report total includes other tenant');
integration_assert(count($report['rows'])===1 && $report['rows'][0]['invoice_number']==='CI-A-0001',
    'Cross-company invoice leaked into report');
integration_assert((int)$report['summary']['total']===123456,'Own-only report total includes another employee or tenant');
integration_assert(count($report['rows'])===1 && $report['rows'][0]['invoice_number']==='CI-A-0001',
    'Own-only report exposed another employee invoice');
apply_sql_fixture($root.'/database/migrations/017_invoice_payment_idempotency.sql');
apply_sql_fixture($root.'/database/migrations/017_invoice_payment_idempotency.sql');
apply_sql_fixture($root.'/database/migrations/018_cash_shifts.sql');
apply_sql_fixture($root.'/database/migrations/018_cash_shifts.sql');
integration_assert(cashbox_ready(),'Cash-shift migration did not install');
apply_sql_fixture($root.'/database/migrations/019_financial_period_locks.sql');
apply_sql_fixture($root.'/database/migrations/019_financial_period_locks.sql');
integration_assert(accounting_periods_ready(),'Financial periods migration failed');
apply_sql_fixture($root.'/database/migrations/020_service_rate_history.sql');
apply_sql_fixture($root.'/database/migrations/020_service_rate_history.sql');
integration_assert(service_rates_ready(),'Rate version migration failed');
integration_assert(invoice_followup_ready(),'Invoice follow-up schema did not migrate');
cashbox_add_register($companyA,'CI Cash Register');
$registerId=(int)query('SELECT id FROM cash_registers WHERE company_id=? AND name=?',[$companyA,'CI Cash Register'])->fetchColumn();
$shiftId=cashbox_start_shift($companyA,$userA,$registerId,100);
integration_assert($shiftId>0,'Cash-shift opening failed');
$partialInvoice=insert_fixture('INSERT INTO invoices(company_id,invoice_number,customer_id,user_id,total_amount,payment_type,invoice_date) VALUES(?,?,?,?,?,?,?)',
    [$companyA,'CI-A-PARTIAL',$customerA,$userA,5000000,'cash','2026-11-21']);
$initial=invoice_payment_rows([
    ['method'=>'cash','amount'=>'۱٬۰۰۰٬۰۰۰'],
    ['method'=>'pos','amount'=>'۲٬۰۰۰٬۰۰۰'],
],5000000,true);
create_invoice_payment_rows($companyA,$partialInvoice,$userA,$initial);
integration_assert(invoice_payment_totals($companyA,$partialInvoice,5000000)['remaining']===2000000,'Initial partial balance incorrect');
$key=str_repeat('a',64);
db()->beginTransaction();
try {
    query('SELECT id FROM invoices WHERE company_id=? AND id=? FOR UPDATE',[$companyA,$partialInvoice])->fetch();
    $collected=invoice_followup_record($companyA,$partialInvoice,$otherUserA,
        ['method'=>'cash','amount'=>2000000,'terminal'=>null,'tracking'=>'','reference'=>''],$key);
    db()->commit();
} catch(Throwable $ex) {db()->rollBack();throw $ex;}
integration_assert($collected>0 && invoice_payment_totals($companyA,$partialInvoice,5000000)['remaining']===0,
    'Later receipt did not settle the partial invoice');
$shiftSnapshot=cashbox_shift_totals($companyA,$shiftId);
integration_assert($shiftSnapshot['receipts']===2 && $shiftSnapshot['cash']===1000000
    && $shiftSnapshot['pos']===2000000,'Cashbox conflated receipt methods or lost shift attribution');
cashbox_close_shift($companyA,$shiftId,$userA,1000100,'');
integration_assert((string)query('SELECT status FROM cash_shifts WHERE id=? AND company_id=?',[$shiftId,$companyA])->fetchColumn()==='pending',
    'Cashbox was not submitted for approval');
$managerA=insert_fixture('INSERT INTO users(company_id,username,password_hash,full_name,role) VALUES(?,?,?,?,?)',
    [$companyA,'ci_cashbox_manager',$pass,'Cashbox Manager','company_admin']);
$_SESSION['user']=['id'=>$managerA,'company_id'=>$companyA,'full_name'=>'Cashbox Manager','role'=>'company_admin'];
cashbox_review_shift($companyA,$shiftId,$managerA,'approved','CI cash reviewed');
$_SESSION['user']=['id'=>$userA,'company_id'=>$companyA,'full_name'=>'Operator A','role'=>'operator'];
integration_assert((string)query('SELECT status FROM cash_shifts WHERE id=? AND company_id=?',[$shiftId,$companyA])->fetchColumn()==='approved',
    'Manager cash approval failed');
$closedReceiptBlocked=false;
try { cashbox_assert_payments_mutable($companyA,$partialInvoice); }
catch(RuntimeException $expected) { $closedReceiptBlocked=true; }
integration_assert($closedReceiptBlocked,'Receipt from approved cash shift was editable');
$_SESSION['user']=['id'=>$managerA,'company_id'=>$companyA,'full_name'=>'Cashbox Manager','role'=>'company_admin'];
[$jy,$jm,$jd]=gregorian_to_jalali(2026,11,21);
$jalaliDay=sprintf('%04d/%02d/%02d',$jy,$jm,$jd);
$lockId=accounting_lock_period($companyA,$managerA,$jalaliDay,$jalaliDay,'CI lock final accounting date');
integration_assert($lockId>0,'Accounting period lock was not recorded');
$blockedPeriod=false;
try {
    db()->beginTransaction();
    accounting_assert_write_open($companyA,'2026-11-21');
} catch (RuntimeException $expected) { $blockedPeriod=true; }
finally {if(db()->inTransaction())db()->rollBack();}
integration_assert($blockedPeriod,'A closed financial date permitted another write');
$tariffService=insert_fixture('INSERT INTO services(company_id,title,price,is_active) VALUES(?,?,?,1)',
    [$companyA,'CI Dynamic Service',500]);
register_initial_service_rate($companyA,$tariffService,$managerA,'CI Dynamic Service',500);
$futureDate=(new DateTimeImmutable('tomorrow'))->format('Y-m-d');
[$futureY,$futureM,$futureD]=gregorian_to_jalali((int)substr($futureDate,0,4),(int)substr($futureDate,5,2),(int)substr($futureDate,8,2));
$futureJalali=sprintf('%04d/%02d/%02d',$futureY,$futureM,$futureD);
db()->beginTransaction();
try {
    update_service_with_rates($companyA,$tariffService,'CI Updated Service',750,1,
        $futureJalali,'CI future-date tariff adjustment',$managerA);
    db()->commit();
} catch(Throwable $ex){db()->rollBack();throw $ex;}
$todayRows=effective_service_rows($companyA,date('Y-m-d'),[$tariffService]);
$futureRows=effective_service_rows($companyA,$futureDate,[$tariffService]);
integration_assert((int)$todayRows[0]['price']===500 && $todayRows[0]['title']==='CI Dynamic Service',
    'Future tariff revision prematurely changed current invoice pricing');
integration_assert((int)$futureRows[0]['price']===750 && $futureRows[0]['title']==='CI Updated Service',
    'Effective-date rate lookup failed');

db()->beginTransaction();
try {accounting_assert_write_open($companyB,'2026-11-21'); db()->commit();}
catch(Throwable $ex){db()->rollBack();throw $ex;}
$_SESSION['user']=['id'=>$userA,'company_id'=>$companyA,'full_name'=>'Operator A','role'=>'operator'];


$duplicateBlocked=false;
try {
    invoice_followup_record($companyA,$partialInvoice,$otherUserA,
        ['method'=>'cash','amount'=>2000000,'terminal'=>null,'tracking'=>'','reference'=>''],$key);
} catch(PDOException $ex) {
    if ($ex->getCode()!=='23000') throw $ex;
    $duplicateBlocked=true;
}
integration_assert($duplicateBlocked,'Repeated receipt idempotency key was accepted');
$receiptFilter=report_filters_default();
$receiptFilter['user_id']=(string)$otherUserA;
$receipts=invoice_payment_report($companyA,$receiptFilter);
integration_assert((int)$receipts['summary']['transaction_count']===1,
    'Receipt report operator filter must use the collecting operator, not invoice issuer');

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

// A commission period is unique per company, operator and period. A duplicated
// browser submit or concurrent clerk must not create a second financial record.
integration_assert(!commission_period_already_recorded($companyA,$otherUserA,'daily','1405-09-01'),
    'A fresh commission period is incorrectly marked as calculated.');
$commissionA=insert_fixture('INSERT INTO commissions(company_id,user_id,period_type,period_key,sales_total,rate,amount) VALUES(?,?,?,?,?,?,?)',
    [$companyA,$otherUserA,'daily','1405-09-01',888,'5.000',44]);
integration_assert($commissionA>0 && commission_period_already_recorded($companyA,$otherUserA,'daily','1405-09-01'),
    'Already saved commission period was not detected.');
integration_assert(!commission_period_already_recorded($companyA,$userA,'daily','1405-09-01'),
    'Another employee commission period must remain available.');
integration_assert(!commission_period_already_recorded($companyB,$userB,'daily','1405-09-01'),
    'Another company commission period must remain available.');
integration_assert(!commission_period_already_recorded($companyA,$otherUserA,'monthly','1405-09-01'),
    'Daily and monthly periods must be independent.');

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
$_SESSION['user']=['id'=>$managerA,'company_id'=>$companyA,'full_name'=>'Cashbox Manager','role'=>'company_admin'];

foreach(array_keys(finance_preset_catalog()) as $presetType) {
    $presetFilter=finance_preset_input(['report_type'=>$presetType,
        'date_from'=>'2026-11-21','date_to'=>'2026-11-23']);
    $presetResult=finance_preset_data($companyA,$presetFilter);
    integration_assert(is_array($presetResult['rows']) && !empty($presetResult['headers']),
        'Financial report preset failed: '.$presetType);
    foreach($presetResult['rows'] as $presetRow) {
        if(isset($presetRow['national_code']))
            integration_assert($presetRow['national_code']!=='0987654321',
                'Foreign-company national identity leaked into report: '.$presetType);
    }
}
$driverFinance=driver_account_data($companyA,$customerA);
integration_assert((int)$driverFinance['summary']['invoices']>=2,
    'Driver statement failed to retrieve tenant-owned invoices');
integration_assert((int)$driverFinance['summary']['invoiced']===(int)$driverFinance['receipts']['paid'],
    'Driver account double-counted payments or lost transactions');
$otherTenantAccountRejected=false;
try {driver_account_data($companyA,$customerB);}
catch(RuntimeException $expected){$otherTenantAccountRejected=true;}
integration_assert($otherTenantAccountRejected,'Cross-company driver financial profile was accessible');
$_SESSION['user']=['id'=>$userA,'company_id'=>$companyA,'full_name'=>'Operator A','role'=>'operator'];


query('UPDATE bill_payments SET deleted_at=CURRENT_TIMESTAMP,deleted_by=? WHERE id=? AND company_id=?',[$userA,$billA,$companyA]);
$deleted=query('SELECT deleted_at,paid_method,ref_code,response_code FROM bill_payments WHERE id=? AND company_id=?',
    [$billA,$companyA])->fetch();
integration_assert(bill_can_permanently_delete($deleted),'Eligible manually entered bill should be deletable');
$unconfirmed=query('SELECT deleted_at,paid_method,ref_code,response_code FROM bill_payments WHERE id=? AND company_id=?',
    [$billB,$companyB])->fetch();
integration_assert(!bill_can_permanently_delete($unconfirmed),'Bank/POS bill must not be permanently deleted');

// Apply after legacy fixtures: verify idempotent historical backfill and real payment rows.
$paymentMigration=$root.'/database/migrations/016_invoice_payments.sql';
foreach(range(1,2) as $pass) apply_sql_fixture($paymentMigration);
integration_assert((int)query('SELECT COUNT(*) FROM invoice_payments WHERE company_id=? AND invoice_id=?',[$companyA,$invoiceA])->fetchColumn()===1,
    'Legacy invoice backfill must not duplicate on repeated migration.');
$expected=invoice_payment_rows([
    ['method'=>'cash','amount'=>'۵۰۰٬۰۰۰'],
    ['method'=>'pos','amount'=>'1,000,000','tracking'=>'۱۲۳'],
],1500000);
integration_assert(count($expected)===2 && $expected[0]['amount']===500000 && $expected[1]['tracking']==='123',
    'Persian/Arabic payment amount or tracking normalization failed.');
$invalidRejected=false;
try { invoice_payment_rows([['method'=>'cash','amount'=>'-1']],100); }
catch (RuntimeException $ex) { $invalidRejected=true; }
integration_assert($invalidRejected,'Negative payments accepted.');
$invalidGroupingRejected=false;
try { invoice_payment_rows([['method'=>'cash','amount'=>'1,5']],15); }
catch (RuntimeException $ex) { $invalidGroupingRejected=true; }
integration_assert($invalidGroupingRejected,'Invalid grouped amount 1,5 was silently converted to 15.');

$multiInvoice=insert_fixture('INSERT INTO invoices(company_id,invoice_number,customer_id,user_id,total_amount,payment_type,invoice_date) VALUES(?,?,?,?,?,?,?)',
    [$companyA,'CI-MULTI',$customerA,$userA,1500000,'cash','2026-11-22']);
create_invoice_payment_rows($companyA,$multiInvoice,$userA,$expected);
$totals=invoice_payment_totals($companyA,$multiInvoice,1500000);
integration_assert($totals['paid']===1500000 && $totals['remaining']===0 && count($totals['payments'])===2,
    'Multiple transactions were not persisted accurately.');
$paymentFilter=report_filters_default();
$paymentFilter['invoice_number']='CI-MULTI';
$paymentSummary=invoice_payment_report($companyA,$paymentFilter,50);
integration_assert((int)$paymentSummary['summary']['transaction_count']===2
    && (int)$paymentSummary['summary']['cash_paid']===500000
    && (int)$paymentSummary['summary']['pos_paid']===1000000
    && count($paymentSummary['rows'])===2,
    'Cash/POS receipt report did not preserve both transactions without double counting.');
// Management query uses exactly one invoice row even if two receipts exist.
$managerFilters=invoice_management_filters(['q'=>'CI-MULTI','method'=>'mixed','status'=>'valid','size'=>'20']);
$managerResult=invoice_management_data($companyA,$managerFilters);
integration_assert((int)$managerResult['summary']['invoice_count']===1
    && (int)$managerResult['summary']['invoiced']===1500000
    && (int)$managerResult['summary']['received']===1500000
    && (int)$managerResult['summary']['cash_total']===500000
    && (int)$managerResult['summary']['pos_total']===1000000
    && count($managerResult['rows'])===1,
    'Invoice manager duplicated a mixed-payment invoice or aggregated payments incorrectly.');
$foreignManager=invoice_management_data($companyB,$managerFilters);
integration_assert((int)$foreignManager['summary']['invoice_count']===0,'Management search leaked another tenant invoice.');
$badManagerFilter=false;
try {invoice_management_filters(['sort'=>'id; DROP TABLE invoices']);}
catch(RuntimeException $ex) {$badManagerFilter=true;}
integration_assert(invoice_management_filters(['sort'=>'id; DROP TABLE invoices'])['sort']==='date','Untrusted sorting SQL was not rejected.');
$foreignPaymentRejected=false;
try {create_invoice_payment_rows($companyB,$multiInvoice,$userB,[['method'=>'cash','amount'=>1,'tracking'=>'','reference'=>'']]);}
catch (PDOException $ex) {if($ex->getCode()!=='23000')throw $ex;$foreignPaymentRejected=true;}
integration_assert($foreignPaymentRejected,'Cross-tenant invoice payment was accepted.');
echo "PASS: migration 016 idempotency, legacy backfill, Persian amounts, multiple payments, tenant isolation.\\n";
