<?php
declare(strict_types=1);
/** Disposable MariaDB fixture for browser E2E: never execute on production. */
if (getenv('SODORYAR_INTEGRATION')!=='1'
    || getenv('DB_NAME')!=='sodoryar_integration_test'
    || !in_array(getenv('DB_HOST'),['127.0.0.1','localhost'],true)) {
    fwrite(STDERR,"Refusing to seed financial E2E outside isolated CI database.\n");
    exit(2);
}
require dirname(__DIR__).'/src/bootstrap.php';

$hash=password_hash('Temporary-Test-Passphrase-123!',PASSWORD_DEFAULT);
foreach (['ci-tenant-a'=>'a','ci-tenant-b'=>'b'] as $slug=>$suffix) {
    $tenant=(int)query('SELECT id FROM companies WHERE slug=?',[$slug])->fetchColumn();
    if ($tenant<1) throw new RuntimeException('Missing disposable company fixture: '.$slug);
    query('INSERT INTO users(company_id,username,password_hash,full_name,role) VALUES(?,?,?,?,?)',
        [$tenant,'ci_manager_'.$suffix,$hash,'CI E2E Manager '.strtoupper($suffix),'company_admin']);
    $manager=(int)db()->lastInsertId();
    $branch=(int)query('SELECT id FROM branches WHERE company_id=? AND branch_number=1',[$tenant])->fetchColumn();
    if(!$branch)throw new RuntimeException('Missing migrated central branch for browser fixture');
    query('INSERT INTO user_branch_assignments(company_id,user_id,branch_id,is_default,is_active) VALUES(?,?,?,1,1)',[$tenant,$manager,$branch]);
    query('INSERT INTO cash_registers(company_id,branch_id,name) VALUES(?,?,?)',[$tenant,$branch,'CI E2E register '.$suffix]);
    $register=(int)db()->lastInsertId();
    query("INSERT INTO cash_shifts(company_id,register_id,operator_id,opening_cash,started_at,status) VALUES(?,?,?,0,NOW(),'open')",[$tenant,$register,$manager]);
    query('INSERT INTO pos_settings(company_id,brand,is_active) VALUES(?,?,1)',[$tenant,'CI E2E Terminal']);
    $terminal=(int)db()->lastInsertId();
    query('INSERT INTO pos_branch_assignments(company_id,terminal_id,branch_id,effective_from) VALUES(?,?,?,NOW())',[$tenant,$terminal,$branch]);
    query('INSERT INTO services(company_id,title,price,is_active) VALUES(?,?,?,1)',
        [$tenant,'CI E2E Service '.strtoupper($suffix),$suffix==='a'?45000:99999]);
}
query('INSERT INTO global_drivers(national_code,first_name,last_name,mobile,line_area) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE national_code=VALUES(national_code)',
    ['1234567890','CI','Driver','09123456789','خط آزمایشی']);
echo "Disposable E2E tenants, managers, services and driver seeded.\n";
