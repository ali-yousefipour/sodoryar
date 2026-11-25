<?php
declare(strict_types=1);

/** Branch selection is always scoped to the authenticated tenant and actor. */
function branches_ready(): bool {
    return database_table_exists('branches') && database_table_exists('user_branch_assignments')
        && database_table_exists('pos_branch_assignments')
        && (bool)query("SHOW COLUMNS FROM invoices LIKE 'branch_id'")->fetch()
        && (bool)query("SHOW COLUMNS FROM invoice_payments LIKE 'branch_id'")->fetch();
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
        if ($id!==false && (int)$branch['id']===$id) return $id;
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

/** Expose only terminals assigned to branches the issuer may use. */
function branch_terminal_choices(int $tenant,int $actor): array {
    $ids=array_map(static fn($b)=>(int)$b['id'],branch_choices($tenant,$actor));
    if(!$ids)return [];
    $registry=database_table_exists('pos_account_registry') && database_table_exists('company_bank_accounts');
    $extra=$registry?",k.name bank_name,r.terminal_number":",NULL bank_name,NULL terminal_number";
    $joins=$registry?' LEFT JOIN pos_account_registry r ON r.terminal_id=p.id AND r.company_id=p.company_id LEFT JOIN company_bank_accounts ba ON ba.id=r.account_id AND ba.company_id=r.company_id LEFT JOIN banks k ON k.id=ba.bank_id':'';
    return query("SELECT p.id,p.brand,GROUP_CONCAT(a.branch_id) branch_ids".$extra." FROM pos_settings p JOIN pos_branch_assignments a ON a.terminal_id=p.id AND a.company_id=p.company_id".$joins." WHERE p.company_id=? AND p.is_active=1 AND a.effective_from<=NOW() AND (a.effective_until IS NULL OR a.effective_until>NOW()) AND a.branch_id IN (".implode(',',array_fill(0,count($ids),'?')).") GROUP BY p.id,p.brand".($registry?',k.name,r.terminal_number':'')." ORDER BY p.brand,p.id",array_merge([$tenant],$ids))->fetchAll();
}
