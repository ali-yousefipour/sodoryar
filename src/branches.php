<?php
declare(strict_types=1);

/** Branch selection is always scoped to the authenticated tenant and actor. */
function branches_ready(): bool {
    static $ready=null;
    if ($ready!==null) return $ready;
    $tables=['branches','user_branch_assignments','pos_branch_assignments','banks'];
    $columns=['invoices','invoice_payments','cash_registers'];
    $tableCount=(int)query("SELECT COUNT(DISTINCT TABLE_NAME) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' AND TABLE_NAME IN (?,?,?,?)",$tables)->fetchColumn();
    $columnCount=(int)query("SELECT COUNT(DISTINCT TABLE_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND COLUMN_NAME='branch_id' AND TABLE_NAME IN (?,?,?)",$columns)->fetchColumn();
    $ready=$tableCount===count($tables) && $columnCount===count($columns);
    if(!$ready) error_log('Sodoryar branch schema readiness: tables='.$tableCount.'/4 columns='.$columnCount.'/3');
    return $ready;
}

function branch_choices(int $tenant,int $actor): array {
    if ($tenant!==tenant_id() || $actor!==(int)user()['id']) throw new RuntimeException('دسترسی نامعتبر است.');
    if (in_array(user()['role'],['company_admin','superadmin'],true))
        return query('SELECT id,name,branch_number,is_default FROM branches WHERE company_id=? AND is_active=1 ORDER BY is_default DESC,branch_number',[$tenant])->fetchAll();
    return query('SELECT b.id,b.name,b.branch_number,a.is_default FROM branches b JOIN user_branch_assignments a ON a.branch_id=b.id AND a.company_id=b.company_id WHERE b.company_id=? AND a.user_id=? AND b.is_active=1 AND a.is_active=1 ORDER BY a.is_default DESC,b.branch_number',[$tenant,$actor])->fetchAll();
}
function branch_require(int $tenant,int $actor,mixed $requested): int {
    $id=filter_var($requested,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    foreach(branch_choices($tenant,$actor) as $branch) {
        if ($id!==false && (int)$branch['id']===$id) {
            if(db()->inTransaction()&&!query('SELECT id FROM branches WHERE id=? AND company_id=? AND is_active=1 FOR UPDATE',[$id,$tenant])->fetch())break;
            return $id;
        }
    }
    throw new RuntimeException('شعبه انتخاب‌شده فعال یا مجاز نیست.');
}
function branch_payment_shift(int $tenant,int $actor,int $branch,array $row): ?int {
    if (!db()->inTransaction()) throw new LogicException('Branch payment requires transaction.');
    branch_require($tenant,$actor,$branch);
    if ($row['method']==='pos') {
        $terminal=$row['terminal']??null;
        if (!$terminal || !query('SELECT p.id FROM pos_settings p JOIN pos_branch_assignments a ON a.terminal_id=p.id AND a.company_id=p.company_id WHERE p.id=? AND p.company_id=? AND p.is_active=1 AND a.branch_id=? AND a.effective_from<=NOW() AND (a.effective_until IS NULL OR a.effective_until>NOW()) FOR UPDATE',[$terminal,$tenant,$branch])->fetch())
            throw new RuntimeException('کارتخوان معتبر همین شعبه را انتخاب کنید.');
        return null;
    }
    $shift=query("SELECT s.id FROM cash_shifts s JOIN cash_registers r ON r.id=s.register_id AND r.company_id=s.company_id WHERE s.company_id=? AND s.operator_id=? AND s.status='open' AND r.branch_id=? AND r.is_active=1 FOR UPDATE",[$tenant,$actor,$branch])->fetch();
    if (!$shift) throw new RuntimeException('ابتدا صندوق نقدی همین شعبه را باز کنید.');
    return (int)$shift['id'];
}
